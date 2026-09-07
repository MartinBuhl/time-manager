-- Hochgeladene Belege/Dateien je Zahlung als JSON-Liste.
-- Jeder Eintrag: {stored, name, size, mime, uploaded}. NULL = keine Dateien.
ALTER TABLE `tm_payments`
    ADD COLUMN `files` TEXT DEFAULT NULL AFTER `note`;
