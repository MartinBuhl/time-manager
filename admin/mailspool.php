<?php
require_once __DIR__ . '/auth.php';

$filter = $_GET['filter'] ?? 'active';
$validFilters = ['active', 'archived'];
if (!in_array($filter, $validFilters, true)) $filter = 'active';

$where = 'WHERE m.archived_at IS NULL';
if ($filter === 'archived') $where = 'WHERE m.archived_at IS NOT NULL';

$stmt = db()->query(
    "SELECT m.id, m.invoice_id, m.subject, m.recipient, m.pdf_file,
            m.html_body, m.text_body,
            m.spooled_at, m.sent_at,
            i.invoice_number, c.name AS customer_name, c.billing_email_cc
     FROM tm_mail_spool m
     LEFT JOIN tm_invoices  i ON i.id = m.invoice_id
     LEFT JOIN tm_customers c ON c.id = i.customer_id
     $where
     ORDER BY m.spooled_at DESC"
);
$mails = $stmt->fetchAll();

$counts = db()->query(
    "SELECT
         SUM(CASE WHEN archived_at IS NULL THEN 1 ELSE 0 END)     AS active,
         SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END)  AS archived
     FROM tm_mail_spool"
)->fetch();

function fmtDt($dt) {
    if (!$dt) return '';
    return date('d.m.Y H:i', strtotime($dt));
}
?><!DOCTYPE html>
<html lang="<?= h(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('mailspool.pageTitle')) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<script src="../assets/dialog.js"></script>
<style>
.mail-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 16px;
}
.mail-tabs a {
    padding: 6px 14px;
    border: 1px solid var(--card-border);
    border-radius: 4px;
    text-decoration: none;
    color: var(--text);
    background: var(--card-bg);
    font-size: 13px;
}
.mail-tabs a.active {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
.status-pending { color: #e67e22; font-weight: 600; }
.status-sent    { color: #27ae60; font-weight: 600; }
.preview-cell {
    font-size: 12px;
    color: var(--text-muted);
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.modal-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.65);
    z-index: 1000;
    align-items: center; justify-content: center;
}
.modal-backdrop.show { display: flex; }
.modal {
    background: var(--card-bg);
    color: var(--text);
    border-radius: 6px;
    max-width: 760px;
    width: 92%;
    max-height: 86vh;
    overflow: auto;
    padding: 20px 24px;
}
.modal h2 { margin-top: 0; font-size: 16px; }
.modal-row { margin-bottom: 10px; font-size: 13px; }
.modal-row strong { display: inline-block; min-width: 110px; color: var(--text-muted); }
.modal pre {
    background: #1a1a1a;
    color: #ddd;
    padding: 12px;
    border-radius: 4px;
    font-size: 12px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
}
.bulk-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 10px 14px;
    background: #fff8e1;
    border: 1px solid #ffc107;
    border-radius: var(--radius);
    margin-top: 12px;
    font-size: 13px;
    color: #333;
}
.bulk-bar select { width: auto; background: #fff; color: #1f2937; }
.html-preview-frame {
    width: 100%;
    height: 300px;
    border: 1px solid var(--card-border);
    border-radius: 4px;
    background: #fff;
}
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1><?= h(t('admin.card.mailspool')) ?></h1>
            <div class="admin-breadcrumb">
                <a href="index.php"><?= h(t('admin.title')) ?></a> &rsaquo; <?= h(t('admin.card.mailspool')) ?>
            </div>
        </div>
        <a href="../index.php" class="btn-logout"><?= h(t('admin.toApp')) ?></a>
    </div>

    <div class="admin-section">
        <div class="mail-tabs">
            <a href="mailspool.php" class="<?= $filter === 'active' ? 'active' : '' ?>">
                <?= h(t('mailspool.tabActive')) ?> (<?= (int)$counts['active'] ?>)
            </a>
            <a href="mailspool.php?filter=archived" class="<?= $filter === 'archived' ? 'active' : '' ?>">
                <?= h(t('mailspool.tabArchived')) ?> (<?= (int)$counts['archived'] ?>)
            </a>
        </div>

        <?php if (empty($mails)): ?>
            <p class="empty-message"><?= h(t('mailspool.empty')) ?></p>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th style="width:32px;text-align:center">
                            <input type="checkbox" id="spoolSelectAll" title="<?= h(t('customers.selectAll')) ?>">
                        </th>
                        <th><?= h(t('customers.colStatus')) ?></th>
                        <th><?= h(t('mailspool.colSpooled')) ?></th>
                        <th><?= h(t('mailspool.sent')) ?></th>
                        <th><?= h(t('mailspool.colInvoice')) ?></th>
                        <th><?= h(t('entries.colCustomer')) ?></th>
                        <th><?= h(t('mailspool.colRecipient')) ?></th>
                        <th>PDF</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mails as $m): ?>
                    <tr id="spool-row-<?= (int)$m['id'] ?>">
                        <td style="width:32px;text-align:center">
                            <input type="checkbox" class="spool-check" value="<?= (int)$m['id'] ?>">
                        </td>
                        <td>
                            <?php if ($m['sent_at']): ?>
                                <span class="status-sent"><?= h(t('mailspool.sent')) ?></span>
                            <?php else: ?>
                                <span class="status-pending"><?= h(t('mailspool.statusOpen')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap"><?= h(fmtDt($m['spooled_at'])) ?></td>
                        <td style="white-space:nowrap"><?= h(fmtDt($m['sent_at'])) ?></td>
                        <td><?= h($m['invoice_number'] ?? '') ?></td>
                        <td><?= h($m['customer_name'] ?? '') ?></td>
                        <td>
                            <?= h($m['recipient']) ?>
                            <?php if (!empty($m['billing_email_cc'])): ?>
                                <br><span style="color:var(--text-muted);font-size:11px"><?= h(t('mailspool.copyPrefix', ['cc' => $m['billing_email_cc']])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['pdf_file']): ?>
                                <a href="invoice_download.php?type=pdf&file=<?= urlencode($m['pdf_file']) ?>"
                                   class="btn" style="font-size:11px;padding:2px 8px"
                                   target="_blank" rel="noopener">PDF</a>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <button type="button" class="btn" style="font-size:11px;padding:2px 8px"
                                    onclick="showMail(<?= (int)$m['id'] ?>)"><?= h(t('mailspool.view')) ?></button>
                            <button type="button" class="btn testmail-btn"
                                    data-id="<?= (int)$m['id'] ?>"
                                    style="font-size:11px;padding:2px 8px;margin-left:4px"
                                    title="<?= h(t('mailspool.testmailTitle')) ?>"><?= h(t('mailspool.testmail')) ?></button>
                            <?php if (!$m['sent_at']): ?>
                            <button type="button" class="btn btn--danger unspool-btn"
                                    data-id="<?= (int)$m['id'] ?>"
                                    data-number="<?= h($m['invoice_number'] ?? '') ?>"
                                    style="font-size:11px;padding:2px 8px;margin-left:4px"><?= h(t('invoices.reverse')) ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="spoolBulkBar" class="bulk-bar hidden">
            <span id="spoolBulkCount" style="font-weight:600"></span>
            <select id="spoolAction">
                <option value=""><?= h(t('adminEntries.bulkAction')) ?></option>
                <?php if ($filter === 'active'): ?>
                <option value="send"><?= h(t('mailspool.actionSend')) ?></option>
                <option value="reset"><?= h(t('mailspool.actionReset')) ?></option>
                <option value="archive"><?= h(t('mailspool.actionArchive')) ?></option>
                <?php else: ?>
                <option value="unarchive"><?= h(t('mailspool.actionUnarchive')) ?></option>
                <?php endif; ?>
            </select>
            <button type="button" class="btn btn--primary" id="spoolExecBtn"><?= h(t('config.execute')) ?></button>
            <span id="spoolActionMsg" style="font-size:12px"></span>
        </div>
        <?php endif; ?>
    </div>

</div>

<div class="modal-backdrop" id="mailModal" onclick="if(event.target===this)hideMail()">
    <div class="modal">
        <h2><?= h(t('mailspool.modalTitle')) ?></h2>
        <div class="modal-row"><strong><?= h(t('mailspool.colRecipient')) ?>:</strong> <span id="mModalRecipient"></span></div>
        <div class="modal-row"><strong><?= h(t('mailspool.subject')) ?>:</strong> <span id="mModalSubject"></span></div>
        <div class="modal-row"><strong><?= h(t('mailspool.colSpooled')) ?>:</strong> <span id="mModalSpooled"></span></div>
        <div class="modal-row"><strong><?= h(t('mailspool.sent')) ?>:</strong> <span id="mModalSent"></span></div>
        <div class="modal-row"><strong>PDF:</strong> <span id="mModalPdf"></span></div>

        <div class="modal-row" style="margin-top:14px">
            <strong style="display:block;margin-bottom:6px">HTML:</strong>
            <iframe id="mModalHtml" class="html-preview-frame"
                    sandbox="allow-same-origin"
                    title="<?= h(t('mailspool.modalTitle')) ?>"></iframe>
        </div>
        <div class="modal-row" style="margin-top:14px">
            <strong style="display:block;margin-bottom:6px"><?= h(t('mailspool.plainText')) ?>:</strong>
            <pre id="mModalText"></pre>
        </div>

        <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
            <button type="button" class="btn" onclick="hideMail()"><?= h(t('orders.close')) ?></button>
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
const MAILS = <?= json_encode(array_column($mails, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;

async function showMail(id) {
    const m = MAILS[id];
    if (!m) return;

    document.getElementById('mModalRecipient').textContent = m.recipient || '';
    document.getElementById('mModalSubject').textContent   = t('mailspool.loading');
    document.getElementById('mModalSpooled').textContent   = m.spooled_at ? new Date(m.spooled_at.replace(' ','T')).toLocaleString(window.LANG === 'de' ? 'de-DE' : 'en-GB') : '';
    document.getElementById('mModalSent').textContent      = m.sent_at    ? new Date(m.sent_at.replace(' ','T')).toLocaleString(window.LANG === 'de' ? 'de-DE' : 'en-GB')    : t('mailspool.notSentYet');
    document.getElementById('mModalPdf').textContent       = m.pdf_file   || '—';
    document.getElementById('mModalHtml').srcdoc           = '<p style="margin:8px;color:#999;font-style:italic">' + t('mailspool.loading') + '</p>';
    document.getElementById('mModalText').textContent      = '';
    document.getElementById('mailModal').classList.add('show');

    try {
        const res  = await fetch('api.php', {
            method:  'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body:    new URLSearchParams({ action: 'preview_spool_mail', id: id }),
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('mModalSubject').textContent = data.data.subject || '';
            document.getElementById('mModalHtml').srcdoc         = data.data.html    || '<p style="margin:8px;color:#999;font-style:italic">' + t('mailspool.noHtml') + '</p>';
            document.getElementById('mModalText').textContent    = data.data.plain   || '';
        } else {
            document.getElementById('mModalSubject').textContent = t('mailspool.errorDash');
            document.getElementById('mModalHtml').srcdoc         = '<p style="margin:8px;color:#c0392b">' + (data.error || t('mailspool.previewError')) + '</p>';
        }
    } catch(e) {
        document.getElementById('mModalSubject').textContent = t('mailspool.serverErrorDash');
        document.getElementById('mModalHtml').srcdoc         = '<p style="margin:8px;color:#c0392b">' + t('mailspool.serverErrorPreview') + '</p>';
    }
}

function hideMail() {
    document.getElementById('mailModal').classList.remove('show');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideMail();
});

/* ================================================================
   CHECKBOXEN & BULK-AKTIONEN
   ================================================================ */
function getCheckedIds() {
    return Array.from(document.querySelectorAll('.spool-check:checked'))
        .map(function(cb) { return cb.value; });
}

function updateBulkBar() {
    const ids = getCheckedIds();
    const bar = document.getElementById('spoolBulkBar');
    if (!bar) return;
    if (ids.length === 0) {
        bar.classList.add('hidden');
    } else {
        bar.classList.remove('hidden');
        document.getElementById('spoolBulkCount').textContent = t(ids.length === 1 ? 'mailspool.selectedOne' : 'mailspool.selectedMany', { n: ids.length });
        document.getElementById('spoolActionMsg').textContent = '';
    }
    const sa = document.getElementById('spoolSelectAll');
    if (!sa) return;
    const all = document.querySelectorAll('.spool-check');
    sa.indeterminate = ids.length > 0 && ids.length < all.length;
    sa.checked       = all.length > 0 && ids.length === all.length;
}

const selectAll = document.getElementById('spoolSelectAll');
if (selectAll) {
    selectAll.addEventListener('change', function() {
        document.querySelectorAll('.spool-check').forEach(function(cb) {
            cb.checked = selectAll.checked;
        });
        updateBulkBar();
    });
}

document.querySelectorAll('.spool-check').forEach(function(cb) {
    cb.addEventListener('change', updateBulkBar);
});

const execBtn = document.getElementById('spoolExecBtn');
if (execBtn) {
    execBtn.addEventListener('click', async function() {
        const action = document.getElementById('spoolAction').value;
        const ids    = getCheckedIds();
        const msgEl  = document.getElementById('spoolActionMsg');

        if (!action) { msgEl.style.color = '#c0392b'; msgEl.textContent = t('mailspool.chooseAction'); return; }
        if (ids.length === 0) return;

        if (action === 'send') {
            if (!await Dialog.confirm(t('mailspool.confirmSend', { n: ids.length }))) return;
        }
        if (action === 'reset') {
            if (!await Dialog.confirm(t('mailspool.confirmReset', { n: ids.length }))) return;
        }

        execBtn.disabled = true;
        msgEl.style.color = '#777';
        msgEl.textContent = action === 'send' ? t('mailspool.sending')
                          : action === 'reset' ? t('invoices.resetting')
                          : action === 'unarchive' ? t('mailspool.unarchiving')
                          : t('mailspool.archiving');

        try {
            const apiAction = action === 'send' ? 'send_spool_mails'
                       : action === 'reset' ? 'reset_spool_mails'
                       : action === 'unarchive' ? 'unarchive_spool_mails'
                       : 'archive_spool_mails';
            const body = new URLSearchParams({ action: apiAction, ids: ids.join(',') });
            const res  = await fetch('api.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF },
                body
            });
            const data = await res.json();

            if (!data.success) {
                msgEl.style.color = '#c0392b';
                msgEl.textContent = t('common.error') + ': ' + (data.error || t('common.unknownError'));
                execBtn.disabled = false;
                return;
            }

            if (action === 'send') {
                const sent   = data.data.sent   || 0;
                const errors = data.data.errors || [];

                // Mark sent rows visually
                ids.forEach(function(id) {
                    const row = document.getElementById('spool-row-' + id);
                    if (!row) return;
                    const statusCell = row.cells[1];
                    if (statusCell) statusCell.innerHTML = '<span class="status-sent">' + t('mailspool.sent') + '</span>';
                    const sentCell = row.cells[3];
                    if (sentCell) sentCell.textContent = new Date().toLocaleString(window.LANG === 'de' ? 'de-DE' : 'en-GB');
                    row.querySelector('.spool-check').checked = false;
                });

                if (errors.length > 0) {
                    msgEl.style.color = '#c0392b';
                    msgEl.textContent = t('mailspool.sentWithErrors', { sent: sent, errors: errors.join('; ') });
                } else {
                    msgEl.style.color = '#27ae60';
                    msgEl.textContent = t('mailspool.sentCount', { n: sent });
                }
            } else if (action === 'reset') {
                ids.forEach(function(id) {
                    const row = document.getElementById('spool-row-' + id);
                    if (!row) return;
                    const statusCell = row.cells[1];
                    if (statusCell) statusCell.innerHTML = '<span class="status-pending">' + t('mailspool.statusOpen') + '</span>';
                    const sentCell = row.cells[3];
                    if (sentCell) sentCell.textContent = '';
                    row.querySelector('.spool-check').checked = false;
                });
                msgEl.style.color = '#27ae60';
                msgEl.textContent = t('mailspool.resetCount', { n: data.data.reset || 0 });
                updateBulkBar();
            } else if (action === 'unarchive') {
                ids.forEach(function(id) {
                    document.getElementById('spool-row-' + id)?.remove();
                });
                msgEl.style.color = '#27ae60';
                msgEl.textContent = t('mailspool.unarchivedCount', { n: data.data.unarchived || 0 });
                updateBulkBar();
            } else {
                // Remove archived rows
                ids.forEach(function(id) {
                    document.getElementById('spool-row-' + id)?.remove();
                });
                msgEl.style.color = '#27ae60';
                msgEl.textContent = t('mailspool.archivedCount', { n: data.data.archived || 0 });
            }

            updateBulkBar();
        } catch(e) {
            msgEl.style.color = '#c0392b';
            msgEl.textContent = t('config.serverError');
        }
        execBtn.disabled = false;
    });
}

document.querySelectorAll('.testmail-btn').forEach(function(btn) {
    btn.addEventListener('click', async function() {
        const id       = btn.dataset.id;
        const original = btn.textContent;
        btn.disabled    = true;
        btn.textContent = t('config.sending');

        try {
            const body = new URLSearchParams({ action: 'send_spool_testmail', id });
            const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body });
            const data = await res.json();

            if (data.success) {
                btn.textContent = t('mailspool.testSentTo', { r: data.data.recipient });
                setTimeout(function() {
                    btn.disabled    = false;
                    btn.textContent = original;
                }, 4000);
            } else {
                Dialog.alert(t('common.error') + ': ' + (data.error || t('common.unknownError')));
                btn.disabled    = false;
                btn.textContent = original;
            }
        } catch(e) {
            Dialog.alert(t('config.serverError'));
            btn.disabled    = false;
            btn.textContent = original;
        }
    });
});

document.querySelectorAll('.unspool-btn').forEach(function(btn) {
    btn.addEventListener('click', async function() {
        const id     = btn.dataset.id;
        const number = btn.dataset.number;

        if (!await Dialog.confirm(t('mailspool.confirmUnspool', { number: number }), { danger: true })) return;

        btn.disabled    = true;
        btn.textContent = '…';

        try {
            const body = new URLSearchParams({ action: 'spool_invoice_undo', id });
            const res  = await fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body });
            const data = await res.json();

            if (data.success) {
                document.getElementById('spool-row-' + id)?.remove();
            } else {
                Dialog.alert(t('common.error') + ': ' + (data.error || t('common.unknownError')));
                btn.disabled    = false;
                btn.textContent = t('invoices.reverse');
            }
        } catch(e) {
            Dialog.alert(t('config.serverError'));
            btn.disabled    = false;
            btn.textContent = t('invoices.reverse');
        }
    });
});
</script>
</body>
</html>
