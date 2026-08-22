-- Rotation der Auftrags-Bearbeitungsliste nach "zuletzt bearbeitet" (pro Kunde).
-- NULL = noch nie bearbeitet (sortiert dann nach dem aeltesten offenen Auftrag).
ALTER TABLE `tm_customers`
    ADD COLUMN `last_worked_at` DATETIME(6) DEFAULT NULL;
