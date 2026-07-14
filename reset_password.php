<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/i18n.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$token   = trim($_REQUEST['token'] ?? '');
$error   = '';
$success = false;

// Sprache des Benutzers über das Token ermitteln (Fallback: globaler Default)
$resetLang = null;
if ($token !== '') {
    $ls = db()->prepare(
        'SELECT u.lang FROM tm_password_resets r
         JOIN tm_users u ON u.id = r.user_id
         WHERE r.token = ? LIMIT 1'
    );
    $ls->execute([$token]);
    $resetLang = $ls->fetchColumn() ?: null;
}
i18nInit(i18nResolve($resetLang, null, cfg('default_lang', 'de')));

/* ------------------------------------------------------------------
   POST – neues Passwort speichern
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $error = t('resetpw.tooShort');
    } elseif ($password !== $confirm) {
        $error = t('resetpw.mismatch');
    } else {
        $stmt = db()->prepare('
            SELECT user_id
            FROM   tm_password_resets
            WHERE  token = ? AND expires_at > NOW()
            LIMIT 1
        ');
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $error = t('resetpw.invalidExpired');
            $token = '';
        } else {
            $pdo  = db();
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('UPDATE tm_users SET password = ? WHERE id = ?');
            $stmt->execute([$hash, $reset['user_id']]);

            $stmt = $pdo->prepare('DELETE FROM tm_password_resets WHERE token = ?');
            $stmt->execute([$token]);

            $success = true;
        }
    }

/* ------------------------------------------------------------------
   GET – Token vorab prüfen
------------------------------------------------------------------ */
} elseif ($token !== '') {
    $stmt = db()->prepare('
        SELECT token FROM tm_password_resets WHERE token = ? AND expires_at > NOW()
    ');
    $stmt->execute([$token]);
    if (!$stmt->fetch()) {
        $error = t('resetpw.invalidExpired2');
        $token = '';
    }
} else {
    $error = t('resetpw.noLink');
}
?>
<!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(t('resetpw.pageTitle')) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="login-overlay">
    <div class="login-card">
        <h1><?= h(t('resetpw.heading')) ?></h1>

        <?php if ($success): ?>
            <p class="success-message">
                <?= h(t('resetpw.success')) ?>
            </p>
            <a href="index.php"
               class="btn btn--primary"
               style="display:block;text-align:center;margin-top:14px;">
                <?= h(t('resetpw.toLogin')) ?>
            </a>

        <?php elseif ($error !== '' && $token === ''): ?>
            <p class="login-error"><?= h($error) ?></p>
            <a href="index.php"
               class="btn"
               style="display:block;text-align:center;margin-top:10px;">
                <?= h(t('resetpw.backToLoginPlain')) ?>
            </a>

        <?php else: ?>
            <?php if ($error !== ''): ?>
                <p class="login-error"><?= h($error) ?></p>
            <?php endif; ?>
            <form method="post" novalidate>
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <input type="password"
                       name="password"
                       placeholder="<?= h(t('resetpw.newPassword')) ?>"
                       autocomplete="new-password"
                       required>
                <input type="password"
                       name="password_confirm"
                       placeholder="<?= h(t('resetpw.confirmPassword')) ?>"
                       autocomplete="new-password"
                       required>
                <button type="submit" class="btn btn--primary">
                    <?= h(t('resetpw.save')) ?>
                </button>
            </form>
            <div class="login-switch">
                <a href="index.php"><?= h(t('resetpw.backToLogin')) ?></a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
