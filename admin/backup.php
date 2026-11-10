<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/backup_files.php';

define('BACKUP_DIR', dirname(__DIR__) . '/backups');

// Vorhandene Backups einlesen
$backups = [];
if (is_dir(BACKUP_DIR)) {
    $paths = glob(BACKUP_DIR . '/tm_backup_*.zip') ?: [];
    usort($paths, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach ($paths as $path) {
        $backups[] = backupInfo($path);
    }
}
$uploadLimit = backupUploadLimit();

function fmtSize(int $bytes): string
{
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
}
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('backup.pageTitle')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.col-num { white-space: nowrap; }
.backup-msg { font-size: 13px; margin-left: 8px; }
.backup-opt { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
.backup-hint { font-size: 12px; color: var(--text-muted); margin: -8px 0 16px; }
.backup-badge { font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--border, #ddd); white-space: nowrap; }
.backup-actions .btn { font-size: 11px; padding: 2px 8px; margin-left: 4px; }
.backup-actions .btn:first-child { margin-left: 0; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1><?= h(t('admin.card.backup')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo; <?= h(t('admin.card.backup')) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="index.php" class="btn"><?= h(t('admin.back')) ?></a>
            <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
        </div>
    </div>

    <div class="admin-section">

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
            <button type="button" class="btn btn--primary" id="createBtn"><?= h(t('backup.create')) ?></button>
            <label class="backup-opt"><input type="checkbox" id="withFiles" checked> <?= h(t('backup.withFiles')) ?></label>
            <label for="uploadInput" class="btn" id="uploadBtn" style="cursor:pointer"><?= h(t('backup.upload')) ?></label>
            <input type="file" id="uploadInput" accept=".zip" style="display:none">
            <span id="createMsg" class="backup-msg"></span>
        </div>
        <?php if ($uploadLimit > 0): ?>
        <p class="backup-hint"><?= h(t('backup.uploadLimit', ['size' => fmtSize($uploadLimit)])) ?></p>
        <?php endif; ?>

        <h2><?= h(t('backup.existing')) ?></h2>

        <div class="table-wrapper">
            <table class="entries-table" id="backupTable">
                <thead>
                    <tr>
                        <th><?= h(t('logs.colName')) ?></th>
                        <th class="col-num"><?= h(t('users.colCreated')) ?></th>
                        <th><?= h(t('backup.colContent')) ?></th>
                        <th class="col-num"><?= h(t('logs.colSize')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($backups)): ?>
                    <tr id="emptyRow"><td colspan="5" class="empty-message"><?= h(t('backup.empty')) ?></td></tr>
                <?php else: foreach ($backups as $b): ?>
                    <tr class="entry-row" id="row-<?= h($b['name']) ?>"></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
window.I18N = <?= json_encode(i18nStrings(), JSON_UNESCAPED_UNICODE) ?>;
window.LANG = <?= json_encode(currentLang()) ?>;
function t(key, params) {
    let s = (window.I18N && window.I18N[key]) || key;
    if (params) { for (const k in params) { s = s.split('{' + k + '}').join(params[k]); } }
    return s;
}
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
const BACKUPS = <?= json_encode($backups, JSON_UNESCAPED_UNICODE) ?>;

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
    row.querySelector('.restore-btn')?.addEventListener('click', handleRestore);
    row.querySelector('.mail-btn')?.addEventListener('click', handleMail);
    row.querySelector('.del-btn')?.addEventListener('click', handleDelete);
}

function rowHtml(b) {
    const file = escAttr(b.name);
    return '<td>' + file + '</td>' +
        '<td class="col-num">' + escAttr(b.mtime) + '</td>' +
        '<td><span class="backup-badge">' + escAttr(t(b.files ? 'backup.contentFull' : 'backup.contentDb')) + '</span></td>' +
        '<td class="col-num">' + fmtSize(b.size) + '</td>' +
        '<td class="backup-actions" style="white-space:nowrap">' +
            '<a class="btn" href="backup_download.php?file=' + encodeURIComponent(b.name) + '" download>' + escAttr(t('backup.download')) + '</a>' +
            '<button type="button" class="btn restore-btn" data-file="' + file + '" data-files="' + (b.files ? '1' : '') + '">' + escAttr(t('backup.restore')) + '</button>' +
            (b.mailable ? '<button type="button" class="btn mail-btn" data-file="' + file + '">' + escAttr(t('backup.mail')) + '</button>' : '') +
            '<button type="button" class="btn btn--danger del-btn" data-file="' + file + '">' + escAttr(t('common.delete')) + '</button>' +
        '</td>';
}

function addBackupRow(b) {
    document.getElementById('emptyRow')?.remove();
    const tbody = document.querySelector('#backupTable tbody');
    let tr = document.getElementById('row-' + b.name);
    if (tr) {                         // vorhandene Zeile aktualisieren (Überschreiben)
        tr.innerHTML = rowHtml(b);
    } else {
        tr = document.createElement('tr');
        tr.className = 'entry-row';
        tr.id = 'row-' + b.name;
        tr.innerHTML = rowHtml(b);
        tbody.insertBefore(tr, tbody.firstChild);
    }
    attachRowHandlers(tr);
}

// ---- Backup erstellen ----
document.getElementById('createBtn').addEventListener('click', async function() {
    const btn = this;
    const msg = document.getElementById('createMsg');
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = t('backup.creating');
    msg.textContent = '';
    msg.style.color = '';

    try {
        const withFiles = document.getElementById('withFiles').checked ? '1' : '';
        const data = await apiCall('create_backup', { with_files: withFiles });
        if (data.success) {
            addBackupRow(data.data);
            msg.style.color = '#27ae60';
            msg.textContent = t('backup.created', { name: data.data.name });
        } else {
            msg.style.color = '#c0392b';
            msg.textContent = data.error || t('backup.createError');
        }
    } catch (e) {
        msg.style.color = '#c0392b';
        msg.textContent = t('config.serverError');
    }

    btn.disabled = false;
    btn.textContent = orig;
});

// ---- Backup hochladen ----
document.getElementById('uploadInput').addEventListener('change', async function() {
    const input = this;
    const file  = input.files && input.files[0];
    if (!file) return;

    const btn = document.getElementById('uploadBtn');
    const msg = document.getElementById('createMsg');
    const orig = btn.textContent;
    btn.textContent = t('backup.uploading');
    btn.style.pointerEvents = 'none';
    btn.style.opacity = '0.6';
    msg.textContent = '';
    msg.style.color = '';

    try {
        const fd = new FormData();
        fd.append('action', 'upload_backup');
        fd.append('file', file);
        const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd });
        const data = await res.json();
        if (data.success) {
            addBackupRow(data.data);
            msg.style.color = '#27ae60';
            msg.textContent = t('backup.uploaded', { name: data.data.name });
        } else {
            msg.style.color = '#c0392b';
            msg.textContent = data.error || t('backup.uploadError');
        }
    } catch (e) {
        msg.style.color = '#c0392b';
        msg.textContent = t('config.serverError');
    }

    input.value = '';
    btn.textContent = orig;
    btn.style.pointerEvents = '';
    btn.style.opacity = '';
});

// ---- Backup einspielen ----
async function handleRestore(ev) {
    const btn  = ev.currentTarget;
    const file = btn.dataset.file;
    let question = t('backup.confirmRestore', { file: file });
    if (btn.dataset.files) question += '\n\n' + t('backup.confirmRestoreFiles');
    if (!await Dialog.confirm(question, { danger: true })) return;

    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = t('backup.restoring');

    try {
        const data = await apiCall('restore_backup', { file });
        if (data.success) {
            let done = t('backup.restoreDone', {
                statements: data.data.statements,
                safety: data.data.safety || '—',
            });
            if (data.data.files) {
                done += '\n\n' + t('backup.restoreFiles', { restored: data.data.files.restored, removed: data.data.files.removed });
            }
            await Dialog.alert(done);
            location.reload();
        } else {
            Dialog.alert(t('backup.restoreErrorPrefix') + ': ' + (data.error || t('common.unknownError')));
            btn.textContent = orig;
            btn.disabled = false;
        }
    } catch (e) {
        Dialog.alert(t('backup.restoreServerError'));
        btn.textContent = orig;
        btn.disabled = false;
    }
}

// ---- Mail versenden ----
async function handleMail(ev) {
    const btn  = ev.currentTarget;
    const file = btn.dataset.file;
    if (!await Dialog.confirm(t('backup.confirmMail', { file: file }))) return;

    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = t('backup.sending');

    try {
        const data = await apiCall('mail_backup', { file });
        if (data.success) {
            btn.textContent = t('backup.sent');
            setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 4000);
        } else {
            Dialog.alert(t('common.error') + ': ' + (data.error || t('common.unknownError')));
            btn.textContent = orig;
            btn.disabled = false;
        }
    } catch (e) {
        Dialog.alert(t('config.serverError'));
        btn.textContent = orig;
        btn.disabled = false;
    }
}

// ---- Löschen ----
async function handleDelete(ev) {
    const btn  = ev.currentTarget;
    const file = btn.dataset.file;
    if (!await Dialog.confirm(t('backup.confirmDelete', { file: file }), { danger: true })) return;

    btn.disabled = true;
    try {
        const data = await apiCall('delete_backup', { file });
        if (data.success) {
            const row = document.getElementById('row-' + file);
            row?.remove();
            if (!document.querySelector('#backupTable tbody tr')) {
                const tbody = document.querySelector('#backupTable tbody');
                tbody.innerHTML = '<tr id="emptyRow"><td colspan="5" class="empty-message">' + t('backup.empty') + '</td></tr>';
            }
        } else {
            Dialog.alert(t('common.error') + ': ' + (data.error || t('common.unknownError')));
            btn.disabled = false;
        }
    } catch (e) {
        Dialog.alert(t('config.serverError'));
        btn.disabled = false;
    }
}

BACKUPS.forEach(b => {
    const tr = document.getElementById('row-' + b.name);
    if (tr) { tr.innerHTML = rowHtml(b); attachRowHandlers(tr); }
});
</script>
</body>
</html>
