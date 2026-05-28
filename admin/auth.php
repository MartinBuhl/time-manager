<?php
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

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
