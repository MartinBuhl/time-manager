-- Korrigiert Einträge, deren date-Spalte nicht zum start_datetime passt.
-- Ursache: Der Eintrags-Editor im Admin (update_entry) hat beim Ändern des
-- Start-Datums die date-Spalte nicht mitgepflegt. Dadurch wichen Vorschau
-- (nach start_datetime) und Abrechnung (nach date) voneinander ab.
UPDATE `tm_entries`
SET `date` = DATE(`start_datetime`)
WHERE `date` <> DATE(`start_datetime`)
  AND `deleted_at` IS NULL;
