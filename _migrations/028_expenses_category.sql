-- Freies Kategorie-Feld je Ausgabe (Text, max. 255 Zeichen).
ALTER TABLE `tm_expenses`
    ADD COLUMN `category` VARCHAR(255) DEFAULT NULL AFTER `scope`;
