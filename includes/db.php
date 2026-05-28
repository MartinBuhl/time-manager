<?php
define('APP_VERSION', is_readable(dirname(__DIR__) . '/VERSION')
    ? trim(file_get_contents(dirname(__DIR__) . '/VERSION'))
    : '0.0.0');

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function cfg(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query(
                'SELECT configuration_key, configuration_value FROM tm_configuration'
            )->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['configuration_key']] = $row['configuration_value'];
            }
        } catch (\Throwable $e) {
            // table not yet created
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/**
 * Liefert 1, wenn der Eintrag berechenbar ist (kein Regel-Treffer),
 * sonst 0. Ein Treffer liegt vor, wenn (customer_id, activity, comment)
 * exakt mit einer Regel übereinstimmt (case-insensitive, getrimmt).
 */
function entryIsBillable(?int $customerId, string $activity, ?string $comment): int
{
    if (!$customerId) return 1;
    $stmt = db()->prepare(
        "SELECT 1 FROM tm_billing_rules
         WHERE customer_id = ?
           AND LOWER(TRIM(activity))                    = LOWER(TRIM(?))
           AND LOWER(TRIM(COALESCE(comment, '')))       = LOWER(TRIM(COALESCE(?, '')))
         LIMIT 1"
    );
    $stmt->execute([$customerId, $activity, $comment]);
    return $stmt->fetchColumn() === false ? 1 : 0;
}
