<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/mail_client.php';

$configured = mcIsConfigured();
$mcS = mcSettings();
$fromEmail = mcFromEmail($mcS);
$fromLabel = $fromEmail === '' ? '' : ($mcS['mc_from_name'] !== '' ? $mcS['mc_from_name'] . ' <' . $fromEmail . '>' : $fromEmail);
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('mail.title')) ?> – <?= h(t('admin.title')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
html, body { height: 100%; }
body { margin: 0; overflow: hidden; background: var(--bg); }
.mc-app { display: flex; flex-direction: column; height: 100vh; height: 100dvh; }

.mc-top {
    display: flex; align-items: center; gap: 8px; padding: 8px 12px;
    border-bottom: 1px solid var(--card-border); background: var(--card-bg); flex-wrap: wrap;
}
.mc-top h1 { font-size: 16px; margin: 0 8px 0 0; }
.mc-top .mc-spacer { flex: 1; }
.mc-search { display: flex; align-items: center; gap: 4px; }
.mc-search input { width: 220px; max-width: 40vw; padding: 5px 8px; font-size: 13px; }
.mc-back { display: none; }

.mc-main { flex: 1; display: flex; min-height: 0; }
.mc-folders { width: 220px; min-width: 120px; overflow: auto; background: var(--card-bg); padding: 6px 0; }
.mc-right { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.mc-list { height: 40%; min-height: 60px; overflow: auto; background: var(--card-bg); position: relative; }
.mc-view { flex: 1; min-height: 80px; display: flex; flex-direction: column; background: var(--card-bg); }

.mc-split-v, .mc-split-h {
    flex: none; background: var(--card-border); position: relative; touch-action: none; user-select: none;
}
.mc-split-v { width: 5px; cursor: col-resize; }
.mc-split-h { height: 5px; cursor: row-resize; }
.mc-split-v::after, .mc-split-h::after {
    content: ''; position: absolute; background: var(--text-muted); opacity: .5; border-radius: 2px;
}
.mc-split-v::after { width: 1px; height: 28px; left: 2px; top: 50%; margin-top: -14px; box-shadow: -1px 0 0 0 transparent; }
.mc-split-h::after { height: 1px; width: 28px; top: 2px; left: 50%; margin-left: -14px; }
.mc-split-v:hover, .mc-split-h:hover, .mc-split-v:focus-visible, .mc-split-h:focus-visible,
.mc-dragging .mc-split-v.active, .mc-dragging .mc-split-h.active { background: var(--accent); outline: none; }
.mc-split-v::before, .mc-split-h::before { content: ''; position: absolute; z-index: 5; }
.mc-split-v::before { top: 0; bottom: 0; left: -3px; right: -3px; }
.mc-split-h::before { left: 0; right: 0; top: -3px; bottom: -3px; }
/* Touch-Geräte (iPad): breite Greifer mit sichtbarem Griff und großer Trefferfläche */
@media (any-pointer: coarse) {
    .mc-split-v { width: 14px; }
    .mc-split-h { height: 14px; }
    .mc-split-v::after { width: 4px; height: 44px; left: 5px; margin-top: -22px; opacity: .7; }
    .mc-split-h::after { height: 4px; width: 44px; top: 5px; margin-left: -22px; opacity: .7; }
    .mc-split-v::before { left: -9px; right: -9px; }
    .mc-split-h::before { top: -9px; bottom: -9px; }
}
.mc-dragging, .mc-dragging * { user-select: none; }
.mc-dragging iframe { pointer-events: none; }

.mc-folder {
    display: flex; align-items: center; gap: 6px; padding: 5px 10px; font-size: 13px; cursor: pointer;
    color: var(--text); border-left: 3px solid transparent; white-space: nowrap;
}
.mc-folder:hover { background: var(--hover-bg); }
.mc-folder.active { background: var(--hover-bg); border-left-color: var(--accent); font-weight: 600; }
.mc-folder.disabled { color: var(--text-muted); cursor: default; }
.mc-folder.drop { outline: 2px dashed var(--accent); outline-offset: -2px; }
.mc-folder svg { flex: none; fill: currentColor; opacity: .7; }
.mc-folder .mc-fname { overflow: hidden; text-overflow: ellipsis; flex: 1; }
.mc-badge { font-size: 11px; font-weight: 600; background: var(--accent); color: #fff; border-radius: 9px; padding: 0 6px; }

.mc-list { --c-from: 200px; --c-date: 132px; }
.mc-cols { display: grid; grid-template-columns: 24px 24px minmax(80px, 1fr) var(--c-from) var(--c-date); }
.mc-sticky { position: sticky; top: 0; z-index: 2; background: var(--card-bg); }
.mc-lhead {
    border-bottom: 1px solid var(--card-border); font-size: 12px; font-weight: 600; color: var(--text-muted);
    user-select: none;
}
.mc-lhead > div { position: relative; padding: 6px 8px; white-space: nowrap; min-width: 0; }
.mc-lh-t { display: block; overflow: hidden; text-overflow: ellipsis; }
.mc-lhead .mc-l-seen span { background: var(--text-muted); opacity: .6; }
.mc-colgrip { position: absolute; top: 0; bottom: 0; right: -6px; width: 12px; cursor: col-resize; touch-action: none; z-index: 1; }
.mc-colgrip::after { content: ''; position: absolute; left: 50%; top: 5px; bottom: 5px; width: 1px; margin-left: -.5px; background: var(--card-border); }
.mc-colgrip:hover::after, .mc-colgrip.active::after { background: var(--accent); width: 3px; margin-left: -1.5px; }
@media (any-pointer: coarse) {
    .mc-lhead > div { padding: 11px 8px; }
    .mc-colgrip { right: -16px; width: 32px; }
    .mc-colgrip::after { width: 4px; margin-left: -2px; top: 8px; bottom: 8px; border-radius: 2px; background: var(--text-muted); opacity: .5; }
    .mc-colgrip:hover::after, .mc-colgrip.active::after { width: 4px; margin-left: -2px; opacity: 1; }
}
.mc-row {
    align-items: center; height: 30px; border-bottom: 1px solid var(--card-border); cursor: default;
    font-size: 13px; color: var(--text); user-select: none; -webkit-user-select: none;
}
.mc-row > div { padding: 0 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.mc-row:hover { background: var(--hover-bg); }
.mc-row.checked { background: color-mix(in srgb, var(--accent) 20%, transparent); }
.mc-row.current { box-shadow: inset 3px 0 0 var(--accent); }
.mc-row.unseen .mc-l-subj, .mc-row.unseen .mc-l-from { font-weight: 700; }
.mc-l-seen { display: flex; align-items: center; justify-content: center; padding: 0 !important; align-self: stretch; }
.mc-row .mc-l-seen { cursor: pointer; }
.mc-l-seen span { width: 9px; height: 9px; border-radius: 50%; box-sizing: border-box; border: 1.5px solid transparent; }
.mc-row.unseen .mc-l-seen span { background: var(--accent); }
.mc-row:not(.unseen) .mc-l-seen:hover span { border-color: var(--text-muted); }
.mc-l-from, .mc-l-date { color: var(--text-muted); }
.mc-row.unseen .mc-l-from { color: var(--text); }
.mc-l-date { font-size: 12px; font-variant-numeric: tabular-nums; }
.mc-l-ans { display: flex; align-items: center; justify-content: center; padding: 0 !important; color: var(--text-muted); font-size: 13px; letter-spacing: -2px; }
.mc-ic { color: var(--text-muted); margin-right: 5px; font-weight: 400; }
.mc-ic.mc-flag { color: #e67e22; }
.mc-bulk {
    display: flex; gap: 6px; align-items: center; flex-wrap: wrap; padding: 6px 8px; font-size: 12px;
    background: var(--hover-bg); border-bottom: 1px solid var(--card-border);
}
.mc-bulk b { margin-right: 4px; }
.mc-bulk .btn, .mc-bulk select { font-size: 12px; padding: 3px 9px; }
.mc-bulk select { width: auto; max-width: 200px; }
.mc-more { display: block; margin: 10px auto; }
.mc-info { padding: 16px; font-size: 13px; color: var(--text-muted); text-align: center; }
.mc-error { padding: 10px 12px; font-size: 13px; color: var(--danger); }

.mc-head { padding: 10px 14px; border-bottom: 1px solid var(--card-border); font-size: 13px; }
.mc-head h2 { font-size: 16px; margin: 0 0 6px; word-break: break-word; }
.mc-meta { display: grid; grid-template-columns: auto 1fr; gap: 2px 8px; color: var(--text-muted); }
.mc-meta b { font-weight: 600; color: var(--text); }
.mc-meta span { word-break: break-word; }
.mc-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; align-items: center; }
.mc-actions .btn, .mc-actions select { font-size: 12px; padding: 3px 9px; }
.mc-actions select { width: auto; max-width: 220px; }
.mc-atts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.mc-att {
    display: inline-flex; align-items: center; gap: 5px; font-size: 12px; padding: 3px 8px;
    border: 1px solid var(--card-border); border-radius: var(--radius); color: var(--text); text-decoration: none;
    max-width: 260px;
}
.mc-att span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mc-att:hover { background: var(--hover-bg); }
.mc-remote {
    display: flex; gap: 8px; align-items: center; padding: 6px 14px; font-size: 12px;
    background: var(--hover-bg); border-bottom: 1px solid var(--card-border);
}
.mc-body { flex: 1; min-height: 0; position: relative; }
.mc-body iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; background: #fff; }
.mc-text {
    position: absolute; inset: 0; overflow: auto; margin: 0; padding: 12px 14px; font-size: 13px;
    white-space: pre-wrap; word-wrap: break-word; font-family: var(--font); color: var(--text);
}

.mc-reload {
    flex: none; border: 0; background: none; color: var(--text-muted); cursor: pointer; padding: 0 2px;
    line-height: 0; border-radius: 4px;
}
.mc-reload svg { fill: currentColor; opacity: 1; }
.mc-reload:hover, .mc-reload:focus-visible { color: var(--accent); outline: none; }
.mc-reload.spin svg { animation: mc-spin .8s linear infinite; }
@keyframes mc-spin { to { transform: rotate(360deg); } }
.mc-actions .mc-sep { width: 1px; align-self: stretch; background: var(--card-border); margin: 0 2px; }

.mc-toast {
    position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%); z-index: 1200; max-width: 90vw;
    background: var(--text); color: var(--bg); padding: 8px 14px; border-radius: var(--radius); font-size: 13px;
    box-shadow: 0 4px 16px rgba(0,0,0,.25); opacity: 0; transition: opacity .2s; pointer-events: none;
}
.mc-toast.show { opacity: 1; }

.mc-compose-overlay {
    position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,.35);
    display: flex; align-items: center; justify-content: center; padding: 16px;
}
.mc-compose-overlay[hidden] { display: none; }
.mc-compose {
    width: min(860px, 100%); height: min(760px, 100%); display: flex; flex-direction: column;
    background: var(--card-bg); color: var(--text); border-radius: var(--radius);
    box-shadow: 0 10px 40px rgba(0,0,0,.3); overflow: hidden; position: relative;
}
.mc-compose.drop::after {
    content: attr(data-drop); position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    background: rgba(0,0,0,.45); color: #fff; font-size: 18px; border: 3px dashed #fff; border-radius: var(--radius);
    pointer-events: none;
}
.mc-c-head { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid var(--card-border); }
.mc-c-head b { font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mc-c-x { border: 0; background: none; color: var(--text-muted); font-size: 22px; line-height: 1; cursor: pointer; padding: 0 4px; }
.mc-c-x:hover { color: var(--text); }
.mc-c-fields { padding: 4px 12px; border-bottom: 1px solid var(--card-border); }
.mc-c-row { display: flex; align-items: center; gap: 8px; padding: 3px 0; font-size: 13px; }
.mc-c-row > label { width: 58px; flex: none; color: var(--text-muted); }
.mc-c-row input[type=text] { flex: 1; min-width: 0; padding: 5px 8px; font-size: 13px; }
.mc-c-row .mc-c-from { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mc-c-row[hidden] { display: none; }
.mc-c-link { border: 0; background: none; color: var(--accent); cursor: pointer; font-size: 12px; padding: 2px 4px; flex: none; }
.mc-c-atts { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; flex: 1; min-width: 0; }
.mc-c-att {
    display: inline-flex; align-items: center; gap: 4px; font-size: 12px; padding: 2px 4px 2px 8px; max-width: 260px;
    border: 1px solid var(--card-border); border-radius: var(--radius);
}
.mc-c-att span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mc-c-att small { color: var(--text-muted); white-space: nowrap; }
.mc-c-att button { border: 0; background: none; cursor: pointer; color: var(--text-muted); font-size: 15px; line-height: 1; padding: 0 3px; }
.mc-c-att button:hover { color: var(--danger); }
.mc-c-body {
    flex: 1; min-height: 0; border: 0; border-radius: 0; resize: none; padding: 12px 14px; margin: 0;
    font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--card-bg); color: var(--text);
    outline: none;
}
.mc-c-foot { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-top: 1px solid var(--card-border); flex-wrap: wrap; }
.mc-c-status { font-size: 12px; color: var(--text-muted); }
.mc-c-status.err { color: var(--danger); }

@media (max-width: 760px) {
    .mc-compose-overlay { padding: 0; }
    .mc-compose { width: 100%; height: 100%; border-radius: 0; }
    .mc-c-row > label { width: 48px; }
    .mc-compose-btn-label { display: none; }
    .mc-split-v, .mc-split-h { display: none; }
    .mc-folders, .mc-right, .mc-list, .mc-view { display: none; }
    .mc-folders { width: auto !important; flex: 1; }
    .mc-list { height: auto !important; flex: 1; }
    .mc-app[data-pane="folders"] .mc-folders { display: block; }
    .mc-app[data-pane="list"] .mc-right,
    .mc-app[data-pane="view"] .mc-right { display: flex; }
    .mc-app[data-pane="list"] .mc-list { display: block; }
    .mc-app[data-pane="view"] .mc-view { display: flex; }
    .mc-app:not([data-pane="folders"]) .mc-back { display: inline-block; }
    .mc-top { flex-wrap: nowrap; gap: 6px; }
    .mc-top h1 { display: none; }
    .mc-search { flex: 1; min-width: 0; }
    .mc-search input { width: 100%; max-width: none; }
    .mc-app:not([data-pane="folders"]) .mc-admin-back { display: none; }
    .mc-list { --c-from: 30vw !important; --c-date: 62px !important; }
    .mc-colgrip { display: none; }
    .mc-row { height: 40px; }
}
</style>
</head>
<body>
<div class="mc-app" id="app" data-pane="list">

    <div class="mc-top">
        <a href="index.php" class="btn mc-admin-back"><?= h(t('admin.back')) ?></a>
        <button type="button" class="btn mc-back" id="backBtn">&#8592;</button>
        <h1><?= h(t('mail.title')) ?></h1>
        <?php if ($configured): ?>
        <button type="button" class="btn btn--primary" id="composeBtn" title="<?= h(t('mail.compose')) ?>">&#9998;<span class="mc-compose-btn-label"> <?= h(t('mail.compose')) ?></span></button>
        <?php endif; ?>
        <span class="mc-spacer"></span>
        <form class="mc-search" id="searchForm">
            <input type="search" id="searchInput" placeholder="<?= h(t('mail.search')) ?>" autocomplete="off">
        </form>
        <button type="button" class="btn" id="refreshBtn" title="<?= h(t('mail.refresh')) ?>">&#8635;</button>
    </div>

    <?php if (!$configured): ?>
    <div class="mc-info" style="margin-top:40px">
        <?= h(t('mail.notConfigured')) ?><br><br>
        <a class="btn btn--primary" href="config.php#cfg-group-section-7"><?= h(t('mail.toSettings')) ?></a>
    </div>
    <?php else: ?>
    <div class="mc-main" id="main">
        <nav class="mc-folders" id="folders" aria-label="<?= h(t('mail.folders')) ?>"></nav>
        <div class="mc-split-v" id="splitV" role="separator" aria-orientation="vertical" tabindex="0"
             title="<?= h(t('mail.dragToResize')) ?>"></div>
        <div class="mc-right" id="right">
            <div class="mc-list" id="list" tabindex="0"></div>
            <div class="mc-split-h" id="splitH" role="separator" aria-orientation="horizontal" tabindex="0"
                 title="<?= h(t('mail.dragToResize')) ?>"></div>
            <div class="mc-view" id="view"></div>
        </div>
    </div>
    <div class="mc-compose-overlay" id="compose" hidden>
        <form class="mc-compose" id="composeForm" autocomplete="off" data-drop="<?= h(t('mail.dropFiles')) ?>">
            <div class="mc-c-head">
                <b id="cTitle"></b>
                <span class="mc-spacer" style="flex:1"></span>
                <button type="button" class="mc-c-x" id="cClose" title="<?= h(t('mail.close')) ?>">&times;</button>
            </div>
            <div class="mc-c-fields">
                <div class="mc-c-row"><label><?= h(t('mail.from')) ?></label><span class="mc-c-from" id="cFrom"><?= h($fromLabel) ?></span></div>
                <div class="mc-c-row"><label for="cTo"><?= h(t('mail.to')) ?></label>
                    <input type="text" id="cTo" placeholder="<?= h(t('mail.recipientsHint')) ?>" spellcheck="false">
                    <button type="button" class="mc-c-link" id="cCcToggle"><?= h(t('mail.showCcBcc')) ?></button></div>
                <div class="mc-c-row" id="cCcRow" hidden><label for="cCc"><?= h(t('mail.cc')) ?></label><input type="text" id="cCc" spellcheck="false"></div>
                <div class="mc-c-row" id="cBccRow" hidden><label for="cBcc"><?= h(t('mail.bcc')) ?></label><input type="text" id="cBcc" spellcheck="false"></div>
                <div class="mc-c-row"><label for="cSubject"><?= h(t('mail.subject')) ?></label><input type="text" id="cSubject"></div>
                <div class="mc-c-row"><label>&#128206;</label>
                    <div class="mc-c-atts" id="cAtts"></div>
                    <button type="button" class="mc-c-link" id="cAttach"><?= h(t('mail.addAttachment')) ?></button>
                    <input type="file" id="cFile" multiple hidden></div>
            </div>
            <textarea class="mc-c-body" id="cBody" spellcheck="true"></textarea>
            <div class="mc-c-foot">
                <button type="submit" class="btn btn--primary" id="cSend"><?= h(t('mail.send')) ?></button>
                <button type="button" class="btn" id="cDraft"><?= h(t('mail.saveDraft')) ?></button>
                <span class="mc-c-status" id="cStatus"></span>
                <span style="flex:1"></span>
                <button type="button" class="btn" id="cDiscard"><?= h(t('mail.discard')) ?></button>
            </div>
        </form>
    </div>
    <div class="mc-toast" id="toast" role="status" aria-live="polite"></div>
    <?php endif; ?>
</div>

<?php if ($configured): ?>
<script>
window.I18N = <?= json_encode(i18nStrings(), JSON_UNESCAPED_UNICODE) ?>;
function t(key, params) {
    let s = (window.I18N && window.I18N[key]) || key;
    if (params) { for (const k in params) { s = s.split('{' + k + '}').join(params[k]); } }
    return s;
}
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
const LOCALE = <?= json_encode(currentLang() === 'en' ? 'en-GB' : 'de-DE') ?>;
const SIGNATURE = <?= json_encode(mcSignatureBlock($mcS), JSON_UNESCAPED_UNICODE) ?>;
const MAX_SEND_MB = <?= (int)(MC_MAX_SEND_BYTES / 1048576) ?>;
const HAS_FROM = <?= $fromEmail !== '' ? 'true' : 'false' ?>;

const state = {
    folders: [], folder: null, page: 0, query: '', messages: [], hasMore: false, total: 0,
    selected: null, message: null, remoteAllowed: false, loadingList: 0,
    sel: new Set(), anchor: null, multi: false,
};
const $ = id => document.getElementById(id);

function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function store(key, val) {
    try { if (val === undefined) return JSON.parse(localStorage.getItem('tm_mail_' + key)); localStorage.setItem('tm_mail_' + key, JSON.stringify(val)); }
    catch (e) { return null; }
}

async function api(action, params = {}) {
    const res = await fetch('mail_api.php', {
        method: 'POST', headers: { 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action, ...params }),
    });
    let data;
    try { data = await res.json(); } catch (e) { throw new Error(t('config.serverError')); }
    if (!data.success) throw new Error(data.error || t('common.unknownError'));
    return data.data;
}

// ------------------------------------------------------------------
// Greifer zwischen den Bereichen
// ------------------------------------------------------------------
function initSplitters() {
    const folders = $('folders'), list = $('list'), right = $('right'), app = $('app');
    const saved = store('layout') || {};
    if (saved.folders) folders.style.width = saved.folders + 'px';
    if (saved.list) list.style.height = saved.list + 'px';

    const clampW = w => Math.max(120, Math.min(w, window.innerWidth * 0.5));
    const clampH = h => Math.max(60, Math.min(h, right.clientHeight - 120));
    const save = () => store('layout', { folders: folders.offsetWidth, list: list.offsetHeight });

    // onMove bekommt die Verschiebung seit dem Antippen – so springt der Bereich
    // nicht zur Fingerposition, wenn man neben der Mitte des Greifers anfasst.
    function drag(handle, onStart, onMove) {
        // Verhindert, dass beim Ziehen Text markiert wird
        handle.addEventListener('mousedown', e => e.preventDefault());
        handle.addEventListener('pointerdown', e => {
            if (e.button !== 0) return;
            e.preventDefault();
            handle.setPointerCapture(e.pointerId);
            handle.classList.add('active');
            app.classList.add('mc-dragging');
            const x0 = e.clientX, y0 = e.clientY, start = onStart();
            const move = ev => onMove(start, ev.clientX - x0, ev.clientY - y0);
            const up = () => {
                handle.removeEventListener('pointermove', move);
                handle.removeEventListener('pointerup', up);
                handle.removeEventListener('pointercancel', up);
                handle.classList.remove('active');
                app.classList.remove('mc-dragging');
                save();
            };
            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', up);
            handle.addEventListener('pointercancel', up);
        });
    }
    drag($('splitV'), () => folders.offsetWidth, (w, dx) => { folders.style.width = clampW(w + dx) + 'px'; });
    drag($('splitH'), () => list.offsetHeight, (h, dx, dy) => { list.style.height = clampH(h + dy) + 'px'; });

    // Tastatur: Pfeiltasten verschieben, Doppelklick setzt zurück
    $('splitV').addEventListener('keydown', e => {
        const d = e.key === 'ArrowLeft' ? -20 : e.key === 'ArrowRight' ? 20 : 0;
        if (d) { e.preventDefault(); folders.style.width = clampW(folders.offsetWidth + d) + 'px'; save(); }
    });
    $('splitH').addEventListener('keydown', e => {
        const d = e.key === 'ArrowUp' ? -20 : e.key === 'ArrowDown' ? 20 : 0;
        if (d) { e.preventDefault(); list.style.height = clampH(list.offsetHeight + d) + 'px'; save(); }
    });
    $('splitV').addEventListener('dblclick', () => { folders.style.width = ''; save(); });
    $('splitH').addEventListener('dblclick', () => { list.style.height = ''; save(); });
    window.addEventListener('resize', () => {
        if (window.innerWidth > 760) {
            folders.style.width = clampW(folders.offsetWidth) + 'px';
            list.style.height = clampH(list.offsetHeight) + 'px';
        }
    });
}

// ------------------------------------------------------------------
// Ordner
// ------------------------------------------------------------------
const ICONS = {
    inbox:   'M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 12h-4c0 1.66-1.35 3-3 3s-3-1.34-3-3H5V5h14v10z',
    drafts:  'M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z',
    sent:    'M2.01 21 23 12 2.01 3 2 10l15 2-15 2z',
    junk:    'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z',
    trash:   'M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z',
    archive: 'M20.54 5.23 19.15 3.55C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5 6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z',
    folder:  'M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z',
};

const ICON_RELOAD = 'M17.65 6.35A7.958 7.958 0 0 0 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08A5.99 5.99 0 0 1 12 18c-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z';

async function reloadInbox() {
    if (state.reloading) return;
    const inbox = folderByRole('inbox');
    if (!inbox) return;
    state.reloading = true;
    renderFolders();
    try {
        if (state.folder !== inbox.name) {
            openFolder(inbox.name);
            await loadFolders();
        } else {
            await loadFolders();
            await loadList(0, true);
        }
    } finally {
        state.reloading = false;
        renderFolders();
    }
}

function folderLabel(f) {
    return f.role ? t('mail.role.' + f.role) : f.label;
}

async function loadFolders() {
    try {
        const data = await api('folders');
        state.folders = data.folders;
        renderFolders();
    } catch (e) {
        $('folders').innerHTML = '<div class="mc-error">' + esc(e.message) + '</div>';
    }
}

function renderFolders() {
    $('folders').innerHTML = state.folders.map(f =>
        '<div class="mc-folder' + (f.name === state.folder ? ' active' : '') + (f.selectable ? '' : ' disabled') + '"' +
        ' data-name="' + esc(f.name) + '" style="padding-left:' + (10 + f.level * 14) + 'px"' +
        (f.selectable ? ' tabindex="0" role="button"' : '') + ' title="' + esc(f.display) + '">' +
        '<svg viewBox="0 0 24 24" width="16" height="16"><path d="' + ICONS[f.role || 'folder'] + '"/></svg>' +
        '<span class="mc-fname">' + esc(folderLabel(f)) + '</span>' +
        (f.unseen ? '<span class="mc-badge">' + f.unseen + '</span>' : '') +
        (f.role === 'inbox' ? '<button type="button" class="mc-reload' + (state.reloading ? ' spin' : '') + '" id="reloadInbox"' +
            ' title="' + esc(t('mail.reloadInbox')) + '" aria-label="' + esc(t('mail.reloadInbox')) + '">' +
            '<svg viewBox="0 0 24 24" width="15" height="15"><path d="' + ICON_RELOAD + '"/></svg></button>' : '') +
        '</div>'
    ).join('');
    $('folders').querySelectorAll('.mc-folder:not(.disabled)').forEach(el => {
        el.addEventListener('click', () => openFolder(el.dataset.name));
        el.addEventListener('keydown', e => { if (e.key === 'Enter') openFolder(el.dataset.name); });
        el.addEventListener('dragover', e => { if (el.dataset.name !== state.folder) { e.preventDefault(); el.classList.add('drop'); } });
        el.addEventListener('dragleave', () => el.classList.remove('drop'));
        el.addEventListener('drop', e => {
            e.preventDefault();
            el.classList.remove('drop');
            const uids = String(e.dataTransfer.getData('text/x-tm-uid') || '').split(',')
                .map(x => parseInt(x, 10)).filter(x => x > 0);
            if (uids.length) moveMessages(uids, el.dataset.name);
        });
    });
    $('reloadInbox')?.addEventListener('click', e => { e.stopPropagation(); reloadInbox(); });
    $('reloadInbox')?.addEventListener('keydown', e => e.stopPropagation());
    const current = state.folders.find(f => f.name === state.folder);
    document.title = (current ? folderLabel(current) + ' – ' : '') + t('mail.title');
}

function folderByRole(role) { return state.folders.find(f => f.role === role); }

function openFolder(name) {
    state.folder = name;
    state.query = '';
    $('searchInput').value = '';
    store('folder', name);
    clearSelection();
    renderFolders();
    showView(null);
    loadList(0);
    setPane('list');
}

// ------------------------------------------------------------------
// Liste
// ------------------------------------------------------------------
function fmtDate(iso, long) {
    if (!iso) return '';
    const d = new Date(iso), now = new Date();
    if (long) return d.toLocaleString(LOCALE, { dateStyle: 'full', timeStyle: 'short' });
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
    if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString(LOCALE, { day: '2-digit', month: '2-digit' });
    return d.toLocaleDateString(LOCALE, { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function fmtAddr(list, full) {
    return (list || []).map(a => full
        ? (a.name ? a.name + ' <' + a.email + '>' : a.email)
        : (a.name || a.email)).join(', ');
}

function fmtSize(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return Math.round(b / 1024) + ' KB';
    return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
}

function isDraftFolder() {
    const f = state.folders.find(x => x.name === state.folder);
    return !!f && f.role === 'drafts';
}

function showsRecipients() {
    const f = state.folders.find(x => x.name === state.folder);
    return f && (f.role === 'sent' || f.role === 'drafts');
}

async function loadList(page, keepSelection) {
    const token = ++state.loadingList;
    const list = $('list');
    if (page === 0 && !keepSelection) {
        list.innerHTML = '<div class="mc-info">' + esc(t('mail.loading')) + '</div>';
    }
    try {
        const data = await api('list', { folder: state.folder, page, q: state.query });
        if (token !== state.loadingList) return;
        state.page = page;
        state.total = data.total;
        state.hasMore = data.has_more;
        state.messages = page === 0 ? data.messages : state.messages.concat(data.messages);
        if (!keepSelection && page === 0) {
            clearSelection();
        } else {
            const present = new Set(state.messages.map(m => m.uid));
            state.sel = new Set([...state.sel].filter(u => present.has(u)));
            if (state.selected && !present.has(state.selected)) state.selected = null;
            if (!state.sel.size) state.multi = false;
        }
        renderList();
    } catch (e) {
        if (token !== state.loadingList) return;
        list.innerHTML = '<div class="mc-error">' + esc(e.message) + '</div>';
    }
}

function isMobile() { return window.innerWidth <= 760; }

function fmtListDate(iso) {
    if (!iso) return '';
    if (isMobile()) return fmtDate(iso);
    return new Date(iso).toLocaleString(LOCALE, {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).replace(',', '');
}

function moveOptionsHtml(exclude) {
    return state.folders
        .filter(f => f.selectable && f.name !== exclude)
        .map(f => '<option value="' + esc(f.name) + '">' + esc(' '.repeat(f.level * 3) + folderLabel(f)) + '</option>')
        .join('');
}

function renderListHead() {
    const head = $('listHead');
    if (!head) return;
    const n = state.sel.size;
    head.innerHTML =
        (n > 1 || (state.multi && n > 0)
            ? '<div class="mc-bulk">' +
                '<b>' + esc(t('mail.selectedCount', { n })) + '</b>' +
                '<button type="button" class="btn" data-bulk="read">' + esc(t('mail.markRead')) + '</button>' +
                '<select data-bulk="move"><option value="">' + esc(t('mail.moveTo')) + '</option>' + moveOptionsHtml(state.folder) + '</select>' +
                '<button type="button" class="btn btn--danger" data-bulk="delete">' + esc(t('common.delete')) + '</button>' +
                '<button type="button" class="btn" data-bulk="clear">' + esc(t('mail.clearSelection')) + '</button>' +
              '</div>'
            : '') +
        '<div class="mc-lhead mc-cols">' +
            '<div class="mc-l-seen" title="' + esc(t('mail.colSeen')) + '"><span></span></div>' +
            '<div class="mc-l-ans" title="' + esc(t('mail.colAnswered')) + '">&#8617;</div>' +
            '<div><span class="mc-lh-t">' + esc(t('mail.subject')) + '</span><span class="mc-colgrip" data-grip="1" title="' + esc(t('mail.dragToResize')) + '"></span></div>' +
            '<div><span class="mc-lh-t">' + esc(t(showsRecipients() ? 'mail.to' : 'mail.from')) + '</span><span class="mc-colgrip" data-grip="2" title="' + esc(t('mail.dragToResize')) + '"></span></div>' +
            '<div><span class="mc-lh-t">' + esc(t('mail.date')) + '</span></div>' +
        '</div>';
}

function rowHtml(m, recipients) {
    const who = recipients ? m.to : m.from;
    return '<div class="mc-row mc-cols' + (m.seen ? '' : ' unseen') + (state.sel.has(m.uid) ? ' checked' : '') +
        (m.uid === state.selected ? ' current' : '') + '" data-uid="' + m.uid + '" draggable="true">' +
        '<div class="mc-l-seen" title="' + esc(t(m.seen ? 'mail.markUnread' : 'mail.markRead')) + '"><span></span></div>' +
        '<div class="mc-l-ans"' + (m.answered || m.forwarded
            ? ' title="' + esc([m.answered ? t('mail.answered') : '', m.forwarded ? t('mail.forwarded') : ''].filter(Boolean).join(', ')) + '">' +
              (m.answered ? '&#8617;' : '') + (m.forwarded ? '&#8618;' : '')
            : '>') + '</div>' +
        '<div class="mc-l-subj" title="' + esc(m.subject || t('mail.noSubject')) + '">' +
            (m.flagged ? '<span class="mc-ic mc-flag" title="' + esc(t('mail.flagged')) + '">&#9733;</span>' : '') +
            (m.attachment ? '<span class="mc-ic" title="' + esc(t('mail.attachments')) + '">&#128206;</span>' : '') +
            esc(m.subject || t('mail.noSubject')) +
        '</div>' +
        '<div class="mc-l-from" title="' + esc(fmtAddr(who, true)) + '">' + esc(fmtAddr(who)) + '</div>' +
        '<div class="mc-l-date" title="' + esc(fmtDate(m.date, true)) + '">' + esc(fmtListDate(m.date)) + '</div>' +
        '</div>';
}

function renderList() {
    const list = $('list');
    if (!state.messages.length) {
        list.innerHTML = '<div class="mc-info">' + esc(t(state.query ? 'mail.noResults' : 'mail.empty')) + '</div>';
        return;
    }
    const recipients = showsRecipients();
    list.innerHTML = '<div class="mc-sticky" id="listHead"></div>' +
        state.messages.map(m => rowHtml(m, recipients)).join('') +
        (state.hasMore ? '<button type="button" class="btn mc-more" id="moreBtn">' + esc(t('mail.loadMore')) + '</button>' : '');
    renderListHead();
}

/** Aktualisiert nur die Markierungen, ohne die Liste neu aufzubauen. */
function syncSelection() {
    document.querySelectorAll('#list .mc-row').forEach(row => {
        const uid = parseInt(row.dataset.uid, 10);
        row.classList.toggle('checked', state.sel.has(uid));
        row.classList.toggle('current', uid === state.selected);
    });
    renderListHead();
}

function clearSelection() {
    state.sel = new Set();
    state.anchor = null;
    state.multi = false;
    state.selected = null;
}

/** Nach Strg-/Umschalt-Klick: Ansicht passend zur Auswahl. */
function selectionChanged() {
    const n = state.sel.size;
    if (!n) state.multi = false;
    if (n === 1 && !state.multi) {
        openMessage([...state.sel][0]);
        return;
    }
    state.selected = null;
    syncSelection();
    if (n > 1) {
        state.message = null;
        $('view').innerHTML = '<div class="mc-info" style="margin:auto">' + esc(t('mail.selectedCount', { n })) + '</div>';
    } else if (!n) {
        showView(null);
    }
}

function toggleSelect(uid) {
    if (state.sel.has(uid)) state.sel.delete(uid); else state.sel.add(uid);
    state.anchor = uid;
    state.multi = true;
    selectionChanged();
}

function selectRange(toUid, additive) {
    const ids = state.messages.map(m => m.uid);
    let a = ids.indexOf(state.anchor), b = ids.indexOf(toUid);
    if (a < 0) a = b;
    if (a > b) [a, b] = [b, a];
    if (!additive) state.sel = new Set();
    ids.slice(a, b + 1).forEach(u => state.sel.add(u));
    state.multi = state.sel.size > 1;
    selectionChanged();
}

function selectRelative(step) {
    const from = state.selected ?? state.anchor;
    const idx = state.messages.findIndex(m => m.uid === from);
    const next = idx < 0 ? state.messages[0] : state.messages[idx + step];
    if (next) {
        openMessage(next.uid);
        document.querySelector('.mc-row[data-uid="' + next.uid + '"]')?.scrollIntoView({ block: 'nearest' });
    }
}

function initList() {
    const list = $('list');
    let press = null, pressed = false;

    list.addEventListener('click', e => {
        const bulk = e.target.closest('[data-bulk]');
        if (bulk) {
            if (bulk.tagName === 'SELECT') return;
            const uids = [...state.sel];
            if (bulk.dataset.bulk === 'read') setFlag(uids, 'seen', true);
            else if (bulk.dataset.bulk === 'delete') deleteMessages(uids);
            else if (bulk.dataset.bulk === 'clear') { clearSelection(); syncSelection(); showView(null); }
            return;
        }
        if (e.target.closest('#moreBtn')) { loadList(state.page + 1); return; }
        const row = e.target.closest('.mc-row');
        if (!row) return;
        const uid = parseInt(row.dataset.uid, 10);
        if (pressed) { pressed = false; return; }
        if (e.target.closest('.mc-l-seen')) {
            const m = state.messages.find(x => x.uid === uid);
            if (m) setFlag([uid], 'seen', !m.seen);
            return;
        }
        if (e.shiftKey) { selectRange(uid, e.ctrlKey || e.metaKey); return; }
        if (e.ctrlKey || e.metaKey || (state.multi && isMobile())) { toggleSelect(uid); return; }
        openMessage(uid);
    });
    list.addEventListener('change', e => {
        if (e.target.dataset.bulk === 'move' && e.target.value) moveMessages([...state.sel], e.target.value);
    });
    list.addEventListener('dblclick', e => {
        if (e.target.closest('.mc-colgrip')) {
            list.style.removeProperty('--c-from');
            list.style.removeProperty('--c-date');
            store('cols', null);
            return;
        }
        const row = e.target.closest('.mc-row');
        if (row && isDraftFolder()) composeFrom({ folder: state.folder, uid: parseInt(row.dataset.uid, 10) }, 'edit');
    });
    list.addEventListener('dragstart', e => {
        const row = e.target.closest('.mc-row');
        if (!row) return;
        const uid = parseInt(row.dataset.uid, 10);
        const uids = state.sel.has(uid) ? [...state.sel] : [uid];
        e.dataTransfer.setData('text/x-tm-uid', uids.join(','));
        e.dataTransfer.effectAllowed = 'move';
    });

    // Mobil: lange drücken startet die Mehrfachauswahl
    list.addEventListener('pointerdown', e => {
        const row = e.target.closest('.mc-row');
        if (!row || e.pointerType !== 'touch') return;
        pressed = false;
        press = setTimeout(() => {
            press = null;
            pressed = true;
            if (navigator.vibrate) navigator.vibrate(20);
            toggleSelect(parseInt(row.dataset.uid, 10));
        }, 500);
    });
    ['pointerup', 'pointercancel', 'pointermove'].forEach(ev => list.addEventListener(ev, e => {
        if (press && (ev !== 'pointermove' || Math.abs(e.movementX) + Math.abs(e.movementY) > 4)) {
            clearTimeout(press);
            press = null;
        }
    }));
    list.addEventListener('contextmenu', e => { if (pressed) e.preventDefault(); });

    // Spaltenbreiten (Greifer im Spaltenkopf)
    const saved = store('cols');
    if (saved && saved.from) list.style.setProperty('--c-from', saved.from + 'px');
    if (saved && saved.date) list.style.setProperty('--c-date', saved.date + 'px');
    const widths = () => {
        const cs = getComputedStyle(list);
        return { from: parseFloat(cs.getPropertyValue('--c-from')) || 200, date: parseFloat(cs.getPropertyValue('--c-date')) || 132 };
    };
    list.addEventListener('mousedown', e => { if (e.target.closest('.mc-colgrip')) e.preventDefault(); });
    list.addEventListener('pointerdown', e => {
        const grip = e.target.closest('.mc-colgrip');
        if (!grip || e.button !== 0) return;
        e.preventDefault();
        grip.setPointerCapture(e.pointerId);
        grip.classList.add('active');
        const start = widths(), x0 = e.clientX;
        const room = list.clientWidth - 48 - 120;   // Betreff behält mindestens 120 px
        const MIN = 70;
        const move = ev => {
            const dx = ev.clientX - x0;
            let from, date = start.date;
            if (grip.dataset.grip === '1') {
                // Grenze Betreff | Von: nach rechts wird „Von“ schmaler
                from = Math.max(MIN, Math.min(start.from - dx, room - date));
            } else {
                // Grenze Von | Datum: „Von“ wächst, „Datum“ schrumpft
                from = Math.max(MIN, Math.min(start.from + dx, start.from + start.date - MIN));
                date = start.from + start.date - from;
            }
            list.style.setProperty('--c-from', Math.round(from) + 'px');
            list.style.setProperty('--c-date', Math.round(date) + 'px');
        };
        const up = () => {
            grip.removeEventListener('pointermove', move);
            grip.removeEventListener('pointerup', up);
            grip.removeEventListener('pointercancel', up);
            grip.classList.remove('active');
            store('cols', widths());
        };
        grip.addEventListener('pointermove', move);
        grip.addEventListener('pointerup', up);
        grip.addEventListener('pointercancel', up);
    });
}

// ------------------------------------------------------------------
// Ansicht
// ------------------------------------------------------------------
function frameDoc(html, allowRemote) {
    const remote = allowRemote ? ' https: http:' : '';
    const csp = "default-src 'none'; style-src 'unsafe-inline'" + remote + '; img-src data:' + remote +
        '; font-src data:' + remote + "; form-action 'none'";
    return '<!DOCTYPE html><html><head><meta charset="utf-8">' +
        '<meta http-equiv="Content-Security-Policy" content="' + csp + '">' +
        '<meta name="referrer" content="no-referrer"><base target="_blank">' +
        '<style>body{margin:12px 14px;font:14px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#222;background:#fff;word-wrap:break-word}' +
        'img{max-width:100%;height:auto}pre{white-space:pre-wrap}blockquote{margin-left:0;padding-left:10px;border-left:3px solid #ccc;color:#555}</style>' +
        '</head><body>' + html + '</body></html>';
}

function linkify(text) {
    return esc(text).replace(/\b(https?:\/\/[^\s<>"']+[^\s<>"'.,;:!?)\]])/g,
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
}

function showView(msg) {
    state.message = msg;
    const view = $('view');
    if (!msg) {
        view.innerHTML = '<div class="mc-info" style="margin:auto">' + esc(t('mail.noSelection')) + '</div>';
        return;
    }
    const moveOptions = moveOptionsHtml(msg.folder);
    const attUrl = a => 'mail_attachment.php?folder=' + encodeURIComponent(msg.folder) +
        '&uid=' + msg.uid + '&part=' + encodeURIComponent(a.id);

    view.innerHTML =
        '<div class="mc-head">' +
            '<h2>' + esc(msg.subject || t('mail.noSubject')) + '</h2>' +
            '<div class="mc-meta">' +
                '<b>' + esc(t('mail.from')) + '</b><span>' + esc(fmtAddr(msg.from, true)) + '</span>' +
                '<b>' + esc(t('mail.to')) + '</b><span>' + esc(fmtAddr(msg.to, true)) + '</span>' +
                (msg.cc.length ? '<b>' + esc(t('mail.cc')) + '</b><span>' + esc(fmtAddr(msg.cc, true)) + '</span>' : '') +
                '<b>' + esc(t('mail.date')) + '</b><span>' + esc(fmtDate(msg.date, true)) + '</span>' +
            '</div>' +
            '<div class="mc-actions">' +
                (isDraftFolder()
                    ? '<button type="button" class="btn btn--primary" id="actEdit">' + esc(t('mail.editDraft')) + '</button>'
                    : '<button type="button" class="btn" id="actReply">&#8617; ' + esc(t('mail.reply')) + '</button>' +
                      '<button type="button" class="btn" id="actReplyAll">' + esc(t('mail.replyAll')) + '</button>' +
                      '<button type="button" class="btn" id="actForward">&#8618; ' + esc(t('mail.forward')) + '</button>') +
                '<span class="mc-sep"></span>' +
                '<button type="button" class="btn" id="actUnread">' + esc(t('mail.markUnread')) + '</button>' +
                '<button type="button" class="btn" id="actFlag">' + esc(t(msg.flagged ? 'mail.unflag' : 'mail.flag')) + '</button>' +
                '<select id="actMove"><option value="">' + esc(t('mail.moveTo')) + '</option>' + moveOptions + '</select>' +
                '<button type="button" class="btn btn--danger" id="actDelete">' + esc(t('common.delete')) + '</button>' +
            '</div>' +
            (msg.attachments.length ? '<div class="mc-atts">' + msg.attachments.map(a =>
                '<a class="mc-att" href="' + esc(attUrl(a)) + '" download title="' + esc(a.filename) + '">' +
                '&#128206; <span>' + esc(a.filename) + '</span> <small>' + esc(fmtSize(a.size)) + '</small></a>'
            ).join('') + '</div>' : '') +
        '</div>' +
        (msg.html !== null && msg.remote_content && !state.remoteAllowed
            ? '<div class="mc-remote">' + esc(t('mail.remoteBlocked')) +
              ' <button type="button" class="btn" id="actRemote" style="font-size:12px;padding:2px 8px">' + esc(t('mail.remoteShow')) + '</button></div>'
            : '') +
        '<div class="mc-body" id="mcBody"></div>';

    const body = $('mcBody');
    if (msg.html !== null) {
        const frame = document.createElement('iframe');
        // Ohne allow-scripts und allow-same-origin: Mail-HTML kann weder Skripte ausführen noch auf die App zugreifen.
        frame.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.title = msg.subject || '';
        frame.srcdoc = frameDoc(msg.html, state.remoteAllowed);
        body.appendChild(frame);
    } else {
        body.innerHTML = '<div class="mc-text">' + linkify(msg.text || '') + '</div>';
    }

    $('actReply')?.addEventListener('click', () => composeFrom(msg, 'reply'));
    $('actReplyAll')?.addEventListener('click', () => composeFrom(msg, 'replyall'));
    $('actForward')?.addEventListener('click', () => composeFrom(msg, 'forward'));
    $('actEdit')?.addEventListener('click', () => composeFrom(msg, 'edit'));
    $('actUnread').addEventListener('click', () => setFlag([msg.uid], 'seen', false));
    $('actFlag').addEventListener('click', () => setFlag([msg.uid], 'flagged', !msg.flagged));
    $('actDelete').addEventListener('click', () => deleteMessages([msg.uid]));
    $('actMove').addEventListener('change', e => { if (e.target.value) moveMessages([msg.uid], e.target.value); });
    $('actRemote')?.addEventListener('click', () => { state.remoteAllowed = true; showView(msg); });
}

async function openMessage(uid) {
    state.selected = uid;
    state.sel = new Set([uid]);
    state.anchor = uid;
    state.multi = false;
    state.remoteAllowed = false;
    syncSelection();
    setPane('view');
    $('view').innerHTML = '<div class="mc-info" style="margin:auto">' + esc(t('mail.loading')) + '</div>';
    try {
        const msg = await api('message', { folder: state.folder, uid });
        if (state.selected !== uid) return;
        const item = state.messages.find(m => m.uid === uid);
        if (item && !item.seen) {
            item.seen = true;
            const row = document.querySelector('.mc-row[data-uid="' + uid + '"]');
            row?.classList.remove('unseen');
            row?.querySelector('.mc-l-seen')?.setAttribute('title', t('mail.markUnread'));
            adjustUnseen(state.folder, -1);
        }
        showView(msg);
    } catch (e) {
        if (state.selected === uid) $('view').innerHTML = '<div class="mc-error">' + esc(e.message) + '</div>';
    }
}

function adjustUnseen(folder, delta) {
    const f = state.folders.find(x => x.name === folder);
    if (f && f.unseen !== null && delta) { f.unseen = Math.max(0, f.unseen + delta); renderFolders(); }
}

async function setFlag(uids, flag, on) {
    if (!uids.length) return;
    try {
        await api('flag', { folder: state.folder, uids: uids.join(','), flag, on: on ? '1' : '0' });
        let delta = 0;
        uids.forEach(uid => {
            const item = state.messages.find(m => m.uid === uid);
            if (!item) return;
            if (flag === 'seen') {
                if (item.seen !== on) delta += on ? -1 : 1;
                item.seen = on;
            } else {
                item.flagged = on;
            }
        });
        adjustUnseen(state.folder, delta);
        if (flag === 'flagged' && state.message && uids.includes(state.message.uid)) {
            state.message.flagged = on;
            showView(state.message);
        }
        renderList();
    } catch (e) {
        Dialog.alert(e.message);
    }
}

function removeFromList(uids) {
    const gone = new Set(uids);
    const firstIdx = state.messages.findIndex(m => gone.has(m.uid));
    if (firstIdx < 0) return;
    const removed = state.messages.filter(m => gone.has(m.uid));
    state.messages = state.messages.filter(m => !gone.has(m.uid));
    adjustUnseen(state.folder, -removed.filter(m => !m.seen).length);
    state.total -= removed.length;
    const wasCurrent = state.selected !== null && gone.has(state.selected);
    uids.forEach(u => state.sel.delete(u));
    if (wasCurrent && removed.length === 1) {
        // Wie in Mail-Programmen: die nächste Nachricht öffnen
        const next = state.messages[firstIdx] || state.messages[firstIdx - 1];
        clearSelection();
        renderList();
        if (next && !isMobile()) openMessage(next.uid); else { showView(null); setPane('list'); }
        return;
    }
    if (removed.length > 1 || wasCurrent) {
        clearSelection();
        showView(null);
        setPane('list');
    }
    renderList();
}

async function moveMessages(uids, target) {
    if (!uids.length) return;
    try {
        await api('move', { folder: state.folder, uids: uids.join(','), target });
        adjustUnseen(target, state.messages.filter(m => uids.includes(m.uid) && !m.seen).length);
        removeFromList(uids);
        if (uids.length > 1) toast(t('mail.movedCount', { n: uids.length }));
    } catch (e) {
        Dialog.alert(e.message);
        renderListHead();
    }
}

async function deleteMessages(uids) {
    if (!uids.length) return;
    const trash = folderByRole('trash');
    const permanent = !trash || trash.name === state.folder;
    const question = uids.length > 1 ? t('mail.confirmDeletePermanentN', { n: uids.length }) : t('mail.confirmDeletePermanent');
    if (permanent && !await Dialog.confirm(question, { danger: true })) return;
    try {
        await api('delete', { folder: state.folder, uids: uids.join(',') });
        removeFromList(uids);
        if (!permanent && trash) { trash.messages = (trash.messages || 0) + uids.length; }
        if (uids.length > 1) toast(t('mail.deletedCount', { n: uids.length }));
    } catch (e) {
        Dialog.alert(e.message);
    }
}

// ------------------------------------------------------------------
// Schreiben, Antworten, Weiterleiten, Entwürfe
// ------------------------------------------------------------------
const compose = { open: false, busy: false, dirty: false, data: null, keep: [], files: [] };

function toast(text, ms = 3500) {
    const el = $('toast');
    el.textContent = text;
    el.classList.add('show');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => el.classList.remove('show'), ms);
}

function composeTitle(mode) {
    return t({ reply: 'mail.reply', replyall: 'mail.replyAll', forward: 'mail.forward', edit: 'mail.editDraft' }[mode] || 'mail.compose');
}

function openCompose(data) {
    if (!HAS_FROM) { Dialog.alert(t('mail.noFrom')); return; }
    compose.data = Object.assign({
        mode: 'new', to: '', cc: '', bcc: '', subject: '', body: '\n' + SIGNATURE,
        in_reply_to: '', references: '', draft_id: '', orig_folder: '', orig_uid: 0, attachments: [],
    }, data || {});
    compose.keep = compose.data.attachments.slice();
    compose.files = [];
    compose.dirty = false;
    compose.open = true;
    const d = compose.data;
    $('cTitle').textContent = d.subject ? composeTitle(d.mode) + ': ' + d.subject : composeTitle(d.mode);
    $('cTo').value = d.to;
    $('cCc').value = d.cc;
    $('cBcc').value = d.bcc;
    $('cSubject').value = d.subject;
    $('cBody').value = d.body;
    const showCc = !!(d.cc || d.bcc);
    $('cCcRow').hidden = !showCc;
    $('cBccRow').hidden = !showCc;
    $('cCcToggle').hidden = showCc;
    setComposeStatus('');
    renderComposeAttachments();
    $('compose').hidden = false;
    if (!d.to && d.mode !== 'edit') {
        $('cTo').focus();
    } else {
        const body = $('cBody');
        body.focus();
        body.setSelectionRange(0, 0);
        body.scrollTop = 0;
    }
}

async function composeFrom(msg, mode) {
    if (compose.open && !await closeCompose()) return;
    try {
        const data = await api('compose_source', { folder: msg.folder, uid: msg.uid, mode });
        openCompose(data);
    } catch (e) {
        Dialog.alert(e.message);
    }
}

async function closeCompose(force) {
    if (!compose.open) return true;
    if (!force && compose.dirty && !await Dialog.confirm(t('mail.confirmDiscard'), { danger: true })) return false;
    compose.open = false;
    compose.files = [];
    $('compose').hidden = true;
    return true;
}

function setComposeStatus(text, isError) {
    const el = $('cStatus');
    el.textContent = text;
    el.classList.toggle('err', !!isError);
}

function composeSize() {
    return compose.keep.reduce((n, a) => n + (a.size || 0), 0) + compose.files.reduce((n, f) => n + f.size, 0);
}

function renderComposeAttachments() {
    const items = compose.keep.map((a, i) => ({ name: a.filename, size: a.size, kind: 'keep', i }))
        .concat(compose.files.map((f, i) => ({ name: f.name, size: f.size, kind: 'file', i })));
    $('cAtts').innerHTML = items.map(a =>
        '<span class="mc-c-att" title="' + esc(a.name) + '"><span>' + esc(a.name) + '</span><small>' + esc(fmtSize(a.size || 0)) + '</small>' +
        '<button type="button" data-kind="' + a.kind + '" data-i="' + a.i + '" title="' + esc(t('mail.removeAttachment')) + '">&times;</button></span>'
    ).join('');
    $('cAtts').querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
        (b.dataset.kind === 'keep' ? compose.keep : compose.files).splice(parseInt(b.dataset.i, 10), 1);
        compose.dirty = true;
        renderComposeAttachments();
    }));
}

function addComposeFiles(list) {
    const files = Array.from(list || []);
    if (!files.length) return;
    const total = composeSize() + files.reduce((n, f) => n + f.size, 0);
    if (total > MAX_SEND_MB * 1048576) { Dialog.alert(t('mail.tooLarge', { max: MAX_SEND_MB })); return; }
    compose.files.push(...files);
    compose.dirty = true;
    renderComposeAttachments();
}

function composeForm(action) {
    const d = compose.data;
    const fd = new FormData();
    fd.append('action', action);
    fd.append('to', $('cTo').value);
    fd.append('cc', $('cCc').value);
    fd.append('bcc', $('cBcc').value);
    fd.append('subject', $('cSubject').value);
    fd.append('body', $('cBody').value);
    fd.append('in_reply_to', d.in_reply_to || '');
    fd.append('references', d.references || '');
    fd.append('draft_id', d.draft_id || '');
    fd.append('mode', d.mode);
    fd.append('orig_folder', d.orig_folder || '');
    fd.append('orig_uid', String(d.orig_uid || 0));
    fd.append('keep', JSON.stringify(compose.keep.map(a => ({ folder: a.folder, uid: a.uid, part: a.part }))));
    compose.files.forEach(f => fd.append('files[]', f, f.name));
    return fd;
}

async function postForm(fd) {
    const res = await fetch('mail_api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd });
    let data;
    try { data = await res.json(); } catch (e) { throw new Error(t('config.serverError')); }
    if (!data.success) throw new Error(data.error || t('common.unknownError'));
    return data.data;
}

function setComposeBusy(busy) {
    compose.busy = busy;
    ['cSend', 'cDraft', 'cDiscard'].forEach(id => { $(id).disabled = busy; });
}

async function sendCompose() {
    if (compose.busy) return;
    if (!$('cTo').value.trim() && !$('cCc').value.trim() && !$('cBcc').value.trim()) {
        await Dialog.alert(t('mail.noRecipients'));
        $('cTo').focus();
        return;
    }
    if (!$('cSubject').value.trim() && !await Dialog.confirm(t('mail.confirmNoSubject'))) return;
    setComposeBusy(true);
    setComposeStatus(t('mail.sending'));
    try {
        const d = compose.data;
        const res = await postForm(composeForm('send'));
        setComposeBusy(false);
        await closeCompose(true);
        toast(res.warnings && res.warnings.length
            ? t('mail.sentWithWarnings', { warnings: res.warnings.join(' ') }) : t('mail.sent'), res.warnings && res.warnings.length ? 8000 : 3500);
        if (d.orig_folder === state.folder && d.orig_uid) {
            const item = state.messages.find(m => m.uid === d.orig_uid);
            if (item) { item[d.mode === 'forward' ? 'forwarded' : 'answered'] = true; renderList(); }
        }
        afterComposeChange();
    } catch (e) {
        setComposeBusy(false);
        setComposeStatus(e.message, true);
    }
}

async function saveDraft() {
    if (compose.busy) return;
    setComposeBusy(true);
    setComposeStatus(t('mail.loading'));
    try {
        const res = await postForm(composeForm('save_draft'));
        compose.data.draft_id = res.draft_id;
        if (res.uid) {
            // Hochgeladene Dateien liegen jetzt im Entwurf; künftig von dort übernehmen
            compose.keep = res.attachments;
            compose.files = [];
            renderComposeAttachments();
        }
        compose.dirty = false;
        setComposeStatus(t('mail.draftSaved', { time: new Date().toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }) }));
        afterComposeChange();
    } catch (e) {
        setComposeStatus(e.message, true);
    } finally {
        setComposeBusy(false);
    }
}

function afterComposeChange() {
    loadFolders();
    const f = state.folders.find(x => x.name === state.folder);
    if (f && (f.role === 'sent' || f.role === 'drafts')) {
        if (f.role === 'drafts' && state.selected) { clearSelection(); showView(null); }
        loadList(0, true);
    }
}

$('composeBtn').addEventListener('click', async () => { if (!compose.open || await closeCompose()) openCompose(); });
$('composeForm').addEventListener('submit', e => { e.preventDefault(); sendCompose(); });
$('composeForm').addEventListener('input', () => { compose.dirty = true; });
$('composeForm').addEventListener('keydown', e => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); sendCompose(); }
    else if (e.key === 'Escape' && !compose.busy) { e.preventDefault(); closeCompose(); }
    else if (e.key === 's' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); saveDraft(); }
});
$('cDraft').addEventListener('click', saveDraft);
$('cClose').addEventListener('click', () => { if (!compose.busy) closeCompose(); });
$('cDiscard').addEventListener('click', () => closeCompose());
$('cCcToggle').addEventListener('click', () => {
    $('cCcRow').hidden = false; $('cBccRow').hidden = false; $('cCcToggle').hidden = true; $('cCc').focus();
});
$('cAttach').addEventListener('click', () => $('cFile').click());
$('cFile').addEventListener('change', e => { addComposeFiles(e.target.files); e.target.value = ''; });
(function () {
    const form = $('composeForm');
    let depth = 0;
    const hasFiles = e => Array.from(e.dataTransfer?.types || []).includes('Files');
    form.addEventListener('dragenter', e => { if (hasFiles(e)) { depth++; form.classList.add('drop'); } });
    form.addEventListener('dragleave', e => { if (hasFiles(e) && --depth <= 0) { depth = 0; form.classList.remove('drop'); } });
    form.addEventListener('dragover', e => { if (hasFiles(e)) e.preventDefault(); });
    form.addEventListener('drop', e => {
        if (!hasFiles(e)) return;
        e.preventDefault();
        depth = 0;
        form.classList.remove('drop');
        addComposeFiles(e.dataTransfer.files);
    });
})();
window.addEventListener('beforeunload', e => { if (compose.open && compose.dirty) { e.preventDefault(); e.returnValue = ''; } });

// ------------------------------------------------------------------
// Mobil: ein Bereich zur Zeit
// ------------------------------------------------------------------
function setPane(pane) { $('app').dataset.pane = pane; }
$('backBtn').addEventListener('click', () => {
    const pane = $('app').dataset.pane;
    setPane(pane === 'view' ? 'list' : 'folders');
});

// ------------------------------------------------------------------
// Suche, Aktualisieren, Tastatur
// ------------------------------------------------------------------
$('searchForm').addEventListener('submit', e => {
    e.preventDefault();
    state.query = $('searchInput').value.trim();
    clearSelection();
    showView(null);
    loadList(0);
});
$('searchInput').addEventListener('search', () => {
    if ($('searchInput').value === '' && state.query !== '') { state.query = ''; loadList(0); }
});

async function refresh() {
    await loadFolders();
    if (state.page === 0) loadList(0, true);
}
$('refreshBtn').addEventListener('click', refresh);
setInterval(() => { if (!document.hidden) refresh(); }, 120000);

$('list').addEventListener('keydown', e => {
    if (e.key === 'ArrowDown' || e.key === 'j') { e.preventDefault(); selectRelative(1); }
    else if (e.key === 'ArrowUp' || e.key === 'k') { e.preventDefault(); selectRelative(-1); }
    else if (e.key === 'Delete' && state.sel.size) { e.preventDefault(); deleteMessages([...state.sel]); }
    else if (e.key === 'a' && (e.ctrlKey || e.metaKey) && state.messages.length) {
        e.preventDefault();
        state.sel = new Set(state.messages.map(m => m.uid));
        state.multi = true;
        selectionChanged();
    } else if (e.key === 'Escape' && state.multi) {
        e.preventDefault();
        clearSelection();
        selectionChanged();
    }
});

// ------------------------------------------------------------------
// Start
// ------------------------------------------------------------------
(async function init() {
    initSplitters();
    initList();
    showView(null);
    $('list').innerHTML = '<div class="mc-info">' + esc(t('mail.loading')) + '</div>';
    await loadFolders();
    if (!state.folders.length) return;
    const last = store('folder');
    const start = state.folders.find(f => f.name === last && f.selectable)
        || state.folders.find(f => f.role === 'inbox')
        || state.folders.find(f => f.selectable);
    if (start) openFolder(start.name);
})();
</script>
<?php endif; ?>
</body>
</html>
