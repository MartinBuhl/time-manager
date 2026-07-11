<?php
function sendPasswordResetMail(string $toEmail, string $resetLink): bool
{
    $subject = '=?UTF-8?B?' . base64_encode('Time Manager – Passwort zurücksetzen') . '?=';

    $body = "Hallo,\r\n\r\n"
        . "Sie haben das Zurücksetzen Ihres Passworts angefordert.\r\n\r\n"
        . "Klicken Sie auf den folgenden Link, um ein neues Passwort zu vergeben:\r\n"
        . $resetLink . "\r\n\r\n"
        . "Der Link ist 1 Stunde gültig.\r\n\r\n"
        . "Falls Sie keine Anfrage gestellt haben, ignorieren Sie diese E-Mail bitte.\r\n\r\n"
        . "-- Time Manager";

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
