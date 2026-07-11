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

// Field definitions per group
$groups = [
    1 => [
        'title'  => 'Rechnungsabsender (eigene Firma)',
        'fields' => [
            ['key' => 'invoice_company', 'label' => 'Firmenname',            'type' => 'text'],
            ['key' => 'invoice_street',  'label' => 'Straße & Hausnummer',   'type' => 'text'],
            ['key' => 'invoice_zip',     'label' => 'PLZ',                   'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_city',    'label' => 'Ort',                   'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_email',          'label' => 'E-Mail',          'type' => 'email'],
            ['key' => 'invoice_phone',          'label' => 'Telefon',         'type' => 'text'],
            ['key' => 'invoice_tax_id',         'label' => 'USt-IdNr.',       'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_tax_number',     'label' => 'Steuernummer',    'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_bank',           'label' => 'Bank',            'type' => 'text'],
            ['key' => 'invoice_account_holder', 'label' => 'Kontoinhaber',    'type' => 'text'],
            ['key' => 'invoice_iban',           'label' => 'IBAN',            'type' => 'text'],
            ['key' => 'invoice_bic',            'label' => 'BIC',             'type' => 'text', 'width' => 'half'],
        ],
    ],
    2 => [
        'title'  => 'Rechnungsparameter',
        'fields' => [
            ['key' => 'invoice_hourly_rate',    'label' => 'Standard-Stundensatz (€)',   'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_tax_rate',       'label' => 'MwSt-Satz (%)',              'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_payment_days',   'label' => 'Zahlungsziel (Tage)',        'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_number_prefix',  'label' => 'Rechnungsnummer Prefix',     'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_number_start',   'label' => 'Rechnungsnummer Start',      'type' => 'text', 'width' => 'half'],
            ['key' => 'invoice_mail_subject',   'label' => 'E-Mail Betreff für Rechnungen — Platzhalter: {project}, {time}', 'type' => 'text'],
            ['key' => 'invoice_mail_bcc',       'label' => 'Kopie der Rechnungsmails an (BCC) — wird beim Versand aus dem Mailspool als Kopie zugestellt', 'type' => 'email'],
            ['key' => 'invoice_general_info',   'label' => 'Allgemeine Info auf allen Rechnungen — erscheint unter dem Gesamtbetrag (PDF und Vorschau)', 'type' => 'textarea'],
        ],
    ],
    3 => [
        'title'  => 'System & E-Mail',
        'fields' => [
            ['key' => 'github_repo',           'label' => 'GitHub Repository (für System-Updates, z.B. benutzer/time-manager)', 'type' => 'text'],
            ['key' => 'site_url',              'label' => 'Basis-URL der Installation (ohne abschließenden Slash)', 'type' => 'text'],
            ['key' => 'mail_from',             'label' => 'Absender-E-Mail (noreply)',   'type' => 'email',    'width' => 'half'],
            ['key' => 'mail_name',             'label' => 'Absender-Name',               'type' => 'text',     'width' => 'half'],
            ['key' => 'mail_bcc',              'label' => 'BCC (alle ausgehenden Mails)', 'type' => 'email',   'width' => 'half'],
            ['key' => 'mail_signature_html',   'label' => 'E-Mail Signatur (HTML)',      'type' => 'richtext'],
            ['key' => 'mail_signature_plain',  'label' => 'E-Mail Signatur (Plain Text)','type' => 'textarea'],
        ],
    ],
    4 => [
        'title'  => 'SMTP-Mailversand',
        'fields' => [
            ['key' => 'smtp_host',       'label' => 'SMTP Server',                         'type' => 'text',     'width' => 'half'],
            ['key' => 'smtp_port',       'label' => 'Port (587 = TLS, 465 = SSL)',         'type' => 'text',     'width' => 'half'],
            ['key' => 'smtp_user',       'label' => 'Benutzername',                        'type' => 'text',     'width' => 'half'],
            ['key' => 'smtp_password',   'label' => 'Passwort',                            'type' => 'password', 'width' => 'half'],
            ['key' => 'smtp_encryption', 'label' => 'Verschlüsselung (tls / ssl / none)', 'type' => 'text',     'width' => 'half'],
            ['key' => 'imap_save_sent',   'label' => 'Versendete Rechnungsmails im IMAP-Sent-Ordner ablegen', 'type' => 'select',
                'options' => ['0' => 'Nein', '1' => 'Ja']],
            ['key' => 'imap_host',        'label' => 'IMAP Server (Login = SMTP-Benutzer/Passwort)', 'type' => 'text', 'width' => 'half'],
            ['key' => 'imap_port',        'label' => 'IMAP Port (993 = SSL, 143 = TLS/none)',        'type' => 'text', 'width' => 'half'],
            ['key' => 'imap_encryption',  'label' => 'IMAP Verschlüsselung (ssl / tls / none)',       'type' => 'text', 'width' => 'half'],
            ['key' => 'imap_sent_folder', 'label' => 'Sent-Ordner (z.B. Sent, INBOX.Sent, Gesendet)', 'type' => 'text', 'width' => 'half'],
        ],
    ],
];
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Konfiguration – Administration</title>
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
            <h1>Konfiguration</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo; Konfiguration
            </div>
        </div>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div id="globalMsg"></div>

    <div class="admin-section">
        <h2>Darstellung</h2>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span style="font-size:13px;font-weight:600">Design</span>
            <div class="theme-choice" id="adminThemeChoice">
                <button type="button" class="theme-btn" data-theme-choice="light">Hell</button>
                <button type="button" class="theme-btn" data-theme-choice="dark">Dunkel</button>
            </div>
            <span style="font-size:12px;color:var(--text-muted)">Gilt für App und Administration.</span>
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
            <button class="btn btn--primary" onclick="saveGroup(<?= $gid ?>)">Speichern</button>
            <?php if ($gid === 4): ?>
            <button class="btn" type="button" onclick="sendTestMail()">Testmail versenden</button>
            <button class="btn" type="button" onclick="listImapFolders()">IMAP-Ordner anzeigen</button>
            <?php endif; ?>
            <span class="cfg-msg" id="gmsg-<?= $gid ?>" style="font-size:12px"></span>
        </div>
        <?php if ($gid === 4): ?>
        <div id="imapFoldersResult" style="margin-top:10px; font-size:12px"></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="admin-section">
        <h2>Shortcuts festlegen</h2>

        <?php if ($cfgShortcuts === null): ?>
            <div class="admin-msg admin-msg--err" style="margin-bottom:12px">
                Tabelle <code>tm_shortcuts</code> fehlt. Bitte zuerst im SQL-Migrations-Bereich ausführen:
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
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">Kunde</label>
                <select id="scCustomer">
                    <option value="">— Alle Kunden —</option>
                    <?php foreach ($cfgCustomers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">Tätigkeit</label>
                <select id="scActivity">
                    <?php foreach (ACTIVITIES as $act): ?>
                    <option value="<?= h($act) ?>"><?= h($act) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:200px">
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">Shortcut Text</label>
                <input type="text" id="scText" placeholder="z.B. Fehler in Login behoben" style="width:100%;box-sizing:border-box">
            </div>
            <button class="btn btn--primary" onclick="saveShortcut()">Shortcut speichern</button>
            <span id="scMsg" style="font-size:12px"></span>
        </div>

        <div id="scList">
        <?php if (!empty($cfgShortcuts)): ?>
        <table class="entries-table">
            <thead>
                <tr>
                    <th>Kunde</th>
                    <th>Tätigkeit</th>
                    <th>Text</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="scTbody">
            <?php foreach ($cfgShortcuts as $s): ?>
            <tr id="sc-row-<?= (int)$s['id'] ?>">
                <td><?= $s['customer_name'] !== '' ? h($s['customer_name']) : '<span style="color:var(--text-muted)">— Alle Kunden —</span>' ?></td>
                <td><?= h($s['activity']) ?></td>
                <td><?= h($s['shortcut_text']) ?></td>
                <td>
                    <button type="button" class="btn-icon btn-icon--danger"
                            onclick="deleteShortcut(<?= (int)$s['id'] ?>)" title="Löschen">
                        <svg viewBox="0 0 448 512" width="13" height="13" aria-hidden="true"><path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"/></svg>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="empty-message" id="scEmpty">Noch keine Shortcuts vorhanden.</p>
        <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>

    <div class="admin-section">
        <h2>SQL-Migration ausführen</h2>
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px">
            Mehrere Anweisungen mit Semikolon trennen. Nur für Datenbank-Migrationen verwenden.
        </p>
        <textarea id="sqlInput" rows="8" style="
            width:100%; box-sizing:border-box; font-family:monospace; font-size:12px;
            padding:10px; border:1px solid var(--border); border-radius:6px;
            background:var(--bg); color:#fff; resize:vertical;
        " placeholder="ALTER TABLE `tm_customers` ADD COLUMN ..."></textarea>
        <div style="margin-top:10px; display:flex; align-items:center; gap:10px; flex-wrap:wrap">
            <button class="btn btn--primary" onclick="runSql()">Ausführen</button>
            <button class="btn" onclick="document.getElementById('sqlInput').value=''">Leeren</button>
            <span id="sqlMsg" style="font-size:12px"></span>
        </div>
        <div id="sqlResults" style="margin-top:10px"></div>
    </div>

</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

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
            msgEl.textContent = 'Gespeichert.';
        } else {
            msgEl.style.color = 'var(--danger)';
            msgEl.textContent = data.error || 'Fehler beim Speichern.';
        }
    } catch(e) {
        msgEl.style.color = 'var(--danger)';
        msgEl.textContent = 'Serverfehler.';
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
        msgEl.textContent = 'Kein SQL eingegeben.';
        return;
    }

    msgEl.style.color = 'var(--text-muted)';
    msgEl.textContent = 'Wird ausgeführt…';

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
            msgEl.textContent = data.data.count + ' Anweisung(en) erfolgreich ausgeführt.';

            const rows = data.data.results.map(function(r) {
                return '<tr style="border-bottom:1px solid var(--border)">' +
                    '<td style="padding:5px 10px 5px 0;font-family:monospace;font-size:11px;color:var(--text-muted)">' +
                        escHtml(r.preview) + (r.preview.length >= 80 ? '…' : '') +
                    '</td>' +
                    '<td style="padding:5px 0;white-space:nowrap;font-size:12px;color:var(--success)">' +
                        r.affected + ' Zeile(n) betroffen' +
                    '</td>' +
                '</tr>';
            }).join('');
            resEl.innerHTML = '<table style="width:100%;border-collapse:collapse">' + rows + '</table>';
        } else {
            msgEl.style.color = 'var(--danger)';
            msgEl.textContent = 'Fehler: ' + (data.error || 'Unbekannter Fehler');
        }
    } catch(e) {
        msgEl.style.color = 'var(--danger)';
        msgEl.textContent = 'Serverfehler – bitte erneut versuchen.';
    }
}

async function sendTestMail() {
    const msgEl = document.getElementById('gmsg-4');
    msgEl.style.color   = '#777';
    msgEl.textContent   = 'Wird gesendet…';

    try {
        const res  = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body: new URLSearchParams({ action: 'send_test_mail' }),
        });
        const data = await res.json();
        if (data.success) {
            msgEl.style.color = 'var(--success)';
            msgEl.textContent = 'Testmail versendet an ' + (data.data.recipient || '?')
                              + (data.data.imap ? ' — ' + data.data.imap : '');
        } else {
            msgEl.style.color = 'var(--danger)';
            msgEl.textContent = data.error || 'Fehler beim Versenden.';
        }
    } catch(e) {
        msgEl.style.color = 'var(--danger)';
        msgEl.textContent = 'Serverfehler.';
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
        msgEl.style.color = 'var(--danger)'; msgEl.textContent = 'Bitte Text eingeben.'; return;
    }

    try {
        const res  = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body: new URLSearchParams({ action: 'save_shortcut', customer_id: customerId, activity, shortcut_text: text }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fehler');

        msgEl.style.color = 'var(--success)'; msgEl.textContent = 'Gespeichert.';
        document.getElementById('scText').value = '';

        // Zeile in Tabelle einfügen
        const tbody = document.getElementById('scTbody');
        const empty = document.getElementById('scEmpty');
        if (empty) empty.remove();
        if (!tbody) {
            // Tabelle neu aufbauen wenn vorher leer
            document.getElementById('scList').innerHTML =
                '<table class="entries-table"><thead><tr><th>Kunde</th><th>Tätigkeit</th><th>Text</th><th></th></tr></thead>' +
                '<tbody id="scTbody"></tbody></table>';
        }
        const tb = document.getElementById('scTbody');
        const customerName = document.getElementById('scCustomer').selectedOptions[0].text;
        const tr = document.createElement('tr');
        tr.id = 'sc-row-' + data.data.id;
        tr.innerHTML =
            '<td>' + (customerId ? escHtml(customerName) : '<span style="color:var(--text-muted)">— Alle Kunden —</span>') + '</td>' +
            '<td>' + escHtml(activity) + '</td>' +
            '<td>' + escHtml(text) + '</td>' +
            '<td><button type="button" class="btn-icon btn-icon--danger" onclick="deleteShortcut(' + data.data.id + ')" title="Löschen">' +
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
        if (!data.success) throw new Error(data.error || 'Fehler');
        document.getElementById('sc-row-' + id)?.remove();
    } catch(e) {
        Dialog.alert('Fehler: ' + e.message);
    }
}

async function listImapFolders() {
    const out = document.getElementById('imapFoldersResult');
    out.textContent = 'Lade Ordner…';
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
            err.textContent = data.error || 'Fehler';
            out.appendChild(err);
            return;
        }
        const folders = data.data.folders || [];
        if (folders.length === 0) {
            out.textContent = 'Keine Ordner gefunden.';
            return;
        }
        const title = document.createElement('div');
        title.innerHTML = '<strong>Ordner auf dem Server</strong> (Wert für „Sent-Ordner" übernehmen):';
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
        err.textContent = 'Serverfehler.';
        out.appendChild(err);
    }
}
</script>
</body>
</html>
