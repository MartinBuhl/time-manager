-- Manuelle Sortierung der Auftrags-Bearbeitungsliste (Drag & Drop).
-- 0 = noch nicht manuell positioniert (sortiert dann nach created_at ans Ende).
ALTER TABLE `tm_orders`
    ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `status`;
