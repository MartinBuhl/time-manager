<?php
require_once __DIR__ . '/auth.php';

// ---- Filter & Pagination params ----------------------------------------
$search     = trim($_GET['q'] ?? '');
$exclude    = trim($_GET['exclude'] ?? '');
$excludeTerms = array_values(array_filter(
    array_map('trim', explode(',', $exclude)),
    fn($t) => $t !== ''
));
$customerId = filter_var($_GET['customer_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$filterProject = trim($_GET['project'] ?? '');
$dateFrom   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$dateTo     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : '';
$status     = in_array($_GET['status'] ?? '', ['billed', 'open'], true) ? $_GET['status'] : '';
$sort       = ($_GET['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$perPage    = in_array((int)($_GET['per_page'] ?? 50), [50, 100, 500, 1000])
              ? (int)($_GET['per_page'] ?? 50) : 50;
$page       = max(1, (int)($_GET['page'] ?? 1));

$pdo = db();

// ---- Customers for filter select ----------------------------------------
$customers = $pdo->query(
    'SELECT id, name, projects FROM tm_customers ORDER BY name ASC'
)->fetchAll();

// Map customerId -> [Projektnamen] für die Massen-Projektzuweisung
$customerProjects = [];
foreach ($customers as $c) {
    $projs = json_decode($c['projects'] ?? '[]', true);
    $names = [];
    if (is_array($projs)) {
        foreach ($projs as $p) {
            $n = trim($p['name'] ?? '');
            if ($n !== '') $names[] = $n;
        }
    }
    $customerProjects[(int)$c['id']] = $names;
}

// Tatsächlich in den Einträgen vorkommende Projekte je Kunde (für das Filter-Dropdown)
$entryProjects = [];
foreach ($pdo->query(
    "SELECT DISTINCT customer_id, project FROM tm_entries
     WHERE deleted_at IS NULL AND project IS NOT NULL AND project <> ''
     ORDER BY project"
)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $entryProjects[(int)$row['customer_id']][] = $row['project'];
}

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
foreach ($excludeTerms as $term) {
    $where[]  = '(e.comment IS NULL OR e.comment NOT LIKE ?)';
    $params[] = '%' . $term . '%';
}
if ($customerId > 0) {
    $where[]  = 'e.customer_id = ?';
    $params[] = $customerId;
}
if ($filterProject !== '') {
    $where[]  = 'e.project = ?';
    $params[] = $filterProject;
}
if ($dateFrom !== '') {
    $where[]  = 'e.date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[]  = 'e.date <= ?';
    $params[] = $dateTo;
}
if ($status === 'billed') {
    $where[] = 'e.billed_at IS NOT NULL';
} elseif ($status === 'open') {
    $where[] = 'e.billed_at IS NULL';
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
        'exclude'     => $_GET['exclude']     ?? '',
        'customer_id' => $_GET['customer_id'] ?? '',
        'project'     => $_GET['project']     ?? '',
        'date_from'   => $_GET['date_from']   ?? '',
        'date_to'     => $_GET['date_to']     ?? '',
        'status'      => $_GET['status']       ?? '',
        'sort'        => $_GET['sort']         ?? 'desc',
        'per_page'    => $_GET['per_page']     ?? '50',
        'page'        => $_GET['page']         ?? '1',
    ];
    $p = array_merge($base, $overrides);
    // strip empty / default values to keep URLs clean
    if ($p['q']           === '')    unset($p['q']);
    if ($p['exclude']     === '')    unset($p['exclude']);
    if ($p['customer_id'] === '' || $p['customer_id'] === '0') unset($p['customer_id']);
    if ($p['project']     === '')    unset($p['project']);
    if ($p['date_from']   === '')    unset($p['date_from']);
    if ($p['date_to']     === '')    unset($p['date_to']);
    if ($p['status']      === '')    unset($p['status']);
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
<script src="../assets/dialog.js"></script>
<style>
.filter-bar {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 16px;
}
.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 12px;
    align-items: flex-end;
}
.filter-field {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.filter-field > label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .03em;
}
.filter-field--search { flex: 1 1 220px; min-width: 180px; }
.filter-field--search input { width: 100%; }
.filter-bar input[type="date"] { width: auto; }
.filter-bar select { min-width: 150px; }
.filter-dates {
    display: flex;
    align-items: center;
    gap: 6px;
}
.filter-sep { font-size: 13px; color: var(--text-muted); white-space: nowrap; }
.filter-actions {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}
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
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    text-decoration: none;
    color: #334155;
    background: #f1f5f9;
    line-height: 1.4;
}
.pager a:hover      { background: #e2e8f0; }
.pager span.current { background: var(--primary, #0078d4); color: #fff; border-color: var(--primary, #0078d4); font-weight: 600; }
.pager span.dots    { border: none; background: transparent; padding: 4px 4px; color: var(--text-muted); }
.pager-info         { margin-left: auto; font-size: 12px; color: var(--text-muted); }
.col-user           { white-space: nowrap; font-size: 12px; color: var(--text-muted); }
.col-project        { font-size: 11px; }
.col-check          { width: 28px; text-align: center; }
.col-check input    { cursor: pointer; }
.bulk-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-top: 14px;
    padding: 12px 14px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 13px;
}
.bulk-bar select { min-width: 170px; }
.bulk-note { font-size: 12px; color: #b45309; }
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
            <!-- Zeile 1: Kunde, Status, Zeitraum, Sortierung … Pro Seite + Aktionen -->
            <div class="filter-row">
                <div class="filter-field">
                    <label for="f-customer">Kunde</label>
                    <select id="f-customer" name="customer_id">
                        <option value="">Alle Kunden</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                            <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= h($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="f-project">Projekt</label>
                    <select id="f-project" name="project">
                        <option value="">Alle Projekte</option>
                        <?php if ($customerId > 0): foreach (($entryProjects[$customerId] ?? []) as $p): ?>
                        <option value="<?= h($p) ?>"<?= $filterProject === $p ? ' selected' : '' ?>><?= h($p) ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="f-status">Status</label>
                    <select id="f-status" name="status">
                        <option value="">Alle Status</option>
                        <option value="open"   <?= $status === 'open'   ? 'selected' : '' ?>>Nicht abgerechnet</option>
                        <option value="billed" <?= $status === 'billed' ? 'selected' : '' ?>>Abgerechnet</option>
                    </select>
                </div>

                <div class="filter-field">
                    <label>Zeitraum</label>
                    <div class="filter-dates">
                        <input type="date" name="date_from" value="<?= h($dateFrom) ?>" aria-label="Von">
                        <span class="filter-sep">–</span>
                        <input type="date" name="date_to" value="<?= h($dateTo) ?>" aria-label="Bis">
                    </div>
                </div>

                <div class="filter-field">
                    <label for="f-sort">Sortierung</label>
                    <select id="f-sort" name="sort">
                        <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Neueste zuerst</option>
                        <option value="asc"  <?= $sort === 'asc'  ? 'selected' : '' ?>>Älteste zuerst</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <div class="filter-field">
                        <label for="f-perpage">Pro Seite</label>
                        <select id="f-perpage" name="per_page">
                            <?php foreach ([50, 100, 500, 1000] as $n): ?>
                            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn--primary" type="submit">Filtern</button>
                    <?php if ($search !== '' || $exclude !== '' || $customerId > 0 || $filterProject !== '' || $dateFrom !== '' || $dateTo !== '' || $status !== ''): ?>
                    <a href="entries.php" class="btn">Zurücksetzen</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Zeile 2: Suche + Ausschluss -->
            <div class="filter-row">
                <div class="filter-field filter-field--search">
                    <label for="f-q">Suche (Kommentar)</label>
                    <input type="text" id="f-q" name="q"
                           value="<?= h($search) ?>"
                           placeholder="Kommentar suchen…"
                           autocomplete="off">
                </div>
                <div class="filter-field filter-field--search">
                    <label for="f-exclude">Ausschließen (Kommentar, kommagetrennt)</label>
                    <input type="text" id="f-exclude" name="exclude"
                           value="<?= h($exclude) ?>"
                           placeholder="z. B. urlaub, intern, pause"
                           autocomplete="off">
                </div>
            </div>
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
                        <th class="col-check"><input type="checkbox" id="checkAll" title="Alle auswählen"></th>
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
                    <td class="col-check">
                        <input type="checkbox" class="row-check"
                               data-id="<?= (int)$e['id'] ?>"
                               data-customer-id="<?= (int)$e['customer_id'] ?>">
                    </td>
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
                    <td colspan="11">
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

        <!-- Massen-Aktionen für angehakte Einträge -->
        <div id="bulkBar" class="bulk-bar" style="display:none">
            <span><strong id="bulkCount">0</strong>&nbsp;ausgewählt</span>
            <select id="bulkAction">
                <option value="">— Aktion wählen —</option>
                <option value="assign_project">Projekt zuweisen</option>
                <option value="assign_customer">Anderem Kunden zuweisen</option>
                <option value="change_billed">Abrechnungs-Status ändern</option>
            </select>
            <span id="bulkProjectWrap" style="display:none;align-items:center;gap:10px">
                <select id="bulkProject"></select>
                <span id="bulkProjectNote" class="bulk-note"></span>
            </span>
            <span id="bulkCustomerWrap" style="display:none;align-items:center;gap:10px">
                <select id="bulkCustomer">
                    <option value="">— Kunde wählen —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <span id="bulkBilledWrap" style="display:none;align-items:center;gap:10px">
                <select id="bulkBilled">
                    <option value="">— Status wählen —</option>
                    <option value="open">Nicht abgerechnet</option>
                    <option value="billed">Abgerechnet</option>
                </select>
            </span>
            <button type="button" class="btn btn--primary" id="bulkSaveBtn" style="display:none">Speichern</button>
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
const CUSTOMER_PROJECTS = <?= json_encode($customerProjects, JSON_UNESCAPED_UNICODE) ?>;
const ENTRY_PROJECTS    = <?= json_encode($entryProjects, JSON_UNESCAPED_UNICODE) ?>;

// Projekt-Filter abhängig vom gewählten Kunden aktualisieren
(function() {
    const custSel = document.getElementById('f-customer');
    const projSel = document.getElementById('f-project');
    if (!custSel || !projSel) return;
    custSel.addEventListener('change', function() {
        const projects = ENTRY_PROJECTS[this.value] || [];
        const current  = projSel.value;
        projSel.innerHTML = '<option value="">Alle Projekte</option>';
        projects.forEach(function(p) {
            const o = document.createElement('option');
            o.value = p; o.textContent = p;
            if (p === current) o.selected = true;
            projSel.appendChild(o);
        });
    });
})();

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
        Dialog.alert('Fehler beim Speichern: ' + (res.error || 'Unbekannter Fehler'));
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
        Dialog.alert('Fehler: ' + (res.error || 'Unbekannter Fehler'));
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
        Dialog.alert('Fehler: ' + (res.error || 'Unbekannter Fehler'));
        cancelDelete(id);
    }
}

/* ================================================================
   MASSEN-AKTIONEN (angehakte Einträge)
   ================================================================ */
function selectedChecks() {
    return Array.from(document.querySelectorAll('.row-check:checked'));
}

function updateBulkBar() {
    const checked = selectedChecks();
    const bar     = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = checked.length;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';

    // "Alle"-Checkbox-Status angleichen
    const all = document.querySelectorAll('.row-check');
    const checkAll = document.getElementById('checkAll');
    checkAll.checked = all.length > 0 && checked.length === all.length;
    checkAll.indeterminate = checked.length > 0 && checked.length < all.length;

    if (document.getElementById('bulkAction').value === 'assign_project') {
        populateProjectSelect();
    }
}

function populateProjectSelect() {
    const checked  = selectedChecks();
    const sel      = document.getElementById('bulkProject');
    const note     = document.getElementById('bulkProjectNote');
    const saveBtn  = document.getElementById('bulkSaveBtn');
    const custIds  = [...new Set(checked.map(cb => cb.dataset.customerId))];

    sel.innerHTML = '';
    note.textContent = '';
    saveBtn.style.display = 'none';

    if (checked.length === 0) { sel.style.display = 'none'; return; }

    if (custIds.length > 1) {
        sel.style.display = 'none';
        note.textContent  = 'Bitte nur Einträge eines Kunden auswählen.';
        return;
    }
    if (custIds[0] === '0' || custIds[0] === '' || custIds[0] === undefined) {
        sel.style.display = 'none';
        note.textContent  = 'Die ausgewählten Einträge haben keinen Kunden.';
        return;
    }

    const projects = CUSTOMER_PROJECTS[custIds[0]] || [];
    if (projects.length === 0) {
        sel.style.display = 'none';
        note.textContent  = 'Für diesen Kunden sind keine Projekte hinterlegt.';
        return;
    }

    sel.style.display = '';
    const ph = document.createElement('option');
    ph.value = ''; ph.textContent = '— Projekt wählen —';
    sel.appendChild(ph);
    projects.forEach(function(p) {
        const o = document.createElement('option');
        o.value = p; o.textContent = p;
        sel.appendChild(o);
    });
}

// Listener nur registrieren, wenn die Liste (und damit die Elemente) existiert
if (document.getElementById('bulkBar')) {
    document.getElementById('checkAll').addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(cb => { cb.checked = this.checked; });
        updateBulkBar();
    });

    document.querySelector('.entries-table tbody').addEventListener('change', function(ev) {
        if (ev.target.classList.contains('row-check')) updateBulkBar();
    });

    document.getElementById('bulkAction').addEventListener('change', function() {
        const projWrap   = document.getElementById('bulkProjectWrap');
        const custWrap   = document.getElementById('bulkCustomerWrap');
        const billedWrap = document.getElementById('bulkBilledWrap');
        const saveBtn    = document.getElementById('bulkSaveBtn');
        projWrap.style.display   = 'none';
        custWrap.style.display   = 'none';
        billedWrap.style.display = 'none';
        saveBtn.style.display    = 'none';

        if (this.value === 'assign_project') {
            projWrap.style.display = 'inline-flex';
            populateProjectSelect();
        } else if (this.value === 'assign_customer') {
            custWrap.style.display = 'inline-flex';
            document.getElementById('bulkCustomer').value = '';
        } else if (this.value === 'change_billed') {
            billedWrap.style.display = 'inline-flex';
            document.getElementById('bulkBilled').value = '';
        }
    });

    document.getElementById('bulkProject').addEventListener('change', function() {
        document.getElementById('bulkSaveBtn').style.display = this.value ? '' : 'none';
    });

    document.getElementById('bulkCustomer').addEventListener('change', function() {
        document.getElementById('bulkSaveBtn').style.display = this.value ? '' : 'none';
    });

    document.getElementById('bulkBilled').addEventListener('change', function() {
        document.getElementById('bulkSaveBtn').style.display = this.value ? '' : 'none';
    });

    document.getElementById('bulkSaveBtn').addEventListener('click', async function() {
        const action = document.getElementById('bulkAction').value;
        const ids    = selectedChecks().map(cb => cb.dataset.id);
        if (ids.length === 0) return;

        let apiAction, params, confirmMsg;
        if (action === 'assign_project') {
            const project = document.getElementById('bulkProject').value;
            if (!project) return;
            apiAction  = 'bulk_assign_project';
            params     = { ids: ids.join(','), project };
            confirmMsg = ids.length + ' Eintrag/Einträgen das Projekt „' + project + '" zuweisen?';
        } else if (action === 'assign_customer') {
            const sel  = document.getElementById('bulkCustomer');
            const cid  = sel.value;
            if (!cid) return;
            apiAction  = 'bulk_assign_customer';
            params     = { ids: ids.join(','), customer_id: cid };
            confirmMsg = ids.length + ' Eintrag/Einträge dem Kunden „'
                       + sel.options[sel.selectedIndex].text + '" zuweisen?';
        } else if (action === 'change_billed') {
            const sel    = document.getElementById('bulkBilled');
            const status = sel.value;
            if (!status) return;
            apiAction  = 'bulk_set_billed';
            params     = { ids: ids.join(','), status };
            confirmMsg = ids.length + ' Eintrag/Einträge auf „'
                       + sel.options[sel.selectedIndex].text + '" setzen?';
        } else {
            return;
        }

        if (!await Dialog.confirm(confirmMsg)) return;

        this.disabled = true;
        const orig = this.textContent;
        this.textContent = 'Speichere…';
        const res = await api(apiAction, params);
        if (res.success) {
            location.reload();
        } else {
            Dialog.alert('Fehler: ' + (res.error || 'Unbekannter Fehler'));
            this.disabled = false;
            this.textContent = orig;
        }
    });
}
</script>
</body>
</html>
