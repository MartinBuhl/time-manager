<?php
// -------------------------------------------------------
// PHP-Fehler in eine Logdatei schreiben (Diagnose).
// Schreibt nach log/php_error.log im Projekt-Root.
// Wird ganz früh von den Haupteinstiegen eingebunden,
// damit auch Fehler in nachgeladenen Dateien erfasst werden.
// -------------------------------------------------------
(static function (): void {
    $logDir = dirname(__DIR__) . '/log';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    ini_set('log_errors', '1');
    ini_set('display_errors', '0');
    ini_set('error_log', $logDir . '/php_error.log');
    error_reporting(E_ALL);
})();
