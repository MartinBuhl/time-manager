-- IMAP-Ablage versendeter Rechnungsmails im Sent-Ordner (Gruppe 4: Mailversand).
-- Login erfolgt mit den vorhandenen SMTP-Zugangsdaten (smtp_user/smtp_password).
INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`) VALUES
('imap_save_sent',   '0',     4, 60),
('imap_host',        '',      4, 70),
('imap_port',        '993',   4, 80),
('imap_encryption',  'ssl',   4, 90),
('imap_sent_folder', 'Sent',  4, 100);
