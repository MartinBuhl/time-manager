-- Neuer Konfigurationswert: Kopie der Rechnungsmails (BCC) unter
-- Rechnungsparameter (Gruppe 2).
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`)
VALUES ('invoice_mail_bcc', '', 2, 65);
