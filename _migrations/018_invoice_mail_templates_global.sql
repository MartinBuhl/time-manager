-- Globale Standard-Mailvorlagen für Rechnungen (Rechnungsparameter, Gruppe 2).
-- Dienen als Vorlage, die beim Kunden per Button übernommen werden kann.
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`)
VALUES
    ('invoice_mail_template_html',  '', 2, 67),
    ('invoice_mail_template_plain', '', 2, 68);
