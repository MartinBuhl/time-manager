-- Allgemeine Info-Text, der auf allen Rechnungen unter dem Gesamtbetrag
-- erscheint (Rechnungsparameter, Gruppe 2).
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`)
VALUES ('invoice_general_info', '', 2, 66);
