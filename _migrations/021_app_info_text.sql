-- Globaler Freitext-Infobereich in der App (index.php, WYSIWYG-editierbar).
-- group_id 0 = kein Eintrag in der Admin-Konfiguration.
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`)
VALUES ('app_info_text', '', 0, 0);
