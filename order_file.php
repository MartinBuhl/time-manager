<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/error_log.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Nicht angemeldet.');
}

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$stmt = db()->prepare('SELECT stored_name, original_name, mime FROM tm_order_files WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$baseDir  = __DIR__ . '/orders';
$fullPath = $baseDir . '/' . basename((string) $file['stored_name']);

$realBase = realpath($baseDir);
$realFile = realpath($fullPath);

if ($realBase === false || $realFile === false
    || strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0
    || !is_file($realFile)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$mime = $file['mime'] ?: 'application/octet-stream';
// Bilder und PDF inline anzeigen, alles andere zum Download anbieten
$inline = preg_match('#^(image/|application/pdf$)#', $mime) === 1;

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . rawurlencode((string) $file['original_name']) . '"');
header('Content-Length: ' . filesize($realFile));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');
readfile($realFile);
