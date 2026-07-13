<?php
// Standard-Tätigkeiten (Fallback und Erstbefüllung der verwaltbaren Liste).
// Die aktive Liste kommt aus der Tabelle tm_activities (siehe getActivities()).
const ACTIVITIES = [
    'Abrechnung',
    'E-Mails bearbeiten',
    'PHP Programmierung',
    'HTML Programmierung',
    'Flash Programmierung',
    'Typo3 Programmierung',
    'Joomla Programmierung',
    'WordPress Programmierung',
    'VB Programmierung',
    'Office Programmierung',
    'Sonstige Programmierung',
    'Webserver Administration',
    'Kunden Aquise',
    'Kundenbetreuung',
    'Telefonat',
    'E-Mail Antwort',
    'Sonstiges',
];

/**
 * Liefert die aktiven Tätigkeiten als Liste von Namen.
 * Fällt auf die Konstante ACTIVITIES zurück, wenn die Tabelle noch nicht
 * existiert (alte Installation vor der Migration) oder leer ist.
 */
function getActivities(?PDO $pdo = null): array
{
    if ($pdo === null && function_exists('db')) {
        $pdo = db();
    }
    if ($pdo instanceof PDO) {
        try {
            $rows = $pdo->query(
                'SELECT name FROM tm_activities WHERE active = 1 ORDER BY sort_order, name'
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($rows)) {
                return $rows;
            }
        } catch (Throwable $e) {
            // Tabelle fehlt noch – Fallback nutzen
        }
    }
    return ACTIVITIES;
}
