-- Wiederkehrende und einmalige Zahlungen inkl. E-Mail-Erinnerungen.
CREATE TABLE IF NOT EXISTS `tm_payments` (
    `id`               INT           NOT NULL AUTO_INCREMENT,
    `title`            VARCHAR(255)  NOT NULL DEFAULT '',
    `note`             TEXT          DEFAULT NULL,
    `amount`           DECIMAL(12,2) NOT NULL DEFAULT 0,
    `currency`         ENUM('EUR','USD') NOT NULL DEFAULT 'EUR',
    `recurrence`       ENUM('once','monthly','quarterly','yearly') NOT NULL DEFAULT 'once',
    `due_date`         DATE          NOT NULL,
    `active`           TINYINT(1)    NOT NULL DEFAULT 1,
    `done`             TINYINT(1)    NOT NULL DEFAULT 0,
    `reminder_stage`   TINYINT(1)    NOT NULL DEFAULT 0,
    `last_reminded_on` DATE          DEFAULT NULL,
    `last_paid_at`     DATETIME      DEFAULT NULL,
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_due` (`active`, `done`, `due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Konfiguration: Gruppe 5 = Zahlungserinnerungen
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`) VALUES
('payment_reminder_email',       '', 5, 10),
('payment_reminder_days_first',  '7', 5, 20),
('payment_reminder_days_second', '3', 5, 30),
('payment_reminder_days_daily',  '2', 5, 40);
