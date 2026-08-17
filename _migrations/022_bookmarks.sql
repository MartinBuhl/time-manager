-- Bookmarks-System (Firefox-artige Lesezeichen-Leiste in der App).
-- Baumstruktur: parent_id = NULL -> oberste Ebene (Symbolleiste).
CREATE TABLE IF NOT EXISTS `tm_bookmarks` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `parent_id`  INT          DEFAULT NULL,
    `type`       ENUM('folder','link') NOT NULL DEFAULT 'link',
    `title`      VARCHAR(500) NOT NULL DEFAULT '',
    `url`        TEXT         DEFAULT NULL,
    `sort_order` INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
