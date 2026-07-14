<?php
require_once __DIR__ . '/i18n.php';

class MailHelper
{
    /** Dokumentsprache der Rechnungs-Mail (global). */
    private static function docLang(): string
    {
        return cfg('default_lang', 'de');
    }

    /**
     * Builds subject, html and plain body for an invoice mail.
     */
    public static function buildMailBody(
        array  $customer,
        array  $items,
        string $invoiceNumber,
        float  $amountGross
    ): array {
        $time    = self::buildTimePlaceholder($items);
        $project = self::getProjectName($customer);

        $subject = str_replace(
            ['{project}', '{time}'],
            [$project,    $time],
            cfg('invoice_mail_subject', 'Rechnung {project} {time}')
        );

        $workHtml = '';
        if (($customer['invoice_mode'] ?? 'entries') === 'text') {
            $workHtml = self::buildWorkList($items, 'html');
        }

        $htmlTpl = trim((string)($customer['mail_template_html'] ?? ''));
        if ($htmlTpl !== '') {
            $html = str_replace(['{project}', '{time}', '{work}'], [$project, $time, $workHtml], $htmlTpl);
        } else {
            $html = self::defaultHtml($invoiceNumber, $amountGross, $time);
        }
        $sigHtml = trim(cfg('mail_signature_html'));
        if ($sigHtml !== '') {
            $html .= '<br><hr style="border:none;border-top:1px solid #ddd;margin:16px 0">' . $sigHtml;
        }

        // Der Plaintext (AltBody) wird automatisch aus dem HTML erzeugt –
        // es gibt keine separaten Plain-Vorlagen mehr.
        $plain = self::htmlToPlain($html);

        return compact('subject', 'html', 'plain');
    }

    /**
     * Returns "im April 2026" or "von März bis Mai 2026" from item dates.
     */
    public static function buildTimePlaceholder(array $items): string
    {
        $months = [];
        foreach ($items as $item) {
            $d = $item['date'] ?? '';
            if ($d) $months[substr($d, 0, 7)] = true;
        }
        ksort($months);
        $keys = array_keys($months);
        if (empty($keys)) return '';

        $lang     = self::docLang();
        $monthName = fn(int $m): string => tLang('stats.month.' . $m, $lang);

        if (count($keys) === 1) {
            [$y, $m] = explode('-', $keys[0]);
            return tLang('invoiceMail.timeSingle', $lang, [
                'month' => $monthName((int)$m),
                'year'  => $y,
            ]);
        }

        [$fy, $fm] = explode('-', $keys[0]);
        [$ly, $lm] = explode('-', $keys[count($keys) - 1]);
        $from = $monthName((int)$fm) . ($fy !== $ly ? ' ' . $fy : '');
        $to   = $monthName((int)$lm) . ' ' . $ly;
        return tLang('invoiceMail.timeRange', $lang, ['from' => $from, 'to' => $to]);
    }

    /**
     * Creates a configured PHPMailer instance from SMTP config.
     */
    public static function createMailer(): \PHPMailer\PHPMailer\PHPMailer
    {
        $src = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/';
        require_once $src . 'Exception.php';
        require_once $src . 'PHPMailer.php';
        require_once $src . 'SMTP.php';

        $host = trim(cfg('smtp_host'));
        if ($host === '') {
            throw new \RuntimeException('SMTP-Server nicht konfiguriert (smtp_host).');
        }

        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host    = $host;
        $mailer->Port    = (int)(cfg('smtp_port') ?: '587');
        $mailer->CharSet = 'UTF-8';

        $enc = strtolower(trim(cfg('smtp_encryption', 'tls')));
        if ($enc === 'ssl') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mailer->SMTPAuth   = true;
        } elseif ($enc === 'none') {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAuth   = false;
        } else {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->SMTPAuth   = true;
        }

        if ($mailer->SMTPAuth) {
            $mailer->Username = cfg('smtp_user');
            $mailer->Password = cfg('smtp_password');
        }

        $mailer->setFrom(cfg('mail_from'), cfg('mail_name'));
        $bcc = trim(cfg('mail_bcc'));
        if ($bcc !== '') $mailer->addBCC($bcc);

        return $mailer;
    }

    /** Schreibt eine Zeile in log/imap.log (erscheint in der Admin-Logs-Seite). */
    private static function imapLog(string $msg): void
    {
        $dir = dirname(__DIR__) . '/log';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        @file_put_contents(
            $dir . '/imap.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Legt eine bereits versendete Nachricht (rohe MIME-Daten aus
     * PHPMailer::getSentMIMEMessage()) per IMAP im Sent-Ordner ab.
     * Login = SMTP-Benutzer/Passwort.
     *
     * @return string Leerstring bei Erfolg/deaktiviert, sonst Fehlermeldung.
     */
    public static function saveToImapSent(string $rawMessage): string
    {
        if (cfg('imap_save_sent') !== '1') {
            return '';
        }
        if (!function_exists('imap_open')) {
            $m = 'PHP-imap-Extension ist nicht installiert/aktiviert.';
            self::imapLog('FEHLER: ' . $m);
            return $m;
        }

        $host = trim(cfg('imap_host'));
        if ($host === '') {
            $m = 'Kein IMAP-Server konfiguriert.';
            self::imapLog('FEHLER: ' . $m);
            return $m;
        }
        $folder = trim(cfg('imap_sent_folder')) ?: 'Sent';
        $port   = (int)(cfg('imap_port') ?: '993');
        $enc    = strtolower(trim(cfg('imap_encryption', 'ssl')));

        $flags = '/imap';
        if ($enc === 'ssl') {
            $flags .= '/ssl';
        } elseif ($enc === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }

        $mailbox = '{' . $host . ':' . $port . $flags . '}' . $folder;
        $user    = cfg('smtp_user');
        $pass    = cfg('smtp_password');

        self::imapLog('Verbinde zu ' . $mailbox . ' als ' . $user);

        // imap_errors() leeren, damit alte Meldungen nicht stören
        if (function_exists('imap_errors')) { @imap_errors(); }

        $imap = @imap_open($mailbox, $user, $pass, OP_HALFOPEN);
        if ($imap === false) {
            $m = 'Verbindung/Login fehlgeschlagen: ' . (imap_last_error() ?: 'unbekannt');
            self::imapLog('FEHLER: ' . $m);
            return $m;
        }

        // IMAP erwartet CRLF-Zeilenenden
        $msg = preg_replace('/\r?\n/', "\r\n", $rawMessage);
        $ok  = @imap_append($imap, $mailbox, $msg, "\\Seen");
        imap_close($imap);

        if (!$ok) {
            $m = 'Ablage im Ordner "' . $folder . '" fehlgeschlagen: ' . (imap_last_error() ?: 'unbekannt');
            self::imapLog('FEHLER: ' . $m);
            return $m;
        }

        self::imapLog('OK: Nachricht im Ordner "' . $folder . '" abgelegt.');
        return '';
    }

    private static function buildWorkList(array $items, string $format): string
    {
        if (empty($items)) return '';
        $lines = [];
        foreach ($items as $item) {
            $datePart = '';
            $d = $item['date'] ?? '';
            if ($d) {
                [$y, $mo, $day] = explode('-', $d);
                $datePart = sprintf('%02d.%02d.%s', (int)$day, (int)$mo, $y);
            }
            $timePart = '';
            $start = $item['start_datetime'] ?? '';
            $end   = $item['end_datetime']   ?? '';
            if ($start && $end) {
                $timePart = substr($start, 11, 5) . '-' . substr($end, 11, 5);
            }

            $min      = (int)($item['duration_minutes'] ?? 0);
            $activity = trim($item['activity'] ?? '');
            $project  = trim($item['project']  ?? '');
            $comment  = trim($item['comment']  ?? '');
            $parts    = [];
            if ($activity !== '') $parts[] = $activity;
            if ($project  !== '') $parts[] = $project;
            if ($comment  !== '') $parts[] = $comment;
            $desc     = implode(': ', $parts);

            // Datum TAB Zeiten TAB Minuten TAB Tätigkeit: Projekt: Kommentar
            $lines[]  = $datePart . "\t" . $timePart . "\t" . $min . "\t" . $desc;
        }

        $heading = tLang('invoiceMail.workListHeading', self::docLang());
        if ($format === 'html') {
            $escaped = array_map(fn($l) => htmlspecialchars($l, ENT_QUOTES, 'UTF-8'), $lines);
            return '<p>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</p>'
                 . '<div style="font-size:13px; white-space:pre-wrap">' . implode("\n", $escaped) . '</div>';
        }
        return $heading . "\n" . implode("\n", $lines);
    }

    private static function getProjectName(array $customer): string
    {
        $projects = json_decode($customer['projects'] ?? '[]', true);
        if (is_array($projects) && !empty($projects)) {
            return $projects[0]['name'] ?? '';
        }
        return trim((string)($customer['billing_name'] ?? '')) ?: ($customer['name'] ?? '');
    }

    private static function defaultHtml(string $invoiceNumber, float $amountGross, string $time): string
    {
        $lang = self::docLang();
        $n = htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8');
        $t = htmlspecialchars($time, ENT_QUOTES, 'UTF-8');
        $a = number_format($amountGross, 2, ',', '.');
        $s = htmlspecialchars(cfg('invoice_company'), ENT_QUOTES, 'UTF-8');
        $sal  = htmlspecialchars(tLang('invoiceMail.salutation', $lang), ENT_QUOTES, 'UTF-8');
        $body = tLang('invoiceMail.bodyHtml', $lang, ['number' => $n, 'time' => $t, 'amount' => $a]);
        $clos = htmlspecialchars(tLang('invoiceMail.closing', $lang), ENT_QUOTES, 'UTF-8');
        return "<p>$sal</p>"
             . "<p>$body</p>"
             . "<p>$clos<br>$s</p>";
    }

    /**
     * Erzeugt aus dem HTML-Body eine lesbare Plaintext-Fassung (AltBody).
     * Block-Elemente werden zu Zeilenumbrüchen, Links zu "Text (URL)".
     */
    private static function htmlToPlain(string $html): string
    {
        $s = $html;
        // Links: <a href="url">text</a> -> "text (url)"
        $s = preg_replace_callback(
            '/<a\b[^>]*\bhref\s*=\s*["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
            function ($m) {
                $url = trim($m[1]);
                $txt = trim(strip_tags($m[2]));
                if ($url === '' || $url === $txt) { return $txt !== '' ? $txt : $url; }
                return $txt !== '' ? $txt . ' (' . $url . ')' : $url;
            },
            $s
        );
        // Listen-Einträge einleiten
        $s = preg_replace('/<li\b[^>]*>/i', "\n- ", $s);
        // Zeilen-/Blockenden zu Umbruch (Absätze/Überschriften mit Leerzeile)
        $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
        $s = preg_replace('/<\/(p|h[1-6])>/i', "\n\n", $s);
        $s = preg_replace('/<\/(div|li|tr|table)>/i', "\n", $s);
        $s = preg_replace('/<hr\s*\/?>/i', "\n", $s);
        // restliche Tags entfernen, Entities dekodieren
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\xC2\xA0", ' ', $s); // &nbsp; -> Leerzeichen
        // Whitespace normalisieren
        $s = preg_replace('/[ \t]+/', ' ', $s);
        $s = preg_replace('/ *\n */', "\n", $s);
        $s = preg_replace('/\n{3,}/', "\n\n", $s);
        return trim($s);
    }
}
