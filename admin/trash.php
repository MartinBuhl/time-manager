<?php
require_once __DIR__ . '/auth.php';

$stmt = db()->query(
    "SELECT e.id, u.username, c.name AS customer_name, e.activity,
            e.comment, e.start_datetime, e.end_datetime, e.duration_minutes,
            e.deleted_at
     FROM tm_entries e
     JOIN tm_users u ON u.id = e.user_id
     LEFT JOIN tm_customers c ON c.id = e.customer_id
     WHERE e.deleted_at IS NOT NULL
     ORDER BY e.deleted_at DESC"
);
$entries = $stmt->fetchAll();

$deletedOrders = [];
try {
    $deletedOrders = db()->query(
        "SELECT o.id, o.created_at, o.deleted_at,
                COALESCE(c.name, '') AS customer_name
         FROM tm_orders o
         LEFT JOIN tm_customers c ON c.id = o.customer_id
         WHERE o.deleted_at IS NOT NULL
         ORDER BY o.deleted_at DESC"
    )->fetchAll();
} catch (Throwable $e) { /* Tabelle evtl. noch nicht vorhanden */ }

function fmtDur(int $min): string
{
    return sprintf('%d:%02d h', intdiv($min, 60), $min % 60);
}
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('trash.pageTitle')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<style>
.col-check       { width: 28px; text-align: center; }
.col-check input { cursor: pointer; }
.trash-bulk-bar {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    margin: 0 0 10px; padding: 8px 12px; border-radius: 8px;
    background: var(--hover-bg); border: 1px solid var(--card-border); font-size: 13px;
}
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1><?= h(t('admin.card.trash')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo; <?= h(t('admin.card.trash')) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="index.php" class="btn"><?= h(t('admin.back')) ?></a>
            <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
        </div>
    </div>

    <div class="admin-section">
        <h2><?= h(t('trash.deletedEntries')) ?></h2>
        <div id="trashMsg"></div>
        <?php if (!empty($entries)): ?>
        <div id="entryBulkBar" class="trash-bulk-bar" style="display:none">
            <span><strong id="entryBulkCount">0</strong>&nbsp;<?= h(t('adminEntries.selected')) ?></span>
            <button type="button" class="btn" onclick="restoreSelectedEntries()"><?= h(t('trash.restoreSelected')) ?></button>
            <button type="button" class="btn btn--danger" onclick="purgeSelectedEntries()"><?= h(t('trash.purgeSelected')) ?></button>
        </div>
        <?php endif; ?>
        <div class="table-wrapper">
            <table class="entries-table" id="trashTable">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="checkAllEntries" title="<?= h(t('customers.selectAll')) ?>"></th>
                        <th><?= h(t('users.roleMitarbeiter')) ?></th>
                        <th><?= h(t('customers.colDate')) ?></th>
                        <th><?= h(t('adminEntries.period')) ?></th>
                        <th><?= h(t('customers.colDuration')) ?></th>
                        <th><?= h(t('entries.colCustomer')) ?></th>
                        <th><?= h(t('common.activity')) ?></th>
                        <th><?= h(t('customers.colComment')) ?></th>
                        <th><?= h(t('trash.colDeletedAt')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $e): ?>
                    <tr id="trow-<?= (int)$e['id'] ?>">
                        <td class="col-check">
                            <input type="checkbox" class="trash-entry-check" data-id="<?= (int)$e['id'] ?>" onchange="updateEntryBulkBar()">
                        </td>
                        <td><?= h($e['username']) ?></td>
                        <td class="col-time"><?= h(date('d.m.Y', strtotime($e['start_datetime']))) ?></td>
                        <td class="col-time">
                            <?= h(date('H:i', strtotime($e['start_datetime']))) ?> –
                            <?= h(date('H:i', strtotime($e['end_datetime']))) ?>
                        </td>
                        <td class="col-dur"><?= h(fmtDur((int)$e['duration_minutes'])) ?></td>
                        <td><?= h($e['customer_name'] ?? '–') ?></td>
                        <td><?= h($e['activity']) ?></td>
                        <td class="comment"><?= h($e['comment'] ?? '') ?></td>
                        <td class="col-time"><?= h(date('d.m.Y H:i', strtotime($e['deleted_at']))) ?></td>
                        <td>
                            <button class="btn" onclick="restoreEntry(<?= (int)$e['id'] ?>)">
                                <?= h(t('trash.restore')) ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($entries)): ?>
                    <tr id="emptyRow"><td colspan="10" class="empty-message"><?= h(t('trash.emptyEntries')) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-section">
        <h2><?= h(t('trash.deletedOrders')) ?></h2>
        <div id="trashOrderMsg"></div>
        <?php if (!empty($deletedOrders)): ?>
        <div id="orderBulkBar" class="trash-bulk-bar" style="display:none">
            <span><strong id="orderBulkCount">0</strong>&nbsp;<?= h(t('adminEntries.selected')) ?></span>
            <button type="button" class="btn" onclick="restoreSelectedOrders()"><?= h(t('trash.restoreSelected')) ?></button>
            <button type="button" class="btn btn--danger" onclick="purgeSelectedOrders()"><?= h(t('trash.purgeSelected')) ?></button>
        </div>
        <?php endif; ?>
        <div class="table-wrapper">
            <table class="entries-table" id="trashOrdersTable">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="checkAllOrders" title="<?= h(t('customers.selectAll')) ?>"></th>
                        <th><?= h(t('entries.colCustomer')) ?></th>
                        <th><?= h(t('adminOrders.colCreated')) ?></th>
                        <th><?= h(t('trash.colDeletedAt')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deletedOrders as $o): ?>
                    <tr id="otrow-<?= (int)$o['id'] ?>">
                        <td class="col-check">
                            <input type="checkbox" class="trash-order-check" data-id="<?= (int)$o['id'] ?>" onchange="updateOrderBulkBar()">
                        </td>
                        <td><?= h($o['customer_name'] !== '' ? $o['customer_name'] : '—') ?></td>
                        <td class="col-time"><?= h(date('d.m.Y H:i', strtotime($o['created_at']))) ?></td>
                        <td class="col-time"><?= h(date('d.m.Y H:i', strtotime($o['deleted_at']))) ?></td>
                        <td style="white-space:nowrap;text-align:right">
                            <button class="btn" onclick="restoreOrder(<?= (int)$o['id'] ?>)"><?= h(t('trash.restore')) ?></button>
                            <button class="btn btn--danger" onclick="purgeOrder(<?= (int)$o['id'] ?>)"><?= h(t('trash.purge')) ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($deletedOrders)): ?>
                    <tr id="emptyOrderRow"><td colspan="5" class="empty-message"><?= h(t('trash.emptyOrders')) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="../assets/dialog.js"></script>
<script>
window.I18N = <?= json_encode(i18nStrings(), JSON_UNESCAPED_UNICODE) ?>;
window.LANG = <?= json_encode(currentLang()) ?>;
function t(key, params) {
    let s = (window.I18N && window.I18N[key]) || key;
    if (params) { for (const k in params) { s = s.split('{' + k + '}').join(params[k]); } }
    return s;
}
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

async function restoreEntry(id) {
    const msgEl = document.getElementById('trashMsg');
    try {
        const body = new URLSearchParams({ action: 'restore_entry', id });
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body
        });
        const data = await res.json();
        if (data.success) {
            const row = document.getElementById('trow-' + id);
            if (row) row.remove();
            const tbody = document.querySelector('#trashTable tbody');
            if (!tbody.querySelector('tr:not(#emptyRow), tr')) {
                const tr = document.createElement('tr');
                tr.id = 'emptyRow';
                tr.innerHTML = '<td colspan="9" class="empty-message">' + t('trash.emptyEntries') + '</td>';
                tbody.appendChild(tr);
            }
            msgEl.className = 'admin-msg admin-msg--ok';
            msgEl.textContent = t('trash.entryRestored');
        } else {
            msgEl.className = 'admin-msg admin-msg--err';
            msgEl.textContent = data.error || t('trash.restoreError');
        }
    } catch (e) {
        msgEl.className = 'admin-msg admin-msg--err';
        msgEl.textContent = t('config.serverErrorRetry');
    }
}

async function orderAction(action, id) {
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action, id }),
    });
    return res.json();
}

async function restoreOrder(id) {
    const msgEl = document.getElementById('trashOrderMsg');
    try {
        const data = await orderAction('restore_order', id);
        if (data.success) {
            const row = document.getElementById('otrow-' + id);
            if (row) row.remove();
            msgEl.className = 'admin-msg admin-msg--ok';
            msgEl.textContent = t('trash.orderRestored');
        } else {
            msgEl.className = 'admin-msg admin-msg--err';
            msgEl.textContent = data.error || t('trash.restoreError');
        }
    } catch (e) {
        msgEl.className = 'admin-msg admin-msg--err';
        msgEl.textContent = t('config.serverErrorRetry');
    }
}

async function purgeOrder(id) {
    const msgEl = document.getElementById('trashOrderMsg');
    if (!await Dialog.confirm(t('trash.confirmPurge'), { danger: true })) return;
    try {
        const data = await orderAction('purge_order', id);
        if (data.success) {
            const row = document.getElementById('otrow-' + id);
            if (row) row.remove();
            msgEl.className = 'admin-msg admin-msg--ok';
            msgEl.textContent = t('trash.orderPurged');
        } else {
            msgEl.className = 'admin-msg admin-msg--err';
            msgEl.textContent = data.error || t('customers.deleteError');
        }
    } catch (e) {
        msgEl.className = 'admin-msg admin-msg--err';
        msgEl.textContent = t('config.serverErrorRetry');
    }
}

/* ------------------------------------------------------------------
   Mehrfachauswahl – "Alle" anhaken, Sammel-Wiederherstellen/-Löschen
------------------------------------------------------------------ */
async function bulkPost(action, ids) {
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action, ids: ids.join(',') }),
    });
    return res.json();
}

function checkedIds(cls) {
    return Array.from(document.querySelectorAll('.' + cls + ':checked')).map(cb => cb.dataset.id);
}

function refreshBulkBar(boxCls, allId, barId, countId) {
    const boxes   = Array.from(document.querySelectorAll('.' + boxCls));
    const checked = boxes.filter(cb => cb.checked);
    const bar     = document.getElementById(barId);
    if (bar) {
        const cnt = document.getElementById(countId);
        if (cnt) cnt.textContent = checked.length;
        bar.style.display = checked.length ? 'flex' : 'none';
    }
    const all = document.getElementById(allId);
    if (all) {
        all.checked       = boxes.length > 0 && checked.length === boxes.length;
        all.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }
}

function updateEntryBulkBar() { refreshBulkBar('trash-entry-check', 'checkAllEntries', 'entryBulkBar', 'entryBulkCount'); }
function updateOrderBulkBar() { refreshBulkBar('trash-order-check', 'checkAllOrders', 'orderBulkBar', 'orderBulkCount'); }

document.getElementById('checkAllEntries')?.addEventListener('change', function () {
    document.querySelectorAll('.trash-entry-check').forEach(cb => { cb.checked = this.checked; });
    updateEntryBulkBar();
});
document.getElementById('checkAllOrders')?.addEventListener('change', function () {
    document.querySelectorAll('.trash-order-check').forEach(cb => { cb.checked = this.checked; });
    updateOrderBulkBar();
});

function removeRows(ids, rowPrefix) {
    ids.forEach(id => document.getElementById(rowPrefix + id)?.remove());
}

function setMsg(elId, ok, text) {
    const el = document.getElementById(elId);
    if (!el) return;
    el.className = 'admin-msg ' + (ok ? 'admin-msg--ok' : 'admin-msg--err');
    el.textContent = text;
}

async function restoreSelectedEntries() {
    const ids = checkedIds('trash-entry-check');
    if (!ids.length) return;
    const res = await bulkPost('restore_entries', ids);
    if (res.success) { removeRows(ids, 'trow-'); updateEntryBulkBar(); setMsg('trashMsg', true, t('trash.selectedRestored')); }
    else setMsg('trashMsg', false, res.error || t('trash.restoreError'));
}

async function purgeSelectedEntries() {
    const ids = checkedIds('trash-entry-check');
    if (!ids.length) return;
    if (!await Dialog.confirm(t('trash.confirmPurgeSelectedEntries', { count: ids.length }), { danger: true })) return;
    const res = await bulkPost('purge_entries', ids);
    if (res.success) { removeRows(ids, 'trow-'); updateEntryBulkBar(); setMsg('trashMsg', true, t('trash.selectedPurged')); }
    else setMsg('trashMsg', false, res.error || t('customers.deleteError'));
}

async function restoreSelectedOrders() {
    const ids = checkedIds('trash-order-check');
    if (!ids.length) return;
    const res = await bulkPost('restore_orders', ids);
    if (res.success) { removeRows(ids, 'otrow-'); updateOrderBulkBar(); setMsg('trashOrderMsg', true, t('trash.selectedRestored')); }
    else setMsg('trashOrderMsg', false, res.error || t('trash.restoreError'));
}

async function purgeSelectedOrders() {
    const ids = checkedIds('trash-order-check');
    if (!ids.length) return;
    if (!await Dialog.confirm(t('trash.confirmPurgeSelectedOrders', { count: ids.length }), { danger: true })) return;
    const res = await bulkPost('purge_orders', ids);
    if (res.success) { removeRows(ids, 'otrow-'); updateOrderBulkBar(); setMsg('trashOrderMsg', true, t('trash.selectedPurged')); }
    else setMsg('trashOrderMsg', false, res.error || t('customers.deleteError'));
}
</script>
</body>
</html>
