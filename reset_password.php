<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$token   = trim($_REQUEST['token'] ?? '');
$error   = '';
$success = false;

/* ------------------------------------------------------------------
   POST – neues Passwort speichern
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($password !== $confirm) {
        $error = 'Die Passwörter stimmen nicht überein.';
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
            $error = 'Der Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen an.';
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
        $error = 'Der Link ist ungültig oder bereits abgelaufen.';
        $token = '';
    }
} else {
    $error = 'Kein gültiger Link angegeben.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort zurücksetzen – Time Manager</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="login-overlay">
    <div class="login-card">
        <h1>Passwort zurücksetzen</h1>

        <?php if ($success): ?>
            <p class="success-message">
                Ihr Passwort wurde erfolgreich geändert.
            </p>
            <a href="index.php"
               class="btn btn--primary"
               style="display:block;text-align:center;margin-top:14px;">
                Zum Login
            </a>

        <?php elseif ($error !== '' && $token === ''): ?>
            <p class="login-error"><?= h($error) ?></p>
            <a href="index.php"
               class="btn"
               style="display:block;text-align:center;margin-top:10px;">
                Zurück zum Login
            </a>

        <?php else: ?>
            <?php if ($error !== ''): ?>
                <p class="login-error"><?= h($error) ?></p>
            <?php endif; ?>
            <form method="post" novalidate>
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <input type="password"
                       name="password"
                       placeholder="Neues Passwort (min. 8 Zeichen)"
                       autocomplete="new-password"
                       required>
                <input type="password"
                       name="password_confirm"
                       placeholder="Passwort bestätigen"
                       autocomplete="new-password"
                       required>
                <button type="submit" class="btn btn--primary">
                    Passwort speichern
                </button>
            </form>
            <div class="login-switch">
                <a href="index.php">← Zurück zum Login</a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
