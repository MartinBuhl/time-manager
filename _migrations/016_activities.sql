-- Tätigkeiten als verwaltbare Liste (bisher fest im Code).
-- Bestehende Zeiteinträge speichern die Tätigkeit als Freitext und bleiben
-- unverändert gültig; diese Tabelle steuert nur die Auswahl-Dropdowns.
CREATE TABLE IF NOT EXISTS `tm_activities` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100) NOT NULL,
    `active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `sort_order` INT          NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bisherige Standard-Tätigkeiten übernehmen (nur falls noch nicht vorhanden).
INSERT IGNORE INTO `tm_activities` (`name`, `sort_order`) VALUES
    ('Abrechnung', 1),
    ('E-Mails bearbeiten', 2),
    ('PHP Programmierung', 3),
    ('HTML Programmierung', 4),
    ('Flash Programmierung', 5),
    ('Typo3 Programmierung', 6),
    ('Joomla Programmierung', 7),
    ('WordPress Programmierung', 8),
    ('VB Programmierung', 9),
    ('Office Programmierung', 10),
    ('Sonstige Programmierung', 11),
    ('Webserver Administration', 12),
    ('Kunden Aquise', 13),
    ('Kundenbetreuung', 14),
    ('Telefonat', 15),
    ('E-Mail Antwort', 16),
    ('Sonstiges', 17);
