<?php
require_once __DIR__ . '/i18n.php';

function sendPasswordResetMail(string $toEmail, string $resetLink, ?string $lang = null): bool
{
    $subject = '=?UTF-8?B?' . base64_encode(tLang('email.reset.subject', $lang)) . '?=';

    // Sprachdatei nutzt \n; für Mail-Transport auf CRLF normalisieren.
    $body = str_replace("\n", "\r\n",
        tLang('email.reset.body', $lang, ['link' => $resetLink]));

    $headers = implode("\r\n", [
        'From: ' . cfg('mail_name', 'Time Manager') . ' <' . cfg('mail_from', '') . '>',
        'Reply-To: ' . cfg('mail_from', ''),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: PHP/' . phpversion(),
    ]);

    return mail($toEmail, $subject, $body, $headers);
}
