<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/backup_files.php';

$name = basename((string)($_GET['file'] ?? ''));
if (!preg_match(BACKUP_NAME_PATTERN, $name)) {
    http_response_code(400);
    exit('Ungültiger Dateiname.');
}
$path = dirname(__DIR__) . '/backups/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit('Backup-Datei nicht gefunden.');
}

// Session freigeben, damit die App während eines langen Downloads benutzbar bleibt.
session_write_close();
@set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
