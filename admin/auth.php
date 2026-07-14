<?php
require_once dirname(__DIR__) . '/includes/error_log.php';

if (!file_exists(dirname(__DIR__) . '/_installer/installed.lock')) {
    header('Location: ../_installer/');
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

$adminUserId = (int)($_SESSION['user_id'] ?? 0);

if (!$adminUserId) {
    header('Location: ../index.php');
    exit;
}

$stmt = db()->prepare('SELECT id, username, role FROM tm_users WHERE id = ? LIMIT 1');
$stmt->execute([$adminUserId]);
$adminUser = $stmt->fetch();

if (!$adminUser || $adminUser['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ------------------------------------------------------------------
   Sprache (i18n) – macht t() im gesamten Admin-Bereich verfügbar.
   Ändert noch keine Texte; die werden schrittweise umgestellt.
------------------------------------------------------------------ */
require_once dirname(__DIR__) . '/includes/i18n.php';

if (isset($_GET['lang']) && in_array($_GET['lang'], i18nAvailableLangs(), true)) {
    $_SESSION['lang'] = $_GET['lang'];
    try {
        db()->prepare('UPDATE tm_users SET lang = ? WHERE id = ?')
            ->execute([$_GET['lang'], $adminUserId]);
    } catch (Throwable $e) { /* Spalte evtl. noch nicht vorhanden */ }
    $to = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
    header('Location: ' . $to);
    exit;
}

$adminLang = null;
try {
    $langStmt = db()->prepare('SELECT lang FROM tm_users WHERE id = ? LIMIT 1');
    $langStmt->execute([$adminUserId]);
    $adminLang = $langStmt->fetchColumn() ?: null;
} catch (Throwable $e) { /* Spalte evtl. noch nicht vorhanden */ }

i18nInit(i18nResolve($adminLang, $_SESSION['lang'] ?? null, cfg('default_lang', 'de')));

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
