-- Projekt je Rechnungsposten, damit die Arbeitsliste in der Mail das
-- Projekt nach der Tätigkeit anzeigen kann.
ALTER TABLE `tm_invoice_items`
    ADD COLUMN `project` VARCHAR(255) DEFAULT NULL AFTER `comment`;

-- Bestehende Posten aus den verknüpften Arbeitszeit-Einträgen nachfüllen.
UPDATE `tm_invoice_items` ii
JOIN `tm_entries` e ON e.id = ii.entry_id
SET ii.`project` = e.`project`
WHERE ii.`entry_id` IS NOT NULL;
