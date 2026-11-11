<?php
declare(strict_types=1);

/**
 * Schlanker IMAP-Client (RFC 3501) über eine TLS-Verbindung.
 * Ohne PHP-Erweiterung imap, ohne Abhängigkeiten.
 *
 * Unterstützt das, was der Web-Client braucht: Anmelden, Ordner, Status,
 * Nachrichten abrufen, Flags, Verschieben, Löschen, Ablegen (APPEND), Suche.
 */
final class ImapException extends RuntimeException
{
}

final class ImapClient
{
    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;
    /** @var array<string,bool> */
    private array $caps = [];
    private ?string $selected = null;
    private int $exists = 0;

    public function __construct(
        private string $host,
        private int $port = 993,
        private string $encryption = 'ssl',
        private int $timeout = 20
    ) {
    }

    public function __destruct()
    {
        if ($this->sock) {
            @fwrite($this->sock, 'Z' . (++$this->tag) . " LOGOUT\r\n");
            @fclose($this->sock);
        }
    }

    // ------------------------------------------------------------------
    // Verbindung
    // ------------------------------------------------------------------

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $this->host,
            'SNI_enabled'      => true,
        ]]);
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $sock = @stream_socket_client(
            "$scheme://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$sock) {
            throw new ImapException("Verbindung zu {$this->host}:{$this->port} fehlgeschlagen: "
                . ($errstr ?: 'unbekannter Fehler'));
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;

        $greeting = $this->readResponse();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            throw new ImapException('Unerwartete Begrüßung des Servers: ' . substr($greeting, 0, 200));
        }
        $this->captureCaps($greeting);

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            $ok = @stream_socket_enable_crypto(
                $sock,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if (!$ok) {
                throw new ImapException('STARTTLS fehlgeschlagen (Zertifikat oder TLS-Version).');
            }
            $this->caps = [];
        }
    }

    public function login(string $user, string $password): void
    {
        if (!$this->caps) {
            $this->capability();
        }
        if ($this->hasCap('LOGINDISABLED')) {
            throw new ImapException('Der Server erlaubt keine Anmeldung ohne Verschlüsselung.');
        }
        try {
            $this->command('LOGIN ', $this->astring($user), ' ', $this->astring($password));
        } catch (ImapException $e) {
            throw new ImapException('Anmeldung fehlgeschlagen: ' . $e->getMessage());
        }
        // Viele Server melden nach dem Login andere Fähigkeiten.
        $this->capability();
    }

    public function logout(): void
    {
        if ($this->sock) {
            try {
                $this->command('LOGOUT');
            } catch (ImapException) {
            }
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function capability(): array
    {
        $this->caps = [];
        foreach ($this->command('CAPABILITY') as $line) {
            $this->captureCaps($line);
        }
        return array_keys($this->caps);
    }

    public function hasCap(string $cap): bool
    {
        return isset($this->caps[strtoupper($cap)]);
    }

    // ------------------------------------------------------------------
    // Ordner
    // ------------------------------------------------------------------

    /**
     * @return list<array{name:string, display:string, delimiter:string, flags:list<string>, selectable:bool}>
     */
    public function listFolders(): array
    {
        $cmd = $this->hasCap('SPECIAL-USE') ? 'LIST "" "*" RETURN (SPECIAL-USE)' : 'LIST "" "*"';
        $folders = [];
        foreach ($this->command($cmd) as $line) {
            $t = self::parse($line);
            if (($t[1] ?? '') === '' || strtoupper((string)$t[1]) !== 'LIST' || !is_array($t[2] ?? null)) {
                continue;
            }
            $flags = array_map(fn($f) => strtolower((string)$f), $t[2]);
            $name  = (string)($t[4] ?? '');
            if ($name === '') {
                continue;
            }
            $folders[] = [
                'name'       => $name,
                'display'    => self::decodeFolderName($name),
                'delimiter'  => (string)($t[3] ?? ''),
                'flags'      => $flags,
                'selectable' => !in_array('\\noselect', $flags, true) && !in_array('\\nonexistent', $flags, true),
            ];
        }
        return $folders;
    }

    /** @return array{messages:int, unseen:int} */
    public function status(string $folder): array
    {
        $out = ['messages' => 0, 'unseen' => 0];
        foreach ($this->command('STATUS ', $this->astring($folder), ' (MESSAGES UNSEEN)') as $line) {
            $t = self::parse($line);
            if (strtoupper((string)($t[1] ?? '')) !== 'STATUS' || !is_array(end($t))) {
                continue;
            }
            $items = end($t);
            for ($i = 0; $i + 1 < count($items); $i += 2) {
                $key = strtolower((string)$items[$i]);
                if (isset($out[$key])) {
                    $out[$key] = (int)$items[$i + 1];
                }
            }
        }
        return $out;
    }

    /** @return array{exists:int, uidvalidity:int, uidnext:int} */
    public function select(string $folder, bool $readOnly = false): array
    {
        $info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        $lines = $this->command($readOnly ? 'EXAMINE ' : 'SELECT ', $this->astring($folder));
        foreach ($lines as $line) {
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) {
                $info['exists'] = (int)$m[1];
            } elseif (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) {
                $info['uidvalidity'] = (int)$m[1];
            } elseif (preg_match('/\[UIDNEXT (\d+)\]/i', $line, $m)) {
                $info['uidnext'] = (int)$m[1];
            }
        }
        $this->selected = $folder;
        $this->exists = $info['exists'];
        return $info;
    }

    // ------------------------------------------------------------------
    // Nachrichten
    // ------------------------------------------------------------------

    /**
     * FETCH über Sequenznummern oder UIDs.
     *
     * @return array<int, array<string,mixed>> Schlüssel = Sequenznummer; Werte = Datenelemente
     *         (Namen in Großbuchstaben, z. B. UID, FLAGS, BODY[]).
     */
    public function fetch(string $set, string $items, bool $byUid = false): array
    {
        $out = [];
        foreach ($this->command(($byUid ? 'UID FETCH ' : 'FETCH ') . $set . ' (' . $items . ')') as $line) {
            if (!preg_match('/^\* (\d+) FETCH /i', $line, $m)) {
                continue;
            }
            $t = self::parse($line);
            $list = $t[3] ?? null;
            if (!is_array($list)) {
                continue;
            }
            $data = [];
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                $key = strtoupper((string)$list[$i]);
                // BODY[HEADER.FIELDS (…)] und BODY.PEEK[…] einheitlich benennen
                $key = preg_replace('/^BODY\[HEADER\.FIELDS.*$/', 'HEADER', $key);
                $data[$key] = $list[$i + 1];
            }
            $seq = (int)$m[1];
            $out[$seq] = isset($out[$seq]) ? $out[$seq] + $data : $data;
        }
        return $out;
    }

    /** Ganze Nachricht roh, ohne sie als gelesen zu markieren. */
    public function fetchRaw(int $uid): ?string
    {
        foreach ($this->fetch((string)$uid, 'UID BODY.PEEK[]', true) as $data) {
            if ((int)($data['UID'] ?? 0) === $uid && isset($data['BODY[]'])) {
                return (string)$data['BODY[]'];
            }
        }
        return null;
    }

    /** @param list<int> $uids */
    public function setFlags(array $uids, array $flags, bool $add): void
    {
        $this->command('UID STORE ' . self::uidSet($uids) . ($add ? ' +' : ' -')
            . 'FLAGS.SILENT (' . implode(' ', $flags) . ')');
    }

    /** @param list<int> $uids */
    public function move(array $uids, string $target): void
    {
        $set = self::uidSet($uids);
        if ($this->hasCap('MOVE')) {
            $this->command('UID MOVE ' . $set . ' ', $this->astring($target));
            return;
        }
        $this->command('UID COPY ' . $set . ' ', $this->astring($target));
        $this->expungeUids($uids);
    }

    /** Endgültig löschen. @param list<int> $uids */
    public function delete(array $uids): void
    {
        $this->expungeUids($uids);
    }

    /** @param list<int> $uids */
    private function expungeUids(array $uids): void
    {
        $set = self::uidSet($uids);
        $this->command('UID STORE ' . $set . ' +FLAGS.SILENT (\\Deleted)');
        // Ohne UIDPLUS würde EXPUNGE auch fremde, schon markierte Nachrichten entfernen.
        $this->command($this->hasCap('UIDPLUS') ? 'UID EXPUNGE ' . $set : 'EXPUNGE');
    }

    /** Legt eine Nachricht in einem Ordner ab (z. B. „Gesendet“). */
    public function append(string $folder, string $message, array $flags = ['\\Seen']): void
    {
        $message = preg_replace("/\r?\n/", "\r\n", $message);
        $this->command('APPEND ', $this->astring($folder), ' (' . implode(' ', $flags) . ') ', ['literal' => $message]);
    }

    /**
     * Volltextsuche in Betreff, Absender, Empfänger und Text.
     *
     * @return list<int> UIDs
     */
    public function search(string $text): array
    {
        $q = ['literal' => $text];
        $parts = ['UID SEARCH CHARSET UTF-8 OR OR OR SUBJECT ', $q, ' FROM ', $q, ' TO ', $q, ' BODY ', $q];
        try {
            $lines = $this->command(...$parts);
        } catch (ImapException) {
            // Manche Server kennen CHARSET nicht; reines ASCII geht dann trotzdem.
            $lines = $this->command('UID SEARCH OR OR OR SUBJECT ', $this->astring($text), ' FROM ',
                $this->astring($text), ' TO ', $this->astring($text), ' BODY ', $this->astring($text));
        }
        return self::searchResult($lines);
    }

    /**
     * Sucht Nachrichten im ausgewählten Ordner über einen Kopfzeilenwert (z. B. Message-ID).
     *
     * @return list<int> UIDs
     */
    public function searchHeader(string $name, string $value): array
    {
        return self::searchResult($this->command('UID SEARCH HEADER ', $this->astring($name), ' ', $this->astring($value)));
    }

    /** @return list<int> */
    private static function searchResult(array $lines): array
    {
        $uids = [];
        foreach ($lines as $line) {
            if (preg_match('/^\* SEARCH ?(.*)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $n) {
                    if (ctype_digit($n)) {
                        $uids[] = (int)$n;
                    }
                }
            }
        }
        return $uids;
    }

    // ------------------------------------------------------------------
    // Protokoll
    // ------------------------------------------------------------------

    /**
     * Sendet einen Befehl. Teile sind Strings oder ['literal' => Daten].
     *
     * @return list<string> ungetaggte Antworten
     */
    public function command(string|array ...$parts): array
    {
        if (!$this->sock) {
            throw new ImapException('Keine Verbindung.');
        }
        $tag = 'A' . (++$this->tag);
        $buffer = $tag . ' ';
        foreach ($parts as $part) {
            if (!is_array($part)) {
                $buffer .= $part;
                continue;
            }
            $data = (string)$part['literal'];
            if ($this->hasCap('LITERAL+')) {
                $buffer .= '{' . strlen($data) . "+}\r\n" . $data;
                continue;
            }
            $this->write($buffer . '{' . strlen($data) . "}\r\n");
            $buffer = '';
            $resp = $this->readResponse();
            if (!str_starts_with($resp, '+')) {
                throw new ImapException(self::errorText($resp, $tag));
            }
            $buffer = $data;
        }
        $this->write($buffer . "\r\n");

        $untagged = [];
        while (true) {
            $resp = $this->readResponse();
            if (str_starts_with($resp, $tag . ' ')) {
                $rest = substr($resp, strlen($tag) + 1);
                if (strncasecmp($rest, 'OK', 2) === 0) {
                    $this->captureCaps($rest);
                    return $untagged;
                }
                throw new ImapException(self::errorText($resp, $tag));
            }
            if (str_starts_with($resp, '* ')) {
                if (preg_match('/^\* BYE/i', $resp) && !preg_match('/LOGOUT$/i', $parts[0] ?? '')) {
                    throw new ImapException('Server hat die Verbindung beendet: ' . substr($resp, 6, 200));
                }
                $untagged[] = $resp;
            }
        }
    }

    private static function errorText(string $resp, string $tag): string
    {
        $text = preg_replace('/^' . preg_quote($tag, '/') . ' (NO|BAD)\s*(\[[^\]]*\]\s*)?/i', '', $resp);
        return trim((string)$text) ?: 'Fehler vom Server.';
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        for ($done = 0; $done < $len;) {
            $n = @fwrite($this->sock, substr($data, $done, 65536));
            if ($n === false || $n === 0) {
                throw new ImapException('Verbindung beim Senden getrennt.');
            }
            $done += $n;
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->sock);
        if ($line === false) {
            $meta = stream_get_meta_data($this->sock);
            throw new ImapException($meta['timed_out'] ? 'Zeitüberschreitung beim Mailserver.' : 'Verbindung getrennt.');
        }
        return $line;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->sock, min(1048576, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['timed_out'] || feof($this->sock)) {
                    throw new ImapException('Verbindung beim Lesen getrennt.');
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** Eine vollständige Antwort inkl. Literale, ohne abschließendes CRLF. */
    private function readResponse(): string
    {
        $out = '';
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $out .= $line . $this->readBytes((int)$m[1]);
                continue;
            }
            return $out . rtrim($line, "\r\n");
        }
    }

    private function captureCaps(string $line): void
    {
        if (preg_match('/^\* CAPABILITY (.+)$/i', $line, $m) || preg_match('/\[CAPABILITY ([^\]]+)\]/i', $line, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $cap) {
                $this->caps[strtoupper($cap)] = true;
            }
        }
    }

    /** Quoted String oder Literal (bei Zeilenumbrüchen und 8-Bit-Zeichen). */
    private function astring(string $s): string|array
    {
        if (preg_match('/[\x00-\x1f\x7f-\xff]/', $s)) {
            return ['literal' => $s];
        }
        return '"' . addcslashes($s, '"\\') . '"';
    }

    /** @param list<int> $uids */
    public static function uidSet(array $uids): string
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn($u) => $u > 0)));
        if (!$uids) {
            throw new ImapException('Keine Nachricht ausgewählt.');
        }
        return implode(',', $uids);
    }

    /**
     * Zerlegt eine Antwort in Atome, Strings, Literale (string), NIL (null) und Listen (array).
     */
    public static function parse(string $s): array
    {
        $i = 0;
        return self::parseList($s, $i, strlen($s));
    }

    private static function parseList(string $s, int &$i, int $len): array
    {
        $out = [];
        while ($i < $len) {
            $c = $s[$i];
            if ($c === ' ') {
                $i++;
            } elseif ($c === ')') {
                $i++;
                return $out;
            } elseif ($c === '(') {
                $i++;
                $out[] = self::parseList($s, $i, $len);
            } elseif ($c === '"') {
                $i++;
                $buf = '';
                while ($i < $len && $s[$i] !== '"') {
                    if ($s[$i] === '\\' && $i + 1 < $len) {
                        $i++;
                    }
                    $buf .= $s[$i];
                    $i++;
                }
                $i++;
                $out[] = $buf;
            } elseif ($c === '{' && preg_match('/\G\{(\d+)\+?\}\r?\n/', $s, $m, 0, $i)) {
                $i += strlen($m[0]);
                $out[] = substr($s, $i, (int)$m[1]);
                $i += (int)$m[1];
            } else {
                $start = $i;
                while ($i < $len) {
                    $c = $s[$i];
                    if ($c === ' ' || $c === '(' || $c === ')') {
                        break;
                    }
                    if ($c === '[') {
                        $close = strpos($s, ']', $i);
                        $i = $close === false ? $len : $close + 1;
                        continue;
                    }
                    $i++;
                }
                $atom = substr($s, $start, $i - $start);
                $out[] = strtoupper($atom) === 'NIL' ? null : $atom;
            }
        }
        return $out;
    }

    /** Ordnernamen sind „modified UTF-7“ (RFC 3501, 5.1.3). */
    public static function decodeFolderName(string $name): string
    {
        if (!str_contains($name, '&')) {
            return $name;
        }
        $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
        return is_string($decoded) && $decoded !== '' ? $decoded : $name;
    }
}
