<?php
/**
 * Liefert einen hochgeladenen Zahlungs-Beleg aus dem geschützten Ordner
 * uploads/payments/ aus. Der Ordner ist per .htaccess für den direkten
 * Zugriff gesperrt; hier greift die Admin-Authentifizierung (auth.php).
 *
 * Aufruf:  payment_file.php?id=<zahlungId>&f=<gespeicherterName>[&dl=1]
 *   dl=1 erzwingt den Download (Content-Disposition: attachment),
 *   sonst Inline-Anzeige (Bild/PDF im Browser).
 *
 * Sicherheit: Es wird nur ausgeliefert, was auch in der files-Liste der
 * betreffenden Zahlung eingetragen ist – kein Zugriff auf beliebige Pfade.
 */

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/payments.php';

$id     = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$stored = basename((string) ($_GET['f'] ?? ''));

if (!$id || $stored === '' || !paymentValidStoredName($stored)) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$stmt = db()->prepare('SELECT files FROM tm_payments WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if ($row === false) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

$entry = null;
foreach (paymentDecodeFiles($row['files']) as $f) {
    if (($f['stored'] ?? '') === $stored) { $entry = $f; break; }
}
if ($entry === null) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

$path = paymentUploadDir() . '/' . $stored;
if (!is_file($path)) {
    http_response_code(404);
    exit('Datei fehlt.');
}

$allowed = paymentAllowedFileTypes();
$ext     = strtolower((string) pathinfo($stored, PATHINFO_EXTENSION));
$mime    = $entry['mime'] ?? ($allowed[$ext] ?? 'application/octet-stream');
$dispose = (isset($_GET['dl']) && $_GET['dl']) ? 'attachment' : 'inline';

// Anzeigename bereinigen (keine Steuerzeichen / Anführungszeichen im Header).
$name = (string) ($entry['name'] ?? $stored);
$name = preg_replace('/[\r\n"]/', '', $name);
if ($name === '') $name = $stored;

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $dispose . '; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

encFileSend($path);
