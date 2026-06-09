<?php
class MailHelper
{
    private static array $months = [
        'Januar','Februar','März','April','Mai','Juni',
        'Juli','August','September','Oktober','November','Dezember',
    ];

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

        $workHtml  = '';
        $workPlain = '';
        if (($customer['invoice_mode'] ?? 'entries') === 'text') {
            $workHtml  = self::buildWorkList($items, 'html');
            $workPlain = self::buildWorkList($items, 'text');
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

        $plainTpl = trim((string)($customer['mail_template_plain'] ?? ''));
        if ($plainTpl !== '') {
            $plain = str_replace(['{project}', '{time}', '{work}'], [$project, $time, $workPlain], $plainTpl);
        } else {
            $plain = self::defaultPlain($invoiceNumber, $amountGross, $time);
        }
        $sigPlain = trim(cfg('mail_signature_plain'));
        if ($sigPlain !== '') {
            $plain .= "\n\n-- \n" . $sigPlain;
        }

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

        if (count($keys) === 1) {
            [$y, $m] = explode('-', $keys[0]);
            return 'im ' . self::$months[(int)$m - 1] . ' ' . $y;
        }

        [$fy, $fm] = explode('-', $keys[0]);
        [$ly, $lm] = explode('-', $keys[count($keys) - 1]);
        $from = self::$months[(int)$fm - 1] . ($fy !== $ly ? ' ' . $fy : '');
        $to   = self::$months[(int)$lm - 1] . ' ' . $ly;
        return 'von ' . $from . ' bis ' . $to;
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

        $heading = 'Hier die Liste der Arbeiten:';
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
        $n = htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8');
        $t = htmlspecialchars($time, ENT_QUOTES, 'UTF-8');
        $a = number_format($amountGross, 2, ',', '.');
        $s = htmlspecialchars(cfg('invoice_company'), ENT_QUOTES, 'UTF-8');
        return "<p>Sehr geehrte Damen und Herren,</p>"
             . "<p>anbei erhalten Sie unsere Rechnung <strong>$n</strong> für $t über $a&nbsp;€.</p>"
             . "<p>Mit freundlichen Grüßen<br>$s</p>";
    }

    private static function defaultPlain(string $invoiceNumber, float $amountGross, string $time): string
    {
        $a = number_format($amountGross, 2, ',', '.');
        return "Sehr geehrte Damen und Herren,\n\n"
             . "anbei erhalten Sie unsere Rechnung $invoiceNumber fuer $time ueber $a EUR.\n\n"
             . "Mit freundlichen Gruessen\n"
             . cfg('invoice_company');
    }
}
