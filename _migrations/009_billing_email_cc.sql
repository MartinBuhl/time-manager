-- Weitere Empfänger (kommagetrennt) für Rechnungsmails (Kopie/CC) pro Kunde.
ALTER TABLE `tm_customers`
    ADD COLUMN `billing_email_cc` VARCHAR(500) DEFAULT NULL AFTER `billing_email`;
