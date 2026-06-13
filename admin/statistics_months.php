<?php
require_once __DIR__ . '/auth.php';

$pdo = db();

// Jahre mit Einträgen
$years = $pdo->query(
    "SELECT DISTINCT YEAR(date) AS y
     FROM tm_entries
     WHERE deleted_at IS NULL
     ORDER BY y DESC"
)->fetchAll();

$year  = filter_var($_GET['year']  ?? '', FILTER_VALIDATE_INT) ?: (int)date('Y');
$month = filter_var($_GET['month'] ?? '', FILTER_VALIDATE_INT) ?: (int)date('n');
if ($month < 1 || $month > 12) $month = (int)date('n');

$sort = ($_GET['sort'] ?? 'time') === 'customer' ? 'customer' : 'time';

$entries  = [];
$totalMin = 0;

if ($year && $month) {
    $orderBy = $sort === 'customer'
        ? 'c.name ASC, e.start_datetime ASC'
        : 'e.start_datetime ASC';

    $stmt = $pdo->prepare(
        "SELECT e.id,
                e.start_datetime, e.end_datetime, e.duration_minutes,
                e.activity, e.project, e.comment,
                u.username,
                COALESCE(c.name, '') AS customer_name
         FROM tm_entries e
         LEFT JOIN tm_customers c ON c.id = e.customer_id
         LEFT JOIN tm_users     u ON u.id = e.user_id
         WHERE e.deleted_at IS NULL
           AND YEAR(e.date)  = ?
           AND MONTH(e.date) = ?
         ORDER BY $orderBy"
    );
    $stmt->execute([$year, $month]);
    $entries  = $stmt->fetchAll();
    $totalMin = (int)array_sum(array_column($entries, 'duration_minutes'));
}

$monthNames = [
    1  => 'Januar',  2 => 'Februar',  3  => 'März',     4  => 'April',
    5  => 'Mai',     6 => 'Juni',     7  => 'Juli',     8  => 'August',
    9  => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
];

function fmtTime(string $dt): string { return substr($dt, 11, 5); }
function fmtDate(string $dt): string {
    return substr($dt, 8, 2) . '.' . substr($dt, 5, 2) . '.' . substr($dt, 0, 4);
}
function fmtH(int $min): string {
    return number_format($min / 60, 2, '.', '') . ' h';
}

function statsUrl(array $overrides = []): string {
    $p = array_merge([
        'year'  => $_GET['year']  ?? '',
        'month' => $_GET['month'] ?? '',
        'sort'  => $_GET['sort']  ?? 'time',
    ], $overrides);
    if ($p['year']  === '' || $p['year']  === '0') unset($p['year']);
    if ($p['month'] === '' || $p['month'] === '0') unset($p['month']);
    if ($p['sort']  === 'time') unset($p['sort']);
    return 'statistics_months.php' . ($p ? '?' . http_build_query($p) : '');
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monats-Statistik – Administration</title>
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<style>
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 16px;
}
.filter-bar select { min-width: 120px; }
.summary-bar {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    margin-bottom: 12px;
    font-size: 13px;
    color: var(--text-muted);
}
.summary-bar strong { color: var(--text); }
.col-user    { white-space: nowrap; font-size: 12px; color: var(--text-muted); }
.col-project { font-size: 11px; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Monats-Statistik</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo;
                <a href="statistics.php">Statistik</a> &rsaquo; Monate
            </div>
        </div>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div class="admin-section">

        <form method="get" action="statistics_months.php" class="filter-bar">
            <select name="year">
                <option value="">— Jahr wählen —</option>
                <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y['y'] ?>" <?= $year === (int)$y['y'] ? 'selected' : '' ?>>
                    <?= (int)$y['y'] ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="month">
                <option value="">— Monat wählen —</option>
                <?php foreach ($monthNames as $mNum => $mName): ?>
                <option value="<?= $mNum ?>" <?= $month === $mNum ? 'selected' : '' ?>>
                    <?= h($mName) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="sort">
                <option value="time"     <?= $sort === 'time'     ? 'selected' : '' ?>>Sortieren nach Zeit</option>
                <option value="customer" <?= $sort === 'customer' ? 'selected' : '' ?>>Sortieren nach Kunde</option>
            </select>

            <button class="btn btn--primary" type="submit">Anzeigen</button>

            <?php if ($year || $month): ?>
            <a href="statistics_months.php" class="btn">Zurücksetzen</a>
            <?php endif; ?>
        </form>

        <?php if (!$year || !$month): ?>
            <p class="empty-message">Bitte Jahr und Monat wählen.</p>
        <?php elseif (empty($entries)): ?>
            <p class="empty-message">Keine Einträge für <?= h($monthNames[$month]) ?> <?= $year ?>.</p>
        <?php else: ?>

        <div class="summary-bar">
            <span><strong><?= h($monthNames[$month]) ?> <?= $year ?></strong></span>
            <span><strong><?= count($entries) ?></strong> Einträge</span>
            <span>Gesamt: <strong><?= h(fmtH($totalMin)) ?></strong></span>
        </div>

        <div class="table-wrapper">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Zeit</th>
                        <th class="col-dur">Min</th>
                        <th>Kunde</th>
                        <th>Tätigkeit</th>
                        <th>Kommentar</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $currentCustomer = null;
                $customerMin     = 0;
                foreach ($entries as $i => $e):
                    $customerKey = $e['customer_name'] !== '' ? $e['customer_name'] : '';

                    if ($sort === 'customer' && $currentCustomer !== null && $customerKey !== $currentCustomer):
                ?>
                    <tr style="background:var(--hover-bg,#f5f5f5);font-weight:600;font-size:12px">
                        <td colspan="6" style="color:var(--text-muted);padding-left:8px">
                            Gesamt <?= h($currentCustomer ?: '—') ?>: <?= h(fmtH($customerMin)) ?>
                        </td>
                    </tr>
                    <tr style="height:14px"><td colspan="6" style="border:none;padding:0;background:transparent">&nbsp;</td></tr>
                <?php
                        $customerMin = 0;
                    endif;

                    if ($sort === 'customer') {
                        $currentCustomer = $customerKey;
                        $customerMin    += (int)$e['duration_minutes'];
                    }
                ?>
                <tr class="entry-row">
                    <td style="white-space:nowrap"><?= fmtDate($e['start_datetime']) ?></td>
                    <td style="white-space:nowrap">
                        <?= fmtTime($e['start_datetime']) ?>–<?= fmtTime($e['end_datetime']) ?>
                    </td>
                    <td class="col-dur"><?= (int)$e['duration_minutes'] ?></td>
                    <td><?= $e['customer_name'] !== '' ? h($e['customer_name']) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                    <td><?= h($e['activity']) ?></td>
                    <td style="color:var(--text-muted);font-size:12px">
                        <?= $e['comment'] ? h($e['comment']) : '' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($sort === 'customer' && $currentCustomer !== null): ?>
                    <tr style="background:var(--hover-bg,#f5f5f5);font-weight:600;font-size:12px">
                        <td colspan="6" style="color:var(--text-muted);padding-left:8px">
                            Gesamt <?= h($currentCustomer ?: '—') ?>: <?= h(fmtH($customerMin)) ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>
    </div>

</div>
</body>
</html>
