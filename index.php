<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/error_log.php';

if (!file_exists(__DIR__ . '/_installer/installed.lock')) {
    header('Location: _installer/');
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

/* ------------------------------------------------------------------
   Logout
------------------------------------------------------------------ */
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

/* ------------------------------------------------------------------
   Auth state
------------------------------------------------------------------ */
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$loggedIn  = $userId > 0;

if ($loggedIn && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/includes/activities.php';
require_once __DIR__ . '/includes/i18n.php';

/* ------------------------------------------------------------------
   Sprache: Umschaltung (?lang=) + Auflösung
------------------------------------------------------------------ */
if (isset($_GET['lang']) && in_array($_GET['lang'], i18nAvailableLangs(), true)) {
    $_SESSION['lang'] = $_GET['lang'];
    if ($loggedIn) {
        try {
            db()->prepare('UPDATE tm_users SET lang = ? WHERE id = ?')
                ->execute([$_GET['lang'], $userId]);
        } catch (Throwable $e) { /* Spalte evtl. noch nicht vorhanden */ }
    }
    $qDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    header('Location: index.php?date=' . $qDate);
    exit;
}

$userLang = null;
if ($loggedIn) {
    try {
        $lst = db()->prepare('SELECT lang FROM tm_users WHERE id = ? LIMIT 1');
        $lst->execute([$userId]);
        $userLang = $lst->fetchColumn() ?: null;
    } catch (Throwable $e) { /* Spalte evtl. noch nicht vorhanden */ }
}
$activeLang = i18nResolve($userLang, $_SESSION['lang'] ?? null, cfg('default_lang', 'de'));
i18nInit($activeLang);

/* ------------------------------------------------------------------
   Load data (only when logged in)
------------------------------------------------------------------ */
$monthlyStats = ['total' => 0.0, 'avg' => 0.0];
$todayEntries = [];
$processingOrders = [];
$openOrdersByCustomer = [];
$workedCustomers = [];
$activities   = ACTIVITIES;
$customers    = [];
$userState    = null;
$todayMinutes = 0;
$userRole     = '';
$today        = date('Y-m-d');
$viewDate     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;
$isToday      = $viewDate === $today;

if ($loggedIn) {
    $pdo = db();

    /* Verwaltbare Tätigkeiten (Fallback: Konstante) */
    $activities = getActivities($pdo);

    /* User role */
    $stmt = $pdo->prepare('SELECT role FROM tm_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $userRole = (string)($stmt->fetchColumn() ?: 'mitarbeiter');

    /* Monthly stats */
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(duration_minutes), 0) AS total
        FROM tm_entries
        WHERE user_id = ? AND date BETWEEN ? AND ? AND deleted_at IS NULL
    ');
    $stmt->execute([$userId, date('Y-m-01'), date('Y-m-t')]);
    $totalMin = (int) $stmt->fetchColumn();
    $days     = (int) date('j');

    $monthlyStats = [
        'total' => round($totalMin / 60, 2),
        'avg'   => $days > 0 ? round($totalMin / $days / 60, 2) : 0.0,
    ];

    /* Today's entries */
    $stmt = $pdo->prepare('
        SELECT e.id,
               e.customer_id,
               e.date,
               e.start_datetime,
               e.end_datetime,
               e.duration_minutes,
               e.activity,
               e.project,
               e.comment,
               COALESCE(c.name, \'\') AS customer_name
        FROM   tm_entries e
        LEFT JOIN tm_customers c ON c.id = e.customer_id
        WHERE  e.user_id = ? AND e.date = ? AND e.deleted_at IS NULL
        ORDER BY e.start_datetime
    ');
    $stmt->execute([$userId, $viewDate]);
    $todayEntries = $stmt->fetchAll();
    $todayMinutes = (int) array_sum(array_column($todayEntries, 'duration_minutes'));

    /* Shortcuts */
    $shortcuts = [];
    try {
        $shortcuts = $pdo->query(
            'SELECT id, customer_id, activity, shortcut_text FROM tm_shortcuts ORDER BY id'
        )->fetchAll();
    } catch (Throwable $e) { /* table not yet created */ }

    /* Customers */
    $stmt = $pdo->query('SELECT id, name, projects FROM tm_customers WHERE active = 1 ORDER BY name');
    $customers = $stmt->fetchAll();

    /* Projects per customer (für Edit-Form) */
    $projectsByCustomer = [];
    foreach ($customers as $c) {
        $p = $c['projects'] ? json_decode($c['projects'], true) : [];
        $projectsByCustomer[(int)$c['id']] = is_array($p) ? $p : [];
    }

    /* Saved tracking state */
    $stmt = $pdo->prepare('
        SELECT customer_id, customer_name, activity, project, start_time
        FROM   tm_user_state
        WHERE  user_id = ?
    ');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row && $row['activity'] !== null) {
        $userState = $row;
    }

    /* Bearbeitungsliste: je Kunde der aelteste offene Auftrag,
       insgesamt aeltester zuerst */
    $processingOrders = [];
    try {
        $processingOrders = $pdo->query("
            SELECT o.id, o.customer_id, o.created_at,
                   COALESCE(c.name, '') AS customer_name
            FROM tm_orders o
            LEFT JOIN tm_customers c ON c.id = o.customer_id
            WHERE o.status = 'offen' AND o.deleted_at IS NULL
              AND (o.last_worked_date IS NULL OR o.last_worked_date < CURDATE())
              AND o.id = (
                  SELECT o2.id FROM tm_orders o2
                  WHERE o2.customer_id = o.customer_id AND o2.status = 'offen'
                    AND o2.deleted_at IS NULL
                    AND (o2.last_worked_date IS NULL OR o2.last_worked_date < CURDATE())
                  ORDER BY o2.created_at ASC, o2.id ASC
                  LIMIT 1
              )
            ORDER BY (o.sort_order = 0) ASC, o.sort_order ASC, o.created_at ASC, o.id ASC
        ")->fetchAll();

        // Alle offenen Aufträge je Kunde (für die aufklappbare Unterliste)
        foreach ($pdo->query("
            SELECT id, customer_id, created_at, body
            FROM tm_orders
            WHERE status = 'offen' AND deleted_at IS NULL
            ORDER BY created_at ASC, id ASC
        ") as $row) {
            $openOrdersByCustomer[(int)$row['customer_id']][] = $row;
        }

        // Heute bearbeitete Kunden: alle offenen Aufträge des Kunden sind
        // heute als bearbeitet markiert (Status kann zurückgesetzt werden).
        $workedCustomers = $pdo->query("
            SELECT o.customer_id, COALESCE(c.name, '') AS customer_name
            FROM tm_orders o
            LEFT JOIN tm_customers c ON c.id = o.customer_id
            WHERE o.status = 'offen' AND o.deleted_at IS NULL
            GROUP BY o.customer_id, c.name
            HAVING SUM(o.last_worked_date IS NULL OR o.last_worked_date < CURDATE()) = 0
               AND SUM(o.last_worked_date = CURDATE()) > 0
            ORDER BY customer_name ASC, o.customer_id ASC
        ")->fetchAll();
    } catch (Throwable $e) { /* Tabelle evtl. noch nicht vorhanden */ }
}

/* ------------------------------------------------------------------
   Template helpers
------------------------------------------------------------------ */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmtTime(string $dt): string
{
    return substr($dt, 11, 5);
}

/**
 * Zerlegt den (Rich-Text-)Auftragstext in einzelne Zeilen. Block-Elemente
 * und <br> werden zu Zeilenumbrüchen, Tags entfernt, leere Zeilen verworfen.
 */
function orderBodyLines(?string $body): array
{
    $s = (string) $body;
    $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
    $s = preg_replace('#</(div|p|li|h[1-6]|tr)>#i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $s);
    $lines = array_map('trim', explode("\n", $s));
    return array_values(array_filter($lines, static fn($l) => $l !== ''));
}

function fmtDate(string $dt): string
{
    return substr($dt, 8, 2) . '.' . substr($dt, 5, 2) . '.' . substr($dt, 0, 4);
}
?>
<!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Time Manager</title>
    <meta name="theme-color" content="#2563eb">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="apple-touch-icon" href="assets/icons/icon-180.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Time Manager">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="stylesheet" href="assets/style.css?v=<?= APP_VERSION ?>">
    <script>(function(){
        var t=localStorage.getItem('tm_theme')||'dark';
        document.documentElement.setAttribute('data-theme',t);
        var z=localStorage.getItem('tm_zoom');
        if(z)document.documentElement.style.setProperty('--app-zoom',z/100);
        if(!new URLSearchParams(location.search).has('date')){
            var d=new Date();
            var s=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
            location.replace('index.php?date='+s);
        }
    }());</script>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ================================================================
     LOGIN
================================================================ -->
<div class="login-overlay">
    <div class="login-card">
        <h1>Time Manager</h1>

        <!-- Login-Formular -->
        <div id="loginError" class="login-error hidden"></div>
        <form id="loginForm" novalidate>
            <input type="text"     name="username" placeholder="<?= h(t('login.username')) ?>"
                   autocomplete="username" required>
            <input type="password" name="password" placeholder="<?= h(t('login.password')) ?>"
                   autocomplete="current-password" required>
            <button type="submit" class="btn btn--primary"><?= h(t('login.signIn')) ?></button>
        </form>
        <div class="login-switch">
            <a href="#" id="forgotLink"><?= h(t('login.forgot')) ?></a>
        </div>

        <!-- Passwort-vergessen-Formular (anfangs ausgeblendet) -->
        <div id="forgotPanel" class="hidden">
            <div id="forgotMessage" class="hidden"></div>
            <input type="email" id="forgotEmail"
                   placeholder="<?= h(t('login.email')) ?>" autocomplete="email">
            <button type="button" id="forgotSubmit" class="btn btn--primary">
                <?= h(t('login.sendLink')) ?>
            </button>
            <div class="login-switch">
                <a href="#" id="backToLogin"><?= h(t('login.back')) ?></a>
            </div>
        </div>

        <!-- Sprachauswahl -->
        <div class="login-switch" style="margin-top:14px">
            <?php foreach (i18nLangLabels() as $lc => $ll): ?>
            <a href="?lang=<?= h($lc) ?>" style="<?= $lc === currentLang() ? 'font-weight:700;text-decoration:underline' : '' ?>"><?= h($ll) ?></a><?= $lc !== array_key_last(i18nLangLabels()) ? ' · ' : '' ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ================================================================
     APP
================================================================ -->
<script>
    window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
    window.LANG = <?= json_encode(currentLang()) ?>;
    window.I18N = <?= json_encode(i18nStrings(), JSON_UNESCAPED_UNICODE) ?>;
    window.USER_STATE = <?= $userState ? json_encode($userState) : 'null' ?>;
    window.SHORTCUTS = <?= json_encode(array_values($shortcuts), JSON_UNESCAPED_UNICODE) ?>;
    window.CUSTOMER_PROJECTS = <?php
        $cp = [];
        foreach ($customers as $c) {
            $p = $c['projects'] ? json_decode($c['projects'], true) : [];
            $cp[(int)$c['id']] = is_array($p) ? $p : [];
        }
        echo json_encode($cp);
    ?>;
</script>

<!-- ---- EINSTELLUNGEN (eigene Ansicht) -------------------- -->
<div id="settingsView" class="settings-view hidden">
    <div class="settings-inner">
        <div class="settings-topbar">
            <strong><?= h(t('settings.title')) ?></strong>
            <button type="button" class="btn" id="settingsClose"><?= h(t('settings.done')) ?></button>
        </div>
        <div class="settings-item">
            <span class="settings-item-label"><?= h(t('settings.fontSize')) ?></span>
            <div class="settings-item-control">
                <input type="range" id="fontSizeSlider" min="90" max="150" step="1" value="100">
                <span id="fontSizeValue" class="settings-val">100%</span>
            </div>
        </div>
        <div class="settings-item">
            <span class="settings-item-label"><?= h(t('settings.design')) ?></span>
            <div class="settings-item-control">
                <div class="theme-choice" id="themeChoice">
                    <button type="button" class="theme-btn" data-theme-choice="light"><?= h(t('settings.light')) ?></button>
                    <button type="button" class="theme-btn" data-theme-choice="dark"><?= h(t('settings.dark')) ?></button>
                </div>
            </div>
        </div>
        <div class="settings-item">
            <span class="settings-item-label"><?= h(t('settings.language')) ?></span>
            <div class="settings-item-control">
                <div class="theme-choice" id="langChoice">
                    <?php foreach (i18nLangLabels() as $lc => $ll): ?>
                    <button type="button" class="theme-btn<?= $lc === currentLang() ? ' active' : '' ?>"
                            onclick="location.href='index.php?lang=<?= h($lc) ?>'"><?= h($ll) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="app">

    <!-- ---- TRACKER -------------------------------------------- -->
    <section class="tracker-card">

        <!-- Header: title + monthly stats -->
        <div class="tracker-row tracker-header">
            <strong><?= h(t('tracker.title')) ?></strong>
            <span class="tracker-stats">
                <?= $monthlyStats['total'] ?>&nbsp;h
                &nbsp;/&nbsp;
                Ø&nbsp;<?= $monthlyStats['avg'] ?>&nbsp;h/d
            </span>
            <div class="tracker-header-icons">
                <div class="settings-wrap">
                    <button type="button" class="btn-icon btn-settings" id="btnSettings" title="<?= h(t('settings.title')) ?>">
                        <svg viewBox="0 0 512 512" width="14" height="14" aria-hidden="true"><path d="M0 416c0 17.7 14.3 32 32 32l54.7 0c12.3 28.3 40.5 48 73.3 48s61-19.7 73.3-48L480 448c17.7 0 32-14.3 32-32s-14.3-32-32-32l-246.7 0c-12.3-28.3-40.5-48-73.3-48s-61 19.7-73.3 48L32 384c-17.7 0-32 14.3-32 32zm128 0a32 32 0 1 1 64 0 32 32 0 1 1 -64 0zM320 256a32 32 0 1 1 64 0 32 32 0 1 1 -64 0zm32-80c-32.8 0-61 19.7-73.3 48L32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l246.7 0c12.3 28.3 40.5 48 73.3 48s61-19.7 73.3-48L480 288c17.7 0 32-14.3 32-32s-14.3-32-32-32l-54.7 0c-12.3-28.3-40.5-48-73.3-48zM192 128a32 32 0 1 1 0-64 32 32 0 1 1 0 64zm73.3-48C253 51.7 224.8 32 192 32s-61 19.7-73.3 48L32 80C14.3 80 0 94.3 0 112s14.3 32 32 32l86.7 0c12.3 28.3 40.5 48 73.3 48s61-19.7 73.3-48L480 144c17.7 0 32-14.3 32-32s-14.3-32-32-32L265.3 80z"/></svg>
                    </button>
                </div>
                <button type="button" class="btn-icon btn-reload" onclick="location.reload()" title="<?= h(t('tracker.reload')) ?>">
                    <svg viewBox="0 0 512 512" width="14" height="14" aria-hidden="true"><path d="M463.5 224H472c13.3 0 24-10.7 24-24V72c0-9.7-5.8-18.5-14.8-22.2S461.9 48.1 455 55l-41.6 41.6c-87.6-86.5-228.7-86.2-315.8 1C51.6 143.9 32 198.9 32 256c0 114.9 93.1 208 208 208c55.4 0 105.9-21.4 143.5-56.2c11.1-10.4 11.7-27.8 1.3-38.9s-27.8-11.7-38.9-1.3C317.8 396.3 288.4 408 256 408c-83.9 0-152-68.1-152-152c0-42.4 17.3-80.9 45.2-108.8c59.1-59.1 154.7-59.1 213.8 0l-41.6 41.6c-6.9 6.9-8.9 17.2-5.2 26.2S327.3 232 337 232h126.5z"/></svg>
                </button>
            </div>
        </div>

        <!-- Controls: reset · countdown · customer label -->
        <div class="tracker-row">
            <button type="button" id="resetBtn" class="btn"><?= h(t('tracker.reset')) ?></button>
            <div id="countdown" class="countdown">1800</div>
            <span class="customer-display">
                <span id="customerDisplay"></span><span id="projectDisplay" class="display-sep"></span><span id="activityDisplay" class="display-sep"></span>
            </span>
        </div>

        <!-- Selects: Kunde | Projekt | Tätigkeit -->
        <div class="tracker-row tracker-selects">
            <select id="selectCustomer">
                <option value=""><?= h(t('tracker.chooseCustomer')) ?></option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>"
                        data-name="<?= h($c['name']) ?>">
                    <?= h($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select id="selectProject" class="hidden">
                <option value=""><?= h(t('tracker.chooseProject')) ?></option>
            </select>
            <select id="selectActivity" class="hidden">
                <option value=""><?= h(t('tracker.chooseActivity')) ?></option>
                <?php foreach ($activities as $act): ?>
                <option value="<?= h($act) ?>"><?= h($act) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- State 2: Kommentar -->
        <div class="tracker-row hidden" id="rowComment">
            <input type="text" id="inputComment" placeholder="<?= h(t('tracker.commentPlaceholder')) ?>">
        </div>

        <!-- Shortcuts -->
        <div class="tracker-row hidden" id="rowShortcuts">
            <div id="shortcutBtns" style="display:flex;gap:6px;flex-wrap:wrap"></div>
        </div>

        <!-- State 2: Stop + Startzeit -->
        <div class="tracker-row hidden" id="rowStop">
            <button type="button" id="stopBtn" class="btn btn--primary">
                <?= h(t('tracker.stopSave')) ?>
            </button>
            <span class="start-label">
                <?= h(t('tracker.startLabel')) ?>&nbsp;<span id="startTimeWrap"
                      style="position:relative;display:inline-block;cursor:pointer;border-bottom:1px dashed currentColor"
                      title="<?= h(t('tracker.changeStart')) ?>"
                      onclick="var p=document.getElementById('startTimePicker'); if(p.showPicker){try{p.showPicker();}catch(e){}}">
                    <span id="startTime">--:--:--</span>
                    <input type="time" id="startTimePicker"
                           style="position:absolute;left:0;top:0;width:100%;height:100%;opacity:0;cursor:pointer;border:none;padding:0;margin:0">
                </span>
            </span>
        </div>

    </section>

    <!-- ---- ENTRIES -------------------------------------------- -->
    <section class="entries-section">

        <div class="entries-header">
            <span class="entries-header-title">
                <span id="dateLabel"
                      style="position:relative;display:inline-block;cursor:pointer;border-bottom:1px dashed currentColor"
                      title="<?= h(t('entries.chooseDay')) ?>"
                      onclick="var p=document.getElementById('datePicker'); if(p.showPicker){try{p.showPicker();}catch(e){}}">
                    <?= $isToday ? h(t('entries.today')) : h(t('entries.entries')) ?>: <?= date('d.m.Y', strtotime($viewDate)) ?>
                    <input type="date" id="datePicker" value="<?= h($viewDate) ?>"
                           style="position:absolute;left:0;top:0;width:100%;height:100%;opacity:0;cursor:pointer;border:none;padding:0;margin:0">
                </span>
                <span class="entries-meta">
                    &ndash; <?= count($todayEntries) ?> <?= h(t('entries.countUnit')) ?>,
                    <?= round($todayMinutes / 60, 2) ?>&nbsp;h
                </span>
            </span>
            <div style="display:flex;align-items:center;gap:8px">
                <?php if ($userRole === 'admin'): ?>
                <a href="admin/" class="btn-logout"><?= h(t('nav.admin')) ?></a>
                <?php endif; ?>
                <a href="?logout=1" class="btn-logout"><?= h(t('nav.logout')) ?></a>
            </div>
        </div>

        <?php if (empty($todayEntries)): ?>
            <p class="empty-message"><?= h(t('entries.emptyToday')) ?></p>
        <?php else: ?>

        <div class="table-wrapper">
            <table class="entries-table today-entries">
                <thead>
                    <tr>
                        <th class="col-time"><?= h(t('entries.colTime')) ?></th>
                        <th class="col-dur"><?= h(t('entries.colMin')) ?></th>
                        <th class="col-customer"><?= h(t('entries.colCustomer')) ?></th>
                        <th class="col-activity"><?= h(t('entries.colActivity')) ?></th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($todayEntries as $e): ?>

                    <!-- Display row -->
                    <tr id="row-<?= $e['id'] ?>" class="entry-row">
                        <td class="col-time">
                            <?= fmtTime($e['start_datetime']) ?>–<?= fmtTime($e['end_datetime']) ?>
                            <span class="tm-meta">
                                <span class="tm-min"><?= (int)$e['duration_minutes'] ?> <?= h(t('entries.minShort')) ?></span>
                                <span class="tm-cust"><?= $e['customer_name'] !== '' ? h($e['customer_name']) : '—' ?></span>
                            </span>
                        </td>
                        <td class="col-dur"><?= $e['duration_minutes'] ?></td>
                        <td class="col-customer">
                            <?= $e['customer_name'] !== '' ? h($e['customer_name']) : '—' ?>
                        </td>
                        <td class="col-activity">
                            <?= h($e['activity']) ?>
                            <?php if ($e['project'] !== '' && $e['project'] !== null): ?>
                                <span class="project-tag"><?= h($e['project']) ?></span>
                            <?php endif; ?>
                            <?php if ($e['comment'] !== '' && $e['comment'] !== null): ?>
                                <span class="comment"><?= h($e['comment']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="col-actions">
                            <!-- Normalzustand: Bearbeiten + Löschen -->
                            <div class="actions-normal" id="actions-<?= $e['id'] ?>">
                                <button type="button" class="btn-icon"
                                        onclick="showEdit(<?= $e['id'] ?>)"
                                        title="<?= h(t('common.edit')) ?>">
                                    <svg viewBox="0 0 512 512" width="14" height="14" aria-hidden="true"><path d="M441 58.9L453.1 71c9.4 9.4 9.4 24.6 0 33.9L424 134.1 377.9 88 407 58.9c9.4-9.4 24.6-9.4 33.9 0zM209.8 256.2L344 121.9 390.1 168 255.8 302.2c-2.9 2.9-6.5 5-10.4 6.1l-58.5 16.7 16.7-58.5c1.1-3.9 3.2-7.5 6.1-10.4zM373.1 25L175.8 222.2c-8.7 8.7-15 19.4-18.3 31.1l-28.6 100c-2.4 8.4-.1 17.4 6.1 23.6s15.2 8.5 23.6 6.1l100-28.6c11.8-3.4 22.5-9.7 31.1-18.3L487 138.9c28.1-28.1 28.1-73.7 0-101.8L474.9 25C446.8-3.1 401.2-3.1 373.1 25zM88 64C39.4 64 0 103.4 0 152L0 424c0 48.6 39.4 88 88 88l272 0c48.6 0 88-39.4 88-88l0-112c0-13.3-10.7-24-24-24s-24 10.7-24 24l0 112c0 22.1-17.9 40-40 40L88 464c-22.1 0-40-17.9-40-40l0-272c0-22.1 17.9-40 40-40l112 0c13.3 0 24-10.7 24-24s-10.7-24-24-24L88 64z"/></svg>
                                </button>
                                <button type="button" class="btn-icon btn-icon--danger"
                                        onclick="showDeleteConfirm(<?= $e['id'] ?>)"
                                        title="<?= h(t('common.delete')) ?>">
                                    <svg viewBox="0 0 448 512" width="14" height="14" aria-hidden="true"><path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"/></svg>
                                </button>
                            </div>
                            <!-- Bestätigungszustand: Bestätigen + Abbrechen -->
                            <div class="actions-confirm hidden" id="actions-confirm-<?= $e['id'] ?>">
                                <button type="button" class="btn-icon btn-icon--confirm"
                                        onclick="confirmDelete(<?= $e['id'] ?>)"
                                        title="<?= h(t('common.confirmDelete')) ?>">
                                    <svg viewBox="0 0 448 512" width="14" height="14" aria-hidden="true"><path d="M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z"/></svg>
                                </button>
                                <button type="button" class="btn-icon"
                                        onclick="cancelDelete(<?= $e['id'] ?>)"
                                        title="<?= h(t('common.cancel')) ?>">
                                    <svg viewBox="0 0 384 512" width="14" height="14" aria-hidden="true"><path d="M342.6 150.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L192 210.7 86.6 105.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L146.7 256 41.4 361.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L192 301.3 297.4 406.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L237.3 256 342.6 150.6z"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>

                    <!-- Edit row -->
                    <tr id="edit-<?= $e['id'] ?>" class="edit-row hidden">
                        <td colspan="5">
                            <div class="edit-form" style="flex-wrap:wrap;row-gap:6px">
                                <input type="date"
                                       class="edit-date"
                                       value="<?= h($e['date']) ?>">
                                <input type="text"
                                       class="edit-start"
                                       value="<?= h($e['start_datetime']) ?>"
                                       placeholder="<?= h(t('entries.startPlaceholder')) ?>">
                                <span>–</span>
                                <input type="text"
                                       class="edit-end"
                                       value="<?= h($e['end_datetime']) ?>"
                                       placeholder="<?= h(t('entries.endPlaceholder')) ?>">
                                <button type="button"
                                        class="btn"
                                        onclick="setEndNow(<?= $e['id'] ?>)"
                                        title="<?= h(t('entries.setEndNow')) ?>">
                                    <?= h(t('common.now')) ?>
                                </button>
                                <select class="edit-customer">
                                    <option value=""><?= h(t('common.noCustomer')) ?></option>
                                    <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"
                                        <?= (int)$e['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= h($c['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="edit-activity">
                                    <?php
                                    // Aktuelle Tätigkeit des Eintrags erhalten, auch wenn sie
                                    // nicht (mehr) in der verwalteten Liste steht.
                                    $editActs = $activities;
                                    if ($e['activity'] !== '' && !in_array($e['activity'], $editActs, true)) {
                                        array_unshift($editActs, $e['activity']);
                                    }
                                    foreach ($editActs as $act): ?>
                                    <option value="<?= h($act) ?>"
                                        <?= $e['activity'] === $act ? 'selected' : '' ?>>
                                        <?= h($act) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="edit-project">
                                    <option value=""><?= h(t('common.noProject')) ?></option>
                                    <?php foreach ($projectsByCustomer[(int)$e['customer_id']] ?? [] as $p): ?>
                                    <option value="<?= h($p['name']) ?>"
                                        <?= ($e['project'] ?? '') === $p['name'] ? 'selected' : '' ?>>
                                        <?= h($p['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text"
                                       class="edit-comment"
                                       value="<?= h((string)$e['comment']) ?>"
                                       placeholder="<?= h(t('entries.commentPlaceholder')) ?>">
                                <button type="button"
                                        class="btn btn--primary"
                                        onclick="saveEdit(<?= $e['id'] ?>)">
                                    <?= h(t('common.save')) ?>
                                </button>
                                <button type="button"
                                        class="btn"
                                        onclick="hideEdit(<?= $e['id'] ?>)">
                                    <?= h(t('common.cancel')) ?>
                                </button>
                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>
    </section>

    <!-- ---- AUFTRÄGE ------------------------------------------ -->
    <section class="orders-section">

        <div class="entries-header">
            <span class="entries-header-title"><?= h(t('orders.title')) ?></span>
        </div>

        <div class="order-form">
            <select id="orderCustomer">
                <option value=""><?= h(t('tracker.chooseCustomer')) ?></option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" data-name="<?= h($c['name']) ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <div id="orderFields" style="display:none;flex-direction:column;gap:10px">
                <div class="rte-wrap">
                    <div class="rte-toolbar">
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('bold')"><b>B</b></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('italic')"><em>I</em></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('underline')"><u>U</u></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('insertUnorderedList')"><?= t('orders.rteList') ?></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('removeFormat')" title="<?= h(t('orders.removeFormat')) ?>">&#10005;</button>
                    </div>
                    <div class="rte-body" id="orderBody" contenteditable="true"
                         data-placeholder="<?= h(t('orders.bodyPlaceholder')) ?>"></div>
                </div>

                <div class="order-upload">
                    <input type="file" id="orderFiles" multiple
                           accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.heic,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv">
                    <span class="order-hint"><?= h(t('orders.fileHint')) ?></span>
                </div>

                <div class="order-form-actions">
                    <button type="button" class="btn btn--primary" id="orderSaveBtn"><?= h(t('orders.save')) ?></button>
                    <span id="orderMsg" class="order-msg"></span>
                </div>
            </div>
        </div>

        <div style="margin-top:24px"></div>

        <?php if (empty($processingOrders)): ?>
            <p class="empty-message" id="ordersEmpty"><?= h(t('orders.none')) ?></p>
        <?php endif; ?>
<?php
$icoPencil = '<svg viewBox="0 0 512 512" width="14" height="14" aria-hidden="true"><path d="M441 58.9L453.1 71c9.4 9.4 9.4 24.6 0 33.9L424 134.1 377.9 88 407 58.9c9.4-9.4 24.6-9.4 33.9 0zM209.8 256.2L344 121.9 390.1 168 255.8 302.2c-2.9 2.9-6.5 5-10.4 6.1l-58.5 16.7 16.7-58.5c1.1-3.9 3.2-7.5 6.1-10.4zM373.1 25L175.8 222.2c-8.7 8.7-15 19.4-18.3 31.1l-28.6 100c-2.4 8.4-.1 17.4 6.1 23.6s15.2 8.5 23.6 6.1l100-28.6c11.8-3.4 22.5-9.7 31.1-18.3L487 138.9c28.1-28.1 28.1-73.7 0-101.8L474.9 25C446.8-3.1 401.2-3.1 373.1 25zM88 64C39.4 64 0 103.4 0 152L0 424c0 48.6 39.4 88 88 88l272 0c48.6 0 88-39.4 88-88l0-112c0-13.3-10.7-24-24-24s-24 10.7-24 24l0 112c0 22.1-17.9 40-40 40L88 464c-22.1 0-40-17.9-40-40l0-272c0-22.1 17.9-40 40-40l112 0c13.3 0 24-10.7 24-24s-10.7-24-24-24L88 64z"/></svg>';
$icoTrash  = '<svg viewBox="0 0 448 512" width="14" height="14" aria-hidden="true"><path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"/></svg>';
$icoCheck  = '<svg viewBox="0 0 448 512" width="14" height="14" aria-hidden="true"><path d="M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z"/></svg>';
$icoX      = '<svg viewBox="0 0 384 512" width="14" height="14" aria-hidden="true"><path d="M342.6 150.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L192 210.7 86.6 105.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L146.7 256 41.4 361.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L192 301.3 297.4 406.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L237.3 256 342.6 150.6z"/></svg>';
$icoGrip   = '<svg viewBox="0 0 320 512" width="12" height="12" aria-hidden="true"><path d="M40 352l48 0c22.1 0 40 17.9 40 40l0 48c0 22.1-17.9 40-40 40l-48 0c-22.1 0-40-17.9-40-40l0-48c0-22.1 17.9-40 40-40zm192 0l48 0c22.1 0 40 17.9 40 40l0 48c0 22.1-17.9 40-40 40l-48 0c-22.1 0-40-17.9-40-40l0-48c0-22.1 17.9-40 40-40zM40 320c-22.1 0-40-17.9-40-40l0-48c0-22.1 17.9-40 40-40l48 0c22.1 0 40 17.9 40 40l0 48c0 22.1-17.9 40-40 40l-48 0zm192-128l48 0c22.1 0 40 17.9 40 40l0 48c0 22.1-17.9 40-40 40l-48 0c-22.1 0-40-17.9-40-40l0-48c0-22.1 17.9-40 40-40zM40 32l48 0c22.1 0 40 17.9 40 40l0 48c0 22.1-17.9 40-40 40l-48 0c-22.1 0-40-17.9-40-40l0-48c0-22.1 17.9-40 40-40zM232 32l48 0c22.1 0 40 17.9 40 40l0 48c0 22.1-17.9 40-40 40l-48 0c-22.1 0-40-17.9-40-40l0-48c0-22.1 17.9-40 40-40z"/></svg>';
$ordAccept = '.jpg,.jpeg,.png,.gif,.webp,.bmp,.heic,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv';
$icoList   = '<svg viewBox="0 0 512 512" width="14" height="14" aria-hidden="true"><path d="M40 48C26.7 48 16 58.7 16 72l0 48c0 13.3 10.7 24 24 24l48 0c13.3 0 24-10.7 24-24l0-48c0-13.3-10.7-24-24-24L40 48zM192 64c-17.7 0-32 14.3-32 32s14.3 32 32 32l288 0c17.7 0 32-14.3 32-32s-14.3-32-32-32L192 64zm0 160c-17.7 0-32 14.3-32 32s14.3 32 32 32l288 0c17.7 0 32-14.3 32-32s-14.3-32-32-32l-288 0zm0 160c-17.7 0-32 14.3-32 32s14.3 32 32 32l288 0c17.7 0 32-14.3 32-32s-14.3-32-32-32l-288 0zM16 232l0 48c0 13.3 10.7 24 24 24l48 0c13.3 0 24-10.7 24-24l0-48c0-13.3-10.7-24-24-24l-48 0c-13.3 0-24 10.7-24 24zM40 368c-13.3 0-24 10.7-24 24l0 48c0 13.3 10.7 24 24 24l48 0c13.3 0 24-10.7 24-24l0-48c0-13.3-10.7-24-24-24l-48 0z"/></svg>';

$renderOrderEditor = function (int $id) use ($ordAccept) { ?>
    <div style="display:flex;flex-direction:column;gap:10px;padding:4px 2px">
        <div class="rte-wrap">
            <div class="rte-toolbar">
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('bold')"><b>B</b></button>
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('italic')"><em>I</em></button>
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('underline')"><u>U</u></button>
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('insertUnorderedList')">&bull; Liste</button>
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('removeFormat')" title="Formatierung entfernen">&#10005;</button>
            </div>
            <div class="rte-body" id="oeditBody-<?= $id ?>" contenteditable="true"></div>
        </div>
        <div class="order-view-files" id="oeditFiles-<?= $id ?>"></div>
        <div class="order-upload">
            <input type="file" id="oeditNewFiles-<?= $id ?>" multiple accept="<?= $ordAccept ?>">
            <span class="order-hint"><?= h(t('orders.attachMore')) ?></span>
        </div>
        <div class="order-view-actions">
            <button type="button" class="btn btn--primary" onclick="saveOrderInline(<?= $id ?>)"><?= h(t('common.save')) ?></button>
            <button type="button" class="btn" onclick="completeOrderInline(<?= $id ?>)"
                    style="background:#27ae60;color:#fff;border-color:#27ae60"><?= h(t('orders.markDone')) ?></button>
            <button type="button" class="btn" onclick="hideOrderEdit(<?= $id ?>)"><?= h(t('orders.close')) ?></button>
            <span class="order-msg" id="oeditMsg-<?= $id ?>"></span>
        </div>
    </div>
<?php };
?>
        <div class="table-wrapper">
            <table class="entries-table orders-table"<?= empty($processingOrders) ? ' style="display:none"' : '' ?> id="ordersTable">
                <thead>
                    <tr>
                        <th class="ord-cust"><?= h(t('entries.colCustomer')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="ordersTbody">
                <?php foreach ($processingOrders as $o):
                    $oid = (int)$o['id'];
                    $cid = (int)$o['customer_id'];
                    $custOrders = $openOrdersByCustomer[$cid] ?? [];
                ?>
                    <tr class="entry-row order-row" id="orow-<?= $oid ?>" data-id="<?= $oid ?>"
                        onclick="toggleCustomerOrders(event, <?= $oid ?>)" title="<?= h(t('orders.showCustomer')) ?>">
                        <td class="ord-cust">
                            <span class="order-drag" draggable="true" title="<?= h(t('orders.dragReorder')) ?>"
                                  onclick="event.stopPropagation()"><?= $icoGrip ?></span>
                            <span class="ord-date"><?= h(date('d.m.', strtotime($o['created_at']))) ?></span>
                            <span class="ord-name"><?= h($o['customer_name'] !== '' ? $o['customer_name'] : '—') ?></span>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <button type="button" class="btn" onclick="markWorked(event, <?= $oid ?>)"
                                    title="<?= h(t('orders.workedTitle')) ?>"><?= h(t('orders.worked')) ?></button>
                        </td>
                    </tr>
                    <tr id="osub-<?= $oid ?>" class="edit-row hidden">
                        <td colspan="2">
                            <div class="suborder-list">
                                <?php foreach ($custOrders as $so):
                                    $soid   = (int)$so['id'];
                                    $olines = orderBodyLines($so['body']);
                                    $ofirst = $olines[0] ?? '';
                                    $omulti = count($olines) > 1;
                                ?>
                                <div class="suborder" id="suborder-<?= $soid ?>">
                                    <div class="suborder-head">
                                        <span class="suborder-date"><?= h(date('d.m.', strtotime($so['created_at']))) ?></span>
                                        <?php if ($omulti): ?>
                                        <button type="button" class="suborder-expand" id="soexpand-<?= $soid ?>"
                                                onclick="toggleSuborderFull(event, <?= $soid ?>)"
                                                title="<?= h(t('orders.expand')) ?>" aria-expanded="false">&#9656;</button>
                                        <?php endif; ?>
                                        <span class="suborder-preview"><?= $ofirst !== '' ? h($ofirst) : '<i>' . h(t('orders.noText')) . '</i>' ?></span>
                                        <span class="actions-normal" id="soactions-<?= $soid ?>">
                                            <button type="button" class="btn-icon" onclick="showOrderEdit(<?= $soid ?>)" title="<?= h(t('common.edit')) ?>"><?= $icoPencil ?></button>
                                            <button type="button" class="btn-icon btn-icon--danger" onclick="showSubDeleteConfirm(<?= $soid ?>)" title="<?= h(t('common.delete')) ?>"><?= $icoTrash ?></button>
                                        </span>
                                        <span class="actions-confirm hidden" id="soactions-confirm-<?= $soid ?>">
                                            <button type="button" class="btn-icon btn-icon--confirm" onclick="confirmSubDelete(<?= $soid ?>)" title="<?= h(t('common.confirmDelete')) ?>"><?= $icoCheck ?></button>
                                            <button type="button" class="btn-icon" onclick="cancelSubDelete(<?= $soid ?>)" title="<?= h(t('common.cancel')) ?>"><?= $icoX ?></button>
                                        </span>
                                    </div>
                                    <?php if ($omulti): ?>
                                    <div class="suborder-full hidden" id="sofull-<?= $soid ?>"><?= nl2br(h(implode("\n", $olines))) ?></div>
                                    <?php endif; ?>
                                    <div id="oedit-<?= $soid ?>" class="suborder-edit hidden">
                                        <?php $renderOrderEditor($soid); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($custOrders)): ?>
                                <div class="order-hint" style="padding:6px"><?= h(t('orders.none')) ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="worked-customers"<?= empty($workedCustomers) ? ' style="display:none"' : '' ?> id="workedCustomers">
            <button type="button" class="worked-toggle" onclick="toggleWorkedList()">
                <span class="worked-caret" id="workedCaret">&#9656;</span>
                <span><?= h(t('orders.workedCustomers')) ?> (<span id="workedCount"><?= count($workedCustomers) ?></span>)</span>
            </button>
            <div class="worked-list hidden" id="workedList">
                <?php foreach ($workedCustomers as $wc): $wcid = (int)$wc['customer_id']; ?>
                <div class="worked-item" id="worked-<?= $wcid ?>">
                    <span class="worked-name"><?= h($wc['customer_name'] !== '' ? $wc['customer_name'] : '—') ?></span>
                    <button type="button" class="btn" onclick="resetWorked(<?= $wcid ?>)"
                            title="<?= h(t('orders.resetStatusTitle')) ?>"><?= h(t('orders.resetStatus')) ?></button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </section>

</div><!-- .app -->
<?php endif; ?>

<script src="assets/dialog.js"></script>
<script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
