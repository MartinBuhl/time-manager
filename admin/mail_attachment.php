<?php
/**
 * Lädt einen Anhang einer Nachricht vom Mailserver und liefert ihn als Download.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/mail_client.php';

session_write_close();
@set_time_limit(120);

$folder = (string)($_GET['folder'] ?? '');
$uid    = (int)($_GET['uid'] ?? 0);
$partId = (string)($_GET['part'] ?? '');

if ($folder === '' || preg_match('/[\r\n\0]/', $folder) || $uid <= 0 || !preg_match('/^(\d+(\.\d+)*)?$/', $partId)) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

try {
    $imap = mcImap();
    $raw  = mcFetchRaw($imap, $folder, $uid);
    $imap->logout();
} catch (ImapException $e) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit($e->getMessage());
}

$part = MimeMessage::findPart($raw, $partId);
if ($part === null || $part->isMultipart()) {
    http_response_code(404);
    exit('Anhang nicht gefunden.');
}
$content  = $part->content();
$filename = $part->filename();
if ($filename === '') {
    $filename = 'anhang-' . ($partId === '' ? '1' : str_replace('.', '-', $partId))
        . ($part->type === 'message/rfc822' ? '.eml' : '');
}
$ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $filename);

// Immer als Download und ohne MIME-Sniffing: Anhänge sind fremde Inhalte.
header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($filename));
header('Content-Length: ' . strlen($content));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $content;
