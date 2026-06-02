ALTER TABLE `tm_invoices`
    ADD COLUMN `status` ENUM('erstellt','pdf_erstellt') NOT NULL DEFAULT 'erstellt' AFTER `pdf_file`;
