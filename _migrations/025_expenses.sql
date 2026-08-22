-- Regelmäßige Ausgaben (Admin-Verwaltung).
CREATE TABLE IF NOT EXISTS `tm_expenses` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255)  NOT NULL DEFAULT '',
    `description` TEXT          DEFAULT NULL,
    `amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `period`      ENUM('day','month','year') NOT NULL DEFAULT 'month',
    `currency`    ENUM('EUR','USD')          NOT NULL DEFAULT 'EUR',
    `url`         VARCHAR(500)  DEFAULT NULL,
    `username`    VARCHAR(255)  DEFAULT NULL,
    `email`       VARCHAR(255)  DEFAULT NULL,
    `pw_info`     VARCHAR(50)   DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
