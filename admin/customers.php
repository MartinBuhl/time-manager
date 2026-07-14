<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/activities.php';

$stmt = db()->query(
    'SELECT id, name, active, billable, projects,
            billing_name, billing_street, billing_zip, billing_city,
            billing_email, billing_tax_id,
            phone_landline, phone_mobile,
            contact_first_name, contact_last_name, contact_on_invoice,
            hourly_rate
     FROM tm_customers ORDER BY name ASC'
);
$customers = $stmt->fetchAll();
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('customers.pageTitle')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.project-list { list-style: none; margin: 0 0 10px; padding: 0; }
.project-list li {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 0;
    border-bottom: 1px solid #e8e8e8;
}
.project-list li:last-child { border-bottom: none; }
.project-name { flex: 1; font-size: 12px; }
.project-edit-input { flex: 1; font-size: 12px; }
.project-add-row { display: flex; gap: 6px; margin-top: 8px; }
.project-add-row input { flex: 1; }
.stamm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}
.stamm-grid label { font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 3px; }
.stamm-grid .full { grid-column: 1 / -1; }
.table-controls { display:flex; gap:8px; margin-bottom:10px; align-items:center; }
.table-controls input[type="search"] { flex:1; }
.table-controls select { flex:0 0 auto; width:130px; }
#customerTable th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
#customerTable th.sortable:hover { background:rgba(0,0,0,.04); }
.sort-icon { font-size:10px; color:var(--text-muted); margin-left:3px; }
#customerTable th.sorted .sort-icon { color:var(--accent, #4a7cdc); }
.cname-company { font-size:11px; color:var(--text-muted); }
.toggle-switch { position:relative; display:inline-block; width:36px; height:20px; flex-shrink:0; }
.toggle-switch input { opacity:0; width:0; height:0; position:absolute; }
.toggle-slider {
    position:absolute; cursor:pointer;
    inset:0;
    background:#ccc;
    border-radius:20px;
    transition:background .2s;
}
.toggle-slider::before {
    content:'';
    position:absolute;
    width:16px; height:16px;
    left:2px; bottom:2px;
    background:#fff;
    border-radius:50%;
    transition:transform .2s;
    box-shadow:0 1px 3px rgba(0,0,0,.25);
}
.toggle-switch input:checked + .toggle-slider { background:var(--success); }
.toggle-switch input:checked + .toggle-slider::before { transform:translateX(16px); }
.toggle-switch input:focus-visible + .toggle-slider { outline:2px solid var(--accent); outline-offset:2px; }
.bulk-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #fff8e1;
    border: 1px solid #ffc107;
    border-radius: var(--radius);
    margin-top: 12px;
    font-size: 13px;
    color: #333;
}
.bulk-bar select { background: #fff; color: #1f2937; }
.bulk-normal, .bulk-confirm { display: flex; align-items: center; gap: 10px; }
.rule-list { list-style: none; margin: 0 0 10px; padding: 0; }
.rule-list li {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 6px;
    align-items: center;
    padding: 4px 0;
    border-bottom: 1px solid #e8e8e8;
    font-size: 12px;
}
.rule-list li:last-child { border-bottom: none; }
.rule-act, .rule-cmt { padding: 2px 4px; }
.rule-cmt-empty { color: var(--text-muted); font-style: italic; }
.rule-add-row {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 6px;
    margin-top: 8px;
}
.rule-help {
    font-size: 11px;
    color: var(--text-muted);
    margin: 4px 0 8px;
}
.search-result {
    margin-top: 12px;
    padding: 10px 12px;
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    background: #fafafa;
    font-size: 12px;
}
.search-result h4 {
    margin: 0 0 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
}
.search-result table { width: 100%; border-collapse: collapse; margin: 8px 0; }
.search-result th, .search-result td {
    padding: 4px 6px;
    text-align: left;
    border-bottom: 1px solid var(--card-border);
}
.search-result th { font-weight: 600; font-size: 11px; color: var(--text-muted); }
.search-result td { font-size: 11px; color: var(--text); }
.search-result td.right, .search-result th.right { text-align: right; }
.search-result-actions { margin-top: 10px; display: flex; gap: 8px; align-items: center; }
.search-empty { color: var(--text-muted); font-style: italic; }

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
.rte-btn:hover { background: #e0eef9; border-color: var(--accent); }
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
            <h1><?= h(t('customers.heading')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo; <?= h(t('customers.heading')) ?>
            </div>
        </div>
        <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
    </div>

    <div class="admin-section">
        <h2><?= h(t('customers.addNew')) ?></h2>
        <div id="addMsg"></div>
        <div class="add-form">
            <input type="text" id="newCustomerName" placeholder="<?= h(t('customers.customerName')) ?>" maxlength="255">
            <button class="btn btn--primary" id="addBtn"><?= h(t('customers.add')) ?></button>
        </div>
    </div>

    <div class="admin-section" id="customerListSection">
        <h2><?= h(t('customers.allCustomers')) ?></h2>
        <div class="table-controls">
            <input type="search" id="customerSearch" placeholder="<?= h(t('customers.searchPlaceholder')) ?>">
            <select id="statusFilter">
                <option value=""><?= h(t('customers.allStatus')) ?></option>
                <option value="1" selected><?= h(t('customers.active')) ?></option>
                <option value="0"><?= h(t('customers.inactive')) ?></option>
            </select>
        </div>
        <div class="table-wrapper">
            <table class="entries-table" id="customerTable">
                <thead>
                    <tr>
                        <th style="width:32px;text-align:center"><input type="checkbox" id="selectAll" title="<?= h(t('customers.selectAll')) ?>"></th>
                        <th class="sortable" data-col="0"><?= h(t('customers.colName')) ?> <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="1"><?= h(t('customers.colLastName')) ?> <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="2"><?= h(t('customers.colEmail')) ?> <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="3"><?= h(t('customers.colStatus')) ?> <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="4"><?= h(t('customers.colBillable')) ?> <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="5"><?= h(t('customers.colProjects')) ?> <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="6"><?= h(t('customers.colRate')) ?> <span class="sort-icon"></span></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $c):
                    $projects     = json_decode($c['projects'] ?? '[]', true);
                    if (!is_array($projects)) $projects = [];
                    $projectNames = implode(', ', array_column($projects, 'name'));
                    $projectsJson = h(json_encode(array_values($projects), JSON_UNESCAPED_UNICODE));
                    $rate         = $c['hourly_rate'] !== null ? number_format((float)$c['hourly_rate'], 2, ',', '.') : '';
                    $billable     = (int)$c['billable'];
                ?>
                    <!-- Main row -->
                    <tr class="entry-row" id="crow-<?= (int)$c['id'] ?>" data-projects="<?= $projectsJson ?>">
                        <td style="width:32px;text-align:center"><input type="checkbox" class="row-check" value="<?= (int)$c['id'] ?>"></td>
                        <td id="cname-<?= (int)$c['id'] ?>">
                            <span class="cname-main"><?= h($c['name']) ?></span>
                            <?php if (!empty($c['billing_name'])): ?><br><span class="cname-company"><?= h($c['billing_name']) ?></span><?php endif; ?>
                        </td>
                        <td id="clastname-<?= (int)$c['id'] ?>" style="font-size:12px"><?= h($c['contact_last_name'] ?? '') ?></td>
                        <td id="cemail-<?= (int)$c['id'] ?>" style="font-size:12px;color:var(--text-muted)"><?= h($c['billing_email'] ?? '') ?></td>
                        <td>
                            <label class="toggle-switch" title="<?= h($c['active'] ? t('customers.toggleActive') : t('customers.toggleInactive')) ?>">
                                <input type="checkbox" id="ctoggle-<?= (int)$c['id'] ?>"
                                       onchange="toggleCustomer(<?= (int)$c['id'] ?>)"
                                       <?= $c['active'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </td>
                        <td>
                            <span class="badge <?= $billable ? 'badge--active' : 'badge--inactive' ?>" id="cbillable-<?= (int)$c['id'] ?>">
                                <?= h($billable ? t('common.yes') : t('common.no')) ?>
                            </span>
                        </td>
                        <td id="cprojectnames-<?= (int)$c['id'] ?>" style="color:var(--text-muted);font-size:12px"><?= h($projectNames) ?></td>
                        <td id="crate-<?= (int)$c['id'] ?>" style="white-space:nowrap;font-size:12px">
                            <?php if ($rate !== ''): ?>
                                <?= h($rate) ?> €
                            <?php else: ?>
                                <span style="color:var(--text-muted)"><?= h(t('customers.default')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn-icon" title="<?= h(t('common.edit')) ?>" onclick="openEdit(<?= (int)$c['id'] ?>)">
                                <svg viewBox="0 0 24 24" width="15" height="15"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="9" class="empty-message"><?= h(t('customers.empty')) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Bulk action bar -->
        <div id="bulkBar" class="bulk-bar hidden">
            <div id="bulkNormal" class="bulk-normal">
                <span id="bulkCount"></span>
                <button class="btn btn--danger" id="bulkDeleteBtn" type="button"><?= h(t('common.delete')) ?></button>
            </div>
            <div id="bulkConfirm" class="bulk-confirm hidden">
                <span id="bulkConfirmText"></span>
                <button class="btn btn--danger" id="bulkConfirmYes" type="button"><?= h(t('customers.confirmYesDelete')) ?></button>
                <button class="btn" id="bulkConfirmNo" type="button"><?= h(t('common.cancel')) ?></button>
            </div>
        </div>
    </div>

    <div class="admin-section hidden" id="customerEditSection">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
            <h2 id="editTitle" style="margin:0"><?= h(t('customers.editTitle')) ?></h2>
            <button class="btn" type="button" onclick="closeEdit()"><?= h(t('customers.backToList')) ?></button>
        </div>
        <div id="editMsg" style="margin-bottom:12px"></div>
        <div class="stamm-grid">
            <div><label><?= h(t('customers.fName')) ?></label><input type="text" id="e-name" maxlength="255"></div>
            <div><label><?= h(t('customers.fCompany')) ?></label><input type="text" id="e-billing-name" maxlength="255"></div>
            <div class="full"><label><?= h(t('customers.fStreet')) ?></label><input type="text" id="e-street" maxlength="255"></div>
            <div><label><?= h(t('customers.fZip')) ?></label><input type="text" id="e-zip" maxlength="20"></div>
            <div><label><?= h(t('customers.fCity')) ?></label><input type="text" id="e-city" maxlength="100"></div>
            <div><label><?= h(t('customers.fEmail')) ?></label><input type="email" id="e-email" maxlength="255"></div>
            <div class="full"><label><?= h(t('customers.fEmailCc')) ?></label><input type="text" id="e-email-cc" maxlength="500" placeholder="kopie1@example.com, kopie2@example.com"></div>
            <div><label><?= h(t('customers.fTaxId')) ?></label><input type="text" id="e-tax-id" maxlength="50"></div>
            <div><label><?= h(t('customers.fLandline')) ?></label><input type="text" id="e-phone-landline" maxlength="50"></div>
            <div><label><?= h(t('customers.fMobile')) ?></label><input type="text" id="e-phone-mobile" maxlength="50"></div>
            <div><label><?= h(t('customers.fContactFirst')) ?></label><input type="text" id="e-contact-first" maxlength="100"></div>
            <div><label><?= h(t('customers.fContactLast')) ?></label><input type="text" id="e-contact-last" maxlength="100"></div>
            <div><label><?= h(t('customers.fRate')) ?></label><input type="text" id="e-rate" placeholder="<?= h(number_format((float)cfg('invoice_hourly_rate', '85.00'), 2, ',', '.')) ?>"></div>
            <div class="full" style="display:flex;align-items:center;gap:8px;padding-top:4px">
                <input type="checkbox" id="e-contact-on-invoice">
                <label for="e-contact-on-invoice" style="margin:0;cursor:pointer"><?= h(t('customers.fContactOnInvoice')) ?></label>
            </div>
            <div class="full" style="display:flex;align-items:center;gap:8px;padding-top:4px">
                <input type="checkbox" id="e-billable">
                <label for="e-billable" style="margin:0;cursor:pointer"><?= h(t('customers.fBillable')) ?></label>
            </div>
            <div class="full">
                <label><?= h(t('customers.fInvoiceMode')) ?></label>
                <select id="e-invoice-mode" onchange="toggleInvoiceMode()">
                    <option value="entries"><?= h(t('customers.invoiceModeEntries')) ?></option>
                    <option value="text"><?= h(t('customers.invoiceModeText')) ?></option>
                </select>
            </div>
            <div class="full" id="e-invoice-text-wrap">
                <label><?= h(t('customers.fInvoiceText')) ?></label>
                <button type="button" class="btn" style="margin-bottom:6px" onclick="applyGlobalInvoiceText()"><?= h(t('customers.applyGlobalTemplate')) ?></button>
                <textarea id="e-invoice-text" rows="3" maxlength="1000"
                          style="width:100%;padding:7px 10px;border:1px solid var(--card-border);border-radius:var(--radius);font-family:var(--font);font-size:13px;resize:vertical;color:var(--text)"></textarea>
            </div>
            <div class="full">
                <label><?= h(t('customers.fMailHtml')) ?></label>
                <button type="button" class="btn" style="margin-bottom:6px" onclick="applyGlobalMailHtml()"><?= h(t('customers.applyGlobalTemplate')) ?></button>
                <div class="rte-wrap">
                    <div class="rte-toolbar">
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('bold')"><b>B</b></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('italic')"><em>I</em></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('underline')"><u>U</u></button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="rteLink(this)">Link</button>
                        <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.execCommand('removeFormat')" title="<?= h(t('orders.removeFormat')) ?>">&#10005;</button>
                    </div>
                    <div class="rte-body" id="e-mail-tmpl-html" contenteditable="true"></div>
                </div>
            </div>
        </div>
        <h3 style="margin:20px 0 10px;font-size:14px;font-weight:600"><?= h(t('customers.projects')) ?></h3>
        <ul class="project-list" id="e-plist"></ul>
        <div class="project-add-row">
            <input type="text" id="e-pnew" placeholder="<?= h(t('customers.newProject')) ?>" maxlength="255">
            <button class="btn" type="button" onclick="editAddProject()"><?= h(t('customers.add')) ?></button>
        </div>

        <h3 style="margin:20px 0 6px;font-size:14px;font-weight:600"><?= h(t('customers.autoRules')) ?></h3>
        <p class="rule-help">
            <?= h(t('customers.autoRulesHelp')) ?>
        </p>
        <ul class="rule-list" id="e-rlist"></ul>
        <div class="rule-add-row">
            <select id="e-rnew-act">
                <option value=""><?= h(t('customers.chooseActivity')) ?></option>
                <?php foreach (getActivities(db()) as $act): ?>
                <option value="<?= h($act) ?>"><?= h($act) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="e-rnew-cmt" placeholder="<?= h(t('customers.commentOptional')) ?>" maxlength="255">
            <button class="btn" type="button" onclick="editAddRule()"><?= h(t('customers.add')) ?></button>
        </div>

        <h3 style="margin:24px 0 6px;font-size:14px;font-weight:600"><?= h(t('customers.markExisting')) ?></h3>
        <p class="rule-help">
            <?= h(t('customers.markExistingHelp')) ?>
        </p>
        <div class="rule-add-row">
            <select id="e-search-act">
                <option value=""><?= h(t('customers.chooseActivity')) ?></option>
                <?php foreach (getActivities(db()) as $act): ?>
                <option value="<?= h($act) ?>"><?= h($act) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="e-search-cmt" placeholder="<?= h(t('customers.commentOptional')) ?>" maxlength="255">
            <button class="btn" type="button" onclick="editSearchEntries()"><?= h(t('customers.search')) ?></button>
        </div>
        <div id="e-search-result" class="search-result hidden"></div>

        <div style="display:flex;gap:8px;margin-top:20px">
            <button class="btn btn--primary" type="button" onclick="saveFullCustomer()"><?= h(t('common.save')) ?></button>
            <button class="btn" type="button" onclick="closeEdit()"><?= h(t('common.cancel')) ?></button>
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
let pidCounter   = Date.now();
let editCid      = null;
let editProjects = [];
let editRules    = [];
let ridCounter   = Date.now();

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
    el.className   = 'admin-msg ' + (ok ? 'admin-msg--ok' : 'admin-msg--err');
    el.textContent = text;
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleInvoiceMode() {
    const mode = document.getElementById('e-invoice-mode').value;
    document.getElementById('e-invoice-text-wrap').style.display = mode === 'text' ? '' : 'none';
}

function rteLink(btn) {
    const body = btn.closest('.rte-wrap').querySelector('.rte-body');
    const sel  = window.getSelection();
    let range  = sel && sel.rangeCount > 0 ? sel.getRangeAt(0).cloneRange() : null;
    const url  = prompt(t('customers.urlPrompt'), 'https://');
    if (!url) return;
    body.focus();
    if (range) { sel.removeAllRanges(); sel.addRange(range); }
    document.execCommand('createLink', false, url);
}

/* ================================================================
   EDIT VIEW
   ================================================================ */
async function openEdit(cid) {
    editCid = cid;
    const res = await api('get_customer', { id: cid });
    if (!res.success) { Dialog.alert(t('customers.loadError')); return; }
    const c = res.data;

    document.getElementById('editTitle').textContent         = t('customers.editTitleName', { name: c.name });
    document.getElementById('e-name').value                  = c.name               || '';
    document.getElementById('e-billing-name').value          = c.billing_name        || '';
    document.getElementById('e-street').value                = c.billing_street      || '';
    document.getElementById('e-zip').value                   = c.billing_zip         || '';
    document.getElementById('e-city').value                  = c.billing_city        || '';
    document.getElementById('e-email').value                 = c.billing_email       || '';
    document.getElementById('e-email-cc').value              = c.billing_email_cc    || '';
    document.getElementById('e-tax-id').value                = c.billing_tax_id      || '';
    document.getElementById('e-phone-landline').value        = c.phone_landline      || '';
    document.getElementById('e-phone-mobile').value          = c.phone_mobile        || '';
    document.getElementById('e-contact-first').value         = c.contact_first_name  || '';
    document.getElementById('e-contact-last').value          = c.contact_last_name   || '';
    document.getElementById('e-contact-on-invoice').checked  = !!parseInt(c.contact_on_invoice);
    document.getElementById('e-billable').checked            = !!parseInt(c.billable);
    document.getElementById('e-rate').value = (c.hourly_rate !== null && c.hourly_rate !== '')
        ? parseFloat(c.hourly_rate).toFixed(2).replace('.', ',') : '';
    document.getElementById('e-invoice-mode').value       = c.invoice_mode       || 'entries';
    document.getElementById('e-invoice-text').value       = c.invoice_text       || '';
    document.getElementById('e-mail-tmpl-html').innerHTML = c.mail_template_html  || '';
    toggleInvoiceMode();

    try { editProjects = JSON.parse(c.projects || '[]'); } catch(e) { editProjects = []; }
    if (!Array.isArray(editProjects)) editProjects = [];
    renderEditProjectList();

    editRules = Array.isArray(c.billing_rules) ? c.billing_rules.map(function(r) {
        return { id: String(++ridCounter), activity: r.activity || '', comment: r.comment || '' };
    }) : [];
    renderEditRuleList();

    document.getElementById('e-search-act').value = '';
    document.getElementById('e-search-cmt').value = '';
    const sres = document.getElementById('e-search-result');
    sres.classList.add('hidden');
    sres.innerHTML = '';

    document.getElementById('editMsg').className   = '';
    document.getElementById('editMsg').textContent = '';
    document.getElementById('e-pnew').value        = '';
    document.getElementById('customerListSection').classList.add('hidden');
    document.getElementById('customerEditSection').classList.remove('hidden');
    window.scrollTo(0, 0);
}

function closeEdit() {
    document.getElementById('customerEditSection').classList.add('hidden');
    document.getElementById('customerListSection').classList.remove('hidden');
}

/* Allgemeine (globale) Mailvorlagen aus der Konfiguration – koennen per
   Button in die Kundenfelder uebernommen werden. */
const GLOBAL_INVOICE_TEXT = <?= json_encode(cfg('invoice_text_template', ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const GLOBAL_MAIL_HTML  = <?= json_encode(cfg('invoice_mail_template_html', ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

async function applyGlobalInvoiceText() {
    if (!GLOBAL_INVOICE_TEXT.trim()) { Dialog.alert(t('customers.applyGlobalEmpty')); return; }
    const el = document.getElementById('e-invoice-text');
    if (el.value.trim() !== '' && !await Dialog.confirm(t('customers.applyGlobalConfirm'))) return;
    el.value = GLOBAL_INVOICE_TEXT;
}

async function applyGlobalMailHtml() {
    if (!GLOBAL_MAIL_HTML.trim()) { Dialog.alert(t('customers.applyGlobalEmpty')); return; }
    const el = document.getElementById('e-mail-tmpl-html');
    if (el.innerHTML.trim() !== '' && !await Dialog.confirm(t('customers.applyGlobalConfirm'))) return;
    el.innerHTML = GLOBAL_MAIL_HTML;
}

function renderEditProjectList() {
    const ul = document.getElementById('e-plist');
    if (editProjects.length === 0) {
        ul.innerHTML = '<li><span class="project-name" style="color:var(--text-muted)">' + escHtml(t('customers.noProjects')) + '</span></li>';
        return;
    }
    ul.innerHTML = '';
    editProjects.forEach(function(p) {
        const li = document.createElement('li');
        li.id = 'epitem-' + p.id;
        li.innerHTML =
            '<span class="project-name" id="epname-' + p.id + '">' + escHtml(p.name) + '</span>' +
            '<input type="text" class="project-edit-input hidden" id="epinput-' + p.id + '" value="' + escHtml(p.name) + '" maxlength="255">' +
            '<button class="btn-icon" title="' + escHtml(t('customers.rename')) + '" onclick="editStartRename(\'' + p.id + '\')">' +
                '<svg viewBox="0 0 24 24" width="13" height="13"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>' +
            '</button>' +
            '<button class="btn-icon btn-icon--confirm hidden" id="epsave-' + p.id + '" title="' + escHtml(t('common.save')) + '" onclick="editConfirmRename(\'' + p.id + '\')">' +
                '<svg viewBox="0 0 24 24" width="13" height="13"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>' +
            '</button>' +
            '<button class="btn-icon btn-icon--danger" title="' + escHtml(t('customers.deleteProject')) + '" onclick="editDeleteProject(\'' + p.id + '\')">' +
                '<svg viewBox="0 0 24 24" width="13" height="13"><path d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>' +
            '</button>';
        ul.appendChild(li);
    });
}

function editStartRename(pid) {
    document.getElementById('epname-'  + pid).classList.add('hidden');
    document.getElementById('epinput-' + pid).classList.remove('hidden');
    document.getElementById('epsave-'  + pid).classList.remove('hidden');
    document.getElementById('epinput-' + pid).focus();
}

function editConfirmRename(pid) {
    const input = document.getElementById('epinput-' + pid);
    const name  = input.value.trim();
    if (!name) return;
    const p = editProjects.find(function(x) { return String(x.id) === String(pid); });
    if (p) p.name = name;
    document.getElementById('epname-'  + pid).textContent = name;
    document.getElementById('epname-'  + pid).classList.remove('hidden');
    document.getElementById('epinput-' + pid).classList.add('hidden');
    document.getElementById('epsave-'  + pid).classList.add('hidden');
}

function editAddProject() {
    const input = document.getElementById('e-pnew');
    const name  = input.value.trim();
    if (!name) return;
    editProjects.push({ id: String(++pidCounter), name: name });
    input.value = '';
    renderEditProjectList();
}

function editDeleteProject(pid) {
    editProjects = editProjects.filter(function(p) { return String(p.id) !== String(pid); });
    renderEditProjectList();
}

/* -------- Nicht-berechenbar Regeln -------- */
function renderEditRuleList() {
    const ul = document.getElementById('e-rlist');
    if (editRules.length === 0) {
        ul.innerHTML = '<li><span class="rule-act" style="color:var(--text-muted);grid-column:1/-1">' + escHtml(t('customers.noRules')) + '</span></li>';
        return;
    }
    ul.innerHTML = '';
    editRules.forEach(function(r) {
        const li = document.createElement('li');
        li.id = 'eritem-' + r.id;
        const cmtCell = r.comment
            ? '<span class="rule-cmt">' + escHtml(r.comment) + '</span>'
            : '<span class="rule-cmt rule-cmt-empty">' + escHtml(t('customers.emptyComment')) + '</span>';
        li.innerHTML =
            '<span class="rule-act">' + escHtml(r.activity) + '</span>' +
            cmtCell +
            '<button class="btn-icon btn-icon--danger" title="' + escHtml(t('customers.deleteRule')) + '" onclick="editDeleteRule(\'' + r.id + '\')">' +
                '<svg viewBox="0 0 24 24" width="13" height="13"><path d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>' +
            '</button>';
        ul.appendChild(li);
    });
}

function editAddRule() {
    const actEl = document.getElementById('e-rnew-act');
    const cmtEl = document.getElementById('e-rnew-cmt');
    const act   = actEl.value.trim();
    const cmt   = cmtEl.value.trim();
    if (!act) { actEl.focus(); return; }

    const dup = editRules.some(function(r) {
        return r.activity.toLowerCase() === act.toLowerCase()
            && (r.comment || '').toLowerCase() === cmt.toLowerCase();
    });
    if (dup) {
        showMsg(document.getElementById('editMsg'), t('customers.ruleExists'), false);
        return;
    }

    editRules.push({ id: String(++ridCounter), activity: act, comment: cmt });
    actEl.value = '';
    cmtEl.value = '';
    renderEditRuleList();
}

function editDeleteRule(rid) {
    editRules = editRules.filter(function(r) { return String(r.id) !== String(rid); });
    renderEditRuleList();
}

/* -------- Bestehende Einträge nachträglich markieren -------- */
function fmtDateDe(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return iso;
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return dd + '.' + mm + '.' + d.getFullYear();
}
function fmtHoursMin(min) {
    const h = Math.floor(min / 60);
    const m = min % 60;
    return h + ':' + String(m).padStart(2, '0') + ' h';
}

async function editSearchEntries() {
    const actEl = document.getElementById('e-search-act');
    const cmtEl = document.getElementById('e-search-cmt');
    const resEl = document.getElementById('e-search-result');
    const act   = actEl.value.trim();
    const cmt   = cmtEl.value.trim();

    if (!act) { actEl.focus(); return; }

    resEl.classList.remove('hidden');
    resEl.innerHTML = '<span class="search-empty">' + escHtml(t('customers.searching')) + '</span>';

    try {
        const data = await api('find_unbilled_matches', {
            customer_id: editCid,
            activity:    act,
            comment:     cmt,
        });
        if (!data.success) {
            resEl.innerHTML = '<span class="search-empty">' + escHtml(t('common.error') + ': ' + (data.error || t('customers.searchFailed'))) + '</span>';
            return;
        }

        const d = data.data;
        if (d.count === 0) {
            resEl.innerHTML = '<span class="search-empty">' + escHtml(t('customers.noMatches')) + '</span>';
            return;
        }

        let html =
            '<h4>' + escHtml(t(d.count === 1 ? 'customers.entriesFoundOne' : 'customers.entriesFoundMany',
                { n: d.count, min: fmtHoursMin(d.minutes) })) + '</h4>' +
            '<table><thead><tr>' +
                '<th>' + escHtml(t('customers.colDate')) + '</th><th>' + escHtml(t('common.activity')) +
                '</th><th>' + escHtml(t('customers.colComment')) + '</th><th class="right">' +
                escHtml(t('customers.colDuration')) + '</th>' +
            '</tr></thead><tbody>';

        d.preview.forEach(function(e) {
            html += '<tr>' +
                '<td>' + escHtml(fmtDateDe(e.start_datetime)) + '</td>' +
                '<td>' + escHtml(e.activity) + '</td>' +
                '<td>' + escHtml(e.comment || '—') + '</td>' +
                '<td class="right">' + escHtml(fmtHoursMin(parseInt(e.duration_minutes))) + '</td>' +
            '</tr>';
        });
        html += '</tbody></table>';

        if (d.count > d.preview.length) {
            html += '<p class="search-empty">' + escHtml(t('customers.andMore', { n: d.count - d.preview.length })) + '</p>';
        }

        html += '<div class="search-result-actions">' +
            '<button class="btn btn--primary" type="button" onclick="editConvertEntries(' +
                "'" + act.replace(/'/g, "\\'") + "', '" + cmt.replace(/'/g, "\\'") + "'" +
            ')">' + escHtml(t('customers.convertNow')) + '</button>' +
            '<button class="btn" type="button" onclick="document.getElementById(\'e-search-result\').classList.add(\'hidden\')">' + escHtml(t('orders.close')) + '</button>' +
        '</div>';

        resEl.innerHTML = html;
    } catch(err) {
        resEl.innerHTML = '<span class="search-empty">' + escHtml(t('config.serverError')) + '</span>';
    }
}

async function editConvertEntries(act, cmt) {
    const resEl = document.getElementById('e-search-result');
    if (!await Dialog.confirm(t('customers.confirmConvert'))) return;

    try {
        const data = await api('mark_entries_unbillable', {
            customer_id: editCid,
            activity:    act,
            comment:     cmt,
        });
        if (data.success) {
            resEl.innerHTML = '<span style="color:var(--success)">' +
                escHtml(t(data.data.updated === 1 ? 'customers.convertedOne' : 'customers.convertedMany',
                    { n: data.data.updated })) + '</span>';
            document.getElementById('e-search-cmt').value = '';
        } else {
            resEl.innerHTML = '<span class="search-empty">' + escHtml(t('common.error') + ': ' + (data.error || t('customers.actionFailed'))) + '</span>';
        }
    } catch(err) {
        resEl.innerHTML = '<span class="search-empty">' + escHtml(t('config.serverError')) + '</span>';
    }
}

async function saveFullCustomer() {
    const msgEl            = document.getElementById('editMsg');
    const billable         = document.getElementById('e-billable').checked;
    const contactOnInvoice = document.getElementById('e-contact-on-invoice').checked;
    const params = {
        id:                 editCid,
        name:               document.getElementById('e-name').value.trim(),
        billing_name:       document.getElementById('e-billing-name').value.trim(),
        billing_street:     document.getElementById('e-street').value.trim(),
        billing_zip:        document.getElementById('e-zip').value.trim(),
        billing_city:       document.getElementById('e-city').value.trim(),
        billing_email:      document.getElementById('e-email').value.trim(),
        billing_email_cc:   document.getElementById('e-email-cc').value.trim(),
        billing_tax_id:     document.getElementById('e-tax-id').value.trim(),
        phone_landline:     document.getElementById('e-phone-landline').value.trim(),
        phone_mobile:       document.getElementById('e-phone-mobile').value.trim(),
        contact_first_name: document.getElementById('e-contact-first').value.trim(),
        contact_last_name:  document.getElementById('e-contact-last').value.trim(),
        contact_on_invoice: contactOnInvoice ? '1' : '0',
        hourly_rate:        document.getElementById('e-rate').value.trim(),
        billable:           billable ? '1' : '0',
        invoice_mode:        document.getElementById('e-invoice-mode').value,
        invoice_text:        document.getElementById('e-invoice-text').value.trim(),
        mail_template_html:  document.getElementById('e-mail-tmpl-html').innerHTML,
    };
    try {
        const stampRes = await api('update_customer_billing', params);
        if (!stampRes.success) { showMsg(msgEl, stampRes.error || t('common.saveError'), false); return; }

        const projRes = await api('save_customer_projects', {
            id: editCid, projects: JSON.stringify(editProjects)
        });
        if (!projRes.success) { showMsg(msgEl, projRes.error || t('customers.saveProjectsError'), false); return; }

        const rulesPayload = editRules.map(function(r) {
            return { activity: r.activity, comment: r.comment };
        });
        const ruleRes = await api('save_customer_rules', {
            id: editCid, rules: JSON.stringify(rulesPayload)
        });
        if (!ruleRes.success) { showMsg(msgEl, ruleRes.error || t('customers.saveRulesError'), false); return; }

        const nameCell = document.getElementById('cname-' + editCid);
        if (nameCell) {
            nameCell.innerHTML = '<span class="cname-main">' + escHtml(stampRes.data.name) + '</span>' +
                (params.billing_name ? '<br><span class="cname-company">' + escHtml(params.billing_name) + '</span>' : '');
        }
        const lastnameCell = document.getElementById('clastname-' + editCid);
        if (lastnameCell) lastnameCell.textContent = params.contact_last_name;
        const emailCell = document.getElementById('cemail-' + editCid);
        if (emailCell) emailCell.textContent = params.billing_email;
        const rateCell = document.getElementById('crate-' + editCid);
        if (rateCell) {
            rateCell.innerHTML = params.hourly_rate !== ''
                ? escHtml(params.hourly_rate) + ' €'
                : '<span style="color:var(--text-muted)">' + escHtml(t('customers.default')) + '</span>';
        }
        const billBadge = document.getElementById('cbillable-' + editCid);
        if (billBadge) {
            billBadge.className   = 'badge ' + (billable ? 'badge--active' : 'badge--inactive');
            billBadge.textContent = billable ? t('common.yes') : t('common.no');
        }
        const projNames = document.getElementById('cprojectnames-' + editCid);
        if (projNames) projNames.textContent = editProjects.map(function(p) { return p.name; }).join(', ');

        closeEdit();
        showMsg(document.getElementById('addMsg'), t('common.saved'), true);
    } catch(e) {
        showMsg(msgEl, t('config.serverErrorRetry'), false);
    }
}

/* ================================================================
   ADD CUSTOMER
   ================================================================ */
document.getElementById('addBtn').addEventListener('click', async function() {
    const input = document.getElementById('newCustomerName');
    const msgEl = document.getElementById('addMsg');
    const name  = input.value.trim();
    if (!name) { showMsg(msgEl, t('customers.enterName'), false); return; }

    const btn = document.getElementById('addBtn');
    btn.disabled = true;
    try {
        const data = await api('add_customer', { name: name });
        if (data.success) {
            const tbody = document.querySelector('#customerTable tbody');
            const emptyRow = tbody.querySelector('.empty-message');
            if (emptyRow) emptyRow.closest('tr').remove();

            const id = data.data.id;
            tbody.insertAdjacentHTML('beforeend',
                '<tr class="entry-row" id="crow-' + id + '" data-projects="[]">' +
                    '<td style="width:32px;text-align:center"><input type="checkbox" class="row-check" value="' + id + '"></td>' +
                    '<td id="cname-' + id + '"><span class="cname-main">' + escHtml(data.data.name) + '</span></td>' +
                    '<td id="clastname-' + id + '" style="font-size:12px"></td>' +
                    '<td id="cemail-' + id + '" style="font-size:12px;color:var(--text-muted)"></td>' +
                    '<td><label class="toggle-switch" title="' + escHtml(t('customers.toggleActive')) + '"><input type="checkbox" id="ctoggle-' + id + '" onchange="toggleCustomer(' + id + ')" checked><span class="toggle-slider"></span></label></td>' +
                    '<td><span class="badge badge--active" id="cbillable-' + id + '">' + escHtml(t('common.yes')) + '</span></td>' +
                    '<td id="cprojectnames-' + id + '" style="color:var(--text-muted);font-size:12px"></td>' +
                    '<td id="crate-' + id + '" style="white-space:nowrap;font-size:12px"><span style="color:var(--text-muted)">' + escHtml(t('customers.default')) + '</span></td>' +
                    '<td style="white-space:nowrap">' +
                        '<button class="btn-icon" title="' + escHtml(t('common.edit')) + '" onclick="openEdit(' + id + ')">' +
                            '<svg viewBox="0 0 24 24" width="15" height="15"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>' +
                        '</button>' +
                    '</td>' +
                '</tr>'
            );
            input.value = '';
            showMsg(msgEl, t('customers.created'), true);
        } else {
            showMsg(msgEl, data.error || t('customers.createError'), false);
        }
    } catch(e) {
        showMsg(msgEl, t('config.serverErrorRetry'), false);
    }
    btn.disabled = false;
});

/* ================================================================
   SEARCH & SORT
   ================================================================ */
let sortCol = -1;
let sortDir = 1;

function getCellValue(entryRow, col) {
    const id = entryRow.id.replace('crow-', '');
    switch (col) {
        case 0: return (document.getElementById('cname-'     + id)?.querySelector('.cname-main')?.textContent || '').trim().toLowerCase();
        case 1: return (document.getElementById('clastname-' + id)?.textContent || '').trim().toLowerCase();
        case 2: return (document.getElementById('cemail-'    + id)?.textContent || '').trim().toLowerCase();
        case 3: return document.getElementById('ctoggle-' + id)?.checked ? 'aktiv' : 'inaktiv';
        case 4: return (document.getElementById('cbillable-'     + id)?.textContent || '').trim().toLowerCase();
        case 5: return (document.getElementById('cprojectnames-' + id)?.textContent || '').trim().toLowerCase();
        case 6: {
            const t = (document.getElementById('crate-' + id)?.textContent || '').replace(/[^\d,]/g, '').replace(',', '.');
            return parseFloat(t) || -1;
        }
        default: return '';
    }
}

function sortTable(col) {
    if (sortCol === col) { sortDir *= -1; } else { sortCol = col; sortDir = 1; }
    document.querySelectorAll('#customerTable thead th.sortable').forEach(function(th) {
        const icon = th.querySelector('.sort-icon');
        if (parseInt(th.dataset.col) === col) {
            icon.textContent = sortDir === 1 ? '▲' : '▼';
            th.classList.add('sorted');
        } else {
            icon.textContent = '';
            th.classList.remove('sorted');
        }
    });
    const tbody = document.querySelector('#customerTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr.entry-row'));
    rows.sort(function(a, b) {
        const va = getCellValue(a, col);
        const vb = getCellValue(b, col);
        if (typeof va === 'number') return (va - vb) * sortDir;
        return va < vb ? -sortDir : va > vb ? sortDir : 0;
    });
    rows.forEach(function(row) {
        tbody.appendChild(row);
    });
    applySearch();
}

function applySearch() {
    const term   = document.getElementById('customerSearch').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    document.querySelectorAll('#customerTable tbody tr.entry-row').forEach(function(row) {
        const id      = row.id.replace('crow-', '');
        const active  = document.getElementById('ctoggle-' + id)?.checked;
        const matchSearch = !term   || row.textContent.toLowerCase().includes(term);
        const matchStatus = status === '' || (status === '1' ? active : !active);
        const visible = matchSearch && matchStatus;
        if (!visible) {
            row.style.display = 'none';
        } else {
            row.style.display = '';
        }
    });
    updateSelectAll();
}

function resetTable() {
    document.getElementById('customerSearch').value = '';
    document.getElementById('statusFilter').value   = '1';
    sortCol = -1; sortDir = 1;
    document.querySelectorAll('#customerTable thead th.sortable').forEach(function(th) {
        th.querySelector('.sort-icon').textContent = '';
        th.classList.remove('sorted');
    });
    document.querySelectorAll('#customerTable tbody tr').forEach(function(r) { r.style.display = ''; });
    updateSelectAll();
}

/* ================================================================
   BULK DELETE
   ================================================================ */
function updateBulkBar() {
    const n   = document.querySelectorAll('.row-check:checked').length;
    const bar = document.getElementById('bulkBar');
    if (n === 0) {
        bar.classList.add('hidden');
        resetBulkConfirm();
    } else {
        bar.classList.remove('hidden');
        document.getElementById('bulkCount').textContent =
            t(n === 1 ? 'customers.selectedOne' : 'customers.selectedMany', { n: n });
        document.getElementById('bulkConfirmText').textContent =
            t(n === 1 ? 'customers.confirmDeleteOne' : 'customers.confirmDeleteMany', { n: n });
    }
}

function resetBulkConfirm() {
    document.getElementById('bulkNormal').classList.remove('hidden');
    document.getElementById('bulkConfirm').classList.add('hidden');
}

function updateSelectAll() {
    const visibleRows = Array.from(document.querySelectorAll('#customerTable tbody tr.entry-row'))
        .filter(function(r) { return r.style.display !== 'none'; });
    const checkedCount = visibleRows.filter(function(r) {
        return r.querySelector('.row-check')?.checked;
    }).length;
    const sa = document.getElementById('selectAll');
    sa.indeterminate = checkedCount > 0 && checkedCount < visibleRows.length;
    sa.checked       = visibleRows.length > 0 && checkedCount === visibleRows.length;
}

document.getElementById('selectAll').addEventListener('change', function() {
    const checked = this.checked;
    document.querySelectorAll('#customerTable tbody tr.entry-row').forEach(function(row) {
        if (row.style.display !== 'none') {
            row.querySelector('.row-check').checked = checked;
        }
    });
    updateBulkBar();
    updateSelectAll();
});

document.querySelector('#customerTable tbody').addEventListener('change', function(e) {
    if (e.target.classList.contains('row-check')) {
        updateBulkBar();
        updateSelectAll();
    }
});

document.getElementById('bulkDeleteBtn').addEventListener('click', function() {
    document.getElementById('bulkNormal').classList.add('hidden');
    document.getElementById('bulkConfirm').classList.remove('hidden');
});

document.getElementById('bulkConfirmNo').addEventListener('click', resetBulkConfirm);

document.getElementById('bulkConfirmYes').addEventListener('click', async function() {
    const ids = Array.from(document.querySelectorAll('.row-check:checked'))
        .map(function(cb) { return cb.value; }).join(',');
    try {
        const data = await api('delete_customers', { ids });
        if (data.success) {
            Array.from(document.querySelectorAll('.row-check:checked')).forEach(function(cb) {
                const el = document.getElementById('crow-' + cb.value);
                if (el) el.remove();
            });
            const tbody = document.querySelector('#customerTable tbody');
            if (!tbody.querySelector('tr.entry-row')) {
                tbody.innerHTML = '<tr><td colspan="8" class="empty-message">' + escHtml(t('customers.empty')) + '</td></tr>';
            }
            updateBulkBar();
            updateSelectAll();
            showMsg(document.getElementById('addMsg'),
                t(data.data.deleted === 1 ? 'customers.deletedOne' : 'customers.deletedMany', { n: data.data.deleted }), true);
        } else {
            showMsg(document.getElementById('addMsg'), data.error || t('customers.deleteError'), false);
            resetBulkConfirm();
        }
    } catch(e) {
        showMsg(document.getElementById('addMsg'), t('config.serverErrorRetry'), false);
        resetBulkConfirm();
    }
});

document.getElementById('customerSearch').addEventListener('input', applySearch);
document.getElementById('statusFilter').addEventListener('change', applySearch);
applySearch();
document.querySelectorAll('#customerTable thead th.sortable').forEach(function(th) {
    th.addEventListener('click', function() { sortTable(parseInt(th.dataset.col)); });
});

/* Direktaufruf von anderen Seiten (z. B. Abrechnung): ?edit=<id> oeffnet
   das Bearbeitungsformular des Kunden sofort. */
(function() {
    const editId = parseInt(new URLSearchParams(window.location.search).get('edit') || '', 10);
    if (editId > 0) { openEdit(editId); }
})();

/* ================================================================
   TOGGLE ACTIVE
   ================================================================ */
async function toggleCustomer(id) {
    const toggle = document.getElementById('ctoggle-' + id);
    try {
        const data = await api('toggle_customer', { id: id });
        if (!data.success) {
            toggle.checked = !toggle.checked;
        }
    } catch(e) {
        toggle.checked = !toggle.checked;
    }
}
</script>
</body>
</html>
