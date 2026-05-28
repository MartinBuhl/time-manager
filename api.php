<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

header('Content-Type: application/json; charset=utf-8');

function jsonOk($data = null): void
{
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function jsonErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function requireAuth(): void
{
    if (empty($_SESSION['user_id'])) {
        jsonErr('Nicht angemeldet.', 401);
    }
}

// Alle unbehandelten Exceptions als JSON zurückgeben
set_exception_handler(function (Throwable $e): void {
    error_log('TimeManager API: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonErr('Serverfehler: ' . $e->getMessage(), 500);
});

function verifyCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        jsonErr('Ungültiger Sicherheitstoken.', 403);
    }
}

function timePattern(): string
{
    return '/^\d{2}:\d{2}:\d{2}$/';
}

function datetimePattern(): string
{
    return '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
}

$action = $_POST['action'] ?? '';

switch ($action) {

    // ----------------------------------------------------------------
    case 'login':
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            jsonErr('Benutzername und Passwort erforderlich.');
        }

        $stmt = db()->prepare(
            'SELECT id, password FROM tm_users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            jsonErr('Ungültige Anmeldedaten.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        jsonOk(['csrf_token' => $_SESSION['csrf_token']]);

    // ----------------------------------------------------------------
    case 'save_start_state':
        requireAuth();
        verifyCsrf();

        $customerId   = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $customerName = trim($_POST['customer_name'] ?? '');
        $activity     = trim($_POST['activity'] ?? '');
        $project      = trim($_POST['project'] ?? '') ?: null;
        $startTime    = trim($_POST['start_time'] ?? '');

        if ($activity === '' || !preg_match(timePattern(), $startTime)) {
            jsonErr('Ungültige Eingabe.');
        }

        $stmt = db()->prepare('
            INSERT INTO tm_user_state (user_id, customer_id, customer_name, activity, project, start_time)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                customer_id   = VALUES(customer_id),
                customer_name = VALUES(customer_name),
                activity      = VALUES(activity),
                project       = VALUES(project),
                start_time    = VALUES(start_time)
        ');
        $stmt->execute([
            $_SESSION['user_id'],
            $customerId,
            $customerName ?: null,
            $activity,
            $project,
            $startTime,
        ]);

        jsonOk();

    // ----------------------------------------------------------------
    case 'clear_start_state':
        requireAuth();
        verifyCsrf();

        $stmt = db()->prepare('DELETE FROM tm_user_state WHERE user_id = ?');
        $stmt->execute([$_SESSION['user_id']]);

        jsonOk();

    // ----------------------------------------------------------------
    case 'send_work':
        requireAuth();
        verifyCsrf();

        $customerId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $activity   = trim($_POST['activity'] ?? '');
        $project    = trim($_POST['project']  ?? '') ?: null;
        $comment    = trim($_POST['comment']  ?? '') ?: null;
        $startTime  = trim($_POST['start_time'] ?? '');
        $stopTime   = trim($_POST['stop_time']  ?? '');
        $stopDate   = trim($_POST['stop_date']  ?? '');

        if ($activity === '') {
            jsonErr('Tätigkeit fehlt.');
        }
        if (
            !preg_match(timePattern(), $startTime) ||
            !preg_match(timePattern(), $stopTime)
        ) {
            jsonErr('Ungültige Zeitangabe.');
        }
        $today = preg_match('/^\d{4}-\d{2}-\d{2}$/', $stopDate) ? $stopDate : date('Y-m-d');

        $pdo = db();
        $pdo->beginTransaction();

        $billable = entryIsBillable($customerId, $activity, $comment);

        $ins = $pdo->prepare('
            INSERT INTO tm_entries
                (user_id, customer_id, activity, project, comment, date, start_datetime, end_datetime, duration_minutes, billable)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $uid = $_SESSION['user_id'];

        if ($stopTime < $startTime) {
            // Mitternachtsüberlauf: API wird nach Mitternacht aufgerufen,
            // daher ist $today bereits der neue Tag — Start lag noch gestern.
            $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
            $startDt   = $yesterday . ' ' . $startTime;
            $endDt1    = $yesterday . ' 23:59:59';
            $startDt2  = $today     . ' 00:00:00';
            $endDt2    = $today     . ' ' . $stopTime;

            $min1 = max(1, (int) round((strtotime($endDt1) - strtotime($startDt))  / 60));
            $min2 = max(1, (int) round((strtotime($endDt2) - strtotime($startDt2)) / 60));

            $ins->execute([$uid, $customerId, $activity, $project, $comment, $yesterday, $startDt,  $endDt1, $min1, $billable]);
            $ins->execute([$uid, $customerId, $activity, $project, $comment, $today,     $startDt2, $endDt2, $min2, $billable]);
        } else {
            $startDt = $today . ' ' . $startTime;
            $endDt   = $today . ' ' . $stopTime;
            $minutes = max(1, (int) round((strtotime($endDt) - strtotime($startDt)) / 60));
            $ins->execute([$uid, $customerId, $activity, $project, $comment, $today, $startDt, $endDt, $minutes, $billable]);
        }

        $pdo->prepare('DELETE FROM tm_user_state WHERE user_id = ?')->execute([$uid]);
        $pdo->commit();

        jsonOk();

    // ----------------------------------------------------------------
    case 'update_entry':
        requireAuth();
        verifyCsrf();

        $id         = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $startDt    = trim($_POST['start_datetime'] ?? '');
        $endDt      = trim($_POST['end_datetime'] ?? '');
        $comment    = trim($_POST['comment']   ?? '') ?: null;
        $project    = trim($_POST['project']   ?? '') ?: null;
        $activity   = trim($_POST['activity']  ?? '') ?: null;
        $customerId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $dateRaw    = trim($_POST['date'] ?? '');
        $date       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) ? $dateRaw : null;

        if (!$id) {
            jsonErr('Ungültige ID.');
        }
        if (
            !preg_match(datetimePattern(), $startDt) ||
            !preg_match(datetimePattern(), $endDt)
        ) {
            jsonErr('Ungültiges Datumsformat. Erwartet: YYYY-MM-DD HH:MM:SS');
        }

        $minutes = (int) round((strtotime($endDt) - strtotime($startDt)) / 60);
        if ($minutes < 1) $minutes = 1;

        // date fällt auf das Datum von start_datetime zurück wenn nicht angegeben
        if ($date === null) $date = substr($startDt, 0, 10);

        $stmt = db()->prepare('
            UPDATE tm_entries
            SET date = ?, start_datetime = ?, end_datetime = ?, duration_minutes = ?,
                comment = ?, project = ?, activity = ?, customer_id = ?
            WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([
            $date, $startDt, $endDt, $minutes, $comment, $project, $activity, $customerId,
            $id, $_SESSION['user_id'],
        ]);

        jsonOk(['duration_minutes' => $minutes]);

    // ----------------------------------------------------------------
    case 'delete_entry':
        requireAuth();
        verifyCsrf();

        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$id) {
            jsonErr('Ungültige ID.');
        }

        $stmt = db()->prepare('
            UPDATE tm_entries
            SET deleted_at = NOW()
            WHERE id = ? AND user_id = ? AND deleted_at IS NULL
        ');
        $stmt->execute([$id, $_SESSION['user_id']]);

        jsonOk();

    // ----------------------------------------------------------------
    case 'request_password_reset':
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonErr('Ungültige E-Mail-Adresse.');
        }

        $stmt = db()->prepare(
            'SELECT id FROM tm_users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token   = bin2hex(random_bytes(32)); // 64 Hex-Zeichen
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo = db();

            // Alte Token des Users löschen
            $stmt = $pdo->prepare('DELETE FROM tm_password_resets WHERE user_id = ?');
            $stmt->execute([$user['id']]);

            $stmt = $pdo->prepare(
                'INSERT INTO tm_password_resets (token, user_id, expires_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([$token, $user['id'], $expires]);

            require_once __DIR__ . '/includes/mail.php';
            $link = rtrim(cfg('site_url'), '/') . '/reset_password.php?token=' . $token;
            sendPasswordResetMail($email, $link);
        }

        // Immer OK zurückgeben – verhindert E-Mail-Enumeration
        jsonOk();

    // ----------------------------------------------------------------
    case 'get_monthly_entries':
        requireAuth();
        verifyCsrf();

        $customerId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$customerId) jsonErr('Ungültige Kunden-ID.');

        $project = trim($_POST['project'] ?? '');

        $sql = '
            SELECT e.id, e.date, e.activity, e.project, e.comment, e.duration_minutes
            FROM tm_entries e
            WHERE e.customer_id = ?
              AND e.date BETWEEN ? AND ?
              AND e.deleted_at IS NULL
        ';
        $params = [$customerId, date('Y-m-01'), date('Y-m-t')];

        if ($project !== '') {
            $sql .= ' AND e.project = ?';
            $params[] = $project;
        }
        $sql .= ' ORDER BY e.date, e.start_datetime';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        jsonOk($stmt->fetchAll());

    // ----------------------------------------------------------------
    default:
        jsonErr('Unbekannte Aktion.', 404);
}
