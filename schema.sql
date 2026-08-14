-- Time Manager – Datenbankschema
-- Ausführen: mysql -u root -p datenbankname < schema.sql

CREATE TABLE IF NOT EXISTS `tm_users` (
    `id`         INT                                    NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(50)                            NOT NULL,
    `email`      VARCHAR(255)                           DEFAULT NULL,
    `password`   VARCHAR(255)                           NOT NULL,
    `role`       ENUM('admin','mitarbeiter','kunde')    NOT NULL DEFAULT 'mitarbeiter',
    `admin_layout` TEXT                                 DEFAULT NULL,
    `lang`       VARCHAR(5)                             DEFAULT NULL,
    `created_at` DATETIME                               NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email`    (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Neues Passwort generieren:
-- php -r "echo password_hash('IHR_PASSWORT', PASSWORD_DEFAULT);"
-- Standardpasswort unten: "admin123" – BITTE VOR DEM EINSATZ ÄNDERN!
INSERT IGNORE INTO `tm_users` (`username`, `email`, `password`, `role`)
VALUES ('admin', 'admin@ihre-domain.de', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

CREATE TABLE IF NOT EXISTS `tm_customers` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255) NOT NULL,
    `active`          TINYINT(1)    NOT NULL DEFAULT 1,
    `billable`        TINYINT(1)    NOT NULL DEFAULT 1,
    `projects`        TEXT                   DEFAULT NULL,
    `billing_name`    VARCHAR(255)           DEFAULT NULL,
    `billing_street`  VARCHAR(255)           DEFAULT NULL,
    `billing_zip`     VARCHAR(20)            DEFAULT NULL,
    `billing_city`    VARCHAR(100)           DEFAULT NULL,
    `billing_email`   VARCHAR(255)           DEFAULT NULL,
    `billing_email_cc` VARCHAR(500)          DEFAULT NULL,
    `billing_tax_id`        VARCHAR(50)   DEFAULT NULL,
    `phone_landline`        VARCHAR(50)   DEFAULT NULL,
    `phone_mobile`          VARCHAR(50)   DEFAULT NULL,
    `contact_first_name`    VARCHAR(100)  DEFAULT NULL,
    `contact_last_name`     VARCHAR(100)  DEFAULT NULL,
    `contact_on_invoice`    TINYINT(1)    NOT NULL DEFAULT 0,
    `hourly_rate`     DECIMAL(10,2)          DEFAULT NULL,
    `invoice_mode`    ENUM('entries','text') NOT NULL DEFAULT 'entries',
    `invoice_text`    TEXT                   DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `tm_entries` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `user_id`          INT          NOT NULL,
    `customer_id`      INT          DEFAULT NULL,
    `activity`         VARCHAR(100) NOT NULL,
    `comment`          TEXT         DEFAULT NULL,
    `date`             DATE         NOT NULL,
    `start_datetime`   DATETIME     NOT NULL,
    `end_datetime`     DATETIME     NOT NULL,
    `duration_minutes` INT          NOT NULL,
    `billable`         TINYINT(1)   NOT NULL DEFAULT 1,
    `project`          VARCHAR(255) DEFAULT NULL,
    `deleted_at`       DATETIME     DEFAULT NULL,
    `billed_at`        DATETIME     DEFAULT NULL,
    `invoice_id`       INT          DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_date`  (`user_id`, `date`),
    KEY `idx_customer`   (`customer_id`),
    KEY `idx_invoice_id` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tm_billing_rules` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `customer_id` INT          NOT NULL,
    `activity`    VARCHAR(100) NOT NULL,
    `comment`     VARCHAR(255)          DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_lookup`   (`customer_id`, `activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tm_invoice_items` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `invoice_id`       INT          NOT NULL,
    `entry_id`         INT                   DEFAULT NULL,
    `date`             DATE         NOT NULL,
    `start_datetime`   DATETIME              DEFAULT NULL,
    `end_datetime`     DATETIME              DEFAULT NULL,
    `activity`         VARCHAR(255) NOT NULL,
    `comment`          TEXT                  DEFAULT NULL,
    `project`          VARCHAR(255)          DEFAULT NULL,
    `duration_minutes` INT          NOT NULL,
    `sort_order`       INT          NOT NULL DEFAULT 0,
    `visible`          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_invoice_id` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tm_invoices` (
    `id`             INT           NOT NULL AUTO_INCREMENT,
    `customer_id`    INT           NOT NULL,
    `invoice_number` VARCHAR(50)   NOT NULL,
    `invoice_seq`    INT           NOT NULL DEFAULT 0,
    `total_minutes`  INT           NOT NULL DEFAULT 0,
    `amount_net`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `amount_gross`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `pdf_file`       VARCHAR(255)           DEFAULT NULL,
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tm_mail_spool` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `invoice_id` INT          DEFAULT NULL,
    `subject`    VARCHAR(255) NOT NULL,
    `recipient`  VARCHAR(255) NOT NULL,
    `pdf_file`   VARCHAR(255) DEFAULT NULL,
    `html_body`  MEDIUMTEXT   DEFAULT NULL,
    `text_body`  MEDIUMTEXT   DEFAULT NULL,
    `spooled_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at`    DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_invoice_id` (`invoice_id`),
    KEY `idx_sent_at`    (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tm_password_resets` (
    `token`      CHAR(64)  NOT NULL,
    `user_id`    INT       NOT NULL,
    `expires_at` DATETIME  NOT NULL,
    `created_at` DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`token`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Migration (nur bei bestehender Installation ausführen):
-- ALTER TABLE `tm_users`
--     ADD COLUMN `email` VARCHAR(255) DEFAULT NULL AFTER `username`,
--     ADD UNIQUE KEY `uq_email` (`email`);
-- ALTER TABLE `tm_users`
--     ADD COLUMN `role` ENUM('admin','mitarbeiter','kunde') NOT NULL DEFAULT 'mitarbeiter' AFTER `email`;
-- UPDATE `tm_users` SET `role` = 'admin' WHERE `username` = 'admin';
-- ALTER TABLE `tm_entries`
--     ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `duration_minutes`;
-- ALTER TABLE `tm_customers`
--     ADD COLUMN `projects` TEXT DEFAULT NULL AFTER `active`;
-- ALTER TABLE `tm_customers`
--     ADD COLUMN `billing_name`   VARCHAR(255)  DEFAULT NULL AFTER `projects`,
--     ADD COLUMN `billing_street` VARCHAR(255)  DEFAULT NULL AFTER `billing_name`,
--     ADD COLUMN `billing_zip`    VARCHAR(20)   DEFAULT NULL AFTER `billing_street`,
--     ADD COLUMN `billing_city`   VARCHAR(100)  DEFAULT NULL AFTER `billing_zip`,
--     ADD COLUMN `billing_email`  VARCHAR(255)  DEFAULT NULL AFTER `billing_city`,
--     ADD COLUMN `billing_tax_id` VARCHAR(50)   DEFAULT NULL AFTER `billing_email`,
--     ADD COLUMN `hourly_rate`    DECIMAL(10,2) DEFAULT NULL AFTER `billing_tax_id`;
-- ALTER TABLE `tm_entries`
--     ADD COLUMN `billed_at` DATETIME DEFAULT NULL AFTER `deleted_at`;
-- ALTER TABLE `tm_entries`
--     ADD COLUMN `project` VARCHAR(255) DEFAULT NULL AFTER `customer_id`;
-- ALTER TABLE `tm_user_state`
--     ADD COLUMN `project` VARCHAR(255) DEFAULT NULL AFTER `activity`;
-- ALTER TABLE `tm_customers`
--     ADD COLUMN `billable` TINYINT(1) NOT NULL DEFAULT 1 AFTER `active`;
-- ALTER TABLE `tm_customers`
--     ADD COLUMN `contact_first_name` VARCHAR(100) DEFAULT NULL AFTER `billing_tax_id`,
--     ADD COLUMN `contact_last_name`  VARCHAR(100) DEFAULT NULL AFTER `contact_first_name`,
--     ADD COLUMN `contact_on_invoice` TINYINT(1)   NOT NULL DEFAULT 0 AFTER `contact_last_name`;
-- ALTER TABLE `tm_customers`
--     ADD COLUMN `phone_landline` VARCHAR(50) DEFAULT NULL AFTER `billing_tax_id`,
--     ADD COLUMN `phone_mobile`   VARCHAR(50) DEFAULT NULL AFTER `phone_landline`;
-- ALTER TABLE `tm_customers`
--     ADD COLUMN `invoice_mode` ENUM('entries','text') NOT NULL DEFAULT 'entries' AFTER `hourly_rate`,
--     ADD COLUMN `invoice_text` TEXT DEFAULT NULL AFTER `invoice_mode`;
-- ALTER TABLE `tm_customers`
--     ADD COLUMN `mail_template_html`  MEDIUMTEXT DEFAULT NULL AFTER `invoice_text`,
--     ADD COLUMN `mail_template_plain` TEXT       DEFAULT NULL AFTER `mail_template_html`;
-- ALTER TABLE `tm_mail_spool`
--     ADD COLUMN `archived_at` DATETIME DEFAULT NULL AFTER `sent_at`;
-- ALTER TABLE `tm_invoices`
--     ADD COLUMN `invoice_seq` INT NOT NULL DEFAULT 0 AFTER `invoice_number`;
-- ALTER TABLE `tm_invoices`
--     ADD COLUMN `pdf_file` VARCHAR(255) DEFAULT NULL AFTER `amount_gross`;
-- ALTER TABLE `tm_entries`
--     ADD COLUMN `invoice_id` INT DEFAULT NULL AFTER `billed_at`,
--     ADD KEY `idx_invoice_id` (`invoice_id`);
-- CREATE TABLE IF NOT EXISTS `tm_mail_spool` (
--     `id` INT NOT NULL AUTO_INCREMENT,
--     `invoice_id` INT DEFAULT NULL,
--     `subject` VARCHAR(255) NOT NULL,
--     `recipient` VARCHAR(255) NOT NULL,
--     `pdf_file` VARCHAR(255) DEFAULT NULL,
--     `html_body` MEDIUMTEXT DEFAULT NULL,
--     `text_body` MEDIUMTEXT DEFAULT NULL,
--     `spooled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     `sent_at` DATETIME DEFAULT NULL,
--     PRIMARY KEY (`id`),
--     KEY `idx_invoice_id` (`invoice_id`),
--     KEY `idx_sent_at` (`sent_at`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- INSERT IGNORE INTO `tm_configuration` (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`) VALUES
-- ('invoice_number_prefix', 'RE-', 2, 40),
-- ('invoice_number_start',  '1',   2, 50);
-- ALTER TABLE `tm_entries`
--     ADD COLUMN `billable` TINYINT(1) NOT NULL DEFAULT 1 AFTER `duration_minutes`;
-- CREATE TABLE IF NOT EXISTS `tm_billing_rules` (
--     `id` INT NOT NULL AUTO_INCREMENT,
--     `customer_id` INT NOT NULL,
--     `activity` VARCHAR(100) NOT NULL,
--     `comment` VARCHAR(255) DEFAULT NULL,
--     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     PRIMARY KEY (`id`),
--     KEY `idx_customer` (`customer_id`),
--     KEY `idx_lookup` (`customer_id`, `activity`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tm_configuration` (
    `configuration_id`       INT          NOT NULL AUTO_INCREMENT,
    `configuration_key`      VARCHAR(128) NOT NULL,
    `configuration_value`    TEXT         NOT NULL,
    `configuration_group_id` INT          NOT NULL,
    `sort_order`             INT(5)       DEFAULT NULL,
    `last_modified`          DATETIME     DEFAULT NULL,
    `date_added`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`configuration_id`),
    UNIQUE KEY `uq_key` (`configuration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gruppe 1 = Rechnungsabsender, Gruppe 2 = Rechnungsparameter
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`) VALUES
('invoice_company',        'Ihre Firma GmbH',                  1,  10),
('invoice_street',         'Musterstraße 1',                   1,  20),
('invoice_zip',            '12345',                            1,  30),
('invoice_city',           'Musterstadt',                      1,  40),
('invoice_email',          'info@ihre-firma.de',               1,  50),
('invoice_phone',          '+49 123 456789',                   1,  60),
('invoice_tax_id',         'DE123456789',                      1,  70),
('invoice_tax_number',     '',                                 1,  75),
('invoice_iban',           'DE12 3456 7890 1234 5678 90',      1,  80),
('invoice_bic',            'MUSTERDEBBXXX',                    1,  90),
('invoice_bank',           'Musterbank',                       1, 100),
('invoice_account_holder', '',                                 1, 110),
('invoice_hourly_rate',     '85.00',  2,  10),
('invoice_tax_rate',       '19',     2,  20),
('invoice_payment_days',   '14',     2,  30),
('invoice_number_prefix',  'RE-',    2,  40),
('invoice_number_start',   '1',      2,  50),
('invoice_mail_subject',   'Rechnung {project} – {time}', 2, 60),
('invoice_mail_bcc',       '',                            2, 65),
('invoice_general_info',   '',                            2, 66),
('site_url',             'https://ihre-domain.de/time_manager', 3, 10),
('mail_from',            'noreply@ihre-domain.de',           3,  20),
('mail_name',            'Time Manager',                     3,  30),
('mail_bcc',             '',                                 3,  35),
('mail_signature_html',  '',                                 3,  40),
('mail_signature_plain', '',                                 3,  50),
('smtp_host',            '',                                 4,  10),
('smtp_port',            '587',                             4,  20),
('smtp_user',            '',                                 4,  30),
('smtp_password',        '',                                 4,  40),
('smtp_encryption',      'tls',                             4,  50),
('imap_save_sent',       '0',                                4,  60),
('imap_host',            '',                                 4,  70),
('imap_port',            '993',                             4,  80),
('imap_encryption',      'ssl',                             4,  90),
('imap_sent_folder',     'Sent',                            4, 100),
('app_info_text',        '',                                 0,   0);

CREATE TABLE IF NOT EXISTS `tm_user_state` (
    `user_id`       INT          NOT NULL,
    `customer_id`   INT          DEFAULT NULL,
    `customer_name` VARCHAR(255) DEFAULT NULL,
    `activity`      VARCHAR(100) DEFAULT NULL,
    `project`       VARCHAR(255) DEFAULT NULL,
    `start_time`    VARCHAR(8)   DEFAULT NULL,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tm_orders` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `user_id`          INT          DEFAULT NULL,
    `customer_id`      INT          NOT NULL,
    `body`             MEDIUMTEXT   DEFAULT NULL,
    `status`           ENUM('offen','erledigt') NOT NULL DEFAULT 'offen',
    `sort_order`       INT          NOT NULL DEFAULT 0,
    `last_worked_date` DATE         DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`     DATETIME     DEFAULT NULL,
    `deleted_at`       DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tm_order_files` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `order_id`      INT          NOT NULL,
    `stored_name`   VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `mime`          VARCHAR(150) DEFAULT NULL,
    `size`          INT          DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tm_activities` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100) NOT NULL,
    `active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `sort_order` INT          NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
