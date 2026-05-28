<?php
require_once __DIR__ . '/auth.php';

$type = $_GET['type'] ?? '';
$file = $_GET['file'] ?? '';

if (!in_array($type, ['pdf', 'ods'], true) || $file === '') {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

// Allow optional year subdir: 2026/filename.pdf
if (!preg_match('/^(\d{4}\/)?[a-zA-Z0-9_\-]+\.(pdf|ods)$/', $file)) {
    http_response_code(400);
    exit('Ungültiger Dateiname.');
}

$basePath = dirname(__DIR__) . '/invoices/' . $type . '/';
$fullPath = $basePath . $file;

$realBase = realpath($basePath);
$realFile = realpath($fullPath);

if ($realBase === false || $realFile === false || strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

if (!is_file($realFile)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$mimeTypes = [
    'pdf' => 'application/pdf',
    'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
];

$disposition = $type === 'pdf' ? 'inline' : 'attachment';
header('Content-Type: ' . $mimeTypes[$type]);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($file) . '"');
header('Content-Length: ' . filesize($realFile));
header('Cache-Control: private, no-cache');
readfile($realFile);
