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

$deletedOrders = [];
try {
    $deletedOrders = db()->query(
        "SELECT o.id, o.created_at, o.deleted_at,
                COALESCE(c.name, '') AS customer_name
         FROM tm_orders o
         LEFT JOIN tm_customers c ON c.id = o.customer_id
         WHERE o.deleted_at IS NOT NULL
         ORDER BY o.deleted_at DESC"
    )->fetchAll();
} catch (Throwable $e) { /* Tabelle evtl. noch nicht vorhanden */ }

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

    <div class="admin-section">
        <h2>Gelöschte Aufträge</h2>
        <div id="trashOrderMsg"></div>
        <div class="table-wrapper">
            <table class="entries-table" id="trashOrdersTable">
                <thead>
                    <tr>
                        <th>Kunde</th>
                        <th>Erfasst</th>
                        <th>Gelöscht am</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deletedOrders as $o): ?>
                    <tr id="otrow-<?= (int)$o['id'] ?>">
                        <td><?= h($o['customer_name'] !== '' ? $o['customer_name'] : '—') ?></td>
                        <td class="col-time"><?= h(date('d.m.Y H:i', strtotime($o['created_at']))) ?></td>
                        <td class="col-time"><?= h(date('d.m.Y H:i', strtotime($o['deleted_at']))) ?></td>
                        <td style="white-space:nowrap;text-align:right">
                            <button class="btn" onclick="restoreOrder(<?= (int)$o['id'] ?>)">Wiederherstellen</button>
                            <button class="btn btn--danger" onclick="purgeOrder(<?= (int)$o['id'] ?>)">Endgültig löschen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($deletedOrders)): ?>
                    <tr id="emptyOrderRow"><td colspan="4" class="empty-message">Keine gelöschten Aufträge.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="../assets/dialog.js"></script>
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

async function orderAction(action, id) {
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action, id }),
    });
    return res.json();
}

async function restoreOrder(id) {
    const msgEl = document.getElementById('trashOrderMsg');
    try {
        const data = await orderAction('restore_order', id);
        if (data.success) {
            const row = document.getElementById('otrow-' + id);
            if (row) row.remove();
            msgEl.className = 'admin-msg admin-msg--ok';
            msgEl.textContent = 'Auftrag wurde wiederhergestellt.';
        } else {
            msgEl.className = 'admin-msg admin-msg--err';
            msgEl.textContent = data.error || 'Fehler beim Wiederherstellen.';
        }
    } catch (e) {
        msgEl.className = 'admin-msg admin-msg--err';
        msgEl.textContent = 'Serverfehler. Bitte erneut versuchen.';
    }
}

async function purgeOrder(id) {
    const msgEl = document.getElementById('trashOrderMsg');
    if (!await Dialog.confirm('Auftrag mit allen Dateien endgültig löschen? Das kann nicht rückgängig gemacht werden.', { danger: true })) return;
    try {
        const data = await orderAction('purge_order', id);
        if (data.success) {
            const row = document.getElementById('otrow-' + id);
            if (row) row.remove();
            msgEl.className = 'admin-msg admin-msg--ok';
            msgEl.textContent = 'Auftrag wurde endgültig gelöscht.';
        } else {
            msgEl.className = 'admin-msg admin-msg--err';
            msgEl.textContent = data.error || 'Fehler beim Löschen.';
        }
    } catch (e) {
        msgEl.className = 'admin-msg admin-msg--err';
        msgEl.textContent = 'Serverfehler. Bitte erneut versuchen.';
    }
}
</script>
</body>
</html>
