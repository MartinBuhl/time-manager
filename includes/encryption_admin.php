<?php
/**
 * Aktivieren und Deaktivieren der Verschlüsselung (Bereich „Sicherheit“).
 *
 * Die Umstellung läuft in kurzen Portionen und setzt nach einem Abbruch
 * einfach fort: Welche Werte noch fehlen, steht am Präfix in der Datenbank.
 * Klartext und verschlüsselte Werte dürfen so lange nebeneinander stehen.
 */

require_once __DIR__ . '/field_encryption.php';
require_once __DIR__ . '/db_backup.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/backup_files.php';

const ENC_PENDING_TTL   = 1800;
const ENC_STEP_SECONDS  = 2.0;
const ENC_BATCH_ROWS    = 100;
const ENC_MEDIUMTEXT_MAX = 16777215;

/** @return array<int, array{table:string, pk:string, column:string, where:string}> */
function encTargets(): array
{
    $tables = [
        'tm_customers' => ['id', [
            'billing_name', 'billing_street', 'billing_zip', 'billing_city', 'billing_email',
            'billing_email_cc', 'billing_tax_id', 'phone_landline', 'phone_mobile',
            'contact_first_name', 'contact_last_name', 'invoice_text',
            'mail_template_html', 'mail_template_plain',
        ]],
        'tm_invoices'    => ['id', ['invoice_text', 'mail_template_html', 'mail_template_plain']],
        'tm_mail_spool'  => ['id', ['subject', 'recipient', 'html_body', 'text_body']],
        'tm_orders'      => ['id', ['body']],
        'tm_order_files' => ['id', ['original_name']],
        'tm_expenses'    => ['id', ['description', 'url', 'username', 'email', 'pw_info']],
        'tm_payments'    => ['id', ['title', 'note', 'files']],
    ];

    $pdo     = db();
    $targets = [];
    foreach ($tables as $table => [$pk, $columns]) {
        $existing = dbRawStatement($pdo, "SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_intersect($columns, $existing) as $column) {
            $targets[] = ['table' => $table, 'pk' => $pk, 'column' => $column, 'where' => '1'];
        }
    }

    $plainKeys = implode(',', array_map([$pdo, 'quote'], FieldCipher::plaintextConfigKeys()));
    $targets[] = [
        'table'  => 'tm_configuration',
        'pk'     => 'configuration_id',
        'column' => 'configuration_value',
        'where'  => "configuration_key NOT IN ($plainKeys)",
    ];
    return $targets;
}

function encPendingCondition(array $target, bool $encrypt): string
{
    $column = '`' . $target['column'] . '`';
    $prefix = "'" . Crypto::FIELD_PREFIX . "%'";
    $match  = $encrypt
        ? "$column IS NOT NULL AND $column <> '' AND $column NOT LIKE $prefix"
        : "$column LIKE $prefix";
    return "({$target['where']}) AND $match";
}

function encRemaining(bool $encrypt): int
{
    $pdo   = db();
    $total = 0;
    foreach (encTargets() as $target) {
        $sql = "SELECT COUNT(*) FROM `{$target['table']}` WHERE " . encPendingCondition($target, $encrypt);
        $total += (int)dbRawStatement($pdo, $sql)->fetchColumn();
    }
    return $total + count(encPendingFiles($encrypt));
}

/** @return string[] Dateien, die in dieser Richtung noch umzuwandeln sind */
function encPendingFiles(bool $encrypt): array
{
    $pending = [];
    foreach ([dirname(__DIR__) . '/invoices', ordersDir(), paymentUploadDir()] as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $name = $file->getFilename();
            if ($file->isFile() && $name !== '.htaccess' && !str_ends_with($name, ENC_FILE_TMP_SUFFIX)
                && encFileIsEncrypted($file->getPathname()) !== $encrypt) {
                $pending[] = $file->getPathname();
            }
        }
    }
    return $pending;
}

/** Wandelt so viele Werte um, wie in die Zeitvorgabe passen. */
function encConvertBatch(bool $encrypt, string $dataKey): void
{
    $deadline = microtime(true) + ENC_STEP_SECONDS;
    foreach (encTargets() as $target) {
        while (microtime(true) < $deadline) {
            if (encConvertRows($target, $encrypt, $dataKey, $deadline) === 0) {
                break;
            }
        }
        if (microtime(true) >= $deadline) {
            return;
        }
    }
    foreach (encPendingFiles($encrypt) as $path) {
        encFileConvert($path, $encrypt, $dataKey);
        if (microtime(true) >= $deadline) {
            return;
        }
    }
}

/** @return int Anzahl der gefundenen Zeilen; 0 bedeutet: diese Spalte ist fertig */
function encConvertRows(array $target, bool $encrypt, string $dataKey, float $deadline): int
{
    $pdo = db();
    ['table' => $table, 'pk' => $pk, 'column' => $column] = $target;
    $rows = dbRawStatement(
        $pdo,
        "SELECT `$pk` AS pk, `$column` AS val FROM `$table` WHERE "
            . encPendingCondition($target, $encrypt) . ' LIMIT ' . ENC_BATCH_ROWS
    )->fetchAll(PDO::FETCH_ASSOC);

    $update  = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE `$pk` = ? AND `$column` = ?");
    $restore = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE `$pk` = ?");
    $reread = $pdo->prepare(
        "SELECT `$column` FROM `$table` WHERE `$pk` = ?",
        [PDO::ATTR_STATEMENT_CLASS => [PDOStatement::class]]
    );

    $changed = 0;
    foreach ($rows as $row) {
        $old = (string)$row['val'];
        $new = encConvertValue($old, $encrypt, $dataKey, "$table.$column #{$row['pk']}");

        // Bedingung auf den alten Wert: Eine parallele Änderung wird nicht überschrieben.
        $update->execute([$new, $row['pk'], $old]);
        if ($update->rowCount() > 0) {
            $changed++;
            $reread->execute([$row['pk']]);
            if ($reread->fetchColumn() !== $new) {
                $restore->execute([$old, $row['pk']]);
                throw new RuntimeException("Wert wurde nicht korrekt gespeichert: $table.$column #{$row['pk']}");
            }
        }
        if (microtime(true) >= $deadline) {
            break;
        }
    }
    if ($rows !== [] && $changed === 0) {
        throw new RuntimeException("Umstellung kommt nicht voran: $table.$column");
    }
    return count($rows);
}

function encConvertValue(string $value, bool $encrypt, string $dataKey, string $label): string
{
    if (!$encrypt) {
        try {
            return Crypto::decrypt($value, $dataKey);
        } catch (CryptoException) {
            throw new RuntimeException("Wert lässt sich nicht entschlüsseln: $label");
        }
    }
    $cipher = Crypto::encrypt($value, $dataKey);
    if (strlen($cipher) > ENC_MEDIUMTEXT_MAX) {
        throw new RuntimeException("Wert ist zu groß für die Verschlüsselung: $label");
    }
    if (Crypto::decrypt($cipher, $dataKey) !== $value) {
        throw new RuntimeException("Prüfung der Verschlüsselung fehlgeschlagen: $label");
    }
    return $cipher;
}

function encSetConfig(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO tm_configuration
             (configuration_key, configuration_value, configuration_group_id, sort_order, last_modified)
         VALUES (?, ?, 6, 0, NOW())
         ON DUPLICATE KEY UPDATE configuration_value = VALUES(configuration_value), last_modified = NOW()'
    )->execute([$key, $value]);
    FieldCipher::invalidateConfig();
}

function encVerifyPassword(int $userId, string $password): void
{
    $stmt = db()->prepare('SELECT password FROM tm_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    if (!password_verify($password, (string)$stmt->fetchColumn())) {
        throw new RuntimeException('Das Passwort ist nicht korrekt.');
    }
}

function encBackupDir(): string
{
    return dirname(__DIR__) . '/backups';
}

function encCreateSafetyBackup(): string
{
    $ts   = date('Y-m-d_His');
    $name = "tm_backup_pre_encryption_$ts.zip";
    backupWriteZip(backupDir() . '/' . $name, "tm_backup_pre_encryption_$ts.sql", true);
    return $name;
}

/** Schritt 1: Passwort prüfen und Wiederherstellungsschlüssel erzeugen. Ändert noch nichts. */
function encPrepare(int $userId, string $password): array
{
    if (!Crypto::isAvailable()) {
        throw new RuntimeException('Die PHP-Erweiterung sodium fehlt auf diesem Server.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Die PHP-Erweiterung zip fehlt auf diesem Server.');
    }
    if (FieldCipher::state() !== 'off') {
        throw new RuntimeException('Die Verschlüsselung ist bereits aktiv.');
    }
    encVerifyPassword($userId, $password);
    if (mb_strlen($password) < ENC_MIN_PASSWORD_LENGTH_ACTIVE) {
        throw new RuntimeException(
            'Das Passwort muss mindestens ' . ENC_MIN_PASSWORD_LENGTH_ACTIVE
            . ' Zeichen haben. Bitte zuerst in der Benutzerverwaltung ändern.'
        );
    }

    $dataKey    = Crypto::generateDataKey();
    $code       = Crypto::generateRecoveryCode();
    $normalized = Crypto::normalizeRecoveryCode($code);

    $_SESSION['enc_pending'] = [
        'key'      => bin2hex($dataKey),
        'envelope' => Crypto::wrapKey($dataKey, $password),
        'recovery' => Crypto::wrapKey($dataKey, $normalized),
        'tail'     => substr($normalized, -8),
        'at'       => time(),
    ];
    return ['code' => $code];
}

/** Schritt 2: Nach bestätigtem Code sichern und die Umstellung starten. */
function encConfirm(int $userId, string $tail): array
{
    $pending = $_SESSION['enc_pending'] ?? null;
    if (!is_array($pending) || time() - (int)$pending['at'] > ENC_PENDING_TTL) {
        unset($_SESSION['enc_pending']);
        throw new RuntimeException('Der Vorgang ist abgelaufen. Bitte neu starten.');
    }
    if (FieldCipher::state() !== 'off') {
        throw new RuntimeException('Die Verschlüsselung ist bereits aktiv.');
    }
    if (!hash_equals($pending['tail'], Crypto::cleanRecoveryInput($tail))) {
        throw new RuntimeException('Die eingegebenen Zeichen stimmen nicht mit dem Code überein.');
    }

    $backup  = encCreateSafetyBackup();
    $dataKey = (string)hex2bin($pending['key']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE tm_users SET enc_key_envelope = ? WHERE id = ?')
            ->execute([$pending['envelope'], $userId]);
        encSetConfig('encryption_recovery_envelope', $pending['recovery']);
        encSetConfig('encryption_state', 'encrypting');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        FieldCipher::invalidateConfig();
        throw $e;
    }

    unset($_SESSION['enc_pending']);
    encSessionStart($dataKey);
    $_SESSION['enc_total'] = encRemaining(true);
    return ['backup' => $backup, 'total' => $_SESSION['enc_total']];
}

function encCancel(): array
{
    unset($_SESSION['enc_pending']);
    return [];
}

function encDisable(int $userId, string $password): array
{
    if (FieldCipher::state() !== 'on') {
        throw new RuntimeException('Die Verschlüsselung ist nicht aktiv.');
    }
    encVerifyPassword($userId, $password);
    $backup = encCreateSafetyBackup();
    encSetConfig('encryption_state', 'decrypting');
    $_SESSION['enc_total'] = encRemaining(false);
    return ['backup' => $backup, 'total' => $_SESSION['enc_total']];
}

/** Eine Portion der laufenden Umstellung; schließt sie ab, wenn nichts mehr fehlt. */
function encStep(): array
{
    $state = FieldCipher::state();
    if ($state !== 'encrypting' && $state !== 'decrypting') {
        throw new RuntimeException('Es läuft keine Umstellung.');
    }
    $dataKey = encSessionDataKey();
    if ($dataKey === null) {
        throw new RuntimeException('Die Verschlüsselung ist nicht entsperrt. Bitte neu anmelden.');
    }

    @set_time_limit(60);
    $encrypt = $state === 'encrypting';
    encConvertBatch($encrypt, $dataKey);

    $remaining = encRemaining($encrypt);
    $total     = max((int)($_SESSION['enc_total'] ?? 0), $remaining);
    $_SESSION['enc_total'] = $total;

    if ($remaining > 0) {
        return ['state' => $state, 'remaining' => $remaining, 'total' => $total];
    }

    unset($_SESSION['enc_total']);
    if ($encrypt) {
        if (cfg('encryption_activated_at') === '') {
            encSetConfig('encryption_activated_at', (string)time());
        }
        encSetConfig('encryption_state', 'on');
        return ['state' => 'on', 'remaining' => 0, 'total' => $total];
    }
    encFinishDisable();
    return ['state' => 'off', 'remaining' => 0, 'total' => $total];
}

function encFinishDisable(): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE tm_users SET enc_key_envelope = NULL');
        encSetConfig('encryption_recovery_envelope', '');
        encSetConfig('encryption_activated_at', '');
        encSetConfig('encryption_state', 'off');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        FieldCipher::invalidateConfig();
        throw $e;
    }
    encSessionEnd();
}

/** Backups von vor dem Aktivieren: Sie enthalten die Daten noch im Klartext. */
function encPlaintextBackups(): array
{
    $activatedAt = (int)cfg('encryption_activated_at', '0');
    $files = [];
    if ($activatedAt > 0) {
        foreach (glob(encBackupDir() . '/*.{zip,sql}', GLOB_BRACE) ?: [] as $path) {
            if (filemtime($path) <= $activatedAt) {
                $files[] = [
                    'name'  => basename($path),
                    'size'  => filesize($path),
                    'mtime' => date('d.m.Y H:i', filemtime($path)),
                ];
            }
        }
    }
    $plainFiles = FieldCipher::state() === 'on' ? count(encPendingFiles(true)) : 0;
    return ['files' => $files, 'plain_files' => $plainFiles];
}

/** Dateien nachverschlüsseln, z. B. wenn nach einem Restore noch Klartext-Dateien liegen. */
function encResumeFiles(): array
{
    if (FieldCipher::state() !== 'on') {
        throw new RuntimeException('Die Verschlüsselung ist nicht aktiv.');
    }
    encSetConfig('encryption_state', 'encrypting');
    $_SESSION['enc_total'] = encRemaining(true);
    return ['total' => $_SESSION['enc_total']];
}

/**
 * Backups enthalten keine Dateien. Gehört ein Backup zu einem anderen Schlüssel,
 * wären die Dateien danach unlesbar, deshalb werden sie vorher entschlüsselt.
 */
function encFilesBeforeRestore(string $sql): void
{
    if (FieldCipher::state() === 'off') {
        return;
    }
    $backupEnvelope = preg_match("/'encryption_recovery_envelope'\\s*,\\s*'([^']*)'/", $sql, $m) ? $m[1] : '';
    if ($backupEnvelope === cfg('encryption_recovery_envelope')) {
        return;
    }
    $dataKey = encSessionDataKey();
    if ($dataKey === null) {
        throw new RuntimeException('Die Verschlüsselung ist nicht entsperrt.');
    }
    @set_time_limit(300);
    foreach (encPendingFiles(false) as $path) {
        encFileConvert($path, false, $dataKey);
    }
}

function encDeleteBackups(array $names): array
{
    $allowed = array_column(encPlaintextBackups()['files'], 'name');
    $deleted = 0;
    foreach ($names as $name) {
        if (in_array($name, $allowed, true) && @unlink(encBackupDir() . '/' . $name)) {
            $deleted++;
        }
    }
    return ['deleted' => $deleted];
}

function encHandleAction(string $action, array $post, int $userId): array
{
    return match ($action) {
        'enc_prepare'        => encPrepare($userId, (string)($post['password'] ?? '')),
        'enc_confirm'        => encConfirm($userId, (string)($post['tail'] ?? '')),
        'enc_cancel'         => encCancel(),
        'enc_disable'        => encDisable($userId, (string)($post['password'] ?? '')),
        'enc_step'           => encStep(),
        'enc_backups'        => encPlaintextBackups(),
        'enc_resume_files'   => encResumeFiles(),
        'enc_delete_backups' => encDeleteBackups(array_map('strval', (array)($post['files'] ?? []))),
    };
}
