-- Grundlage der optionalen Feldverschlüsselung. Verschlüsselt selbst noch nichts.
-- Verschlüsselte Werte sind länger als der Klartext: VARCHAR wird zu TEXT,
-- TEXT zu MEDIUMTEXT. NULL-Verhalten und Spaltenreihenfolge bleiben gleich.

ALTER TABLE `tm_users`
    ADD COLUMN IF NOT EXISTS `enc_key_envelope` TEXT DEFAULT NULL AFTER `password`;

-- Gruppe 6 = Sicherheit; beide Werte bleiben immer Klartext.
-- encryption_state: off | encrypting | on | decrypting
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`) VALUES
('encryption_state',             'off', 6, 10),
('encryption_recovery_envelope', '',    6, 20);

ALTER TABLE `tm_configuration`
    MODIFY COLUMN IF EXISTS `configuration_value` MEDIUMTEXT NOT NULL;

ALTER TABLE `tm_customers`
    MODIFY COLUMN IF EXISTS `billing_name`        TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `billing_street`      TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `billing_zip`         TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `billing_city`        TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `billing_email`       TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `billing_email_cc`    TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `billing_tax_id`      TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `phone_landline`      TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `phone_mobile`        TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `contact_first_name`  TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `contact_last_name`   TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `invoice_text`        MEDIUMTEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `mail_template_plain` MEDIUMTEXT DEFAULT NULL;

ALTER TABLE `tm_invoices`
    MODIFY COLUMN IF EXISTS `invoice_text`        MEDIUMTEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `mail_template_plain` MEDIUMTEXT DEFAULT NULL;

ALTER TABLE `tm_mail_spool`
    MODIFY COLUMN IF EXISTS `subject`   TEXT NOT NULL,
    MODIFY COLUMN IF EXISTS `recipient` TEXT NOT NULL;

ALTER TABLE `tm_order_files`
    MODIFY COLUMN IF EXISTS `original_name` TEXT NOT NULL;

ALTER TABLE `tm_expenses`
    MODIFY COLUMN IF EXISTS `description` MEDIUMTEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `url`         TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `username`    TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `email`       TEXT DEFAULT NULL,
    MODIFY COLUMN IF EXISTS `pw_info`     TEXT DEFAULT NULL;

ALTER TABLE `tm_payments`
    MODIFY COLUMN IF EXISTS `title` TEXT NOT NULL DEFAULT '',
    MODIFY COLUMN IF EXISTS `note`  MEDIUMTEXT DEFAULT NULL;
