<?php
require_once __DIR__ . '/auth.php';

$customerFilter = filter_var($_GET['customer_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$view = (($_GET['view'] ?? 'active') === 'archive') ? 'archive' : 'active';

$customers = db()->query(
    'SELECT DISTINCT c.id, c.name
     FROM tm_invoices i
     JOIN tm_customers c ON c.id = i.customer_id
     ORDER BY c.name ASC'
)->fetchAll();

// Eine Rechnung gilt als archiviert, sobald ein Mailspool-Eintrag archiviert wurde
$archivedExpr = 'EXISTS (SELECT 1 FROM tm_mail_spool m
                         WHERE m.invoice_id = i.id AND m.archived_at IS NOT NULL)';

$baseConditions = [];
$params         = [];
if ($customerFilter > 0) {
    $baseConditions[] = 'i.customer_id = ?';
    $params[]         = $customerFilter;
}

// Reiter Aktiv blendet archivierte Rechnungen aus, Reiter Archiv zeigt nur diese
$conditions   = $baseConditions;
$conditions[] = $view === 'archive' ? $archivedExpr : 'NOT ' . $archivedExpr;
$where        = 'WHERE ' . implode(' AND ', $conditions);

// Zähler für beide Reiter (Kundenfilter berücksichtigt)
$baseWhere  = $baseConditions ? 'WHERE ' . implode(' AND ', $baseConditions) : '';
$countStmt  = db()->prepare(
    "SELECT
        COALESCE(SUM($archivedExpr), 0)     AS archive_cnt,
        COALESCE(SUM(NOT $archivedExpr), 0) AS active_cnt
     FROM tm_invoices i $baseWhere"
);
$countStmt->execute($params);
$tabCounts = $countStmt->fetch();

$stmt = db()->prepare(
    "SELECT i.id, i.invoice_number, i.invoice_seq, i.total_minutes,
            i.amount_net, i.amount_gross, i.pdf_file, i.created_at, i.status,
            c.name AS customer_name,
            (SELECT COUNT(*) FROM tm_entries e WHERE e.invoice_id = i.id) AS entry_count,
            (SELECT MIN(sent_at) FROM tm_mail_spool m WHERE m.invoice_id = i.id) AS sent_at,
            (SELECT m.id FROM tm_mail_spool m WHERE m.invoice_id = i.id ORDER BY m.id DESC LIMIT 1) AS mail_id
     FROM tm_invoices i
     LEFT JOIN tm_customers c ON c.id = i.customer_id
     $where
     ORDER BY i.created_at DESC"
);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$totals = db()->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_net),0) AS net, COALESCE(SUM(amount_gross),0) AS gross
     FROM tm_invoices i $where"
);
$totals->execute($params);
$sum = $totals->fetch();

function fmtH(int $min): string  { return number_format($min / 60, 2, ',', '.') . ' h'; }
function fmtEur(float $a): string { return number_format($a, 2, ',', '.') . ' €'; }
function fmtDt($dt): string       { return $dt ? date('d.m.Y H:i', strtotime($dt)) : ''; }
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rechnungen – Administration</title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.inv-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 16px;
}
.inv-tabs a {
    padding: 6px 14px;
    border: 1px solid var(--card-border);
    border-radius: 4px;
    text-decoration: none;
    color: var(--text);
    background: var(--card-bg);
    font-size: 13px;
}
.inv-tabs a.active {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 16px;
}
.summary-bar {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    margin-bottom: 14px;
    font-size: 13px;
    color: var(--text-muted);
}
.summary-bar strong { color: var(--text); }
.mail-status-sent    { color: #27ae60; font-size: 12px; }
.mail-status-pending { color: #e67e22; font-size: 12px; }
.mail-status-none    { color: var(--text-muted); font-size: 12px; }
.inv-status { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; white-space:nowrap; }
.inv-status-erstellt          { background:#e0f2fe; color:#0369a1; }
.inv-status-pdf_erstellt      { background:#dcfce7; color:#15803d; }
.inv-status-mail_vorbereitet  { background:#fef9c3; color:#854d0e; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Rechnungen</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo; Rechnungen
            </div>
        </div>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div class="admin-section">

        <?php $custQ = $customerFilter > 0 ? '&customer_id=' . $customerFilter : ''; ?>
        <div class="inv-tabs">
            <a href="invoices.php<?= $customerFilter > 0 ? '?customer_id=' . $customerFilter : '' ?>"
               class="<?= $view === 'active' ? 'active' : '' ?>">
                Aktiv (<?= (int)$tabCounts['active_cnt'] ?>)
            </a>
            <a href="invoices.php?view=archive<?= $custQ ?>"
               class="<?= $view === 'archive' ? 'active' : '' ?>">
                Archiv (<?= (int)$tabCounts['archive_cnt'] ?>)
            </a>
        </div>

        <form method="get" action="invoices.php" class="filter-bar">
            <input type="hidden" name="view" value="<?= h($view) ?>">
            <select name="customer_id">
                <option value="">Alle Kunden</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $customerFilter === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= h($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn--primary">Filtern</button>
            <?php if ($customerFilter > 0): ?>
            <a href="invoices.php<?= $view === 'archive' ? '?view=archive' : '' ?>" class="btn">Zurücksetzen</a>
            <?php endif; ?>
        </form>

        <div class="summary-bar">
            <span><strong><?= (int)$sum['cnt'] ?></strong> Rechnungen</span>
            <span>Netto: <strong><?= fmtEur((float)$sum['net']) ?></strong></span>
            <span>Brutto: <strong><?= fmtEur((float)$sum['gross']) ?></strong></span>
        </div>

        <?php if (empty($invoices)): ?>
            <p class="empty-message">
                <?= $view === 'archive' ? 'Keine archivierten Rechnungen vorhanden.' : 'Keine Rechnungen vorhanden.' ?>
            </p>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Nummer</th>
                        <th>Kunde</th>
                        <th class="col-dur">Einträge</th>
                        <th class="col-dur">Stunden</th>
                        <th class="col-dur">Netto</th>
                        <th class="col-dur">Brutto</th>
                        <th>Status</th>
                        <th>Mail</th>
                        <th>Dateien</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                    <tr id="row-inv-<?= (int)$inv['id'] ?>">
                        <td style="white-space:nowrap"><?= h(fmtDt($inv['created_at'])) ?></td>
                        <td><?= h($inv['invoice_number']) ?></td>
                        <td><?= h($inv['customer_name'] ?? '—') ?></td>
                        <td class="col-dur"><?= (int)$inv['entry_count'] ?></td>
                        <td class="col-dur"><?= fmtH((int)$inv['total_minutes']) ?></td>
                        <td class="col-dur"><?= fmtEur((float)$inv['amount_net']) ?></td>
                        <td class="col-dur"><?= fmtEur((float)$inv['amount_gross']) ?></td>
                        <td>
                            <?php
                                $st = $inv['status'] ?? 'erstellt';
                                $stLabels = [
                                    'erstellt'         => 'Erstellt',
                                    'pdf_erstellt'     => 'PDF erstellt',
                                    'mail_vorbereitet' => 'Mail vorbereitet',
                                ];
                                $stLabel = $stLabels[$st] ?? $st;
                            ?>
                            <span class="inv-status inv-status-<?= h($st) ?>"><?= $stLabel ?></span>
                        </td>
                        <td>
                            <?php if ($inv['mail_id']): ?>
                                <?php if ($inv['sent_at']): ?>
                                    <a href="mailspool.php?filter=sent" class="mail-status-sent">Versendet <?= h(fmtDt($inv['sent_at'])) ?></a>
                                <?php else: ?>
                                    <a href="mailspool.php?filter=pending" class="mail-status-pending">Im Spool</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="mail-status-none">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap" id="files-<?= (int)$inv['id'] ?>">
                            <a href="invoice_items.php?invoice_id=<?= (int)$inv['id'] ?>"
                               class="btn" style="font-size:11px;padding:2px 8px">Bearbeiten</a>
                            <a href="invoice_view.php?invoice_id=<?= (int)$inv['id'] ?>"
                               class="btn" style="font-size:11px;padding:2px 8px;margin-left:4px">Vorschau</a>
                            <?php if ($inv['pdf_file']): ?>
                                <a href="invoice_download.php?type=pdf&file=<?= urlencode($inv['pdf_file']) ?>"
                                   class="btn" style="font-size:11px;padding:2px 8px;margin-left:4px"
                                   target="_blank" rel="noopener">PDF</a>
                            <?php endif; ?>
                            <?php if (!$inv['mail_id']): ?>
                            <button type="button" class="btn spool-btn"
                                    data-id="<?= (int)$inv['id'] ?>"
                                    data-number="<?= h($inv['invoice_number']) ?>"
                                    style="font-size:11px;padding:2px 8px;margin-left:4px">
                                Mail vorbereiten
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn--danger reverse-btn"
                                    data-id="<?= (int)$inv['id'] ?>"
                                    data-number="<?= h($inv['invoice_number']) ?>"
                                    style="font-size:11px;padding:2px 8px;margin-left:4px"
                                    title="Abrechnung rückgängig machen">
                                Rückgängig
                            </button>
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
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

async function handleReverse(ev) {
    const btn    = ev.currentTarget;
    const id     = btn.dataset.id;
    const number = btn.dataset.number;

    if (!await Dialog.confirm('Abrechnung „' + number + '" rückgängig machen?\n\nDie Einträge werden wieder als nicht abgerechnet markiert, die Rechnung und die PDF-Datei werden gelöscht.', { danger: true })) {
        return;
    }

    btn.disabled    = true;
    btn.textContent = 'Wird zurückgesetzt…';

    try {
        const body = new URLSearchParams({ action: 'reverse_invoice', invoice_id: id });
        const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body });
        const data = await res.json();

        if (data.success) {
            document.getElementById('row-inv-' + id)?.remove();
        } else {
            Dialog.alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
            btn.disabled    = false;
            btn.textContent = 'Rückgängig';
        }
    } catch (e) {
        Dialog.alert('Serverfehler.');
        btn.disabled    = false;
        btn.textContent = 'Rückgängig';
    }
}

document.querySelectorAll('.reverse-btn').forEach(function(btn) {
    btn.addEventListener('click', handleReverse);
});

async function handleSpool(ev) {
    const btn    = ev.currentTarget;
    const id     = btn.dataset.id;
    const number = btn.dataset.number;

    if (!await Dialog.confirm('Rechnung „' + number + '" in den Mail-Spool legen?\nDie Mail kann danach unter Mail-Spool geprüft und versendet werden.')) return;

    btn.disabled    = true;
    btn.textContent = 'Wird vorbereitet…';

    try {
        const body = new URLSearchParams({ action: 'spool_invoice', invoice_id: id });
        const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body });
        const data = await res.json();

        if (data.success) {
            btn.remove();
            const cell = document.getElementById('files-' + id);

            // Insert PDF link if returned
            if (data.data && data.data.pdf_file) {
                const pdfLink = document.createElement('a');
                pdfLink.href      = 'invoice_download.php?type=pdf&file=' + encodeURIComponent(data.data.pdf_file);
                pdfLink.className = 'btn';
                pdfLink.target    = '_blank';
                pdfLink.rel       = 'noopener';
                pdfLink.style.cssText = 'font-size:11px;padding:2px 8px;margin-left:4px';
                pdfLink.textContent   = 'PDF';
                // Insert before reverse btn
                const reverseBtn = cell.querySelector('.reverse-btn');
                cell.insertBefore(pdfLink, reverseBtn);
            }

            // Add spool link
            const spoolLink = document.createElement('a');
            spoolLink.href      = 'mailspool.php';
            spoolLink.className = 'mail-status-pending';
            spoolLink.style.cssText = 'font-size:12px;margin-left:6px';
            spoolLink.textContent   = 'Im Spool';
            cell.appendChild(spoolLink);

            // Update status badge
            const row = document.getElementById('row-inv-' + id);
            const badge = row?.querySelector('.inv-status');
            if (badge) {
                badge.className   = 'inv-status inv-status-mail_vorbereitet';
                badge.textContent = 'Mail vorbereitet';
            }
        } else {
            Dialog.alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
            btn.disabled    = false;
            btn.textContent = 'Mail vorbereiten';
        }
    } catch (e) {
        Dialog.alert('Serverfehler.');
        btn.disabled    = false;
        btn.textContent = 'Mail vorbereiten';
    }
}

document.querySelectorAll('.spool-btn').forEach(function(btn) {
    btn.addEventListener('click', handleSpool);
});
</script>
</body>
</html>
