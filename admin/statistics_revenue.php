<?php
require_once __DIR__ . '/auth.php';

$pdo = db();

$now       = new DateTimeImmutable();
$thisYear  = (int)$now->format('Y');
$thisMonth = (int)$now->format('n');

$prevMonth     = $thisMonth > 1 ? $thisMonth - 1 : 12;
$prevMonthYear = $thisMonth > 1 ? $thisYear      : $thisYear - 1;

$defaultRate = (float)cfg('invoice_hourly_rate', '85.00');
$taxRate     = (int)cfg('invoice_tax_rate', '19');

// Available years: aus tm_entries + immer aktuelles/Vorjahr
$dbYears = $pdo->query(
    "SELECT DISTINCT YEAR(date) AS y FROM tm_entries WHERE deleted_at IS NULL ORDER BY y DESC"
)->fetchAll(PDO::FETCH_COLUMN);
$allYears = array_values(array_unique(array_merge(
    array_map('intval', (array)$dbYears),
    [$thisYear - 1, $thisYear]
)));
rsort($allYears);

// Defaults: Bis = Vormonat, Von = gleicher Monat ein Jahr davor
$fromYear  = isset($_GET['from_year'])  ? (int)$_GET['from_year']  : $prevMonthYear - 1;
$fromMonth = isset($_GET['from_month']) ? (int)$_GET['from_month'] : $prevMonth;
$toYear    = isset($_GET['to_year'])    ? (int)$_GET['to_year']    : $prevMonthYear;
$toMonth   = isset($_GET['to_month'])   ? (int)$_GET['to_month']   : $prevMonth;

if ($fromMonth < 1 || $fromMonth > 12) $fromMonth = 1;
if ($toMonth   < 1 || $toMonth   > 12) $toMonth   = 12;

$fromCode = $fromYear * 100 + $fromMonth;
$toCode   = $toYear   * 100 + $toMonth;

// Falls Von > Bis: tauschen
if ($fromCode > $toCode) {
    [$fromYear, $fromMonth, $toYear, $toMonth, $fromCode, $toCode] =
        [$toYear, $toMonth, $fromYear, $fromMonth, $toCode, $fromCode];
}

// Monatliche Summen aus tm_entries × Stundensatz (nur abrechenbare Kunden)
$stmt = $pdo->prepare(
    "SELECT YEAR(e.date) AS y, MONTH(e.date) AS m,
            SUM(e.duration_minutes)                                            AS total_minutes,
            SUM(e.duration_minutes / 60.0 * COALESCE(c.hourly_rate, ?))        AS total_net,
            COUNT(*)                                                           AS cnt
     FROM tm_entries e
     LEFT JOIN tm_customers c ON c.id = e.customer_id
     WHERE e.deleted_at IS NULL
       AND e.billable = 1
       AND COALESCE(c.billable, 1) = 1
       AND (YEAR(e.date) * 100 + MONTH(e.date)) >= ?
       AND (YEAR(e.date) * 100 + MONTH(e.date)) <= ?
     GROUP BY YEAR(e.date), MONTH(e.date)
     ORDER BY y ASC, m ASC"
);
$stmt->execute([$defaultRate, $fromCode, $toCode]);

$dataByCode = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $dataByCode[(int)$r['y'] * 100 + (int)$r['m']] = $r;
}

// Alle Monate im Bereich aufbauen (Lücken mit 0 füllen)
$months = [];
$y = $fromYear;
$m = $fromMonth;
while (true) {
    $code      = $y * 100 + $m;
    $row       = $dataByCode[$code] ?? null;
    $net       = $row ? (float)$row['total_net'] : 0.0;
    $gross     = round($net * (1 + $taxRate / 100), 2);
    $months[]  = [
        'y'             => $y,
        'm'             => $m,
        'total_minutes' => $row ? (int)$row['total_minutes'] : 0,
        'total_net'     => $net,
        'total_gross'   => $gross,
        'cnt'           => $row ? (int)$row['cnt']           : 0,
    ];
    if ($code >= $toCode) break;
    $m++;
    if ($m > 12) { $m = 1; $y++; }
}

$totalMinutes = array_sum(array_column($months, 'total_minutes'));
$totalNet     = array_sum(array_column($months, 'total_net'));
$totalGross   = array_sum(array_column($months, 'total_gross'));
$totalEntries = array_sum(array_column($months, 'cnt'));
$maxGross     = max(array_column($months, 'total_gross') ?: [0]);
$activeMonths = count(array_filter(array_column($months, 'cnt')));
$avgGross     = $activeMonths > 0 ? $totalGross / $activeMonths : 0.0;

$CHART_H = 260; // px für die Balkenhöhe

$monthNames = [
    1=>'Jan',2=>'Feb',3=>'Mär',4=>'Apr',5=>'Mai',6=>'Jun',
    7=>'Jul',8=>'Aug',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Dez',
];

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
<title>Umsatz-Statistik – Administration</title>
<link rel="stylesheet" href="../assets/style.css">
<style>
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 20px;
}
.filter-bar select { min-width: 120px; }
.filter-group {
    display: flex;
    align-items: center;
    gap: 6px;
}
.filter-sep {
    color: var(--text-muted);
    font-size: 13px;
    padding: 0 2px;
}
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
    gap: 8px;
    height: <?= $CHART_H ?>px;
    padding: 0 8px;
    border-left: 2px solid var(--card-border);
    border-bottom: 2px solid var(--card-border);
    width: max-content;
    min-width: 100%;
    position: relative;
    /* Horizontale Hilfslinien */
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
    min-width: 52px;
    height: 100%;
    gap: 0;
    flex-shrink: 0;
}
.bar-amount {
    font-size: 9px;
    color: var(--text-muted);
    white-space: nowrap;
    margin-bottom: 3px;
    text-align: center;
    min-height: 13px;
}
.bar-fill {
    width: 36px;
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
.bar-month {
    margin-top: 8px;
    font-size: 10px;
    color: var(--text-muted);
    white-space: nowrap;
    text-align: center;
}
.bar-month strong {
    display: block;
    color: var(--text);
    font-size: 11px;
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
            <h1>Umsatz-Statistik</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo;
                <a href="statistics.php">Statistik</a> &rsaquo; Umsatz
            </div>
        </div>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div class="admin-section">

        <form method="get" action="statistics_revenue.php" class="filter-bar">

            <div class="filter-group">
                <span class="filter-sep">Von</span>
                <select name="from_year">
                    <?php foreach ($allYears as $yr): ?>
                    <option value="<?= (int)$yr ?>" <?= $fromYear === (int)$yr ? 'selected' : '' ?>>
                        <?= (int)$yr ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="from_month">
                    <?php foreach ($monthNames as $mNum => $mName): ?>
                    <option value="<?= $mNum ?>" <?= $fromMonth === $mNum ? 'selected' : '' ?>>
                        <?= h($mName) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <span class="filter-sep">–</span>

            <div class="filter-group">
                <span class="filter-sep">Bis</span>
                <select name="to_year">
                    <?php foreach ($allYears as $yr): ?>
                    <option value="<?= (int)$yr ?>" <?= $toYear === (int)$yr ? 'selected' : '' ?>>
                        <?= (int)$yr ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="to_month">
                    <?php foreach ($monthNames as $mNum => $mName): ?>
                    <option value="<?= $mNum ?>" <?= $toMonth === $mNum ? 'selected' : '' ?>>
                        <?= h($mName) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="btn btn--primary" type="submit">Anzeigen</button>
        </form>

        <?php if (empty($months)): ?>
            <p class="empty-message">Kein gültiger Zeitraum.</p>
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
                Ø Monat (Brutto): <strong><?= h(fmtEur($avgGross)) ?></strong>
            </span>
            <?php endif; ?>
        </div>

        <div class="chart-container">
            <div class="chart-scroll">
                <div class="chart-area">
                    <?php foreach ($months as $mo):
                        $barH = $maxGross > 0
                            ? max(2, (int)round($mo['total_gross'] / $maxGross * $CHART_H))
                            : 2;
                        $isZero = $mo['total_gross'] <= 0;
                    ?>
                    <div class="bar-col">
                        <span class="bar-amount">
                            <?= !$isZero ? h(fmtEurShort($mo['total_gross'])) : '' ?>
                        </span>
                        <div class="bar-fill <?= $isZero ? 'bar-zero' : '' ?>"
                             style="height:<?= $barH ?>px"
                             title="<?= h($monthNames[$mo['m']]) ?> <?= $mo['y'] ?>: <?= h(fmtEur($mo['total_gross'])) ?> Brutto<?= $mo['cnt'] ? ' (' . h(fmtHours($mo['total_minutes'])) . ', ' . $mo['cnt'] . ' Eintr' . ($mo['cnt'] > 1 ? 'äge' : 'ag') . ')' : '' ?>">
                        </div>
                        <div class="bar-month">
                            <strong><?= h($monthNames[$mo['m']]) ?></strong>
                            <?= $mo['y'] ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="entries-table rev-table">
                <thead>
                    <tr>
                        <th>Monat</th>
                        <th class="right">Stunden</th>
                        <th class="right">Einträge</th>
                        <th class="right">Netto</th>
                        <th class="right">MwSt.</th>
                        <th class="right">Brutto</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($months as $mo):
                    $mwst = $mo['total_gross'] - $mo['total_net'];
                ?>
                <tr class="<?= $mo['cnt'] === 0 ? 'col-zero' : '' ?>">
                    <td><?= h($monthNames[$mo['m']]) ?> <?= $mo['y'] ?></td>
                    <td class="right"><?= $mo['cnt'] ? h(fmtHours($mo['total_minutes'])) : '—' ?></td>
                    <td class="right"><?= $mo['cnt'] ?: '—' ?></td>
                    <td class="right"><?= $mo['cnt'] ? h(fmtEur($mo['total_net']))   : '—' ?></td>
                    <td class="right"><?= $mo['cnt'] ? h(fmtEur($mwst))              : '—' ?></td>
                    <td class="right"><?= $mo['cnt'] ? h(fmtEur($mo['total_gross'])) : '—' ?></td>
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
