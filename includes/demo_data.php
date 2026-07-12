<?php
/**
 * Demo-Daten: Anlegen und sicheres Löschen.
 *
 * Sämtliche Demo-Datensätze (Kunde, Zeiten, Rechnung, Rechnungsposten,
 * Aufträge) hängen an EINEM Demo-Kunden. Dessen ID wird im internen
 * Konfigurations-Schlüssel `demo_customer_ids` (JSON-Array) gespeichert.
 * Das Löschen entfernt ausschließlich Datensätze, die an diesen ID(s)
 * hängen – dadurch ist garantiert, dass keine echten Daten betroffen sind.
 */

/** Liefert die gespeicherten Demo-Kunden-IDs (kann leer sein). */
function demoCustomerIds(PDO $pdo): array
{
    try {
        $val = $pdo->query(
            "SELECT configuration_value FROM tm_configuration
             WHERE configuration_key = 'demo_customer_ids' LIMIT 1"
        )->fetchColumn();
    } catch (Throwable $e) {
        return [];
    }
    if ($val === false || $val === null || $val === '') {
        return [];
    }
    $ids = json_decode((string) $val, true);
    if (!is_array($ids)) {
        return [];
    }
    return array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
}

/** Gibt es aktuell Demo-Daten (existiert mindestens ein gespeicherter Demo-Kunde)? */
function demoDataExists(PDO $pdo): bool
{
    $ids = demoCustomerIds($pdo);
    if (empty($ids)) {
        return false;
    }
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tm_customers WHERE id IN ($ph)");
    $stmt->execute($ids);
    return (int) $stmt->fetchColumn() > 0;
}

/** Speichert die Demo-Kunden-IDs im internen Konfig-Schlüssel. */
function setDemoCustomerIds(PDO $pdo, array $ids): void
{
    $json = json_encode(array_values(array_map('intval', $ids)));
    $stmt = $pdo->prepare(
        "INSERT INTO tm_configuration
            (configuration_key, configuration_value, configuration_group_id, sort_order, last_modified)
         VALUES ('demo_customer_ids', ?, 0, 0, NOW())
         ON DUPLICATE KEY UPDATE
            configuration_value = VALUES(configuration_value), last_modified = NOW()"
    );
    $stmt->execute([$json]);
}

/**
 * Legt einen vollständigen Demo-Datensatz an.
 *
 * @throws RuntimeException wenn bereits Demo-Daten vorhanden sind.
 * @return array Zusammenfassung (customer_id, Anzahl Zeiten/Aufträge/Rechnung).
 */
function createDemoData(PDO $pdo, int $userId = 0): array
{
    if (demoDataExists($pdo)) {
        throw new RuntimeException('Es sind bereits Demo-Daten vorhanden. Bitte zuerst löschen.');
    }

    // Gültige Benutzer-ID sicherstellen (für tm_entries.user_id / tm_orders.user_id)
    if ($userId <= 0) {
        $userId = (int) ($pdo->query('SELECT id FROM tm_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
    }

    $rate    = 95.00;
    $taxRate = (int) ($pdo->query(
        "SELECT configuration_value FROM tm_configuration
         WHERE configuration_key = 'invoice_tax_rate' LIMIT 1"
    )->fetchColumn() ?: 19);

    // Datumshilfen – Zeitwerte in PHP berechnen (keine SQL-INTERVAL-Bindings).
    $day = static function (int $offsetDays, string $hm): string {
        $ts = strtotime("today {$offsetDays} days");
        return date('Y-m-d', $ts) . ' ' . $hm . ':00';
    };
    $dateOnly = static function (int $offsetDays): string {
        return date('Y-m-d', strtotime("today {$offsetDays} days"));
    };

    // Abgerechnete Zeiten (Teil der Demo-Rechnung)
    $billed = [
        ['off' => -32, 'start' => '09:00', 'end' => '12:00', 'min' => 180, 'act' => 'Konzeption',    'cmt' => 'Anforderungsanalyse und Konzept',  'prj' => 'Website-Relaunch'],
        ['off' => -31, 'start' => '14:00', 'end' => '17:00', 'min' => 180, 'act' => 'Programmierung', 'cmt' => 'Grundgeruest umgesetzt',           'prj' => 'Website-Relaunch'],
        ['off' => -30, 'start' => '10:00', 'end' => '11:30', 'min' => 90,  'act' => 'Testing',        'cmt' => 'Erste Testrunde',                 'prj' => 'Website-Relaunch'],
    ];
    // Offene (nicht abgerechnete) Zeiten
    $open = [
        ['off' => -3, 'start' => '09:00', 'end' => '11:30', 'min' => 150, 'act' => 'Programmierung', 'cmt' => 'Feature: Kontaktformular',  'prj' => 'Wartung'],
        ['off' => -2, 'start' => '13:00', 'end' => '14:00', 'min' => 60,  'act' => 'Support',        'cmt' => 'Telefon-Support',          'prj' => 'Wartung'],
        ['off' => -1, 'start' => '10:00', 'end' => '12:00', 'min' => 120, 'act' => 'Meeting',        'cmt' => 'Abstimmung Roadmap',       'prj' => 'Beratung'],
        ['off' => 0,  'start' => '08:30', 'end' => '09:15', 'min' => 45,  'act' => 'E-Mail',         'cmt' => 'Korrespondenz',            'prj' => 'Wartung'],
    ];

    $pdo->beginTransaction();
    try {
        // 1) Demo-Kunde
        $pdo->prepare(
            "INSERT INTO tm_customers
                (name, active, billable, hourly_rate,
                 billing_name, billing_street, billing_zip, billing_city, billing_email,
                 contact_first_name, contact_last_name, phone_landline, created_at)
             VALUES
                ('Demo GmbH (Musterdaten)', 1, 1, ?,
                 'Demo GmbH', 'Musterstrasse 1', '12345', 'Musterstadt', 'demo@example.com',
                 'Max', 'Mustermann', '01234 567890', NOW())"
        )->execute([$rate]);
        $custId = (int) $pdo->lastInsertId();

        // 2) Demo-Rechnung (Summen zunächst 0, unten neu berechnet)
        $invNumber = 'DEMO-' . date('Y') . '-001';
        $pdo->prepare(
            "INSERT INTO tm_invoices
                (customer_id, invoice_number, invoice_seq, total_minutes, amount_net, amount_gross, created_at)
             VALUES (?, ?, 0, 0, 0.00, 0.00, ?)"
        )->execute([$custId, $invNumber, $dateOnly(-29) . ' 12:00:00']);
        $invId = (int) $pdo->lastInsertId();

        // 3) Abgerechnete Zeiten + Rechnungsposten (Snapshots)
        $insEntry = $pdo->prepare(
            "INSERT INTO tm_entries
                (user_id, customer_id, activity, comment, date, start_datetime, end_datetime,
                 duration_minutes, billable, project, billed_at, invoice_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
        );
        $insItem = $pdo->prepare(
            "INSERT INTO tm_invoice_items
                (invoice_id, entry_id, date, start_datetime, end_datetime, activity, comment,
                 project, duration_minutes, sort_order, visible)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );

        $billedMinutes = 0;
        $sort = 0;
        $billedAt = $dateOnly(-29) . ' 12:00:00';
        foreach ($billed as $e) {
            $date  = $dateOnly($e['off']);
            $start = $day($e['off'], $e['start']);
            $end   = $day($e['off'], $e['end']);
            $insEntry->execute([
                $userId, $custId, $e['act'], $e['cmt'], $date, $start, $end,
                $e['min'], $e['prj'], $billedAt, $invId, $start,
            ]);
            $entryId = (int) $pdo->lastInsertId();
            $insItem->execute([
                $invId, $entryId, $date, $start, $end, $e['act'], $e['cmt'],
                $e['prj'], $e['min'], $sort++,
            ]);
            $billedMinutes += $e['min'];
        }

        // Rechnungssummen berechnen
        $netAmount   = round(($billedMinutes / 60) * $rate, 2);
        $grossAmount = round($netAmount * (1 + $taxRate / 100), 2);
        $pdo->prepare(
            'UPDATE tm_invoices SET total_minutes = ?, amount_net = ?, amount_gross = ? WHERE id = ?'
        )->execute([$billedMinutes, $netAmount, $grossAmount, $invId]);

        // 4) Offene Zeiten
        foreach ($open as $e) {
            $date  = $dateOnly($e['off']);
            $start = $day($e['off'], $e['start']);
            $end   = $day($e['off'], $e['end']);
            $insEntry->execute([
                $userId, $custId, $e['act'], $e['cmt'], $date, $start, $end,
                $e['min'], $e['prj'], null, null, $start,
            ]);
        }

        // 5) Aufträge
        $insOrder = $pdo->prepare(
            "INSERT INTO tm_orders
                (user_id, customer_id, body, status, last_worked_date, created_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $insOrder->execute([
            $userId, $custId,
            '<p>Bitte die <b>Startseite</b> ueberarbeiten und neue Produktbilder einbauen.</p>',
            'offen', null, $dateOnly(-6) . ' 09:15:00', null,
        ]);
        $insOrder->execute([
            $userId, $custId,
            '<p>Angebot fuer einen jaehrlichen <b>Wartungsvertrag</b> erstellen und zusenden.</p>',
            'offen', null, $dateOnly(-2) . ' 16:40:00', null,
        ]);
        $insOrder->execute([
            $userId, $custId,
            '<p>Firmenlogo in Vektorformat (SVG) liefern.</p>',
            'erledigt', null, $dateOnly(-20) . ' 11:00:00', $dateOnly(-15) . ' 14:30:00',
        ]);

        // 6) IDs merken
        setDemoCustomerIds($pdo, [$custId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'customer_id'      => $custId,
        'entries_billed'   => count($billed),
        'entries_open'     => count($open),
        'orders'           => 3,
        'invoice_number'   => $invNumber,
    ];
}

/**
 * Löscht ausschließlich die Demo-Daten (an den gespeicherten Demo-Kunden-IDs).
 *
 * @return array Gelöschte Zeilen je Tabelle.
 */
function deleteDemoData(PDO $pdo): array
{
    $ids = demoCustomerIds($pdo);
    if (empty($ids)) {
        return ['customers' => 0];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));

    // Zugehörige Auftrags-Dateien physisch entfernen (falls vorhanden)
    try {
        $stmt = $pdo->prepare(
            "SELECT f.stored_name FROM tm_order_files f
             JOIN tm_orders o ON o.id = f.order_id
             WHERE o.customer_id IN ($ph)"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stored) {
            $path = dirname(__DIR__) . '/orders/' . basename((string) $stored);
            if (is_file($path)) { @unlink($path); }
        }
    } catch (Throwable $e) { /* Tabelle evtl. nicht vorhanden */ }

    $deleted = [];
    $pdo->beginTransaction();
    try {
        // Kind-Datensätze zuerst, dann Eltern – jeweils nur an den Demo-IDs.
        $run = function (string $sql) use ($pdo, $ids, &$deleted, $ph) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
            return $stmt->rowCount();
        };

        // Auftrags-Dateien
        $deleted['order_files'] = $run(
            "DELETE f FROM tm_order_files f
             JOIN tm_orders o ON o.id = f.order_id
             WHERE o.customer_id IN ($ph)"
        );
        // Aufträge
        $deleted['orders'] = $run("DELETE FROM tm_orders WHERE customer_id IN ($ph)");

        // Rechnungsposten (über die Demo-Rechnungen)
        $deleted['invoice_items'] = $run(
            "DELETE it FROM tm_invoice_items it
             JOIN tm_invoices i ON i.id = it.invoice_id
             WHERE i.customer_id IN ($ph)"
        );
        // Mail-Spool (über die Demo-Rechnungen) – Sicherheit, falls vorhanden
        $deleted['mail_spool'] = $run(
            "DELETE m FROM tm_mail_spool m
             JOIN tm_invoices i ON i.id = m.invoice_id
             WHERE i.customer_id IN ($ph)"
        );
        // Rechnungen
        $deleted['invoices'] = $run("DELETE FROM tm_invoices WHERE customer_id IN ($ph)");
        // Zeiten
        $deleted['entries'] = $run("DELETE FROM tm_entries WHERE customer_id IN ($ph)");
        // Abrechnungsregeln
        $deleted['billing_rules'] = $run("DELETE FROM tm_billing_rules WHERE customer_id IN ($ph)");
        // Shortcuts (Tabelle evtl. nicht vorhanden)
        try {
            $deleted['shortcuts'] = $run("DELETE FROM tm_shortcuts WHERE customer_id IN ($ph)");
        } catch (Throwable $e) { /* ignore */ }
        // Kunde(n)
        $deleted['customers'] = $run("DELETE FROM tm_customers WHERE id IN ($ph)");

        // Marker entfernen
        $pdo->prepare("DELETE FROM tm_configuration WHERE configuration_key = 'demo_customer_ids'")->execute();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $deleted;
}
