-- Aufträge (Bearbeitungsliste) mit Text und Datei-Anhängen.
CREATE TABLE IF NOT EXISTS `tm_orders` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `user_id`          INT          DEFAULT NULL,
    `customer_id`      INT          NOT NULL,
    `body`             MEDIUMTEXT   DEFAULT NULL,
    `status`           ENUM('offen','erledigt') NOT NULL DEFAULT 'offen',
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
