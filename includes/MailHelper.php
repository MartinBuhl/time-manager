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

    private static function buildWorkList(array $items, string $format): string
    {
        if (empty($items)) return '';
        $lines = [];
        foreach ($items as $item) {
            $d   = $item['date'] ?? '';
            $pre = '';
            if ($d) {
                [$y, $mo, $day] = explode('-', $d);
                $pre = sprintf('%02d.%02d.%s', (int)$day, (int)$mo, $y) . ': ';
            }
            $min      = (int)($item['duration_minutes'] ?? 0);
            $activity = trim($item['activity'] ?? '');
            $comment  = trim($item['comment']  ?? '');
            $desc     = $activity . ($comment !== '' ? ': ' . $comment : '');
            $lines[]  = $pre . $desc . ' ' . $min . ' Min.';
        }

        $heading = 'Hier die Liste der Arbeiten:';
        if ($format === 'html') {
            $escaped = array_map(fn($l) => htmlspecialchars($l, ENT_QUOTES, 'UTF-8'), $lines);
            return '<p>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '<br>'
                 . '<span style="font-size:13px">' . implode('<br>', $escaped) . '</span></p>';
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
