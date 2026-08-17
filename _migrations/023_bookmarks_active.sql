-- Aktiv-/Inaktiv-Schalter je Bookmark/Ordner. Inaktive Ordner (inkl. Inhalt)
-- und inaktive Links werden in der App-Leiste ausgeblendet.
ALTER TABLE `tm_bookmarks`
    ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `type`;
