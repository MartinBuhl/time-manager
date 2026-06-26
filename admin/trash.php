<?php
require_once __DIR__ . '/auth.php';

$stmt = db()->query(
    "SELECT e.id, u.username, c.name AS customer_name, e.activity,
            e.comment, e.start_datetime, e.end_datetime, e.duration_minutes,
            e.deleted_at
     FROM tm_entries e
     JOIN tm_users u ON u.id = e.user_id
     LEFT JOIN tm_customers c ON c.id = e.customer_id
     WHERE e.deleted_at IS NOT NULL
     ORDER BY e.deleted_at DESC"
);
$entries = $stmt->fetchAll();

function fmtDur(int $min): string
{
    return sprintf('%d:%02d h', intdiv($min, 60), $min % 60);
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Papierkorb – Administration</title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Papierkorb</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo; Papierkorb
            </div>
        </div>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div class="admin-section">
        <h2>Gelöschte Einträge</h2>
        <div id="trashMsg"></div>
        <div class="table-wrapper">
            <table class="entries-table" id="trashTable">
                <thead>
                    <tr>
                        <th>Mitarbeiter</th>
                        <th>Datum</th>
                        <th>Zeitraum</th>
                        <th>Dauer</th>
                        <th>Kunde</th>
                        <th>Tätigkeit</th>
                        <th>Kommentar</th>
                        <th>Gelöscht am</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $e): ?>
                    <tr id="trow-<?= (int)$e['id'] ?>">
                        <td><?= h($e['username']) ?></td>
                        <td class="col-time"><?= h(date('d.m.Y', strtotime($e['start_datetime']))) ?></td>
                        <td class="col-time">
                            <?= h(date('H:i', strtotime($e['start_datetime']))) ?> –
                            <?= h(date('H:i', strtotime($e['end_datetime']))) ?>
                        </td>
                        <td class="col-dur"><?= h(fmtDur((int)$e['duration_minutes'])) ?></td>
                        <td><?= h($e['customer_name'] ?? '–') ?></td>
                        <td><?= h($e['activity']) ?></td>
                        <td class="comment"><?= h($e['comment'] ?? '') ?></td>
                        <td class="col-time"><?= h(date('d.m.Y H:i', strtotime($e['deleted_at']))) ?></td>
                        <td>
                            <button class="btn" onclick="restoreEntry(<?= (int)$e['id'] ?>)">
                                Wiederherstellen
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($entries)): ?>
                    <tr id="emptyRow"><td colspan="9" class="empty-message">Papierkorb ist leer.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

async function restoreEntry(id) {
    const msgEl = document.getElementById('trashMsg');
    try {
        const body = new URLSearchParams({ action: 'restore_entry', id });
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body
        });
        const data = await res.json();
        if (data.success) {
            const row = document.getElementById('trow-' + id);
            if (row) row.remove();
            const tbody = document.querySelector('#trashTable tbody');
            if (!tbody.querySelector('tr:not(#emptyRow), tr')) {
                const tr = document.createElement('tr');
                tr.id = 'emptyRow';
                tr.innerHTML = '<td colspan="9" class="empty-message">Papierkorb ist leer.</td>';
                tbody.appendChild(tr);
            }
            msgEl.className = 'admin-msg admin-msg--ok';
            msgEl.textContent = 'Eintrag wurde wiederhergestellt.';
        } else {
            msgEl.className = 'admin-msg admin-msg--err';
            msgEl.textContent = data.error || 'Fehler beim Wiederherstellen.';
        }
    } catch (e) {
        msgEl.className = 'admin-msg admin-msg--err';
        msgEl.textContent = 'Serverfehler. Bitte erneut versuchen.';
    }
}
</script>
</body>
</html>
