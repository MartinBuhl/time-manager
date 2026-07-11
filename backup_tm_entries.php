<?php
// ============================================================
// Backup-Script: Arbeitszeit Time Manager
// Cron: 05 0 * * * php /path/to/time_manager/backup_tm_entries.php
// ============================================================

require_once __DIR__ . '/config.php';

// ---- Debug-Modus -------------------------------------------
// Standardmäßig läuft das Script still (Cron). Mit ?debug=1 (oder
// Aufruf "php backup_tm_entries.php debug") gibt es Ausgaben zum Testen.
define('DEBUG', isset($_GET['debug']) || in_array('debug', $argv ?? [], true));

// Im Browser als reinen Text ausgeben, damit Zeilenumbrüche lesbar sind
if (DEBUG && PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
}

function dbg(string $msg): void
{
    if (DEBUG) echo $msg . "\n";
}

function fail(string $msg): void
{
    if (DEBUG) echo "FEHLER: $msg\n";
    exit(1);
}

// ---- Konfiguration -----------------------------------------
$tmpDir = sys_get_temp_dir();
$date   = date('Y-m-d');

// ---- Datumsbereich: Vormonat + aktueller Monat
// (sichert den Vormonat den ganzen Folgemonat über mit, damit auch die
//  Einträge vom letzten Tag eines Monats vollständig erfasst werden)
$startPrevMonth  = date('Y-m-01', strtotime('first day of last month'));
$endCurrentMonth = date('Y-m-t');

// ---- DB-Verbindung (PDO) für die Textlisten ----------------
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// ---- Empfänger: Mail des Admins (aus tm_users) -------------
$mailto = $pdo->query(
    "SELECT email FROM tm_users
     WHERE role = 'admin' AND email IS NOT NULL AND email <> ''
     ORDER BY id ASC LIMIT 1"
)->fetchColumn();
if (!$mailto) {
    fail('Keine Admin-Mailadresse hinterlegt (tm_users).');
}

// ---- Absender: konfigurierte mail_from, sonst Admin-Mail ----
$from = $pdo->query(
    "SELECT configuration_value FROM tm_configuration
     WHERE configuration_key = 'mail_from' LIMIT 1"
)->fetchColumn();
if (!$from) {
    $from = $mailto;
}

// ---- Hilfsfunktionen ---------------------------------------

function fmtDateDe(string $ymd): string
{
    return substr($ymd, 8, 2) . '.' . substr($ymd, 5, 2) . '.' . substr($ymd, 0, 4);
}

function fmtTime(string $dt): string
{
    return substr($dt, 11, 5);
}

// ---- Einträge im Datumsbereich laden -----------------------
$stmt = $pdo->prepare(
    "SELECT e.date,
            e.start_datetime, e.end_datetime,
            e.duration_minutes,
            e.activity, e.comment,
            COALESCE(c.name, '(kein Kunde)') AS customer_name
     FROM   tm_entries e
     LEFT JOIN tm_customers c ON c.id = e.customer_id
     WHERE  e.deleted_at IS NULL
       AND  e.date BETWEEN ? AND ?
     ORDER BY e.date ASC, e.start_datetime ASC"
);
$stmt->execute([$startPrevMonth, $endCurrentMonth]);
$entries = $stmt->fetchAll();

// ---- Liste 1: Sortiert nach Zeit, gruppiert nach Tag -------
$byDay = [];
foreach ($entries as $e) {
    $byDay[$e['date']][] = $e;
}

$listByTime = "=== ARBEITSZEITEN NACH TAG ===\n\n";
foreach ($byDay as $day => $rows) {
    $listByTime .= fmtDateDe($day) . "\n";
    $dayMin = 0;
    foreach ($rows as $e) {
        $timeRange = fmtTime($e['start_datetime']) . '-' . fmtTime($e['end_datetime']);
        $detail    = $e['activity'];
        if ($e['comment'] !== null && $e['comment'] !== '') {
            $detail .= ': ' . $e['comment'];
        }
        $listByTime .= sprintf(
            "  %-11s  %4d  %-20s  %s\n",
            $timeRange,
            (int)$e['duration_minutes'],
            $e['customer_name'],
            $detail
        );
        $dayMin += (int)$e['duration_minutes'];
    }
    $listByTime .= 'Gesamt: ' . number_format($dayMin / 60, 2, ',', '') . " h\n\n";
}

// ---- Liste 2: Sortiert nach Kunde, gruppiert nach Kunde ----
$byCustomer = [];
foreach ($entries as $e) {
    $byCustomer[$e['customer_name']][] = $e;
}
ksort($byCustomer);

$listByCustomer = "=== ARBEITSZEITEN NACH KUNDE ===\n\n";
foreach ($byCustomer as $customerName => $rows) {
    $listByCustomer .= $customerName . "\n";
    $custMin = 0;
    foreach ($rows as $e) {
        $timeRange = fmtDateDe($e['date']) . '  ' . fmtTime($e['start_datetime']) . '-' . fmtTime($e['end_datetime']);
        $detail    = $e['activity'];
        if ($e['comment'] !== null && $e['comment'] !== '') {
            $detail .= ': ' . $e['comment'];
        }
        $listByCustomer .= sprintf(
            "  %-22s  %4d  %s\n",
            $timeRange,
            (int)$e['duration_minutes'],
            $detail
        );
        $custMin += (int)$e['duration_minutes'];
    }
    $listByCustomer .= 'Gesamt: ' . number_format($custMin / 60, 2, ',', '') . " h\n\n";
}

// ---- Hilfsfunktion: mysqldump ausführen --------------------
function mysqldump_exec(array $tables, string $extraArgs, string $outFile): void
{
    $tableArgs = implode(' ', array_map('escapeshellarg', $tables));
    $cmd = sprintf(
        'mysqldump --host=%s --user=%s --password=%s --single-transaction %s %s %s > %s',
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        $extraArgs,
        escapeshellarg(DB_NAME),
        $tableArgs,
        escapeshellarg($outFile)
    );
    dbg('  mysqldump: ' . implode(', ', $tables));
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fail('mysqldump (' . implode(', ', $tables) . ') fehlgeschlagen. Exit-Code: ' . $code);
    }
}

// ---- Alle Systemtabellen (tm_*) ermitteln ------------------
$allTables = $pdo->query("SHOW TABLES LIKE 'tm\\_%'")->fetchAll(PDO::FETCH_COLUMN);
if (empty($allTables)) {
    fail('Keine Systemtabellen (tm_*) gefunden.');
}
// Alle übrigen Tabellen außer tm_entries (die wird separat/gefiltert gesichert)
$otherTables = array_values(array_filter($allTables, fn($t) => $t !== 'tm_entries'));

// ---- Dumps erstellen ---------------------------------------
// tm_entries: nur aktueller Monat (unverändert)
$fileEntries = "$tmpDir/tm_entries_dump_$date.sql";
mysqldump_exec(
    ['tm_entries'],
    '--where=' . escapeshellarg("date BETWEEN '$startPrevMonth' AND '$endCurrentMonth'"),
    $fileEntries
);

// alle weiteren Systemtabellen: vollständig
$fileOthers = "$tmpDir/tm_tables_dump_$date.sql";
mysqldump_exec($otherTables, '', $fileOthers);

// ---- Beide Dumps in ein ZIP packen -------------------------
$fileZip = "$tmpDir/tm_backup_$date.zip";
$zip = new ZipArchive();
if ($zip->open($fileZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail('ZIP-Datei konnte nicht erstellt werden.');
}
$zip->addFile($fileEntries, basename($fileEntries));
$zip->addFile($fileOthers,  basename($fileOthers));
$zip->close();
dbg('ZIP erstellt: ' . basename($fileZip) . ' (' . number_format(filesize($fileZip)) . ' Bytes)');

// ---- E-Mail zusammenbauen ----------------------------------
$subject  = "Time Manager Backup vom $date";
$bodyText = "Zeitraum: $startPrevMonth bis $endCurrentMonth\n"
          . "Einträge: " . count($entries) . "\n\n"
          . $listByTime
          . $listByCustomer
          . "---\n"
          . "Anhang: ZIP mit zwei SQL-Dumps:\n"
          . "  - tm_entries (Vormonat + aktueller Monat)\n"
          . "  - alle weiteren Systemtabellen, vollständig:\n"
          . "    " . implode(', ', $otherTables) . "\n";

$boundary = md5(uniqid('tm_backup_', true));

$headers  = "From: $from\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

$message  = "--$boundary\r\n";
$message .= "Content-Type: text/plain; charset=\"utf-8\"\r\n\r\n";
$message .= $bodyText . "\r\n";

$attachName = basename($fileZip);
$content    = file_get_contents($fileZip);
dbg('  Anhang ' . $attachName . ': ' . number_format(strlen($content)) . ' Bytes');
$message .= "--$boundary\r\n";
$message .= "Content-Type: application/zip; name=\"$attachName\"\r\n";
$message .= "Content-Transfer-Encoding: base64\r\n";
$message .= "Content-Disposition: attachment; filename=\"$attachName\"\r\n\r\n";
$message .= chunk_split(base64_encode($content)) . "\r\n";

$message .= "--$boundary--";

if (DEBUG) {
    echo "Empfänger:   $mailto\n";
    echo "Absender:    $from\n";
    echo "Betreff:     $subject\n";
    echo "Zeitraum:    $startPrevMonth bis $endCurrentMonth\n";
    echo "Tabellen:    " . implode(', ', $allTables) . "\n\n";
    echo "--- Mailtext ---\n" . $bodyText . "\n";
}

$sent = mail($mailto, $subject, $message, $headers);
dbg($sent ? "Mail versendet an $mailto." : "Mail-Versand fehlgeschlagen (mail() lieferte false).");

// ---- Temp-Dateien aufräumen --------------------------------
unlink($fileEntries);
unlink($fileOthers);
unlink($fileZip);

dbg('Temp-Dateien aufgeräumt. Fertig.');
