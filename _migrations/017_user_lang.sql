-- Mehrsprachigkeit: Sprache pro Benutzer + globaler Default.
ALTER TABLE `tm_users` ADD COLUMN `lang` VARCHAR(5) DEFAULT NULL AFTER `admin_layout`;

INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`)
VALUES ('default_lang', 'de', 0, 0);
