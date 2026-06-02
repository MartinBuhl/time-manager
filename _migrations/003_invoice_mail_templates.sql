ALTER TABLE `tm_invoices`
    ADD COLUMN `mail_template_html`  MEDIUMTEXT DEFAULT NULL AFTER `invoice_text`,
    ADD COLUMN `mail_template_plain` TEXT       DEFAULT NULL AFTER `mail_template_html`;
