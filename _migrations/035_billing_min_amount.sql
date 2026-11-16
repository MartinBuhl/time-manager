-- Abrechnungsliste (admin/billing.php): nur Kunden ab diesem Nettobetrag anzeigen.
-- 0 = alle Kunden anzeigen.
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`) VALUES
('billing_min_amount', '0', 2, 15);
