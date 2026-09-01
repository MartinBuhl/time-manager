<?php
/**
 * Zahlungserinnerungen manuell auslösen (optional).
 *
 *   php /var/www/time-manager/payment_reminders.php
 *
 * Im Normalbetrieb ist kein Cron nötig: Die Prüfung läuft automatisch beim
 * Login (max. einmal pro Tag). Dieses Skript stößt denselben Durchlauf von
 * Hand an – z. B. zum Testen – und ignoriert die Tages-Sperre.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript ist nur über die Kommandozeile ausführbar.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/payments.php';

$logger = static function (string $m): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $m;
    echo $line . "\n";
    $dir = __DIR__ . '/log';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/payment_reminders.log', $line . "\n", FILE_APPEND | LOCK_EX);
};

$res = paymentRunReminders($logger);
$logger('Fertig. ' . $res['sent'] . ' Erinnerung(en) versendet, ' . $res['checked'] . ' offene Zahlung(en) geprüft.');
