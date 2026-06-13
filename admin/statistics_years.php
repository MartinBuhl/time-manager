<?php
require_once __DIR__ . '/auth.php';

$pdo = db();

$defaultRate = (float)cfg('invoice_hourly_rate', '85.00');
$taxRate     = (int)cfg('invoice_tax_rate', '19');

// Jährliche Summen aus tm_entries × Stundensatz (nur abrechenbare Kunden)
$stmt = $pdo->prepare(
    "SELECT YEAR(e.date) AS y,
            SUM(e.duration_minutes)                                            AS total_minutes,
            SUM(e.duration_minutes / 60.0 * COALESCE(c.hourly_rate, ?))        AS total_net,
            COUNT(*)                                                           AS cnt
     FROM tm_entries e
     LEFT JOIN tm_customers c ON c.id = e.customer_id
     WHERE e.deleted_at IS NULL
       AND e.billable = 1
       AND COALESCE(c.billable, 1) = 1
     GROUP BY YEAR(e.date)
     ORDER BY y ASC"
);
$stmt->execute([$defaultRate]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years = [];
foreach ($rows as $r) {
    $net   = (float)$r['total_net'];
    $gross = round($net * (1 + $taxRate / 100), 2);
    $years[] = [
        'y'             => (int)$r['y'],
        'total_minutes' => (int)$r['total_minutes'],
        'total_net'     => $net,
        'total_gross'   => $gross,
        'cnt'           => (int)$r['cnt'],
    ];
}

$totalMinutes = array_sum(array_column($years, 'total_minutes'));
$totalNet     = array_sum(array_column($years, 'total_net'));
$totalGross   = array_sum(array_column($years, 'total_gross'));
$totalEntries = array_sum(array_column($years, 'cnt'));
$maxGross     = max(array_column($years, 'total_gross') ?: [0]);
$activeYears  = count(array_filter(array_column($years, 'cnt')));
$avgGross     = $activeYears > 0 ? $totalGross / $activeYears : 0.0;

$CHART_H = 260;

function fmtEur(float $v): string {
    return number_format($v, 2, ',', '.') . ' €';
}
function fmtEurShort(float $v): string {
    if ($v >= 1000) {
        return number_format($v / 1000, 1, ',', '.') . ' k€';
    }
    return number_format($v, 0, ',', '.') . ' €';
}
function fmtHours(int $minutes): string {
    return number_format($minutes / 60, 2, ',', '.') . ' h';
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jahres-Statistik – Administration</title>
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<style>
.summary-bar {
    display: flex;
    gap: 28px;
    flex-wrap: wrap;
    margin-bottom: 24px;
    font-size: 13px;
    color: var(--text-muted);
}
.summary-bar strong { color: var(--text); font-size: 15px; }

/* Chart */
.chart-container {
    margin-bottom: 32px;
}
.chart-scroll {
    overflow-x: auto;
    overflow-y: visible;
    padding-bottom: 4px;
}
.chart-area {
    display: flex;
    align-items: flex-end;
    gap: 16px;
    height: <?= $CHART_H ?>px;
    padding: 0 8px;
    border-left: 2px solid var(--card-border);
    border-bottom: 2px solid var(--card-border);
    width: max-content;
    min-width: 100%;
    position: relative;
    background-image:
        repeating-linear-gradient(
            to top,
            transparent 0px,
            transparent calc(<?= $CHART_H ?>px / 4 - 1px),
            #e8edf2 calc(<?= $CHART_H ?>px / 4 - 1px),
            #e8edf2 calc(<?= $CHART_H ?>px / 4)
        );
}
.bar-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    min-width: 72px;
    height: 100%;
    gap: 0;
    flex-shrink: 0;
}
.bar-amount {
    font-size: 10px;
    color: var(--text-muted);
    white-space: nowrap;
    margin-bottom: 3px;
    text-align: center;
    min-height: 14px;
}
.bar-fill {
    width: 48px;
    background: var(--accent);
    border-radius: 3px 3px 0 0;
    min-height: 2px;
    transition: background 0.15s;
    cursor: default;
}
.bar-fill:hover {
    background: var(--accent-dark);
}
.bar-fill.bar-zero {
    background: var(--card-border);
    opacity: 0.5;
}
.bar-year {
    margin-top: 8px;
    font-size: 12px;
    color: var(--text);
    white-space: nowrap;
    text-align: center;
    font-weight: 600;
}

/* Tabelle */
.rev-table { width: 100%; }
.rev-table td.right { text-align: right; }
.rev-table tfoot td { font-weight: 600; border-top: 2px solid var(--card-border); }
.col-zero { color: var(--text-muted); }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Jahres-Statistik</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo;
                <a href="statistics.php">Statistik</a> &rsaquo; Jahre
            </div>
        </div>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div class="admin-section">

        <?php if (empty($years)): ?>
            <p class="empty-message">Keine Einträge vorhanden.</p>
        <?php else: ?>

        <div class="summary-bar">
            <span>
                Stunden: <strong><?= h(fmtHours($totalMinutes)) ?></strong>
            </span>
            <span>
                Netto gesamt: <strong><?= h(fmtEur($totalNet)) ?></strong>
            </span>
            <span>
                Brutto gesamt: <strong><?= h(fmtEur($totalGross)) ?></strong>
            </span>
            <span>
                Einträge: <strong><?= $totalEntries ?></strong>
            </span>
            <?php if ($avgGross > 0): ?>
            <span>
                Ø Jahr (Brutto): <strong><?= h(fmtEur($avgGross)) ?></strong>
            </span>
            <?php endif; ?>
        </div>

        <div class="chart-container">
            <div class="chart-scroll">
                <div class="chart-area">
                    <?php foreach ($years as $yr):
                        $barH = $maxGross > 0
                            ? max(2, (int)round($yr['total_gross'] / $maxGross * $CHART_H))
                            : 2;
                        $isZero = $yr['total_gross'] <= 0;
                    ?>
                    <div class="bar-col">
                        <span class="bar-amount">
                            <?= !$isZero ? h(fmtEurShort($yr['total_gross'])) : '' ?>
                        </span>
                        <div class="bar-fill <?= $isZero ? 'bar-zero' : '' ?>"
                             style="height:<?= $barH ?>px"
                             title="<?= $yr['y'] ?>: <?= h(fmtEur($yr['total_gross'])) ?> Brutto<?= $yr['cnt'] ? ' (' . h(fmtHours($yr['total_minutes'])) . ', ' . $yr['cnt'] . ' Eintr' . ($yr['cnt'] > 1 ? 'äge' : 'ag') . ')' : '' ?>">
                        </div>
                        <div class="bar-year"><?= $yr['y'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="entries-table rev-table">
                <thead>
                    <tr>
                        <th>Jahr</th>
                        <th class="right">Stunden</th>
                        <th class="right">Einträge</th>
                        <th class="right">Netto</th>
                        <th class="right">MwSt.</th>
                        <th class="right">Brutto</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($years as $yr):
                    $mwst = $yr['total_gross'] - $yr['total_net'];
                ?>
                <tr class="<?= $yr['cnt'] === 0 ? 'col-zero' : '' ?>">
                    <td><?= $yr['y'] ?></td>
                    <td class="right"><?= $yr['cnt'] ? h(fmtHours($yr['total_minutes'])) : '—' ?></td>
                    <td class="right"><?= $yr['cnt'] ?: '—' ?></td>
                    <td class="right"><?= $yr['cnt'] ? h(fmtEur($yr['total_net']))   : '—' ?></td>
                    <td class="right"><?= $yr['cnt'] ? h(fmtEur($mwst))              : '—' ?></td>
                    <td class="right"><?= $yr['cnt'] ? h(fmtEur($yr['total_gross'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Gesamt</td>
                        <td class="right"><?= h(fmtHours($totalMinutes)) ?></td>
                        <td class="right"><?= $totalEntries ?></td>
                        <td class="right"><?= h(fmtEur($totalNet)) ?></td>
                        <td class="right"><?= h(fmtEur($totalGross - $totalNet)) ?></td>
                        <td class="right"><?= h(fmtEur($totalGross)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php endif; ?>
    </div>

</div>
</body>
</html>
