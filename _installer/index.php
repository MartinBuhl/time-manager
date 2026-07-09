<?php
declare(strict_types=1);

// -------------------------------------------------------
// Time Manager – Installer
// Selbst-ständig, ohne Abhängigkeiten zum Hauptprogramm.
// -------------------------------------------------------

define('LOCK_FILE',   __DIR__ . '/installed.lock');
define('CONFIG_FILE', dirname(__DIR__) . '/config.php');

if (file_exists(LOCK_FILE)) {
    header('Location: ../index.php');
    exit;
}

session_name('tm_installer');
session_start();

$step   = 1;
$errors = [];

// ---- Hilfsfunktionen ----------------------------------------

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function checkReqs(): array
{
    $r = [];
    $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
    $r[] = ['PHP-Version >= 8.0', PHP_VERSION, $phpOk];
    foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl'] as $ext) {
        $ok = extension_loaded($ext);
        $r[] = ['PHP-Erweiterung: ' . $ext, $ok ? 'vorhanden' : 'fehlt', $ok];
    }
    $confWritable = is_writable(CONFIG_FILE)
        || (!file_exists(CONFIG_FILE) && is_writable(dirname(CONFIG_FILE)));
    $r[] = ['config.php beschreibbar', $confWritable ? 'OK' : 'Keine Schreibrechte', $confWritable];
    return $r;
}

function allOk(array $checks): bool
{
    foreach ($checks as [,, $ok]) { if (!$ok) return false; }
    return true;
}

function tableSql(): array
{
    return [
        'tm_users' =>
        "CREATE TABLE IF NOT EXISTS `tm_users` (
            `id`         INT NOT NULL AUTO_INCREMENT,
            `username`   VARCHAR(50)  NOT NULL,
            `email`      VARCHAR(255) DEFAULT NULL,
            `password`   VARCHAR(255) NOT NULL,
            `role`       ENUM('admin','mitarbeiter','kunde') NOT NULL DEFAULT 'mitarbeiter',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_username` (`username`),
            UNIQUE KEY `uq_email`    (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_customers' =>
        "CREATE TABLE IF NOT EXISTS `tm_customers` (
            `id`                  INT           NOT NULL AUTO_INCREMENT,
            `name`                VARCHAR(255)  NOT NULL,
            `active`              TINYINT(1)    NOT NULL DEFAULT 1,
            `billable`            TINYINT(1)    NOT NULL DEFAULT 1,
            `projects`            TEXT          DEFAULT NULL,
            `billing_name`        VARCHAR(255)  DEFAULT NULL,
            `billing_street`      VARCHAR(255)  DEFAULT NULL,
            `billing_zip`         VARCHAR(20)   DEFAULT NULL,
            `billing_city`        VARCHAR(100)  DEFAULT NULL,
            `billing_email`       VARCHAR(255)  DEFAULT NULL,
            `billing_tax_id`      VARCHAR(50)   DEFAULT NULL,
            `phone_landline`      VARCHAR(50)   DEFAULT NULL,
            `phone_mobile`        VARCHAR(50)   DEFAULT NULL,
            `contact_first_name`  VARCHAR(100)  DEFAULT NULL,
            `contact_last_name`   VARCHAR(100)  DEFAULT NULL,
            `contact_on_invoice`  TINYINT(1)    NOT NULL DEFAULT 0,
            `hourly_rate`         DECIMAL(10,2) DEFAULT NULL,
            `invoice_mode`        ENUM('entries','text') NOT NULL DEFAULT 'entries',
            `invoice_text`        TEXT          DEFAULT NULL,
            `mail_template_html`  MEDIUMTEXT    DEFAULT NULL,
            `mail_template_plain` TEXT          DEFAULT NULL,
            `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_entries' =>
        "CREATE TABLE IF NOT EXISTS `tm_entries` (
            `id`               INT          NOT NULL AUTO_INCREMENT,
            `user_id`          INT          NOT NULL,
            `customer_id`      INT          DEFAULT NULL,
            `activity`         VARCHAR(100) NOT NULL,
            `comment`          TEXT         DEFAULT NULL,
            `date`             DATE         NOT NULL,
            `start_datetime`   DATETIME     NOT NULL,
            `end_datetime`     DATETIME     NOT NULL,
            `duration_minutes` INT          NOT NULL,
            `billable`         TINYINT(1)   NOT NULL DEFAULT 1,
            `project`          VARCHAR(255) DEFAULT NULL,
            `deleted_at`       DATETIME     DEFAULT NULL,
            `billed_at`        DATETIME     DEFAULT NULL,
            `invoice_id`       INT          DEFAULT NULL,
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_date`  (`user_id`, `date`),
            KEY `idx_customer`   (`customer_id`),
            KEY `idx_invoice_id` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_billing_rules' =>
        "CREATE TABLE IF NOT EXISTS `tm_billing_rules` (
            `id`          INT          NOT NULL AUTO_INCREMENT,
            `customer_id` INT          NOT NULL,
            `activity`    VARCHAR(100) NOT NULL,
            `comment`     VARCHAR(255) DEFAULT NULL,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_customer` (`customer_id`),
            KEY `idx_lookup`   (`customer_id`, `activity`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_invoices' =>
        "CREATE TABLE IF NOT EXISTS `tm_invoices` (
            `id`             INT           NOT NULL AUTO_INCREMENT,
            `customer_id`    INT           NOT NULL,
            `invoice_number` VARCHAR(50)   NOT NULL,
            `invoice_seq`    INT           NOT NULL DEFAULT 0,
            `total_minutes`  INT           NOT NULL DEFAULT 0,
            `amount_net`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `amount_gross`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `pdf_file`       VARCHAR(255)  DEFAULT NULL,
            `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_customer_id` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_invoice_items' =>
        "CREATE TABLE IF NOT EXISTS `tm_invoice_items` (
            `id`               INT          NOT NULL AUTO_INCREMENT,
            `invoice_id`       INT          NOT NULL,
            `entry_id`         INT          DEFAULT NULL,
            `date`             DATE         NOT NULL,
            `activity`         VARCHAR(100) NOT NULL,
            `comment`          TEXT         DEFAULT NULL,
            `duration_minutes` INT          NOT NULL,
            `sort_order`       INT          NOT NULL DEFAULT 0,
            `visible`          TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_invoice_id` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_mail_spool' =>
        "CREATE TABLE IF NOT EXISTS `tm_mail_spool` (
            `id`          INT          NOT NULL AUTO_INCREMENT,
            `invoice_id`  INT          DEFAULT NULL,
            `subject`     VARCHAR(255) NOT NULL,
            `recipient`   VARCHAR(255) NOT NULL,
            `pdf_file`    VARCHAR(255) DEFAULT NULL,
            `html_body`   MEDIUMTEXT   DEFAULT NULL,
            `text_body`   MEDIUMTEXT   DEFAULT NULL,
            `spooled_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `sent_at`     DATETIME     DEFAULT NULL,
            `archived_at` DATETIME     DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_invoice_id` (`invoice_id`),
            KEY `idx_sent_at`    (`sent_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_password_resets' =>
        "CREATE TABLE IF NOT EXISTS `tm_password_resets` (
            `token`      CHAR(64)  NOT NULL,
            `user_id`    INT       NOT NULL,
            `expires_at` DATETIME  NOT NULL,
            `created_at` DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`token`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_configuration' =>
        "CREATE TABLE IF NOT EXISTS `tm_configuration` (
            `configuration_id`       INT          NOT NULL AUTO_INCREMENT,
            `configuration_key`      VARCHAR(128) NOT NULL,
            `configuration_value`    TEXT         NOT NULL,
            `configuration_group_id` INT          NOT NULL,
            `sort_order`             INT          DEFAULT NULL,
            `last_modified`          DATETIME     DEFAULT NULL,
            `date_added`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`configuration_id`),
            UNIQUE KEY `uq_key` (`configuration_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_user_state' =>
        "CREATE TABLE IF NOT EXISTS `tm_user_state` (
            `user_id`       INT          NOT NULL,
            `customer_id`   INT          DEFAULT NULL,
            `customer_name` VARCHAR(255) DEFAULT NULL,
            `activity`      VARCHAR(100) DEFAULT NULL,
            `project`       VARCHAR(255) DEFAULT NULL,
            `start_time`    VARCHAR(8)   DEFAULT NULL,
            `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_shortcuts' =>
        "CREATE TABLE IF NOT EXISTS `tm_shortcuts` (
            `id`            INT          NOT NULL AUTO_INCREMENT,
            `customer_id`   INT          DEFAULT NULL,
            `activity`      VARCHAR(100) NOT NULL,
            `shortcut_text` VARCHAR(255) NOT NULL,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tm_migrations' =>
        "CREATE TABLE IF NOT EXISTS `tm_migrations` (
            `id`         INT          NOT NULL AUTO_INCREMENT,
            `filename`   VARCHAR(255) NOT NULL,
            `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_filename` (`filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function defaultConfigSql(): string
{
    return "INSERT IGNORE INTO `tm_configuration`
        (`configuration_key`,`configuration_value`,`configuration_group_id`,`sort_order`) VALUES
    ('invoice_company',        '',                                 1,  10),
    ('invoice_street',         '',                                 1,  20),
    ('invoice_zip',            '',                                 1,  30),
    ('invoice_city',           '',                                 1,  40),
    ('invoice_email',          '',                                 1,  50),
    ('invoice_phone',          '',                                 1,  60),
    ('invoice_tax_id',         '',                                 1,  70),
    ('invoice_tax_number',     '',                                 1,  75),
    ('invoice_bank',           '',                                 1,  80),
    ('invoice_account_holder', '',                                 1, 100),
    ('invoice_iban',           '',                                 1, 110),
    ('invoice_bic',            '',                                 1, 120),
    ('invoice_hourly_rate',    '85.00',                            2,  10),
    ('invoice_tax_rate',       '19',                               2,  20),
    ('invoice_payment_days',   '14',                               2,  30),
    ('invoice_number_prefix',  'RE-',                              2,  40),
    ('invoice_number_start',   '1',                                2,  50),
    ('invoice_mail_subject',   'Rechnung {project} - {time}',      2,  60),
    ('invoice_general_info',   '',                                 2,  66),
    ('github_repo',            '',                                 3,   5),
    ('site_url',               '',                                 3,  10),
    ('mail_from',              '',                                 3,  20),
    ('mail_name',              'Time Manager',                     3,  30),
    ('mail_bcc',               '',                                 3,  35),
    ('mail_signature_html',    '',                                 3,  40),
    ('mail_signature_plain',   '',                                 3,  50),
    ('smtp_host',              '',                                 4,  10),
    ('smtp_port',              '587',                              4,  20),
    ('smtp_user',              '',                                 4,  30),
    ('smtp_password',          '',                                 4,  40),
    ('smtp_encryption',        'tls',                              4,  50)";
}

/**
 * Spielt nach dem Anlegen der Basis-Tabellen alle vorhandenen
 * _migrations/*.sql ein, damit eine Neuinstallation dasselbe vollständige
 * Schema wie eine aktualisierte Installation hat. Bereits im Basis-Schema
 * enthaltene Änderungen (z. B. doppelte Spalte) werden toleriert. Jede
 * Migration wird in tm_migrations vermerkt, damit ein späteres System-Update
 * sie nicht erneut ausführt.
 */
function runInstallerMigrations(PDO $pdo): void
{
    $files = glob(dirname(__DIR__) . '/_migrations/*.sql') ?: [];
    sort($files);
    $ins = $pdo->prepare('INSERT IGNORE INTO `tm_migrations` (`filename`) VALUES (?)');
    foreach ($files as $file) {
        try {
            $pdo->exec((string)file_get_contents($file));
        } catch (PDOException $e) {
            // 1050 Table exists, 1060 Duplicate column, 1061 Duplicate key,
            // 1062 Duplicate entry, 1091 Can't DROP – Änderung schon vorhanden.
            $code = (int)($e->errorInfo[1] ?? 0);
            if (!in_array($code, [1050, 1060, 1061, 1062, 1091], true)) {
                throw $e;
            }
        }
        $ins->execute([basename($file)]);
    }
}

function makeConfig(string $host, string $name, string $user, string $pass): string
{
    $h = var_export($host, true);
    $n = var_export($name, true);
    $u = var_export($user, true);
    $p = var_export($pass, true);
    $d = date('d.m.Y H:i:s');
    return "<?php\n"
         . "// -------------------------------------------------------\n"
         . "// Datenbankverbindung\n"
         . "// Generiert vom Installer am {$d}\n"
         . "// -------------------------------------------------------\n"
         . "define('DB_HOST',    {$h});\n"
         . "define('DB_NAME',    {$n});\n"
         . "define('DB_USER',    {$u});\n"
         . "define('DB_PASS',    {$p});\n"
         . "define('DB_CHARSET', 'utf8mb4');\n";
}

// Firmendaten-Felder (Gruppe 1)
const FIRMA_FIELDS = [
    'invoice_company'        => ['label' => 'Firmenname',          'type' => 'text',  'width' => 'full'],
    'invoice_street'         => ['label' => 'Straße & Hausnummer', 'type' => 'text',  'width' => 'full'],
    'invoice_zip'            => ['label' => 'PLZ',                 'type' => 'text',  'width' => 'half'],
    'invoice_city'           => ['label' => 'Ort',                 'type' => 'text',  'width' => 'half'],
    'invoice_email'          => ['label' => 'E-Mail',              'type' => 'email', 'width' => 'full'],
    'invoice_phone'          => ['label' => 'Telefon',             'type' => 'text',  'width' => 'full'],
    'invoice_tax_id'         => ['label' => 'USt-IdNr.',           'type' => 'text',  'width' => 'half'],
    'invoice_tax_number'     => ['label' => 'Steuernummer',        'type' => 'text',  'width' => 'half'],
    'invoice_bank'           => ['label' => 'Bank',                'type' => 'text',  'width' => 'full'],
    'invoice_account_holder' => ['label' => 'Kontoinhaber',        'type' => 'text',  'width' => 'full'],
    'invoice_iban'           => ['label' => 'IBAN',                'type' => 'text',  'width' => 'full'],
    'invoice_bic'            => ['label' => 'BIC',                 'type' => 'text',  'width' => 'half'],
];

// ---- Step-Verarbeitung ----------------------------------------

$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

// AJAX: Datenbankverbindung testen
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    header('Content-Type: application/json; charset=utf-8');
    $h = trim($_POST['db_host'] ?? '');
    $n = trim($_POST['db_name'] ?? '');
    $u = trim($_POST['db_user'] ?? '');
    $p = $_POST['db_pass'] ?? '';
    if ($n === '' || $u === '') {
        echo json_encode(['ok' => false, 'msg' => 'Datenbankname und Benutzer sind Pflichtfelder.']);
        exit;
    }
    try {
        new PDO("mysql:host={$h};dbname={$n};charset=utf8mb4", $u, $p,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo json_encode(['ok' => true, 'msg' => 'Verbindung erfolgreich!']);
    } catch (PDOException $ex) {
        echo json_encode(['ok' => false, 'msg' => $ex->getMessage()]);
    }
    exit;
}

if ($posted) {
    $step = (int)($_POST['step'] ?? 1);

    if ($step === 2) {
        // Requirements bestätigt → DB-Formular anzeigen

    } elseif ($step === 3) {
        // DB-Formular abgeschickt → Verbindung testen
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';

        if ($dbName === '') $errors[] = 'Datenbankname darf nicht leer sein.';
        if ($dbUser === '') $errors[] = 'Datenbankbenutzer darf nicht leer sein.';

        if (empty($errors)) {
            try {
                $testPdo = new PDO(
                    "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                    $dbUser, $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                unset($testPdo);
                $_SESSION['inst_db'] = [
                    'host' => $dbHost, 'name' => $dbName,
                    'user' => $dbUser, 'pass' => $dbPass,
                ];
            } catch (PDOException $ex) {
                $errors[] = 'Datenbankverbindung fehlgeschlagen: ' . $ex->getMessage();
                $step = 2;
            }
        } else {
            $step = 2;
        }

    } elseif ($step === 4) {
        // Admin-Formular abgeschickt → Tabellen anlegen + Admin erstellen
        if (empty($_SESSION['inst_db'])) {
            $step    = 2;
            $errors[] = 'Sitzung abgelaufen – bitte Datenbankdaten erneut eingeben.';
        } else {
            $adminUser  = trim($_POST['admin_user']  ?? '');
            $adminEmail = trim($_POST['admin_email'] ?? '');
            $adminPass1 = $_POST['admin_pass1'] ?? '';
            $adminPass2 = $_POST['admin_pass2'] ?? '';

            if ($adminUser === '')
                $errors[] = 'Benutzername fehlt.';
            elseif (strlen($adminUser) > 50)
                $errors[] = 'Benutzername zu lang (max. 50 Zeichen).';
            elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $adminUser))
                $errors[] = 'Benutzername darf nur Buchstaben, Zahlen und _ enthalten.';

            if (strlen($adminPass1) < 8)
                $errors[] = 'Passwort muss mindestens 8 Zeichen haben.';
            if ($adminPass1 !== $adminPass2)
                $errors[] = 'Passwörter stimmen nicht überein.';
            if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL))
                $errors[] = 'Ungültige E-Mail-Adresse.';

            if (empty($errors)) {
                try {
                    $db  = $_SESSION['inst_db'];
                    $pdo = new PDO(
                        "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                        $db['user'], $db['pass'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );

                    foreach (tableSql() as $sql) { $pdo->exec($sql); }
                    $pdo->exec(defaultConfigSql());
                    runInstallerMigrations($pdo);

                    $hash = password_hash($adminPass1, PASSWORD_DEFAULT);
                    $pdo->prepare(
                        'INSERT INTO tm_users (username, email, password, role) VALUES (?, ?, ?, ?)'
                    )->execute([$adminUser, $adminEmail ?: null, $hash, 'admin']);

                    file_put_contents(CONFIG_FILE,
                        makeConfig($db['host'], $db['name'], $db['user'], $db['pass']));

                    // Kein Lock noch – erst nach Firmendaten-Schritt
                    $step = 4; // → Firmendaten anzeigen
                } catch (Throwable $ex) {
                    $errors[] = 'Installation fehlgeschlagen: ' . $ex->getMessage();
                    $step = 3;
                }
            } else {
                $step = 3;
            }
        }

    } elseif ($step === 5) {
        // Firmendaten speichern → Lock schreiben → Fertig
        if (empty($_SESSION['inst_db'])) {
            $step    = 4;
            $errors[] = 'Sitzung abgelaufen – bitte erneut versuchen.';
        } else {
            try {
                $db  = $_SESSION['inst_db'];
                $pdo = new PDO(
                    "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                    $db['user'], $db['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                $stmt = $pdo->prepare(
                    'INSERT INTO tm_configuration
                        (configuration_key, configuration_value, configuration_group_id, sort_order)
                     VALUES (?, ?, 1, 0)
                     ON DUPLICATE KEY UPDATE
                        configuration_value = VALUES(configuration_value),
                        last_modified       = NOW()'
                );
                foreach (array_keys(FIRMA_FIELDS) as $key) {
                    $stmt->execute([$key, trim($_POST[$key] ?? '')]);
                }

                file_put_contents(LOCK_FILE, date('Y-m-d H:i:s'));
                unset($_SESSION['inst_db']);
                $step = 6; // Fertig!
            } catch (Throwable $ex) {
                $errors[] = 'Speichern fehlgeschlagen: ' . $ex->getMessage();
                $step = 4;
            }
        }
    }
}

// ---- Formularvorbelegung ------------------------------------
$fDbHost     = e($_POST['db_host']    ?? $_SESSION['inst_db']['host'] ?? 'localhost');
$fDbName     = e($_POST['db_name']    ?? $_SESSION['inst_db']['name'] ?? '');
$fDbUser     = e($_POST['db_user']    ?? $_SESSION['inst_db']['user'] ?? '');
$fAdminUser  = e($_POST['admin_user']  ?? '');
$fAdminEmail = e($_POST['admin_email'] ?? '');

$fFirma = [];
foreach (array_keys(FIRMA_FIELDS) as $key) {
    $fFirma[$key] = e($_POST[$key] ?? '');
}

$reqs   = checkReqs();
$reqsOk = allOk($reqs);
$tables = array_keys(tableSql());

// Step-Indikator: 1 Voraussetzungen, 2 Datenbank, 3 Admin-Konto, 4 Firmendaten, 6 Fertig
$stepMap = [
    1 => 'Voraussetzungen',
    2 => 'Datenbank',
    3 => 'Admin-Konto',
    4 => 'Firmendaten',
    6 => 'Fertig',
];
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Time Manager – Installation</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
       font-size: 14px; background: #f0f2f5; color: #222; min-height: 100vh;
       display: flex; flex-direction: column; align-items: center; padding: 40px 16px; }
.card { background: #fff; border-radius: 8px; box-shadow: 0 2px 16px rgba(0,0,0,.12);
        width: 100%; max-width: 620px; overflow: hidden; }
.card-header { background: #1e293b; color: #fff; padding: 28px 32px; }
.card-header h1 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.card-header p  { font-size: 13px; color: #94a3b8; }
.steps { display: flex; border-bottom: 1px solid #e5e7eb; padding: 0 32px; overflow-x: auto; }
.step { padding: 14px 12px 14px 0; margin-right: 20px; font-size: 12px; font-weight: 600;
        color: #9ca3af; border-bottom: 2px solid transparent; white-space: nowrap; }
.step.active  { color: #2563eb; border-color: #2563eb; }
.step.done    { color: #16a34a; }
.step .num    { display: inline-flex; align-items: center; justify-content: center;
                width: 20px; height: 20px; border-radius: 50%;
                background: #e5e7eb; color: #6b7280; font-size: 11px; margin-right: 6px; }
.step.active .num { background: #2563eb; color: #fff; }
.step.done   .num { background: #16a34a; color: #fff; }
.card-body { padding: 32px; }
.section-title { font-size: 16px; font-weight: 700; margin-bottom: 20px; }
.req-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
.req-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.req-table td:first-child { color: #374151; }
.req-table td:nth-child(2) { color: #6b7280; font-size: 12px; }
.req-table td:last-child { text-align: right; font-weight: 600; width: 60px; }
.ok   { color: #16a34a; }
.fail { color: #dc2626; }
.badge-ok   { background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 12px; font-size: 11px; }
.badge-fail { background: #fee2e2; color: #b91c1c; padding: 2px 8px; border-radius: 12px; font-size: 11px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 600; color: #374151;
                    margin-bottom: 5px; }
.form-group input { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db;
                    border-radius: 6px; font-size: 13px; color: #111;
                    transition: border-color .15s; outline: none; }
.form-group input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.form-hint { font-size: 11px; color: #6b7280; margin-top: 3px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-row-full { display: grid; grid-template-columns: 1fr; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px;
       border: none; border-radius: 6px; font-size: 13px; font-weight: 600;
       cursor: pointer; text-decoration: none; transition: background .15s; }
.btn-primary { background: #2563eb; color: #fff; }
.btn-primary:hover { background: #1d4ed8; }
.btn-primary:disabled { background: #93c5fd; cursor: not-allowed; }
.btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
.btn-secondary:hover { background: #e5e7eb; }
.btn-success { background: #16a34a; color: #fff; font-size: 15px; padding: 12px 28px; }
.btn-success:hover { background: #15803d; }
.btn-row { display: flex; gap: 10px; align-items: center; margin-top: 24px; }
.alert { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; }
.alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
.alert-info  { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; }
.alert ul { margin: 6px 0 0 18px; }
.db-info { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px;
           padding: 14px 16px; margin-bottom: 20px; font-size: 13px; }
.db-info strong { color: #15803d; }
.db-info table { margin-top: 8px; }
.db-info td { padding: 2px 8px 2px 0; }
.db-info td:first-child { color: #6b7280; font-size: 12px; min-width: 120px; }
.table-list { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0 20px; }
.table-tag { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px;
             padding: 3px 9px; font-size: 11px; font-family: monospace; color: #475569; }
.divider { border: none; border-top: 1px solid #f1f5f9; margin: 24px 0; }
.done-icon { text-align: center; margin-bottom: 24px; }
.done-icon svg { color: #16a34a; }
.done-title { text-align: center; font-size: 22px; font-weight: 700; margin-bottom: 8px; }
.done-sub   { text-align: center; color: #6b7280; font-size: 13px; margin-bottom: 28px; }
.done-list  { background: #f8fafc; border-radius: 6px; padding: 16px 20px; margin-bottom: 24px; }
.done-list li { font-size: 13px; color: #374151; margin-bottom: 6px; padding-left: 4px; }
.done-list li::marker { color: #2563eb; }
.done-list li:last-child { margin-bottom: 0; }
#testResult { font-size: 12px; padding: 6px 12px; border-radius: 4px; display: none; }
#testResult.ok   { background: #dcfce7; color: #15803d; display: inline-block; }
#testResult.fail { background: #fee2e2; color: #b91c1c; display: inline-block; }
.skip-hint { font-size: 12px; color: #6b7280; margin-top: 8px; }
</style>
</head>
<body>

<div class="card">

    <div class="card-header">
        <h1>Time Manager – Installation</h1>
        <p>Willkommen beim Installations-Assistenten</p>
    </div>

    <!-- Step-Indikator -->
    <div class="steps">
        <?php
        $displayNum = 0;
        foreach ($stepMap as $num => $label):
            $displayNum++;
            $cls = '';
            if ($num < $step)  $cls = 'done';
            if ($num === $step) $cls = 'active';
        ?>
        <div class="step <?= $cls ?>">
            <span class="num">
                <?php if ($num < $step): ?>&#10003;<?php else: ?><?= $displayNum ?><?php endif; ?>
            </span>
            <?= $label ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card-body">

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <strong>Fehler:</strong>
        <?php if (count($errors) === 1): ?>
            <?= e($errors[0]) ?>
        <?php else: ?>
            <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         SCHRITT 1: Voraussetzungen
    ============================================================ -->
    <?php if ($step === 1): ?>

    <p class="section-title">Systemvoraussetzungen</p>

    <table class="req-table">
        <?php foreach ($reqs as [$label, $value, $ok]): ?>
        <tr>
            <td><?= e($label) ?></td>
            <td><?= e((string)$value) ?></td>
            <td><span class="<?= $ok ? 'badge-ok' : 'badge-fail' ?>"><?= $ok ? '✓ OK' : '✗ Fehler' ?></span></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <?php if (!$reqsOk): ?>
    <div class="alert alert-error">
        Nicht alle Voraussetzungen sind erfüllt. Bitte beheben Sie die markierten Probleme und laden Sie die Seite neu.
    </div>
    <?php else: ?>
    <div class="alert alert-info">
        Alle Voraussetzungen sind erfüllt. Sie können mit der Installation fortfahren.
    </div>
    <form method="post">
        <input type="hidden" name="step" value="2">
        <button type="submit" class="btn btn-primary">Weiter zur Datenbankkonfiguration →</button>
    </form>
    <?php endif; ?>

    <!-- ============================================================
         SCHRITT 2: Datenbank
    ============================================================ -->
    <?php elseif ($step === 2): ?>

    <p class="section-title">Datenbankverbindung konfigurieren</p>

    <form method="post" id="dbForm">
        <input type="hidden" name="step" value="3">

        <div class="form-group">
            <label for="db_host">Datenbankserver (Host)</label>
            <input type="text" id="db_host" name="db_host" value="<?= $fDbHost ?>"
                   placeholder="localhost" autocomplete="off">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="db_name">Datenbankname *</label>
                <input type="text" id="db_name" name="db_name" value="<?= $fDbName ?>"
                       placeholder="time_manager" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="db_user">Datenbankbenutzer *</label>
                <input type="text" id="db_user" name="db_user" value="<?= $fDbUser ?>"
                       placeholder="db_user" required autocomplete="off">
            </div>
        </div>

        <div class="form-group">
            <label for="db_pass">Datenbankpasswort</label>
            <input type="password" id="db_pass" name="db_pass" placeholder="(leer lassen wenn kein Passwort)"
                   autocomplete="new-password">
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Verbindung prüfen &amp; weiter →</button>
            <button type="button" class="btn btn-secondary" id="testBtn">Verbindung testen</button>
            <span id="testResult"></span>
        </div>
    </form>

    <script>
    document.getElementById('testBtn').addEventListener('click', async function() {
        const btn = this;
        const res = document.getElementById('testResult');
        btn.disabled = true;
        res.className = '';
        res.style.display = 'none';
        res.textContent = 'Teste…';
        res.style.display = 'inline-block';

        const body = new URLSearchParams({
            db_host: document.getElementById('db_host').value,
            db_name: document.getElementById('db_name').value,
            db_user: document.getElementById('db_user').value,
            db_pass: document.getElementById('db_pass').value,
        });

        try {
            const r = await fetch('?action=test_db', { method: 'POST', body });
            const d = await r.json();
            res.textContent = d.msg;
            res.className = d.ok ? 'ok' : 'fail';
        } catch(e) {
            res.textContent = 'Verbindungsfehler.';
            res.className = 'fail';
        }
        btn.disabled = false;
    });
    </script>

    <!-- ============================================================
         SCHRITT 3: Admin-Konto anlegen
    ============================================================ -->
    <?php elseif ($step === 3): ?>

    <?php $db = $_SESSION['inst_db'] ?? []; ?>

    <div class="db-info">
        <strong>✓ Datenbankverbindung erfolgreich</strong>
        <table>
            <tr><td>Host:</td><td><?= e($db['host'] ?? '') ?></td></tr>
            <tr><td>Datenbank:</td><td><?= e($db['name'] ?? '') ?></td></tr>
            <tr><td>Benutzer:</td><td><?= e($db['user'] ?? '') ?></td></tr>
        </table>
    </div>

    <p style="font-size:13px;color:#374151;margin-bottom:12px">
        Folgende Datenbanktabellen werden beim Klick auf <strong>Weiter</strong> angelegt:
    </p>
    <div class="table-list">
        <?php foreach ($tables as $t): ?>
        <span class="table-tag"><?= e($t) ?></span>
        <?php endforeach; ?>
    </div>

    <hr class="divider">

    <p class="section-title">Administrator-Konto erstellen</p>

    <form method="post">
        <input type="hidden" name="step" value="4">

        <div class="form-row">
            <div class="form-group">
                <label for="admin_user">Benutzername *</label>
                <input type="text" id="admin_user" name="admin_user" value="<?= $fAdminUser ?>"
                       placeholder="admin" required autocomplete="off"
                       pattern="[a-zA-Z0-9_]+" title="Nur Buchstaben, Zahlen und _">
            </div>
            <div class="form-group">
                <label for="admin_email">E-Mail (optional)</label>
                <input type="email" id="admin_email" name="admin_email" value="<?= $fAdminEmail ?>"
                       placeholder="admin@ihre-domain.de" autocomplete="off">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="admin_pass1">Passwort * (min. 8 Zeichen)</label>
                <input type="password" id="admin_pass1" name="admin_pass1" required
                       minlength="8" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="admin_pass2">Passwort bestätigen *</label>
                <input type="password" id="admin_pass2" name="admin_pass2" required
                       minlength="8" autocomplete="new-password">
            </div>
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Weiter →</button>
            <a href="?step=2" class="btn btn-secondary">← Zurück</a>
        </div>
    </form>

    <!-- ============================================================
         SCHRITT 4: Firmendaten
    ============================================================ -->
    <?php elseif ($step === 4): ?>

    <p class="section-title">Rechnungsabsender (eigene Firma)</p>
    <p style="font-size:13px;color:#6b7280;margin-bottom:20px">
        Diese Angaben erscheinen auf Ihren Rechnungen. Sie können alle Felder auch später
        unter <strong>Administration → Konfiguration</strong> ändern.
    </p>

    <form method="post">
        <input type="hidden" name="step" value="5">

        <?php
        $keys = array_keys(FIRMA_FIELDS);
        $i = 0;
        while ($i < count($keys)):
            $key  = $keys[$i];
            $meta = FIRMA_FIELDS[$key];
            if ($meta['width'] === 'half' && isset($keys[$i + 1]) && FIRMA_FIELDS[$keys[$i + 1]]['width'] === 'half'):
                $key2  = $keys[$i + 1];
                $meta2 = FIRMA_FIELDS[$key2];
        ?>
        <div class="form-row">
            <div class="form-group">
                <label for="<?= $key ?>"><?= e($meta['label']) ?></label>
                <input type="<?= e($meta['type']) ?>" id="<?= $key ?>" name="<?= $key ?>"
                       value="<?= $fFirma[$key] ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="<?= $key2 ?>"><?= e($meta2['label']) ?></label>
                <input type="<?= e($meta2['type']) ?>" id="<?= $key2 ?>" name="<?= $key2 ?>"
                       value="<?= $fFirma[$key2] ?>" autocomplete="off">
            </div>
        </div>
        <?php
                $i += 2;
            else:
        ?>
        <div class="form-group">
            <label for="<?= $key ?>"><?= e($meta['label']) ?></label>
            <input type="<?= e($meta['type']) ?>" id="<?= $key ?>" name="<?= $key ?>"
                   value="<?= $fFirma[$key] ?>" autocomplete="off">
        </div>
        <?php
                $i++;
            endif;
        endwhile;
        ?>

        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Weiter →</button>
        </div>
        <p class="skip-hint">Alle Felder sind optional – Sie können diese Angaben jederzeit in der Administration nachholen.</p>
    </form>

    <!-- ============================================================
         SCHRITT 6: Fertig
    ============================================================ -->
    <?php elseif ($step === 6): ?>

    <div class="done-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <path d="M8 12l3 3 5-6"/>
        </svg>
    </div>
    <p class="done-title">Installation abgeschlossen!</p>
    <p class="done-sub">Time Manager wurde erfolgreich eingerichtet.</p>

    <ul class="done-list">
        <li>Alle Datenbanktabellen wurden angelegt</li>
        <li>Die Standardkonfiguration wurde eingetragen</li>
        <li>Ihr Administrator-Konto wurde erstellt</li>
        <li>Ihre Firmendaten wurden gespeichert</li>
        <li>Die Datei <code>config.php</code> wurde geschrieben</li>
    </ul>

    <div class="alert alert-info" style="margin-bottom:24px">
        <strong>Sicherheitshinweis:</strong> Löschen oder schützen Sie das Verzeichnis
        <code>_installer/</code> nach der Installation, damit Dritte den Installer nicht erneut aufrufen können.
    </div>

    <div style="text-align:center">
        <a href="../index.php" class="btn btn-success">Zum Login →</a>
    </div>

    <?php endif; ?>

    </div><!-- .card-body -->
</div><!-- .card -->

</body>
</html>
