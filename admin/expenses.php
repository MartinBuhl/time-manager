<?php
require_once __DIR__ . '/auth.php';

$db = db();
$expenses = $db->query('SELECT * FROM tm_expenses ORDER BY title ASC, id ASC')->fetchAll();

// Monatssummen je Währung + Art (Tag*365/12, Monat*1, Jahr/12)
$totals = []; // [currency => ['business'=>x, 'private'=>y, 'total'=>z]]
foreach ($db->query(
    "SELECT currency, scope,
            SUM(CASE period WHEN 'day' THEN amount*365/12 WHEN 'year' THEN amount/12 ELSE amount END) AS monthly
     FROM tm_expenses WHERE active = 1 GROUP BY currency, scope"
) as $t) {
    $c = $t['currency'];
    if (!isset($totals[$c])) $totals[$c] = ['business' => 0.0, 'private' => 0.0, 'total' => 0.0];
    $totals[$c][$t['scope']] = (float) $t['monthly'];
    $totals[$c]['total']    += (float) $t['monthly'];
}

function curSym(string $c): string { return $c === 'USD' ? '$' : '€'; }
function fmtMoney(float $v, string $c): string {
    return number_format($v, 2, ',', '.') . '&nbsp;' . curSym($c);
}

$periodLabels = ['day' => t('expenses.perDay'), 'month' => t('expenses.perMonth'), 'year' => t('expenses.perYear')];

/** Rendert das Bearbeitungsformular (für Bearbeiten und Anlegen). */
$renderForm = function (array $e) use ($periodLabels) {
    $val = fn($k) => h((string) ($e[$k] ?? ''));
    $per   = $e['period']   ?? 'month';
    $cur   = $e['currency'] ?? 'EUR';
    $scope = $e['scope']    ?? 'business';
    ?>
    <div class="exp-form">
        <label class="exp-full"><span><?= h(t('expenses.fTitle')) ?> *</span>
            <input type="text" class="exp-title" value="<?= $val('title') ?>" maxlength="255"></label>
        <label class="exp-full"><span><?= h(t('expenses.fDescription')) ?></span>
            <textarea class="exp-description" rows="2" maxlength="2000"><?= $val('description') ?></textarea></label>
        <label><span><?= h(t('expenses.fAmount')) ?></span>
            <input type="text" class="exp-amount" inputmode="decimal" value="<?= $e ? h(number_format((float)($e['amount'] ?? 0), 2, ',', '.')) : '' ?>" placeholder="0,00"></label>
        <label><span><?= h(t('expenses.fPeriod')) ?></span>
            <select class="exp-period">
                <?php foreach ($periodLabels as $pk => $pl): ?>
                <option value="<?= $pk ?>"<?= $per === $pk ? ' selected' : '' ?>><?= h($pl) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label><span><?= h(t('expenses.fCurrency')) ?></span>
            <select class="exp-currency">
                <option value="EUR"<?= $cur === 'EUR' ? ' selected' : '' ?>>EUR (€)</option>
                <option value="USD"<?= $cur === 'USD' ? ' selected' : '' ?>>USD ($)</option>
            </select></label>
        <label><span><?= h(t('expenses.fScope')) ?></span>
            <select class="exp-scope">
                <option value="private"<?= $scope === 'private' ? ' selected' : '' ?>><?= h(t('expenses.scopePrivate')) ?></option>
                <option value="business"<?= $scope === 'business' ? ' selected' : '' ?>><?= h(t('expenses.scopeBusiness')) ?></option>
            </select></label>
        <label><span><?= h(t('expenses.fCategory')) ?></span>
            <input type="text" class="exp-category" value="<?= $val('category') ?>" maxlength="255"></label>
        <label class="exp-full"><span><?= h(t('expenses.fUrl')) ?></span>
            <input type="text" class="exp-url" value="<?= $val('url') ?>" maxlength="500" placeholder="https://…"></label>
        <label><span><?= h(t('expenses.fUsername')) ?></span>
            <input type="text" class="exp-username" value="<?= $val('username') ?>" maxlength="255" autocomplete="off"></label>
        <label><span><?= h(t('expenses.fEmail')) ?></span>
            <input type="email" class="exp-email" value="<?= $val('email') ?>" maxlength="255" autocomplete="off"></label>
        <label><span><?= h(t('expenses.fPwInfo')) ?></span>
            <input type="text" class="exp-pwinfo" value="<?= $val('pw_info') ?>" maxlength="10" autocomplete="off"></label>
    </div>
    <?php
};
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('admin.card.expenses')) ?> – <?= h(t('admin.title')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.exp-total { display: flex; flex-direction: column; gap: 6px;
    margin: 0 0 16px; padding: 12px 16px; border-radius: 8px;
    background: var(--hover-bg); border: 1px solid var(--card-border); }
.exp-total-line { display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: baseline; }
.exp-total .lbl { font-size: 13px; color: var(--text-muted); }
.exp-total .amt { font-size: 18px; font-weight: 700; }
.exp-total .amt2 { font-size: 15px; font-weight: 600; }
.exp-total .sep { color: var(--text-muted); }
.col-scope { white-space: nowrap; }
.col-active { white-space: nowrap; }
tr.exp-inactive td { opacity: .5; }
tr.exp-inactive td.col-active, tr.exp-inactive td.exp-actions { opacity: 1; }
.exp-actions { white-space: nowrap; text-align: right; }
.exp-actions .btn { font-size: 11px; padding: 2px 8px; margin-left: 4px; }
.col-cost { white-space: nowrap; }
.exp-form { display: flex; flex-wrap: wrap; gap: 10px 14px; padding: 6px 2px 10px; }
.exp-form label { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: var(--text-muted); flex: 1 1 150px; }
.exp-form label.exp-full { flex: 1 1 100%; }
.exp-form input, .exp-form select, .exp-form textarea {
    padding: 6px 8px; border: 1px solid var(--card-border); border-radius: 6px;
    background: var(--card-bg); color: var(--text); font-size: 13px; font-family: inherit; box-sizing: border-box; }
.exp-form textarea { resize: vertical; }
.exp-form-actions { display: flex; gap: 8px; padding: 0 2px 8px; }
.exp-desc-cell { color: var(--text-muted); font-size: 12px; }
.exp-badge { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 10px;
    font-size: 11px; vertical-align: middle; border: 1px solid var(--card-border); color: var(--text-muted); }
.exp-badge--private { color: #b45309; border-color: #fcd34d; background: rgba(250,204,21,.12); }
.exp-badge--business { color: #2563eb; border-color: #bfdbfe; background: rgba(37,99,235,.10); }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1><?= h(t('admin.card.expenses')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo; <?= h(t('admin.card.expenses')) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="index.php" class="btn"><?= h(t('admin.back')) ?></a>
            <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
        </div>
    </div>

    <div class="admin-section">

        <div class="exp-total">
            <?php if (empty($totals)): ?>
            <div class="exp-total-line">
                <span class="lbl"><?= h(t('expenses.monthlyTotal')) ?>:</span> <span class="amt"><?= fmtMoney(0, 'EUR') ?></span>
            </div>
            <?php else: foreach ($totals as $cur => $s): ?>
            <div class="exp-total-line">
                <span class="lbl"><?= h(t('expenses.monthlyTotal')) ?>:</span> <span class="amt"><?= fmtMoney($s['total'], $cur) ?></span>
                <span class="sep">·</span>
                <span class="lbl"><?= h(t('expenses.scopeBusiness')) ?>:</span> <span class="amt2"><?= fmtMoney($s['business'], $cur) ?></span>
                <span class="sep">·</span>
                <span class="lbl"><?= h(t('expenses.scopePrivate')) ?>:</span> <span class="amt2"><?= fmtMoney($s['private'], $cur) ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div style="margin-bottom:14px">
            <button type="button" class="btn btn--primary" id="btnNew">+ <?= h(t('expenses.new')) ?></button>
        </div>

        <div id="addRow" class="hidden" style="margin-bottom:16px;padding:10px 12px;border:1px solid var(--card-border);border-radius:8px">
            <?php $renderForm([]); ?>
            <div class="exp-form-actions">
                <button type="button" class="btn btn--primary" onclick="saveExpense('new')"><?= h(t('common.save')) ?></button>
                <button type="button" class="btn" onclick="hideAdd()"><?= h(t('common.cancel')) ?></button>
                <span class="exp-msg" data-for="new" style="font-size:12px"></span>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="entries-table" id="expTable">
                <thead>
                    <tr>
                        <th><?= h(t('expenses.fTitle')) ?></th>
                        <th class="col-cost"><?= h(t('expenses.colCost')) ?></th>
                        <th class="col-scope"><?= h(t('expenses.fScope')) ?></th>
                        <th><?= h(t('expenses.colCategory')) ?></th>
                        <th><?= h(t('expenses.fUsername')) ?></th>
                        <th class="col-active"><?= h(t('expenses.colActive')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="expTbody">
                <?php if (empty($expenses)): ?>
                    <tr id="emptyRow"><td colspan="7" class="empty-message"><?= h(t('expenses.empty')) ?></td></tr>
                <?php else: foreach ($expenses as $e): $id = (int)$e['id']; $active = (int)$e['active'] === 1; ?>
                    <tr class="entry-row<?= $active ? '' : ' exp-inactive' ?>" data-id="<?= $id ?>">
                        <td>
                            <?php if (!empty($e['url'])): ?>
                            <a href="<?= h((string)$e['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($e['title']) ?></a>
                            <?php else: ?><?= h($e['title']) ?><?php endif; ?>
                            <?php if (!empty($e['description'])): ?><div class="exp-desc-cell"><?= h($e['description']) ?></div><?php endif; ?>
                        </td>
                        <td class="col-cost"><?= fmtMoney((float)$e['amount'], $e['currency']) ?> <span class="exp-desc-cell"><?= h($periodLabels[$e['period']] ?? '') ?></span></td>
                        <td class="col-scope"><span class="exp-badge exp-badge--<?= h($e['scope']) ?>"><?= h($e['scope'] === 'private' ? t('expenses.scopePrivate') : t('expenses.scopeBusiness')) ?></span></td>
                        <td><?= h((string)($e['category'] ?? '')) ?></td>
                        <td><?= h((string)$e['username']) ?></td>
                        <td class="col-active">
                            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                                <input type="checkbox" onchange="toggleExpense(<?= $id ?>)"<?= $active ? ' checked' : '' ?>>
                                <span><?= h($active ? t('expenses.active') : t('expenses.inactive')) ?></span>
                            </label>
                        </td>
                        <td class="exp-actions">
                            <button type="button" class="btn" onclick="showEdit(<?= $id ?>)"><?= h(t('common.edit')) ?></button>
                            <button type="button" class="btn" onclick="copyExpense(<?= $id ?>)"><?= h(t('expenses.copy')) ?></button>
                            <button type="button" class="btn btn--danger" onclick="deleteExpense(<?= $id ?>, <?= htmlspecialchars(json_encode($e['title']), ENT_QUOTES) ?>)"><?= h(t('common.delete')) ?></button>
                        </td>
                    </tr>
                    <tr id="edit-<?= $id ?>" class="edit-row hidden">
                        <td colspan="7">
                            <?php $renderForm($e); ?>
                            <div class="exp-form-actions">
                                <button type="button" class="btn btn--primary" onclick="saveExpense(<?= $id ?>)"><?= h(t('common.save')) ?></button>
                                <button type="button" class="btn" onclick="hideEdit(<?= $id ?>)"><?= h(t('common.cancel')) ?></button>
                                <span class="exp-msg" data-for="<?= $id ?>" style="font-size:12px"></span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
window.I18N = <?= json_encode(i18nStrings(), JSON_UNESCAPED_UNICODE) ?>;
function t(key, params) {
    let s = (window.I18N && window.I18N[key]) || key;
    if (params) { for (const k in params) { s = s.split('{' + k + '}').join(params[k]); } }
    return s;
}
async function apiCall(action, params) {
    const res = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action, ...params }) });
    return res.json();
}

function showEdit(id) { document.getElementById('edit-' + id)?.classList.remove('hidden'); }
function hideEdit(id) { document.getElementById('edit-' + id)?.classList.add('hidden'); }
function hideAdd()    { document.getElementById('addRow').classList.add('hidden'); }
document.getElementById('btnNew').addEventListener('click', () => document.getElementById('addRow').classList.remove('hidden'));

function collectForm(container) {
    const g = sel => container.querySelector(sel);
    return {
        title:       g('.exp-title').value.trim(),
        description: g('.exp-description').value.trim(),
        amount:      g('.exp-amount').value.trim(),
        period:      g('.exp-period').value,
        currency:    g('.exp-currency').value,
        scope:       g('.exp-scope').value,
        category:    g('.exp-category').value.trim(),
        url:         g('.exp-url').value.trim(),
        username:    g('.exp-username').value.trim(),
        email:       g('.exp-email').value.trim(),
        pw_info:     g('.exp-pwinfo').value.trim(),
    };
}

async function saveExpense(id) {
    const isNew    = id === 'new';
    const container = isNew ? document.getElementById('addRow') : document.getElementById('edit-' + id);
    const msg       = document.querySelector('.exp-msg[data-for="' + id + '"]');
    const data      = collectForm(container);
    if (!data.title) { if (msg) { msg.style.color = 'var(--danger)'; msg.textContent = t('expenses.titleRequired'); } return; }
    const params = isNew ? data : { id, ...data };
    const res = await apiCall('save_expense', params);
    if (!res.success) { if (msg) { msg.style.color = 'var(--danger)'; msg.textContent = res.error || t('common.error'); } return; }
    location.reload();
}

async function toggleExpense(id) {
    const res = await apiCall('toggle_expense', { id });
    if (!res.success) { Dialog.alert(t('common.error') + ': ' + (res.error || '')); return; }
    location.reload(); // Summe neu berechnen
}

async function copyExpense(id) {
    const res = await apiCall('copy_expense', { id });
    if (!res.success) { Dialog.alert(t('common.error') + ': ' + (res.error || '')); return; }
    location.reload();
}

async function deleteExpense(id, title) {
    if (!await Dialog.confirm(t('expenses.confirmDelete', { title }), { danger: true })) return;
    const res = await apiCall('delete_expense', { id });
    if (!res.success) { Dialog.alert(t('common.error') + ': ' + (res.error || '')); return; }
    location.reload();
}
</script>
</body>
</html>
