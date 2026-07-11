ALTER TABLE `tm_invoices`
    ADD COLUMN `invoice_date` DATE                   DEFAULT NULL          AFTER `customer_id`,
    ADD COLUMN `period_start` DATE                   DEFAULT NULL          AFTER `invoice_date`,
    ADD COLUMN `period_end`   DATE                   DEFAULT NULL          AFTER `period_start`,
    ADD COLUMN `invoice_mode` ENUM('entries','text') NOT NULL DEFAULT 'entries' AFTER `period_end`,
    ADD COLUMN `invoice_text` TEXT                   DEFAULT NULL          AFTER `invoice_mode`,
    ADD COLUMN `tax_rate`     TINYINT UNSIGNED       NOT NULL DEFAULT 19   AFTER `invoice_text`,
    ADD COLUMN `hourly_rate`  DECIMAL(10,2)          DEFAULT NULL          AFTER `tax_rate`;
