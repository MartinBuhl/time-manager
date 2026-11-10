<?php
declare(strict_types=1);

/**
 * Krypto-Kern der Feldverschlüsselung (libsodium).
 *
 * Modell: Ein zufälliger Datenschlüssel verschlüsselt die Felder. Er liegt
 * nur "verpackt" in der Datenbank: je einmal mit einem aus dem Login-Passwort
 * bzw. dem Wiederherstellungsschlüssel abgeleiteten Schlüssel (Argon2id).
 * Eine Passwortänderung tauscht daher nur die Verpackung aus.
 *
 * Diese Klasse kennt weder Datenbank noch Session.
 *
 * Formate (ASCII, Versionskennung im Präfix):
 *   Feldwert: enc:v1:base64(nonce | ciphertext+tag)
 *   Hülle:    env:v1:base64(ops | mem | salt | nonce | ciphertext+tag)
 */
final class CryptoException extends RuntimeException
{
}

final class Crypto
{
    public const FIELD_PREFIX     = 'enc:v1:';
    public const FILE_MAGIC       = "TMENC1\n";
    private const ENVELOPE_PREFIX = 'env:v1:';

    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    private const TAG_BYTES   = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
    private const KEY_BYTES   = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    private const SALT_BYTES  = SODIUM_CRYPTO_PWHASH_SALTBYTES;
    private const BASE64      = SODIUM_BASE64_VARIANT_ORIGINAL;

    /** Argon2id-Kosten neuer Hüllen; sie werden je Hülle mitgespeichert. */
    private const PWHASH_OPS = 3;
    private const PWHASH_MEM = 134217728; // 128 MiB

    /** Grenzen beim Öffnen, damit manipulierte Hüllen den Server nicht lahmlegen. */
    private const PWHASH_OPS_MAX = 16;
    private const PWHASH_MEM_MIN = 8192; // Argon2id-Minimum, PHP hat dafür keine Konstante
    private const PWHASH_MEM_MAX = 1073741824; // 1 GiB

    /** Crockford-Base32: ohne I, L, O, U (Verwechslungsgefahr). */
    private const RECOVERY_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const RECOVERY_BYTES    = 20; // 160 Bit → 32 Zeichen
    private const RECOVERY_LENGTH   = 32;

    public static function isAvailable(): bool
    {
        return extension_loaded('sodium');
    }

    public static function generateDataKey(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    public static function encrypt(string $plaintext, string $dataKey): string
    {
        self::assertKey($dataKey);
        $nonce  = random_bytes(self::NONCE_BYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $dataKey);
        return self::FIELD_PREFIX . sodium_bin2base64($nonce . $cipher, self::BASE64);
    }

    /** @throws CryptoException bei falschem Schlüssel, Manipulation oder ungültigem Format */
    public static function decrypt(string $value, string $dataKey): string
    {
        self::assertKey($dataKey);
        $raw = self::decodePayload($value, self::FIELD_PREFIX);
        if ($raw === null || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new CryptoException('Ungültiges Format des verschlüsselten Werts.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, self::NONCE_BYTES),
            '',
            substr($raw, 0, self::NONCE_BYTES),
            $dataKey
        );
        if ($plain === false) {
            throw new CryptoException('Entschlüsselung fehlgeschlagen.');
        }
        return $plain;
    }

    /** Wie encrypt(), aber binär: Dateien werden sonst durch Base64 ein Drittel größer. */
    public static function encryptBytes(string $plaintext, string $dataKey): string
    {
        self::assertKey($dataKey);
        $nonce = random_bytes(self::NONCE_BYTES);
        return self::FILE_MAGIC . $nonce
            . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $dataKey);
    }

    /** @throws CryptoException bei falschem Schlüssel, Manipulation oder ungültigem Format */
    public static function decryptBytes(string $data, string $dataKey): string
    {
        self::assertKey($dataKey);
        $headerBytes = strlen(self::FILE_MAGIC) + self::NONCE_BYTES;
        if (!str_starts_with($data, self::FILE_MAGIC) || strlen($data) < $headerBytes + self::TAG_BYTES) {
            throw new CryptoException('Ungültiges Format der verschlüsselten Datei.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($data, $headerBytes),
            '',
            substr($data, strlen(self::FILE_MAGIC), self::NONCE_BYTES),
            $dataKey
        );
        if ($plain === false) {
            throw new CryptoException('Entschlüsselung der Datei fehlgeschlagen.');
        }
        return $plain;
    }

    /** Prüft die Struktur, damit Klartext mit gleichem Anfang nicht als verschlüsselt gilt. */
    public static function isEncrypted(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        $raw = self::decodePayload($value, self::FIELD_PREFIX);
        return $raw !== null && strlen($raw) >= self::NONCE_BYTES + self::TAG_BYTES;
    }

    /** Verpackt den Datenschlüssel mit einem Passwort oder Wiederherstellungsschlüssel. */
    public static function wrapKey(string $dataKey, string $secret): string
    {
        self::assertKey($dataKey);
        $salt  = random_bytes(self::SALT_BYTES);
        $nonce = random_bytes(self::NONCE_BYTES);
        $kek   = self::deriveKey($secret, $salt, self::PWHASH_OPS, self::PWHASH_MEM);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dataKey, '', $nonce, $kek);
        sodium_memzero($kek);

        return self::ENVELOPE_PREFIX . sodium_bin2base64(
            pack('NN', self::PWHASH_OPS, self::PWHASH_MEM) . $salt . $nonce . $cipher,
            self::BASE64
        );
    }

    /** @throws CryptoException bei falschem Geheimnis oder beschädigter Hülle */
    public static function unwrapKey(string $envelope, string $secret): string
    {
        $headerBytes = 8 + self::SALT_BYTES + self::NONCE_BYTES;
        $raw = self::decodePayload($envelope, self::ENVELOPE_PREFIX);
        if ($raw === null || strlen($raw) !== $headerBytes + self::KEY_BYTES + self::TAG_BYTES) {
            throw new CryptoException('Ungültiges Format der Schlüssel-Hülle.');
        }
        ['ops' => $ops, 'mem' => $mem] = unpack('Nops/Nmem', substr($raw, 0, 8));
        if ($ops < 1 || $ops > self::PWHASH_OPS_MAX
            || $mem < self::PWHASH_MEM_MIN || $mem > self::PWHASH_MEM_MAX) {
            throw new CryptoException('Ungültige Parameter in der Schlüssel-Hülle.');
        }
        $salt  = substr($raw, 8, self::SALT_BYTES);
        $nonce = substr($raw, 8 + self::SALT_BYTES, self::NONCE_BYTES);

        $kek = self::deriveKey($secret, $salt, $ops, $mem);
        $key = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $headerBytes), '', $nonce, $kek);
        sodium_memzero($kek);

        if ($key === false) {
            throw new CryptoException('Falsches Passwort oder falscher Wiederherstellungsschlüssel.');
        }
        return $key;
    }

    /** Erzeugt einen Code im Format XXXX-XXXX-… (8 Blöcke, 160 Bit). */
    public static function generateRecoveryCode(): string
    {
        $bytes  = random_bytes(self::RECOVERY_BYTES);
        $buffer = 0;
        $bits   = 0;
        $code   = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits  += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $code .= self::RECOVERY_ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        return implode('-', str_split($code, 4));
    }

    /**
     * Bringt einen eingegebenen Code in die Form, mit der die Hülle erstellt
     * wurde: ohne Trenner, Großbuchstaben, verwechselbare Zeichen korrigiert.
     *
     * @throws CryptoException wenn Länge oder Zeichen nicht passen
     */
    public static function normalizeRecoveryCode(string $input): string
    {
        $code = self::cleanRecoveryInput($input);
        if (strlen($code) !== self::RECOVERY_LENGTH
            || strspn($code, self::RECOVERY_ALPHABET) !== self::RECOVERY_LENGTH) {
            throw new CryptoException('Der Wiederherstellungsschlüssel hat ein ungültiges Format.');
        }
        return $code;
    }

    /** Entfernt Trenner und korrigiert verwechselbare Zeichen. */
    public static function cleanRecoveryInput(string $input): string
    {
        return strtr(strtoupper(preg_replace('/[\s-]+/', '', $input) ?? ''), ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    private static function deriveKey(string $secret, string $salt, int $ops, int $mem): string
    {
        try {
            return sodium_crypto_pwhash(
                self::KEY_BYTES,
                $secret,
                $salt,
                $ops,
                $mem,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            );
        } catch (SodiumException $e) {
            throw new CryptoException('Schlüsselableitung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Liefert die Binärdaten hinter dem Präfix oder null, wenn das Format nicht passt. */
    private static function decodePayload(string $value, string $prefix): ?string
    {
        if (!str_starts_with($value, $prefix)) {
            return null;
        }
        try {
            return sodium_base642bin(substr($value, strlen($prefix)), self::BASE64);
        } catch (SodiumException) {
            return null;
        }
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new CryptoException('Ungültige Schlüssellänge.');
        }
    }
}
