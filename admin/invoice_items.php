<?php
require_once __DIR__ . '/auth.php';

$invoiceId = filter_var($_GET['invoice_id'] ?? 0, FILTER_VALIDATE_INT);
if (!$invoiceId) { header('Location: invoices.php'); exit; }

$stmt = db()->prepare(
    'SELECT i.id, i.invoice_number, i.total_minutes, i.amount_net, i.amount_gross,
            i.pdf_file, i.created_at,
            i.invoice_date, i.period_start, i.period_end,
            i.invoice_mode, i.invoice_text,
            i.mail_template_html, i.mail_template_plain,
            i.tax_rate AS stored_tax, i.hourly_rate AS stored_rate,
            c.name AS customer_name, c.id AS customer_id, c.hourly_rate AS cust_rate
     FROM tm_invoices i
     LEFT JOIN tm_customers c ON c.id = i.customer_id
     WHERE i.id = ? LIMIT 1'
);
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();
if (!$invoice) { header('Location: invoices.php'); exit; }

$rate    = $invoice['stored_rate'] !== null
    ? (float)$invoice['stored_rate']
    : (float)($invoice['cust_rate'] ?: cfg('invoice_hourly_rate', '85.00'));
$taxRate = $invoice['stored_tax'] !== null
    ? (int)$invoice['stored_tax']
    : (int)cfg('invoice_tax_rate', '19');
$invoiceMode       = $invoice['invoice_mode']       ?? 'entries';
$invoiceText       = $invoice['invoice_text']       ?? '';
$mailTemplateHtml  = $invoice['mail_template_html']  ?? '';

$stmt = db()->prepare(
    'SELECT id, date, activity, comment, duration_minutes, sort_order, visible
     FROM tm_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id'
);
$stmt->execute([$invoiceId]);
$items = $stmt->fetchAll();

// Auto-populate from linked entries if no items exist yet
if (empty($items)) {
    $eStmt = db()->prepare(
        'SELECT id, date, start_datetime, end_datetime, activity, comment, project, duration_minutes
         FROM tm_entries WHERE invoice_id = ? AND deleted_at IS NULL
         ORDER BY date ASC, start_datetime ASC'
    );
    $eStmt->execute([$invoiceId]);
    $linked = $eStmt->fetchAll();

    if (!empty($linked)) {
        $ins = db()->prepare(
            'INSERT INTO tm_invoice_items
             (invoice_id, entry_id, date, start_datetime, end_datetime, activity, comment, project, duration_minutes, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($linked as $idx => $le) {
            $ins->execute([
                $invoiceId, (int)$le['id'], $le['date'],
                $le['start_datetime'], $le['end_datetime'],
                $le['activity'], $le['comment'], $le['project'],
                (int)$le['duration_minutes'], $idx + 1,
            ]);
        }
        // Recalculate stored totals
        $an = 0.0; $rh = 0.0;
        foreach ($linked as $le) {
            $h = (int)$le['duration_minutes'] / 60;
            $an += $h * $rate; $rh += $h;
        }
        $an = round($an, 2);
        $ag = round($an * (1 + $taxRate / 100), 2);
        $bm = (int)round($rh * 60);
        db()->prepare('UPDATE tm_invoices SET total_minutes=?, amount_net=?, amount_gross=? WHERE id=?')
            ->execute([$bm, $an, $ag, $invoiceId]);
        $invoice['total_minutes'] = $bm;
        $invoice['amount_net']    = $an;
        $invoice['amount_gross']  = $ag;

        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll();
    }
}

function fmtH(int $min): string    { return number_format($min / 60, 2, ',', '.') . ' h'; }
function fmtEur(float $a): string  { return number_format($a, 2, ',', '.') . ' €'; }
function fmtDate(?string $d): string { return $d ? date('d.m.Y', strtotime($d)) : ''; }

$totalMinutes = (int)$invoice['total_minutes'];
$amountNet    = (float)$invoice['amount_net'];
$amountGross  = (float)$invoice['amount_gross'];
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('invItems.title', ['num' => $invoice['invoice_number']])) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.summary-bar { display:flex; gap:24px; flex-wrap:wrap; margin-bottom:16px; font-size:13px; color:var(--text-muted); }
.summary-bar strong { color:var(--text); }
.customer-link { color:inherit; text-decoration:none; }
.customer-link:hover { color:#3b82f6; text-decoration:underline; }
.add-form { background:var(--bg-card); border:1px solid var(--border); border-radius:8px;
            padding:16px 20px; margin-bottom:20px;
            display:flex; flex-direction:column; align-items:flex-start; }
.add-form h3 { margin:0 0 12px; font-size:14px; }
.add-row { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
.add-row > div { display:flex; flex-direction:column; gap:4px; }
.add-row label { font-size:11px; color:var(--text-muted); font-weight:500; }
.add-row input[type="text"], .add-row input[type="date"], .add-row input[type="number"] {
    min-width:0; }
.add-row .f-date { width:130px; }
.add-row .f-activity { width:200px; }
.add-row .f-comment { flex:1; min-width:160px; }
.add-row .f-min { width:80px; }
.edit-row td { background:var(--bg-hover); }
.edit-row input[type="text"], .edit-row input[type="date"], .edit-row input[type="number"] {
    width:100%; box-sizing:border-box; }
.col-min  { width:70px; text-align:right; }
.col-h    { width:70px; text-align:right; }
.col-eur  { width:90px; text-align:right; }
.col-act  { width:200px; }
.items-foot td { border-top:2px solid #cbd3dc; font-weight:700; color:#1e293b; padding-top:8px; }
tr.item-hidden td { opacity:0.4; }
tr.item-hidden td:first-child { opacity:1; }
.meta-form { background:var(--bg-card); border:1px solid var(--border); border-radius:8px;
             padding:16px 20px; margin-bottom:20px; }
.meta-form h3 { margin:0 0 12px; font-size:14px; }
.meta-row { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-bottom:8px; }
.meta-row > div { display:flex; flex-direction:column; gap:4px; }
.meta-row label { font-size:11px; color:var(--text-muted); font-weight:500; }
.meta-row textarea {
    padding: 7px 10px;
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    font-family: var(--font);
    font-size: 13px;
    color: var(--text);
    background: var(--card-bg);
    transition: border-color 0.15s;
    min-width: 0;
}
.meta-row textarea:focus {
    outline: none;
    border-color: var(--primary);
}
.meta-row .f-date  { width:140px; }
.meta-row .f-rate  { width:90px; }
.meta-row .f-tax   { width:70px; }
.meta-row .f-hours { width:90px; }
.meta-row .f-net   { width:110px; }
.meta-row textarea { width:320px; min-height:60px; resize:vertical; }
.meta-form textarea {
    padding: 7px 10px;
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    font-family: var(--font);
    font-size: 13px;
    color: var(--text);
    background: var(--card-bg);
    transition: border-color 0.15s;
    min-height: 60px;
    resize: vertical;
}
.meta-form textarea:focus {
    outline: none;
    border-color: var(--primary);
}
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1><?= h(t('invItems.title', ['num' => $invoice['invoice_number']])) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo;
                <a href="invoices.php"><?= h(t('admin.card.invoices')) ?></a> &rsaquo;
                <?= h($invoice['invoice_number']) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="invoice_view.php?invoice_id=<?= (int)$invoiceId ?>" class="btn"><?= h(t('invoices.preview')) ?></a>
            <a href="invoices.php" class="btn-logout">&#8592; <?= h(t('admin.card.invoices')) ?></a>
        </div>
    </div>

    <div class="admin-section">

        <div class="summary-bar" id="summaryBar">
            <span><?= h(t('entries.colCustomer')) ?>: <strong><?php if (!empty($invoice['customer_id'])): ?><a class="customer-link" href="customers.php?edit=<?= (int)$invoice['customer_id'] ?>"><?= h($invoice['customer_name'] ?? '—') ?></a><?php else: ?><?= h($invoice['customer_name'] ?? '—') ?><?php endif; ?></strong></span>
            <span><strong id="sumItems"><?= count(array_filter($items, fn($it) => (int)$it['visible'] === 1)) ?></strong> <?= h(t('invItems.posten')) ?></span>
            <span><strong id="sumH"><?= fmtH($totalMinutes) ?></strong></span>
            <span><?= h(t('invoices.net')) ?>: <strong id="sumNet"><?= fmtEur($amountNet) ?></strong></span>
            <span><?= h(t('invoices.gross')) ?>: <strong id="sumGross"><?= fmtEur($amountGross) ?></strong></span>
            <span style="color:var(--text-muted);font-size:12px"><?= number_format($rate, 2, ',', '.') ?> €/h</span>
        </div>

        <div class="meta-form" id="metaForm">
            <h3><?= h(t('invItems.metaHeading')) ?></h3>
            <div class="meta-row">
                <div>
                    <label><?= h(t('invItems.invoiceDate')) ?></label>
                    <input type="date" id="metaDate" class="f-date"
                           value="<?= h($invoice['invoice_date'] ?? date('Y-m-d', strtotime($invoice['created_at']))) ?>">
                </div>
                <div>
                    <label><?= h(t('invItems.periodFrom')) ?></label>
                    <input type="date" id="metaPeriodStart" class="f-date"
                           value="<?= h($invoice['period_start'] ?? '') ?>">
                </div>
                <div>
                    <label><?= h(t('invItems.periodTo')) ?></label>
                    <input type="date" id="metaPeriodEnd" class="f-date"
                           value="<?= h($invoice['period_end'] ?? '') ?>">
                </div>
                <div>
                    <label><?= h(t('invItems.hourlyRate')) ?></label>
                    <input type="number" id="metaRate" class="f-rate" step="0.01" min="0"
                           value="<?= number_format($rate, 2, '.', '') ?>">
                </div>
                <div>
                    <label><?= h(t('invItems.tax')) ?></label>
                    <input type="number" id="metaTax" class="f-tax" step="1" min="0" max="100"
                           value="<?= $taxRate ?>">
                </div>
            </div>
            <div class="meta-row">
                <div>
                    <label><?= h(t('invItems.invoiceType')) ?></label>
                    <select id="metaMode" onchange="toggleTextMode()">
                        <option value="entries"<?= $invoiceMode === 'entries' ? ' selected' : '' ?>><?= h(t('invItems.modeEntries')) ?></option>
                        <option value="text"<?=   $invoiceMode === 'text'    ? ' selected' : '' ?>><?= h(t('invItems.modeText')) ?></option>
                    </select>
                </div>
                <div id="metaTextHoursWrap" style="display:<?= $invoiceMode === 'text' ? 'flex' : 'none' ?>;flex-direction:column;gap:4px">
                    <label><?= h(t('invItems.hoursRounded')) ?></label>
                    <input type="number" id="metaHours" class="f-hours" step="0.01" min="0"
                           value="<?= number_format((int)$invoice['total_minutes'] / 60, 2, '.', '') ?>">
                </div>
                <div id="metaNetWrap" style="display:<?= $invoiceMode === 'text' ? 'flex' : 'none' ?>;flex-direction:column;gap:4px">
                    <label><?= h(t('invItems.netAmount')) ?></label>
                    <input type="number" id="metaNet" class="f-net" step="0.01" min="0"
                           value="<?= number_format((float)$invoice['amount_net'], 2, '.', '') ?>">
                </div>
            </div>
            <div id="metaTextWrap" style="display:<?= $invoiceMode === 'text' ? 'block' : 'none' ?>;margin-bottom:8px">
                <label style="font-size:11px;color:var(--text-muted);font-weight:500;display:block;margin-bottom:4px"><?= h(t('customers.invoiceModeText')) ?></label>
                <textarea id="metaText" style="width:100%;box-sizing:border-box"><?= h($invoiceText) ?></textarea>
            </div>
            <div style="margin-bottom:8px">
                <label style="font-size:11px;color:var(--text-muted);font-weight:500;display:block;margin-bottom:4px"><?= h(t('customers.fMailHtml')) ?></label>
                <div class="rte-wrap">
                    <div class="rte-toolbar">
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('bold')"><b>B</b></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('italic')"><em>I</em></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('underline')"><u>U</u></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="rteLink(this)">Link</button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('removeFormat')" title="<?= h(t('orders.removeFormat')) ?>">&#10005;</button>
                    </div>
                    <div class="rte-body" id="metaMailHtml" contenteditable="true"><?= $mailTemplateHtml ?></div>
                </div>
            </div>
            <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
                <button class="btn btn--primary" id="metaSaveBtn"><?= h(t('invItems.saveMeta')) ?></button>
                <span id="metaMsg" style="font-size:12px"></span>
            </div>
        </div>

        <div class="add-form">
            <h3><?= h(t('invItems.addHeading')) ?></h3>
            <div class="add-row">
                <div>
                    <label><?= h(t('customers.colDate')) ?></label>
                    <input type="date" id="addDate" class="f-date" value="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label><?= h(t('common.activity')) ?></label>
                    <input type="text" id="addActivity" class="f-activity" placeholder="<?= h(t('common.activity')) ?>">
                </div>
                <div style="flex:1">
                    <label><?= h(t('customers.colComment')) ?></label>
                    <input type="text" id="addComment" class="f-comment" placeholder="<?= h(t('customers.commentOptional')) ?>">
                </div>
                <div>
                    <label><?= h(t('invItems.minutes')) ?></label>
                    <input type="number" id="addMinutes" class="f-min" min="1" placeholder="<?= h(t('entries.colMin')) ?>">
                </div>
                <div>
                    <label>&nbsp;</label>
                    <button class="btn btn--primary" id="addBtn"><?= h(t('customers.add')) ?></button>
                </div>
            </div>
            <div id="addMsg" style="margin-top:8px;font-size:12px"></div>
        </div>

        <?php if (empty($items)): ?>
            <p class="empty-message"><?= h(t('invItems.emptyItems')) ?></p>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="entries-table" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width:40px;text-align:center" title="<?= h(t('invItems.colVisibleTitle')) ?>"><?= h(t('invItems.colVisible')) ?></th>
                        <th><?= h(t('customers.colDate')) ?></th>
                        <th><?= h(t('invItems.colActivityComment')) ?></th>
                        <th class="col-min"><?= h(t('entries.colMin')) ?></th>
                        <th class="col-h"><?= h(t('invItems.colHoursShort')) ?></th>
                        <th class="col-eur"><?= h(t('invItems.colAmount')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item):
                    $itemH   = (int)$item['duration_minutes'] / 60;
                    $itemEur = round($itemH * $rate, 2);
                ?>
                    <tr id="row-<?= (int)$item['id'] ?>"<?= (int)$item['visible'] === 0 ? ' class="item-hidden"' : '' ?>>
                        <td style="text-align:center">
                            <input type="checkbox" class="ei-visible" onchange="toggleItemVisible(<?= (int)$item['id'] ?>, this.checked)"<?= (int)$item['visible'] === 1 ? ' checked' : '' ?>>
                        </td>
                        <td><?= h(fmtDate($item['date'])) ?></td>
                        <td>
                            <?= h($item['activity']) ?>
                            <?php if ($item['comment']): ?>
                                <br><small style="color:var(--text-muted)"><?= h($item['comment']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="col-min"><?= (int)$item['duration_minutes'] ?></td>
                        <td class="col-h"><?= number_format((int)$item['duration_minutes'] / 60, 2, ',', '.') ?></td>
                        <td class="col-eur"><?= fmtEur($itemEur) ?></td>
                        <td style="white-space:nowrap">
                            <button class="btn" onclick="showEdit(<?= (int)$item['id'] ?>)" style="font-size:11px;padding:2px 8px"><?= h(t('invItems.editShort')) ?></button>
                            <button class="btn btn--danger" onclick="deleteItem(<?= (int)$item['id'] ?>)" style="font-size:11px;padding:2px 8px;margin-left:4px"><?= h(t('common.delete')) ?></button>
                        </td>
                    </tr>
                    <tr id="edit-<?= (int)$item['id'] ?>" class="edit-row hidden">
                        <td></td>
                        <td><input type="date" class="ei-date" value="<?= h($item['date']) ?>"></td>
                        <td>
                            <input type="text" class="ei-activity" value="<?= h($item['activity']) ?>" placeholder="<?= h(t('common.activity')) ?>">
                            <input type="text" class="ei-comment" value="<?= h($item['comment'] ?? '') ?>" placeholder="<?= h(t('customers.colComment')) ?>" style="margin-top:4px">
                        </td>
                        <td class="col-min"><input type="number" class="ei-min" value="<?= (int)$item['duration_minutes'] ?>" min="1" style="width:60px"></td>
                        <td colspan="2"></td>
                        <td style="white-space:nowrap">
                            <button class="btn btn--primary" onclick="saveItem(<?= (int)$item['id'] ?>)" style="font-size:11px;padding:2px 8px"><?= h(t('common.save')) ?></button>
                            <button class="btn" onclick="hideEdit(<?= (int)$item['id'] ?>)" style="font-size:11px;padding:2px 8px;margin-left:4px"><?= h(t('common.cancel')) ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php $itemsSumMin = array_sum(array_map(fn($it) => (int)$it['visible'] ? (int)$it['duration_minutes'] : 0, $items)); ?>
                <tfoot>
                    <tr class="items-foot">
                        <td colspan="3" style="text-align:right"><?= h(t('invItems.sum')) ?></td>
                        <td class="col-min" id="footSumMin"><?= $itemsSumMin ?></td>
                        <td class="col-h" id="footSumH"><?= number_format($itemsSumMin / 60, 2, ',', '.') ?></td>
                        <td class="col-eur"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

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
const CSRF      = <?= json_encode($_SESSION['csrf_token']) ?>;
const INVOICE_ID = <?= (int)$invoiceId ?>;
const RATE       = <?= json_encode($rate) ?>;
const TAX_RATE   = <?= json_encode($taxRate) ?>;

function fmtH(min) {
    return (min / 60).toFixed(2).replace('.', ',') + ' h';
}
function fmtEur(val) {
    return parseFloat(val).toFixed(2).replace('.', ',') + ' €';
}
function fmtDate(iso) {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return d + '.' + m + '.' + y;
}

function updateSummary(totals, itemCount) {
    document.getElementById('sumItems').textContent = itemCount;
    document.getElementById('sumH').textContent     = fmtH(totals.total_minutes);
    document.getElementById('sumNet').textContent   = fmtEur(totals.amount_net);
    document.getElementById('sumGross').textContent = fmtEur(totals.amount_gross);
}

async function apiCall(action, data) {
    const body = new URLSearchParams({ action, ...data });
    const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body });
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

function rowCount() {
    return document.querySelectorAll('#itemsTable tbody tr[id^="row-"]').length;
}

// Zahl der auf der Rechnung sichtbaren (angehakten) Posten
function visibleRowCount() {
    let n = 0;
    document.querySelectorAll('#itemsTable tbody tr[id^="row-"]').forEach(function(row) {
        const cb = row.querySelector('.ei-visible');
        if (!cb || cb.checked) n++;
    });
    return n;
}

// Summenzeile (Minuten + Stunden) nur aus den angehakten Posten aktualisieren
function recalcItemsFooter() {
    if (!document.getElementById('footSumMin')) return;
    let sum = 0;
    document.querySelectorAll('#itemsTable tbody tr[id^="row-"]').forEach(function(row) {
        const cb = row.querySelector('.ei-visible');
        if (cb && !cb.checked) return;
        const td = row.querySelector('td.col-min');
        if (td) sum += parseInt(td.textContent, 10) || 0;
    });
    document.getElementById('footSumMin').textContent = sum;
    document.getElementById('footSumH').textContent   = (sum / 60).toFixed(2).replace('.', ',');
}

// Posten auf der Rechnung ein-/ausblenden
async function toggleItemVisible(id, checked) {
    const data = await apiCall('toggle_invoice_item_visible', { id, visible: checked ? 1 : 0 });
    if (data.success) {
        document.getElementById('row-' + id)?.classList.toggle('item-hidden', !checked);
        updateSummary(data.data.totals, visibleRowCount());
        recalcItemsFooter();
    } else {
        Dialog.alert(t('common.error') + ': ' + (data.error || t('common.unknownError')));
        const cb = document.querySelector('#row-' + id + ' .ei-visible');
        if (cb) cb.checked = !checked;
    }
}

async function saveItem(id) {
    const eRow     = document.getElementById('edit-' + id);
    const date     = eRow.querySelector('.ei-date').value;
    const activity = eRow.querySelector('.ei-activity').value.trim();
    const comment  = eRow.querySelector('.ei-comment').value.trim();
    const minutes  = parseInt(eRow.querySelector('.ei-min').value, 10);

    if (!date || !activity || !minutes) { Dialog.alert(t('invItems.alertAllFields')); return; }

    const data = await apiCall('update_invoice_item', {
        id, date, activity, comment, duration_minutes: minutes
    });

    if (data.success) {
        const h      = minutes / 60;
        const amount = Math.round(h * RATE * 100) / 100;
        const vRow   = document.getElementById('row-' + id);
        const cells  = vRow.querySelectorAll('td');
        cells[1].textContent = fmtDate(date);
        cells[2].innerHTML   = escHtml(activity) + (comment ? '<br><small style="color:var(--text-muted)">' + escHtml(comment) + '</small>' : '');
        cells[3].textContent = minutes;
        cells[4].textContent = (minutes / 60).toFixed(2).replace('.', ',');
        cells[5].textContent = fmtEur(amount);
        hideEdit(id);
        updateSummary(data.data.totals, visibleRowCount());
        recalcItemsFooter();
    } else {
        Dialog.alert(t('common.error') + ': ' + (data.error || t('common.unknownError')));
    }
}

async function deleteItem(id) {
    if (!await Dialog.confirm(t('invItems.confirmDeleteItem'), { danger: true })) return;
    const data = await apiCall('delete_invoice_item', { id });
    if (data.success) {
        document.getElementById('row-'  + id)?.remove();
        document.getElementById('edit-' + id)?.remove();
        updateSummary(data.data.totals, visibleRowCount());
        recalcItemsFooter();
    } else {
        Dialog.alert(t('common.error') + ': ' + (data.error || t('common.unknownError')));
    }
}

document.getElementById('addBtn').addEventListener('click', async function() {
    const date     = document.getElementById('addDate').value;
    const activity = document.getElementById('addActivity').value.trim();
    const comment  = document.getElementById('addComment').value.trim();
    const minutes  = parseInt(document.getElementById('addMinutes').value, 10);
    const msg      = document.getElementById('addMsg');

    if (!date || !activity || !minutes) {
        msg.style.color = '#c0392b';
        msg.textContent = t('invItems.addRequired');
        return;
    }

    const data = await apiCall('add_invoice_item', {
        invoice_id: INVOICE_ID, date, activity, comment, duration_minutes: minutes
    });

    if (data.success) {
        const id     = data.data.id;
        const h      = minutes / 60;
        const amount = Math.round(h * RATE * 100) / 100;

        const tbody = document.querySelector('#itemsTable tbody') || createTableBody();

        const vRow = document.createElement('tr');
        vRow.id    = 'row-' + id;
        vRow.innerHTML =
            '<td style="text-align:center"><input type="checkbox" class="ei-visible" onchange="toggleItemVisible(' + id + ', this.checked)" checked></td>' +
            '<td>' + fmtDate(date) + '</td>' +
            '<td>' + escHtml(activity) + (comment ? '<br><small style="color:var(--text-muted)">' + escHtml(comment) + '</small>' : '') + '</td>' +
            '<td class="col-min">' + minutes + '</td>' +
            '<td class="col-h">' + (minutes / 60).toFixed(2).replace('.', ',') + '</td>' +
            '<td class="col-eur">' + fmtEur(amount) + '</td>' +
            '<td style="white-space:nowrap">' +
              '<button class="btn" onclick="showEdit(' + id + ')" style="font-size:11px;padding:2px 8px">' + escHtml(t('invItems.editShort')) + '</button> ' +
              '<button class="btn btn--danger" onclick="deleteItem(' + id + ')" style="font-size:11px;padding:2px 8px;margin-left:4px">' + escHtml(t('common.delete')) + '</button>' +
            '</td>';

        const eRow = document.createElement('tr');
        eRow.id        = 'edit-' + id;
        eRow.className = 'edit-row hidden';
        eRow.innerHTML =
            '<td></td>' +
            '<td><input type="date" class="ei-date" value="' + date + '"></td>' +
            '<td><input type="text" class="ei-activity" value="' + escAttr(activity) + '" placeholder="' + escAttr(t('common.activity')) + '">' +
                '<input type="text" class="ei-comment" value="' + escAttr(comment) + '" placeholder="' + escAttr(t('customers.colComment')) + '" style="margin-top:4px"></td>' +
            '<td class="col-min"><input type="number" class="ei-min" value="' + minutes + '" min="1" style="width:60px"></td>' +
            '<td colspan="2"></td>' +
            '<td style="white-space:nowrap">' +
              '<button class="btn btn--primary" onclick="saveItem(' + id + ')" style="font-size:11px;padding:2px 8px">' + escHtml(t('common.save')) + '</button> ' +
              '<button class="btn" onclick="hideEdit(' + id + ')" style="font-size:11px;padding:2px 8px;margin-left:4px">' + escHtml(t('common.cancel')) + '</button>' +
            '</td>';

        tbody.appendChild(vRow);
        tbody.appendChild(eRow);

        document.getElementById('addActivity').value = '';
        document.getElementById('addComment').value  = '';
        document.getElementById('addMinutes').value  = '';
        msg.textContent = '';

        updateSummary(data.data.totals, visibleRowCount());
        recalcItemsFooter();
    } else {
        msg.style.color = '#c0392b';
        msg.textContent = data.error || t('invItems.addError');
    }
});

function createTableBody() {
    const section = document.querySelector('.admin-section');
    const empty   = section.querySelector('.empty-message');
    if (empty) empty.remove();

    const wrapper = document.createElement('div');
    wrapper.className = 'table-wrapper';
    wrapper.innerHTML =
        '<table class="entries-table" id="itemsTable">' +
        '<thead><tr>' +
        '<th style="width:40px;text-align:center">' + escHtml(t('invItems.colVisible')) + '</th>' +
        '<th>' + escHtml(t('customers.colDate')) + '</th><th>' + escHtml(t('invItems.colActivityComment')) + '</th>' +
        '<th class="col-min">' + escHtml(t('entries.colMin')) + '</th><th class="col-h">' + escHtml(t('invItems.colHoursShort')) + '</th><th class="col-eur">' + escHtml(t('invItems.colAmount')) + '</th><th></th>' +
        '</tr></thead><tbody></tbody>' +
        '<tfoot><tr class="items-foot">' +
        '<td colspan="3" style="text-align:right">' + escHtml(t('invItems.sum')) + '</td>' +
        '<td class="col-min" id="footSumMin">0</td>' +
        '<td class="col-h" id="footSumH">0,00</td>' +
        '<td class="col-eur"></td><td></td>' +
        '</tr></tfoot></table>';
    section.appendChild(wrapper);
    return wrapper.querySelector('tbody');
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

function rteLink(btn) {
    const body = btn.closest('.rte-wrap').querySelector('.rte-body');
    const sel  = window.getSelection();
    const range = sel && sel.rangeCount > 0 ? sel.getRangeAt(0).cloneRange() : null;
    const url  = prompt(t('customers.urlPrompt'), 'https://');
    if (!url) return;
    body.focus();
    if (range) { sel.removeAllRanges(); sel.addRange(range); }
    document.execCommand('createLink', false, url);
}

function toggleTextMode() {
    const isText = document.getElementById('metaMode').value === 'text';
    document.getElementById('metaTextWrap').style.display      = isText ? 'block' : 'none';
    document.getElementById('metaTextHoursWrap').style.display = isText ? 'flex'  : 'none';
    document.getElementById('metaNetWrap').style.display       = isText ? 'flex'  : 'none';
}

document.getElementById('metaHours').addEventListener('input', function() {
    const hours = parseFloat(this.value) || 0;
    const rate  = parseFloat(document.getElementById('metaRate').value) || 0;
    document.getElementById('metaNet').value = (hours * rate).toFixed(2);
});

document.getElementById('metaSaveBtn').addEventListener('click', async function() {
    const btn = this;
    const msg = document.getElementById('metaMsg');
    btn.disabled = true;
    msg.style.color = '';
    msg.textContent = t('common.saving');

    const mode = document.getElementById('metaMode').value;
    const params = {
        invoice_id:          INVOICE_ID,
        invoice_date:        document.getElementById('metaDate').value,
        period_start:        document.getElementById('metaPeriodStart').value,
        period_end:          document.getElementById('metaPeriodEnd').value,
        invoice_mode:        mode,
        invoice_text:        document.getElementById('metaText').value,
        mail_template_html:  document.getElementById('metaMailHtml').innerHTML,
        tax_rate:            document.getElementById('metaTax').value,
        hourly_rate:         document.getElementById('metaRate').value,
    };
    if (mode === 'text') {
        params.total_minutes = Math.round(parseFloat(document.getElementById('metaHours').value) * 60);
        params.amount_net    = document.getElementById('metaNet').value;
    }

    const data = await apiCall('update_invoice_meta', params);
    if (data.success) {
        msg.style.color = '#27ae60';
        msg.textContent = t('invItems.savedCheck');
        if (data.data.totals || data.data.amount_net) {
            const t = data.data;
            updateSummary({
                total_minutes: t.total_minutes,
                amount_net:    t.amount_net,
                amount_gross:  t.amount_gross,
            }, rowCount());
        }
        setTimeout(() => { btn.disabled = false; msg.textContent = ''; }, 2500);
    } else {
        msg.style.color = '#c0392b';
        msg.textContent = data.error || t('common.error');
        btn.disabled = false;
    }
});
</script>
</body>
</html>
