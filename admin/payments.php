<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/payments.php';

$db = db();
// Offene zuerst, danach erledigte; jeweils nach Fälligkeit.
$payments = $db->query(
    'SELECT * FROM tm_payments ORDER BY done ASC, due_date ASC, id ASC'
)->fetchAll();

function curSym(string $c): string { return $c === 'USD' ? '$' : '€'; }
function fmtMoney(float $v, string $c): string {
    return number_format($v, 2, ',', '.') . '&nbsp;' . curSym($c);
}

$recLabels = [
    'once'      => t('payments.recOnce'),
    'monthly'   => t('payments.recMonthly'),
    'quarterly' => t('payments.recQuarterly'),
    'yearly'    => t('payments.recYearly'),
];

$today = new DateTimeImmutable('today');

$monthNames = currentLang() === 'en'
    ? ['January','February','March','April','May','June','July','August','September','October','November','December']
    : ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

/** Liefert [Text, CSS-Klasse] für die Status-Spalte einer offenen Zahlung. */
function paymentStatus(string $due, DateTimeImmutable $today): array {
    $d = (int) $today->diff(new DateTimeImmutable($due))->format('%r%a');
    if ($d < 0)  return [t('payments.overdueDays', ['days' => abs($d)]), 'pay-overdue'];
    if ($d === 0) return [t('payments.dueToday'), 'pay-today'];
    return [t('payments.dueInDays', ['days' => $d]), 'pay-soon'];
}

/** Bearbeitungs-/Anlege-Formular. */
$renderForm = function (array $p) use ($recLabels, $monthNames) {
    $val = fn($k) => h((string) ($p[$k] ?? ''));
    $cur = $p['currency']   ?? 'EUR';
    $rec = $p['recurrence'] ?? 'once';
    $due = $p['due_date']   ?? '';
    $dueMonth = $due !== '' ? (int) (new DateTime($due))->format('n') : (int) date('n');
    $dueYear  = $due !== '' ? (int) (new DateTime($due))->format('Y') : (int) date('Y');
    $dayVal   = $p['due_day'] ?? ($due !== '' ? (new DateTime($due))->format('j') : '');
    ?>
    <div class="pay-form">
        <label class="pay-full"><span><?= h(t('payments.fTitle')) ?> *</span>
            <input type="text" class="pay-title" value="<?= $val('title') ?>" maxlength="255"></label>
        <label><span><?= h(t('payments.fAmount')) ?></span>
            <input type="text" class="pay-amount" inputmode="decimal" value="<?= $p ? h(number_format((float)($p['amount'] ?? 0), 2, ',', '.')) : '' ?>" placeholder="0,00"></label>
        <label><span><?= h(t('payments.fCurrency')) ?></span>
            <select class="pay-currency">
                <option value="EUR"<?= $cur === 'EUR' ? ' selected' : '' ?>>EUR (€)</option>
                <option value="USD"<?= $cur === 'USD' ? ' selected' : '' ?>>USD ($)</option>
            </select></label>
        <label><span><?= h(t('payments.fRecurrence')) ?></span>
            <select class="pay-recurrence">
                <?php foreach ($recLabels as $rk => $rl): ?>
                <option value="<?= $rk ?>"<?= $rec === $rk ? ' selected' : '' ?>><?= h($rl) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="pay-date-wrap<?= $rec !== 'once' ? ' hidden' : '' ?>"><span><?= h(t('payments.fDueDate')) ?> *</span>
            <input type="date" class="pay-due" value="<?= h((string)$due) ?>"></label>
        <label class="pay-dueday-wrap<?= $rec === 'once' ? ' hidden' : '' ?>"><span><?= h(t('payments.fDueDay')) ?> *</span>
            <input type="number" class="pay-dueday" min="1" max="31" value="<?= h((string)$dayVal) ?>" placeholder="1–31">
            <small class="pay-field-hint"><?= h(t('payments.dueDayHint')) ?></small></label>
        <label class="pay-month-wrap<?= in_array($rec, ['quarterly','yearly'], true) ? '' : ' hidden' ?>"><span><?= h(t('payments.fMonth')) ?> *</span>
            <select class="pay-month">
                <?php foreach ($monthNames as $i => $mn): ?>
                <option value="<?= $i + 1 ?>"<?= $dueMonth === $i + 1 ? ' selected' : '' ?>><?= h($mn) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="pay-field-hint"><?= h(t('payments.monthHint')) ?></small></label>
        <label class="pay-year-wrap<?= in_array($rec, ['quarterly','yearly'], true) ? '' : ' hidden' ?>"><span><?= h(t('payments.fYear')) ?> *</span>
            <input type="number" class="pay-year" min="<?= (int) date('Y') ?>" max="2100" value="<?= (int) $dueYear ?>">
            <small class="pay-field-hint"><?= h(t('payments.yearHint')) ?></small></label>
        <label class="pay-full"><span><?= h(t('payments.fNote')) ?></span>
            <textarea class="pay-note" rows="2" maxlength="2000"><?= $val('note') ?></textarea></label>
    </div>
    <?php
};
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('admin.card.payments')) ?> – <?= h(t('admin.title')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.pay-hint { margin: 0 0 16px; padding: 10px 14px; border-radius: 8px; font-size: 13px;
    color: var(--text-muted); background: var(--hover-bg); border: 1px solid var(--card-border); }
.col-amount, .col-recurrence, .col-due, .col-status, .col-active { white-space: nowrap; }
tr.pay-inactive td { opacity: .5; }
tr.pay-inactive td.col-active, tr.pay-inactive td.pay-actions { opacity: 1; }
tr.pay-done td { opacity: .55; }
tr.pay-done td.pay-actions { opacity: 1; }
.pay-actions { white-space: nowrap; text-align: right; }
.pay-actions .btn { font-size: 11px; padding: 2px 8px; margin-left: 4px; }
.pay-status { font-weight: 600; }
.pay-overdue { color: var(--danger, #dc2626); }
.pay-today   { color: #b45309; }
.pay-soon    { color: var(--text); }
.pay-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px;
    border: 1px solid var(--card-border); color: var(--text-muted); }
.pay-note-cell { color: var(--text-muted); font-size: 12px; }
.pay-form { display: flex; flex-wrap: wrap; gap: 10px 14px; padding: 6px 2px 10px; }
.pay-form label { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: var(--text-muted); flex: 1 1 150px; }
.pay-form label.pay-full { flex: 1 1 100%; }
.pay-form input, .pay-form select, .pay-form textarea {
    padding: 6px 8px; border: 1px solid var(--card-border); border-radius: 6px;
    background: var(--card-bg); color: var(--text); font-size: 13px; font-family: inherit; box-sizing: border-box; }
.pay-form textarea { resize: vertical; }
.pay-field-hint { font-size: 11px; color: var(--text-muted); font-weight: 400; margin-top: 2px; }
.pay-form-actions { display: flex; gap: 8px; padding: 0 2px 8px; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1><?= h(t('admin.card.payments')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo; <?= h(t('admin.card.payments')) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="index.php" class="btn"><?= h(t('admin.back')) ?></a>
            <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
        </div>
    </div>

    <div class="admin-section">

        <div class="pay-hint"><?= h(t('payments.hint')) ?></div>

        <div style="margin-bottom:14px">
            <button type="button" class="btn btn--primary" id="btnNew">+ <?= h(t('payments.new')) ?></button>
        </div>

        <div id="addRow" class="hidden" style="margin-bottom:16px;padding:10px 12px;border:1px solid var(--card-border);border-radius:8px">
            <?php $renderForm([]); ?>
            <div class="pay-form-actions">
                <button type="button" class="btn btn--primary" onclick="savePayment('new')"><?= h(t('common.save')) ?></button>
                <button type="button" class="btn" onclick="hideAdd()"><?= h(t('common.cancel')) ?></button>
                <span class="pay-msg" data-for="new" style="font-size:12px"></span>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="entries-table" id="payTable">
                <thead>
                    <tr>
                        <th><?= h(t('payments.fTitle')) ?></th>
                        <th class="col-amount"><?= h(t('payments.colAmount')) ?></th>
                        <th class="col-recurrence"><?= h(t('payments.colRecurrence')) ?></th>
                        <th class="col-due"><?= h(t('payments.colDueDate')) ?></th>
                        <th class="col-status"><?= h(t('payments.colStatus')) ?></th>
                        <th class="col-active"><?= h(t('payments.colActive')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="payTbody">
                <?php if (empty($payments)): ?>
                    <tr id="emptyRow"><td colspan="7" class="empty-message"><?= h(t('payments.empty')) ?></td></tr>
                <?php else: foreach ($payments as $p):
                    $id = (int)$p['id']; $active = (int)$p['active'] === 1; $done = (int)$p['done'] === 1;
                    $rowClass = 'entry-row' . ($active ? '' : ' pay-inactive') . ($done ? ' pay-done' : '');
                ?>
                    <tr class="<?= $rowClass ?>" data-id="<?= $id ?>">
                        <td>
                            <?= h($p['title']) ?>
                            <?php if (!empty($p['note'])): ?><div class="pay-note-cell"><?= h($p['note']) ?></div><?php endif; ?>
                        </td>
                        <td class="col-amount"><?= fmtMoney((float)$p['amount'], $p['currency']) ?></td>
                        <td class="col-recurrence"><?= h($recLabels[$p['recurrence']] ?? $p['recurrence']) ?></td>
                        <td class="col-due"><?= h((new DateTime($p['due_date']))->format('d.m.Y')) ?></td>
                        <td class="col-status">
                            <?php if ($done): ?>
                                <span class="pay-badge"><?= h(t('payments.statusDone')) ?></span>
                            <?php else: [$stTxt, $stCls] = paymentStatus($p['due_date'], $today); ?>
                                <span class="pay-status <?= $stCls ?>"><?= h($stTxt) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="col-active">
                            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                                <input type="checkbox" onchange="togglePayment(<?= $id ?>)"<?= $active ? ' checked' : '' ?>>
                                <span><?= h($active ? t('payments.active') : t('payments.inactive')) ?></span>
                            </label>
                        </td>
                        <td class="pay-actions">
                            <?php if ($done): ?>
                                <button type="button" class="btn" onclick="reopenPayment(<?= $id ?>)"><?= h(t('payments.reopen')) ?></button>
                            <?php else:
                                $recurring = $p['recurrence'] !== 'once';
                                $btnLabel  = $recurring ? t('payments.markPaid') : t('payments.markDone');
                                $confirmKey = $recurring ? 'payments.confirmPaid' : 'payments.confirmDone';
                            ?>
                                <button type="button" class="btn btn--primary" onclick="completePayment(<?= $id ?>, <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES) ?>, '<?= $confirmKey ?>')"><?= h($btnLabel) ?></button>
                            <?php endif; ?>
                            <button type="button" class="btn" onclick="showEdit(<?= $id ?>)"><?= h(t('common.edit')) ?></button>
                            <button type="button" class="btn btn--danger" onclick="deletePayment(<?= $id ?>, <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES) ?>)"><?= h(t('common.delete')) ?></button>
                        </td>
                    </tr>
                    <tr id="edit-<?= $id ?>" class="edit-row hidden">
                        <td colspan="7">
                            <?php $renderForm($p); ?>
                            <div class="pay-form-actions">
                                <button type="button" class="btn btn--primary" onclick="savePayment(<?= $id ?>)"><?= h(t('common.save')) ?></button>
                                <button type="button" class="btn" onclick="hideEdit(<?= $id ?>)"><?= h(t('common.cancel')) ?></button>
                                <span class="pay-msg" data-for="<?= $id ?>" style="font-size:12px"></span>
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
        title:      g('.pay-title').value.trim(),
        amount:     g('.pay-amount').value.trim(),
        currency:   g('.pay-currency').value,
        recurrence: g('.pay-recurrence').value,
        due_date:   g('.pay-due').value,
        due_day:    g('.pay-dueday').value.trim(),
        due_month:  g('.pay-month').value,
        due_year:   g('.pay-year').value.trim(),
        note:       g('.pay-note').value.trim(),
    };
}

// Sichtbarkeit der Termin-Felder je nach Rhythmus.
function syncRecurrence(sel) {
    const form = sel.closest('.pay-form');
    if (!form) return;
    const rec = sel.value;
    const withMonth = (rec === 'quarterly' || rec === 'yearly');
    form.querySelector('.pay-date-wrap')  ?.classList.toggle('hidden', rec !== 'once');
    form.querySelector('.pay-dueday-wrap')?.classList.toggle('hidden', rec === 'once');
    form.querySelector('.pay-month-wrap') ?.classList.toggle('hidden', !withMonth);
    form.querySelector('.pay-year-wrap')  ?.classList.toggle('hidden', !withMonth);
}
document.querySelectorAll('.pay-recurrence').forEach(sel => {
    sel.addEventListener('change', () => syncRecurrence(sel));
});

async function savePayment(id) {
    const isNew     = id === 'new';
    const container = isNew ? document.getElementById('addRow') : document.getElementById('edit-' + id);
    const msg       = document.querySelector('.pay-msg[data-for="' + id + '"]');
    const data      = collectForm(container);
    const fail = txt => { if (msg) { msg.style.color = 'var(--danger)'; msg.textContent = txt; } };
    if (!data.title) { fail(t('payments.titleRequired')); return; }
    if (data.recurrence === 'once') {
        if (!data.due_date) { fail(t('payments.dateRequired')); return; }
    } else if (!data.due_day) {
        fail(t('payments.dayRequired')); return;
    }
    const params = isNew ? data : { id, ...data };
    const res = await apiCall('save_payment', params);
    if (!res.success) { fail(res.error || t('common.error')); return; }
    location.reload();
}

async function completePayment(id, title, confirmKey) {
    if (!await Dialog.confirm(t(confirmKey, { title }))) return;
    const res = await apiCall('complete_payment', { id });
    if (!res.success) { Dialog.alert(t('common.error') + ': ' + (res.error || '')); return; }
    location.reload();
}

async function reopenPayment(id) {
    const res = await apiCall('reopen_payment', { id });
    if (!res.success) { Dialog.alert(t('common.error') + ': ' + (res.error || '')); return; }
    location.reload();
}

async function togglePayment(id) {
    const res = await apiCall('toggle_payment', { id });
    if (!res.success) { Dialog.alert(t('common.error') + ': ' + (res.error || '')); return; }
    location.reload();
}

async function deletePayment(id, title) {
    if (!await Dialog.confirm(t('payments.confirmDelete', { title }), { danger: true })) return;
    const res = await apiCall('delete_payment', { id });
    if (!res.success) { Dialog.alert(t('common.error') + ': ' + (res.error || '')); return; }
    location.reload();
}
</script>
</body>
</html>
