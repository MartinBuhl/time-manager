-- Aktiv-/Deaktiviert-Schalter je Ausgabe. Deaktivierte Ausgaben werden bei der
-- Monatssumme nicht berücksichtigt.
ALTER TABLE `tm_expenses`
    ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `scope`;
