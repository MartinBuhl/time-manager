<?php
require_once __DIR__ . '/auth.php';

define('BACKUP_DIR', dirname(__DIR__) . '/backups');

// Vorhandene Backups einlesen
$backups = [];
if (is_dir(BACKUP_DIR)) {
    foreach (glob(BACKUP_DIR . '/tm_backup_*.zip') ?: [] as $path) {
        $backups[] = [
            'name'  => basename($path),
            'size'  => filesize($path),
            'mtime' => filemtime($path),
        ];
    }
    usort($backups, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

function fmtSize(int $bytes): string
{
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backup – Administration</title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.col-num { white-space: nowrap; }
.backup-msg { font-size: 13px; margin-left: 8px; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Backup</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo; Backup
            </div>
        </div>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div class="admin-section">

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <button type="button" class="btn btn--primary" id="createBtn">Backup erstellen</button>
            <span id="createMsg" class="backup-msg"></span>
        </div>

        <h2>Vorhandene Backups</h2>

        <div class="table-wrapper">
            <table class="entries-table" id="backupTable">
                <thead>
                    <tr>
                        <th>Dateiname</th>
                        <th class="col-num">Erstellt</th>
                        <th class="col-num">Größe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($backups)): ?>
                    <tr id="emptyRow"><td colspan="4" class="empty-message">Noch keine Backups vorhanden.</td></tr>
                <?php else: foreach ($backups as $b): ?>
                    <tr class="entry-row" id="row-<?= h($b['name']) ?>">
                        <td><?= h($b['name']) ?></td>
                        <td class="col-num"><?= h(date('d.m.Y H:i:s', $b['mtime'])) ?></td>
                        <td class="col-num"><?= h(fmtSize((int)$b['size'])) ?></td>
                        <td style="white-space:nowrap">
                            <button type="button" class="btn mail-btn" data-file="<?= h($b['name']) ?>"
                                    style="font-size:11px;padding:2px 8px">Mail</button>
                            <button type="button" class="btn btn--danger del-btn" data-file="<?= h($b['name']) ?>"
                                    style="font-size:11px;padding:2px 8px;margin-left:4px">Löschen</button>
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

function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

async function apiCall(action, params) {
    const body = new URLSearchParams({ action, ...params });
    const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body });
    return res.json();
}

function fmtSize(bytes) {
    if (bytes < 1024)        return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1).replace('.', ',') + ' KB';
    return (bytes / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB';
}

function attachRowHandlers(row) {
    row.querySelector('.mail-btn')?.addEventListener('click', handleMail);
    row.querySelector('.del-btn')?.addEventListener('click', handleDelete);
}

// ---- Backup erstellen ----
document.getElementById('createBtn').addEventListener('click', async function() {
    const btn = this;
    const msg = document.getElementById('createMsg');
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = 'Wird erstellt…';
    msg.textContent = '';
    msg.style.color = '';

    try {
        const data = await apiCall('create_backup', {});
        if (data.success) {
            const b = data.data;
            document.getElementById('emptyRow')?.remove();
            const tbody = document.querySelector('#backupTable tbody');
            const tr = document.createElement('tr');
            tr.className = 'entry-row';
            tr.id = 'row-' + b.name;
            tr.innerHTML =
                '<td>' + escAttr(b.name) + '</td>' +
                '<td class="col-num">' + escAttr(b.mtime) + '</td>' +
                '<td class="col-num">' + fmtSize(b.size) + '</td>' +
                '<td style="white-space:nowrap">' +
                    '<button type="button" class="btn mail-btn" data-file="' + escAttr(b.name) + '" style="font-size:11px;padding:2px 8px">Mail</button>' +
                    '<button type="button" class="btn btn--danger del-btn" data-file="' + escAttr(b.name) + '" style="font-size:11px;padding:2px 8px;margin-left:4px">Löschen</button>' +
                '</td>';
            tbody.insertBefore(tr, tbody.firstChild);
            attachRowHandlers(tr);
            msg.style.color = '#27ae60';
            msg.textContent = '✓ Backup erstellt: ' + b.name;
        } else {
            msg.style.color = '#c0392b';
            msg.textContent = data.error || 'Fehler beim Erstellen.';
        }
    } catch (e) {
        msg.style.color = '#c0392b';
        msg.textContent = 'Serverfehler.';
    }

    btn.disabled = false;
    btn.textContent = orig;
});

// ---- Mail versenden ----
async function handleMail(ev) {
    const btn  = ev.currentTarget;
    const file = btn.dataset.file;
    if (!await Dialog.confirm('Backup „' + file + '" an die Admin-Mailadresse senden?')) return;

    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Sende…';

    try {
        const data = await apiCall('mail_backup', { file });
        if (data.success) {
            btn.textContent = '✓ Gesendet';
            setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 4000);
        } else {
            Dialog.alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
            btn.textContent = orig;
            btn.disabled = false;
        }
    } catch (e) {
        Dialog.alert('Serverfehler.');
        btn.textContent = orig;
        btn.disabled = false;
    }
}

// ---- Löschen ----
async function handleDelete(ev) {
    const btn  = ev.currentTarget;
    const file = btn.dataset.file;
    if (!await Dialog.confirm('Backup „' + file + '" endgültig löschen?', { danger: true })) return;

    btn.disabled = true;
    try {
        const data = await apiCall('delete_backup', { file });
        if (data.success) {
            const row = document.getElementById('row-' + file);
            row?.remove();
            if (!document.querySelector('#backupTable tbody tr')) {
                const tbody = document.querySelector('#backupTable tbody');
                tbody.innerHTML = '<tr id="emptyRow"><td colspan="4" class="empty-message">Noch keine Backups vorhanden.</td></tr>';
            }
        } else {
            Dialog.alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
            btn.disabled = false;
        }
    } catch (e) {
        Dialog.alert('Serverfehler.');
        btn.disabled = false;
    }
}

document.querySelectorAll('#backupTable tbody tr').forEach(attachRowHandlers);
</script>
</body>
</html>
