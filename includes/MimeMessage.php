<?php
declare(strict_types=1);

/**
 * Zerlegt E-Mails (RFC 5322 / MIME) für die Anzeige im Web-Client.
 * Ohne Abhängigkeiten; nutzt mbstring/iconv für Zeichensätze.
 */
final class MimePart
{
    /** @var array<string, list<string>> Kopfzeilen, Name in Kleinbuchstaben, Wert roh (entfaltet) */
    public array $headers = [];
    public string $type = 'text/plain';
    /** @var array<string,string> */
    public array $params = [];
    public string $disposition = '';
    /** @var array<string,string> */
    public array $dispParams = [];
    public string $body = '';
    /** @var list<MimePart> */
    public array $parts = [];
    public string $id = '1';

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function filename(): string
    {
        $name = $this->dispParams['filename'] ?? $this->params['name'] ?? '';
        return MimeMessage::decodeHeader($name);
    }

    public function contentId(): string
    {
        return trim((string)$this->header('content-id'), " \t<>");
    }

    /** Inhalt nach Transfer-Decodierung (Bytes). */
    public function content(): string
    {
        $enc = strtolower(trim((string)$this->header('content-transfer-encoding')));
        return match ($enc) {
            'base64'           => (string)base64_decode((string)preg_replace('/[^A-Za-z0-9+\/=]/', '', $this->body)),
            'quoted-printable' => quoted_printable_decode($this->body),
            default            => $this->body,
        };
    }

    /** Text als UTF-8. */
    public function text(): string
    {
        return MimeMessage::toUtf8($this->content(), $this->params['charset'] ?? null);
    }

    public function isMultipart(): bool
    {
        return str_starts_with($this->type, 'multipart/');
    }
}

final class MimeMessage
{
    private const MAX_DEPTH = 20;

    public static function parse(string $raw, string $id = '1', int $depth = 0): MimePart
    {
        $part = new MimePart();
        $part->id = $id;

        if (preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $headerBlock = substr($raw, 0, $m[0][1]);
            $part->body  = substr($raw, $m[0][1] + strlen($m[0][0]));
        } elseif (preg_match('/^[\x21-\x39\x3b-\x7e]+:/', $raw)) {
            $headerBlock = $raw; // nur Kopfzeilen
        } else {
            $headerBlock = '';
            $part->body  = $raw;
        }
        $part->headers = self::parseHeaders($headerBlock);

        [$type, $params] = self::parseParams((string)$part->header('content-type'));
        $part->type   = $type !== '' && str_contains($type, '/') ? strtolower($type) : 'text/plain';
        $part->params = $params;
        [$disp, $dispParams] = self::parseParams((string)$part->header('content-disposition'));
        $part->disposition = strtolower($disp);
        $part->dispParams  = $dispParams;

        if ($part->isMultipart() && isset($params['boundary']) && $depth < self::MAX_DEPTH) {
            $n = 1;
            foreach (self::splitMultipart($part->body, $params['boundary']) as $chunk) {
                $part->parts[] = self::parse($chunk, $id === '' ? (string)$n : $id . '.' . $n, $depth + 1);
                $n++;
            }
            $part->body = '';
        }
        return $part;
    }

    /** @return array<string, list<string>> */
    public static function parseHeaders(string $block): array
    {
        $headers = [];
        $block = (string)preg_replace('/\r?\n(?=[ \t])/', '', $block);
        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $headers[$name][] = trim(substr($line, $pos + 1));
        }
        return $headers;
    }

    /** @return list<string> */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $re = '/(?:^|\r?\n)--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?=\r?\n|$)/';
        if (!preg_match_all($re, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $chunks = [];
        $count = count($matches);
        for ($k = 0; $k < $count; $k++) {
            $m = $matches[$k];
            if (isset($m[1]) && $m[1][0] === '--') {
                break; // Abschluss-Trenner
            }
            $start = $m[0][1] + strlen($m[0][0]);
            // Zeilenumbruch nach dem Trenner gehört nicht zum Teil
            if (substr($body, $start, 2) === "\r\n") {
                $start += 2;
            } elseif (($body[$start] ?? '') === "\n") {
                $start += 1;
            }
            $end = $k + 1 < $count ? $matches[$k + 1][0][1] : strlen($body);
            $chunks[] = substr($body, $start, max(0, $end - $start));
        }
        return $chunks;
    }

    /**
     * „text/plain; charset="utf-8"; name*=UTF-8''…“ → [Hauptwert, Parameter]
     * Mit RFC-2231-Fortsetzungen und -Kodierung.
     *
     * @return array{0:string, 1:array<string,string>}
     */
    public static function parseParams(string $value): array
    {
        $segments = [];
        $buf = '';
        $quoted = false;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if ($c === '\\' && $quoted && $i + 1 < $len) {
                $buf .= $value[++$i];
                continue;
            }
            if ($c === '"') {
                $quoted = !$quoted;
                continue;
            }
            if ($c === ';' && !$quoted) {
                $segments[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $segments[] = $buf;

        $main = strtolower(trim((string)array_shift($segments)));
        $simple = [];
        $extended = []; // name => [index => [value, encoded]]
        foreach ($segments as $seg) {
            $pos = strpos($seg, '=');
            if ($pos === false) {
                continue;
            }
            $key = strtolower(trim(substr($seg, 0, $pos)));
            $val = trim(substr($seg, $pos + 1));
            if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $key, $m)) {
                $extended[$m[1]][(int)($m[2] ?? 0)] = [$val, isset($m[3]) || !isset($m[2]) || $m[2] === ''];
                if (!isset($m[2]) || $m[2] === '') {
                    $extended[$m[1]][0][1] = true; // name*=charset''wert
                }
            } else {
                $simple[$key] = $val;
            }
        }
        foreach ($extended as $name => $pieces) {
            ksort($pieces);
            $charset = null;
            $bytes = '';
            foreach ($pieces as $idx => [$val, $encoded]) {
                if ($encoded) {
                    if ($idx === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $m)) {
                        $charset = $m[1] !== '' ? $m[1] : null;
                        $val = $m[2];
                    }
                    $val = rawurldecode($val);
                }
                $bytes .= $val;
            }
            $simple[$name] = self::toUtf8($bytes, $charset);
        }
        return [$main, $simple];
    }

    /** Dekodiert „encoded words“ (RFC 2047) und liefert UTF-8. */
    public static function decodeHeader(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $value = self::toUtf8($value, null);
        if (!str_contains($value, '=?')) {
            return $value;
        }
        $tokens = preg_split('/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        $pendCharset = null;
        $pend = '';
        $last = count($tokens) - 1;
        foreach ($tokens as $idx => $tok) {
            if (preg_match('/^=\?([^?*]+)(?:\*[^?]*)?\?([BbQq])\?([^?]*)\?=$/', $tok, $m)) {
                $bytes = strtoupper($m[2]) === 'B'
                    ? (string)base64_decode($m[3])
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                $charset = strtolower($m[1]);
                if ($pendCharset !== null && $pendCharset !== $charset) {
                    $out .= self::toUtf8($pend, $pendCharset);
                    $pend = '';
                }
                $pendCharset = $charset;
                $pend .= $bytes;
                continue;
            }
            // Leerraum zwischen zwei kodierten Wörtern entfällt (RFC 2047, 6.2)
            if ($pendCharset !== null && $idx < $last && trim($tok) === '') {
                continue;
            }
            if ($pendCharset !== null) {
                $out .= self::toUtf8($pend, $pendCharset);
                $pend = '';
                $pendCharset = null;
            }
            $out .= $tok;
        }
        if ($pendCharset !== null) {
            $out .= self::toUtf8($pend, $pendCharset);
        }
        return $out;
    }

    /** Wandelt in UTF-8; unbekannte oder fehlende Zeichensätze werden geraten. */
    public static function toUtf8(string $s, ?string $charset): string
    {
        $cs = strtolower(trim((string)$charset, " \t\"'"));
        $cs = match ($cs) {
            '', 'us-ascii', 'ascii', 'utf8', 'unicode-1-1-utf-8' => 'utf-8',
            'ks_c_5601-1987'                                    => 'cp949',
            'iso-8859-8-i'                                      => 'iso-8859-8',
            'latin1', 'latin-1'                                 => 'iso-8859-1',
            default                                             => $cs,
        };
        if ($cs === 'utf-8') {
            return mb_check_encoding($s, 'UTF-8') ? $s : self::fromWindows1252($s);
        }
        if ($cs === 'iso-8859-1') {
            $cs = 'windows-1252'; // Obermenge, so wie Mail-Programme es behandeln
        }
        try {
            $out = mb_convert_encoding($s, 'UTF-8', $cs);
            if (is_string($out)) {
                return $out;
            }
        } catch (ValueError) {
        }
        $out = @iconv($cs, 'UTF-8//IGNORE', $s);
        if (is_string($out) && $out !== '') {
            return $out;
        }
        return mb_check_encoding($s, 'UTF-8') ? $s : self::fromWindows1252($s);
    }

    private static function fromWindows1252(string $s): string
    {
        return (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    /**
     * „Name <a@b>, "Nach, Vor" <c@d>, e@f“ → Liste von ['name' => …, 'email' => …]
     *
     * @return list<array{name:string, email:string}>
     */
    public static function addresses(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }
        $items = [];
        $buf = '';
        $quoted = false;
        $angle = false;
        $len = strlen($header);
        for ($i = 0; $i < $len; $i++) {
            $c = $header[$i];
            if ($c === '\\' && $quoted && $i + 1 < $len) {
                $buf .= $c . $header[++$i];
                continue;
            }
            if ($c === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && $c === '<') {
                $angle = true;
            } elseif (!$quoted && $c === '>') {
                $angle = false;
            } elseif (!$quoted && !$angle && ($c === ',' || $c === ';')) {
                $items[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $items[] = $buf;

        $out = [];
        foreach ($items as $item) {
            $item = trim($item);
            // Gruppen-Syntax „Name: a@b, c@d;“
            if (preg_match('/^[^"<@]*:\s*(.*)$/s', $item, $m)) {
                $item = trim($m[1]);
            }
            if ($item === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^>]*)>\s*$/s', $item, $m)) {
                $name  = trim($m[1]);
                $email = trim($m[2]);
            } else {
                $name  = '';
                $email = trim($item, " \t()");
            }
            $name = trim($name);
            if (strlen($name) >= 2 && $name[0] === '"' && str_ends_with($name, '"')) {
                $name = stripcslashes(substr($name, 1, -1));
            }
            if ($email === '' && $name === '') {
                continue;
            }
            $out[] = ['name' => self::decodeHeader($name), 'email' => $email];
        }
        return $out;
    }

    /**
     * Bereitet eine Nachricht für die Anzeige auf.
     *
     * @return array{subject:string, from:list, to:list, cc:list, reply_to:list, date:?string,
     *               message_id:string, references:string, html:?string, text:?string,
     *               attachments:list<array{id:string, filename:string, type:string, size:int}>,
     *               remote_content:bool}
     */
    public static function analyze(string $raw): array
    {
        $root = self::parse($raw, '');
        $state = ['html' => null, 'text' => null, 'attachments' => [], 'cids' => []];
        self::walk($root, $state);

        $html = $state['html'];
        if ($html !== null) {
            // cid:-Verweise durch eingebettete Daten ersetzen
            $used = [];
            $html = (string)preg_replace_callback('/cid:([^"\'\s>)]+)/i', function ($m) use (&$state, &$used) {
                $cid = rawurldecode($m[1]);
                $part = $state['cids'][$cid] ?? null;
                if (!$part || strlen($part->body) > 7000000) {
                    return $m[0];
                }
                $used[$part->id] = true;
                return 'data:' . $part->type . ';base64,' . base64_encode($part->content());
            }, $html);
            $state['attachments'] = array_values(array_filter(
                $state['attachments'],
                fn($a) => !isset($used[$a['id']])
            ));
        }

        $date = $root->header('date');
        $ts = $date !== null ? strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $date) ?? $date) : false;

        return [
            'subject'        => self::decodeHeader($root->header('subject')),
            'from'           => self::addresses($root->header('from')),
            'to'             => self::addresses($root->header('to')),
            'cc'             => self::addresses($root->header('cc')),
            'reply_to'       => self::addresses($root->header('reply-to')),
            'bcc'            => self::addresses($root->header('bcc')),
            'in_reply_to'    => trim((string)$root->header('in-reply-to')),
            'date'           => $ts ? date('c', $ts) : null,
            'message_id'     => trim((string)$root->header('message-id')),
            'references'     => trim((string)$root->header('references')),
            'html'           => $html,
            'text'           => $state['text'],
            'attachments'    => array_map(fn($a) => [
                'id'       => $a['id'],
                'filename' => $a['filename'],
                'type'     => $a['type'],
                'size'     => $a['size'],
            ], $state['attachments']),
            'remote_content' => $html !== null
                && (bool)preg_match('/(?:src|srcset|background)\s*=\s*["\']?\s*(?:https?:)?\/\/|url\(\s*["\']?\s*(?:https?:)?\/\//i', $html),
        ];
    }

    /** Wandelt HTML in lesbaren Text um (für Zitate beim Antworten und Weiterleiten). */
    public static function htmlToText(string $html): string
    {
        $html = (string)preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string)preg_replace('#<!--.*?-->#s', '', $html);
        // Links als „Text (Adresse)“, sofern sich beides unterscheidet
        $html = (string)preg_replace_callback('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is', function ($m) {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim(strip_tags($m[3]));
            if (!preg_match('#^(https?:|mailto:)#i', $url) || $label === '' || html_entity_decode($label) === $url
                || 'mailto:' . html_entity_decode($label) === $url) {
                return $m[3];
            }
            return $m[3] . ' (' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . ')';
        }, $html);
        $html = (string)preg_replace('#<li\b[^>]*>#i', "\n• ", $html);
        $html = (string)preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string)preg_replace('#</?(p|div|tr|table|h[1-6]|ul|ol|blockquote|pre|hr)\b[^>]*>#i', "\n", $html);
        $html = (string)preg_replace('#</t[dh]\s*>#i', "\t", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string)preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string)preg_replace('/\n[ \t]+/', "\n", $text);
        $text = (string)preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /** Sucht einen Teil über seine ID (z. B. „2.1“). */
    public static function findPart(string $raw, string $id): ?MimePart
    {
        $stack = [self::parse($raw, '')];
        while ($stack) {
            $part = array_pop($stack);
            if ($part->id === $id) {
                return $part;
            }
            foreach ($part->parts as $child) {
                $stack[] = $child;
            }
        }
        return null;
    }

    private static function walk(MimePart $part, array &$state): void
    {
        if ($part->isMultipart()) {
            if ($part->type === 'multipart/alternative' && $part->parts) {
                // Die letzte Alternative ist die reichhaltigste; Text als Rückfall merken.
                $best = null;
                foreach ($part->parts as $child) {
                    if ($child->type === 'text/plain' && $state['text'] === null && !self::isAttachment($child)) {
                        $state['text'] = $child->text();
                    } elseif ($child->type === 'text/html' || $child->isMultipart()) {
                        $best = $child;
                    }
                }
                if ($best !== null) {
                    self::walk($best, $state);
                }
                return;
            }
            foreach ($part->parts as $child) {
                self::walk($child, $state);
            }
            return;
        }

        $cid = $part->contentId();
        if ($cid !== '') {
            $state['cids'][$cid] = $part;
        }

        if (!self::isAttachment($part)) {
            if ($part->type === 'text/html') {
                $state['html'] = $state['html'] === null ? $part->text() : $state['html'] . '<hr>' . $part->text();
                return;
            }
            if ($part->type === 'text/plain') {
                $state['text'] = $state['text'] === null ? $part->text() : $state['text'] . "\n\n" . $part->text();
                return;
            }
        }

        $filename = $part->filename();
        if ($filename === '') {
            $filename = $part->type === 'message/rfc822'
                ? (self::decodeHeader(self::parse($part->content())->header('subject')) ?: 'nachricht') . '.eml'
                : 'anhang-' . str_replace('.', '-', $part->id) . self::extensionFor($part->type);
        }
        $state['attachments'][] = [
            'id'       => $part->id,
            'filename' => $filename,
            'type'     => $part->type,
            'size'     => self::decodedSize($part),
        ];
    }

    private static function isAttachment(MimePart $part): bool
    {
        if ($part->disposition === 'attachment') {
            return true;
        }
        if (!in_array($part->type, ['text/plain', 'text/html'], true)) {
            return true;
        }
        return $part->filename() !== '' && $part->disposition !== 'inline';
    }

    private static function decodedSize(MimePart $part): int
    {
        $enc = strtolower(trim((string)$part->header('content-transfer-encoding')));
        if ($enc === 'base64') {
            return (int)floor(strlen((string)preg_replace('/[^A-Za-z0-9+\/]/', '', $part->body)) * 3 / 4);
        }
        return strlen($part->body);
    }

    private static function extensionFor(string $type): string
    {
        return match ($type) {
            'application/pdf' => '.pdf',
            'image/jpeg'      => '.jpg',
            'image/png'       => '.png',
            'image/gif'       => '.gif',
            'text/calendar'   => '.ics',
            'text/plain'      => '.txt',
            'text/html'       => '.html',
            default           => '',
        };
    }
}
