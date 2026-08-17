<?php
require_once __DIR__ . '/auth.php';

$db = db();

$icoFolder = '<svg viewBox="0 0 512 512" width="15" height="15" aria-hidden="true" style="fill:#eab308"><path d="M64 480H448c35.3 0 64-28.7 64-64V160c0-35.3-28.7-64-64-64H288c-10.1 0-19.6-4.7-25.6-12.8L243.2 57.6C231.1 41.5 212.1 32 192 32H64C28.7 32 0 60.7 0 96V416c0 35.3 28.7 64 64 64z"/></svg>';
$icoLink   = '<svg viewBox="0 0 640 512" width="15" height="15" aria-hidden="true" style="fill:var(--text-muted)"><path d="M579.8 267.7c56.5-56.5 56.5-148 0-204.5c-50-50-128.8-56.5-186.3-15.4l-1.6 1.1c-14.4 10.3-17.7 30.3-7.4 44.6s30.3 17.7 44.6 7.4l1.6-1.1c32.1-22.9 76-19.3 103.8 8.6c31.5 31.5 31.5 82.5 0 114L422.3 334.8c-31.5 31.5-82.5 31.5-114 0c-27.9-27.9-31.5-71.8-8.6-103.8l1.1-1.6c10.3-14.4 6.9-34.4-7.4-44.6s-34.4-6.9-44.6 7.4l-1.1 1.6C206.5 251.2 213 330 263 380c56.5 56.5 148 56.5 204.5 0L579.8 267.7zM60.2 244.3c-56.5 56.5-56.5 148 0 204.5c50 50 128.8 56.5 186.3 15.4l1.6-1.1c14.4-10.3 17.7-30.3 7.4-44.6s-30.3-17.7-44.6-7.4l-1.6 1.1c-32.1 22.9-76 19.3-103.8-8.6C81.9 372 81.9 321 113.4 289.5L225.7 177.2c31.5-31.5 82.5-31.5 114 0c27.9 27.9 31.5 71.8 8.6 103.9l-1.1 1.6c-10.3 14.4-6.9 34.4 7.4 44.6s34.4 6.9 44.6-7.4l1.1-1.6C433.5 260.8 427 182 377 132c-56.5-56.5-148-56.5-204.5 0L60.2 244.3z"/></svg>';

// ---- Kontext bestimmen -------------------------------------------------
$parentParam = $_GET['parent'] ?? '';
$mode   = 'root';
$rows   = [];
$crumbs = [];        // Ordnerpfad für Breadcrumb
$looseCount = 0;
$curFolderId = null; // aktueller Ordner (nur im Ordner-Modus)

if ($parentParam === 'loose') {
    $mode = 'loose';
    $rows = $db->query(
        "SELECT id, type, active, title, url FROM tm_bookmarks
         WHERE parent_id IS NULL AND type='link' ORDER BY sort_order, id"
    )->fetchAll();
} elseif ($parentParam !== '' && ctype_digit((string)$parentParam)) {
    $parentId = (int)$parentParam;
    // Ordnerpfad nach oben aufbauen (und Gültigkeit prüfen)
    $node = $db->prepare('SELECT id, parent_id, type, title FROM tm_bookmarks WHERE id = ?');
    $cur  = $parentId;
    while ($cur !== null) {
        $node->execute([$cur]);
        $n = $node->fetch();
        if (!$n || $n['type'] !== 'folder') { break; }
        array_unshift($crumbs, $n);
        $cur = ($n['parent_id'] !== null) ? (int)$n['parent_id'] : null;
    }
    if (empty($crumbs)) { header('Location: bookmarks.php'); exit; }
    $mode = 'folder';
    $curFolderId = $parentId;
    $st = $db->prepare(
        "SELECT id, type, active, title, url,
                (SELECT COUNT(*) FROM tm_bookmarks c WHERE c.parent_id = tm_bookmarks.id) AS child_count
         FROM tm_bookmarks WHERE parent_id = ? ORDER BY sort_order, id"
    );
    $st->execute([$parentId]);
    $rows = $st->fetchAll();
} else {
    // Wurzel: oberste Ordner + Anzahl loser Links
    $rows = $db->query(
        "SELECT id, type, active, title, url,
                (SELECT COUNT(*) FROM tm_bookmarks c WHERE c.parent_id = tm_bookmarks.id) AS child_count
         FROM tm_bookmarks
         WHERE parent_id IS NULL AND type='folder' ORDER BY sort_order, id"
    )->fetchAll();
    $looseCount = (int)$db->query(
        "SELECT COUNT(*) FROM tm_bookmarks WHERE parent_id IS NULL AND type='link'"
    )->fetchColumn();
}

$renderRow = function (array $r) use ($icoFolder, $icoLink) {
    $id = (int)$r['id'];
    $isFolder = $r['type'] === 'folder';
    $folderEmpty = $isFolder && (int)($r['child_count'] ?? 0) === 0;
    $active = (int)($r['active'] ?? 1) === 1;
    ?>
    <tr class="entry-row<?= $active ? '' : ' bm-inactive' ?>" data-id="<?= $id ?>" data-type="<?= h($r['type']) ?>">
        <td class="col-check"><input type="checkbox" class="row-check" value="<?= $id ?>"></td>
        <td class="col-order">
            <button type="button" class="btn move-up" title="&uarr;">&uarr;</button>
            <button type="button" class="btn move-down" title="&darr;">&darr;</button>
        </td>
        <td class="col-type"><?= $isFolder ? $icoFolder : $icoLink ?></td>
        <td><input type="text" class="bm-title-input" value="<?= h($r['title']) ?>" maxlength="500"></td>
        <td>
            <?php if ($isFolder): ?>
                <span class="bm-muted">—</span>
            <?php else: ?>
                <input type="text" class="bm-url-input" value="<?= h((string)$r['url']) ?>" maxlength="2000" placeholder="https://…">
            <?php endif; ?>
        </td>
        <td class="col-active">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" class="bm-active"<?= $active ? ' checked' : '' ?>>
                <span class="bm-active-label"><?= h($active ? t('bmAdmin.active') : t('bmAdmin.inactive')) ?></span>
            </label>
        </td>
        <td class="act-actions">
            <?php if ($isFolder): ?>
            <a class="btn" href="bookmarks.php?parent=<?= $id ?>"><?= h(t('bmAdmin.details')) ?></a>
            <?php endif; ?>
            <button type="button" class="btn save-btn"><?= h(t('common.save')) ?></button>
            <?php if (!$isFolder || $folderEmpty): ?>
            <button type="button" class="btn btn--danger del-btn"><?= h(t('common.delete')) ?></button>
            <?php endif; ?>
        </td>
    </tr>
    <?php
};
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('admin.card.bookmarks')) ?> – <?= h(t('admin.title')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.bm-title-input, .bm-url-input {
    width: 100%; box-sizing: border-box; padding: 5px 8px;
    border: 1px solid var(--card-border); border-radius: 6px;
    background: var(--card-bg); color: var(--text); font-size: 13px;
}
.col-check { width: 28px; text-align: center; }
.col-check input { cursor: pointer; }
.col-order { width: 78px; white-space: nowrap; }
.col-active { width: 92px; white-space: nowrap; }
.bulk-bar {
    display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
    margin-top: 14px; padding: 12px 14px; border-radius: 8px;
    background: var(--hover-bg); border: 1px solid var(--card-border); font-size: 13px;
}
tr.bm-inactive td { opacity: .5; }
tr.bm-inactive td.col-active, tr.bm-inactive td.act-actions { opacity: 1; }
.col-type  { width: 28px; text-align: center; }
.col-type svg { vertical-align: middle; }
.bm-muted { color: var(--text-muted); font-size: 12px; }
.act-actions { white-space: nowrap; text-align: right; }
.act-actions .btn { font-size: 11px; padding: 2px 8px; margin-left: 4px; }
.col-order .btn { font-size: 11px; padding: 2px 7px; }
.bm-add-bars { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.bm-add-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.bm-add-bar input {
    padding: 5px 8px; border: 1px solid var(--card-border); border-radius: 6px;
    background: var(--card-bg); color: var(--text); font-size: 13px;
}
.bm-add-bar input[type=url] { flex: 1; min-width: 200px; max-width: 340px; }
.bm-import { display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
    padding: 10px 12px; border: 1px solid var(--card-border); border-radius: 8px;
    background: var(--hover-bg); margin-bottom: 8px; }
.bm-import-check { font-size: 12px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.bm-import-hint { font-size: 11px; color: var(--text-muted); margin: 0 0 16px; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1><?= h(t('admin.card.bookmarks')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo;
                <a href="bookmarks.php"><?= h(t('admin.card.bookmarks')) ?></a>
                <?php if ($mode === 'loose'): ?>
                    &rsaquo; <?= h(t('bookmarks.more')) ?>
                <?php elseif ($mode === 'folder'): $cn = count($crumbs); foreach ($crumbs as $i => $c): ?>
                    &rsaquo; <?php if ($i < $cn - 1): ?><a href="bookmarks.php?parent=<?= (int)$c['id'] ?>"><?= h($c['title']) ?></a><?php else: ?><?= h($c['title']) ?><?php endif; ?>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <?php if ($mode === 'root'): ?>
            <a href="index.php" class="btn"><?= h(t('admin.back')) ?></a>
            <?php else: ?>
            <a href="bookmarks.php" class="btn"><?= h(t('bmAdmin.backToFolders')) ?></a>
            <?php endif; ?>
            <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
        </div>
    </div>

    <div class="admin-section">
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 14px">
            <?= h($mode === 'root' ? t('bmAdmin.introFolders') : t('bmAdmin.introEntries')) ?>
        </p>

        <?php if ($mode === 'root'): ?>
        <div class="bm-import">
            <input type="file" id="bmImportFile" accept=".json,.html,.htm">
            <label class="bm-import-check"><input type="checkbox" id="bmImportReplace"> <?= h(t('bmAdmin.importReplace')) ?></label>
            <button type="button" class="btn" id="bmImportBtn"><?= h(t('bmAdmin.importBtn')) ?></button>
            <span id="bmImportMsg" style="font-size:12px"></span>
        </div>
        <p class="bm-import-hint"><?= h(t('bmAdmin.importHint')) ?></p>
        <?php endif; ?>

        <div class="bm-add-bars">
            <?php if ($mode !== 'loose'): ?>
            <div class="bm-add-bar">
                <input type="text" id="newFolderName" style="max-width:280px"
                       placeholder="<?= h($mode === 'folder' ? t('bmAdmin.subfolderNamePh') : t('bookmarks.folderName')) ?>" maxlength="500">
                <select id="newFolderPos">
                    <option value="end"><?= h(t('bmAdmin.posEnd')) ?></option>
                    <option value="start"><?= h(t('bmAdmin.posStart')) ?></option>
                </select>
                <button type="button" class="btn btn--primary" id="addFolderBtn"><?= h($mode === 'folder' ? t('bmAdmin.addSubfolder') : t('bmAdmin.addFolder')) ?></button>
            </div>
            <?php endif; ?>
            <?php if ($mode !== 'root'): ?>
            <div class="bm-add-bar">
                <input type="url" id="newLinkUrl" placeholder="https://…" inputmode="url">
                <input type="text" id="newLinkTitle" style="max-width:220px" placeholder="<?= h(t('bookmarks.titleLabel')) ?>" maxlength="500">
                <button type="button" class="btn btn--primary" id="addLinkBtn"><?= h(t('bmAdmin.addLink')) ?></button>
            </div>
            <?php endif; ?>
            <span id="addMsg" style="font-size:12px"></span>
        </div>

        <div class="table-wrapper">
            <table class="entries-table" id="bmTable">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="checkAll"></th>
                        <th class="col-order"><?= h(t('bmAdmin.colOrder')) ?></th>
                        <th class="col-type"></th>
                        <th><?= h(t('bmAdmin.colTitle')) ?></th>
                        <th><?= h(t('bmAdmin.colUrl')) ?></th>
                        <th class="col-active"><?= h(t('bmAdmin.colActive')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="bmTbody">
                <?php if (empty($rows) && !($mode === 'root' && $looseCount > 0)): ?>
                    <tr id="emptyRow"><td colspan="7" class="empty-message"><?= h(t($mode === 'root' ? 'bmAdmin.emptyFolders' : 'bmAdmin.emptyEntries')) ?></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r) { $renderRow($r); } ?>
                    <?php if ($mode === 'root' && $looseCount > 0): ?>
                    <tr class="entry-row bm-pseudo">
                        <td class="col-check"></td>
                        <td class="col-order"></td>
                        <td class="col-type"><?= $icoFolder ?></td>
                        <td><span class="bm-muted"><?= h(t('bookmarks.more')) ?></span></td>
                        <td><span class="bm-muted"><?= (int)$looseCount ?>&nbsp;Links</span></td>
                        <td class="col-active"></td>
                        <td class="act-actions"><a class="btn" href="bookmarks.php?parent=loose"><?= h(t('bmAdmin.details')) ?></a></td>
                    </tr>
                    <?php endif; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="bulkBar" class="bulk-bar" style="display:none">
            <span><strong id="bulkCount">0</strong>&nbsp;<?= h(t('adminEntries.selected')) ?></span>
            <button type="button" class="btn btn--danger" id="bulkDeleteBtn"><?= h(t('common.delete')) ?></button>
        </div>
    </div>

</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
window.I18N = <?= json_encode(i18nStrings(), JSON_UNESCAPED_UNICODE) ?>;
function t(key, params) {
    let s = (window.I18N && window.I18N[key]) || key;
    if (params) { for (const k in params) { s = s.split('{' + k + '}').join(params[k]); } }
    return s;
}

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
    row.querySelector('.move-up')?.addEventListener('click', () => moveRow(row, -1));
    row.querySelector('.move-down')?.addEventListener('click', () => moveRow(row, 1));
    row.querySelector('.bm-active')?.addEventListener('change', () => toggleActive(row));
}

async function toggleActive(row) {
    const data = await apiCall('toggle_bookmark', { id: row.dataset.id });
    if (!data.success) { Dialog.alert(t('common.error') + ': ' + (data.error || '')); return; }
    const active = data.data.active === 1;
    row.classList.toggle('bm-inactive', !active);
    row.querySelector('.bm-active').checked = active;
    row.querySelector('.bm-active-label').textContent = active ? t('bmAdmin.active') : t('bmAdmin.inactive');
}

async function saveRow(row) {
    const id    = row.dataset.id;
    const title = row.querySelector('.bm-title-input').value.trim();
    if (!title) { Dialog.alert(t('bmAdmin.titleRequired')); return; }
    const params = { id, title };
    const urlInput = row.querySelector('.bm-url-input');
    if (urlInput) params.url = urlInput.value.trim();
    const data = await apiCall('update_bookmark', params);
    if (!data.success) { Dialog.alert(t('common.error') + ': ' + (data.error || '')); return; }
    if (urlInput && data.data && data.data.url) urlInput.value = data.data.url;
    flash(row);
}

async function deleteRow(row) {
    const title = row.querySelector('.bm-title-input').value.trim();
    const isFolder = row.dataset.type === 'folder';
    const msg = isFolder
        ? t('bmAdmin.confirmDelFolder', { name: title })
        : t('bmAdmin.confirmDelLink', { name: title });
    if (!await Dialog.confirm(msg, { danger: true })) return;
    const data = await apiCall('delete_bookmark', { id: row.dataset.id });
    if (!data.success) { Dialog.alert(t('common.error') + ': ' + (data.error || '')); return; }
    row.remove();
    ensureNotEmpty();
}

async function moveRow(row, dir) {
    const tbody = document.getElementById('bmTbody');
    if (dir < 0 && row.previousElementSibling && row.previousElementSibling.dataset.id) {
        tbody.insertBefore(row, row.previousElementSibling);
    } else if (dir > 0 && row.nextElementSibling && row.nextElementSibling.dataset.id) {
        tbody.insertBefore(row.nextElementSibling, row);
    } else {
        return;
    }
    const ids = [...tbody.querySelectorAll('tr[data-id]')].map(r => r.dataset.id);
    await apiCall('reorder_bookmarks', { ids: ids.join(',') });
}

function flash(row) {
    const btn = row.querySelector('.save-btn');
    const orig = btn.textContent;
    btn.textContent = '✓';
    setTimeout(() => { btn.textContent = orig; }, 1200);
}

function ensureNotEmpty() {
    const tbody = document.getElementById('bmTbody');
    if (!tbody.querySelector('tr[data-id]') && !tbody.querySelector('.bm-pseudo')) {
        tbody.innerHTML = '<tr id="emptyRow"><td colspan="7" class="empty-message">' +
            t('<?= $mode === 'root' ? 'bmAdmin.emptyFolders' : 'bmAdmin.emptyEntries' ?>') + '</td></tr>';
    }
}

document.querySelectorAll('#bmTbody tr[data-id]').forEach(attachRowHandlers);

/* ---- Mehrfachauswahl + Bulk-Löschen ---- */
function selectedChecks() {
    return Array.from(document.querySelectorAll('.row-check:checked'));
}

function updateBulkBar() {
    const checked = selectedChecks();
    document.getElementById('bulkCount').textContent = checked.length;
    document.getElementById('bulkBar').style.display = checked.length > 0 ? 'flex' : 'none';
    const all      = document.querySelectorAll('.row-check');
    const checkAll = document.getElementById('checkAll');
    checkAll.checked       = all.length > 0 && checked.length === all.length;
    checkAll.indeterminate = checked.length > 0 && checked.length < all.length;
}

async function bulkDelete() {
    const checked = selectedChecks();
    if (!checked.length) return;
    if (!await Dialog.confirm(t('bmAdmin.confirmBulkDelete', { n: checked.length }), { danger: true })) return;
    const ids  = checked.map(cb => cb.value).join(',');
    const data = await apiCall('delete_bookmarks', { ids });
    if (!data.success) { Dialog.alert(t('common.error') + ': ' + (data.error || '')); return; }
    checked.forEach(cb => cb.closest('tr').remove());
    updateBulkBar();
    ensureNotEmpty();
}

if (document.getElementById('bulkBar')) {
    document.getElementById('checkAll').addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(cb => { cb.checked = this.checked; });
        updateBulkBar();
    });
    document.getElementById('bmTbody').addEventListener('change', function (ev) {
        if (ev.target.classList.contains('row-check')) updateBulkBar();
    });
    document.getElementById('bulkDeleteBtn').addEventListener('click', bulkDelete);
}

/* ---- Anlegen ---- */
const BM_PARENT = <?= $curFolderId !== null ? (int)$curFolderId : "''" ?>;

function showAddMsg(text, isErr) {
    const msg = document.getElementById('addMsg');
    if (!msg) return;
    msg.textContent = text;
    msg.style.color = isErr ? 'var(--danger)' : 'var(--success)';
}

async function addFolder() {
    const input = document.getElementById('newFolderName');
    const name  = input.value.trim();
    if (!name) { showAddMsg(t('bookmarks.folderRequired'), true); return; }
    const position = document.getElementById('newFolderPos')?.value || 'end';
    const data = await apiCall('add_bookmark_folder', { parent_id: BM_PARENT, title: name, position });
    if (!data.success) { showAddMsg((data.error || t('common.error')), true); return; }
    location.reload();
}

async function addLink() {
    const url   = document.getElementById('newLinkUrl').value.trim();
    const title = document.getElementById('newLinkTitle').value.trim();
    if (!url) { showAddMsg(t('bookmarks.urlRequired'), true); return; }
    const data = await apiCall('add_bookmark', { parent_id: BM_PARENT, url, title });
    if (!data.success) { showAddMsg((data.error || t('common.error')), true); return; }
    location.reload();
}

/* ---- Import (Firefox-JSON oder Bookmark-HTML) ---- */
async function bmImport() {
    const fileInput = document.getElementById('bmImportFile');
    const msg  = document.getElementById('bmImportMsg');
    const file = fileInput.files[0];
    if (!file) { msg.style.color = 'var(--danger)'; msg.textContent = t('bmAdmin.importNoFile'); return; }
    const replace = document.getElementById('bmImportReplace').checked;
    const fd = new FormData();
    fd.append('action', 'import_bookmarks');
    fd.append('file', file);
    fd.append('replace', replace ? '1' : '0');
    const btn = document.getElementById('bmImportBtn');
    btn.disabled = true;
    msg.style.color = 'var(--text-muted)';
    msg.textContent = t('bmAdmin.importRunning');
    try {
        const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd });
        const data = await res.json();
        if (data.success) {
            msg.style.color = 'var(--success)';
            msg.textContent = t('bmAdmin.importDone', { folders: data.data.folders, links: data.data.links });
            setTimeout(() => location.reload(), 900);
        } else {
            msg.style.color = 'var(--danger)';
            msg.textContent = data.error || t('common.error');
        }
    } catch (e) {
        msg.style.color = 'var(--danger)';
        msg.textContent = t('common.error');
    }
    btn.disabled = false;
}
document.getElementById('bmImportBtn')?.addEventListener('click', bmImport);

document.getElementById('addFolderBtn')?.addEventListener('click', addFolder);
document.getElementById('addLinkBtn')?.addEventListener('click', addLink);
document.getElementById('newFolderName')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addFolder(); } });
document.getElementById('newLinkTitle')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addLink(); } });
</script>
</body>
</html>
