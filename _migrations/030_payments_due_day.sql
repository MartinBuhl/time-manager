-- Expliziter Fälligkeitstag (Tag im Monat, 1–31) für wiederkehrende Zahlungen.
-- Bei einmaligen Zahlungen NULL (dort zählt das exakte Datum).
ALTER TABLE `tm_payments`
    ADD COLUMN `due_day` TINYINT DEFAULT NULL AFTER `due_date`;
