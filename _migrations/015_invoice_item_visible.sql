-- Sichtbar-Flag je Rechnungsposten: abgehakte (unsichtbare) Posten
-- erscheinen nicht auf Rechnung/Vorschau/Mail und zählen nicht zur Summe.
ALTER TABLE `tm_invoice_items`
    ADD COLUMN `visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sort_order`;
