-- Start-/Endzeit je Rechnungsposten, damit die Arbeitsliste in der Mail
-- die Uhrzeit anzeigen kann.
ALTER TABLE `tm_invoice_items`
    ADD COLUMN `start_datetime` DATETIME DEFAULT NULL AFTER `date`,
    ADD COLUMN `end_datetime`   DATETIME DEFAULT NULL AFTER `start_datetime`;

-- Bestehende Posten aus den verknüpften Arbeitszeit-Einträgen nachfüllen.
UPDATE `tm_invoice_items` ii
JOIN `tm_entries` e ON e.id = ii.entry_id
SET ii.`start_datetime` = e.`start_datetime`,
    ii.`end_datetime`   = e.`end_datetime`
WHERE ii.`entry_id` IS NOT NULL;
