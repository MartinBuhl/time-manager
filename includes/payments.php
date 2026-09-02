<?php
/**
 * Gemeinsame Helfer für Zahlungen (tm_payments) – genutzt von der
 * Admin-Seite, der API und dem Cron-Skript für Erinnerungen.
 */

require_once __DIR__ . '/db.php';

/** Anzahl Monate je Rhythmus; null = einmalige Zahlung. */
function paymentIntervalMonths(string $recurrence): ?int
{
    return ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$recurrence] ?? null;
}

/** Gültige Rhythmen. */
function paymentRecurrences(): array
{
    return ['once', 'monthly', 'quarterly', 'yearly'];
}

/**
 * Erstes/nächstes Fälligkeitsdatum aus Bausteinen berechnen (>= heute):
 *   - monthly:   nur $day (nächster Monat mit diesem Tag)
 *   - quarterly: $day + Anker-$month (Zyklus: Monat, +3, +6, +9) + optional $year
 *   - yearly:    $day + $month + optional $year
 * Mit $year lässt sich ein Beginn in einem künftigen Jahr festlegen; ohne
 * $year wird beim aktuellen Jahr begonnen. $day/$month werden auf gültige
 * Bereiche und die Monatslänge begrenzt.
 */
function paymentFirstDueFromParts(string $recurrence, int $day, ?int $month, ?int $year = null): string
{
    $today  = new DateTimeImmutable('today');
    $months = paymentIntervalMonths($recurrence) ?? 1;
    $day    = max(1, min(31, $day));

    if ($recurrence === 'monthly' || $month === null) {
        $cursor = $today->modify('first day of this month');
    } else {
        $m      = max(1, min(12, $month));
        $y      = ($year !== null && $year >= 1970) ? $year : (int) $today->format('Y');
        $cursor = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));
    }

    for ($i = 0; $i < 130; $i++) {
        $target = min($day, (int) $cursor->format('t'));
        $cand   = $cursor->setDate((int) $cursor->format('Y'), (int) $cursor->format('n'), $target);
        if ($cand >= $today) return $cand->format('Y-m-d');
        $cursor = $cursor->modify('first day of this month')->add(new DateInterval("P{$months}M"));
    }
    return $today->format('Y-m-d'); // Fallback (praktisch unerreichbar)
}

/**
 * Nächstes Fälligkeitsdatum für eine wiederkehrende Zahlung. Es wird solange
 * um das Intervall weitergerückt, bis das Datum in der Zukunft liegt (fängt
 * mehrere verpasste Perioden ab). Der Zieltag ist $dueDay (auf die Monatslänge
 * begrenzt); ohne $dueDay bleibt der ursprüngliche Tag erhalten. Der Monat wird
 * überlauffrei erhöht (kein „31. Jan + 1 Monat = 3. März"). Bei einmaligen
 * Zahlungen: null.
 */
function paymentNextDueDate(string $due, string $recurrence, ?int $dueDay = null): ?string
{
    $months = paymentIntervalMonths($recurrence);
    if ($months === null) return null;

    $d     = new DateTimeImmutable($due);
    $day   = ($dueDay !== null && $dueDay >= 1) ? $dueDay : (int) $d->format('j');
    $today = new DateTimeImmutable('today');

    do {
        // Erst auf Monatsanfang, dann Monate addieren -> kein Tages-Überlauf.
        $base   = $d->modify('first day of this month')->add(new DateInterval("P{$months}M"));
        $target = min($day, (int) $base->format('t'));
        $d      = $base->setDate((int) $base->format('Y'), (int) $base->format('n'), $target);
    } while ($d <= $today);

    return $d->format('Y-m-d');
}

/** Empfänger der Erinnerungs-Mails: eigenes Feld, sonst Rechnungs-E-Mail. */
function paymentReminderRecipient(): string
{
    $to = trim(cfg('payment_reminder_email'));
    if ($to === '') $to = trim(cfg('invoice_email'));
    return $to;
}

/** Konfigurierte Erinnerungs-Schwellen (Tage vor Fälligkeit). */
function paymentReminderThresholds(): array
{
    $first  = (int) (cfg('payment_reminder_days_first',  '7') ?: 7);
    $second = (int) (cfg('payment_reminder_days_second', '3') ?: 3);
    $daily  = (int) (cfg('payment_reminder_days_daily',  '2') ?: 2);
    return ['first' => $first, 'second' => $second, 'daily' => $daily];
}

/** Schreibt/aktualisiert einen Konfigurationswert (versteckte Gruppe 0). */
function paymentSetConfig(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO tm_configuration
             (configuration_key, configuration_value, configuration_group_id, sort_order, last_modified)
         VALUES (?, ?, 0, 0, NOW())
         ON DUPLICATE KEY UPDATE configuration_value = VALUES(configuration_value), last_modified = NOW()'
    )->execute([$key, $value]);
}

/**
 * Prüft alle offenen, aktiven Zahlungen und versendet fällige Erinnerungen.
 * Pro Zahlung höchstens eine Mail je Tag; bei Sendefehler bleibt der Status
 * unverändert (Wiederholung beim nächsten Aufruf).
 *
 * @param callable|null $log optionaler Logger fn(string $msg)
 * @return array{sent:int,checked:int,recipient:bool,error:?string}
 */
function paymentRunReminders(?callable $log = null): array
{
    require_once __DIR__ . '/i18n.php';
    require_once __DIR__ . '/MailHelper.php';
    $log = $log ?? static function (string $m): void {};

    $lang     = cfg('default_lang', 'de');
    $to       = paymentReminderRecipient();
    $th       = paymentReminderThresholds();
    $today    = new DateTimeImmutable('today');
    $todayStr = $today->format('Y-m-d');

    if ($to === '') {
        $log('Kein Empfänger konfiguriert (payment_reminder_email bzw. invoice_email).');
        return ['sent' => 0, 'checked' => 0, 'recipient' => false, 'error' => null];
    }

    $rows = db()->query(
        'SELECT * FROM tm_payments WHERE active = 1 AND done = 0'
    )->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0; $mailer = null; $error = null;

    foreach ($rows as $p) {
        // Heute schon erinnert? Überspringen.
        if (!empty($p['last_reminded_on']) && $p['last_reminded_on'] === $todayStr) {
            continue;
        }

        $d     = (int) $today->diff(new DateTimeImmutable($p['due_date']))->format('%r%a');
        $stage = (int) $p['reminder_stage'];

        $send = false; $newStage = $stage;
        if ($d <= $th['daily']) {
            $send = true; $newStage = 3;
        } elseif ($stage < 2 && $d <= $th['second']) {
            $send = true; $newStage = 2;
        } elseif ($stage < 1 && $d <= $th['first']) {
            $send = true; $newStage = 1;
        }
        if (!$send) continue;

        if ($d < 0)       $info = tLang('payments.overdueDays', $lang, ['days' => abs($d)]);
        elseif ($d === 0) $info = tLang('payments.dueToday', $lang);
        else              $info = tLang('payments.dueInDays', $lang, ['days' => $d]);

        $dateFmt = (new DateTime($p['due_date']))->format('d.m.Y');
        $amount  = number_format((float) $p['amount'], 2, ',', '.') . ' ' . ($p['currency'] === 'USD' ? '$' : '€');
        $subject = tLang('email.paymentReminder.subject', $lang, ['title' => $p['title'], 'date' => $dateFmt]);
        $body    = tLang('email.paymentReminder.body', $lang, [
            'title' => $p['title'], 'amount' => $amount, 'date' => $dateFmt, 'info' => $info,
        ]);

        try {
            if ($mailer === null) $mailer = MailHelper::createMailer();
            $mailer->clearAllRecipients();
            $mailer->addAddress($to);
            $mailer->isHTML(false);
            $mailer->Subject = $subject;
            $mailer->Body    = $body;
            $mailer->send();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $log('Fehler beim Senden für #' . $p['id'] . ' (' . $p['title'] . '): ' . $error);
            break; // Mailer/SMTP defekt – weitere Versuche würden ebenso scheitern.
        }

        db()->prepare(
            'UPDATE tm_payments SET reminder_stage = ?, last_reminded_on = ? WHERE id = ?'
        )->execute([$newStage, $todayStr, (int) $p['id']]);
        $sent++;
        $log('Erinnerung gesendet: #' . $p['id'] . ' „' . $p['title'] . '" (' . $info . ') an ' . $to);
    }

    return ['sent' => $sent, 'checked' => count($rows), 'recipient' => true, 'error' => $error];
}

/**
 * Beim Login aufrufen: prüft höchstens einmal pro Tag auf fällige Erinnerungen
 * und versendet sie. Läuft nur erneut, wenn der letzte Durchlauf nicht heute
 * war ODER kein Empfänger/Mailserver bereitstand (dann Wiederholung beim
 * nächsten Login). Fehler brechen den Login niemals ab.
 */
function paymentMaybeRunRemindersOnLogin(): void
{
    try {
        $today = date('Y-m-d');
        if (cfg('payment_reminders_last_run') === $today) return;

        $dir    = dirname(__DIR__) . '/log';
        $logger = static function (string $m) use ($dir): void {
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            @file_put_contents($dir . '/payment_reminders.log',
                '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n", FILE_APPEND | LOCK_EX);
        };

        $res = paymentRunReminders($logger);

        // Tages-Sperre nur setzen, wenn ein Empfänger da war und kein
        // Mailserver-Fehler auftrat – sonst nächster Login erneut versuchen.
        if ($res['recipient'] && $res['error'] === null) {
            paymentSetConfig('payment_reminders_last_run', $today);
        }
    } catch (\Throwable $e) {
        error_log('paymentMaybeRunRemindersOnLogin: ' . $e->getMessage());
    }
}
