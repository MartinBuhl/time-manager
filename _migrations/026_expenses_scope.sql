-- Ausgabe-Art: geschäftlich (Standard) oder privat.
ALTER TABLE `tm_expenses`
    ADD COLUMN `scope` ENUM('business','private') NOT NULL DEFAULT 'business' AFTER `currency`;
