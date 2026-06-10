-- Admin-Dashboard-Layout pro Benutzer dauerhaft in tm_users speichern.
-- Bisher lag es in tm_user_state, das beim Stoppen der Zeiterfassung
-- komplett gelöscht wird – dadurch ging das Layout verloren.
ALTER TABLE `tm_users`
    ADD COLUMN `admin_layout` TEXT DEFAULT NULL AFTER `role`;

-- Vorhandenes Layout aus tm_user_state übernehmen (sofern noch da).
UPDATE `tm_users` u
JOIN `tm_user_state` s ON s.user_id = u.id
SET u.`admin_layout` = s.`admin_layout`
WHERE s.`admin_layout` IS NOT NULL AND s.`admin_layout` <> '';
