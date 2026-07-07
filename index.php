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

/* ------------------------------------------------------------------
   Load data (only when logged in)
------------------------------------------------------------------ */
$monthlyStats = ['total' => 0.0, 'avg' => 0.0];
$todayEntries = [];
$customers    = [];
$userState    = null;
$todayMinutes = 0;
$userRole     = '';
$today        = date('Y-m-d');
$viewDate     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;
$isToday      = $viewDate === $today;

if ($loggedIn) {
    $pdo = db();

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

function fmtDate(string $dt): string
{
    return substr($dt, 8, 2) . '.' . substr($dt, 5, 2) . '.' . substr($dt, 0, 4);
}
?>
<!DOCTYPE html>
<html lang="de">
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
            <input type="text"     name="username" placeholder="Benutzername"
                   autocomplete="username" required>
            <input type="password" name="password" placeholder="Passwort"
                   autocomplete="current-password" required>
            <button type="submit" class="btn btn--primary">Anmelden</button>
        </form>
        <div class="login-switch">
            <a href="#" id="forgotLink">Passwort vergessen?</a>
        </div>

        <!-- Passwort-vergessen-Formular (anfangs ausgeblendet) -->
        <div id="forgotPanel" class="hidden">
            <div id="forgotMessage" class="hidden"></div>
            <input type="email" id="forgotEmail"
                   placeholder="Ihre E-Mail-Adresse" autocomplete="email">
            <button type="button" id="forgotSubmit" class="btn btn--primary">
                Link senden
            </button>
            <div class="login-switch">
                <a href="#" id="backToLogin">← Zurück zum Login</a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ================================================================
     APP
================================================================ -->
<script>
    window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
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
            <strong>Einstellungen</strong>
            <button type="button" class="btn" id="settingsClose">Fertig</button>
        </div>
        <div class="settings-item">
            <span class="settings-item-label">Schriftgröße</span>
            <div class="settings-item-control">
                <input type="range" id="fontSizeSlider" min="90" max="150" step="1" value="100">
                <span id="fontSizeValue" class="settings-val">100%</span>
            </div>
        </div>
        <div class="settings-item">
            <span class="settings-item-label">Design</span>
            <div class="settings-item-control">
                <div class="theme-choice" id="themeChoice">
                    <button type="button" class="theme-btn" data-theme-choice="light">Hell</button>
                    <button type="button" class="theme-btn" data-theme-choice="dark">Dunkel</button>
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
            <strong>Arbeitszeiterfassung</strong>
            <span class="tracker-stats">
                <?= $monthlyStats['total'] ?>&nbsp;h
                &nbsp;/&nbsp;
                Ø&nbsp;<?= $monthlyStats['avg'] ?>&nbsp;h/d
            </span>
            <div class="tracker-header-icons">
                <div class="settings-wrap">
                    <button type="button" class="btn-icon btn-settings" id="btnSettings" title="Einstellungen">
                        <svg viewBox="0 0 512 512" width="14" height="14" aria-hidden="true"><path d="M0 416c0 17.7 14.3 32 32 32l54.7 0c12.3 28.3 40.5 48 73.3 48s61-19.7 73.3-48L480 448c17.7 0 32-14.3 32-32s-14.3-32-32-32l-246.7 0c-12.3-28.3-40.5-48-73.3-48s-61 19.7-73.3 48L32 384c-17.7 0-32 14.3-32 32zm128 0a32 32 0 1 1 64 0 32 32 0 1 1 -64 0zM320 256a32 32 0 1 1 64 0 32 32 0 1 1 -64 0zm32-80c-32.8 0-61 19.7-73.3 48L32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l246.7 0c12.3 28.3 40.5 48 73.3 48s61-19.7 73.3-48L480 288c17.7 0 32-14.3 32-32s-14.3-32-32-32l-54.7 0c-12.3-28.3-40.5-48-73.3-48zM192 128a32 32 0 1 1 0-64 32 32 0 1 1 0 64zm73.3-48C253 51.7 224.8 32 192 32s-61 19.7-73.3 48L32 80C14.3 80 0 94.3 0 112s14.3 32 32 32l86.7 0c12.3 28.3 40.5 48 73.3 48s61-19.7 73.3-48L480 144c17.7 0 32-14.3 32-32s-14.3-32-32-32L265.3 80z"/></svg>
                    </button>
                </div>
                <button type="button" class="btn-icon btn-reload" onclick="location.reload()" title="Neu laden">
                    <svg viewBox="0 0 512 512" width="14" height="14" aria-hidden="true"><path d="M463.5 224H472c13.3 0 24-10.7 24-24V72c0-9.7-5.8-18.5-14.8-22.2S461.9 48.1 455 55l-41.6 41.6c-87.6-86.5-228.7-86.2-315.8 1C51.6 143.9 32 198.9 32 256c0 114.9 93.1 208 208 208c55.4 0 105.9-21.4 143.5-56.2c11.1-10.4 11.7-27.8 1.3-38.9s-27.8-11.7-38.9-1.3C317.8 396.3 288.4 408 256 408c-83.9 0-152-68.1-152-152c0-42.4 17.3-80.9 45.2-108.8c59.1-59.1 154.7-59.1 213.8 0l-41.6 41.6c-6.9 6.9-8.9 17.2-5.2 26.2S327.3 232 337 232h126.5z"/></svg>
                </button>
            </div>
        </div>

        <!-- Controls: reset · countdown · customer label -->
        <div class="tracker-row">
            <button type="button" id="resetBtn" class="btn">Reset</button>
            <div id="countdown" class="countdown">1800</div>
            <span class="customer-display">
                <span id="customerDisplay"></span><span id="projectDisplay" class="display-sep"></span><span id="activityDisplay" class="display-sep"></span>
            </span>
        </div>

        <!-- Selects: Kunde | Projekt | Tätigkeit -->
        <div class="tracker-row tracker-selects">
            <select id="selectCustomer">
                <option value="">-- Kunde wählen --</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>"
                        data-name="<?= h($c['name']) ?>">
                    <?= h($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select id="selectProject" class="hidden">
                <option value="">-- Projekt wählen --</option>
            </select>
            <select id="selectActivity" class="hidden">
                <option value="">-- Tätigkeit wählen --</option>
                <?php foreach (ACTIVITIES as $act): ?>
                <option value="<?= h($act) ?>"><?= h($act) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- State 2: Kommentar -->
        <div class="tracker-row hidden" id="rowComment">
            <input type="text" id="inputComment" placeholder="Was wurde bearbeitet">
        </div>

        <!-- Shortcuts -->
        <div class="tracker-row hidden" id="rowShortcuts">
            <div id="shortcutBtns" style="display:flex;gap:6px;flex-wrap:wrap"></div>
        </div>

        <!-- State 2: Stop + Startzeit -->
        <div class="tracker-row hidden" id="rowStop">
            <button type="button" id="stopBtn" class="btn btn--primary">
                Stop &amp; Speichern
            </button>
            <span class="start-label">
                Start:&nbsp;<span id="startTimeWrap"
                      style="position:relative;display:inline-block;cursor:pointer;border-bottom:1px dashed currentColor"
                      title="Startzeit ändern"
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
                      title="Anderen Tag wählen"
                      onclick="var p=document.getElementById('datePicker'); if(p.showPicker){try{p.showPicker();}catch(e){}}">
                    <?= $isToday ? 'Heute' : 'Einträge' ?>: <?= date('d.m.Y', strtotime($viewDate)) ?>
                    <input type="date" id="datePicker" value="<?= h($viewDate) ?>"
                           style="position:absolute;left:0;top:0;width:100%;height:100%;opacity:0;cursor:pointer;border:none;padding:0;margin:0">
                </span>
                <span class="entries-meta">
                    &ndash; <?= count($todayEntries) ?> Einträge,
                    <?= round($todayMinutes / 60, 2) ?>&nbsp;h
                </span>
            </span>
            <div style="display:flex;align-items:center;gap:8px">
                <?php if ($userRole === 'admin'): ?>
                <a href="admin/" class="btn-logout">Admin</a>
                <?php endif; ?>
                <a href="?logout=1" class="btn-logout">Abmelden</a>
            </div>
        </div>

        <?php if (empty($todayEntries)): ?>
            <p class="empty-message">Noch keine Einträge heute.</p>
        <?php else: ?>

        <div class="table-wrapper">
            <table class="entries-table today-entries">
                <thead>
                    <tr>
                        <th class="col-time">Zeit</th>
                        <th class="col-dur">Min</th>
                        <th class="col-customer">Kunde</th>
                        <th class="col-activity">Tätigkeit / Kommentar</th>
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
                                <span class="tm-min"><?= (int)$e['duration_minutes'] ?> Min.</span>
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
                                        title="Bearbeiten">
                                    <svg viewBox="0 0 512 512" width="14" height="14" aria-hidden="true"><path d="M441 58.9L453.1 71c9.4 9.4 9.4 24.6 0 33.9L424 134.1 377.9 88 407 58.9c9.4-9.4 24.6-9.4 33.9 0zM209.8 256.2L344 121.9 390.1 168 255.8 302.2c-2.9 2.9-6.5 5-10.4 6.1l-58.5 16.7 16.7-58.5c1.1-3.9 3.2-7.5 6.1-10.4zM373.1 25L175.8 222.2c-8.7 8.7-15 19.4-18.3 31.1l-28.6 100c-2.4 8.4-.1 17.4 6.1 23.6s15.2 8.5 23.6 6.1l100-28.6c11.8-3.4 22.5-9.7 31.1-18.3L487 138.9c28.1-28.1 28.1-73.7 0-101.8L474.9 25C446.8-3.1 401.2-3.1 373.1 25zM88 64C39.4 64 0 103.4 0 152L0 424c0 48.6 39.4 88 88 88l272 0c48.6 0 88-39.4 88-88l0-112c0-13.3-10.7-24-24-24s-24 10.7-24 24l0 112c0 22.1-17.9 40-40 40L88 464c-22.1 0-40-17.9-40-40l0-272c0-22.1 17.9-40 40-40l112 0c13.3 0 24-10.7 24-24s-10.7-24-24-24L88 64z"/></svg>
                                </button>
                                <button type="button" class="btn-icon btn-icon--danger"
                                        onclick="showDeleteConfirm(<?= $e['id'] ?>)"
                                        title="Löschen">
                                    <svg viewBox="0 0 448 512" width="14" height="14" aria-hidden="true"><path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"/></svg>
                                </button>
                            </div>
                            <!-- Bestätigungszustand: Bestätigen + Abbrechen -->
                            <div class="actions-confirm hidden" id="actions-confirm-<?= $e['id'] ?>">
                                <button type="button" class="btn-icon btn-icon--confirm"
                                        onclick="confirmDelete(<?= $e['id'] ?>)"
                                        title="Löschen bestätigen">
                                    <svg viewBox="0 0 448 512" width="14" height="14" aria-hidden="true"><path d="M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z"/></svg>
                                </button>
                                <button type="button" class="btn-icon"
                                        onclick="cancelDelete(<?= $e['id'] ?>)"
                                        title="Abbrechen">
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
                                       placeholder="Start: YYYY-MM-DD HH:MM:SS">
                                <span>–</span>
                                <input type="text"
                                       class="edit-end"
                                       value="<?= h($e['end_datetime']) ?>"
                                       placeholder="Ende: YYYY-MM-DD HH:MM:SS">
                                <button type="button"
                                        class="btn"
                                        onclick="setEndNow(<?= $e['id'] ?>)"
                                        title="Aktuelle Zeit als Ende einsetzen">
                                    Jetzt
                                </button>
                                <select class="edit-customer">
                                    <option value="">— Kein Kunde —</option>
                                    <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"
                                        <?= (int)$e['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= h($c['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="edit-activity">
                                    <?php foreach (ACTIVITIES as $act): ?>
                                    <option value="<?= h($act) ?>"
                                        <?= $e['activity'] === $act ? 'selected' : '' ?>>
                                        <?= h($act) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="edit-project">
                                    <option value="">— Kein Projekt —</option>
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
                                       placeholder="Kommentar">
                                <button type="button"
                                        class="btn btn--primary"
                                        onclick="saveEdit(<?= $e['id'] ?>)">
                                    Speichern
                                </button>
                                <button type="button"
                                        class="btn"
                                        onclick="hideEdit(<?= $e['id'] ?>)">
                                    Abbrechen
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

    <!-- ---- MONTHLY OVERVIEW ---------------------------------- -->
    <section class="month-section">

        <div class="entries-header">
            <span class="entries-header-title">Monatsübersicht</span>
        </div>

        <div class="month-controls">
            <select id="monthCustomer">
                <option value="">-- Kunde wählen --</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="monthProject" class="hidden">
                <option value="">-- Projekt wählen --</option>
            </select>
        </div>

        <div id="monthEntriesWrap" class="hidden">
            <div class="table-wrapper">
                <table class="entries-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Tätigkeit / Kommentar</th>
                            <th class="col-dur">Min</th>
                        </tr>
                    </thead>
                    <tbody id="monthEntriesTbody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="month-sum-label">Gesamt:</td>
                            <td id="monthTotal" class="col-dur month-sum-value"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </section>

</div><!-- .app -->
<?php endif; ?>

<script src="assets/dialog.js"></script>
<script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
