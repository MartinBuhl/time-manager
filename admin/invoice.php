<?php
require_once __DIR__ . '/auth.php';

$customerId = filter_var($_GET['customer_id'] ?? 0, FILTER_VALIDATE_INT);
if (!$customerId) {
    header('Location: billing.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT id, name, billing_name, billing_street, billing_zip, billing_city,
            billing_email, billing_tax_id,
            contact_first_name, contact_last_name, contact_on_invoice,
            hourly_rate, projects, invoice_mode, invoice_text
     FROM tm_customers WHERE id = ? LIMIT 1'
);
$stmt->execute([$customerId]);
$customer = $stmt->fetch();
if (!$customer) {
    header('Location: billing.php');
    exit;
}

// Distinct projects for dropdown
$allProjects = db()->prepare(
    "SELECT DISTINCT project FROM tm_entries
     WHERE customer_id = ? AND billed_at IS NULL AND deleted_at IS NULL
       AND project IS NOT NULL AND project != ''
     ORDER BY project"
);
$allProjects->execute([$customerId]);
$allProjects = $allProjects->fetchAll(PDO::FETCH_COLUMN);

$filterProject = trim($_GET['project'] ?? '');

// Standard-Datumsbereich: ältester unabgerechneter Eintrag … heute
$minDateStmt = db()->prepare(
    "SELECT MIN(date) FROM tm_entries
     WHERE customer_id = ? AND billed_at IS NULL AND deleted_at IS NULL"
);
$minDateStmt->execute([$customerId]);
$minDate     = $minDateStmt->fetchColumn();
$defaultFrom = $minDate ?: date('Y-m-d');
$defaultTo   = date('Y-m-d');

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
if ($dateFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = $defaultFrom; }
if ($dateTo   === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $dateTo   = $defaultTo; }
$hasDateFilter = ($dateFrom !== $defaultFrom || $dateTo !== $defaultTo);

// Entries (with optional project + date filter)
$entrySql    = "SELECT e.id, e.activity, e.project, e.comment,
                       e.start_datetime, e.end_datetime, e.duration_minutes
                FROM tm_entries e
                WHERE e.customer_id = ? AND e.billed_at IS NULL AND e.deleted_at IS NULL";
$entryParams = [$customerId];
if ($filterProject !== '') {
    $entrySql    .= ' AND e.project = ?';
    $entryParams[] = $filterProject;
}
$entrySql    .= ' AND e.date >= ?';
$entryParams[] = $dateFrom;
$entrySql    .= ' AND e.date <= ?';
$entryParams[] = $dateTo;
$entrySql    .= ' ORDER BY e.start_datetime ASC';

$stmt = db()->prepare($entrySql);
$stmt->execute($entryParams);
$entries = $stmt->fetchAll();

// Redirect only when no filter is active and there are truly no entries
if (empty($entries) && $filterProject === '' && !$hasDateFilter) {
    header('Location: billing.php');
    exit;
}

// Config from DB
$invCompany       = cfg('invoice_company');
$invStreet        = cfg('invoice_street');
$invZip           = cfg('invoice_zip');
$invCity          = cfg('invoice_city');
$invEmail         = cfg('invoice_email');
$invPhone         = cfg('invoice_phone');
$invTaxId         = cfg('invoice_tax_id');
$invTaxNumber     = cfg('invoice_tax_number');
$invIban          = cfg('invoice_iban');
$invBic           = cfg('invoice_bic');
$invBank          = cfg('invoice_bank');
$invAccountHolder = cfg('invoice_account_holder');
$taxRate          = (int)cfg('invoice_tax_rate', '19');
$paymentDays      = (int)cfg('invoice_payment_days', '14');

$rate      = (float)$customer['hourly_rate'] ?: (float)cfg('invoice_hourly_rate', '85.00');
$totalMin  = $entries ? array_sum(array_column($entries, 'duration_minutes')) : 0;
$amountNet = $entries ? round(array_sum(array_map(
    fn($e) => roundToQuarter((int)$e['duration_minutes']) * $rate,
    $entries
)), 2) : 0.0;
$taxAmount   = round($amountNet * $taxRate / 100, 2);
$amountGross = round($amountNet + $taxAmount, 2);
$paymentDate = date('d.m.Y', strtotime('+' . $paymentDays . ' days'));
$todayStr    = date('d.m.Y');

$periodStart = $entries ? date('d.m.Y', strtotime($entries[0]['start_datetime'])) : $todayStr;
$periodEnd   = $entries ? date('d.m.Y', strtotime($entries[count($entries)-1]['end_datetime'])) : $todayStr;

$invoiceMode = $customer['invoice_mode'] ?? 'entries';
$invoiceText = $customer['invoice_text'] ?? '';
$custProjects = json_decode($customer['projects'] ?? '[]', true);
$firstProject = (is_array($custProjects) && !empty($custProjects)) ? trim($custProjects[0]['name'] ?? '') : '';
if ($invoiceText !== '') {
    $invoiceText = str_replace('{project}', $firstProject, $invoiceText);
}

$invPrefix     = cfg('invoice_number_prefix', 'RE-');
$invStart      = max(1, (int)cfg('invoice_number_start', '1'));
$invSeq        = (int)db()->query('SELECT COALESCE(MAX(invoice_seq), 0) FROM tm_invoices')->fetchColumn();
$invNextSeq    = $invSeq > 0 ? $invSeq + 1 : $invStart;
$invoiceNumber = $invPrefix . $invNextSeq;

function fmtEur(float $v): string {
    return number_format($v, 2, ',', '.') . ' €';
}
function roundToQuarter(int $min): float {
    return round($min / 60 / 0.25) * 0.25;
}
function fmtH(int $min): string {
    return number_format(roundToQuarter($min), 2, ',', '.');
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rechnungsvorschau – <?= h($customer['name']) ?></title>
<link rel="stylesheet" href="../assets/style.css">
<script src="../assets/dialog.js"></script>
<style>
.invoice-wrap {
    max-width: 860px;
    margin: 0 auto;
    padding: 20px;
}
.invoice-actions {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.invoice-paper {
    background: #fff;
    color: #222;
    padding: 48px 52px;
    box-shadow: 0 2px 16px rgba(0,0,0,0.18);
    border-radius: 4px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
    font-size: 13px;
    line-height: 1.5;
    display: flex;
    flex-direction: column;
    min-height: calc(297mm - 96px); /* A4 minus top+bottom padding */
    box-sizing: content-box;
}
.inv-push { flex: 1; }
.inv-header {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 32px;
}
.inv-sender {
    text-align: right;
    font-size: 12px;
    color: #444;
    line-height: 1.6;
}
.inv-sender strong {
    display: block;
    font-size: 15px;
    font-weight: 700;
    color: #111;
    margin-bottom: 2px;
}
.inv-number-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    font-size: 13px;
    font-weight: 600;
    color: #111;
    margin-bottom: 16px;
}
.inv-recipient {
    padding: 0 0 32px;
    font-size: 12px;
}
.inv-recipient p { margin: 2px 0; }
.inv-recipient .inv-rec-name { font-weight: 700; font-size: 13px; }
.inv-subject { margin-bottom: 20px; font-size: 13px; }
.inv-subject strong { font-weight: 600; }
.inv-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 12px; }
.inv-table th {
    background: #f0f4f8;
    padding: 8px 10px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #dde3ea;
    white-space: nowrap;
    color: #333;
}
.inv-table th.right, .inv-table td.right { text-align: right; }
.inv-table td { padding: 7px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
.inv-table tr:last-child td { border-bottom: none; }
.comment-cell { color: #666; font-size: 11px; }
.inv-totals { display: flex; justify-content: flex-end; margin-bottom: 32px; }
.inv-totals table { border-collapse: collapse; min-width: 260px; }
.inv-totals td { padding: 5px 10px; font-size: 13px; color: #333; }
.inv-totals td:last-child { text-align: right; font-weight: 600; }
.inv-totals .total-row td {
    border-top: 2px solid #111;
    font-size: 15px;
    font-weight: 700;
    color: #111;
    padding-top: 8px;
}
.inv-footer {
    border-top: 1px solid #ddd;
    padding-top: 16px;
    font-size: 11px;
    color: #666;
    display: flex;
    gap: 32px;
    flex-wrap: wrap;
}
.inv-footer div { flex: 1; min-width: 160px; }
.inv-footer strong { display: block; font-weight: 600; color: #444; margin-bottom: 3px; }
.entries-detail {
    background: #1a1a1a;
    color: #ddd;
    padding: 24px 32px;
    margin-top: 24px;
    border-radius: 4px;
    font-size: 12px;
}
.entries-detail h3 {
    margin: 0 0 14px;
    font-size: 14px;
    font-weight: 600;
    color: #fff;
}
.entries-detail table { width: 100%; border-collapse: collapse; }
.entries-detail th {
    text-align: left;
    padding: 6px 10px;
    border-bottom: 1px solid #333;
    font-weight: 600;
    color: #aaa;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.entries-detail th.right, .entries-detail td.right { text-align: right; }
.entries-detail td {
    padding: 6px 10px;
    border-bottom: 1px solid #2a2a2a;
    vertical-align: top;
}
.entries-detail tr:last-child td { border-bottom: none; }
.entries-detail .comment-cell { color: #888; font-size: 11px; }
.entries-detail tfoot td {
    border-top: 2px solid #444;
    border-bottom: none;
    padding-top: 8px;
    font-weight: 700;
    color: #fff;
}
.entries-detail .btn-icon {
    background: none;
    border: none;
    cursor: pointer;
    padding: 3px 5px;
    border-radius: 3px;
    color: #888;
    font-size: 13px;
    line-height: 1;
}
.entries-detail .btn-icon:hover { color: #ddd; background: rgba(255,255,255,0.08); }
.entries-detail .btn-icon.btn-del:hover { color: #f87171; }
.entry-edit-row td { padding: 10px 10px 12px !important; background: #1e1e1e; }
.entry-edit-form {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.entry-edit-form label { display: block; font-size: 10px; color: #888; margin-bottom: 3px; }
.entry-edit-form input[type="text"] {
    background: #2d2d2d;
    border: 1px solid #444;
    border-radius: 3px;
    color: #ddd;
    padding: 5px 8px;
    font-size: 12px;
    width: 100%;
    box-sizing: border-box;
}
.entry-edit-form input[type="text"]:focus { outline: none; border-color: #3b82f6; }
.entry-edit-form .field { display: flex; flex-direction: column; }
.entry-edit-form .field-wide { flex: 1; min-width: 160px; }
.entry-edit-form .field-dt { min-width: 160px; max-width: 200px; }
.btn-save-entry { background: #2563eb; color: #fff; border: none; border-radius: 4px;
                  padding: 5px 14px; font-size: 12px; cursor: pointer; }
.btn-save-entry:hover { background: #1d4ed8; }
.btn-cancel-entry { background: #374151; color: #ccc; border: none; border-radius: 4px;
                    padding: 5px 12px; font-size: 12px; cursor: pointer; margin-left: 4px; }
.btn-cancel-entry:hover { background: #4b5563; }
@media print {
    @page { size: A4; margin: 0; }
    body { background: #fff !important; }
    .invoice-actions { display: none !important; }
    .entries-detail { display: none !important; }
    .invoice-wrap { padding: 0; }
    .invoice-paper {
        box-shadow: none;
        border-radius: 0;
        padding: 18mm 20mm;
        min-height: 297mm;
        box-sizing: border-box;
    }
}
</style>
</head>
<body>
<div class="invoice-wrap">

    <div class="invoice-actions">
        <a href="billing.php" class="btn">&#8592; Zurück</a>
        <form method="get" style="display:flex;align-items:center;gap:8px;margin:0;flex-wrap:wrap">
            <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">
            <?php if (count($allProjects) > 0): ?>
            <select name="project" class="form-control" style="min-width:160px"
                    onchange="this.form.submit()">
                <option value="">Alle Projekte</option>
                <?php foreach ($allProjects as $p): ?>
                <option value="<?= h($p) ?>"<?= $filterProject === $p ? ' selected' : '' ?>><?= h($p) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text-muted)">Von
                <input type="date" name="date_from" value="<?= h($dateFrom) ?>"
                       class="form-control" style="width:auto" onchange="this.form.submit()">
            </label>
            <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text-muted)">Bis
                <input type="date" name="date_to" value="<?= h($dateTo) ?>"
                       class="form-control" style="width:auto" onchange="this.form.submit()">
            </label>
        </form>
        <?php if ($hasDateFilter || $filterProject !== ''): ?>
        <a href="?customer_id=<?= (int)$customerId ?>" class="btn" style="font-size:12px">Filter zurücksetzen</a>
        <?php endif; ?>
        <button class="btn btn--primary" id="billBtn"
                data-id="<?= (int)$customerId ?>"
                data-name="<?= h($customer['name']) ?>">Jetzt abrechnen</button>
        <span id="billMsg" style="font-size:12px; align-self:center"></span>
    </div>

    <?php if (empty($entries) && ($filterProject !== '' || $hasDateFilter)): ?>
    <div style="padding:20px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;margin-bottom:16px;color:#856404">
        Keine unabgerechneten Einträge
        <?php if ($filterProject !== ''): ?>für das Projekt <strong><?= h($filterProject) ?></strong><?php endif; ?>
        im Zeitraum <strong><?= h(date('d.m.Y', strtotime($dateFrom))) ?></strong>
        bis <strong><?= h(date('d.m.Y', strtotime($dateTo))) ?></strong> gefunden.
    </div>
    <?php endif; ?>

    <div class="invoice-paper">

        <div class="inv-header">
            <div class="inv-sender">
                <strong><?= h($invCompany) ?></strong>
                <?= h($invStreet) ?><br>
                <?= h($invZip) ?> <?= h($invCity) ?><br>
                <?php if ($invEmail): ?><?= h($invEmail) ?><br><?php endif; ?>
                <?php if ($invPhone): ?><?= h($invPhone) ?><br><?php endif; ?>
                <?php if ($invTaxId): ?>USt-IdNr.: <?= h($invTaxId) ?><?php endif; ?>
            </div>
        </div>

        <div class="inv-recipient">
            <p class="inv-rec-name"><?= h($customer['billing_name'] ?: $customer['name']) ?></p>
            <?php
                $contactName = trim(($customer['contact_first_name'] ?? '') . ' ' . ($customer['contact_last_name'] ?? ''));
                if ($customer['contact_on_invoice'] && $contactName !== ''):
            ?>
                <p>z.&nbsp;Hd. <?= h($contactName) ?></p>
            <?php endif; ?>
            <?php if ($customer['billing_street']): ?>
                <p><?= h($customer['billing_street']) ?></p>
            <?php endif; ?>
            <?php if ($customer['billing_zip'] || $customer['billing_city']): ?>
                <p><?= h(trim($customer['billing_zip'] . ' ' . $customer['billing_city'])) ?></p>
            <?php endif; ?>
            <?php if ($customer['billing_tax_id']): ?>
                <p style="margin-top:6px">USt-IdNr.: <?= h($customer['billing_tax_id']) ?></p>
            <?php endif; ?>
        </div>

        <div class="inv-number-row">
            <span>Rechnung Nr. <?= h($invoiceNumber) ?></span>
            <span><?= $todayStr ?></span>
        </div>

        <div class="inv-subject">
            Leistungen Zeitraum: <?= $periodStart ?><?= $periodStart !== $periodEnd ? ' – ' . $periodEnd : '' ?>
        </div>

        <?php if ($invoiceMode === 'text'): ?>
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Beschreibung</th>
                    <th class="right">Std.</th>
                    <th class="right">Betrag</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= h($invoiceText) ?></td>
                    <td class="right"><?= fmtH($totalMin) ?></td>
                    <td class="right"><?= fmtEur($amountNet) ?></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Tätigkeit &amp; Kommentar</th>
                    <th class="right">Std.</th>
                    <th class="right">Betrag</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $e):
                $hours  = roundToQuarter((int)$e['duration_minutes']);
                $amount = round($hours * $rate, 2);
            ?>
                <tr>
                    <td class="col-time"><?= h(date('d.m.Y', strtotime($e['start_datetime']))) ?></td>
                    <td>
                        <?= h($e['activity']) ?>
                        <?php if ($e['comment']): ?>
                            <br><span class="comment-cell"><?= h($e['comment']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="right"><?= fmtH((int)$e['duration_minutes']) ?></td>
                    <td class="right"><?= fmtEur($amount) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="inv-totals">
            <table>
                <tr><td>Nettobetrag</td><td><?= fmtEur($amountNet) ?></td></tr>
                <tr><td>zzgl. <?= $taxRate ?> % MwSt.</td><td><?= fmtEur($taxAmount) ?></td></tr>
                <tr class="total-row"><td>Gesamtbetrag</td><td><?= fmtEur($amountGross) ?></td></tr>
            </table>
        </div>

        <div class="inv-push"></div>
        <div class="inv-footer">
            <div>
                <strong>Bankverbindung</strong>
                <?php if ($invAccountHolder): ?>Kontoinhaber: <?= h($invAccountHolder) ?><br><?php endif; ?>
                Bank: <?= h($invBank) ?><br>
                IBAN: <?= h($invIban) ?><br>
                BIC:  <?= h($invBic) ?>
            </div>
            <?php if ($invTaxNumber): ?>
            <div>
                <strong>Steuernummer</strong>
                <?= h($invTaxNumber) ?>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <?php if (!empty($entries)): ?>
    <div class="entries-detail">
        <h3>Arbeitszeit-Einträge<?php if ($invoiceMode === 'text'): ?> <span style="font-weight:400;color:#888;font-size:12px">(nicht Teil der Rechnung)</span><?php endif; ?></h3>
        <table>
            <thead>
                <tr>
                    <th style="white-space:nowrap">Datum</th>
                    <th>Tätigkeit &amp; Kommentar</th>
                    <th class="right" style="white-space:nowrap">Min.</th>
                    <th style="width:56px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $e): ?>
                <tr id="entry-row-<?= (int)$e['id'] ?>">
                    <td style="white-space:nowrap"><?= h(date('d.m.Y', strtotime($e['start_datetime']))) ?></td>
                    <td>
                        <?= h($e['activity']) ?>
                        <?php if ($e['project']): ?><br><span style="color:#6b9cce;font-size:11px"><?= h($e['project']) ?></span><?php endif; ?>
                        <?php if ($e['comment']): ?><br><span class="comment-cell"><?= h($e['comment']) ?></span><?php endif; ?>
                    </td>
                    <td class="right"><?= (int)$e['duration_minutes'] ?></td>
                    <td style="white-space:nowrap;padding-right:6px">
                        <button class="btn-icon" onclick="toggleEntryEdit(<?= (int)$e['id'] ?>)" title="Bearbeiten">✏</button>
                        <button class="btn-icon btn-del" onclick="trashEntry(<?= (int)$e['id'] ?>)" title="In Papierkorb">🗑</button>
                    </td>
                </tr>
                <tr id="entry-edit-<?= (int)$e['id'] ?>" class="entry-edit-row" style="display:none">
                    <td colspan="4">
                        <div class="entry-edit-form">
                            <div class="field field-dt">
                                <label>Start (JJJJ-MM-TT HH:MM:SS)</label>
                                <input type="text" id="estart-<?= (int)$e['id'] ?>" value="<?= h($e['start_datetime']) ?>">
                            </div>
                            <div class="field field-dt">
                                <label>Ende (JJJJ-MM-TT HH:MM:SS)</label>
                                <input type="text" id="eend-<?= (int)$e['id'] ?>" value="<?= h($e['end_datetime']) ?>">
                            </div>
                            <div class="field field-wide">
                                <label>Tätigkeit</label>
                                <input type="text" id="eactivity-<?= (int)$e['id'] ?>" value="<?= h($e['activity'] ?? '') ?>">
                            </div>
                            <div class="field field-wide">
                                <label>Kommentar</label>
                                <input type="text" id="ecomment-<?= (int)$e['id'] ?>" value="<?= h($e['comment'] ?? '') ?>">
                            </div>
                            <div class="field" style="justify-content:flex-end">
                                <label>&nbsp;</label>
                                <div>
                                    <button class="btn-save-entry" onclick="saveEntry(<?= (int)$e['id'] ?>, <?= (int)$customerId ?>)">Speichern</button>
                                    <button class="btn-cancel-entry" onclick="toggleEntryEdit(<?= (int)$e['id'] ?>)">Abbrechen</button>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="right">Summe</td>
                    <td class="right"><?= $totalMin ?></td>
                    <td></td>
                </tr>
                <tr>
                    <td colspan="2" class="right">Summe (h):</td>
                    <td class="right"><?= number_format($totalMin / 60, 2, ',', '.') ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
const CSRF           = <?= json_encode($_SESSION['csrf_token']) ?>;
const FILTER_PROJECT = <?= json_encode($filterProject) ?>;
const DATE_FROM      = <?= json_encode($dateFrom) ?>;
const DATE_TO        = <?= json_encode($dateTo) ?>;

async function apiCall(action, params) {
    const body = new URLSearchParams({ action, ...params });
    const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body });
    return res.json();
}

function toggleEntryEdit(id) {
    const row = document.getElementById('entry-edit-' + id);
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

async function saveEntry(id, customerId) {
    const start    = document.getElementById('estart-'    + id).value.trim();
    const end      = document.getElementById('eend-'      + id).value.trim();
    const activity = document.getElementById('eactivity-' + id).value.trim();
    const comment  = document.getElementById('ecomment-'  + id).value.trim();
    const res = await apiCall('update_entry', {
        id, customer_id: customerId,
        start_datetime: start, end_datetime: end,
        activity, comment
    });
    if (res.success) {
        location.reload();
    } else {
        Dialog.alert('Fehler: ' + (res.error || 'Unbekannter Fehler'));
    }
}

async function trashEntry(id) {
    if (!await Dialog.confirm('Diesen Eintrag in den Papierkorb verschieben?', { danger: true })) return;
    const res = await apiCall('delete_entry', { id });
    if (res.success) {
        location.reload();
    } else {
        Dialog.alert('Fehler: ' + (res.error || 'Unbekannter Fehler'));
    }
}

(function() {
    const billBtn      = document.getElementById('billBtn');
    const msgEl        = document.getElementById('billMsg');
    const customerId   = billBtn.dataset.id;

    billBtn.addEventListener('click', function() {
        billBtn.style.display = 'none';
        msgEl.style.color = '';
        msgEl.innerHTML =
            '<span style="margin-right:8px;font-size:12px">Einträge als abgerechnet markieren?</span>' +
            '<button id="billYes" class="btn btn--primary" style="font-size:12px">Ja, abrechnen</button>&nbsp;' +
            '<button id="billNo" class="btn" style="font-size:12px">Abbrechen</button>';

        document.getElementById('billNo').addEventListener('click', function() {
            msgEl.innerHTML = '';
            billBtn.style.display = '';
        });

        document.getElementById('billYes').addEventListener('click', async function() {
            msgEl.innerHTML = '<span style="font-size:12px;color:var(--text-muted)">Wird erstellt…</span>';

            try {
                const params = { action: 'mark_billed', customer_id: customerId };
                if (FILTER_PROJECT !== '') params.project = FILTER_PROJECT;
                if (DATE_FROM !== '') params.date_from = DATE_FROM;
                if (DATE_TO !== '') params.date_to = DATE_TO;
                const body = new URLSearchParams(params);
                const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body });
                const data = await res.json();

                if (data.success) {
                    const d = data.data;
                    const raw     = (d.total_min / 60).toFixed(2).replace('.', ',');
                    const rounded = Number(d.total_rounded_h).toFixed(2).replace('.', ',');
                    const net     = Number(d.amount_net).toFixed(2).replace('.', ',');
                    const rate    = Number(d.rate).toFixed(2).replace('.', ',');
                    msgEl.style.color = '#27ae60';
                    msgEl.innerHTML = '<strong>' + d.invoice_number + '</strong> erstellt &mdash; '
                        + raw + ' h (roh) &rarr; '
                        + rounded + ' h (gerundet) &times; ' + rate + ' &euro; = ' + net + ' &euro;'
                        + ' &mdash; Weiterleitung…';
                    setTimeout(() => { window.location.href = 'invoices.php'; }, 5000);
                } else {
                    msgEl.style.color = '#c0392b';
                    msgEl.innerHTML   = data.error || 'Fehler beim Abrechnen.';
                    billBtn.style.display = '';
                }
            } catch(e) {
                msgEl.style.color = '#c0392b';
                msgEl.innerHTML   = 'Serverfehler.';
                billBtn.style.display = '';
            }
        });
    });
})();
</script>
</body>
</html>
