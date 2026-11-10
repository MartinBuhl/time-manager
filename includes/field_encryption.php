<?php
/**
 * Feldverschlüsselung für die App: Lesen entschlüsselt automatisch
 * (DecryptingStatement), Schreiben läuft an den betroffenen Stellen über
 * encField() bzw. encConfig().
 *
 * Solange encryption_state 'off' ist, bleibt alles unverändert. Klartext wird
 * immer toleriert, deshalb können während der Umstellung beide Formen
 * nebeneinander in einer Tabelle stehen.
 */

require_once __DIR__ . '/Crypto.php';

final class FieldCipher
{
    /** Konfigurationswerte, die nie verschlüsselt werden (vor dem Login nötig oder rein intern). */
    private const PLAINTEXT_CONFIG_KEYS = [
        'site_url',
        'mail_from',
        'mail_name',
        'default_lang',
        'encryption_state',
        'encryption_recovery_envelope',
        'payment_reminders_last_run',
        'demo_customer_ids',
        'encryption_activated_at',
    ];

    private static ?string $dataKey = null;
    private static int $configGeneration = 0;

    public static function setKey(?string $dataKey): void
    {
        if ($dataKey !== self::$dataKey) {
            self::$dataKey = $dataKey;
            self::invalidateConfig();
        }
    }

    /** cfg() lädt beim nächsten Aufruf neu. */
    public static function invalidateConfig(): void
    {
        self::$configGeneration++;
    }

    public static function configGeneration(): int
    {
        return self::$configGeneration;
    }

    /** @return string[] */
    public static function plaintextConfigKeys(): array
    {
        return self::PLAINTEXT_CONFIG_KEYS;
    }

    /** @throws RuntimeException wenn kein Schlüssel vorliegt */
    public static function wrapCurrentKey(string $secret): string
    {
        if (self::$dataKey === null) {
            throw new RuntimeException('Die Verschlüsselung ist aktiv, aber nicht entsperrt.');
        }
        return Crypto::wrapKey(self::$dataKey, $secret);
    }

    public static function isUnlocked(): bool
    {
        return self::$dataKey !== null;
    }

    public static function state(): string
    {
        return cfg('encryption_state', 'off');
    }

    /** @throws CryptoException wenn die Datei verschlüsselt ist und nicht geöffnet werden kann */
    public static function decryptFileContent(string $data): string
    {
        if (!str_starts_with($data, Crypto::FILE_MAGIC)) {
            return $data;
        }
        if (self::$dataKey === null) {
            throw new CryptoException('Die Verschlüsselung ist nicht entsperrt.');
        }
        return Crypto::decryptBytes($data, self::$dataKey);
    }

    public static function protectFile(string $path): void
    {
        $state = self::state();
        if ($state !== 'on' && $state !== 'encrypting') {
            return;
        }
        if (self::$dataKey === null) {
            @unlink($path);
            throw new RuntimeException('Die Verschlüsselung ist aktiv, aber nicht entsperrt.');
        }
        encFileConvert($path, true, self::$dataKey);
    }

    public static function isPlaintextConfigKey(string $key): bool
    {
        return in_array($key, self::PLAINTEXT_CONFIG_KEYS, true);
    }

    /** Verschlüsselter Wert, für den gerade kein Schlüssel vorliegt? */
    public static function isLocked(mixed $value): bool
    {
        return self::$dataKey === null && self::looksEncrypted($value);
    }

    /** Null und Leerstring bleiben, damit SQL-Prüfungen wie `<> ''` weiter stimmen. */
    public static function encrypt(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        $state = self::state();
        if ($state !== 'on' && $state !== 'encrypting') {
            return $value;
        }
        if (self::$dataKey === null) {
            // Niemals stillschweigend Klartext in eine verschlüsselte Datenbank schreiben.
            throw new RuntimeException('Die Verschlüsselung ist aktiv, aber nicht entsperrt.');
        }
        return Crypto::isEncrypted($value) ? $value : Crypto::encrypt($value, self::$dataKey);
    }

    /** Ohne Schlüssel kommt der verschlüsselte Wert unverändert zurück. */
    public static function decrypt(mixed $value): mixed
    {
        if (self::$dataKey === null || !self::looksEncrypted($value)) {
            return $value;
        }
        return Crypto::decrypt($value, self::$dataKey);
    }

    public static function decryptDeep(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'decryptDeep'], $value);
        }
        return self::decrypt($value);
    }

    private static function looksEncrypted(mixed $value): bool
    {
        return is_string($value)
            && str_starts_with($value, 'enc:')
            && Crypto::isAvailable()
            && Crypto::isEncrypted($value);
    }
}

/** Entschlüsselt alle Lesewege. Fürs Backup umgehen: siehe dbRawStatement(). */
class DecryptingStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return FieldCipher::decryptDeep(parent::fetch($mode, $cursorOrientation, $cursorOffset));
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return FieldCipher::decryptDeep(parent::fetchAll($mode, ...$args));
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return FieldCipher::decrypt(parent::fetchColumn($column));
    }

    public function getIterator(): Iterator
    {
        while (($row = $this->fetch()) !== false) {
            yield $row;
        }
    }
}

/** Führt eine Abfrage aus, deren Werte unverändert (also ggf. verschlüsselt) bleiben. */
function dbRawStatement(PDO $pdo, string $sql): PDOStatement
{
    $stmt = $pdo->prepare($sql, [PDO::ATTR_STATEMENT_CLASS => [PDOStatement::class]]);
    $stmt->execute();
    return $stmt;
}

function encField(mixed $value): mixed
{
    return FieldCipher::encrypt($value);
}

function encConfig(string $key, mixed $value): mixed
{
    return FieldCipher::isPlaintextConfigKey($key) ? $value : FieldCipher::encrypt($value);
}

/* ------------------------------------------------------------------
   Dateien (Rechnungs-PDFs, Anhänge): gleiche Schlüssel wie die Felder.
   Der Dateiname bleibt, verschlüsselte Dateien erkennt man am Dateianfang.
------------------------------------------------------------------ */

function encFileIsEncrypted(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $head = (string)fread($handle, strlen(Crypto::FILE_MAGIC));
    fclose($handle);
    return $head === Crypto::FILE_MAGIC;
}

/** @throws CryptoException wenn die Datei verschlüsselt ist und nicht geöffnet werden kann */
function encFileRead(string $path): string
{
    $data = file_get_contents($path);
    if ($data === false) {
        throw new CryptoException('Datei konnte nicht gelesen werden.');
    }
    return FieldCipher::decryptFileContent($data);
}

/** Liefert die Datei aus und setzt Content-Length; die übrigen Header setzt der Aufrufer. */
function encFileSend(string $path): void
{
    if (!encFileIsEncrypted($path)) {
        header('Content-Length: ' . filesize($path));
        readfile($path);
        return;
    }
    try {
        $content = encFileRead($path);
    } catch (CryptoException $e) {
        http_response_code(500);
        header_remove('Content-Disposition');
        header('Content-Type: text/plain; charset=utf-8');
        exit('Die Datei konnte nicht entschlüsselt werden.');
    }
    header('Content-Length: ' . strlen($content));
    echo $content;
}

/** Nach dem Schreiben einer neuen Datei aufrufen. */
function encFileProtect(string $path): void
{
    FieldCipher::protectFile($path);
}

/** Wandelt eine Datei atomar um; das Original bleibt bis zur bestandenen Prüfung. */
function encFileConvert(string $path, bool $encrypt, string $dataKey): void
{
    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Datei konnte nicht gelesen werden: ' . basename($path));
    }
    try {
        $new = $encrypt ? Crypto::encryptBytes($data, $dataKey) : Crypto::decryptBytes($data, $dataKey);
    } catch (CryptoException) {
        throw new RuntimeException('Datei lässt sich nicht umwandeln: ' . basename($path));
    }

    $tmp = $path . ENC_FILE_TMP_SUFFIX;
    if (file_put_contents($tmp, $new, LOCK_EX) !== strlen($new) || file_get_contents($tmp) !== $new
        || ($encrypt && Crypto::decryptBytes($new, $dataKey) !== $data)) {
        @unlink($tmp);
        throw new RuntimeException('Prüfung der Datei fehlgeschlagen: ' . basename($path));
    }
    @chmod($tmp, fileperms($path) & 0777);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Datei konnte nicht ersetzt werden: ' . basename($path));
    }
}

/* ------------------------------------------------------------------
   Sitzung: Der Datenschlüssel liegt verschlüsselt in der Session, der
   Schlüssel dazu in einem HttpOnly-Cookie. Keines von beiden reicht allein.
------------------------------------------------------------------ */

const ENC_FILE_TMP_SUFFIX = '.enc-tmp';
const ENC_KEY_COOKIE = 'tm_ek';
const ENC_MIN_PASSWORD_LENGTH_ACTIVE = 12;

/** Das Passwort schützt bei aktiver Verschlüsselung die Daten, daher die höhere Grenze. */
function encMinPasswordLength(): int
{
    return FieldCipher::state() === 'off' ? 8 : ENC_MIN_PASSWORD_LENGTH_ACTIVE;
}

/**
 * Liefert den Datenschlüssel beim Login; null, wenn die Verschlüsselung aus ist.
 *
 * @throws CryptoException wenn das Konto keinen passenden Schlüssel hat
 */
function encLoginDataKey(int $userId, string $password): ?string
{
    if (FieldCipher::state() === 'off') {
        return null;
    }
    $stmt = db()->prepare('SELECT enc_key_envelope FROM tm_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return Crypto::unwrapKey((string)$stmt->fetchColumn(), $password);
}

/** Muss laufen, bevor Header gesendet werden und nachdem die Session aktiv ist. */
function encSessionStart(string $dataKey): void
{
    $cookieKey = Crypto::generateDataKey();
    $_SESSION['enc_key_blob'] = Crypto::encrypt($dataKey, $cookieKey);
    $_SESSION['enc_epoch']    = encEpoch();
    encSetKeyCookie(bin2hex($cookieKey), false);
    FieldCipher::setKey($dataKey);
}

/**
 * Nach session_start() aufrufen. Lässt sich der Schlüssel nicht herstellen,
 * wird die Anmeldung verworfen, damit die normale Login-Prüfung greift.
 */
function encSessionRestore(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    if (FieldCipher::state() === 'off') {
        if (isset($_SESSION['enc_key_blob'])) {
            encSessionEnd();
        }
        return;
    }
    $dataKey = encSessionDataKey();
    if ($dataKey === null) {
        encSessionEnd();
        unset($_SESSION['user_id']);
        return;
    }
    FieldCipher::setKey($dataKey);
}

function encSessionEnd(): void
{
    unset($_SESSION['enc_key_blob'], $_SESSION['enc_epoch']);
    if (isset($_COOKIE[ENC_KEY_COOKIE])) {
        encSetKeyCookie('', true);
    }
    FieldCipher::setKey(null);
}

/** Verpackt den aktuellen Datenschlüssel neu; null, wenn die Verschlüsselung aus ist. */
function encEnvelopeForPassword(string $password): ?string
{
    return FieldCipher::state() === 'off' ? null : FieldCipher::wrapCurrentKey($password);
}

function encSessionDataKey(): ?string
{
    $blob   = $_SESSION['enc_key_blob'] ?? null;
    $epoch  = $_SESSION['enc_epoch'] ?? null;
    $cookie = $_COOKIE[ENC_KEY_COOKIE] ?? null;
    if (!is_string($blob) || !is_string($epoch) || !is_string($cookie)
        || !hash_equals(encEpoch(), $epoch) || !ctype_xdigit($cookie)) {
        return null;
    }
    try {
        return Crypto::decrypt($blob, (string)hex2bin($cookie));
    } catch (CryptoException) {
        return null;
    }
}

/** Ändert sich bei jedem Aktivieren und beim Einspielen eines anderen Backups. */
function encEpoch(): string
{
    return hash('sha256', cfg('encryption_recovery_envelope'));
}

function encSetKeyCookie(string $value, bool $expire): void
{
    $params = session_get_cookie_params();
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $expires = $params['lifetime'] > 0 ? time() + $params['lifetime'] : 0;
    setcookie(ENC_KEY_COOKIE, $value, [
        'expires'  => $expire ? time() - 3600 : $expires,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if ($expire) {
        unset($_COOKIE[ENC_KEY_COOKIE]);
    } else {
        $_COOKIE[ENC_KEY_COOKIE] = $value;
    }
}
