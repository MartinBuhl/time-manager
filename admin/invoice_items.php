<?php
require_once __DIR__ . '/auth.php';

$invoiceId = filter_var($_GET['invoice_id'] ?? 0, FILTER_VALIDATE_INT);
if (!$invoiceId) { header('Location: invoices.php'); exit; }

$stmt = db()->prepare(
    'SELECT i.id, i.invoice_number, i.total_minutes, i.amount_net, i.amount_gross,
            i.pdf_file, i.created_at,
            c.name AS customer_name, c.id AS customer_id, c.hourly_rate
     FROM tm_invoices i
     LEFT JOIN tm_customers c ON c.id = i.customer_id
     WHERE i.id = ? LIMIT 1'
);
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();
if (!$invoice) { header('Location: invoices.php'); exit; }

$rate    = (float)($invoice['hourly_rate'] ?: cfg('invoice_hourly_rate', '85.00'));
$taxRate = (int)cfg('invoice_tax_rate', '19');

$stmt = db()->prepare(
    'SELECT id, date, activity, comment, duration_minutes, sort_order
     FROM tm_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id'
);
$stmt->execute([$invoiceId]);
$items = $stmt->fetchAll();

// Auto-populate from linked entries if no items exist yet
if (empty($items)) {
    $eStmt = db()->prepare(
        'SELECT id, date, activity, comment, duration_minutes
         FROM tm_entries WHERE invoice_id = ? AND deleted_at IS NULL
         ORDER BY date ASC, start_datetime ASC'
    );
    $eStmt->execute([$invoiceId]);
    $linked = $eStmt->fetchAll();

    if (!empty($linked)) {
        $ins = db()->prepare(
            'INSERT INTO tm_invoice_items
             (invoice_id, entry_id, date, activity, comment, duration_minutes, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($linked as $idx => $le) {
            $ins->execute([
                $invoiceId, (int)$le['id'], $le['date'],
                $le['activity'], $le['comment'],
                (int)$le['duration_minutes'], $idx + 1,
            ]);
        }
        // Recalculate stored totals
        $an = 0.0; $rh = 0.0;
        foreach ($linked as $le) {
            $h = round((int)$le['duration_minutes'] / 15) * 0.25;
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

function fmtH(int $min): string    { return number_format(round($min / 15) * 0.25, 2, ',', '.') . ' h'; }
function fmtEur(float $a): string  { return number_format($a, 2, ',', '.') . ' €'; }
function fmtDate(?string $d): string { return $d ? date('d.m.Y', strtotime($d)) : ''; }

$totalMinutes = (int)$invoice['total_minutes'];
$amountNet    = (float)$invoice['amount_net'];
$amountGross  = (float)$invoice['amount_gross'];
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rechnungsposten <?= h($invoice['invoice_number']) ?></title>
<link rel="stylesheet" href="../assets/style.css">
<style>
.summary-bar { display:flex; gap:24px; flex-wrap:wrap; margin-bottom:16px; font-size:13px; color:var(--text-muted); }
.summary-bar strong { color:var(--text); }
.add-form { background:var(--bg-card); border:1px solid var(--border); border-radius:8px;
            padding:16px 20px; margin-bottom:20px; }
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
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Rechnungsposten <?= h($invoice['invoice_number']) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo;
                <a href="invoices.php">Rechnungen</a> &rsaquo;
                <?= h($invoice['invoice_number']) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button class="btn btn--primary" id="regenBtn">PDF neu erstellen</button>
            <a href="invoices.php" class="btn-logout">&#8592; Rechnungen</a>
        </div>
    </div>

    <div class="admin-section">

        <div class="summary-bar" id="summaryBar">
            <span>Kunde: <strong><?= h($invoice['customer_name'] ?? '—') ?></strong></span>
            <span><strong id="sumItems"><?= count($items) ?></strong> Posten</span>
            <span><strong id="sumH"><?= fmtH($totalMinutes) ?></strong></span>
            <span>Netto: <strong id="sumNet"><?= fmtEur($amountNet) ?></strong></span>
            <span>Brutto: <strong id="sumGross"><?= fmtEur($amountGross) ?></strong></span>
            <span style="color:var(--text-muted);font-size:12px"><?= number_format($rate, 2, ',', '.') ?> €/h</span>
        </div>

        <div class="add-form">
            <h3>Neuen Posten hinzufügen</h3>
            <div class="add-row">
                <div>
                    <label>Datum</label>
                    <input type="date" id="addDate" class="f-date" value="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label>Tätigkeit</label>
                    <input type="text" id="addActivity" class="f-activity" placeholder="Tätigkeit">
                </div>
                <div style="flex:1">
                    <label>Kommentar</label>
                    <input type="text" id="addComment" class="f-comment" placeholder="Kommentar (optional)">
                </div>
                <div>
                    <label>Minuten</label>
                    <input type="number" id="addMinutes" class="f-min" min="1" placeholder="Min">
                </div>
                <div>
                    <label>&nbsp;</label>
                    <button class="btn btn--primary" id="addBtn">Hinzufügen</button>
                </div>
            </div>
            <div id="addMsg" style="margin-top:8px;font-size:12px"></div>
        </div>

        <?php if (empty($items)): ?>
            <p class="empty-message">Keine Posten vorhanden. Bitte oben einen Posten hinzufügen.</p>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="entries-table" id="itemsTable">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Tätigkeit &amp; Kommentar</th>
                        <th class="col-min">Min</th>
                        <th class="col-h">Std.</th>
                        <th class="col-eur">Betrag</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item):
                    $itemH   = round($item['duration_minutes'] / 15) * 0.25;
                    $itemEur = round($itemH * $rate, 2);
                ?>
                    <tr id="row-<?= (int)$item['id'] ?>">
                        <td><?= h(fmtDate($item['date'])) ?></td>
                        <td>
                            <?= h($item['activity']) ?>
                            <?php if ($item['comment']): ?>
                                <br><small style="color:var(--text-muted)"><?= h($item['comment']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="col-min"><?= (int)$item['duration_minutes'] ?></td>
                        <td class="col-h"><?= number_format($itemH, 2, ',', '.') ?></td>
                        <td class="col-eur"><?= fmtEur($itemEur) ?></td>
                        <td style="white-space:nowrap">
                            <button class="btn" onclick="showEdit(<?= (int)$item['id'] ?>)" style="font-size:11px;padding:2px 8px">Bearb.</button>
                            <button class="btn btn--danger" onclick="deleteItem(<?= (int)$item['id'] ?>)" style="font-size:11px;padding:2px 8px;margin-left:4px">Löschen</button>
                        </td>
                    </tr>
                    <tr id="edit-<?= (int)$item['id'] ?>" class="edit-row hidden">
                        <td><input type="date" class="ei-date" value="<?= h($item['date']) ?>"></td>
                        <td>
                            <input type="text" class="ei-activity" value="<?= h($item['activity']) ?>" placeholder="Tätigkeit">
                            <input type="text" class="ei-comment" value="<?= h($item['comment'] ?? '') ?>" placeholder="Kommentar" style="margin-top:4px">
                        </td>
                        <td class="col-min"><input type="number" class="ei-min" value="<?= (int)$item['duration_minutes'] ?>" min="1" style="width:60px"></td>
                        <td colspan="2"></td>
                        <td style="white-space:nowrap">
                            <button class="btn btn--primary" onclick="saveItem(<?= (int)$item['id'] ?>)" style="font-size:11px;padding:2px 8px">Speichern</button>
                            <button class="btn" onclick="hideEdit(<?= (int)$item['id'] ?>)" style="font-size:11px;padding:2px 8px;margin-left:4px">Abbrechen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
const CSRF      = <?= json_encode($_SESSION['csrf_token']) ?>;
const INVOICE_ID = <?= (int)$invoiceId ?>;
const RATE       = <?= json_encode($rate) ?>;
const TAX_RATE   = <?= json_encode($taxRate) ?>;

function fmtH(min) {
    return (Math.round(min / 15) * 0.25).toFixed(2).replace('.', ',') + ' h';
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

async function saveItem(id) {
    const eRow     = document.getElementById('edit-' + id);
    const date     = eRow.querySelector('.ei-date').value;
    const activity = eRow.querySelector('.ei-activity').value.trim();
    const comment  = eRow.querySelector('.ei-comment').value.trim();
    const minutes  = parseInt(eRow.querySelector('.ei-min').value, 10);

    if (!date || !activity || !minutes) { alert('Bitte alle Pflichtfelder ausfüllen.'); return; }

    const data = await apiCall('update_invoice_item', {
        id, date, activity, comment, duration_minutes: minutes
    });

    if (data.success) {
        const h      = Math.round(minutes / 15) * 0.25;
        const amount = Math.round(h * RATE * 100) / 100;
        const vRow   = document.getElementById('row-' + id);
        const cells  = vRow.querySelectorAll('td');
        cells[0].textContent = fmtDate(date);
        cells[1].innerHTML   = escHtml(activity) + (comment ? '<br><small style="color:var(--text-muted)">' + escHtml(comment) + '</small>' : '');
        cells[2].textContent = minutes;
        cells[3].textContent = h.toFixed(2).replace('.', ',');
        cells[4].textContent = fmtEur(amount);
        hideEdit(id);
        updateSummary(data.data.totals, rowCount());
    } else {
        alert('Fehler: ' + (data.error || 'Unbekannt'));
    }
}

async function deleteItem(id) {
    if (!confirm('Posten löschen?')) return;
    const data = await apiCall('delete_invoice_item', { id });
    if (data.success) {
        document.getElementById('row-'  + id)?.remove();
        document.getElementById('edit-' + id)?.remove();
        updateSummary(data.data.totals, rowCount());
    } else {
        alert('Fehler: ' + (data.error || 'Unbekannt'));
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
        msg.textContent = 'Datum, Tätigkeit und Minuten sind Pflichtfelder.';
        return;
    }

    const data = await apiCall('add_invoice_item', {
        invoice_id: INVOICE_ID, date, activity, comment, duration_minutes: minutes
    });

    if (data.success) {
        const id     = data.data.id;
        const h      = Math.round(minutes / 15) * 0.25;
        const amount = Math.round(h * RATE * 100) / 100;

        const tbody = document.querySelector('#itemsTable tbody') || createTableBody();

        const vRow = document.createElement('tr');
        vRow.id    = 'row-' + id;
        vRow.innerHTML =
            '<td>' + fmtDate(date) + '</td>' +
            '<td>' + escHtml(activity) + (comment ? '<br><small style="color:var(--text-muted)">' + escHtml(comment) + '</small>' : '') + '</td>' +
            '<td class="col-min">' + minutes + '</td>' +
            '<td class="col-h">' + h.toFixed(2).replace('.', ',') + '</td>' +
            '<td class="col-eur">' + fmtEur(amount) + '</td>' +
            '<td style="white-space:nowrap">' +
              '<button class="btn" onclick="showEdit(' + id + ')" style="font-size:11px;padding:2px 8px">Bearb.</button> ' +
              '<button class="btn btn--danger" onclick="deleteItem(' + id + ')" style="font-size:11px;padding:2px 8px;margin-left:4px">Löschen</button>' +
            '</td>';

        const eRow = document.createElement('tr');
        eRow.id        = 'edit-' + id;
        eRow.className = 'edit-row hidden';
        eRow.innerHTML =
            '<td><input type="date" class="ei-date" value="' + date + '"></td>' +
            '<td><input type="text" class="ei-activity" value="' + escAttr(activity) + '" placeholder="Tätigkeit">' +
                '<input type="text" class="ei-comment" value="' + escAttr(comment) + '" placeholder="Kommentar" style="margin-top:4px"></td>' +
            '<td class="col-min"><input type="number" class="ei-min" value="' + minutes + '" min="1" style="width:60px"></td>' +
            '<td colspan="2"></td>' +
            '<td style="white-space:nowrap">' +
              '<button class="btn btn--primary" onclick="saveItem(' + id + ')" style="font-size:11px;padding:2px 8px">Speichern</button> ' +
              '<button class="btn" onclick="hideEdit(' + id + ')" style="font-size:11px;padding:2px 8px;margin-left:4px">Abbrechen</button>' +
            '</td>';

        tbody.appendChild(vRow);
        tbody.appendChild(eRow);

        document.getElementById('addActivity').value = '';
        document.getElementById('addComment').value  = '';
        document.getElementById('addMinutes').value  = '';
        msg.textContent = '';

        updateSummary(data.data.totals, rowCount());
    } else {
        msg.style.color = '#c0392b';
        msg.textContent = data.error || 'Fehler beim Hinzufügen.';
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
        '<th>Datum</th><th>Tätigkeit &amp; Kommentar</th>' +
        '<th class="col-min">Min</th><th class="col-h">Std.</th><th class="col-eur">Betrag</th><th></th>' +
        '</tr></thead><tbody></tbody></table>';
    section.appendChild(wrapper);
    return wrapper.querySelector('tbody');
}

document.getElementById('regenBtn').addEventListener('click', async function() {
    const btn = this;
    btn.disabled    = true;
    btn.textContent = 'Wird erstellt…';

    const data = await apiCall('regenerate_invoice', { invoice_id: INVOICE_ID });

    if (data.success) {
        btn.textContent = 'PDF erstellt ✓';
        if (data.data.totals) updateSummary(data.data.totals, rowCount());
        if (data.data.pdf_file) {
            setTimeout(() => {
                window.open('invoice_download.php?type=pdf&file=' + encodeURIComponent(data.data.pdf_file), '_blank', 'noopener');
            }, 300);
        }
        setTimeout(() => { btn.disabled = false; btn.textContent = 'PDF neu erstellen'; }, 3000);
    } else {
        alert('Fehler: ' + (data.error || 'Unbekannt'));
        btn.disabled    = false;
        btn.textContent = 'PDF neu erstellen';
    }
});

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}
</script>
</body>
</html>
