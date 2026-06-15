<?php
require_once __DIR__ . '/auth.php';

$allCustomers = db()->query(
    'SELECT id, name FROM tm_customers ORDER BY name ASC'
)->fetchAll();

$stmt = db()->query(
    "SELECT c.id, c.name,
            COALESCE(c.hourly_rate, 0) as hourly_rate,
            COUNT(e.id)               as entry_count,
            SUM(e.duration_minutes)   as total_minutes,
            MIN(DATE(e.start_datetime)) as period_start,
            MAX(DATE(e.end_datetime))   as period_end
     FROM tm_entries e
     JOIN tm_customers c ON c.id = e.customer_id
     WHERE e.billed_at IS NULL AND e.deleted_at IS NULL AND e.billable = 1
       AND c.billable = 1 AND c.active = 1
     GROUP BY c.id"
);
$customers = $stmt->fetchAll();

// Betrag pro Kunde berechnen und nach Betrag absteigend sortieren
$globalRate = (float)cfg('invoice_hourly_rate', '85.00');
foreach ($customers as &$cRow) {
    $r = (float)$cRow['hourly_rate'] ?: $globalRate;
    $cRow['rate']       = $r;
    $cRow['amount_net'] = round((int)$cRow['total_minutes'] / 60 * $r, 2);
}
unset($cRow);
usort($customers, fn($a, $b) => $b['amount_net'] <=> $a['amount_net']);

// Alle offenen Einträge je Kunde für die Detailansicht vorladen
$entriesByCustomer = [];
$entryStmt = db()->query(
    "SELECT e.customer_id, e.date, e.activity, e.project, e.comment,
            e.start_datetime, e.end_datetime, e.duration_minutes
     FROM tm_entries e
     JOIN tm_customers c ON c.id = e.customer_id
     WHERE e.billed_at IS NULL AND e.deleted_at IS NULL AND e.billable = 1
       AND c.billable = 1 AND c.active = 1
     ORDER BY e.start_datetime ASC"
);
foreach ($entryStmt->fetchAll() as $row) {
    $entriesByCustomer[(int)$row['customer_id']][] = $row;
}

function fmtH(int $min): string
{
    return number_format($min / 60, 2, ',', '.') . ' h';
}

function fmtEur(float $amount): string
{
    return number_format($amount, 2, ',', '.') . ' €';
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Abrechnung – Administration</title>
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<style>
.detail-row > td { background: var(--hover-bg); }
.detail-box { padding: 12px 16px 16px; }
.detail-table { width: 100%; border-collapse: collapse; font-size: 13px; color: var(--text); }
.detail-table th {
    text-align: left;
    padding: 6px 10px;
    border-bottom: 1px solid var(--card-border);
    font-weight: 600;
    color: var(--text-muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.detail-table td { padding: 6px 10px; border-bottom: 1px solid var(--card-border); vertical-align: top; }
.detail-table tbody tr:last-child td { border-bottom: none; }
.detail-table tfoot td {
    border-top: 2px solid var(--card-border);
    border-bottom: none;
    padding-top: 8px;
    font-weight: 700;
    color: var(--text);
}
.detail-table .col-dur { text-align: right; white-space: nowrap; }
.detail-project { color: #3b82f6; font-size: 12px; }
.detail-comment { color: var(--text-muted); font-size: 12px; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Abrechnung</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo; Abrechnung
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button type="button" class="btn" id="btnShowBill" onclick="showBill()">Abgerechnet setzen</button>
            <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
        </div>
    </div>

    <div id="billingMsg" style="display:none;margin-bottom:16px"></div>

    <div class="admin-section" id="listSection">
        <h2>Nicht abgerechnete Arbeitszeit</h2>

        <?php if (empty($customers)): ?>
            <p class="empty-message">Keine offenen Abrechnungen vorhanden.</p>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th>Kunde</th>
                        <th>Zeitraum</th>
                        <th>Einträge</th>
                        <th class="col-dur">Stunden</th>
                        <th class="col-dur">Satz (€/h)</th>
                        <th class="col-dur">Betrag (netto)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $c):
                    $rate      = (float)$c['rate'];
                    $amountNet = (float)$c['amount_net'];
                    $period    = date('d.m.Y', strtotime($c['period_start']));
                    if ($c['period_start'] !== $c['period_end']) {
                        $period .= ' – ' . date('d.m.Y', strtotime($c['period_end']));
                    }
                ?>
                    <tr class="entry-row">
                        <td><?= h($c['name']) ?></td>
                        <td class="col-time"><?= h($period) ?></td>
                        <td><?= (int)$c['entry_count'] ?></td>
                        <td class="col-dur"><?= fmtH((int)$c['total_minutes']) ?></td>
                        <td class="col-dur">
                            <?= number_format($rate, 2, ',', '.') ?>
                            <?php if ((float)$c['hourly_rate'] <= 0): ?>
                                <br><span style="color:var(--text-muted);font-size:10px">Standard</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-dur"><?= fmtEur($amountNet) ?></td>
                        <td style="white-space:nowrap">
                            <a href="invoice.php?customer_id=<?= (int)$c['id'] ?>" class="btn">
                                Prüfen
                            </a>
                            <button type="button" class="btn" onclick="toggleDetails(<?= (int)$c['id'] ?>)"
                                    id="detailsBtn-<?= (int)$c['id'] ?>">Details</button>
                        </td>
                    </tr>
                    <tr class="detail-row" id="detailRow-<?= (int)$c['id'] ?>" style="display:none">
                        <td colspan="7" style="padding:0">
                            <div class="detail-box">
                                <?php $detailMin = array_sum(array_map('intval', array_column($entriesByCustomer[(int)$c['id']] ?? [], 'duration_minutes'))); ?>
                                <table class="detail-table">
                                    <thead>
                                        <tr>
                                            <th style="white-space:nowrap">Datum</th>
                                            <th style="white-space:nowrap">Zeit</th>
                                            <th class="col-dur" style="white-space:nowrap">Min</th>
                                            <th>Kunde</th>
                                            <th>Projekt</th>
                                            <th>Tätigkeit</th>
                                            <th>Kommentar</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach (($entriesByCustomer[(int)$c['id']] ?? []) as $e): ?>
                                        <tr>
                                            <td style="white-space:nowrap"><?= h(date('d.m.Y', strtotime($e['start_datetime']))) ?></td>
                                            <td style="white-space:nowrap"><?= h(substr($e['start_datetime'], 11, 5)) ?>–<?= h(substr($e['end_datetime'], 11, 5)) ?></td>
                                            <td class="col-dur"><?= (int)$e['duration_minutes'] ?></td>
                                            <td><?= h($c['name']) ?></td>
                                            <td class="detail-project"><?= $e['project'] ? h($e['project']) : '' ?></td>
                                            <td><?= h($e['activity']) ?></td>
                                            <td class="detail-comment"><?= $e['comment'] ? h($e['comment']) : '' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2" class="col-dur">Summe (Min.)</td>
                                            <td class="col-dur"><?= $detailMin ?></td>
                                            <td colspan="4"></td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" class="col-dur">Summe (h)</td>
                                            <td class="col-dur"><?= number_format($detailMin / 60, 2, ',', '.') ?></td>
                                            <td colspan="4"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
                    <?php foreach ($allCustomers as $c): ?>
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

</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

function toggleDetails(id) {
    const row = document.getElementById('detailRow-' + id);
    const btn = document.getElementById('detailsBtn-' + id);
    const open = row.style.display === 'none';
    row.style.display = open ? 'table-row' : 'none';
    if (btn) btn.textContent = open ? 'Schließen' : 'Details';
}

function showBill() {
    document.getElementById('listSection').classList.add('hidden');
    document.getElementById('billSection').classList.remove('hidden');
    document.getElementById('btnShowBill').classList.add('hidden');
}

function hideBill() {
    document.getElementById('billSection').classList.add('hidden');
    document.getElementById('listSection').classList.remove('hidden');
    document.getElementById('btnShowBill').classList.remove('hidden');
    document.getElementById('billMsg').innerHTML = '';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
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
        const body = new URLSearchParams({ action: 'set_billed_until', customer_id: customerId, cutoff_date: cutoffDate });
        const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body });
        const data = await res.json();
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
</script>
</body>
</html>
