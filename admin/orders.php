<?php
require_once __DIR__ . '/auth.php';

$customerFilter = filter_var($_GET['customer_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;

// Kunden mit (nicht geloeschten) Auftraegen – fuer den Filter
$customers = db()->query(
    "SELECT DISTINCT c.id, c.name
     FROM tm_orders o
     JOIN tm_customers c ON c.id = o.customer_id
     WHERE o.deleted_at IS NULL
     ORDER BY c.name ASC"
)->fetchAll();

$conditions = ['o.deleted_at IS NULL'];
$params     = [];
if ($customerFilter > 0) {
    $conditions[] = 'o.customer_id = ?';
    $params[]     = $customerFilter;
}
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = db()->prepare(
    "SELECT o.id, o.status, o.last_worked_date, o.created_at,
            COALESCE(c.name, '') AS customer_name,
            (SELECT COUNT(*) FROM tm_order_files f WHERE f.order_id = o.id) AS file_count
     FROM tm_orders o
     LEFT JOIN tm_customers c ON c.id = o.customer_id
     $where
     ORDER BY o.created_at DESC, o.id DESC"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

function fmtDt($dt): string { return $dt ? date('d.m.Y H:i', strtotime($dt)) : ''; }
function fmtD($dt): string  { return $dt ? date('d.m.Y', strtotime($dt)) : '—'; }
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('adminOrders.pageTitle')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; }
.filter-bar select { min-width: 160px; }
.ord-status { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; white-space:nowrap; }
.ord-status-offen    { background:#e0f2fe; color:#0369a1; }
.ord-status-erledigt { background:#dcfce7; color:#15803d; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1><?= h(t('admin.card.orders')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo; <?= h(t('admin.card.orders')) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="index.php" class="btn"><?= h(t('admin.back')) ?></a>
            <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
        </div>
    </div>

    <div class="admin-section">

        <form method="get" action="orders.php" class="filter-bar">
            <select name="customer_id" onchange="this.form.submit()">
                <option value=""><?= h(t('customers.allCustomers')) ?></option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $customerFilter === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= h($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($customerFilter > 0): ?>
            <a href="orders.php" class="btn"><?= h(t('adminEntries.reset')) ?></a>
            <?php endif; ?>
        </form>

        <?php if (empty($orders)): ?>
            <p class="empty-message"><?= h(t('adminOrders.empty')) ?></p>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th><?= h(t('entries.colCustomer')) ?></th>
                        <th><?= h(t('adminOrders.colCreated')) ?></th>
                        <th><?= h(t('customers.colStatus')) ?></th>
                        <th><?= h(t('adminOrders.colLastWorked')) ?></th>
                        <th class="col-dur"><?= h(t('invoices.colFiles')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr id="orow-<?= (int)$o['id'] ?>">
                        <td><?= h($o['customer_name'] !== '' ? $o['customer_name'] : '—') ?></td>
                        <td style="white-space:nowrap"><?= h(fmtDt($o['created_at'])) ?></td>
                        <td>
                            <span class="ord-status ord-status-<?= h($o['status']) ?>">
                                <?= h($o['status'] === 'erledigt' ? t('orders.markDone') : t('adminOrders.statusOffen')) ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap"><?= h(fmtD($o['last_worked_date'])) ?></td>
                        <td class="col-dur"><?= (int)$o['file_count'] ?></td>
                        <td style="white-space:nowrap;text-align:right">
                            <button type="button" class="btn" onclick="openOrder(<?= (int)$o['id'] ?>)"><?= h(t('common.edit')) ?></button>
                            <button type="button" class="btn btn--danger" onclick="deleteOrder(<?= (int)$o['id'] ?>)"><?= h(t('common.delete')) ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- ---- Bearbeiten-Overlay ------------------------------------- -->
<div id="orderView" class="settings-view hidden">
    <div class="settings-inner">
        <div class="settings-topbar">
            <strong id="orderViewTitle"><?= h(t('adminOrders.orderTitle')) ?></strong>
            <button type="button" class="btn" id="orderViewClose"><?= h(t('orders.close')) ?></button>
        </div>
        <div class="order-view-meta" id="orderViewMeta"></div>

        <div class="rte-wrap" style="margin-top:10px">
            <div class="rte-toolbar">
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('bold')"><b>B</b></button>
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('italic')"><em>I</em></button>
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('underline')"><u>U</u></button>
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('insertUnorderedList')"><?= t('orders.rteList') ?></button>
                <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('removeFormat')" title="<?= h(t('orders.removeFormat')) ?>">&#10005;</button>
            </div>
            <div class="rte-body" id="orderViewBody" contenteditable="true"></div>
        </div>

        <div class="order-view-files" id="orderViewFiles"></div>

        <div class="order-upload" style="margin-top:10px">
            <input type="file" id="orderViewNewFiles" multiple
                   accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.heic,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv">
            <span class="order-hint"><?= h(t('orders.attachMore')) ?></span>
        </div>

        <div class="order-view-actions">
            <button type="button" class="btn btn--primary" id="orderSaveEditBtn"><?= h(t('common.save')) ?></button>
            <button type="button" class="btn" id="orderCompleteBtn"><?= h(t('orders.markDone')) ?></button>
            <span id="orderViewMsg" class="order-msg"></span>
        </div>
    </div>
</div>

<script>
window.I18N = <?= json_encode(i18nStrings(), JSON_UNESCAPED_UNICODE) ?>;
window.LANG = <?= json_encode(currentLang()) ?>;
function t(key, params) {
    let s = (window.I18N && window.I18N[key]) || key;
    if (params) { for (const k in params) { s = s.split('{' + k + '}').join(params[k]); } }
    return s;
}
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
let currentOrderId = null;

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* App-Endpunkte (../api.php) – dieselbe Session/CSRF */
async function appApi(action, data = {}) {
    const res = await fetch('../api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action, ...data }),
    });
    return res.json();
}
async function appApiForm(action, formData) {
    formData.append('action', action);
    const res = await fetch('../api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: formData });
    return res.json();
}
/* Admin-Endpunkte (api.php) */
async function adminApi(action, data = {}) {
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action, ...data }),
    });
    return res.json();
}

function fmtCreated(dt) {
    if (!dt) return '';
    const [datePart, timePart] = dt.split(' ');
    const [y, m, d] = datePart.split('-');
    return d + '.' + m + '.' + y + (timePart ? ' ' + timePart.slice(0, 5) : '');
}

function renderOrderFiles(files) {
    const wrap = document.getElementById('orderViewFiles');
    if (!files || !files.length) { wrap.innerHTML = '<span class="order-hint">' + escHtml(t('orders.noFiles')) + '</span>'; return; }
    wrap.innerHTML = files.map(f =>
        '<div class="order-file-item">'
        + '<a href="../order_file.php?id=' + f.id + '" target="_blank" rel="noopener">' + escHtml(f.original_name) + '</a>'
        + '<button type="button" class="order-file-del" title="' + escHtml(t('orders.deleteFileTitle')) + '" onclick="deleteOrderFile(' + f.id + ')">&times;</button>'
        + '</div>'
    ).join('');
}

async function openOrder(id) {
    const res = await appApi('get_order', { id });
    if (!res.success) { Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError'))); return; }
    const o = res.data;
    currentOrderId = o.id;
    document.getElementById('orderViewTitle').textContent = o.customer_name || t('adminOrders.orderTitle');
    document.getElementById('orderViewMeta').textContent  = t('adminOrders.colCreated') + ': ' + fmtCreated(o.created_at)
        + (o.status === 'erledigt' ? t('adminOrders.doneSuffix') : '');
    document.getElementById('orderViewBody').innerHTML     = o.body || '';
    renderOrderFiles(o.files || []);
    const msg = document.getElementById('orderViewMsg');
    msg.textContent = ''; msg.className = 'order-msg';
    document.getElementById('orderViewNewFiles').value = '';
    document.getElementById('orderView').classList.remove('hidden');
}

function closeOrderView() {
    document.getElementById('orderView').classList.add('hidden');
    currentOrderId = null;
}

async function deleteOrderFile(fileId) {
    if (!await Dialog.confirm(t('orders.confirmDeleteFile'), { danger: true })) return;
    const res = await appApi('delete_order_file', { id: fileId });
    if (res.success && currentOrderId) openOrder(currentOrderId);
    else if (!res.success) Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
}

async function deleteOrder(id) {
    if (!await Dialog.confirm(t('adminOrders.confirmTrash'), { danger: true })) return;
    const res = await adminApi('delete_order', { id });
    if (res.success) {
        const row = document.getElementById('orow-' + id);
        if (row) row.remove();
    } else {
        Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
    }
}

document.getElementById('orderViewClose').addEventListener('click', closeOrderView);

document.getElementById('orderSaveEditBtn').addEventListener('click', async () => {
    if (!currentOrderId) return;
    const btn = document.getElementById('orderSaveEditBtn');
    const msg = document.getElementById('orderViewMsg');
    btn.disabled = true; msg.className = 'order-msg'; msg.textContent = t('common.saving');

    const fd = new FormData();
    fd.append('id', currentOrderId);
    fd.append('body', document.getElementById('orderViewBody').innerHTML);
    for (const f of document.getElementById('orderViewNewFiles').files) fd.append('files[]', f);

    const res = await appApiForm('update_order', fd);
    btn.disabled = false;
    if (res.success) {
        await openOrder(currentOrderId);
        msg.textContent = t('common.saved'); msg.classList.add('ok');
    } else {
        msg.textContent = res.error || t('common.error'); msg.classList.add('err');
    }
});

document.getElementById('orderCompleteBtn').addEventListener('click', async () => {
    if (!currentOrderId) return;
    if (!await Dialog.confirm(t('orders.confirmDone'))) return;
    const res = await appApi('complete_order', { id: currentOrderId });
    if (res.success) location.reload();
    else Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
});
</script>
</body>
</html>
