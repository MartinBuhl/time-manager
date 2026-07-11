<?php
// -------------------------------------------------------
// Gemeinsame Helfer für Auftrags-Dateien (App + Admin).
// -------------------------------------------------------

function ordersDir(): string
{
    $dir = dirname(__DIR__) . '/orders';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        // Direktzugriff auf hochgeladene Dateien unterbinden (Apache)
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function orderAllowedExt(): array
{
    return [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic',
        'pdf',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'odt', 'ods', 'odp',
        'txt', 'csv',
    ];
}

/**
 * Speichert hochgeladene Dateien ($_FILES['files']) fuer einen Auftrag.
 * Dateinamen auf der Platte werden zufaellig kodiert (nicht erratbar).
 * Gibt eine Fehlermeldung zurueck oder '' bei Erfolg.
 */
function saveOrderFiles(int $orderId): string
{
    if (empty($_FILES['files']) || !is_array($_FILES['files']['name'] ?? null)) {
        return '';
    }
    $dir     = ordersDir();
    $allowed = orderAllowedExt();
    $maxSize = 25 * 1024 * 1024; // 25 MB
    $ins     = db()->prepare(
        'INSERT INTO tm_order_files (order_id, stored_name, original_name, mime, size)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($_FILES['files']['name'] as $i => $origName) {
        $error = $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            return 'Fehler beim Hochladen von ' . $origName . '.';
        }
        $tmp  = $_FILES['files']['tmp_name'][$i];
        $size = (int) ($_FILES['files']['size'][$i] ?? 0);
        $ext  = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            return 'Dateityp nicht erlaubt: ' . $origName;
        }
        if ($size <= 0 || $size > $maxSize) {
            return 'Datei zu groß (max. 25 MB): ' . $origName;
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
            return 'Datei konnte nicht gespeichert werden: ' . $origName;
        }

        $mime = function_exists('mime_content_type')
            ? (mime_content_type($dir . '/' . $stored) ?: null)
            : null;
        $ins->execute([$orderId, $stored, mb_substr($origName, 0, 255), $mime, $size]);
    }
    return '';
}

/**
 * Loescht einen Auftrag endgueltig inkl. aller Dateien (Platte + DB).
 */
function purgeOrder(int $orderId): void
{
    $pdo   = db();
    $dir   = ordersDir();
    $stmt  = $pdo->prepare('SELECT stored_name FROM tm_order_files WHERE order_id = ?');
    $stmt->execute([$orderId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stored) {
        $path = $dir . '/' . basename((string) $stored);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $pdo->prepare('DELETE FROM tm_order_files WHERE order_id = ?')->execute([$orderId]);
    $pdo->prepare('DELETE FROM tm_orders WHERE id = ?')->execute([$orderId]);
}
