-- Parallele Zeiterfassung: mehrere gleichzeitig laufende Timer je Benutzer.
-- Jede laufende Erfassung ist eine eigene Zeile (statt der einen Zeile in
-- tm_user_state). tm_user_state bleibt für Abwärtskompatibilität bestehen.
CREATE TABLE IF NOT EXISTS `tm_active_timers` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `user_id`       INT          NOT NULL,
    `customer_id`   INT          DEFAULT NULL,
    `customer_name` VARCHAR(255) DEFAULT NULL,
    `activity`      VARCHAR(100) DEFAULT NULL,
    `project`       VARCHAR(255) DEFAULT NULL,
    `start_time`    VARCHAR(8)   DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bereits laufenden Einzel-Timer (falls vorhanden) übernehmen.
INSERT INTO `tm_active_timers` (`user_id`, `customer_id`, `customer_name`, `activity`, `project`, `start_time`)
SELECT `user_id`, `customer_id`, `customer_name`, `activity`, `project`, `start_time`
FROM `tm_user_state`
WHERE `activity` IS NOT NULL AND `start_time` IS NOT NULL;
