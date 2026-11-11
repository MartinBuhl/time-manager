-- Kommentar eines laufenden Timers zwischenspeichern, damit er beim Neuladen
-- der Seite (z. B. nach dem Stoppen eines parallelen Timers) erhalten bleibt.
ALTER TABLE `tm_active_timers` ADD COLUMN `comment` TEXT DEFAULT NULL AFTER `start_time`;
