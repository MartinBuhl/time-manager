<?php
/**
 * Gemeinsame Backup-Funktion (SQL-Dump aller tm_*-Tabellen).
 * Wird von der Backup-Funktion (admin/api.php) und vom System-Update
 * (admin/update.php, Vor-Update-Sicherung) genutzt – gleiches Format,
 * damit solche Backups über die Backup-Oberfläche wiederherstellbar sind.
 */

if (!function_exists('buildSqlDump')) {
    /**
     * Erstellt einen vollständigen SQL-Dump aller Systemtabellen (tm_*) als String.
     * Reines PHP/PDO – keine Abhängigkeit von mysqldump/exec.
     */
    function buildSqlDump(PDO $pdo): string
    {
        $tables = $pdo->query("SHOW TABLES LIKE 'tm\\_%'")->fetchAll(PDO::FETCH_COLUMN);

        $out  = "-- Time Manager – Datenbank-Backup\n";
        $out .= '-- Erstellt: ' . date('Y-m-d H:i:s') . "\n\n";
        $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $out .= "-- ----- Tabelle `$table` -----\n";
            $out .= "DROP TABLE IF EXISTS `$table`;\n";
            $out .= ($create['Create Table'] ?? '') . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `$table`");
            $rows->setFetchMode(PDO::FETCH_NUM);
            foreach ($rows as $row) {
                $vals = array_map(
                    fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v),
                    $row
                );
                $out .= "INSERT INTO `$table` VALUES (" . implode(', ', $vals) . ");\n";
            }
            $out .= "\n";
        }

        $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return $out;
    }
}
