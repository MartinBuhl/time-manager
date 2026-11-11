<?php
declare(strict_types=1);

/**
 * Gemeinsame Funktionen des E-Mail-Clients (admin/mail.php, admin/mail_api.php).
 * Zugangsdaten stehen in tm_configuration (Gruppe 7, Schlüssel mc_*).
 */

require_once __DIR__ . '/ImapClient.php';
require_once __DIR__ . '/MimeMessage.php';

const MC_PAGE_SIZE      = 50;
const MC_MAX_RAW_BYTES  = 60 * 1024 * 1024;
const MC_SECRET_KEYS    = ['mc_imap_password', 'mc_smtp_password'];
const MC_SETTING_KEYS   = [
    'mc_from_name', 'mc_from_email',
    'mc_imap_host', 'mc_imap_port', 'mc_imap_encryption', 'mc_imap_user', 'mc_imap_password',
    'mc_smtp_host', 'mc_smtp_port', 'mc_smtp_encryption', 'mc_smtp_user', 'mc_smtp_password',
    'mc_sent_folder', 'mc_trash_folder', 'mc_drafts_folder', 'mc_save_sent', 'mc_signature',
];

/** Einstellungen; $override (z. B. aus dem Formular) gewinnt, leere Passwörter bleiben gespeichert. */
function mcSettings(array $override = []): array
{
    $s = [];
    foreach (MC_SETTING_KEYS as $key) {
        $s[$key] = array_key_exists($key, $override) ? trim((string)$override[$key]) : trim(cfg($key));
        if (in_array($key, MC_SECRET_KEYS, true) && $s[$key] === '') {
            $s[$key] = cfg($key);
        }
    }
    return $s;
}

function mcIsConfigured(): bool
{
    $s = mcSettings();
    return $s['mc_imap_host'] !== '' && $s['mc_imap_user'] !== '';
}

function mcEncryption(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['ssl', 'tls', 'none'], true) ? $value : 'ssl';
}

/** Verbindet und meldet sich an. */
function mcImap(?array $settings = null): ImapClient
{
    $s = $settings ?? mcSettings();
    if ($s['mc_imap_host'] === '' || $s['mc_imap_user'] === '') {
        throw new ImapException('Der E-Mail-Client ist noch nicht eingerichtet (Konfiguration → E-Mail-Client).');
    }
    $enc = mcEncryption($s['mc_imap_encryption']);
    $port = (int)$s['mc_imap_port'] ?: ($enc === 'ssl' ? 993 : 143);
    $imap = new ImapClient($s['mc_imap_host'], $port, $enc);
    $imap->connect();
    $imap->login($s['mc_imap_user'], $s['mc_imap_password']);
    return $imap;
}

/** Namen, an denen Sonderordner erkannt werden, wenn der Server keine SPECIAL-USE-Flags liefert. */
function mcSpecialNames(): array
{
    return [
        'drafts'  => ['drafts', 'entwürfe', 'entwurf', 'draft'],
        'sent'    => ['sent', 'gesendet', 'gesendete elemente', 'gesendete objekte', 'sent items', 'sent messages', 'sent mail'],
        'junk'    => ['junk', 'spam', 'junk-e-mail', 'junk e-mail', 'bulk mail'],
        'trash'   => ['trash', 'papierkorb', 'gelöschte elemente', 'gelöschte objekte', 'deleted items', 'deleted messages', 'bin'],
        'archive' => ['archive', 'archiv'],
    ];
}

/**
 * Ordnerliste mit Rolle, Ebene und Zählern, sortiert wie in Mail-Programmen:
 * Posteingang, Entwürfe, Gesendet, Spam, Papierkorb, Archiv, dann alphabetisch.
 */
function mcFolders(ImapClient $imap, bool $withStatus = true): array
{
    $s = mcSettings();
    $configured = [
        'sent'   => $s['mc_sent_folder'],
        'trash'  => $s['mc_trash_folder'],
        'drafts' => $s['mc_drafts_folder'],
    ];
    $folders = $imap->listFolders();
    $selectable = array_values(array_filter($folders, fn($f) => $f['selectable']));
    $roles = [];

    // 1. Einstellung, 2. SPECIAL-USE-Flag, 3. Name
    foreach ($configured as $role => $name) {
        if ($name !== '') {
            foreach ($selectable as $f) {
                if ($f['name'] === $name || $f['display'] === $name) {
                    $roles[$role] = $f['name'];
                }
            }
        }
    }
    foreach ($selectable as $f) {
        foreach (['drafts', 'sent', 'junk', 'trash', 'archive'] as $role) {
            if (!isset($roles[$role]) && in_array('\\' . $role, $f['flags'], true)) {
                $roles[$role] = $f['name'];
            }
        }
    }
    foreach (mcSpecialNames() as $role => $names) {
        if (isset($roles[$role])) {
            continue;
        }
        foreach ($selectable as $f) {
            $leaf = mb_strtolower(mcLeafName($f));
            $full = mb_strtolower($f['display']);
            if (in_array($leaf, $names, true) || in_array($full, $names, true)) {
                $roles[$role] = $f['name'];
                break;
            }
        }
    }
    $roleOf = array_flip($roles);
    $order = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'junk' => 3, 'trash' => 4, 'archive' => 5];

    $out = [];
    foreach ($folders as $f) {
        $role = strtoupper($f['name']) === 'INBOX' ? 'inbox' : ($roleOf[$f['name']] ?? '');
        $level = $f['delimiter'] !== '' ? substr_count($f['name'], $f['delimiter']) : 0;
        // Server mit Namensraum „INBOX.“ (z. B. Courier): INBOX.Kunden als Hauptebene zeigen
        if ($level > 0 && stripos($f['name'], 'INBOX' . $f['delimiter']) === 0) {
            $level--;
        }
        $item = [
            'name'       => $f['name'],
            'display'    => $role === 'inbox' ? 'INBOX' : $f['display'],
            'label'      => $role === 'inbox' ? 'INBOX' : mcLeafName($f),
            'delimiter'  => $f['delimiter'],
            'level'      => $role !== '' ? 0 : $level,
            'role'       => $role,
            'selectable' => $f['selectable'],
            'messages'   => null,
            'unseen'     => null,
        ];
        if ($withStatus && $f['selectable']) {
            try {
                $st = $imap->status($f['name']);
                $item['messages'] = $st['messages'];
                $item['unseen']   = $st['unseen'];
            } catch (ImapException) {
            }
        }
        $out[] = $item;
    }
    usort($out, function ($a, $b) use ($order) {
        $ra = $a['role'] !== '' ? $order[$a['role']] : 99;
        $rb = $b['role'] !== '' ? $order[$b['role']] : 99;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        // Unterordner direkt unter ihrem Elternordner
        return strnatcasecmp(
            str_replace($a['delimiter'] ?: "\0", "\x01", $a['display']),
            str_replace($b['delimiter'] ?: "\0", "\x01", $b['display'])
        );
    });
    return $out;
}

function mcLeafName(array $folder): string
{
    if ($folder['delimiter'] === '') {
        return $folder['display'];
    }
    $parts = explode($folder['delimiter'], $folder['display']);
    return (string)end($parts);
}

function mcFolderByRole(ImapClient $imap, string $role): ?string
{
    foreach (mcFolders($imap, false) as $f) {
        if ($f['role'] === $role) {
            return $f['name'];
        }
    }
    return null;
}

/**
 * Eine Seite der Nachrichtenliste, neueste zuerst (nach Eingang).
 *
 * @param list<int>|null $uids Suchtreffer; null = alle Nachrichten
 */
function mcListMessages(ImapClient $imap, string $folder, int $page, ?array $uids = null): array
{
    $info = $imap->select($folder, true);
    $items = 'UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE CONTENT-TYPE)]';

    if ($uids === null) {
        $total = $info['exists'];
        $hi = $total - $page * MC_PAGE_SIZE;
        $lo = max(1, $hi - MC_PAGE_SIZE + 1);
        $rows = $hi >= 1 ? $imap->fetch("$lo:$hi", $items) : [];
    } else {
        rsort($uids);
        $total = count($uids);
        $slice = array_slice($uids, $page * MC_PAGE_SIZE, MC_PAGE_SIZE);
        $rows = $slice ? $imap->fetch(ImapClient::uidSet($slice), $items, true) : [];
    }

    $messages = [];
    foreach ($rows as $seq => $data) {
        $headers = MimeMessage::parseHeaders((string)($data['HEADER'] ?? ''));
        $h = fn(string $k) => $headers[$k][0] ?? null;
        [$ctype] = MimeMessage::parseParams((string)$h('content-type'));
        $flags = array_map(fn($f) => strtolower((string)$f), is_array($data['FLAGS'] ?? null) ? $data['FLAGS'] : []);
        $dateRaw = $h('date');
        $ts = $dateRaw !== null ? strtotime((string)preg_replace('/\s*\([^)]*\)\s*$/', '', $dateRaw)) : false;
        if (!$ts && isset($data['INTERNALDATE'])) {
            $ts = strtotime((string)$data['INTERNALDATE']);
        }
        $messages[] = [
            'uid'        => (int)($data['UID'] ?? 0),
            'seq'        => (int)$seq,
            'from'       => MimeMessage::addresses($h('from')),
            'to'         => MimeMessage::addresses($h('to')),
            'subject'    => MimeMessage::decodeHeader($h('subject')),
            'date'       => $ts ? date('c', $ts) : null,
            'size'       => (int)($data['RFC822.SIZE'] ?? 0),
            'seen'       => in_array('\\seen', $flags, true),
            'flagged'    => in_array('\\flagged', $flags, true),
            'answered'   => in_array('\\answered', $flags, true),
            'forwarded'  => in_array('$forwarded', $flags, true),
            'attachment' => in_array($ctype, ['multipart/mixed', 'multipart/report'], true),
        ];
    }
    // Neueste zuerst; ohne Suche entspricht das der Sequenz, sonst der UID
    usort($messages, fn($a, $b) => $uids === null ? $b['seq'] <=> $a['seq'] : $b['uid'] <=> $a['uid']);

    return [
        'folder'   => $folder,
        'page'     => $page,
        'total'    => $total,
        'has_more' => ($page + 1) * MC_PAGE_SIZE < $total,
        'messages' => $messages,
    ];
}

/** Rohnachricht laden (mit Größenprüfung). */
function mcFetchRaw(ImapClient $imap, string $folder, int $uid): string
{
    $imap->select($folder, true);
    $size = 0;
    foreach ($imap->fetch((string)$uid, 'UID RFC822.SIZE', true) as $data) {
        if ((int)($data['UID'] ?? 0) === $uid) {
            $size = (int)($data['RFC822.SIZE'] ?? 0);
        }
    }
    if ($size === 0) {
        throw new ImapException('Die Nachricht wurde nicht gefunden. Vielleicht wurde sie verschoben oder gelöscht.');
    }
    if ($size > MC_MAX_RAW_BYTES) {
        throw new ImapException('Die Nachricht ist zu groß für die Anzeige (' . round($size / 1048576) . ' MB).');
    }
    $raw = $imap->fetchRaw($uid);
    if ($raw === null) {
        throw new ImapException('Die Nachricht konnte nicht geladen werden.');
    }
    return $raw;
}

/** Testet die SMTP-Anmeldung mit PHPMailer. */
function mcTestSmtp(array $s): void
{
    if ($s['mc_smtp_host'] === '') {
        throw new RuntimeException('Kein SMTP-Server eingetragen.');
    }
    $mail = mcMailer($s);
    try {
        if (!$mail->smtpConnect()) {
            throw new RuntimeException('Verbindung oder Anmeldung fehlgeschlagen.');
        }
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        throw new RuntimeException($e->getMessage());
    } finally {
        $mail->smtpClose();
    }
}

/** PHPMailer mit den Zugangsdaten des Clients. */
function mcMailer(?array $settings = null): \PHPMailer\PHPMailer\PHPMailer
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $s = $settings ?? mcSettings();
    $enc = mcEncryption($s['mc_smtp_encryption']);
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet  = 'UTF-8';
    $mail->Host     = $s['mc_smtp_host'];
    $mail->Port     = (int)$s['mc_smtp_port'] ?: ($enc === 'ssl' ? 465 : 587);
    $mail->Timeout  = 20;
    $mail->SMTPAuth = $s['mc_smtp_user'] !== '';
    $mail->Username = $s['mc_smtp_user'];
    $mail->Password = $s['mc_smtp_password'];
    $mail->SMTPAutoTLS = $enc !== 'none';
    $mail->SMTPSecure = match ($enc) {
        'ssl'   => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
        'tls'   => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
        default => '',
    };
    return $mail;
}


// ----------------------------------------------------------------------
// Schreiben, Antworten, Weiterleiten, Entwürfe
// ----------------------------------------------------------------------

const MC_MAX_SEND_BYTES = 25 * 1024 * 1024;

/** Absenderadresse des Clients: eingetragene Adresse, sonst der IMAP-Benutzer, falls er eine Adresse ist. */
function mcFromEmail(array $s): string
{
    foreach ([$s['mc_from_email'], $s['mc_imap_user'], $s['mc_smtp_user']] as $candidate) {
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }
    }
    return '';
}

/** Eigene Adressen (werden bei „Allen antworten“ weggelassen). */
function mcOwnAddresses(array $s): array
{
    $own = [];
    foreach ([$s['mc_from_email'], $s['mc_imap_user'], $s['mc_smtp_user']] as $a) {
        if ($a !== '' && str_contains($a, '@')) {
            $own[] = mb_strtolower($a);
        }
    }
    return array_values(array_unique($own));
}

/**
 * Zerlegt eine Empfängerliste wie „Max Muster <max@example.com>, "Muster, Erika" <e@x.de>; a@b.de“.
 *
 * @return list<array{email:string, name:string}>
 * @throws RuntimeException bei ungültigen Adressen
 */
function mcParseAddressList(string $input): array
{
    $input = trim(str_replace(["\r", "\n"], ' ', $input));
    if ($input === '') {
        return [];
    }
    $parts = [];
    $buf = '';
    $quoted = false;
    $angle = false;
    $len = strlen($input);
    for ($i = 0; $i < $len; $i++) {
        $c = $input[$i];
        if ($c === '\\' && $quoted && $i + 1 < $len) {
            $buf .= $c . $input[++$i];
            continue;
        }
        if ($c === '"') {
            $quoted = !$quoted;
        } elseif ($c === '<' && !$quoted) {
            $angle = true;
        } elseif ($c === '>' && !$quoted) {
            $angle = false;
        } elseif (($c === ',' || $c === ';') && !$quoted && !$angle) {
            $parts[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $parts[] = $buf;

    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^(.*)<\s*([^<>\s]+)\s*>$/s', $part, $m)) {
            $name = trim($m[1]);
            $email = $m[2];
            if (strlen($name) >= 2 && $name[0] === '"' && str_ends_with($name, '"')) {
                $name = stripcslashes(substr($name, 1, -1));
            }
        } else {
            $name = '';
            $email = $part;
        }
        if (!\PHPMailer\PHPMailer\PHPMailer::validateAddress($email)) {
            throw new RuntimeException('Ungültige E-Mail-Adresse: ' . $part);
        }
        $out[] = ['email' => $email, 'name' => $name];
    }
    return $out;
}

/** Formatiert Adressen für das Eingabefeld. */
function mcFormatAddressList(array $list): string
{
    return implode(', ', array_map(function ($a) {
        if (($a['name'] ?? '') === '' || $a['name'] === $a['email']) {
            return $a['email'];
        }
        $name = preg_match('/[,;<>"@()\[\]:]/', $a['name']) ? '"' . addcslashes($a['name'], '"\\') . '"' : $a['name'];
        return $name . ' <' . $a['email'] . '>';
    }, $list));
}

/** Entfernt doppelte und eigene Adressen. */
function mcUniqueAddresses(array $list, array $exclude = []): array
{
    $seen = array_fill_keys($exclude, true);
    $out = [];
    foreach ($list as $a) {
        $key = mb_strtolower($a['email']);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $a;
    }
    return $out;
}

/** Betreff mit Präfix („Re:“, „Fwd:“), ohne bestehende Präfixe zu verdoppeln. */
function mcPrefixSubject(string $prefix, string $subject): string
{
    $pattern = $prefix === 'Re:' ? '/^\s*(re|aw|antw|sv)\s*(\[\d+\])?\s*:/iu' : '/^\s*(fwd?|wg|weitergeleitet)\s*:/iu';
    return preg_match($pattern, $subject) ? $subject : $prefix . ' ' . $subject;
}

function mcSignatureBlock(array $s): string
{
    $sig = trim(str_replace("\r\n", "\n", $s['mc_signature']));
    return $sig === '' ? '' : "\n\n-- \n" . $sig;
}

/** Nur gültige Message-IDs (<…@…>), getrennt durch Leerzeichen. */
function mcCleanMessageIds(string $value, int $max = 20): string
{
    preg_match_all('/<[^<>\s]{1,250}@[^<>\s]{1,250}>/', $value, $m);
    $ids = array_values(array_unique($m[0]));
    return implode(' ', array_slice($ids, -$max));
}

function mcNewMessageId(array $s): string
{
    $domain = substr(strrchr(mcFromEmail($s) ?: 'mail@localhost', '@'), 1) ?: 'localhost';
    return '<' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^A-Za-z0-9.-]/', '', $domain) . '>';
}

/**
 * Vorbelegung des Schreibfensters für Antworten, Weiterleiten und das Bearbeiten eines Entwurfs.
 *
 * @param 'reply'|'replyall'|'forward'|'edit' $mode
 */
function mcComposeSource(ImapClient $imap, string $folder, int $uid, string $mode): array
{
    $s = mcSettings();
    $msg = MimeMessage::analyze(mcFetchRaw($imap, $folder, $uid));
    $text = $msg['text'] ?? null;
    if (($text === null || trim($text) === '') && $msg['html'] !== null) {
        $text = MimeMessage::htmlToText($msg['html']);
    }
    $text = str_replace("\r\n", "\n", (string)$text);
    $own = mcOwnAddresses($s);
    $sig = mcSignatureBlock($s);
    $refs = mcCleanMessageIds($msg['references'] . ' ' . $msg['message_id']);
    $partRefs = array_map(fn($a) => [
        'folder' => $folder, 'uid' => $uid, 'part' => $a['id'],
        'filename' => $a['filename'], 'size' => $a['size'],
    ], $msg['attachments']);

    $out = [
        'to' => '', 'cc' => '', 'bcc' => '', 'subject' => '', 'body' => '',
        'in_reply_to' => '', 'references' => '', 'attachments' => [], 'draft_id' => '',
        'orig_folder' => $folder, 'orig_uid' => $uid, 'mode' => $mode,
    ];

    if ($mode === 'edit') {
        $out['to'] = mcFormatAddressList($msg['to']);
        $out['cc'] = mcFormatAddressList($msg['cc']);
        $out['bcc'] = mcFormatAddressList($msg['bcc']);
        $out['subject'] = $msg['subject'];
        $out['body'] = $text;
        $out['in_reply_to'] = mcCleanMessageIds($msg['in_reply_to'], 1);
        $out['references'] = mcCleanMessageIds($msg['references']);
        $out['attachments'] = $partRefs;
        $out['draft_id'] = mcCleanMessageIds($msg['message_id'], 1);
        $out['orig_folder'] = '';
        $out['orig_uid'] = 0;
        return $out;
    }

    $fromList = mcFormatAddressList($msg['from']);
    $date = $msg['date'] ? date(currentLang() === 'en' ? 'd/m/Y H:i' : 'd.m.Y H:i', strtotime($msg['date'])) : '';

    if ($mode === 'forward') {
        $out['subject'] = mcPrefixSubject('Fwd:', $msg['subject']);
        $head = t('mail.fwdHeader') . "\n"
            . t('mail.subject') . ': ' . $msg['subject'] . "\n"
            . t('mail.date') . ': ' . $date . "\n"
            . t('mail.from') . ': ' . $fromList . "\n"
            . t('mail.to') . ': ' . mcFormatAddressList($msg['to']) . "\n"
            . ($msg['cc'] ? t('mail.cc') . ': ' . mcFormatAddressList($msg['cc']) . "\n" : '');
        $out['body'] = "\n" . $sig . "\n\n" . $head . "\n" . $text;
        $out['attachments'] = $partRefs;
        $out['references'] = $refs;
        return $out;
    }

    // Antworten: an Reply-To bzw. Absender; bei eigenen Nachrichten (z. B. aus „Gesendet“) an die Empfänger
    $fromIsOwn = $msg['from'] && in_array(mb_strtolower($msg['from'][0]['email']), $own, true);
    $primary = $msg['reply_to'] ?: $msg['from'];
    if ($fromIsOwn && !$msg['reply_to']) {
        $primary = $msg['to'];
    }
    if ($mode === 'replyall') {
        $to = mcUniqueAddresses(array_merge($primary, $fromIsOwn ? [] : $msg['to']), $own);
        if (!$to) {
            $to = mcUniqueAddresses($primary);
        }
        $cc = mcUniqueAddresses($msg['cc'], array_merge($own, array_map(fn($a) => mb_strtolower($a['email']), $to)));
        $out['cc'] = mcFormatAddressList($cc);
    } else {
        $to = mcUniqueAddresses($primary);
    }
    $out['to'] = mcFormatAddressList($to);
    $out['subject'] = mcPrefixSubject('Re:', $msg['subject']);
    $out['in_reply_to'] = mcCleanMessageIds($msg['message_id'], 1);
    $out['references'] = $refs;
    $who = $msg['from'] ? ($msg['from'][0]['name'] ?: $msg['from'][0]['email']) : '';
    $quoted = implode("\n", array_map(
        fn($l) => $l === '' || $l[0] === '>' ? '>' . $l : '> ' . $l,
        explode("\n", rtrim($text))
    ));
    $out['body'] = "\n" . $sig . "\n\n" . t('mail.quoteHeader', ['date' => $date, 'name' => $who]) . "\n" . $quoted . "\n";
    return $out;
}

/**
 * Baut die Nachricht aus den Formularfeldern.
 *
 * @param array $in       to, cc, bcc, subject, body, in_reply_to, references, draft_id
 * @param list<array{folder:string, uid:int, part:string}> $keep  Anhänge aus vorhandenen Nachrichten
 * @param list<array{tmp:string, name:string}> $uploads
 */
function mcBuildMailer(array $s, array $in, array $keep, array $uploads, ?ImapClient $imap, bool $draft): \PHPMailer\PHPMailer\PHPMailer
{
    $from = mcFromEmail($s);
    if ($from === '') {
        throw new RuntimeException('Keine Absenderadresse eingetragen (Konfiguration → E-Mail-Client).');
    }
    $mail = mcMailer($s);
    $mail->setFrom($from, $s['mc_from_name']);
    $to = mcParseAddressList((string)($in['to'] ?? ''));
    $cc = mcParseAddressList((string)($in['cc'] ?? ''));
    $bcc = mcParseAddressList((string)($in['bcc'] ?? ''));
    if (!$draft && !$to && !$cc && !$bcc) {
        throw new RuntimeException('Bitte mindestens einen Empfänger angeben.');
    }
    foreach ($to as $a) { $mail->addAddress($a['email'], $a['name']); }
    foreach ($cc as $a) { $mail->addCC($a['email'], $a['name']); }
    foreach ($bcc as $a) { $mail->addBCC($a['email'], $a['name']); }

    $mail->Subject = str_replace(["\r", "\n"], ' ', (string)($in['subject'] ?? ''));
    $mail->isHTML(false);
    $mail->Body = str_replace("\r\n", "\n", (string)($in['body'] ?? ''));
    $mail->AllowEmpty = true;
    $mail->XMailer = ' ';

    $mid = mcCleanMessageIds((string)($in['draft_id'] ?? ''), 1);
    $mail->MessageID = $mid !== '' ? $mid : mcNewMessageId($s);
    $irt = mcCleanMessageIds((string)($in['in_reply_to'] ?? ''), 1);
    if ($irt !== '') {
        $mail->addCustomHeader('In-Reply-To', $irt);
    }
    $refs = mcCleanMessageIds((string)($in['references'] ?? ''));
    if ($refs !== '') {
        $mail->addCustomHeader('References', $refs);
    }

    $total = strlen($mail->Body);
    $cache = [];
    foreach ($keep as $ref) {
        if (!$imap) {
            throw new ImapException('Für die Anhänge wird die IMAP-Verbindung gebraucht.');
        }
        $key = $ref['folder'] . "\0" . $ref['uid'];
        $cache[$key] ??= mcFetchRaw($imap, $ref['folder'], $ref['uid']);
        $part = MimeMessage::findPart($cache[$key], $ref['part']);
        if (!$part) {
            throw new RuntimeException('Ein Anhang der ursprünglichen Nachricht wurde nicht gefunden.');
        }
        $content = $part->content();
        $total += strlen($content);
        $mail->addStringAttachment($content, $part->filename() ?: 'anhang', \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, $part->type);
    }
    unset($cache);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($uploads as $u) {
        $total += (int)filesize($u['tmp']);
        $mail->addAttachment($u['tmp'], $u['name'], \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
            $finfo->file($u['tmp']) ?: 'application/octet-stream');
    }
    if ($total > MC_MAX_SEND_BYTES) {
        throw new RuntimeException('Die Nachricht ist zu groß (' . round($total / 1048576, 1) . ' MB, höchstens '
            . (MC_MAX_SEND_BYTES / 1048576) . ' MB).');
    }
    return $mail;
}

/** Fertige MIME-Nachricht für die Ablage (Entwürfe; inklusive Bcc). */
function mcRenderForStorage(\PHPMailer\PHPMailer\PHPMailer $mail): string
{
    // Beim Versand per „sendmail“ schreibt PHPMailer To, Subject und Bcc in den Kopf; gesendet wird hier nichts.
    $mail->Mailer = 'sendmail';
    $placeholder = !$mail->getToAddresses() && !$mail->getCcAddresses() && !$mail->getBccAddresses();
    if ($placeholder) {
        $mail->addBCC('entwurf@placeholder.invalid');
    }
    $mail->preSend();
    $raw = $mail->getSentMIMEMessage();
    if ($placeholder) {
        $raw = (string)preg_replace('/^Bcc: entwurf@placeholder\.invalid\r?\n/mi', '', $raw);
    }
    return $raw;
}

/** Ergänzt Bcc im Kopf der gesendeten Kopie (PHPMailer lässt ihn beim SMTP-Versand weg). */
function mcSentCopy(\PHPMailer\PHPMailer\PHPMailer $mail): string
{
    $raw = $mail->getSentMIMEMessage();
    $bcc = $mail->getBccAddresses();
    if ($bcc) {
        $raw = 'Bcc: ' . implode(', ', array_map(fn($a) => $mail->addrFormat($a), $bcc)) . "\r\n" . $raw;
    }
    return $raw;
}

/** Entfernt frühere Fassungen eines Entwurfs (gleiche Message-ID). */
function mcDeleteDrafts(ImapClient $imap, string $draftsFolder, string $messageId): int
{
    if ($messageId === '') {
        return 0;
    }
    $imap->select($draftsFolder);
    $uids = $imap->searchHeader('Message-ID', $messageId);
    if ($uids) {
        $imap->delete($uids);
    }
    return count($uids);
}
