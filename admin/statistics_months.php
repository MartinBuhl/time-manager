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

$monthNames = [];
for ($mn = 1; $mn <= 12; $mn++) { $monthNames[$mn] = t('stats.month.' . $mn); }

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
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('stats.pageTitleMonths')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
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
            <h1><?= h(t('stats.headingMonths')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo;
                <a href="statistics.php"><?= h(t('admin.card.statistics')) ?></a> &rsaquo; <?= h(t('stats.cardMonths')) ?>
            </div>
        </div>
        <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
    </div>

    <div class="admin-section">

        <form method="get" action="statistics_months.php" class="filter-bar">
            <select name="year">
                <option value=""><?= h(t('stats.chooseYear')) ?></option>
                <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y['y'] ?>" <?= $year === (int)$y['y'] ? 'selected' : '' ?>>
                    <?= (int)$y['y'] ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="month">
                <option value=""><?= h(t('stats.chooseMonth')) ?></option>
                <?php foreach ($monthNames as $mNum => $mName): ?>
                <option value="<?= $mNum ?>" <?= $month === $mNum ? 'selected' : '' ?>>
                    <?= h($mName) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="sort">
                <option value="time"     <?= $sort === 'time'     ? 'selected' : '' ?>><?= h(t('stats.sortByTime')) ?></option>
                <option value="customer" <?= $sort === 'customer' ? 'selected' : '' ?>><?= h(t('stats.sortByCustomer')) ?></option>
            </select>

            <button class="btn btn--primary" type="submit"><?= h(t('stats.show')) ?></button>

            <?php if ($year || $month): ?>
            <a href="statistics_months.php" class="btn"><?= h(t('adminEntries.reset')) ?></a>
            <?php endif; ?>
        </form>

        <?php if (!$year || !$month): ?>
            <p class="empty-message"><?= h(t('stats.chooseYearMonth')) ?></p>
        <?php elseif (empty($entries)): ?>
            <p class="empty-message"><?= h(t('stats.noEntriesFor', ['month' => $monthNames[$month], 'year' => $year])) ?></p>
        <?php else: ?>

        <div class="summary-bar">
            <span><strong><?= h($monthNames[$month]) ?> <?= $year ?></strong></span>
            <span><strong><?= count($entries) ?></strong> <?= h(t('invoices.colEntries')) ?></span>
            <span><?= h(t('stats.total')) ?>: <strong><?= h(fmtH($totalMin)) ?></strong></span>
        </div>

        <div class="table-wrapper">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th><?= h(t('customers.colDate')) ?></th>
                        <th><?= h(t('entries.colTime')) ?></th>
                        <th class="col-dur"><?= h(t('entries.colMin')) ?></th>
                        <th><?= h(t('entries.colCustomer')) ?></th>
                        <th><?= h(t('common.activity')) ?></th>
                        <th><?= h(t('customers.colComment')) ?></th>
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
                            <?= h(t('stats.customerTotal', ['name' => $currentCustomer ?: '—', 'hours' => fmtH($customerMin)])) ?>
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
                            <?= h(t('stats.customerTotal', ['name' => $currentCustomer ?: '—', 'hours' => fmtH($customerMin)])) ?>
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
