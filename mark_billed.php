<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ============================================================
//  KONFIGURATION – hier anpassen
// ============================================================

// Nur Testlauf – es wird NICHTS gespeichert
$DRY_RUN = true;

// Pro Kunde: Anzeigename => letztes abgerechnetes Datum (einschliesslich)
// Datum im Format YYYY-MM-DD
$BILLING_CUTOFFS = array(
    // 'scharferladen'  => '2026-03-31',
    // 'musterkunde'    => '2025-12-31',
    // Weitere Kunden hier eintragen...
);

// ============================================================
//  AB HIER NICHTS AENDERN
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$isCli = (PHP_SAPI === 'cli');

function mb_out($msg, $type = 'info') {
    global $isCli;
    if ($isCli) {
        $p = array('ok' => '+ ', 'warn' => '! ', 'error' => 'x ', 'head' => "\n=== ");
        $s = array('head' => ' ===');
        echo (isset($p[$type]) ? $p[$type] : '') . $msg . (isset($s[$type]) ? $s[$type] : '') . "\n";
    } else {
        $styles = array(
            'ok'    => 'color:#27ae60',
            'warn'  => 'color:#e67e22',
            'error' => 'color:#c0392b',
            'head'  => 'font-weight:700;font-size:15px;margin-top:16px',
            'info'  => 'color:#ccc',
        );
        $style = isset($styles[$type]) ? $styles[$type] : 'color:#ccc';
        printf('<div style="%s;font-size:13px;line-height:1.8">%s</div>',
            $style, htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
    }
}

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
       . '<title>Als abgerechnet markieren</title>'
       . '<style>body{font-family:monospace;padding:20px;background:#111;color:#eee}'
       . 'div{padding:1px 0}</style></head><body>';
}

mb_out('Historische Eintraege als abgerechnet markieren', 'head');
mb_out($DRY_RUN ? 'DRY-RUN aktiv - es wird nichts gespeichert' : 'LIVE-MODUS - Daten werden geschrieben', 'warn');

if (empty($BILLING_CUTOFFS)) {
    mb_out('BILLING_CUTOFFS ist leer. Bitte Kunden und Daten oben eintragen.', 'error');
    if (!$isCli) echo '</body></html>';
    exit(1);
}

$pdo = db();

$totalMarked = 0;
$totalErrors = 0;

foreach ($BILLING_CUTOFFS as $customerName => $cutoffDate) {
    // Datum validieren
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoffDate)) {
        mb_out("$customerName: Datum '$cutoffDate' ungueltig (Format: YYYY-MM-DD)", 'error');
        $totalErrors++;
        continue;
    }

    // Kunden-ID suchen
    $stmt = $pdo->prepare('SELECT id FROM tm_customers WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute(array($customerName));
    $customer = $stmt->fetch();

    if (!$customer) {
        mb_out("$customerName: Kunde nicht gefunden", 'error');
        $totalErrors++;
        continue;
    }

    $customerId = (int)$customer['id'];

    // Anzahl betroffener Eintraege ermitteln
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tm_entries
         WHERE customer_id = ? AND date <= ? AND billed_at IS NULL AND deleted_at IS NULL'
    );
    $countStmt->execute(array($customerId, $cutoffDate));
    $count = (int)$countStmt->fetchColumn();

    if ($count === 0) {
        mb_out("$customerName (bis $cutoffDate): Keine offenen Eintraege gefunden", 'info');
        continue;
    }

    if (!$DRY_RUN) {
        $upd = $pdo->prepare(
            'UPDATE tm_entries
             SET billed_at = NOW()
             WHERE customer_id = ? AND date <= ? AND billed_at IS NULL AND deleted_at IS NULL'
        );
        $upd->execute(array($customerId, $cutoffDate));
        mb_out("$customerName (bis $cutoffDate): $count Eintraege als abgerechnet markiert", 'ok');
    } else {
        mb_out("$customerName (bis $cutoffDate): $count Eintraege wuerden markiert (dry-run)", 'ok');
    }

    $totalMarked += $count;
}

mb_out('Zusammenfassung', 'head');
mb_out("Eintraege " . ($DRY_RUN ? 'gefunden' : 'markiert') . ": $totalMarked", $totalMarked > 0 ? 'ok' : 'info');
mb_out("Fehler: $totalErrors", $totalErrors > 0 ? 'error' : 'info');

if ($DRY_RUN) {
    mb_out('DRY-RUN abgeschlossen. $DRY_RUN = false fuer echten Import setzen.', 'warn');
}

if (!$isCli) echo '</body></html>';
