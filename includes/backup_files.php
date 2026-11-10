<?php
declare(strict_types=1);

/**
 * Backups mit Dateien: Datenbank-Dump plus die Ordner mit Rechnungen,
 * Auftragsanhängen und Belegen in einem ZIP.
 *
 * Aufbau des ZIP:
 *   tm_backup_….sql        Datenbank (wie bisher)
 *   manifest.json          Version, Verschlüsselungsstatus, Dateiliste mit Prüfsummen
 *   files/<ordner>/<pfad>  Dateien so, wie sie auf der Platte liegen
 *                          (bei aktiver Verschlüsselung also verschlüsselt)
 *
 * ZIPs ohne manifest.json sind reine Datenbank-Backups und funktionieren wie bisher.
 */

require_once __DIR__ . '/db_backup.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/payments.php';

const BACKUP_MANIFEST           = 'manifest.json';
const BACKUP_FILES_PREFIX       = 'files/';
const BACKUP_MAIL_MAX_BYTES     = 20 * 1024 * 1024;
const BACKUP_RESTORE_TMP_SUFFIX = '.restore-tmp';
const BACKUP_NAME_PATTERN       = '/^tm_backup_[\w\-]+\.zip$/';

/** Gesicherte Ordner: Schlüssel = Pfad im ZIP unter files/. */
function backupFileRoots(): array
{
    return [
        'invoices'         => dirname(__DIR__) . '/invoices',
        'orders'           => ordersDir(),
        'uploads/payments' => paymentUploadDir(),
    ];
}

/** Backup-Verzeichnis anlegen und gegen Direktzugriff schützen. */
function backupDir(): string
{
    $dir = dirname(__DIR__) . '/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException('Backup-Verzeichnis konnte nicht angelegt werden.');
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

/** Dateien, die ins Backup gehören und beim Restore ersetzt werden: ZIP-Pfad => Pfad auf der Platte. */
function backupListFiles(): array
{
    $list = [];
    foreach (backupFileRoots() as $key => $root) {
        if (!is_dir($root)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file->isFile() || $file->isLink() || backupIsIgnoredName($file->getFilename())) {
                continue;
            }
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            $list[BACKUP_FILES_PREFIX . $key . '/' . $rel] = $file->getPathname();
        }
    }
    ksort($list);
    return $list;
}

/** .htaccess und Zwischenstände der Verschlüsselung bzw. des Restores gehören nicht ins Backup. */
function backupIsIgnoredName(string $name): bool
{
    return $name === '.htaccess'
        || str_ends_with($name, BACKUP_RESTORE_TMP_SUFFIX)
        || (defined('ENC_FILE_TMP_SUFFIX') && str_ends_with($name, ENC_FILE_TMP_SUFFIX));
}

/**
 * Prüft einen Pfad aus dem ZIP und liefert das Ziel auf der Platte,
 * oder null, wenn er nicht in einen der gesicherten Ordner gehört.
 */
function backupTargetPath(string $zipPath): ?string
{
    if (!str_starts_with($zipPath, BACKUP_FILES_PREFIX)
        || str_contains($zipPath, '\\') || str_contains($zipPath, "\0")) {
        return null;
    }
    $rest = substr($zipPath, strlen(BACKUP_FILES_PREFIX));
    foreach (backupFileRoots() as $key => $root) {
        if (!str_starts_with($rest, $key . '/')) {
            continue;
        }
        $rel = substr($rest, strlen($key) + 1);
        $segments = explode('/', $rel);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        if (backupIsIgnoredName(end($segments))) {
            return null;
        }
        return $root . '/' . $rel;
    }
    return null;
}

/** Schreibt ein Backup-ZIP. Bei Fehlern bleibt keine halbe Datei liegen. */
function backupWriteZip(string $path, string $sqlName, bool $withFiles): void
{
    $sql = buildSqlDump(db());
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP-Datei konnte nicht erstellt werden.');
    }
    $zip->addFromString($sqlName, $sql);
    unset($sql);

    if ($withFiles) {
        $manifest = [
            'format'           => 1,
            'created'          => date('c'),
            'app_version'      => defined('APP_VERSION') ? APP_VERSION : '',
            'encryption_state' => class_exists('FieldCipher') ? FieldCipher::state() : 'off',
            'files'            => [],
        ];
        foreach (backupListFiles() as $zipPath => $diskPath) {
            $hash = hash_file('sha256', $diskPath);
            if ($hash === false) {
                $zip->close();
                @unlink($path);
                throw new RuntimeException('Datei konnte nicht gelesen werden: ' . basename($diskPath));
            }
            $manifest['files'][$zipPath] = ['size' => filesize($diskPath), 'sha256' => $hash];
            $zip->addFile($diskPath, $zipPath);
            // PDFs, Bilder und verschlüsselte Dateien lassen sich kaum packen.
            $zip->setCompressionName($zipPath, ZipArchive::CM_STORE);
        }
        $zip->addFromString(BACKUP_MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    if (!$zip->close() || !is_file($path) || filesize($path) === 0) {
        @unlink($path);
        throw new RuntimeException('ZIP-Datei konnte nicht geschrieben werden.');
    }
}

/** true, wenn das ZIP Dateien enthält (also eine manifest.json hat). */
function backupHasFiles(string $path): bool
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $has = $zip->locateName(BACKUP_MANIFEST) !== false;
    $zip->close();
    return $has;
}

/** Angaben für die Backup-Liste. */
function backupInfo(string $path): array
{
    $size = (int)filesize($path);
    return [
        'name'     => basename($path),
        'size'     => $size,
        'mtime'    => date('d.m.Y H:i:s', (int)filemtime($path)),
        'files'    => backupHasFiles($path),
        'mailable' => $size <= BACKUP_MAIL_MAX_BYTES,
    ];
}

/**
 * Öffnet und prüft ein Backup-ZIP.
 *
 * @return array{zip: ZipArchive, sql: string, manifest: ?array}
 * @throws RuntimeException wenn das ZIP kein gültiges Backup ist
 */
function backupOpen(string $path, bool $readSql = true): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Die Datei ist kein gültiges ZIP-Archiv.');
    }
    try {
        $sqlIndex = null;
        $fileEntries = [];
        for ($k = 0; $k < $zip->numFiles; $k++) {
            $name = (string)$zip->getNameIndex($k);
            if (str_starts_with($name, BACKUP_FILES_PREFIX)) {
                if (!str_ends_with($name, '/')) {
                    $fileEntries[$name] = true;
                }
                continue;
            }
            // Nur eine SQL-Datei auf oberster Ebene zählt, keine aus files/.
            if ($sqlIndex === null && !str_contains($name, '/')
                && strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) === 'sql') {
                $sqlIndex = $k;
            }
        }
        if ($sqlIndex === null) {
            throw new RuntimeException('Das ZIP enthält keine .sql-Datei – kein gültiges Time-Manager-Backup.');
        }

        $manifest = null;
        $raw = $zip->getFromName(BACKUP_MANIFEST);
        if ($raw !== false) {
            $manifest = json_decode($raw, true);
            if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
                throw new RuntimeException('Die Dateiliste im Backup ist beschädigt.');
            }
            foreach ($manifest['files'] as $zipPath => $meta) {
                if (backupTargetPath((string)$zipPath) === null || !isset($fileEntries[$zipPath])
                    || !is_array($meta) || !is_string($meta['sha256'] ?? null) || !is_int($meta['size'] ?? null)) {
                    throw new RuntimeException('Ungültiger Eintrag im Backup: ' . $zipPath);
                }
            }
            foreach (array_keys($fileEntries) as $zipPath) {
                if (!isset($manifest['files'][$zipPath])) {
                    throw new RuntimeException('Datei im Backup fehlt in der Dateiliste: ' . $zipPath);
                }
            }
        } elseif ($fileEntries) {
            throw new RuntimeException('Das Backup enthält Dateien, aber keine Dateiliste.');
        }

        $sql = '';
        if ($readSql) {
            $sql = $zip->getFromIndex($sqlIndex);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Im Backup wurde keine SQL-Datei gefunden.');
            }
        }
    } catch (RuntimeException $e) {
        $zip->close();
        throw $e;
    }
    return ['zip' => $zip, 'sql' => $sql, 'manifest' => $manifest];
}

/**
 * Entpackt alle Dateien neben ihr Ziel (…restore-tmp) und prüft Größe und
 * Prüfsumme. Ändert noch keine bestehende Datei.
 *
 * @return array<string,string> Zwischendatei => Ziel
 */
function backupStageFiles(ZipArchive $zip, array $manifest): array
{
    $staged = [];
    try {
        foreach ($manifest['files'] as $zipPath => $meta) {
            $target = backupTargetPath($zipPath);
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                throw new RuntimeException('Ordner konnte nicht angelegt werden: ' . $dir);
            }
            $tmp = $target . BACKUP_RESTORE_TMP_SUFFIX;
            $in  = $zip->getStream($zipPath);
            $out = @fopen($tmp, 'wb');
            if ($in === false || $out === false) {
                if ($out !== false) {
                    fclose($out);
                }
                throw new RuntimeException('Datei konnte nicht entpackt werden: ' . $zipPath);
            }
            $staged[$tmp] = $target;
            $hash = hash_init('sha256');
            $size = 0;
            while (!feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    break;
                }
                hash_update($hash, $chunk);
                $size += strlen($chunk);
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    fclose($in);
                    fclose($out);
                    throw new RuntimeException('Datei konnte nicht geschrieben werden: ' . $zipPath);
                }
            }
            fclose($in);
            fclose($out);
            if ($size !== $meta['size'] || !hash_equals($meta['sha256'], hash_final($hash))) {
                throw new RuntimeException('Prüfsumme stimmt nicht: ' . $zipPath);
            }
        }
    } catch (RuntimeException $e) {
        backupDiscardStaged($staged);
        throw $e;
    }
    return $staged;
}

function backupDiscardStaged(array $staged): void
{
    foreach (array_keys($staged) as $tmp) {
        @unlink($tmp);
    }
}

/**
 * Setzt die Ordner exakt auf den Stand des Backups: entpackte Dateien
 * übernehmen, Dateien, die im Backup nicht vorkommen, entfernen.
 *
 * @return array{restored: int, removed: int}
 */
function backupCommitFiles(array $staged): array
{
    $failed = [];
    foreach ($staged as $tmp => $target) {
        if (!@rename($tmp, $target)) {
            $failed[] = $target;
            @unlink($tmp);
        }
    }
    $keep = array_flip(array_values($staged));
    $removed = 0;
    foreach (backupListFiles() as $diskPath) {
        if (!isset($keep[$diskPath])) {
            if (@unlink($diskPath)) {
                $removed++;
            } else {
                $failed[] = $diskPath;
            }
        }
    }
    if ($failed) {
        throw new RuntimeException(count($failed) . ' Datei(en) konnten nicht ersetzt oder entfernt werden, z. B. '
            . basename($failed[0]) . '.');
    }
    return ['restored' => count($staged), 'removed' => $removed];
}

/** Kleinste der Upload-Grenzen des Servers in Bytes (0 = unbegrenzt). */
function backupUploadLimit(): int
{
    $limits = [];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $value = trim((string)ini_get($key));
        $bytes = (int)$value;
        $unit = strtolower(substr($value, -1));
        $bytes *= match ($unit) {
            'g'     => 1073741824,
            'm'     => 1048576,
            'k'     => 1024,
            default => 1,
        };
        if ($bytes > 0) {
            $limits[] = $bytes;
        }
    }
    return $limits ? min($limits) : 0;
}
