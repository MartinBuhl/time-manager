<?php
require_once __DIR__ . '/includes/error_log.php';
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

/**
 * Normalisiert eine Bookmark-URL: fehlt das Schema, wird https:// vorangestellt;
 * andere Schemata (javascript:, data: …) werden abgelehnt. Beendet den Request
 * mit jsonErr bei ungültiger Eingabe.
 */
function bmNormalizeUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') jsonErr('Bitte eine URL angeben.');
    if (!preg_match('~^https?://~i', $url)) {
        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $url)) {
            jsonErr('Nur http(s)-Links sind erlaubt.');
        }
        $url = 'https://' . ltrim($url, '/');
    }
    if (mb_strlen($url) > 2000) jsonErr('Die URL ist zu lang.');
    return $url;
}

function timePattern(): string
{
    return '/^\d{2}:\d{2}:\d{2}$/';
}

function datetimePattern(): string
{
    return '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
}

require_once __DIR__ . '/includes/orders.php';

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

        // Beim Login fällige Zahlungserinnerungen prüfen/versenden (max. 1×/Tag).
        require_once __DIR__ . '/includes/payments.php';
        paymentMaybeRunRemindersOnLogin();

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
            'SELECT id, lang FROM tm_users WHERE email = ? LIMIT 1'
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
            $link    = rtrim(cfg('site_url'), '/') . '/reset_password.php?token=' . $token;
            $usrLang = $user['lang'] ?? null;
            if ($usrLang === null || $usrLang === '') {
                $usrLang = cfg('default_lang', 'de');
            }
            sendPasswordResetMail($email, $link, $usrLang);
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
    case 'create_order':
        requireAuth();
        verifyCsrf();

        $customerId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$customerId) jsonErr('Bitte einen Kunden wählen.');

        $body = trim($_POST['body'] ?? '');
        $body = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $body);

        if ($body === '' && empty($_FILES['files']['name'][0])) {
            jsonErr('Bitte Text eingeben oder eine Datei anhängen.');
        }

        $pdo = db();
        $pdo->prepare('INSERT INTO tm_orders (user_id, customer_id, body) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], $customerId, $body]);
        $orderId = (int) $pdo->lastInsertId();

        // War dies der erste offene Auftrag des Kunden (Kunde neu in der Liste),
        // Sortierung initialisieren -> Kunde unten einreihen. Hatte er bereits
        // offene Aufträge, bleibt die Position (last_worked_at) unverändert.
        $cnt = $pdo->prepare(
            "SELECT COUNT(*) FROM tm_orders
             WHERE customer_id = ? AND status = 'offen' AND deleted_at IS NULL AND id <> ?"
        );
        $cnt->execute([$customerId, $orderId]);
        if ((int) $cnt->fetchColumn() === 0) {
            $pdo->prepare('UPDATE tm_customers SET last_worked_at = NOW(6) WHERE id = ?')
                ->execute([$customerId]);
        }

        $err = saveOrderFiles($orderId);
        if ($err !== '') jsonErr($err);

        jsonOk(['id' => $orderId]);

    // ----------------------------------------------------------------
    case 'get_order':
        requireAuth();
        verifyCsrf();

        $orderId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$orderId) jsonErr('Ungültige Auftrags-ID.');

        $stmt = db()->prepare(
            'SELECT o.id, o.customer_id, o.body, o.status, o.created_at,
                    COALESCE(c.name, \'\') AS customer_name
             FROM tm_orders o
             LEFT JOIN tm_customers c ON c.id = o.customer_id
             WHERE o.id = ? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) jsonErr('Auftrag nicht gefunden.', 404);

        $fstmt = db()->prepare(
            'SELECT id, original_name FROM tm_order_files WHERE order_id = ? ORDER BY id'
        );
        $fstmt->execute([$orderId]);
        $order['files'] = $fstmt->fetchAll();

        jsonOk($order);

    // ----------------------------------------------------------------
    case 'update_order':
        requireAuth();
        verifyCsrf();

        $orderId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$orderId) jsonErr('Ungültige Auftrags-ID.');

        $exists = db()->prepare('SELECT id FROM tm_orders WHERE id = ?');
        $exists->execute([$orderId]);
        if (!$exists->fetchColumn()) jsonErr('Auftrag nicht gefunden.', 404);

        $body = trim($_POST['body'] ?? '');
        $body = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $body);
        db()->prepare('UPDATE tm_orders SET body = ? WHERE id = ?')->execute([$body, $orderId]);

        $err = saveOrderFiles($orderId);
        if ($err !== '') jsonErr($err);

        jsonOk();

    // ----------------------------------------------------------------
    case 'complete_order':
        requireAuth();
        verifyCsrf();

        $orderId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$orderId) jsonErr('Ungültige Auftrags-ID.');

        db()->prepare("UPDATE tm_orders SET status = 'erledigt', completed_at = NOW() WHERE id = ?")
            ->execute([$orderId]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'mark_order_worked':
        requireAuth();
        verifyCsrf();

        $orderId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$orderId) jsonErr('Ungültige Auftrags-ID.');

        // Bearbeitungszeitpunkt am Kunden setzen -> Kunde wandert in der
        // Auftragsliste nach unten (Sortierung nach "zuletzt bearbeitet").
        $cstmt = db()->prepare('SELECT customer_id FROM tm_orders WHERE id = ?');
        $cstmt->execute([$orderId]);
        $custId = $cstmt->fetchColumn();
        if ($custId !== false) {
            db()->prepare('UPDATE tm_customers SET last_worked_at = NOW(6) WHERE id = ?')
                ->execute([$custId]);
        }
        jsonOk();

    // ----------------------------------------------------------------
    case 'reorder_orders':
        requireAuth();
        verifyCsrf();

        // Drag&Drop: der verschobene Kunde bekommt ein "zuletzt bearbeitet"-
        // Datum zwischen seinen neuen Nachbarn, damit er nach Reload genau dort
        // einsortiert wird. Sortierschlüssel = COALESCE(last_worked_at, created_at).
        $pdo  = db();
        $id   = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $prev = filter_var($_POST['prev'] ?? '', FILTER_VALIDATE_INT);
        $next = filter_var($_POST['next'] ?? '', FILTER_VALIDATE_INT);
        if (!$id) jsonErr('Ungültige Auftrags-ID.');

        $cstmt = $pdo->prepare('SELECT customer_id FROM tm_orders WHERE id = ?');
        $cstmt->execute([$id]);
        $cid = $cstmt->fetchColumn();
        if ($cid === false) jsonErr('Auftrag nicht gefunden.');

        // Effektiver Sortierschlüssel eines Nachbar-Auftrags.
        $keyStmt = $pdo->prepare(
            'SELECT COALESCE(c.last_worked_at, o.created_at)
             FROM tm_orders o LEFT JOIN tm_customers c ON c.id = o.customer_id
             WHERE o.id = ?'
        );
        $keyOf = function ($orderId) use ($keyStmt) {
            if (!$orderId) return null;
            $keyStmt->execute([$orderId]);
            $v = $keyStmt->fetchColumn();
            return $v === false ? null : $v;
        };
        $prevKey = $keyOf($prev);
        $nextKey = $keyOf($next);

        if ($prevKey !== null && $nextKey !== null) {
            // Mitte zwischen beiden Nachbarn (Mikrosekunden-genau).
            $pdo->prepare(
                'UPDATE tm_customers
                 SET last_worked_at = FROM_UNIXTIME((UNIX_TIMESTAMP(?) + UNIX_TIMESTAMP(?)) / 2)
                 WHERE id = ?'
            )->execute([$prevKey, $nextKey, $cid]);
        } elseif ($nextKey !== null) {
            // Ganz oben: knapp vor den obersten Nachbarn.
            $pdo->prepare(
                'UPDATE tm_customers SET last_worked_at = FROM_UNIXTIME(UNIX_TIMESTAMP(?) - 1) WHERE id = ?'
            )->execute([$nextKey, $cid]);
        } elseif ($prevKey !== null) {
            // Ganz unten: als zuletzt bearbeitet.
            $pdo->prepare('UPDATE tm_customers SET last_worked_at = NOW(6) WHERE id = ?')
                ->execute([$cid]);
        }
        jsonOk();

    // ----------------------------------------------------------------
    case 'save_info_text':
        requireAuth();
        verifyCsrf();

        $text = (string)($_POST['text'] ?? '');
        if (mb_strlen($text) > 60000) jsonErr('Infotext ist zu lang.');
        db()->prepare(
            "UPDATE tm_configuration SET configuration_value = ?
             WHERE configuration_key = 'app_info_text'"
        )->execute([$text]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'add_bookmark':
        requireAuth();
        verifyCsrf();

        $parentRaw = trim($_POST['parent_id'] ?? '');
        $parentId  = ($parentRaw === '') ? null : (int)$parentRaw;
        $url       = trim($_POST['url'] ?? '');
        $title     = trim($_POST['title'] ?? '');

        $url = bmNormalizeUrl($url);
        if ($title === '') $title = $url;
        $title = mb_substr($title, 0, 500);

        $pdo = db();
        // Parent muss ein existierender Ordner sein (oder NULL = oberste Ebene).
        if ($parentId !== null) {
            $chk = $pdo->prepare("SELECT type FROM tm_bookmarks WHERE id = ?");
            $chk->execute([$parentId]);
            if ($chk->fetchColumn() !== 'folder') jsonErr('Ordner nicht gefunden.');
        }

        if ($parentId === null) {
            $sort = (int)$pdo->query(
                "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tm_bookmarks WHERE parent_id IS NULL"
            )->fetchColumn();
        } else {
            $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tm_bookmarks WHERE parent_id = ?");
            $s->execute([$parentId]);
            $sort = (int)$s->fetchColumn();
        }

        $pdo->prepare(
            "INSERT INTO tm_bookmarks (parent_id, type, title, url, sort_order)
             VALUES (?, 'link', ?, ?, ?)"
        )->execute([$parentId, $title, $url, $sort]);

        jsonOk(['id' => (int)$pdo->lastInsertId(), 'title' => $title, 'url' => $url]);

    // ----------------------------------------------------------------
    case 'add_bookmark_folder':
        requireAuth();
        verifyCsrf();

        $title = trim($_POST['title'] ?? '');
        if ($title === '') jsonErr('Bitte einen Ordnernamen angeben.');
        $title = mb_substr($title, 0, 500);

        $pdo  = db();
        $sort = (int)$pdo->query(
            "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tm_bookmarks WHERE parent_id IS NULL"
        )->fetchColumn();
        $pdo->prepare(
            "INSERT INTO tm_bookmarks (parent_id, type, title, url, sort_order)
             VALUES (NULL, 'folder', ?, NULL, ?)"
        )->execute([$title, $sort]);

        jsonOk(['id' => (int)$pdo->lastInsertId(), 'title' => $title]);

    // ----------------------------------------------------------------
    case 'reset_order_worked':
        requireAuth();
        verifyCsrf();

        $custId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$custId) jsonErr('Ungültige Kunden-ID.');

        // "Bearbeitet"-Markierung des Kunden zurücknehmen -> Kunde wandert
        // wieder nach oben (sortiert dann nach ältestem offenem Auftrag).
        db()->prepare('UPDATE tm_customers SET last_worked_at = NULL WHERE id = ?')
            ->execute([$custId]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'delete_order':
        requireAuth();
        verifyCsrf();

        $orderId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$orderId) jsonErr('Ungültige Auftrags-ID.');

        db()->prepare('UPDATE tm_orders SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL')
            ->execute([$orderId]);
        jsonOk();

    // ----------------------------------------------------------------
    case 'delete_order_file':
        requireAuth();
        verifyCsrf();

        $fileId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$fileId) jsonErr('Ungültige Datei-ID.');

        $stmt = db()->prepare('SELECT stored_name FROM tm_order_files WHERE id = ?');
        $stmt->execute([$fileId]);
        $stored = $stmt->fetchColumn();
        if ($stored !== false) {
            $path = ordersDir() . '/' . basename((string) $stored);
            if (is_file($path)) @unlink($path);
            db()->prepare('DELETE FROM tm_order_files WHERE id = ?')->execute([$fileId]);
        }
        jsonOk();

    // ----------------------------------------------------------------
    default:
        jsonErr('Unbekannte Aktion.', 404);
}
