<?php
require_once __DIR__ . '/auth.php';

$stmt = db()->query(
    "SELECT id, username, email, role, created_at FROM tm_users ORDER BY username ASC"
);
$users = $stmt->fetchAll();

$roleLabels = [
    'admin'       => 'Admin',
    'mitarbeiter' => 'Mitarbeiter',
    'kunde'       => 'Kunde',
];
$roleBadge = [
    'admin'       => 'badge--admin',
    'mitarbeiter' => 'badge--mitarbeiter',
    'kunde'       => 'badge--kunde',
];
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Benutzer – Administration</title>
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Benutzer</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo; Benutzer
            </div>
        </div>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <!-- Add user -->
    <div class="admin-section">
        <h2>Neuen Benutzer anlegen</h2>
        <div id="addMsg"></div>
        <div class="edit-form" style="margin-bottom:0">
            <input type="text"     id="newUsername" placeholder="Benutzername" maxlength="50" style="flex:1 1 130px;min-width:100px">
            <input type="email"    id="newEmail"    placeholder="E-Mail (optional)" style="flex:1 1 160px;min-width:120px">
            <input type="password" id="newPassword" placeholder="Passwort (min. 8 Zeichen)" style="flex:1 1 160px;min-width:120px">
            <select id="newRole" style="flex:0 1 130px;min-width:100px">
                <option value="mitarbeiter">Mitarbeiter</option>
                <option value="admin">Admin</option>
                <option value="kunde">Kunde</option>
            </select>
            <button class="btn btn--primary" id="addBtn">Hinzufügen</button>
        </div>
    </div>

    <!-- User list -->
    <div class="admin-section">
        <h2>Alle Benutzer</h2>
        <div id="listMsg"></div>
        <div class="table-wrapper">
            <table class="entries-table" id="userTable">
                <thead>
                    <tr>
                        <th>Benutzername</th>
                        <th>E-Mail</th>
                        <th>Rolle</th>
                        <th>Erstellt</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr class="entry-row" id="urow-<?= (int)$u['id'] ?>">
                        <td><?= h($u['username']) ?></td>
                        <td><?= h($u['email'] ?? '') ?></td>
                        <td>
                            <span class="badge <?= h($roleBadge[$u['role']] ?? '') ?>">
                                <?= h($roleLabels[$u['role']] ?? $u['role']) ?>
                            </span>
                        </td>
                        <td class="col-time"><?= h(date('d.m.Y', strtotime($u['created_at']))) ?></td>
                        <td>
                            <div class="actions-normal" id="uact-<?= (int)$u['id'] ?>">
                                <!-- Edit -->
                                <button class="btn-icon" title="Bearbeiten" onclick="showEditRow(<?= (int)$u['id'] ?>)">
                                    <svg viewBox="0 0 24 24" width="15" height="15"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </button>
                                <!-- Delete -->
                                <?php if ($u['id'] !== $adminUser['id']): ?>
                                <button class="btn-icon btn-icon--danger" title="Löschen" onclick="showDeleteConfirm(<?= (int)$u['id'] ?>)">
                                    <svg viewBox="0 0 24 24" width="15" height="15"><path d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="actions-confirm hidden" id="udel-<?= (int)$u['id'] ?>">
                                <button class="btn-icon btn-icon--confirm" title="Löschen bestätigen" onclick="confirmDelete(<?= (int)$u['id'] ?>)">
                                    <svg viewBox="0 0 24 24" width="15" height="15"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                </button>
                                <button class="btn-icon" title="Abbrechen" onclick="cancelDelete(<?= (int)$u['id'] ?>)">
                                    <svg viewBox="0 0 24 24" width="15" height="15"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr class="edit-row hidden" id="uedit-<?= (int)$u['id'] ?>">
                        <td colspan="5">
                            <div class="edit-form">
                                <input type="text"     class="edit-username" placeholder="Benutzername" value="<?= h($u['username']) ?>" maxlength="50" style="flex:1 1 130px;min-width:100px">
                                <input type="email"    class="edit-email"    placeholder="E-Mail (optional)" value="<?= h($u['email'] ?? '') ?>" style="flex:1 1 160px;min-width:120px">
                                <input type="password" class="edit-password" placeholder="Neues Passwort (leer = unverändert)" style="flex:1 1 160px;min-width:120px">
                                <select class="edit-role" style="flex:0 1 130px;min-width:100px">
                                    <option value="mitarbeiter" <?= $u['role']==='mitarbeiter'?'selected':'' ?>>Mitarbeiter</option>
                                    <option value="admin"       <?= $u['role']==='admin'      ?'selected':'' ?>>Admin</option>
                                    <option value="kunde"       <?= $u['role']==='kunde'      ?'selected':'' ?>>Kunde</option>
                                </select>
                                <button class="btn btn--primary" onclick="saveEdit(<?= (int)$u['id'] ?>)">Speichern</button>
                                <button class="btn" onclick="cancelEdit(<?= (int)$u['id'] ?>)">Abbrechen</button>
                            </div>
                            <div class="edit-msg" id="ueditmsg-<?= (int)$u['id'] ?>" style="margin-top:6px"></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr id="emptyRow"><td colspan="5" class="empty-message">Keine Benutzer vorhanden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const CSRF    = <?= json_encode($_SESSION['csrf_token']) ?>;
const SELF_ID = <?= (int)$adminUser['id'] ?>;

const ROLE_LABELS = { admin: 'Admin', mitarbeiter: 'Mitarbeiter', kunde: 'Kunde' };
const ROLE_BADGE  = { admin: 'badge--admin', mitarbeiter: 'badge--mitarbeiter', kunde: 'badge--kunde' };

async function api(action, params) {
    const body = new URLSearchParams({ action, ...params });
    const res  = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body
    });
    return res.json();
}

function showMsg(el, text, ok) {
    el.className  = 'admin-msg ' + (ok ? 'admin-msg--ok' : 'admin-msg--err');
    el.textContent = text;
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ---------- Add user ---------- */
document.getElementById('addBtn').addEventListener('click', async () => {
    const msgEl    = document.getElementById('addMsg');
    const username = document.getElementById('newUsername').value.trim();
    const email    = document.getElementById('newEmail').value.trim();
    const password = document.getElementById('newPassword').value;
    const role     = document.getElementById('newRole').value;
    const btn      = document.getElementById('addBtn');

    btn.disabled = true;
    try {
        const data = await api('add_user', { username, email, password, role });
        if (data.success) {
            const d    = data.data;
            const tbody = document.querySelector('#userTable tbody');
            const emptyRow = document.getElementById('emptyRow');
            if (emptyRow) emptyRow.remove();

            const badge = `<span class="badge ${escHtml(ROLE_BADGE[d.role])}">${escHtml(ROLE_LABELS[d.role])}</span>`;
            const delBtn = `<button class="btn-icon btn-icon--danger" title="Löschen" onclick="showDeleteConfirm(${d.id})">
                <svg viewBox="0 0 24 24" width="15" height="15"><path d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
            </button>`;

            tbody.insertAdjacentHTML('beforeend', `
                <tr class="entry-row" id="urow-${d.id}">
                    <td>${escHtml(d.username)}</td>
                    <td>${escHtml(d.email)}</td>
                    <td>${badge}</td>
                    <td class="col-time">${new Date().toLocaleDateString('de-DE',{day:'2-digit',month:'2-digit',year:'numeric'})}</td>
                    <td>
                        <div class="actions-normal" id="uact-${d.id}">
                            <button class="btn-icon" title="Bearbeiten" onclick="showEditRow(${d.id})">
                                <svg viewBox="0 0 24 24" width="15" height="15"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                            </button>
                            ${delBtn}
                        </div>
                        <div class="actions-confirm hidden" id="udel-${d.id}">
                            <button class="btn-icon btn-icon--confirm" title="Löschen bestätigen" onclick="confirmDelete(${d.id})">
                                <svg viewBox="0 0 24 24" width="15" height="15"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                            </button>
                            <button class="btn-icon" title="Abbrechen" onclick="cancelDelete(${d.id})">
                                <svg viewBox="0 0 24 24" width="15" height="15"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <tr class="edit-row hidden" id="uedit-${d.id}">
                    <td colspan="5">
                        <div class="edit-form">
                            <input type="text"     class="edit-username" placeholder="Benutzername" value="${escHtml(d.username)}" maxlength="50" style="flex:1 1 130px;min-width:100px">
                            <input type="email"    class="edit-email"    placeholder="E-Mail (optional)" value="${escHtml(d.email)}" style="flex:1 1 160px;min-width:120px">
                            <input type="password" class="edit-password" placeholder="Neues Passwort (leer = unverändert)" style="flex:1 1 160px;min-width:120px">
                            <select class="edit-role" style="flex:0 1 130px;min-width:100px">
                                <option value="mitarbeiter" ${d.role==='mitarbeiter'?'selected':''}>Mitarbeiter</option>
                                <option value="admin"       ${d.role==='admin'?'selected':''}>Admin</option>
                                <option value="kunde"       ${d.role==='kunde'?'selected':''}>Kunde</option>
                            </select>
                            <button class="btn btn--primary" onclick="saveEdit(${d.id})">Speichern</button>
                            <button class="btn" onclick="cancelEdit(${d.id})">Abbrechen</button>
                        </div>
                        <div class="edit-msg" id="ueditmsg-${d.id}" style="margin-top:6px"></div>
                    </td>
                </tr>
            `);

            document.getElementById('newUsername').value = '';
            document.getElementById('newEmail').value    = '';
            document.getElementById('newPassword').value = '';
            document.getElementById('newRole').value     = 'mitarbeiter';
            showMsg(msgEl, 'Benutzer wurde angelegt.', true);
        } else {
            showMsg(msgEl, data.error || 'Fehler beim Anlegen.', false);
        }
    } catch (e) {
        showMsg(msgEl, 'Serverfehler. Bitte erneut versuchen.', false);
    }
    btn.disabled = false;
});

/* ---------- Edit ---------- */
function showEditRow(id) {
    document.getElementById('urow-' + id).classList.add('hidden');
    document.getElementById('uedit-' + id).classList.remove('hidden');
}

function cancelEdit(id) {
    document.getElementById('uedit-' + id).classList.add('hidden');
    document.getElementById('urow-' + id).classList.remove('hidden');
    document.getElementById('ueditmsg-' + id).textContent = '';
}

async function saveEdit(id) {
    const editRow  = document.getElementById('uedit-' + id);
    const msgEl    = document.getElementById('ueditmsg-' + id);
    const username = editRow.querySelector('.edit-username').value.trim();
    const email    = editRow.querySelector('.edit-email').value.trim();
    const password = editRow.querySelector('.edit-password').value;
    const role     = editRow.querySelector('.edit-role').value;

    try {
        const data = await api('update_user', { id, username, email, password, role });
        if (data.success) {
            const d    = data.data;
            const row  = document.getElementById('urow-' + id);
            const tds  = row.querySelectorAll('td');
            tds[0].textContent = d.username;
            tds[1].textContent = d.email;
            tds[2].innerHTML   = `<span class="badge ${escHtml(ROLE_BADGE[d.role])}">${escHtml(ROLE_LABELS[d.role])}</span>`;
            cancelEdit(id);

            const listMsg = document.getElementById('listMsg');
            showMsg(listMsg, 'Benutzer wurde gespeichert.', true);
        } else {
            showMsg(msgEl, data.error || 'Fehler beim Speichern.', false);
        }
    } catch (e) {
        showMsg(msgEl, 'Serverfehler. Bitte erneut versuchen.', false);
    }
}

/* ---------- Delete ---------- */
function showDeleteConfirm(id) {
    document.getElementById('uact-' + id).classList.add('hidden');
    document.getElementById('udel-' + id).classList.remove('hidden');
}

function cancelDelete(id) {
    document.getElementById('udel-' + id).classList.add('hidden');
    document.getElementById('uact-' + id).classList.remove('hidden');
}

async function confirmDelete(id) {
    const msgEl = document.getElementById('listMsg');
    try {
        const data = await api('delete_user', { id });
        if (data.success) {
            document.getElementById('urow-'  + id)?.remove();
            document.getElementById('uedit-' + id)?.remove();
            const tbody = document.querySelector('#userTable tbody');
            if (!tbody.querySelector('tr')) {
                tbody.innerHTML = '<tr id="emptyRow"><td colspan="5" class="empty-message">Keine Benutzer vorhanden.</td></tr>';
            }
            showMsg(msgEl, 'Benutzer wurde gelöscht.', true);
        } else {
            cancelDelete(id);
            showMsg(msgEl, data.error || 'Fehler beim Löschen.', false);
        }
    } catch (e) {
        cancelDelete(id);
        showMsg(msgEl, 'Serverfehler. Bitte erneut versuchen.', false);
    }
}
</script>
</body>
</html>
