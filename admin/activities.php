<?php
require_once __DIR__ . '/auth.php';

$activities = db()->query(
    'SELECT id, name, active, sort_order FROM tm_activities ORDER BY sort_order, name'
)->fetchAll();
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tätigkeiten – Administration</title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.act-name-input {
    width: 100%; box-sizing: border-box; padding: 5px 8px;
    border: 1px solid var(--border); border-radius: 6px;
    background: var(--card-bg); color: var(--text);
}
tr.act-inactive td { opacity: 0.5; }
tr.act-inactive td.act-actions { opacity: 1; }
.act-actions { white-space: nowrap; text-align: right; }
.act-actions .btn { font-size: 11px; padding: 2px 8px; margin-left: 4px; }
.col-order { width: 70px; white-space: nowrap; }
.col-active { width: 90px; white-space: nowrap; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Tätigkeiten</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo; Tätigkeiten
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="index.php" class="btn"><?= h(t('admin.back')) ?></a>
            <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
        </div>
    </div>

    <div class="admin-section">
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 14px">
            Diese Tätigkeiten stehen in der App zur Auswahl. Reihenfolge per &uarr;/&darr; ändern.
            Inaktive Tätigkeiten verschwinden aus den Auswahllisten. Löschen entfernt nur den
            Listeneintrag – bestehende Zeiteinträge behalten ihre Tätigkeit unverändert.
        </p>

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
            <input type="text" id="newActName" class="act-name-input" style="max-width:280px"
                   placeholder="Neue Tätigkeit" maxlength="100">
            <button type="button" class="btn btn--primary" id="addBtn">Hinzufügen</button>
            <span id="addMsg" style="font-size:12px"></span>
        </div>

        <div class="table-wrapper">
            <table class="entries-table" id="actTable">
                <thead>
                    <tr>
                        <th class="col-order">Reihenfolge</th>
                        <th>Tätigkeit</th>
                        <th class="col-active">Aktiv</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="actTbody">
                <?php if (empty($activities)): ?>
                    <tr id="emptyRow"><td colspan="4" class="empty-message">Noch keine Tätigkeiten vorhanden.</td></tr>
                <?php else: foreach ($activities as $a): ?>
                    <tr class="entry-row<?= $a['active'] ? '' : ' act-inactive' ?>" data-id="<?= (int)$a['id'] ?>">
                        <td class="col-order">
                            <button type="button" class="btn move-up" style="font-size:11px;padding:2px 7px" title="Nach oben">&uarr;</button>
                            <button type="button" class="btn move-down" style="font-size:11px;padding:2px 7px" title="Nach unten">&darr;</button>
                        </td>
                        <td><input type="text" class="act-name-input" value="<?= h($a['name']) ?>" maxlength="100"></td>
                        <td class="col-active">
                            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                                <input type="checkbox" class="act-active" <?= $a['active'] ? 'checked' : '' ?>>
                                <span class="act-active-label"><?= $a['active'] ? 'aktiv' : 'inaktiv' ?></span>
                            </label>
                        </td>
                        <td class="act-actions">
                            <button type="button" class="btn save-btn">Speichern</button>
                            <button type="button" class="btn btn--danger del-btn">Löschen</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

async function apiCall(action, params) {
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action, ...params })
    });
    return res.json();
}

function attachRowHandlers(row) {
    row.querySelector('.save-btn')?.addEventListener('click', () => saveRow(row));
    row.querySelector('.del-btn')?.addEventListener('click', () => deleteRow(row));
    row.querySelector('.act-active')?.addEventListener('change', () => toggleActive(row));
    row.querySelector('.move-up')?.addEventListener('click', () => moveRow(row, -1));
    row.querySelector('.move-down')?.addEventListener('click', () => moveRow(row, 1));
}

async function saveRow(row) {
    const id    = row.dataset.id;
    const name  = row.querySelector('.act-name-input').value.trim();
    if (!name) { Dialog.alert('Name darf nicht leer sein.'); return; }
    const data = await apiCall('rename_activity', { id, name });
    if (!data.success) { Dialog.alert('Fehler: ' + (data.error || 'Unbekannt')); return; }
    flash(row, '✓');
}

async function toggleActive(row) {
    const id   = row.dataset.id;
    const data = await apiCall('toggle_activity', { id });
    if (!data.success) { Dialog.alert('Fehler: ' + (data.error || 'Unbekannt')); return; }
    const active = data.data.active === 1;
    row.classList.toggle('act-inactive', !active);
    row.querySelector('.act-active').checked = active;
    row.querySelector('.act-active-label').textContent = active ? 'aktiv' : 'inaktiv';
}

async function deleteRow(row) {
    const name = row.querySelector('.act-name-input').value.trim();
    if (!await Dialog.confirm('Tätigkeit „' + name + '" aus der Liste löschen?\n\n' +
        'Bestehende Zeiteinträge bleiben unverändert.', { danger: true })) return;
    const data = await apiCall('delete_activity', { id: row.dataset.id });
    if (!data.success) { Dialog.alert('Fehler: ' + (data.error || 'Unbekannt')); return; }
    row.remove();
    ensureNotEmpty();
}

async function moveRow(row, dir) {
    const tbody = document.getElementById('actTbody');
    if (dir < 0 && row.previousElementSibling) {
        tbody.insertBefore(row, row.previousElementSibling);
    } else if (dir > 0 && row.nextElementSibling) {
        tbody.insertBefore(row.nextElementSibling, row);
    } else {
        return;
    }
    const ids = [...tbody.querySelectorAll('tr[data-id]')].map(r => r.dataset.id);
    await apiCall('reorder_activities', { ids: ids.join(',') });
}

function flash(row, text) {
    const btn = row.querySelector('.save-btn');
    const orig = btn.textContent;
    btn.textContent = text;
    setTimeout(() => { btn.textContent = orig; }, 1500);
}

function ensureNotEmpty() {
    const tbody = document.getElementById('actTbody');
    if (!tbody.querySelector('tr[data-id]')) {
        tbody.innerHTML = '<tr id="emptyRow"><td colspan="4" class="empty-message">Noch keine Tätigkeiten vorhanden.</td></tr>';
    }
}

document.getElementById('addBtn').addEventListener('click', async function() {
    const input = document.getElementById('newActName');
    const msg   = document.getElementById('addMsg');
    const name  = input.value.trim();
    msg.textContent = '';
    if (!name) { msg.style.color = 'var(--danger)'; msg.textContent = 'Bitte einen Namen eingeben.'; return; }

    const data = await apiCall('add_activity', { name });
    if (!data.success) {
        msg.style.color = 'var(--danger)';
        msg.textContent = data.error || 'Fehler beim Hinzufügen.';
        return;
    }
    document.getElementById('emptyRow')?.remove();
    const tbody = document.getElementById('actTbody');
    const tr = document.createElement('tr');
    tr.className = 'entry-row';
    tr.dataset.id = data.data.id;
    tr.innerHTML =
        '<td class="col-order">' +
            '<button type="button" class="btn move-up" style="font-size:11px;padding:2px 7px" title="Nach oben">&uarr;</button>' +
            '<button type="button" class="btn move-down" style="font-size:11px;padding:2px 7px" title="Nach unten">&darr;</button>' +
        '</td>' +
        '<td><input type="text" class="act-name-input" maxlength="100"></td>' +
        '<td class="col-active"><label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">' +
            '<input type="checkbox" class="act-active" checked><span class="act-active-label">aktiv</span></label></td>' +
        '<td class="act-actions">' +
            '<button type="button" class="btn save-btn">Speichern</button>' +
            '<button type="button" class="btn btn--danger del-btn">Löschen</button>' +
        '</td>';
    tr.querySelector('.act-name-input').value = data.data.name;
    tbody.appendChild(tr);
    attachRowHandlers(tr);
    input.value = '';
    msg.style.color = 'var(--success)';
    msg.textContent = '✓ Hinzugefügt';
    setTimeout(() => { msg.textContent = ''; }, 1500);
});

document.getElementById('newActName').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('addBtn').click(); }
});

document.querySelectorAll('#actTbody tr[data-id]').forEach(attachRowHandlers);
</script>
</body>
</html>
