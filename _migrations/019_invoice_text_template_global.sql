-- Globaler Standard-Rechnungstext (Rechnungsparameter, Gruppe 2).
-- Dient als Vorlage, die beim Kunden per Button in den Rechnungstext
-- uebernommen werden kann. Platzhalter: {project}.
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`)
VALUES
    ('invoice_text_template', '', 2, 65);
