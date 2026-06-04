<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

header('Content-Type: application/json; charset=utf-8');

function jsonOk($data = null): void
{
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function jsonErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

set_exception_handler(function (Throwable $e): void {
    error_log('Admin API: ' . $e->getMessage());
    jsonErr('Serverfehler: ' . $e->getMessage(), 500);
});

// Auth
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
    jsonErr('Nicht angemeldet.', 401);
}

$stmt = db()->prepare('SELECT role FROM tm_users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$role = $stmt->fetchColumn();

if ($role !== 'admin') {
    jsonErr('Kein Zugriff.', 403);
}

// CSRF
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    jsonErr('Ungültiger Sicherheitstoken.', 403);
}

function roundToQH(int $min): float {
    return (float)((int)round($min / 15) * 15) / 60.0;
}

/**
 * Erstellt einen vollständigen SQL-Dump aller Systemtabellen (tm_*) als String.
 * Reines PHP/PDO – keine Abhängigkeit von mysqldump/exec.
 */
function buildSqlDump(PDO $pdo): string {
    $tables = $pdo->query("SHOW TABLES LIKE 'tm\\_%'")->fetchAll(PDO::FETCH_COLUMN);

    $out  = "-- Time Manager – Datenbank-Backup\n";
    $out .= '-- Erstellt: ' . date('Y-m-d H:i:s') . "\n\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $out .= "-- ----- Tabelle `$table` -----\n";
        $out .= "DROP TABLE IF EXISTS `$table`;\n";
        $out .= ($create['Create Table'] ?? '') . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$table`");
        $rows->setFetchMode(PDO::FETCH_NUM);
        foreach ($rows as $row) {
            $vals = array_map(
                fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v),
                $row
            );
            $out .= "INSERT INTO `$table` VALUES (" . implode(', ', $vals) . ");\n";
        }
        $out .= "\n";
    }

    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $out;
}

function recalcInvoiceTotals(int $invoiceId): array {
    $pdo = db();
    $inv = $pdo->prepare(
        'SELECT i.invoice_mode, i.hourly_rate AS stored_rate, i.tax_rate AS stored_tax,
                i.total_minutes, i.amount_net, i.amount_gross,
                c.hourly_rate AS cust_rate
         FROM tm_invoices i
         LEFT JOIN tm_customers c ON c.id = i.customer_id
         WHERE i.id = ? LIMIT 1'
    );
    $inv->execute([$invoiceId]);
    $invRow = $inv->fetch(PDO::FETCH_ASSOC);
    if (!$invRow) { return []; }

    $rate    = $invRow['stored_rate'] !== null
        ? (float)$invRow['stored_rate']
        : (float)($invRow['cust_rate'] ?: cfg('invoice_hourly_rate', '85.00'));
    $taxRate = $invRow['stored_tax'] !== null
        ? (int)$invRow['stored_tax']
        : (int)cfg('invoice_tax_rate', '19');

    // Text-Modus: Rechnungs-Stammdaten sind Master – NICHT aus Posten neu
    // berechnen oder überschreiben, sondern die gespeicherten Werte liefern.
    if (($invRow['invoice_mode'] ?? 'entries') === 'text') {
        $minutes = (int)$invRow['total_minutes'];
        return [
            'total_minutes'   => $minutes,
            'total_rounded_h' => round($minutes / 60, 2),
            'amount_net'      => (float)$invRow['amount_net'],
            'amount_gross'    => (float)$invRow['amount_gross'],
            'tax_rate'        => $taxRate,
            'rate'            => $rate,
        ];
    }

    $rows  = $pdo->prepare('SELECT duration_minutes FROM tm_invoice_items WHERE invoice_id = ?');
    $rows->execute([$invoiceId]);

    $amountNet = 0.0; $totalRoundedH = 0.0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $h = roundToQH((int)$r['duration_minutes']);
        $amountNet     += $h * $rate;
        $totalRoundedH += $h;
    }
    $amountNet     = round($amountNet, 2);
    $amountGross   = round($amountNet * (1 + $taxRate / 100), 2);
    $billedMinutes = (int)round($totalRoundedH * 60);

    $pdo->prepare(
        'UPDATE tm_invoices SET total_minutes=?, amount_net=?, amount_gross=? WHERE id=?'
    )->execute([$billedMinutes, $amountNet, $amountGross, $invoiceId]);

    return [
        'total_minutes'   => $billedMinutes,
        'total_rounded_h' => round($totalRoundedH, 2),
        'amount_net'      => $amountNet,
        'amount_gross'    => $amountGross,
        'tax_rate'        => $taxRate,
        'rate'            => $rate,
    ];
}

$action = $_POST['action'] ?? '';

switch ($action) {

    // ----------------------------------------------------------------
    case 'add_customer':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            jsonErr('Name darf nicht leer sein.');
        }
        $stmt = db()->prepare(
            'INSERT INTO tm_customers (name, active) VALUES (?, 1)'
        );
        $stmt->execute([$name]);
        $id = (int)db()->lastInsertId();
        jsonOk(['id' => $id, 'name' => $name]);

    // ----------------------------------------------------------------
    case 'toggle_customer':
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$id) {
            jsonErr('Ungültige ID.');
        }
        $stmt = db()->prepare(
            'UPDATE tm_customers SET active = IF(active=1, 0, 1) WHERE id = ?'
        );
        $stmt->execute([$id]);

        $stmt = db()->prepare('SELECT active FROM tm_customers WHERE id = ?');
        $stmt->execute([$id]);
        $active = (int)$stmt->fetchColumn();

        jsonOk(['active' => $active]);

    // ----------------------------------------------------------------
    case 'save_config':
        $allowed = [
            'invoice_company', 'invoice_street', 'invoice_zip', 'invoice_city',
            'invoice_email', 'invoice_phone', 'invoice_tax_id', 'invoice_tax_number',
            'invoice_iban', 'invoice_bic', 'invoice_bank', 'invoice_account_holder',
            'invoice_hourly_rate', 'invoice_tax_rate', 'invoice_payment_days',
            'invoice_number_prefix', 'invoice_number_start', 'invoice_mail_subject',
            'invoice_mail_bcc',
            'github_repo', 'github_token',
            'site_url', 'mail_from', 'mail_name', 'mail_bcc',
            'mail_signature_html', 'mail_signature_plain',
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password', 'smtp_encryption',
            'imap_save_sent', 'imap_host', 'imap_port', 'imap_encryption', 'imap_sent_folder',
        ];
        $stmt = db()->prepare(
            'UPDATE tm_configuration
             SET configuration_value = ?, last_modified = NOW()
             WHERE configuration_key = ?'
        );
        foreach ($allowed as $key) {
            if (array_key_exists($key, $_POST)) {
                $stmt->execute([trim($_POST[$key]), $key]);
            }
        }
        jsonOk();

    // ----------------------------------------------------------------
    case 'update_customer_billing':
        $id            = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $name          = trim($_POST['name'] ?? '');
        $billingName   = trim($_POST['billing_name']   ?? '');
        $billingStreet = trim($_POST['billing_street'] ?? '');
        $billingZip    = trim($_POST['billing_zip']    ?? '');
        $billingCity   = trim($_POST['billing_city']   ?? '');
        $billingEmail  = trim($_POST['billing_email']  ?? '');
        $billingTaxId      = trim($_POST['billing_tax_id']      ?? '');
        $phoneLandline     = trim($_POST['phone_landline']       ?? '');
        $phoneMobile       = trim($_POST['phone_mobile']         ?? '');
        $contactFirstName  = trim($_POST['contact_first_name']  ?? '');
        $contactLastName   = trim($_POST['contact_last_name']   ?? '');
        $contactOnInvoice  = ($_POST['contact_on_invoice'] ?? '0') === '1' ? 1 : 0;
        $hourlyRateRaw     = trim($_POST['hourly_rate']         ?? '');
        $billable          = ($_POST['billable'] ?? '0') === '1' ? 1 : 0;
        $invoiceMode        = ($_POST['invoice_mode'] ?? '') === 'text' ? 'text' : 'entries';
        $invoiceText        = trim($_POST['invoice_text']        ?? '') ?: null;
        $mailTemplateHtml   = trim($_POST['mail_template_html']  ?? '') ?: null;
        $mailTemplatePlain  = trim($_POST['mail_template_plain'] ?? '') ?: null;

        if (!$id)       { jsonErr('Ungültige ID.'); }
        if ($name === '') { jsonErr('Name darf nicht leer sein.'); }
        if ($billingEmail !== '' && !filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
            jsonErr('Ungültige E-Mail-Adresse.');
        }

        $hourlyRate = null;
        if ($hourlyRateRaw !== '') {
            $hourlyRate = (float)str_replace(',', '.', $hourlyRateRaw);
            if ($hourlyRate < 0) { jsonErr('Stundensatz darf nicht negativ sein.'); }
        }

        $stmt = db()->prepare(
            'UPDATE tm_customers SET name=?, billable=?, billing_name=?, billing_street=?, billing_zip=?,
             billing_city=?, billing_email=?, billing_tax_id=?,
             phone_landline=?, phone_mobile=?,
             contact_first_name=?, contact_last_name=?, contact_on_invoice=?,
             hourly_rate=?, invoice_mode=?, invoice_text=?,
             mail_template_html=?, mail_template_plain=? WHERE id=?'
        );
        $stmt->execute([
            $name,
            $billable,
            $billingName   ?: null,
            $billingStreet ?: null,
            $billingZip    ?: null,
            $billingCity   ?: null,
            $billingEmail  ?: null,
            $billingTaxId  ?: null,
            $phoneLandline ?: null,
            $phoneMobile   ?: null,
            $contactFirstName ?: null,
            $contactLastName  ?: null,
            $contactOnInvoice,
            $hourlyRate,
            $invoiceMode,
            $invoiceText,
            $mailTemplateHtml,
            $mailTemplatePlain,
            $id,
        ]);
        jsonOk(['name' => $name, 'billable' => $billable]);

    // ----------------------------------------------------------------
    case 'mark_billed':
        @set_time_limit(120);
        $customerId    = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);
        $filterProject = trim($_POST['project'] ?? '');
        $dateFrom      = trim($_POST['date_from'] ?? '');
        $dateTo        = trim($_POST['date_to'] ?? '');
        // Nur gültige ISO-Datumswerte (JJJJ-MM-TT) akzeptieren
        if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = ''; }
        if ($dateTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $dateTo   = ''; }
        if (!$customerId) { jsonErr('Ungültige Kunden-ID.'); }

        $stmt = db()->prepare(
            'SELECT id, name, billing_name, billing_street, billing_zip, billing_city,
                    billing_email, billing_tax_id,
                    contact_first_name, contact_last_name, contact_on_invoice,
                    hourly_rate, projects, invoice_mode, invoice_text,
                    mail_template_html, mail_template_plain
             FROM tm_customers WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) { jsonErr('Kunde nicht gefunden.'); }

        $entrySql    = "SELECT e.id, e.date, e.activity, e.comment, e.duration_minutes
                        FROM tm_entries e
                        WHERE e.customer_id = ? AND e.billed_at IS NULL AND e.deleted_at IS NULL";
        $entryParams = [$customerId];
        if ($filterProject !== '') {
            $entrySql    .= ' AND e.project = ?';
            $entryParams[] = $filterProject;
        }
        if ($dateFrom !== '') {
            $entrySql    .= ' AND e.date >= ?';
            $entryParams[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $entrySql    .= ' AND e.date <= ?';
            $entryParams[] = $dateTo;
        }
        $entrySql .= ' ORDER BY e.date ASC, e.start_datetime ASC';

        $stmt = db()->prepare($entrySql);
        $stmt->execute($entryParams);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($entries)) { jsonErr('Keine abrechenbaren Einträge vorhanden.'); }

        $items = [];
        foreach ($entries as $idx => $e) {
            $items[] = [
                'entry_id'         => (int)$e['id'],
                'date'             => $e['date'],
                'activity'         => $e['activity'],
                'comment'          => $e['comment'],
                'duration_minutes' => (int)$e['duration_minutes'],
                'sort_order'       => $idx + 1,
            ];
        }

        $totalMin      = (int)array_sum(array_column($entries, 'duration_minutes'));
        $rate          = (float)($customer['hourly_rate'] ?: cfg('invoice_hourly_rate', '85.00'));
        $amountNet     = 0.0;
        $totalRoundedH = 0.0;
        foreach ($items as $item) {
            $h = roundToQH($item['duration_minutes']);
            $amountNet     += $h * $rate;
            $totalRoundedH += $h;
        }
        $amountNet     = round($amountNet, 2);
        $taxRate       = (int)cfg('invoice_tax_rate', '19');
        $amountGross   = round($amountNet * (1 + $taxRate / 100), 2);

        $invoiceDate  = date('Y-m-d');
        $periodStart  = $entries[0]['date'] ?? $invoiceDate;
        $periodEnd    = $entries[count($entries) - 1]['date'] ?? $invoiceDate;
        $invoiceMode       = $customer['invoice_mode'] ?? 'entries';
        $invoiceText       = null;
        $mailTemplateHtml  = $customer['mail_template_html']  ?? null;
        $mailTemplatePlain = $customer['mail_template_plain'] ?? null;
        if ($invoiceMode === 'text') {
            $rawText      = $customer['invoice_text'] ?? '';
            $custProjects = json_decode($customer['projects'] ?? '[]', true);
            $firstProject = (is_array($custProjects) && !empty($custProjects))
                ? trim($custProjects[0]['name'] ?? '') : '';
            $invoiceText = str_replace('{project}', $firstProject, $rawText) ?: null;
        }

        $prefix = cfg('invoice_number_prefix', 'RE-');
        $start  = max(1, (int)cfg('invoice_number_start', '1'));

        $seqRow  = db()->query('SELECT COALESCE(MAX(invoice_seq), 0) FROM tm_invoices')->fetchColumn();
        $nextSeq = (int)$seqRow > 0 ? (int)$seqRow + 1 : $start;

        $invoiceNumber = $prefix . $nextSeq;

        $billedMinutes = (int)round($totalRoundedH * 60);

        $stmt = db()->prepare(
            'INSERT INTO tm_invoices
             (customer_id, invoice_date, period_start, period_end, invoice_mode, invoice_text,
              mail_template_html, mail_template_plain,
              tax_rate, hourly_rate, invoice_number, invoice_seq, total_minutes,
              amount_net, amount_gross, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $customerId, $invoiceDate, $periodStart, $periodEnd,
            $invoiceMode, $invoiceText,
            $mailTemplateHtml, $mailTemplatePlain,
            $taxRate, $rate,
            $invoiceNumber, $nextSeq, $billedMinutes,
            $amountNet, $amountGross, 'erstellt',
        ]);
        $invoiceId = (int)db()->lastInsertId();

        // Snapshot der Einträge als Rechnungsposten speichern
        $itemStmt = db()->prepare(
            'INSERT INTO tm_invoice_items
             (invoice_id, entry_id, date, activity, comment, duration_minutes, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $itemStmt->execute([
                $invoiceId, $item['entry_id'], $item['date'],
                $item['activity'], $item['comment'],
                $item['duration_minutes'], $item['sort_order'],
            ]);
        }

        $updateSql    = 'UPDATE tm_entries SET billed_at = NOW(), invoice_id = ?
                         WHERE customer_id = ? AND billed_at IS NULL AND deleted_at IS NULL';
        $updateParams = [$invoiceId, $customerId];
        if ($filterProject !== '') {
            $updateSql    .= ' AND project = ?';
            $updateParams[] = $filterProject;
        }
        if ($dateFrom !== '') {
            $updateSql    .= ' AND date >= ?';
            $updateParams[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $updateSql    .= ' AND date <= ?';
            $updateParams[] = $dateTo;
        }
        $stmt = db()->prepare($updateSql);
        $stmt->execute($updateParams);

        jsonOk([
            'invoice_number'  => $invoiceNumber,
            'invoice_id'      => $invoiceId,
            'pdf_file'        => null,
            'total_min'       => $totalMin,
            'total_rounded_h' => round($totalRoundedH, 4),
            'rate'            => $rate,
            'amount_net'      => $amountNet,
        ]);

    // ----------------------------------------------------------------
    case 'save_customer_projects':
        $id          = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $projectsRaw = $_POST['projects'] ?? '[]';
        if (!$id) { jsonErr('Ungültige ID.'); }

        $decoded = json_decode($projectsRaw, true);
        if (!is_array($decoded)) { jsonErr('Ungültige Projektdaten.'); }

        foreach ($decoded as $p) {
            if (!isset($p['id'], $p['name']) || trim($p['name']) === '') {
                jsonErr('Ungültiger Projekteintrag.');
            }
        }

        $clean = json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE);
        $stmt  = db()->prepare('UPDATE tm_customers SET projects = ? WHERE id = ?');
        $stmt->execute([$clean, $id]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'get_customer':
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$id) { jsonErr('Ungültige ID.'); }
        $stmt = db()->prepare(
            'SELECT id, name, active, billable, projects,
                    billing_name, billing_street, billing_zip, billing_city,
                    billing_email, billing_tax_id,
                    phone_landline, phone_mobile,
                    contact_first_name, contact_last_name, contact_on_invoice,
                    hourly_rate, invoice_mode, invoice_text,
                    mail_template_html, mail_template_plain
             FROM tm_customers WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { jsonErr('Kunde nicht gefunden.'); }

        $rstmt = db()->prepare(
            'SELECT id, activity, comment FROM tm_billing_rules
             WHERE customer_id = ? ORDER BY activity ASC, comment ASC'
        );
        $rstmt->execute([$id]);
        $row['billing_rules'] = $rstmt->fetchAll(PDO::FETCH_ASSOC);

        jsonOk($row);

    // ----------------------------------------------------------------
    case 'save_customer_rules':
        $id       = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $rulesRaw = $_POST['rules'] ?? '[]';
        if (!$id) { jsonErr('Ungültige ID.'); }

        $decoded = json_decode($rulesRaw, true);
        if (!is_array($decoded)) { jsonErr('Ungültige Regeldaten.'); }

        $clean = [];
        foreach ($decoded as $r) {
            $act = trim((string)($r['activity'] ?? ''));
            $cmt = trim((string)($r['comment']  ?? ''));
            if ($act === '') { jsonErr('Tätigkeit darf nicht leer sein.'); }
            if (mb_strlen($act) > 100) { jsonErr('Tätigkeit zu lang (max. 100 Zeichen).'); }
            if (mb_strlen($cmt) > 255) { jsonErr('Kommentar zu lang (max. 255 Zeichen).'); }
            $clean[] = ['activity' => $act, 'comment' => $cmt !== '' ? $cmt : null];
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM tm_billing_rules WHERE customer_id = ?')->execute([$id]);

            $ins = $pdo->prepare(
                'INSERT INTO tm_billing_rules (customer_id, activity, comment) VALUES (?, ?, ?)'
            );
            foreach ($clean as $r) {
                $ins->execute([$id, $r['activity'], $r['comment']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonErr('Fehler beim Speichern: ' . $e->getMessage(), 500);
        }

        jsonOk(['count' => count($clean)]);

    // ----------------------------------------------------------------
    case 'find_unbilled_matches':
        $customerId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);
        $activity   = trim($_POST['activity'] ?? '');
        $comment    = trim($_POST['comment']  ?? '');
        if (!$customerId)   { jsonErr('Ungültige Kunden-ID.'); }
        if ($activity === '') { jsonErr('Tätigkeit fehlt.'); }

        $stmt = db()->prepare(
            "SELECT id, date, start_datetime, end_datetime,
                    activity, comment, duration_minutes
             FROM tm_entries
             WHERE customer_id = ?
               AND deleted_at IS NULL
               AND billable = 1
               AND LOWER(TRIM(activity))               = LOWER(TRIM(?))
               AND LOWER(TRIM(COALESCE(comment, '')))  = LOWER(TRIM(?))
             ORDER BY start_datetime DESC"
        );
        $stmt->execute([$customerId, $activity, $comment]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalMin = (int)array_sum(array_column($rows, 'duration_minutes'));

        jsonOk([
            'count'   => count($rows),
            'minutes' => $totalMin,
            'preview' => array_slice($rows, 0, 20),
        ]);

    // ----------------------------------------------------------------
    case 'mark_entries_unbillable':
        $customerId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);
        $activity   = trim($_POST['activity'] ?? '');
        $comment    = trim($_POST['comment']  ?? '');
        if (!$customerId)   { jsonErr('Ungültige Kunden-ID.'); }
        if ($activity === '') { jsonErr('Tätigkeit fehlt.'); }

        $stmt = db()->prepare(
            "UPDATE tm_entries
             SET billable = 0
             WHERE customer_id = ?
               AND deleted_at IS NULL
               AND billable = 1
               AND LOWER(TRIM(activity))               = LOWER(TRIM(?))
               AND LOWER(TRIM(COALESCE(comment, '')))  = LOWER(TRIM(?))"
        );
        $stmt->execute([$customerId, $activity, $comment]);
        jsonOk(['updated' => $stmt->rowCount()]);

    // ----------------------------------------------------------------
    case 'delete_customers':
        $raw = trim($_POST['ids'] ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
        if (empty($ids)) { jsonErr('Keine IDs angegeben.'); }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM tm_billing_rules WHERE customer_id IN ($placeholders)")->execute($ids);
        db()->prepare("DELETE FROM tm_customers     WHERE id          IN ($placeholders)")->execute($ids);
        jsonOk(['deleted' => count($ids)]);

    // ----------------------------------------------------------------
    case 'save_shortcut':
        $customerId   = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $activity     = trim($_POST['activity']     ?? '');
        $shortcutText = trim($_POST['shortcut_text'] ?? '');
        if ($activity === '' || $shortcutText === '') jsonErr('Tätigkeit und Text sind Pflichtfelder.');
        db()->prepare('INSERT INTO tm_shortcuts (customer_id, activity, shortcut_text) VALUES (?, ?, ?)')
            ->execute([$customerId, $activity, $shortcutText]);
        jsonOk(['id' => (int)db()->lastInsertId()]);

    // ----------------------------------------------------------------
    case 'delete_shortcut':
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$id) jsonErr('Ungültige ID.');
        db()->prepare('DELETE FROM tm_shortcuts WHERE id = ?')->execute([$id]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'reset_entry_billing':
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$id) { jsonErr('Ungültige ID.'); }
        db()->prepare('UPDATE tm_entries SET billed_at = NULL WHERE id = ? AND deleted_at IS NULL')
            ->execute([$id]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'delete_entry':
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$id) { jsonErr('Ungültige ID.'); }
        $stmt = db()->prepare(
            'UPDATE tm_entries SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'update_entry':
        $id         = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $startDt    = trim($_POST['start_datetime'] ?? '');
        $endDt      = trim($_POST['end_datetime']   ?? '');
        $comment    = trim($_POST['comment']   ?? '') ?: null;
        $activity   = trim($_POST['activity']  ?? '') ?: null;
        $project    = trim($_POST['project']   ?? '') ?: null;
        $customerId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $billedRaw  = trim($_POST['billed_at'] ?? '');
        $billedAt   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $billedRaw) ? $billedRaw . ' 00:00:00' : null;

        if (!$id) { jsonErr('Ungültige ID.'); }
        if (
            !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $startDt) ||
            !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $endDt)
        ) {
            jsonErr('Ungültiges Datumsformat. Erwartet: YYYY-MM-DD HH:MM:SS');
        }
        $minutes = (int) round((strtotime($endDt) - strtotime($startDt)) / 60);
        if ($minutes < 1) $minutes = 1;

        // date-Spalte synchron zum Start-Datum halten (sonst driften Vorschau
        // und Abrechnung auseinander, da diese nach e.date filtert/speichert)
        $entryDate = substr($startDt, 0, 10);

        $stmt = db()->prepare('
            UPDATE tm_entries
            SET date = ?, start_datetime = ?, end_datetime = ?, duration_minutes = ?,
                comment = ?, activity = ?, project = ?, customer_id = ?, billed_at = ?
            WHERE id = ? AND deleted_at IS NULL
        ');
        $stmt->execute([$entryDate, $startDt, $endDt, $minutes, $comment, $activity, $project, $customerId, $billedAt, $id]);
        jsonOk(['duration_minutes' => $minutes]);

    // ----------------------------------------------------------------
    case 'import_entries':
        $targetUserId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
        $rawData      = trim($_POST['raw_data'] ?? '');

        if (!$targetUserId) { jsonErr('Benutzer fehlt.'); }
        if ($rawData === '') { jsonErr('Keine Daten eingegeben.'); }

        $userCheck = db()->prepare('SELECT id FROM tm_users WHERE id = ? LIMIT 1');
        $userCheck->execute([$targetUserId]);
        if (!$userCheck->fetchColumn()) { jsonErr('Benutzer nicht gefunden.'); }

        // Load all customers (including inactive) for name matching
        $custMap = [];
        foreach (db()->query('SELECT id, name, projects FROM tm_customers')->fetchAll() as $row) {
            $projects = json_decode($row['projects'] ?? '[]', true);
            $custMap[mb_strtolower(trim($row['name']))] = [
                'id'      => (int)$row['id'],
                'project' => (is_array($projects) && !empty($projects)) ? trim($projects[0]['name'] ?? '') : null,
            ];
        }

        $insert = db()->prepare(
            'INSERT INTO tm_entries
             (user_id, customer_id, project, activity, comment, date, start_datetime, end_datetime, duration_minutes, billable)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $lines    = preg_split('/\r?\n/', $rawData);
        $imported = 0;
        $errors   = [];

        foreach ($lines as $lineIdx => $line) {
            $line = trim($line);
            if ($line === '') continue;

            $lineNum = $lineIdx + 1;
            $parts   = explode("\t", $line);

            if (count($parts) < 5) {
                $errors[] = 'Zeile ' . $lineNum . ': Zu wenig Spalten (gefunden: ' . count($parts) . ', erwartet: 5)';
                continue;
            }

            // Date: DD.MM.YYYY
            $dateParts = explode('.', trim($parts[0]));
            if (count($dateParts) !== 3 || !checkdate((int)$dateParts[1], (int)$dateParts[0], (int)$dateParts[2])) {
                $errors[] = 'Zeile ' . $lineNum . ': Ungültiges Datum „' . trim($parts[0]) . '"';
                continue;
            }
            $dateStr = sprintf('%04d-%02d-%02d', (int)$dateParts[2], (int)$dateParts[1], (int)$dateParts[0]);

            // Time range: HH:MM-HH:MM
            $timeParts = explode('-', trim($parts[1]), 2);
            if (count($timeParts) !== 2
                || !preg_match('/^\d{1,2}:\d{2}$/', trim($timeParts[0]))
                || !preg_match('/^\d{1,2}:\d{2}$/', trim($timeParts[1]))
            ) {
                $errors[] = 'Zeile ' . $lineNum . ': Ungültiger Zeitraum „' . trim($parts[1]) . '"';
                continue;
            }
            $startDt = $dateStr . ' ' . trim($timeParts[0]) . ':00';
            $endDt   = $dateStr . ' ' . trim($timeParts[1]) . ':00';

            // Duration
            $duration = (int)trim($parts[2]);
            if ($duration <= 0) {
                $errors[] = 'Zeile ' . $lineNum . ': Ungültige Dauer „' . trim($parts[2]) . '"';
                continue;
            }

            // Customer lookup (case-insensitive)
            $customerKey = mb_strtolower(trim($parts[3]));
            if (!isset($custMap[$customerKey])) {
                $errors[] = 'Zeile ' . $lineNum . ': Kunde „' . trim($parts[3]) . '" nicht gefunden';
                continue;
            }
            $customerId  = $custMap[$customerKey]['id'];
            $project     = $custMap[$customerKey]['project'] ?: null;

            // Comment: everything from col 4 onwards (guard against tabs in comment)
            $raw = count($parts) > 5
                ? trim(implode("\t", array_slice($parts, 4)))
                : trim($parts[4]);

            // Split on first colon: left = activity, right = comment
            $colonPos = strpos($raw, ':');
            if ($colonPos === false || $colonPos === 0) {
                $errors[] = 'Zeile ' . $lineNum . ': Kein Doppelpunkt im Kommentar gefunden „' . $raw . '"';
                continue;
            }
            $lineActivity = trim(substr($raw, 0, $colonPos));
            $comment      = trim(substr($raw, $colonPos + 1));

            $cleanComment = $comment !== '' ? $comment : null;
            $billable     = entryIsBillable($customerId, $lineActivity, $cleanComment);

            $insert->execute([
                $targetUserId,
                $customerId,
                $project,
                $lineActivity,
                $cleanComment,
                $dateStr,
                $startDt,
                $endDt,
                $duration,
                $billable,
            ]);
            $imported++;
        }

        jsonOk(['imported' => $imported, 'errors' => $errors]);

    // ----------------------------------------------------------------
    case 'restore_entry':
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$id) {
            jsonErr('Ungültige ID.');
        }
        $stmt = db()->prepare(
            'UPDATE tm_entries SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL'
        );
        $stmt->execute([$id]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'add_user':
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $userRole = $_POST['role'] ?? '';
        $allowed  = ['admin', 'mitarbeiter', 'kunde'];

        if ($username === '')              { jsonErr('Benutzername darf nicht leer sein.'); }
        if (strlen($username) > 50)        { jsonErr('Benutzername zu lang (max. 50 Zeichen).'); }
        if (strlen($password) < 8)         { jsonErr('Passwort muss mindestens 8 Zeichen haben.'); }
        if (!in_array($userRole, $allowed)){ jsonErr('Ungültige Rolle.'); }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonErr('Ungültige E-Mail-Adresse.');
        }

        $stmt = db()->prepare('SELECT id FROM tm_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) { jsonErr('Benutzername bereits vergeben.'); }

        if ($email !== '') {
            $stmt = db()->prepare('SELECT id FROM tm_users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) { jsonErr('E-Mail-Adresse bereits vergeben.'); }
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare(
            'INSERT INTO tm_users (username, email, password, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$username, $email !== '' ? $email : null, $hash, $userRole]);
        $newId = (int)db()->lastInsertId();
        jsonOk(['id' => $newId, 'username' => $username, 'email' => $email, 'role' => $userRole]);

    // ----------------------------------------------------------------
    case 'update_user':
        $id       = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $userRole = $_POST['role'] ?? '';
        $allowed  = ['admin', 'mitarbeiter', 'kunde'];

        if (!$id)                          { jsonErr('Ungültige ID.'); }
        if ($username === '')              { jsonErr('Benutzername darf nicht leer sein.'); }
        if (strlen($username) > 50)        { jsonErr('Benutzername zu lang (max. 50 Zeichen).'); }
        if (!in_array($userRole, $allowed)){ jsonErr('Ungültige Rolle.'); }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonErr('Ungültige E-Mail-Adresse.');
        }

        $stmt = db()->prepare('SELECT id FROM tm_users WHERE username = ? AND id != ? LIMIT 1');
        $stmt->execute([$username, $id]);
        if ($stmt->fetchColumn()) { jsonErr('Benutzername bereits vergeben.'); }

        if ($email !== '') {
            $stmt = db()->prepare('SELECT id FROM tm_users WHERE email = ? AND id != ? LIMIT 1');
            $stmt->execute([$email, $id]);
            if ($stmt->fetchColumn()) { jsonErr('E-Mail-Adresse bereits vergeben.'); }
        }

        if ($password !== '') {
            if (strlen($password) < 8) { jsonErr('Passwort muss mindestens 8 Zeichen haben.'); }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = db()->prepare(
                'UPDATE tm_users SET username=?, email=?, password=?, role=? WHERE id=?'
            );
            $stmt->execute([$username, $email !== '' ? $email : null, $hash, $userRole, $id]);
        } else {
            $stmt = db()->prepare(
                'UPDATE tm_users SET username=?, email=?, role=? WHERE id=?'
            );
            $stmt->execute([$username, $email !== '' ? $email : null, $userRole, $id]);
        }
        jsonOk(['id' => $id, 'username' => $username, 'email' => $email, 'role' => $userRole]);

    // ----------------------------------------------------------------
    case 'delete_user':
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$id)            { jsonErr('Ungültige ID.'); }
        if ($id === $userId) { jsonErr('Eigenes Konto kann nicht gelöscht werden.'); }

        $stmt = db()->prepare('DELETE FROM tm_users WHERE id = ?');
        $stmt->execute([$id]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'execute_sql':
        $sql = trim($_POST['sql'] ?? '');
        if ($sql === '') {
            jsonErr('Kein SQL angegeben.');
        }

        $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
        if (empty($statements)) {
            jsonErr('Kein SQL angegeben.');
        }

        $pdo     = db();
        $results = [];
        foreach ($statements as $s) {
            $affected  = $pdo->exec($s);
            $results[] = [
                'preview'  => mb_substr($s, 0, 80),
                'affected' => (int)$affected,
            ];
        }

        jsonOk(['count' => count($results), 'results' => $results]);

    // ----------------------------------------------------------------
    case 'regenerate_invoice':
        @set_time_limit(120);

        $invoiceId = filter_var($_POST['invoice_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$invoiceId) { jsonErr('Ungültige Rechnungs-ID.'); }

        $stmt = db()->prepare(
            'SELECT i.id, i.invoice_number, i.customer_id
             FROM tm_invoices i WHERE i.id = ? LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) { jsonErr('Rechnung nicht gefunden.'); }

        // Stammdaten der Rechnung (nicht aktuelle Kundendaten) verwenden
        $stmt = db()->prepare(
            'SELECT i.invoice_mode, i.invoice_text, i.hourly_rate,
                    c.name, c.billing_name, c.billing_street, c.billing_zip, c.billing_city,
                    c.billing_email, c.billing_tax_id,
                    c.contact_first_name, c.contact_last_name, c.contact_on_invoice,
                    c.projects
             FROM tm_invoices i
             JOIN tm_customers c ON c.id = i.customer_id
             WHERE i.id = ? LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) { jsonErr('Kunde nicht gefunden.'); }

        $stmt = db()->prepare(
            'SELECT id, date, activity, comment, duration_minutes
             FROM tm_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) { jsonErr('Keine Rechnungsposten vorhanden. Bitte zuerst die Posten-Seite öffnen.'); }

        // Text-Modus: Stammdaten sind Master – $totals bleibt unverändert
        $totals = recalcInvoiceTotals($invoiceId);

        require_once dirname(__DIR__) . '/includes/InvoiceGenerator.php';
        $generator = new InvoiceGenerator($customer, $items, $invoice['invoice_number'], $totals);

        $pdfFile = $generator->generatePdf();

        db()->prepare(
            'UPDATE tm_invoices SET pdf_file=?, status=\'pdf_erstellt\', total_minutes=?, amount_net=?, amount_gross=? WHERE id=?'
        )->execute([$pdfFile, $totals['total_minutes'], $totals['amount_net'], $totals['amount_gross'], $invoiceId]);

        db()->prepare(
            'UPDATE tm_mail_spool SET pdf_file=? WHERE invoice_id=? AND sent_at IS NULL'
        )->execute([$pdfFile, $invoiceId]);

        jsonOk([
            'pdf_file' => $pdfFile,
            'errors'   => [],
            'totals'   => $totals,
        ]);

    // ----------------------------------------------------------------
    case 'spool_invoice':
        @set_time_limit(120);
        $invoiceId = filter_var($_POST['invoice_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$invoiceId) { jsonErr('Ungültige Rechnungs-ID.'); }

        $existing = db()->prepare('SELECT id FROM tm_mail_spool WHERE invoice_id = ? LIMIT 1');
        $existing->execute([$invoiceId]);
        if ($existing->fetchColumn()) { jsonErr('Mail-Spool-Eintrag existiert bereits.'); }

        // Load invoice with stored meta (use invoice values, not current customer values)
        $stmt = db()->prepare(
            'SELECT i.id, i.invoice_number, i.invoice_mode, i.invoice_text,
                    i.mail_template_html, i.mail_template_plain,
                    i.hourly_rate, i.amount_gross,
                    c.id AS customer_id,
                    c.name, c.billing_name, c.billing_street, c.billing_zip, c.billing_city,
                    c.billing_email, c.billing_tax_id,
                    c.contact_first_name, c.contact_last_name, c.contact_on_invoice,
                    c.projects
             FROM tm_invoices i
             JOIN tm_customers c ON c.id = i.customer_id
             WHERE i.id = ? LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) { jsonErr('Rechnung nicht gefunden.'); }

        $recipient = trim((string)($inv['billing_email'] ?? ''));
        if ($recipient === '') { jsonErr('Kunde hat keine E-Mail-Adresse hinterlegt.'); }

        $stmt = db()->prepare(
            'SELECT date, activity, comment, duration_minutes
             FROM tm_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items)) { jsonErr('Keine Rechnungsposten vorhanden. Bitte zuerst die Posten-Seite öffnen.'); }

        // Build customer context using invoice-stored values (not current customer data)
        $customerCtx = array_merge($inv, [
            'invoice_mode' => $inv['invoice_mode'],
            'invoice_text' => $inv['invoice_text'],
            'hourly_rate'  => $inv['hourly_rate'],
        ]);

        // Generate PDF (Text-Modus: Stammdaten sind Master – $totals bleibt unverändert)
        $totals  = recalcInvoiceTotals($invoiceId);
        require_once dirname(__DIR__) . '/includes/InvoiceGenerator.php';
        $generator = new InvoiceGenerator($customerCtx, $items, $inv['invoice_number'], $totals);
        $pdfFile   = $generator->generatePdf();

        db()->prepare(
            'UPDATE tm_invoices SET pdf_file=?, status=\'mail_vorbereitet\',
             total_minutes=?, amount_net=?, amount_gross=? WHERE id=?'
        )->execute([$pdfFile, $totals['total_minutes'], $totals['amount_net'], $totals['amount_gross'], $invoiceId]);

        // Build mail body using invoice-stored templates
        $mailCtx = array_merge($customerCtx, [
            'mail_template_html'  => $inv['mail_template_html'],
            'mail_template_plain' => $inv['mail_template_plain'],
        ]);
        require_once dirname(__DIR__) . '/includes/MailHelper.php';
        $mailBody = MailHelper::buildMailBody(
            $mailCtx, $items, $inv['invoice_number'], (float)$totals['amount_gross']
        );

        db()->prepare(
            'INSERT INTO tm_mail_spool
             (invoice_id, subject, recipient, pdf_file, html_body, text_body, spooled_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $invoiceId,
            $mailBody['subject'],
            $recipient,
            $pdfFile,
            $mailBody['html'],
            $mailBody['plain'],
        ]);

        jsonOk(['pdf_file' => $pdfFile]);

    // ----------------------------------------------------------------
    case 'spool_invoice_undo':
        $spoolId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$spoolId) { jsonErr('Ungültige ID.'); }

        $stmt = db()->prepare(
            'SELECT id, invoice_id, pdf_file, sent_at FROM tm_mail_spool WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$spoolId]);
        $spool = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$spool) { jsonErr('Eintrag nicht gefunden.'); }
        if ($spool['sent_at']) { jsonErr('Mail wurde bereits versendet — Rückgängig nicht möglich.'); }

        db()->prepare('DELETE FROM tm_mail_spool WHERE id = ?')->execute([$spoolId]);

        $prevStatus = $spool['pdf_file'] ? 'pdf_erstellt' : 'erstellt';
        if ($spool['invoice_id']) {
            db()->prepare('UPDATE tm_invoices SET status = ? WHERE id = ?')
                ->execute([$prevStatus, $spool['invoice_id']]);
        }

        jsonOk(['prev_status' => $prevStatus]);

    // ----------------------------------------------------------------
    case 'reverse_invoice':
        $invoiceId = filter_var($_POST['invoice_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$invoiceId) { jsonErr('Ungültige Rechnungs-ID.'); }

        $pdo  = db();
        $stmt = $pdo->prepare('SELECT invoice_number, pdf_file FROM tm_invoices WHERE id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) { jsonErr('Rechnung nicht gefunden.'); }

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE tm_entries SET billed_at = NULL, invoice_id = NULL WHERE invoice_id = ?')
            ->execute([$invoiceId]);
        $pdo->prepare('DELETE FROM tm_mail_spool WHERE invoice_id = ?')
            ->execute([$invoiceId]);
        $pdo->prepare('DELETE FROM tm_invoices WHERE id = ?')
            ->execute([$invoiceId]);
        $pdo->commit();

        if ($invoice['pdf_file'] && preg_match('/^(\d{4}\/)?[a-zA-Z0-9_\-]+\.pdf$/', $invoice['pdf_file'])) {
            $pdfPath = dirname(__DIR__) . '/invoices/pdf/' . $invoice['pdf_file'];
            if (is_file($pdfPath)) { @unlink($pdfPath); }
        }

        jsonOk(['invoice_number' => $invoice['invoice_number']]);

    // ----------------------------------------------------------------
    case 'add_invoice_item':
        $invoiceId = filter_var($_POST['invoice_id'] ?? '', FILTER_VALIDATE_INT);
        $date      = trim($_POST['date'] ?? '');
        $activity  = trim($_POST['activity'] ?? '');
        $comment   = trim($_POST['comment'] ?? '') ?: null;
        $minutes   = filter_var($_POST['duration_minutes'] ?? '', FILTER_VALIDATE_INT);

        if (!$invoiceId)                               { jsonErr('Ungültige Rechnungs-ID.'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { jsonErr('Ungültiges Datum.'); }
        if ($activity === '')                          { jsonErr('Tätigkeit darf nicht leer sein.'); }
        if (!$minutes || $minutes < 1)                 { jsonErr('Ungültige Dauer.'); }

        $check = db()->prepare('SELECT id FROM tm_invoices WHERE id = ? LIMIT 1');
        $check->execute([$invoiceId]);
        if (!$check->fetchColumn()) { jsonErr('Rechnung nicht gefunden.'); }

        $maxOrd = db()->prepare('SELECT COALESCE(MAX(sort_order),0) FROM tm_invoice_items WHERE invoice_id=?');
        $maxOrd->execute([$invoiceId]);
        $nextOrd = (int)$maxOrd->fetchColumn() + 1;

        $stmt = db()->prepare(
            'INSERT INTO tm_invoice_items (invoice_id, date, activity, comment, duration_minutes, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$invoiceId, $date, $activity, $comment, $minutes, $nextOrd]);
        $newId = (int)db()->lastInsertId();

        jsonOk(['id' => $newId, 'sort_order' => $nextOrd, 'totals' => recalcInvoiceTotals($invoiceId)]);

    // ----------------------------------------------------------------
    case 'update_invoice_item':
        $id       = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $date     = trim($_POST['date'] ?? '');
        $activity = trim($_POST['activity'] ?? '');
        $comment  = trim($_POST['comment'] ?? '') ?: null;
        $minutes  = filter_var($_POST['duration_minutes'] ?? '', FILTER_VALIDATE_INT);

        if (!$id)                                      { jsonErr('Ungültige ID.'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { jsonErr('Ungültiges Datum.'); }
        if ($activity === '')                          { jsonErr('Tätigkeit darf nicht leer sein.'); }
        if (!$minutes || $minutes < 1)                 { jsonErr('Ungültige Dauer.'); }

        $r = db()->prepare('SELECT invoice_id FROM tm_invoice_items WHERE id = ? LIMIT 1');
        $r->execute([$id]);
        $invoiceId = (int)$r->fetchColumn();
        if (!$invoiceId) { jsonErr('Posten nicht gefunden.'); }

        db()->prepare(
            'UPDATE tm_invoice_items SET date=?, activity=?, comment=?, duration_minutes=? WHERE id=?'
        )->execute([$date, $activity, $comment, $minutes, $id]);

        jsonOk(['totals' => recalcInvoiceTotals($invoiceId)]);

    // ----------------------------------------------------------------
    case 'delete_invoice_item':
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$id) { jsonErr('Ungültige ID.'); }

        $r = db()->prepare('SELECT invoice_id FROM tm_invoice_items WHERE id = ? LIMIT 1');
        $r->execute([$id]);
        $invoiceId = (int)$r->fetchColumn();
        if (!$invoiceId) { jsonErr('Posten nicht gefunden.'); }

        db()->prepare('DELETE FROM tm_invoice_items WHERE id = ?')->execute([$id]);

        jsonOk(['totals' => recalcInvoiceTotals($invoiceId)]);

    // ----------------------------------------------------------------
    case 'update_invoice_meta':
        $invoiceId   = filter_var($_POST['invoice_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$invoiceId) { jsonErr('Ungültige Rechnungs-ID.'); }

        $check = db()->prepare('SELECT id, invoice_mode FROM tm_invoices WHERE id = ? LIMIT 1');
        $check->execute([$invoiceId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if (!$existing) { jsonErr('Rechnung nicht gefunden.'); }

        $invoiceDateRaw    = trim($_POST['invoice_date'] ?? '');
        $periodStartRaw    = trim($_POST['period_start'] ?? '');
        $periodEndRaw      = trim($_POST['period_end']   ?? '');
        $invoiceMode       = ($_POST['invoice_mode'] ?? '') === 'text' ? 'text' : 'entries';
        $invoiceText       = trim($_POST['invoice_text'] ?? '') ?: null;
        $mailTemplateHtml  = isset($_POST['mail_template_html'])  ? (trim($_POST['mail_template_html'])  ?: null) : false;
        $mailTemplatePlain = isset($_POST['mail_template_plain']) ? (trim($_POST['mail_template_plain']) ?: null) : false;
        $taxRateVal        = filter_var($_POST['tax_rate'] ?? '', FILTER_VALIDATE_INT);
        $hourlyRateRaw     = str_replace(',', '.', trim($_POST['hourly_rate'] ?? ''));
        $hourlyRate        = is_numeric($hourlyRateRaw) ? round((float)$hourlyRateRaw, 2) : null;

        $invoiceDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDateRaw) ? $invoiceDateRaw : null;
        $periodStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStartRaw) ? $periodStartRaw : null;
        $periodEnd   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEndRaw)   ? $periodEndRaw   : null;
        $taxRate     = ($taxRateVal !== false && $taxRateVal >= 0 && $taxRateVal <= 100) ? $taxRateVal : null;

        $fields = [];
        $values = [];
        if ($invoiceDate !== null)    { $fields[] = 'invoice_date = ?';  $values[] = $invoiceDate; }
        if ($periodStart !== null)    { $fields[] = 'period_start = ?';  $values[] = $periodStart; }
        if ($periodEnd   !== null)    { $fields[] = 'period_end = ?';    $values[] = $periodEnd;   }
        $fields[] = 'invoice_mode = ?';  $values[] = $invoiceMode;
        $fields[] = 'invoice_text = ?';  $values[] = $invoiceText;
        if ($mailTemplateHtml  !== false) { $fields[] = 'mail_template_html = ?';  $values[] = $mailTemplateHtml;  }
        if ($mailTemplatePlain !== false) { $fields[] = 'mail_template_plain = ?'; $values[] = $mailTemplatePlain; }
        if ($taxRate !== null)        { $fields[] = 'tax_rate = ?';      $values[] = $taxRate;     }
        if ($hourlyRate !== null)     { $fields[] = 'hourly_rate = ?';   $values[] = $hourlyRate;  }

        // For text mode: also update total_minutes, amount_net, amount_gross directly if provided
        if ($invoiceMode === 'text') {
            $newMinutes = filter_var($_POST['total_minutes'] ?? '', FILTER_VALIDATE_INT);
            $newNetRaw  = str_replace(',', '.', trim($_POST['amount_net'] ?? ''));
            $newNet     = is_numeric($newNetRaw) ? round((float)$newNetRaw, 2) : null;
            $taxForCalc = $taxRate ?? (int)cfg('invoice_tax_rate', '19');
            if ($newMinutes !== false && $newMinutes > 0) {
                $fields[] = 'total_minutes = ?'; $values[] = $newMinutes;
            }
            if ($newNet !== null) {
                $fields[] = 'amount_net = ?';   $values[] = $newNet;
                $fields[] = 'amount_gross = ?'; $values[] = round($newNet * (1 + $taxForCalc / 100), 2);
            }
        }

        $values[] = $invoiceId;
        db()->prepare('UPDATE tm_invoices SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($values);

        // For entries mode, recalc from items
        if ($invoiceMode === 'entries') {
            recalcInvoiceTotals($invoiceId);
        }

        $updated = db()->prepare(
            'SELECT invoice_date, period_start, period_end, invoice_mode, invoice_text,
                    tax_rate, hourly_rate, total_minutes, amount_net, amount_gross
             FROM tm_invoices WHERE id = ? LIMIT 1'
        );
        $updated->execute([$invoiceId]);
        jsonOk($updated->fetch(PDO::FETCH_ASSOC));

    // ----------------------------------------------------------------
    case 'preview_spool_mail':
        $spoolId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$spoolId) { jsonErr('Ungültige ID.'); }

        require_once dirname(__DIR__) . '/includes/MailHelper.php';

        $stmt = db()->prepare(
            'SELECT m.id, m.invoice_id, m.recipient, m.pdf_file,
                    i.invoice_number, i.amount_gross,
                    c.name, c.billing_name, c.projects,
                    c.invoice_mode, c.mail_template_html, c.mail_template_plain
             FROM tm_mail_spool m
             JOIN tm_invoices  i ON i.id = m.invoice_id
             JOIN tm_customers c ON c.id = i.customer_id
             WHERE m.id = ?'
        );
        $stmt->execute([$spoolId]);
        $spool = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$spool) { jsonErr('Eintrag nicht gefunden.'); }

        $itemStmt = db()->prepare(
            'SELECT date, activity, comment, duration_minutes
             FROM tm_invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC'
        );
        $itemStmt->execute([$spool['invoice_id']]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $customer = [
            'name'                => $spool['name'],
            'billing_name'        => $spool['billing_name'],
            'projects'            => $spool['projects'],
            'invoice_mode'        => $spool['invoice_mode'],
            'mail_template_html'  => $spool['mail_template_html'],
            'mail_template_plain' => $spool['mail_template_plain'],
        ];

        $body = MailHelper::buildMailBody(
            $customer,
            $items,
            $spool['invoice_number'],
            (float)$spool['amount_gross']
        );

        jsonOk([
            'subject'   => $body['subject'],
            'html'      => $body['html'],
            'plain'     => $body['plain'],
            'recipient' => $spool['recipient'],
        ]);

    // ----------------------------------------------------------------
    case 'send_spool_mails':
        @set_time_limit(180);
        $idsRaw = trim($_POST['ids'] ?? '');
        $ids    = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
        if (empty($ids)) { jsonErr('Keine IDs angegeben.'); }

        require_once dirname(__DIR__) . '/includes/MailHelper.php';

        $sent   = 0;
        $errors = [];

        foreach ($ids as $spoolId) {
            $stmt = db()->prepare(
                'SELECT m.id, m.invoice_id, m.recipient, m.pdf_file,
                        i.invoice_number, i.amount_gross,
                        c.name, c.billing_name, c.projects,
                        c.invoice_mode, c.mail_template_html, c.mail_template_plain
                 FROM tm_mail_spool m
                 JOIN tm_invoices  i ON i.id  = m.invoice_id
                 JOIN tm_customers c ON c.id  = i.customer_id
                 WHERE m.id = ? AND m.sent_at IS NULL AND m.archived_at IS NULL'
            );
            $stmt->execute([$spoolId]);
            $spool = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$spool) {
                $errors[] = "ID $spoolId nicht gefunden oder bereits versendet/archiviert.";
                continue;
            }

            $recipient = trim((string)$spool['recipient']);
            if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Rechnung {$spool['invoice_number']}: ungueltige Empfaenger-E-Mail '$recipient'.";
                continue;
            }

            $itemStmt = db()->prepare(
                'SELECT date, activity, comment, duration_minutes
                 FROM tm_invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC'
            );
            $itemStmt->execute([$spool['invoice_id']]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $customer = [
                'name'                => $spool['name'],
                'billing_name'        => $spool['billing_name'],
                'projects'            => $spool['projects'],
                'invoice_mode'        => $spool['invoice_mode'],
                'mail_template_html'  => $spool['mail_template_html'],
                'mail_template_plain' => $spool['mail_template_plain'],
            ];

            try {
                $body = MailHelper::buildMailBody(
                    $customer,
                    $items,
                    $spool['invoice_number'],
                    (float)$spool['amount_gross']
                );

                $mail = MailHelper::createMailer();
                $mail->addAddress($recipient);
                // Kopie der Rechnungsmail (BCC) gemäß Rechnungsparameter
                $invBcc = trim(cfg('invoice_mail_bcc'));
                if ($invBcc !== '' && filter_var($invBcc, FILTER_VALIDATE_EMAIL)) {
                    $mail->addBCC($invBcc);
                }
                $mail->Subject = $body['subject'];
                $mail->isHTML(true);
                $mail->Body    = $body['html'];
                $mail->AltBody = $body['plain'];

                if ($spool['pdf_file']) {
                    $pdfPath = dirname(__DIR__) . '/invoices/pdf/' . $spool['pdf_file'];
                    if (is_file($pdfPath)) {
                        $mail->addAttachment($pdfPath, basename((string)$spool['pdf_file']));
                    }
                }

                $mail->send();

                // Kopie im IMAP-Sent-Ordner ablegen (best effort, blockiert nicht)
                try {
                    MailHelper::saveToImapSent($mail->getSentMIMEMessage());
                } catch (\Throwable $imapEx) {
                    error_log('IMAP-Sent: ' . $imapEx->getMessage());
                }

                $upd = db()->prepare(
                    'UPDATE tm_mail_spool
                     SET sent_at = NOW(), subject = ?, html_body = ?, text_body = ?
                     WHERE id = ?'
                );
                $upd->execute([$body['subject'], $body['html'], $body['plain'], $spoolId]);
                $sent++;
            } catch (\Throwable $e) {
                $errors[] = "Rechnung {$spool['invoice_number']}: " . $e->getMessage();
            }
        }

        jsonOk(['sent' => $sent, 'errors' => $errors]);

    // ----------------------------------------------------------------
    case 'send_test_mail':
        require_once dirname(__DIR__) . '/includes/MailHelper.php';
        $recipient = trim(cfg('mail_from'));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            jsonErr('Absender-E-Mail (mail_from) ist nicht konfiguriert oder ungültig.');
        }
        try {
            $mail = MailHelper::createMailer();
            $mail->addAddress($recipient);
            $mail->Subject = 'Testmail – SMTP-Konfiguration erfolgreich';
            $mail->isHTML(true);
            $mail->Body    = '<p>Die SMTP-Konfiguration funktioniert korrekt.</p>'
                           . '<p>Server: <strong>' . htmlspecialchars(cfg('smtp_host'), ENT_QUOTES, 'UTF-8') . '</strong> '
                           . 'Port: <strong>' . (int)cfg('smtp_port') . '</strong></p>';
            $mail->AltBody = 'Die SMTP-Konfiguration funktioniert korrekt.'
                           . "\nServer: " . cfg('smtp_host') . ' Port: ' . cfg('smtp_port');
            $mail->send();
            jsonOk(['recipient' => $recipient]);
        } catch (\Throwable $e) {
            jsonErr('SMTP-Fehler: ' . $e->getMessage());
        }

    // ----------------------------------------------------------------
    case 'send_spool_testmail':
        $spoolId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$spoolId) { jsonErr('Ungültige ID.'); }

        $stmt = db()->prepare(
            'SELECT subject, html_body, text_body, pdf_file FROM tm_mail_spool WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$spoolId]);
        $spool = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$spool) { jsonErr('Eintrag nicht gefunden.'); }

        require_once dirname(__DIR__) . '/includes/MailHelper.php';
        $adminMail = trim(cfg('mail_from'));
        if ($adminMail === '' || !filter_var($adminMail, FILTER_VALIDATE_EMAIL)) {
            jsonErr('Admin-E-Mail (Absender-E-Mail unter Konfiguration → System) ist nicht konfiguriert.');
        }

        try {
            $mail = MailHelper::createMailer();
            $mail->addAddress($adminMail);
            $mail->Subject = '[TESTMAIL] ' . $spool['subject'];
            $mail->isHTML(true);
            $mail->Body    = $spool['html_body']  ?? '';
            $mail->AltBody = $spool['text_body']  ?? '';

            if ($spool['pdf_file']) {
                $pdfPath = dirname(__DIR__) . '/invoices/pdf/' . $spool['pdf_file'];
                if (is_file($pdfPath)) {
                    $mail->addAttachment($pdfPath, basename((string)$spool['pdf_file']));
                }
            }

            $mail->send();
            jsonOk(['recipient' => $adminMail]);
        } catch (\Throwable $e) {
            jsonErr('SMTP-Fehler: ' . $e->getMessage());
        }

    // ----------------------------------------------------------------
    case 'reset_spool_mails':
        $idsRaw = trim($_POST['ids'] ?? '');
        $ids    = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
        if (empty($ids)) { jsonErr('Keine IDs angegeben.'); }

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("UPDATE tm_mail_spool SET sent_at = NULL, archived_at = NULL WHERE id IN ($ph)");
        $stmt->execute($ids);
        jsonOk(['reset' => $stmt->rowCount()]);

    // ----------------------------------------------------------------
    case 'unarchive_spool_mails':
        $idsRaw = trim($_POST['ids'] ?? '');
        $ids    = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
        if (empty($ids)) { jsonErr('Keine IDs angegeben.'); }

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("UPDATE tm_mail_spool SET archived_at = NULL WHERE id IN ($ph)");
        $stmt->execute($ids);
        jsonOk(['unarchived' => $stmt->rowCount()]);

    // ----------------------------------------------------------------
    case 'archive_spool_mails':
        $idsRaw = trim($_POST['ids'] ?? '');
        $ids    = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
        if (empty($ids)) { jsonErr('Keine IDs angegeben.'); }

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("UPDATE tm_mail_spool SET archived_at = NOW() WHERE id IN ($ph)");
        $stmt->execute($ids);
        jsonOk(['archived' => $stmt->rowCount()]);

    // ----------------------------------------------------------------
    case 'set_billed_until':
        $customerId  = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);
        $cutoffDate  = trim($_POST['cutoff_date'] ?? '');

        if (!$customerId) { jsonErr('Ungültige Kunden-ID.'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoffDate)) { jsonErr('Ungültiges Datum.'); }

        $stmt = db()->prepare('SELECT id FROM tm_customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        if (!$stmt->fetchColumn()) { jsonErr('Kunde nicht gefunden.'); }

        $stmt = db()->prepare(
            'UPDATE tm_entries SET billed_at = NOW()
             WHERE customer_id = ? AND date <= ? AND billed_at IS NULL AND deleted_at IS NULL'
        );
        $stmt->execute([$customerId, $cutoffDate]);
        $marked = $stmt->rowCount();

        jsonOk(['marked' => $marked]);

    // ----------------------------------------------------------------
    case 'save_admin_layout':
        $layout = trim($_POST['layout'] ?? '');
        if ($layout === '' || json_decode($layout) === null) {
            jsonErr('Ungültiges Layout-Format.');
        }
        db()->prepare(
            'INSERT INTO tm_user_state (user_id, admin_layout)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE admin_layout = VALUES(admin_layout), updated_at = NOW()'
        )->execute([$userId, $layout]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'check_update':
        $repo  = cfg('github_repo', '');
        $token = cfg('github_token', '');
        if ($repo === '') {
            jsonErr('GitHub Repository nicht konfiguriert. Bitte unter Administration → Konfiguration → System den Wert "GitHub Repository" eintragen (z.B. MartinBuhl/time-manager).');
        }
        $url        = "https://api.github.com/repos/{$repo}/releases/latest";
        $hdrs       = [
            'User-Agent: TimeManager-Updater/' . APP_VERSION,
            'Accept: application/vnd.github+json',
        ];
        if ($token !== '') $hdrs[] = "Authorization: Bearer {$token}";

        // cURL bevorzugt, file_get_contents als Fallback
        $json = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => $hdrs,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body !== false && $code === 200) $json = $body;
        }
        if ($json === false && ini_get('allow_url_fopen')) {
            $ctx  = stream_context_create(['http' => [
                'header'  => implode("\r\n", $hdrs),
                'timeout' => 10,
            ]]);
            $json = @file_get_contents($url, false, $ctx);
        }
        if ($json === false) {
            jsonErr('GitHub API nicht erreichbar. Bitte prüfen: github_repo korrekt gesetzt? Webspace erlaubt ausgehende HTTPS-Verbindungen?');
        }
        $release   = json_decode($json, true);
        $latestTag = ltrim($release['tag_name'] ?? '', 'v');
        $hasUpdate = version_compare($latestTag, APP_VERSION, '>');
        $tag   = $release['tag_name'] ?? '';
        $dlUrl = $tag !== '' ? "https://github.com/{$repo}/archive/refs/tags/{$tag}.zip" : '';
        jsonOk([
            'current'      => APP_VERSION,
            'latest'       => $latestTag,
            'has_update'   => $hasUpdate,
            'download_url' => $dlUrl,
            'release_name' => $release['name'] ?? "v{$latestTag}",
        ]);

    // ----------------------------------------------------------------
    case 'create_backup':
        @set_time_limit(180);
        $dir = dirname(__DIR__) . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            jsonErr('Backup-Verzeichnis konnte nicht angelegt werden.');
        }
        // Direktzugriff per Web unterbinden
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }

        $ts   = date('Y-m-d_His');
        $sql  = buildSqlDump(db());
        $name = "tm_backup_$ts.zip";
        $path = $dir . '/' . $name;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            jsonErr('ZIP-Datei konnte nicht erstellt werden.');
        }
        $zip->addFromString("tm_backup_$ts.sql", $sql);
        $zip->close();

        jsonOk([
            'name'  => $name,
            'size'  => filesize($path),
            'mtime' => date('d.m.Y H:i:s', filemtime($path)),
        ]);

    // ----------------------------------------------------------------
    case 'mail_backup':
        $name = basename((string)($_POST['file'] ?? ''));
        if (!preg_match('/^tm_backup_[\w\-]+\.zip$/', $name)) {
            jsonErr('Ungültiger Dateiname.');
        }
        $path = dirname(__DIR__) . '/backups/' . $name;
        if (!is_file($path)) { jsonErr('Backup-Datei nicht gefunden.'); }

        require_once dirname(__DIR__) . '/includes/MailHelper.php';
        // Admin-E-Mail: zuerst Admin-Benutzer, sonst konfigurierte mail_from
        $adminMail = db()->query(
            "SELECT email FROM tm_users
             WHERE role = 'admin' AND email IS NOT NULL AND email <> ''
             ORDER BY id ASC LIMIT 1"
        )->fetchColumn();
        if (!$adminMail) { $adminMail = trim(cfg('mail_from')); }
        if (!$adminMail || !filter_var($adminMail, FILTER_VALIDATE_EMAIL)) {
            jsonErr('Keine Admin-E-Mail hinterlegt (Benutzer mit Rolle „admin" oder Konfiguration → System).');
        }

        try {
            $mail = MailHelper::createMailer();
            $mail->addAddress($adminMail);
            $mail->Subject = 'Time Manager Datenbank-Backup – ' . $name;
            $mail->Body    = "Anbei das Datenbank-Backup.\n\nDatei: $name\nErstellt: "
                           . date('d.m.Y H:i:s', filemtime($path));
            $mail->addAttachment($path, $name);
            $mail->send();
            jsonOk(['recipient' => $adminMail]);
        } catch (\Throwable $e) {
            jsonErr('SMTP-Fehler: ' . $e->getMessage());
        }

    // ----------------------------------------------------------------
    case 'bulk_assign_project':
        $idsRaw  = trim($_POST['ids'] ?? '');
        $project = trim($_POST['project'] ?? '');
        $ids     = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
        if (empty($ids))     { jsonErr('Keine Einträge ausgewählt.'); }
        if ($project === '') { jsonErr('Kein Projekt angegeben.'); }

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "UPDATE tm_entries SET project = ? WHERE id IN ($ph) AND deleted_at IS NULL"
        );
        $stmt->execute(array_merge([$project], $ids));
        jsonOk(['updated' => $stmt->rowCount()]);

    // ----------------------------------------------------------------
    case 'bulk_assign_customer':
        $idsRaw     = trim($_POST['ids'] ?? '');
        $customerId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);
        $ids        = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
        if (empty($ids))  { jsonErr('Keine Einträge ausgewählt.'); }
        if (!$customerId) { jsonErr('Kein Kunde angegeben.'); }

        $chk = db()->prepare('SELECT 1 FROM tm_customers WHERE id = ? LIMIT 1');
        $chk->execute([$customerId]);
        if (!$chk->fetchColumn()) { jsonErr('Kunde nicht gefunden.'); }

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "UPDATE tm_entries SET customer_id = ? WHERE id IN ($ph) AND deleted_at IS NULL"
        );
        $stmt->execute(array_merge([$customerId], $ids));
        jsonOk(['updated' => $stmt->rowCount()]);

    // ----------------------------------------------------------------
    case 'delete_backup':
        $name = basename((string)($_POST['file'] ?? ''));
        if (!preg_match('/^tm_backup_[\w\-]+\.zip$/', $name)) {
            jsonErr('Ungültiger Dateiname.');
        }
        $path = dirname(__DIR__) . '/backups/' . $name;
        if (!is_file($path)) { jsonErr('Backup-Datei nicht gefunden.'); }
        if (!@unlink($path)) { jsonErr('Backup-Datei konnte nicht gelöscht werden.'); }
        jsonOk();

    // ----------------------------------------------------------------
    default:
        jsonErr('Unbekannte Aktion.', 404);
}
