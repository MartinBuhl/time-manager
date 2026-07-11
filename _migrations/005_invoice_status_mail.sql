ALTER TABLE `tm_invoices`
    MODIFY COLUMN `status` ENUM('erstellt','pdf_erstellt','mail_vorbereitet') NOT NULL DEFAULT 'erstellt';
