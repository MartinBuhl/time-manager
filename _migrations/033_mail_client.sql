-- E-Mail-Client: eigene Zugangsdaten, unabhängig vom Rechnungsversand (Gruppe 4).
-- Gruppe 7 = E-Mail-Client. Bei aktiver Verschlüsselung werden die Werte beim
-- Speichern verschlüsselt (alle Konfigurationswerte außer den Klartext-Schlüsseln).

INSERT IGNORE INTO `tm_configuration`
    (`configuration_key`, `configuration_value`, `configuration_group_id`, `sort_order`) VALUES
('mc_from_name',       '',    7, 10),
('mc_from_email',      '',    7, 20),
('mc_imap_host',       '',    7, 30),
('mc_imap_port',       '993', 7, 40),
('mc_imap_encryption', 'ssl', 7, 50),
('mc_imap_user',       '',    7, 60),
('mc_imap_password',   '',    7, 70),
('mc_smtp_host',       '',    7, 80),
('mc_smtp_port',       '465', 7, 90),
('mc_smtp_encryption', 'ssl', 7, 100),
('mc_smtp_user',       '',    7, 110),
('mc_smtp_password',   '',    7, 120),
('mc_sent_folder',     '',    7, 130),
('mc_trash_folder',    '',    7, 140),
('mc_drafts_folder',   '',    7, 150),
('mc_save_sent',       '1',   7, 155),
('mc_signature',       '',    7, 160);
