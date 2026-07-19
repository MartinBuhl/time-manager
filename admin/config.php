<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/activities.php';

// Load customers for shortcuts form
$cfgCustomers = db()->query('SELECT id, name FROM tm_customers ORDER BY name')->fetchAll();

// Load shortcuts (table may not exist yet)
try {
    $cfgShortcuts = db()->query(
        "SELECT s.id, s.customer_id, s.activity, s.shortcut_text,
                COALESCE(c.name, '') AS customer_name
         FROM tm_shortcuts s
         LEFT JOIN tm_customers c ON c.id = s.customer_id
         ORDER BY c.name, s.activity, s.id"
    )->fetchAll();
} catch (Throwable $e) {
    $cfgShortcuts = null;
}

// Load all config rows grouped
$stmt  = db()->query(
    'SELECT configuration_key, configuration_value, configuration_group_id
     FROM tm_configuration ORDER BY configuration_group_id, sort_order'
);
$rows  = $stmt->fetchAll();
$cfgMap = [];
foreach ($rows as $row) {
    $cfgMap[$row['configuration_key']] = $row['configuration_value'];
}

function cfgVal(array $map, string $key, string $default = ''): string
{
    return $map[$key] ?? $default;
}

// Demo-Daten-Status
require_once dirname(__DIR__) . '/includes/demo_data.php';
$demoExists = demoDataExists(db());

// Field definitions per group
$groups = [
    1 => [
        'title'  => t('cfg.group.1'),
        'fields' => [
            ['key' => 'invoice_company', 'label' => t('cfg.label.invoice_company'),            'type' => 'text'],
            ['key' => 'invoice_street',  'label' => t('cfg.label.invoice_street'),   'type' => 'text'],
            ['key' => 'invoice_zip',     'label' => t('cfg.label.invoice_zip'),                   'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_city',    'label' => t('cfg.label.invoice_city'),                   'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_email',          'label' => t('cfg.label.invoice_email'),          'type' => 'email'],
            ['key' => 'invoice_phone',          'label' => t('cfg.label.invoice_phone'),         'type' => 'text'],
            ['key' => 'invoice_tax_id',         'label' => t('cfg.label.invoice_tax_id'),       'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_tax_number',     'label' => t('cfg.label.invoice_tax_number'),    'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_bank',           'label' => t('cfg.label.invoice_bank'),            'type' => 'text'],
            ['key' => 'invoice_account_holder', 'label' => t('cfg.label.invoice_account_holder'),    'type' => 'text'],
            ['key' => 'invoice_iban',           'label' => t('cfg.label.invoice_iban'),            'type' => 'text'],
            ['key' => 'invoice_bic',            'label' => t('cfg.label.invoice_bic'),             'type' => 'text', 'width' => 'half'],
        ],
    ],
    2 => [
        'title'  => t('cfg.group.2'),
        'fields' => [
            ['key' => 'invoice_hourly_rate',    'label' => t('cfg.label.invoice_hourly_rate'),   'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_tax_rate',       'label' => t('cfg.label.invoice_tax_rate'),              'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_payment_days',   'label' => t('cfg.label.invoice_payment_days'),        'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_number_prefix',  'label' => t('cfg.label.invoice_number_prefix'),     'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_number_start',   'label' => t('cfg.label.invoice_number_start'),      'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_mail_subject',   'label' => t('cfg.label.invoice_mail_subject'), 'type' => 'text'],
            ['key' => 'invoice_mail_bcc',       'label' => t('cfg.label.invoice_mail_bcc'), 'type' => 'email'],
            ['key' => 'invoice_general_info',   'label' => t('cfg.label.invoice_general_info'), 'type' => 'textarea'],
            ['key' => 'invoice_text_template',  'label' => t('cfg.label.invoice_text_template'), 'type' => 'textarea'],
            ['key' => 'invoice_mail_template_html',  'label' => t('cfg.label.invoice_mail_template_html'),  'type' => 'richtext'],
        ],
    ],
    3 => [
        'title'  => t('cfg.group.3'),
        'fields' => [
            ['key' => 'github_repo',           'label' => t('cfg.label.github_repo'), 'type' => 'text'],
            ['key' => 'site_url',              'label' => t('cfg.label.site_url'), 'type' => 'text'],
            ['key' => 'mail_from',             'label' => t('cfg.label.mail_from'),   'type' => 'email',    'width' => 'half'],
            ['key' => 'mail_name',             'label' => t('cfg.label.mail_name'),               'type' => 'text',     'width' => 'half'],
            ['key' => 'mail_bcc',              'label' => t('cfg.label.mail_bcc'), 'type' => 'email',   'width' => 'half'],
            ['key' => 'mail_signature_html',   'label' => t('cfg.label.mail_signature_html'),      'type' => 'richtext'],
        ],
    ],
    4 => [
        'title'  => t('cfg.group.4'),
        'fields' => [
            ['key' => 'smtp_host',       'label' => t('cfg.label.smtp_host'),                         'type' => 'text',     'width' => 'half'],
            ['key' => 'smtp_port',       'label' => t('cfg.label.smtp_port'),         'type' => 'text',     'width' => 'half'],
            ['key' => 'smtp_user',       'label' => t('cfg.label.smtp_user'),                        'type' => 'text',     'width' => 'half'],
            ['key' => 'smtp_password',   'label' => t('cfg.label.smtp_password'),                            'type' => 'password', 'width' => 'half'],
            ['key' => 'smtp_encryption', 'label' => t('cfg.label.smtp_encryption'), 'type' => 'text',     'width' => 'half'],
            ['key' => 'imap_save_sent',   'label' => t('cfg.label.imap_save_sent'), 'type' => 'select',
                'options' => ['0' => t('common.no'), '1' => t('common.yes')]],
            ['key' => 'imap_host',        'label' => t('cfg.label.imap_host'), 'type' => 'text', 'width' => 'half'],
            ['key' => 'imap_port',        'label' => t('cfg.label.imap_port'),        'type' => 'text', 'width' => 'half'],
            ['key' => 'imap_encryption',  'label' => t('cfg.label.imap_encryption'),       'type' => 'text', 'width' => 'half'],
            ['key' => 'imap_sent_folder', 'label' => t('cfg.label.imap_sent_folder'), 'type' => 'text', 'width' => 'half'],
        ],
    ],
];
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('config.title')) ?> – <?= h(t('admin.title')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.cfg-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 16px;
    margin-bottom: 4px;
}
.cfg-grid .full { grid-column: 1 / -1; }
.cfg-grid label { font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 3px; }
@media (max-width: 500px) { .cfg-grid { grid-template-columns: 1fr; } .cfg-grid .full { grid-column: 1; } }

.cfg-textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 7px 10px;
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    font-family: var(--font);
    font-size: 13px;
    color: var(--text);
    background: #fff;
    resize: vertical;
    min-height: 80px;
}
.cfg-textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(0,120,212,0.15);
}

.rte-wrap {
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    background: #fff;
    overflow: hidden;
}
.rte-wrap:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(0,120,212,0.15);
}
.rte-toolbar {
    display: flex;
    gap: 2px;
    padding: 4px 6px;
    border-bottom: 1px solid var(--card-border);
    background: #f5f5f5;
}
.rte-btn {
    border: 1px solid transparent;
    border-radius: 3px;
    background: none;
    cursor: pointer;
    padding: 2px 7px;
    font-size: 13px;
    color: var(--text);
    font-family: var(--font);
    line-height: 1.5;
}
.rte-btn:hover {
    background: #e0eef9;
    border-color: var(--accent);
}
.rte-body {
    min-height: 90px;
    padding: 8px 10px;
    font-size: 13px;
    color: #222;
    outline: none;
    line-height: 1.6;
    background: #fff;
}
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1><?= h(t('config.title')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo; <?= h(t('config.title')) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="index.php" class="btn"><?= h(t('admin.back')) ?></a>
            <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
        </div>
    </div>

    <div id="globalMsg"></div>

    <div class="admin-section">
        <h2><?= h(t('config.appearance')) ?></h2>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span style="font-size:13px;font-weight:600"><?= h(t('settings.design')) ?></span>
            <div class="theme-choice" id="adminThemeChoice">
                <button type="button" class="theme-btn" data-theme-choice="light"><?= h(t('settings.light')) ?></button>
                <button type="button" class="theme-btn" data-theme-choice="dark"><?= h(t('settings.dark')) ?></button>
            </div>
            <span style="font-size:12px;color:var(--text-muted)"><?= h(t('config.appliesBoth')) ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px">
            <span style="font-size:13px;font-weight:600"><?= h(t('settings.language')) ?></span>
            <span class="theme-choice">
                <?php foreach (i18nLangLabels() as $lc => $ll): ?>
                <a href="?lang=<?= h($lc) ?>" class="theme-btn<?= $lc === currentLang() ? ' active' : '' ?>" style="text-decoration:none"><?= h($ll) ?></a>
                <?php endforeach; ?>
            </span>
            <span style="font-size:12px;color:var(--text-muted)"><?= h(t('config.appliesBoth')) ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px">
            <span style="font-size:13px;font-weight:600"><?= h(t('config.defaultLang')) ?></span>
            <select id="cfg_default_lang" data-key="default_lang">
                <?php $curDefault = cfgVal($cfgMap, 'default_lang', 'de'); foreach (i18nLangLabels() as $lc => $ll): ?>
                <option value="<?= h($lc) ?>"<?= $curDefault === $lc ? ' selected' : '' ?>><?= h($ll) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="button" onclick="saveDefaultLang()"><?= h(t('common.save')) ?></button>
            <span id="defaultLangMsg" style="font-size:12px"></span>
        </div>
    </div>

    <?php foreach ($groups as $gid => $group): ?>
    <div class="admin-section" id="cfg-group-section-<?= $gid ?>">
        <h2><?= h($group['title']) ?></h2>
        <div class="cfg-grid" id="group-<?= $gid ?>">
            <?php foreach ($group['fields'] as $f):
                $type  = $f['type'] ?? 'text';
                $full  = !isset($f['width']) || in_array($type, ['richtext', 'textarea']);
                $val   = cfgVal($cfgMap, $f['key']);
            ?>
            <div class="<?= $full ? 'full' : '' ?>">
                <label for="cfg_<?= h($f['key']) ?>"><?= h($f['label']) ?></label>
                <?php if ($type === 'richtext'): ?>
                <div class="rte-wrap">
                    <div class="rte-toolbar">
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('bold')"><b>B</b></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('italic')"><em>I</em></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('underline')"><u>U</u></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="rteLink(this)">Link</button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('removeFormat')" title="Formatierung entfernen">&#10005;</button>
                    </div>
                    <div class="rte-body"
                         id="cfg_<?= h($f['key']) ?>"
                         data-key="<?= h($f['key']) ?>"
                         contenteditable="true"><?= $val ?></div>
                </div>
                <?php elseif ($type === 'textarea'): ?>
                <textarea
                    id="cfg_<?= h($f['key']) ?>"
                    data-key="<?= h($f['key']) ?>"
                    rows="4"
                    class="cfg-textarea"><?= h($val) ?></textarea>
                <?php elseif ($type === 'select'): ?>
                <select id="cfg_<?= h($f['key']) ?>" data-key="<?= h($f['key']) ?>">
                    <?php foreach (($f['options'] ?? []) as $ov => $ol): ?>
                    <option value="<?= h((string)$ov) ?>"<?= (string)$val === (string)$ov ? ' selected' : '' ?>><?= h((string)$ol) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input
                    type="<?= h($type) ?>"
                    id="cfg_<?= h($f['key']) ?>"
                    data-key="<?= h($f['key']) ?>"
                    value="<?= h($val) ?>"
                    autocomplete="off"
                >
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:12px; display:flex; align-items:center; gap:10px; flex-wrap:wrap">
            <button class="btn btn--primary" onclick="saveGroup(<?= $gid ?>)"><?= h(t('common.save')) ?></button>
            <?php if ($gid === 4): ?>
            <button class="btn" type="button" onclick="sendTestMail()"><?= h(t('config.testMail')) ?></button>
            <button class="btn" type="button" onclick="listImapFolders()"><?= h(t('config.showImapFolders')) ?></button>
            <?php endif; ?>
            <span class="cfg-msg" id="gmsg-<?= $gid ?>" style="font-size:12px"></span>
        </div>
        <?php if ($gid === 4): ?>
        <div id="imapFoldersResult" style="margin-top:10px; font-size:12px"></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="admin-section">
        <h2><?= h(t('config.shortcuts')) ?></h2>

        <?php if ($cfgShortcuts === null): ?>
            <div class="admin-msg admin-msg--err" style="margin-bottom:12px">
                <?= h(t('config.shortcutsMissing')) ?>
            </div>
            <pre style="background:#1a1a1a;color:#ddd;padding:10px;border-radius:4px;font-size:12px;overflow:auto">CREATE TABLE IF NOT EXISTS tm_shortcuts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT NULL,
  activity      VARCHAR(255) NOT NULL,
  shortcut_text TEXT NOT NULL,
  created_at    DATETIME DEFAULT NOW()
);</pre>
        <?php else: ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
            <div>
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= h(t('entries.colCustomer')) ?></label>
                <select id="scCustomer">
                    <option value=""><?= h(t('config.allCustomers')) ?></option>
                    <?php foreach ($cfgCustomers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= h(t('common.activity')) ?></label>
                <select id="scActivity">
                    <?php foreach (getActivities(db()) as $act): ?>
                    <option value="<?= h($act) ?>"><?= h($act) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:200px">
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= h(t('config.shortcutText')) ?></label>
                <input type="text" id="scText" placeholder="<?= h(t('config.shortcutPlaceholder')) ?>" style="width:100%;box-sizing:border-box">
            </div>
            <button class="btn btn--primary" onclick="saveShortcut()"><?= h(t('config.saveShortcut')) ?></button>
            <span id="scMsg" style="font-size:12px"></span>
        </div>

        <div id="scList">
        <?php if (!empty($cfgShortcuts)): ?>
        <table class="entries-table">
            <thead>
                <tr>
                    <th><?= h(t('entries.colCustomer')) ?></th>
                    <th><?= h(t('common.activity')) ?></th>
                    <th><?= h(t('config.text')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="scTbody">
            <?php foreach ($cfgShortcuts as $s): ?>
            <tr id="sc-row-<?= (int)$s['id'] ?>">
                <td><?= $s['customer_name'] !== '' ? h($s['customer_name']) : '<span style="color:var(--text-muted)">' . h(t('config.allCustomers')) . '</span>' ?></td>
                <td><?= h($s['activity']) ?></td>
                <td><?= h($s['shortcut_text']) ?></td>
                <td>
                    <button type="button" class="btn-icon btn-icon--danger"
                            onclick="deleteShortcut(<?= (int)$s['id'] ?>)" title="<?= h(t('common.delete')) ?>">
                        <svg viewBox="0 0 448 512" width="13" height="13" aria-hidden="true"><path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"/></svg>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="empty-message" id="scEmpty"><?= h(t('config.noShortcuts')) ?></p>
        <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>

    <div class="admin-section">
        <h2><?= h(t('config.sqlMigration')) ?></h2>
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px">
            <?= h(t('config.sqlHint')) ?>
        </p>
        <textarea id="sqlInput" rows="8" style="
            width:100%; box-sizing:border-box; font-family:monospace; font-size:12px;
            padding:10px; border:1px solid var(--border); border-radius:6px;
            background:var(--bg); color:#fff; resize:vertical;
        " placeholder="ALTER TABLE `tm_customers` ADD COLUMN ..."></textarea>
        <div style="margin-top:10px; display:flex; align-items:center; gap:10px; flex-wrap:wrap">
            <button class="btn btn--primary" onclick="runSql()"><?= h(t('config.execute')) ?></button>
            <button class="btn" onclick="document.getElementById('sqlInput').value=''"><?= h(t('config.clear')) ?></button>
            <span id="sqlMsg" style="font-size:12px"></span>
        </div>
        <div id="sqlResults" style="margin-top:10px"></div>
    </div>

    <div class="admin-section">
        <h2><?= h(t('config.demoData')) ?></h2>
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px">
            <?= h(t('config.demoHint')) ?>
        </p>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <button class="btn btn--primary" id="demoCreateBtn" onclick="createDemo()"
                    <?= $demoExists ? 'style="display:none"' : '' ?>><?= h(t('config.demoCreate')) ?></button>
            <button class="btn btn--danger" id="demoDeleteBtn" onclick="deleteDemo()"
                    <?= $demoExists ? '' : 'style="display:none"' ?>><?= h(t('config.demoDelete')) ?></button>
            <span id="demoMsg" style="font-size:12px"></span>
        </div>
        <p id="demoStatus" style="font-size:12px;color:var(--text-muted);margin:10px 0 0">
            <?= $demoExists ? h(t('config.demoPresent')) : h(t('config.demoAbsent')) ?>
        </p>
    </div>

</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
window.I18N = <?= json_encode(i18nStrings(), JSON_UNESCAPED_UNICODE) ?>;
window.LANG = <?= json_encode(currentLang()) ?>;
function t(key, params) {
    let s = (window.I18N && window.I18N[key]) || key;
    if (params) { for (const k in params) { s = s.split('{' + k + '}').join(params[k]); } }
    return s;
}

async function saveDefaultLang() {
    const val = document.getElementById('cfg_default_lang').value;
    const msg = document.getElementById('defaultLangMsg');
    msg.textContent = '';
    try {
        const res = await fetch('api.php', {
            method: 'POST', headers: { 'X-CSRF-Token': CSRF },
            body: new URLSearchParams({ action: 'save_config', default_lang: val })
        });
        const data = await res.json();
        msg.style.color = data.success ? 'var(--success)' : 'var(--danger)';
        msg.textContent = data.success ? t('common.saved') : (data.error || t('common.saveError'));
    } catch (e) {
        msg.style.color = 'var(--danger)';
        msg.textContent = t('config.serverError');
    }
}

// Design-Schalter (hell/dunkel) – Einstellung wird mit der App geteilt
(function(){
    const choice = document.getElementById('adminThemeChoice');
    if (!choice) return;
    function apply(t){
        t = (t === 'light') ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('tm_theme', t);
        choice.querySelectorAll('.theme-btn').forEach(function(b){
            b.classList.toggle('active', b.dataset.themeChoice === t);
        });
    }
    apply(localStorage.getItem('tm_theme') || 'dark');
    choice.querySelectorAll('.theme-btn').forEach(function(b){
        b.addEventListener('click', function(){ apply(b.dataset.themeChoice); });
    });
})();

function rteLink(btn) {
    const body = btn.closest('.rte-wrap').querySelector('.rte-body');
    const sel  = window.getSelection();
    let range  = sel && sel.rangeCount > 0 ? sel.getRangeAt(0).cloneRange() : null;
    const url  = prompt('URL:', 'https://');
    if (!url) return;
    body.focus();
    if (range) { sel.removeAllRanges(); sel.addRange(range); }
    document.execCommand('createLink', false, url);
}

async function saveGroup(gid) {
    const container = document.getElementById('group-' + gid);
    const fields    = container.querySelectorAll('[data-key]');
    const params    = { action: 'save_config' };
    fields.forEach(function(el) {
        if (el.contentEditable === 'true') {
            params[el.dataset.key] = el.innerHTML;
        } else {
            params[el.dataset.key] = el.value;
        }
    });

    const msgEl = document.getElementById('gmsg-' + gid);
    msgEl.textContent = '';

    try {
        const body = new URLSearchParams(params);
        const res  = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body
        });
        const data = await res.json();
        if (data.success) {
            msgEl.style.color = 'var(--success)';
            msgEl.textContent = t('common.saved');
        } else {
            msgEl.style.color = 'var(--danger)';
            msgEl.textContent = data.error || t('common.saveError');
        }
    } catch(e) {
        msgEl.style.color = 'var(--danger)';
        msgEl.textContent = t('config.serverError');
    }
}

async function runSql() {
    const sql     = document.getElementById('sqlInput').value.trim();
    const msgEl   = document.getElementById('sqlMsg');
    const resEl   = document.getElementById('sqlResults');
    msgEl.textContent = '';
    resEl.innerHTML   = '';

    if (!sql) {
        msgEl.style.color = 'var(--danger)';
        msgEl.textContent = t('config.noSql');
        return;
    }

    msgEl.style.color = 'var(--text-muted)';
    msgEl.textContent = t('config.running');

    try {
        const body = new URLSearchParams({ action: 'execute_sql', sql });
        const res  = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body
        });
        const data = await res.json();

        if (data.success) {
            msgEl.style.color = 'var(--success)';
            msgEl.textContent = t('config.stmtsOk', { n: data.data.count });

            const rows = data.data.results.map(function(r) {
                return '<tr style="border-bottom:1px solid var(--border)">' +
                    '<td style="padding:5px 10px 5px 0;font-family:monospace;font-size:11px;color:var(--text-muted)">' +
                        escHtml(r.preview) + (r.preview.length >= 80 ? '…' : '') +
                    '</td>' +
                    '<td style="padding:5px 0;white-space:nowrap;font-size:12px;color:var(--success)">' +
                        t('config.rowsAffected', { n: r.affected }) +
                    '</td>' +
                '</tr>';
            }).join('');
            resEl.innerHTML = '<table style="width:100%;border-collapse:collapse">' + rows + '</table>';
        } else {
            msgEl.style.color = 'var(--danger)';
            msgEl.textContent = t('common.error') + ': ' + (data.error || t('common.unknownError'));
        }
    } catch(e) {
        msgEl.style.color = 'var(--danger)';
        msgEl.textContent = t('config.serverErrorRetry');
    }
}

async function sendTestMail() {
    const msgEl = document.getElementById('gmsg-4');
    msgEl.style.color   = '#777';
    msgEl.textContent   = t('config.sending');

    try {
        const res  = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body: new URLSearchParams({ action: 'send_test_mail' }),
        });
        const data = await res.json();
        if (data.success) {
            msgEl.style.color = 'var(--success)';
            msgEl.textContent = t('config.testMailSent', { r: data.data.recipient || '?' })
                              + (data.data.imap ? ' — ' + data.data.imap : '');
        } else {
            msgEl.style.color = 'var(--danger)';
            msgEl.textContent = data.error || t('config.sendFailed');
        }
    } catch(e) {
        msgEl.style.color = 'var(--danger)';
        msgEl.textContent = t('config.serverError');
    }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function saveShortcut() {
    const customerId = document.getElementById('scCustomer').value;
    const activity   = document.getElementById('scActivity').value;
    const text       = document.getElementById('scText').value.trim();
    const msgEl      = document.getElementById('scMsg');
    msgEl.textContent = '';

    if (!text) {
        msgEl.style.color = 'var(--danger)'; msgEl.textContent = t('config.enterText'); return;
    }

    try {
        const res  = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body: new URLSearchParams({ action: 'save_shortcut', customer_id: customerId, activity, shortcut_text: text }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || t('common.error'));

        msgEl.style.color = 'var(--success)'; msgEl.textContent = t('common.saved');
        document.getElementById('scText').value = '';

        // Zeile in Tabelle einfügen
        const tbody = document.getElementById('scTbody');
        const empty = document.getElementById('scEmpty');
        if (empty) empty.remove();
        if (!tbody) {
            // Tabelle neu aufbauen wenn vorher leer
            document.getElementById('scList').innerHTML =
                '<table class="entries-table"><thead><tr><th>' + escHtml(t('entries.colCustomer')) + '</th><th>' + escHtml(t('common.activity')) + '</th><th>' + escHtml(t('config.text')) + '</th><th></th></tr></thead>' +
                '<tbody id="scTbody"></tbody></table>';
        }
        const tb = document.getElementById('scTbody');
        const customerName = document.getElementById('scCustomer').selectedOptions[0].text;
        const tr = document.createElement('tr');
        tr.id = 'sc-row-' + data.data.id;
        tr.innerHTML =
            '<td>' + (customerId ? escHtml(customerName) : '<span style="color:var(--text-muted)">' + escHtml(t('config.allCustomers')) + '</span>') + '</td>' +
            '<td>' + escHtml(activity) + '</td>' +
            '<td>' + escHtml(text) + '</td>' +
            '<td><button type="button" class="btn-icon btn-icon--danger" onclick="deleteShortcut(' + data.data.id + ')" title="' + escHtml(t('common.delete')) + '">' +
            '<svg viewBox="0 0 448 512" width="13" height="13"><path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"/></svg>' +
            '</button></td>';
        tb.appendChild(tr);
    } catch(e) {
        msgEl.style.color = 'var(--danger)'; msgEl.textContent = e.message;
    }
}

async function deleteShortcut(id) {
    try {
        const res  = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body: new URLSearchParams({ action: 'delete_shortcut', id }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || t('common.error'));
        document.getElementById('sc-row-' + id)?.remove();
    } catch(e) {
        Dialog.alert(t('common.error') + ': ' + e.message);
    }
}

async function listImapFolders() {
    const out = document.getElementById('imapFoldersResult');
    out.textContent = t('config.loadingFolders');
    try {
        const res  = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body: new URLSearchParams({ action: 'list_imap_folders' }),
        });
        const data = await res.json();
        out.innerHTML = '';
        if (!data.success) {
            const err = document.createElement('span');
            err.style.color = 'var(--danger)';
            err.textContent = data.error || t('common.error');
            out.appendChild(err);
            return;
        }
        const folders = data.data.folders || [];
        if (folders.length === 0) {
            out.textContent = t('config.noFolders');
            return;
        }
        const title = document.createElement('div');
        title.innerHTML = t('config.foldersOnServer');
        title.style.marginBottom = '4px';
        out.appendChild(title);
        folders.forEach(function(name) {
            const c = document.createElement('code');
            c.textContent = name;
            c.style.cssText = 'background:#f0f0f0;padding:1px 6px;border-radius:3px;margin:2px 6px 2px 0;display:inline-block';
            out.appendChild(c);
        });
    } catch(e) {
        out.innerHTML = '';
        const err = document.createElement('span');
        err.style.color = 'var(--danger)';
        err.textContent = t('config.serverError');
        out.appendChild(err);
    }
}

// ---- Demo-Daten ----
async function demoApi(action) {
    const res  = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action })
    });
    return res.json();
}

async function createDemo() {
    const btn = document.getElementById('demoCreateBtn');
    const msg = document.getElementById('demoMsg');
    btn.disabled = true;
    msg.style.color = 'var(--text-muted)';
    msg.textContent = t('config.demoCreating');
    try {
        const data = await demoApi('create_demo_data');
        if (data.success) {
            msg.style.color = 'var(--success)';
            msg.textContent = t('config.demoCreated');
            document.getElementById('demoCreateBtn').style.display = 'none';
            document.getElementById('demoDeleteBtn').style.display = '';
            document.getElementById('demoStatus').textContent = t('config.demoPresent');
        } else {
            msg.style.color = 'var(--danger)';
            msg.textContent = t('common.error') + ': ' + (data.error || t('common.unknownError'));
        }
    } catch (e) {
        msg.style.color = 'var(--danger)';
        msg.textContent = t('config.serverError');
    }
    btn.disabled = false;
}

async function deleteDemo() {
    if (!await Dialog.confirm(t('config.demoConfirmDelete'), { danger: true })) return;
    const btn = document.getElementById('demoDeleteBtn');
    const msg = document.getElementById('demoMsg');
    btn.disabled = true;
    msg.style.color = 'var(--text-muted)';
    msg.textContent = t('config.demoDeleting');
    try {
        const data = await demoApi('delete_demo_data');
        if (data.success) {
            msg.style.color = 'var(--success)';
            msg.textContent = t('config.demoDeleted');
            document.getElementById('demoDeleteBtn').style.display = 'none';
            document.getElementById('demoCreateBtn').style.display = '';
            document.getElementById('demoStatus').textContent = t('config.demoAbsent');
        } else {
            msg.style.color = 'var(--danger)';
            msg.textContent = t('common.error') + ': ' + (data.error || t('common.unknownError'));
        }
    } catch (e) {
        msg.style.color = 'var(--danger)';
        msg.textContent = t('config.serverError');
    }
    btn.disabled = false;
}
</script>
</body>
</html>
