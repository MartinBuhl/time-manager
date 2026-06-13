<?php
require_once __DIR__ . '/auth.php';

$invoiceId = filter_var($_GET['invoice_id'] ?? 0, FILTER_VALIDATE_INT);
if (!$invoiceId) { header('Location: invoices.php'); exit; }

$stmt = db()->prepare(
    'SELECT i.id, i.invoice_number, i.total_minutes, i.amount_net, i.amount_gross,
            i.pdf_file, i.created_at,
            i.invoice_date, i.period_start, i.period_end,
            i.invoice_mode, i.invoice_text, i.tax_rate, i.hourly_rate AS stored_rate,
            c.id AS customer_id, c.name AS customer_name,
            c.billing_name, c.billing_street, c.billing_zip, c.billing_city,
            c.billing_email, c.billing_tax_id,
            c.contact_first_name, c.contact_last_name, c.contact_on_invoice,
            c.hourly_rate AS customer_rate
     FROM tm_invoices i
     LEFT JOIN tm_customers c ON c.id = i.customer_id
     WHERE i.id = ? LIMIT 1'
);
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();
if (!$invoice) { header('Location: invoices.php'); exit; }

// Items from tm_invoice_items (may be empty for old invoices)
$stmt = db()->prepare(
    'SELECT date, activity, comment, duration_minutes
     FROM tm_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id'
);
$stmt->execute([$invoiceId]);
$items = $stmt->fetchAll();

// Fallback: linked entries if no items recorded
if (empty($items)) {
    $stmt = db()->prepare(
        'SELECT date, activity, comment, duration_minutes
         FROM tm_entries WHERE invoice_id = ? AND deleted_at IS NULL
         ORDER BY date ASC, start_datetime ASC'
    );
    $stmt->execute([$invoiceId]);
    $items = $stmt->fetchAll();
}

// Config (sender data always from current config)
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
$paymentDays      = (int)cfg('invoice_payment_days', '14');

// Stored-at-invoice-time values (fall back to config for old invoices that predate the migration)
$taxRate     = $invoice['tax_rate'] !== null ? (int)$invoice['tax_rate'] : (int)cfg('invoice_tax_rate', '19');
$rate        = $invoice['stored_rate'] !== null
    ? (float)$invoice['stored_rate']
    : ((float)$invoice['customer_rate'] ?: (float)cfg('invoice_hourly_rate', '85.00'));
$invoiceMode = $invoice['invoice_mode'] ?? 'entries';
$invoiceText = $invoice['invoice_text'] ?? null;

$amountNet   = (float)$invoice['amount_net'];
$amountGross = (float)$invoice['amount_gross'];
$taxAmount   = round($amountGross - $amountNet, 2);

// Invoice date: use stored invoice_date, fall back to created_at for old records
$rawDate     = $invoice['invoice_date'] ?? null;
$invoiceDate = $rawDate ? date('d.m.Y', strtotime($rawDate)) : date('d.m.Y', strtotime($invoice['created_at']));
$paymentDate = date('d.m.Y', strtotime(($rawDate ?? $invoice['created_at']) . ' +' . $paymentDays . ' days'));

// Period from stored fields (fall back to first/last item date)
$storedStart = $invoice['period_start'] ?? null;
$storedEnd   = $invoice['period_end']   ?? null;

function fmtEur(float $v): string { return number_format($v, 2, ',', '.') . ' €'; }
function hoursOf(int $min): float { return $min / 60; }
function fmtH(int $min): string { return number_format(hoursOf($min), 2, ',', '.'); }
function fmtDate(?string $d): string { return $d ? date('d.m.Y', strtotime($d)) : ''; }
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rechnung <?= h($invoice['invoice_number']) ?> – Vorschau</title>
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
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
    align-items: center;
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
    min-height: calc(297mm - 96px);
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
@media print {
    @page { size: A4; margin: 0; }
    body { background: #fff !important; }
    .invoice-actions { display: none !important; }
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
        <a href="invoices.php" class="btn">&#8592; Rechnungen</a>
        <a href="invoice_items.php?invoice_id=<?= (int)$invoiceId ?>" class="btn">Posten bearbeiten</a>
        <?php if ($invoice['pdf_file']): ?>
        <a href="invoice_download.php?type=pdf&file=<?= urlencode($invoice['pdf_file']) ?>"
           class="btn btn--primary" target="_blank" rel="noopener">PDF herunterladen</a>
        <?php endif; ?>
        <button class="btn" onclick="window.print()">&#128438; Drucken</button>
        <span style="font-size:12px;color:var(--text-muted);margin-left:4px">
            Rechnung <?= h($invoice['invoice_number']) ?>
        </span>
    </div>

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
            <p class="inv-rec-name"><?= h($invoice['billing_name'] ?: $invoice['customer_name']) ?></p>
            <?php
                $contactName = trim(($invoice['contact_first_name'] ?? '') . ' ' . ($invoice['contact_last_name'] ?? ''));
                if ($invoice['contact_on_invoice'] && $contactName !== ''):
            ?>
                <p>z.&nbsp;Hd. <?= h($contactName) ?></p>
            <?php endif; ?>
            <?php if ($invoice['billing_street']): ?>
                <p><?= h($invoice['billing_street']) ?></p>
            <?php endif; ?>
            <?php if ($invoice['billing_zip'] || $invoice['billing_city']): ?>
                <p><?= h(trim($invoice['billing_zip'] . ' ' . $invoice['billing_city'])) ?></p>
            <?php endif; ?>
            <?php if ($invoice['billing_tax_id']): ?>
                <p style="margin-top:6px">USt-IdNr.: <?= h($invoice['billing_tax_id']) ?></p>
            <?php endif; ?>
        </div>

        <div class="inv-number-row">
            <span>Rechnung Nr. <?= h($invoice['invoice_number']) ?></span>
            <span><?= $invoiceDate ?></span>
        </div>

        <?php
            // Period: prefer stored values, fall back to first/last item date
            if ($storedStart) {
                $periodStart = fmtDate($storedStart);
                $periodEnd   = fmtDate($storedEnd ?? $storedStart);
            } elseif (!empty($items)) {
                $periodStart = fmtDate($items[0]['date']);
                $periodEnd   = fmtDate($items[count($items)-1]['date']);
            } else {
                $periodStart = $periodEnd = $invoiceDate;
            }
        ?>
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
                    <td><?= h($invoiceText ?: 'Erbrachte Leistungen') ?></td>
                    <td class="right"><?= fmtH((int)$invoice['total_minutes']) ?></td>
                    <td class="right"><?= fmtEur($amountNet) ?></td>
                </tr>
            </tbody>
        </table>
        <?php elseif (!empty($items)): ?>
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
            <?php foreach ($items as $item):
                $hours  = hoursOf((int)$item['duration_minutes']);
                $amount = round($hours * $rate, 2);
            ?>
                <tr>
                    <td style="white-space:nowrap"><?= h(fmtDate($item['date'])) ?></td>
                    <td>
                        <?= h($item['activity']) ?>
                        <?php if ($item['comment']): ?>
                            <br><span class="comment-cell"><?= h($item['comment']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="right"><?= fmtH((int)$item['duration_minutes']) ?></td>
                    <td class="right"><?= fmtEur($amount) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="color:#666;font-size:13px">Keine Positionen erfasst.</p>
        <?php endif; ?>

        <div class="inv-totals">
            <table>
                <tr><td>Nettobetrag</td><td><?= fmtEur($amountNet) ?></td></tr>
                <tr><td>zzgl. MwSt.</td><td><?= fmtEur($taxAmount) ?></td></tr>
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

</div>
</body>
</html>
