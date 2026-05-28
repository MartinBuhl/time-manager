<?php
require_once __DIR__ . '/auth.php';

// ---- Filter & Pagination params ----------------------------------------
$search     = trim($_GET['q'] ?? '');
$customerId = filter_var($_GET['customer_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$dateFrom   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$dateTo     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : '';
$sort       = ($_GET['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$perPage    = in_array((int)($_GET['per_page'] ?? 50), [50, 100, 500, 1000])
              ? (int)($_GET['per_page'] ?? 50) : 50;
$page       = max(1, (int)($_GET['page'] ?? 1));

$pdo = db();

// ---- Customers for filter select ----------------------------------------
$customers = $pdo->query(
    'SELECT id, name FROM tm_customers ORDER BY name ASC'
)->fetchAll();

// ---- Users for import form ----------------------------------------------
$importUsers = $pdo->query(
    "SELECT id, username FROM tm_users WHERE role != 'kunde' ORDER BY username ASC"
)->fetchAll();

// ---- Build WHERE --------------------------------------------------------
$where  = ['e.deleted_at IS NULL'];
$params = [];

if ($search !== '') {
    $where[]  = 'e.comment LIKE ?';
    $params[] = '%' . $search . '%';
}
if ($customerId > 0) {
    $where[]  = 'e.customer_id = ?';
    $params[] = $customerId;
}
if ($dateFrom !== '') {
    $where[]  = 'e.date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[]  = 'e.date <= ?';
    $params[] = $dateTo;
}

$whereStr = 'WHERE ' . implode(' AND ', $where);
$orderDir = $sort === 'asc' ? 'ASC' : 'DESC';

// ---- Count total --------------------------------------------------------
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM tm_entries e $whereStr"
);
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ---- Fetch entries ------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT e.id,
           e.start_datetime, e.end_datetime, e.duration_minutes,
           e.activity, e.project, e.comment, e.billed_at,
           e.customer_id,
           COALESCE(c.name, '') AS customer_name,
           u.username
    FROM   tm_entries e
    LEFT JOIN tm_customers c ON c.id = e.customer_id
    LEFT JOIN tm_users     u ON u.id = e.user_id
    $whereStr
    ORDER BY e.start_datetime $orderDir
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$entries = $stmt->fetchAll();

// ---- URL builder --------------------------------------------------------
function buildUrl(array $overrides = []): string
{
    $base = [
        'q'           => $_GET['q']           ?? '',
        'customer_id' => $_GET['customer_id'] ?? '',
        'date_from'   => $_GET['date_from']   ?? '',
        'date_to'     => $_GET['date_to']     ?? '',
        'sort'        => $_GET['sort']         ?? 'desc',
        'per_page'    => $_GET['per_page']     ?? '50',
        'page'        => $_GET['page']         ?? '1',
    ];
    $p = array_merge($base, $overrides);
    // strip empty / default values to keep URLs clean
    if ($p['q']           === '')    unset($p['q']);
    if ($p['customer_id'] === '' || $p['customer_id'] === '0') unset($p['customer_id']);
    if ($p['date_from']   === '')    unset($p['date_from']);
    if ($p['date_to']     === '')    unset($p['date_to']);
    if ($p['sort']        === 'desc') unset($p['sort']);
    if ($p['per_page']    === '50')   unset($p['per_page']);
    if ($p['page']        === '1' || $p['page'] === 1) unset($p['page']);
    return 'entries.php' . ($p ? '?' . http_build_query($p) : '');
}

function fmtTime(string $dt): string { return substr($dt, 11, 5); }
function fmtDate(string $dt): string
{
    return substr($dt, 8, 2) . '.' . substr($dt, 5, 2) . '.' . substr($dt, 0, 4);
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Arbeitszeit – Administration</title>
<link rel="stylesheet" href="../assets/style.css">
<style>
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 16px;
}
.filter-bar input[type="text"] { flex: 1; min-width: 160px; }
.filter-bar input[type="date"] { min-width: 140px; width: auto; }
.filter-bar select              { min-width: 140px; }
.filter-sep { font-size: 13px; color: var(--text-muted); white-space: nowrap; }
.pager {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
    margin-top: 16px;
    font-size: 13px;
}
.pager a, .pager span {
    display: inline-block;
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: 4px;
    text-decoration: none;
    color: var(--text);
    background: var(--bg);
    line-height: 1.4;
}
.pager a:hover      { background: var(--hover-bg, #f0f4f8); }
.pager span.current { background: var(--primary, #0078d4); color: #fff; border-color: var(--primary, #0078d4); font-weight: 600; }
.pager span.dots    { border: none; padding: 4px 4px; color: var(--text-muted); }
.pager-info         { margin-left: auto; font-size: 12px; color: var(--text-muted); }
.col-user           { white-space: nowrap; font-size: 12px; color: var(--text-muted); }
.col-project        { font-size: 11px; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Arbeitszeit</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo; Arbeitszeit
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button type="button" class="btn" id="btnShowBill" onclick="showBill()">Abgerechnet setzen</button>
            <button type="button" class="btn" id="btnShowImport" onclick="showImport()">Import</button>
            <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
        </div>
    </div>

    <div class="admin-section" id="listSection">

        <!-- Filter bar -->
        <form method="get" action="entries.php" class="filter-bar">
            <input type="text"
                   name="q"
                   value="<?= h($search) ?>"
                   placeholder="Kommentar suchen…"
                   autocomplete="off">

            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <span class="filter-sep">Von</span>
                <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
                <span class="filter-sep">Bis</span>
                <input type="date" name="date_to"   value="<?= h($dateTo) ?>">
            </div>

            <div style="display:flex;gap:8px;flex-wrap:nowrap">
                <select name="customer_id">
                    <option value="">Alle Kunden</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                        <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= h($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <select name="sort">
                    <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Neueste zuerst</option>
                    <option value="asc"  <?= $sort === 'asc'  ? 'selected' : '' ?>>Älteste zuerst</option>
                </select>

                <select name="per_page">
                    <?php foreach ([50, 100, 500, 1000] as $n): ?>
                    <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> pro Seite</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="btn btn--primary" type="submit">Filtern</button>
            <?php if ($search !== '' || $customerId > 0 || $dateFrom !== '' || $dateTo !== ''): ?>
            <a href="entries.php" class="btn">Zurücksetzen</a>
            <?php endif; ?>
        </form>

        <!-- Result info -->
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            <?= number_format($total, 0, ',', '.') ?> Einträge
            <?php if ($totalPages > 1): ?>
            &nbsp;·&nbsp; Seite <?= $page ?> von <?= $totalPages ?>
            <?php endif; ?>
        </div>

        <?php if (empty($entries)): ?>
            <p class="empty-message">Keine Einträge gefunden.</p>
        <?php else: ?>

        <div class="table-wrapper">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Zeit</th>
                        <th class="col-dur">Min</th>
                        <th>Benutzer</th>
                        <th>Kunde</th>
                        <th>Projekt</th>
                        <th>Tätigkeit</th>
                        <th>Kommentar</th>
                        <th>Abgerechnet</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $e): ?>
                <tr class="entry-row" id="row-<?= (int)$e['id'] ?>">
                    <td style="white-space:nowrap"><?= fmtDate($e['start_datetime']) ?></td>
                    <td style="white-space:nowrap">
                        <?= fmtTime($e['start_datetime']) ?>–<?= fmtTime($e['end_datetime']) ?>
                    </td>
                    <td class="col-dur"><?= (int)$e['duration_minutes'] ?></td>
                    <td class="col-user"><?= h($e['username']) ?></td>
                    <td><?= $e['customer_name'] !== '' ? h($e['customer_name']) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                    <td class="col-project">
                        <?php if ($e['project']): ?>
                            <span class="project-tag"><?= h($e['project']) ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($e['activity']) ?></td>
                    <td style="color:var(--text-muted);font-size:12px">
                        <?= $e['comment'] ? h($e['comment']) : '' ?>
                    </td>
                    <td style="white-space:nowrap;font-size:12px" id="billed-cell-<?= (int)$e['id'] ?>">
                        <?php if ($e['billed_at']): ?>
                            <span style="color:#27ae60"><?= date('d.m.Y', strtotime($e['billed_at'])) ?></span>
                            <button type="button" onclick="resetBilling(<?= (int)$e['id'] ?>)"
                                    title="Abrechnung zurücksetzen"
                                    style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:13px;padding:0 2px;line-height:1">&times;</button>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-actions">
                        <div class="actions-normal" id="actions-<?= (int)$e['id'] ?>">
                            <button type="button" class="btn-icon"
                                    onclick="showEdit(<?= (int)$e['id'] ?>)"
                                    title="Bearbeiten">
                                <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                            </button>
                            <button type="button" class="btn-icon btn-icon--danger"
                                    onclick="showDeleteConfirm(<?= (int)$e['id'] ?>)"
                                    title="Löschen">
                                <svg viewBox="0 0 448 512" width="14" height="14" aria-hidden="true"><path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"/></svg>
                            </button>
                        </div>
                        <div class="actions-confirm hidden" id="actions-confirm-<?= (int)$e['id'] ?>">
                            <button type="button" class="btn-icon btn-icon--confirm"
                                    onclick="confirmDelete(<?= (int)$e['id'] ?>)"
                                    title="Löschen bestätigen">
                                <svg viewBox="0 0 448 512" width="14" height="14" aria-hidden="true"><path d="M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z"/></svg>
                            </button>
                            <button type="button" class="btn-icon"
                                    onclick="cancelDelete(<?= (int)$e['id'] ?>)"
                                    title="Abbrechen">
                                <svg viewBox="0 0 384 512" width="14" height="14" aria-hidden="true"><path d="M342.6 150.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L192 210.7 86.6 105.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L146.7 256 41.4 361.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L192 301.3 297.4 406.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L237.3 256 342.6 150.6z"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <tr id="edit-<?= (int)$e['id'] ?>" class="edit-row hidden">
                    <td colspan="10">
                        <div class="edit-form" style="flex-wrap:wrap;row-gap:6px">
                            <input type="text" class="edit-start"
                                   value="<?= h($e['start_datetime']) ?>"
                                   placeholder="Start: YYYY-MM-DD HH:MM:SS">
                            <span>–</span>
                            <input type="text" class="edit-end"
                                   value="<?= h($e['end_datetime']) ?>"
                                   placeholder="Ende: YYYY-MM-DD HH:MM:SS">
                            <select class="edit-customer">
                                <option value="">— Kein Kunde —</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"
                                    <?= (int)$e['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= h($c['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" class="edit-activity"
                                   value="<?= h((string)$e['activity']) ?>"
                                   placeholder="Tätigkeit">
                            <input type="text" class="edit-project"
                                   value="<?= h((string)$e['project']) ?>"
                                   placeholder="Projekt">
                            <input type="text" class="edit-comment"
                                   value="<?= h((string)$e['comment']) ?>"
                                   placeholder="Kommentar">
                            <label style="display:flex;align-items:center;gap:4px;font-size:13px;white-space:nowrap">
                                <input type="date" class="edit-billed"
                                       value="<?= $e['billed_at'] ? substr($e['billed_at'], 0, 10) : '' ?>"
                                       style="width:auto">
                                Abgerechnet
                            </label>
                            <button type="button" class="btn btn--primary"
                                    onclick="saveEdit(<?= (int)$e['id'] ?>)">Speichern</button>
                            <button type="button" class="btn"
                                    onclick="hideEdit(<?= (int)$e['id'] ?>)">Abbrechen</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pager">
            <?php if ($page > 1): ?>
                <a href="<?= h(buildUrl(['page' => 1])) ?>">&laquo;</a>
                <a href="<?= h(buildUrl(['page' => $page - 1])) ?>">&lsaquo;</a>
            <?php endif; ?>

            <?php
            // Show at most 7 page links: always first, last, and 2 around current
            $shown = [];
            foreach ([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $totalPages] as $p) {
                if ($p >= 1 && $p <= $totalPages) $shown[$p] = true;
            }
            ksort($shown);
            $prev = null;
            foreach (array_keys($shown) as $p):
                if ($prev !== null && $p - $prev > 1):
            ?>
                <span class="dots">…</span>
            <?php
                endif;
                if ($p === $page):
            ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= h(buildUrl(['page' => $p])) ?>"><?= $p ?></a>
            <?php
                endif;
                $prev = $p;
            endforeach;
            ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= h(buildUrl(['page' => $page + 1])) ?>">&rsaquo;</a>
                <a href="<?= h(buildUrl(['page' => $totalPages])) ?>">&raquo;</a>
            <?php endif; ?>

            <span class="pager-info">
                <?= (($page - 1) * $perPage + 1) ?>–<?= min($page * $perPage, $total) ?>
                von <?= number_format($total, 0, ',', '.') ?>
            </span>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- Abgerechnet setzen -->
    <div class="admin-section hidden" id="billSection">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <h2 style="margin:0">Abgerechnet setzen</h2>
            <button type="button" class="btn" onclick="hideBill()">&#8592; Zurück zur Liste</button>
        </div>

        <div style="max-width:400px">
            <div style="margin-bottom:12px">
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">Kunde *</label>
                <select id="billCustomer" style="width:100%">
                    <option value="">— Kunde wählen —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:16px">
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">Abgerechnet bis (einschließlich) *</label>
                <input type="date" id="billDate" style="width:100%">
            </div>

            <div id="billMsg" style="margin-bottom:12px"></div>

            <button type="button" class="btn btn--primary" id="billBtn" onclick="doBill()">Als abgerechnet markieren</button>
        </div>
    </div>

    <!-- Import section -->
    <div class="admin-section hidden" id="importSection">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <h2 style="margin:0">Arbeitszeit importieren</h2>
            <button type="button" class="btn" onclick="hideImport()">&#8592; Zurück zur Liste</button>
        </div>

        <div style="margin-bottom:12px;max-width:240px">
            <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">Benutzer *</label>
            <select id="importUser">
                <?php foreach ($importUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-bottom:4px;font-size:12px;color:var(--text-muted)">
            Daten einfügen (tabulator-getrennt, eine Zeile pro Eintrag):
            <code style="background:#f0f0f0;padding:1px 5px;border-radius:3px;font-size:11px">
                Datum&nbsp;&nbsp;Zeitraum&nbsp;&nbsp;Minuten&nbsp;&nbsp;Kundenname&nbsp;&nbsp;Kommentar
            </code>
        </div>
        <div style="margin-bottom:4px;font-size:11px;color:var(--text-muted)">
            Beispiel: <code style="background:#f0f0f0;padding:1px 5px;border-radius:3px">15.04.2026&nbsp;&nbsp;10:51-11:56&nbsp;&nbsp;65&nbsp;&nbsp;scharferladen&nbsp;&nbsp;PHP Programmierung: Steuerberechnung</code>
        </div>

        <textarea id="importData" rows="14"
                  style="width:100%;font-family:monospace;font-size:12px;padding:8px;border:1px solid #e0e0e0;border-radius:4px;resize:vertical;margin-bottom:12px;color:var(--text);background:#fff"
                  placeholder="15.04.2026&#9;10:51-11:56&#9;65&#9;scharferladen&#9;PHP Programmierung: ..."></textarea>

        <div id="importMsg" style="margin-bottom:12px"></div>

        <button type="button" class="btn btn--primary" id="importBtn" onclick="doImport()">Jetzt importieren</button>
    </div>

</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

async function api(action, params) {
    const body = new URLSearchParams({ action, ...params });
    const res  = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body
    });
    return res.json();
}

function showEdit(id) {
    document.getElementById('row-'  + id).classList.add('hidden');
    document.getElementById('edit-' + id).classList.remove('hidden');
}

function hideEdit(id) {
    document.getElementById('edit-' + id).classList.add('hidden');
    document.getElementById('row-'  + id).classList.remove('hidden');
}

async function saveEdit(id) {
    const editRow    = document.getElementById('edit-' + id);
    const start      = editRow.querySelector('.edit-start').value.trim();
    const end        = editRow.querySelector('.edit-end').value.trim();
    const comment    = editRow.querySelector('.edit-comment').value.trim();
    const customerId = editRow.querySelector('.edit-customer').value;
    const activity   = editRow.querySelector('.edit-activity').value.trim();
    const project    = editRow.querySelector('.edit-project').value.trim();
    const billed     = editRow.querySelector('.edit-billed').value;

    const res = await api('update_entry', { id, start_datetime: start, end_datetime: end, comment, customer_id: customerId, activity, project, billed_at: billed });
    if (res.success) {
        location.reload();
    } else {
        alert('Fehler beim Speichern: ' + (res.error || 'Unbekannter Fehler'));
    }
}

function showDeleteConfirm(id) {
    document.getElementById('actions-'         + id).classList.add('hidden');
    document.getElementById('actions-confirm-' + id).classList.remove('hidden');
}

function cancelDelete(id) {
    document.getElementById('actions-confirm-' + id).classList.add('hidden');
    document.getElementById('actions-'         + id).classList.remove('hidden');
}

/* ================================================================
   ABGERECHNET SETZEN
   ================================================================ */
function showBill() {
    document.getElementById('listSection').classList.add('hidden');
    document.getElementById('importSection').classList.add('hidden');
    document.getElementById('billSection').classList.remove('hidden');
    document.getElementById('btnShowImport').classList.add('hidden');
    document.getElementById('btnShowBill').classList.add('hidden');
}

function hideBill() {
    document.getElementById('billSection').classList.add('hidden');
    document.getElementById('listSection').classList.remove('hidden');
    document.getElementById('btnShowImport').classList.remove('hidden');
    document.getElementById('btnShowBill').classList.remove('hidden');
    document.getElementById('billMsg').innerHTML = '';
}

async function doBill() {
    const customerId = document.getElementById('billCustomer').value;
    const cutoffDate = document.getElementById('billDate').value;
    const msgEl      = document.getElementById('billMsg');

    msgEl.innerHTML = '';

    if (!customerId) {
        msgEl.innerHTML = '<div class="admin-msg admin-msg--err">Bitte einen Kunden wählen.</div>';
        return;
    }
    if (!cutoffDate) {
        msgEl.innerHTML = '<div class="admin-msg admin-msg--err">Bitte ein Datum eingeben.</div>';
        return;
    }

    const btn = document.getElementById('billBtn');
    btn.disabled = true;

    try {
        const data = await api('set_billed_until', { customer_id: customerId, cutoff_date: cutoffDate });
        if (data.success) {
            const n = data.data.marked;
            msgEl.innerHTML = n > 0
                ? '<div class="admin-msg admin-msg--ok">' + n + ' Eintr&auml;ge als abgerechnet markiert.</div>'
                : '<div class="admin-msg admin-msg--err">Keine offenen Eintr&auml;ge bis zu diesem Datum gefunden.</div>';
            document.getElementById('billCustomer').value = '';
            document.getElementById('billDate').value = '';
        } else {
            msgEl.innerHTML = '<div class="admin-msg admin-msg--err">' + escHtml(data.error || 'Fehler.') + '</div>';
        }
    } catch(e) {
        msgEl.innerHTML = '<div class="admin-msg admin-msg--err">Serverfehler.</div>';
    }

    btn.disabled = false;
}

/* ================================================================
   IMPORT
   ================================================================ */
function showImport() {
    document.getElementById('listSection').classList.add('hidden');
    document.getElementById('billSection').classList.add('hidden');
    document.getElementById('importSection').classList.remove('hidden');
    document.getElementById('btnShowImport').classList.add('hidden');
    document.getElementById('btnShowBill').classList.add('hidden');
}

function hideImport() {
    document.getElementById('importSection').classList.add('hidden');
    document.getElementById('listSection').classList.remove('hidden');
    document.getElementById('btnShowImport').classList.remove('hidden');
    document.getElementById('btnShowBill').classList.remove('hidden');
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function doImport() {
    const userId  = document.getElementById('importUser').value;
    const rawData = document.getElementById('importData').value;
    const msgEl   = document.getElementById('importMsg');

    msgEl.innerHTML = '';

    if (!rawData.trim()) {
        msgEl.innerHTML = '<div class="admin-msg admin-msg--err">Bitte Daten eingeben.</div>';
        return;
    }

    const btn = document.getElementById('importBtn');
    btn.disabled = true;

    try {
        const data = await api('import_entries', { user_id: userId, raw_data: rawData });
        if (data.success) {
            let html = '';
            if (data.data.imported > 0) {
                html += '<div class="admin-msg admin-msg--ok">' + data.data.imported + ' Eintr&auml;ge erfolgreich importiert.</div>';
                document.getElementById('importData').value = '';
            }
            if (data.data.errors && data.data.errors.length > 0) {
                html += '<div class="admin-msg admin-msg--err"><strong>' + data.data.errors.length + ' Fehler:</strong><ul style="margin:6px 0 0 16px;line-height:1.7">';
                data.data.errors.forEach(function(e) { html += '<li>' + escHtml(e) + '</li>'; });
                html += '</ul></div>';
            }
            if (data.data.imported === 0 && (!data.data.errors || data.data.errors.length === 0)) {
                html = '<div class="admin-msg admin-msg--err">Keine Eintr&auml;ge importiert. Bitte Daten prüfen.</div>';
            }
            msgEl.innerHTML = html;
        } else {
            msgEl.innerHTML = '<div class="admin-msg admin-msg--err">' + escHtml(data.error || 'Fehler beim Import.') + '</div>';
        }
    } catch(e) {
        msgEl.innerHTML = '<div class="admin-msg admin-msg--err">Serverfehler. Bitte erneut versuchen.</div>';
    }

    btn.disabled = false;
}

async function resetBilling(id) {
    const res = await api('reset_entry_billing', { id });
    if (res.success) {
        const cell = document.getElementById('billed-cell-' + id);
        if (cell) cell.innerHTML = '<span style="color:var(--text-muted)">—</span>';
    } else {
        alert('Fehler: ' + (res.error || 'Unbekannter Fehler'));
    }
}

async function confirmDelete(id) {
    const res = await api('delete_entry', { id });
    if (res.success) {
        const row     = document.getElementById('row-'  + id);
        const editRow = document.getElementById('edit-' + id);
        if (row)     row.remove();
        if (editRow) editRow.remove();
    } else {
        alert('Fehler: ' + (res.error || 'Unbekannter Fehler'));
        cancelDelete(id);
    }
}
</script>
</body>
</html>
