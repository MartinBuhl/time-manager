'use strict';

/* ------------------------------------------------------------------
   Übersetzung: liest aus window.I18N (vom Server gesetzt); fehlt ein
   Schlüssel, wird der Schlüssel selbst zurückgegeben. Platzhalter {name}.
------------------------------------------------------------------ */
function t(key, params) {
    let s = (window.I18N && window.I18N[key]) || key;
    if (params) {
        for (const k in params) { s = s.split('{' + k + '}').join(params[k]); }
    }
    return s;
}

/* ------------------------------------------------------------------
   API helper – sends POST to api.php with CSRF token header
------------------------------------------------------------------ */
async function api(action, data = {}) {
    const body = new URLSearchParams({ action, ...data });
    const res = await fetch('api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': window.CSRF_TOKEN || '',
        },
        body,
    });
    return res.json();
}

/* API-Aufruf mit Datei-Upload (multipart) */
async function apiForm(action, formData) {
    formData.append('action', action);
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: formData,
    });
    return res.json();
}

function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* ------------------------------------------------------------------
   Aufträge – Detailansicht (per onclick global erreichbar)
------------------------------------------------------------------ */
function orderFileListHtml(orderId, files) {
    if (!files || !files.length) return '<span class="order-hint">' + escHtml(t('orders.noFiles')) + '</span>';
    return files.map(f =>
        '<div class="order-file-item">'
        + '<a href="order_file.php?id=' + f.id + '" target="_blank" rel="noopener">'
        + escHtml(f.original_name) + '</a>'
        + '<button type="button" class="order-file-del" title="' + escHtml(t('orders.deleteFileTitle')) + '" '
        + 'onclick="deleteOrderFile(' + orderId + ',' + f.id + ')">&times;</button>'
        + '</div>'
    ).join('');
}

/* Auftrag inline aufklappen und Inhalt (Text + Dateien) laden */
async function showOrderEdit(id) {
    const editRow = document.getElementById('oedit-' + id);
    if (!editRow) return;
    editRow.classList.remove('hidden');
    const res = await api('get_order', { id });
    if (!res.success) { Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError'))); return; }
    const o = res.data;
    document.getElementById('oeditBody-' + id).innerHTML  = o.body || '';
    document.getElementById('oeditFiles-' + id).innerHTML = orderFileListHtml(id, o.files || []);
    const nf = document.getElementById('oeditNewFiles-' + id); if (nf) nf.value = '';
    const msg = document.getElementById('oeditMsg-' + id); if (msg) { msg.textContent = ''; msg.className = 'order-msg'; }
}

function hideOrderEdit(id) {
    const r = document.getElementById('oedit-' + id);
    if (r) r.classList.add('hidden');
}

async function deleteOrderFile(orderId, fileId) {
    if (!await Dialog.confirm(t('orders.confirmDeleteFile'), { danger: true })) return;
    const res = await api('delete_order_file', { id: fileId });
    if (res.success) {
        const g = await api('get_order', { id: orderId });
        if (g.success) document.getElementById('oeditFiles-' + orderId).innerHTML = orderFileListHtml(orderId, g.data.files || []);
    } else {
        Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
    }
}

async function saveOrderInline(id) {
    const msg = document.getElementById('oeditMsg-' + id);
    msg.className = 'order-msg'; msg.textContent = t('common.saving');

    const fd = new FormData();
    fd.append('id', id);
    fd.append('body', document.getElementById('oeditBody-' + id).innerHTML);
    const nf = document.getElementById('oeditNewFiles-' + id);
    for (const f of nf.files) fd.append('files[]', f);

    const res = await apiForm('update_order', fd);
    if (res.success) {
        nf.value = '';
        const g = await api('get_order', { id });
        if (g.success) document.getElementById('oeditFiles-' + id).innerHTML = orderFileListHtml(id, g.data.files || []);
        msg.textContent = t('common.saved'); msg.classList.add('ok');
    } else {
        msg.textContent = res.error || t('common.saveError'); msg.classList.add('err');
    }
}

async function completeOrderInline(id) {
    if (!await Dialog.confirm(t('orders.confirmDone'))) return;
    const res = await api('complete_order', { id });
    if (res.success) location.reload();
    else Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
}

/* Zwei-Schritt-Löschen eines Auftrags in der Kunden-Unterliste */
function showSubDeleteConfirm(id) {
    document.getElementById('soactions-' + id).classList.add('hidden');
    document.getElementById('soactions-confirm-' + id).classList.remove('hidden');
}
function cancelSubDelete(id) {
    document.getElementById('soactions-confirm-' + id).classList.add('hidden');
    document.getElementById('soactions-' + id).classList.remove('hidden');
}
async function confirmSubDelete(id) {
    const res = await api('delete_order', { id });
    if (res.success) {
        const el   = document.getElementById('suborder-' + id);
        const list = el ? el.closest('.suborder-list') : null;
        if (el) el.remove();
        // Bleibt die Unterliste leer, kurzen Hinweis zeigen (Liste bleibt offen)
        if (list && !list.querySelector('.suborder')) {
            list.innerHTML = '<div class="order-hint" style="padding:6px">' + escHtml(t('orders.none')) + '</div>';
        }
    } else {
        Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
        cancelSubDelete(id);
    }
}

/* Liste aller Aufträge des Kunden auf-/zuklappen */
function toggleCustomerOrders(ev, oid) {
    if (ev) ev.stopPropagation();
    const row = document.getElementById('osub-' + oid);
    if (row) row.classList.toggle('hidden');
}

/* Mehrzeiligen Auftragstext auf-/zuklappen */
function toggleSuborderFull(ev, id) {
    if (ev) ev.stopPropagation();
    const full = document.getElementById('sofull-' + id);
    const btn  = document.getElementById('soexpand-' + id);
    if (!full) return;
    const willOpen = full.classList.contains('hidden');
    full.classList.toggle('hidden', !willOpen);
    if (btn) {
        btn.classList.toggle('open', willOpen);
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }
}

/* „Bearbeitet" – Kunde als zuletzt bearbeitet markieren (wandert nach unten) */
async function markWorked(ev, id) {
    ev.stopPropagation();
    const res = await api('mark_order_worked', { id });
    if (res.success) location.reload();
    else Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
}

/* Liste der heute bearbeiteten Kunden auf-/zuklappen */
function toggleWorkedList() {
    const list  = document.getElementById('workedList');
    const caret = document.getElementById('workedCaret');
    if (!list) return;
    const open = list.classList.toggle('hidden') === false;
    if (caret) caret.innerHTML = open ? '&#9662;' : '&#9656;';
}

/* „Zurücksetzen" – Bearbeitet-Markierung des Kunden aufheben (wandert nach oben) */
async function resetWorked(ev, customerId) {
    if (ev) ev.stopPropagation();
    const res = await api('reset_order_worked', { customer_id: customerId });
    if (res.success) location.reload();
    else Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
}

/* ------------------------------------------------------------------
   Countdown
------------------------------------------------------------------ */
/* ==================================================================
   Tracker – eine Instanz der Zeiterfassung. Mehrere Instanzen laufen
   parallel (je ein Kunde). Alle Elemente werden innerhalb des eigenen
   Wurzelknotens per Klasse gesucht, nichts hängt an globalen IDs.
================================================================== */
const trackers = [];

/* Seite neu laden, nachdem die Kommentare aller laufenden Timer gespeichert sind. */
async function reloadAfterSavingTrackers(except = null) {
    try {
        await Promise.all(trackers.filter(tr => tr !== except && tr.serverId).map(tr => tr.flush()));
    } catch (e) { /* trotzdem neu laden */ }
    location.reload();
}

function addTrackerInstance(restoreData = null) {
    const tpl  = document.getElementById('trackerTpl');
    const host = document.getElementById('trackerHost');
    if (!tpl || !host) return null;
    const frag = tpl.content.cloneNode(true);
    const root = frag.querySelector('.tracker-instance');
    host.appendChild(frag);
    const tr = new Tracker(root);
    trackers.push(tr);
    if (restoreData) tr.restore(restoreData);
    return tr;
}

class Tracker {
    constructor(root) {
        this.root    = root;
        this.primary = root.dataset.primary === '1';
        const $ = sel => root.querySelector(sel);

        this.selectCustomer  = $('.selectCustomer');
        this.selectProject   = $('.selectProject');
        this.selectActivity  = $('.selectActivity');
        this.inputComment    = $('.inputComment');
        this.resetBtn        = $('.resetBtn');
        this.stopBtn         = $('.stopBtn');
        this.customerDisplay = $('.customerDisplay');
        this.projectDisplay  = $('.projectDisplay');
        this.activityDisplay = $('.activityDisplay');
        this.startTimeEl     = $('.startTime');
        this.startTimePicker = $('.startTimePicker');
        this.startTimeWrap   = $('.startTimeWrap');
        this.countdownEl     = $('.countdown');
        this.rowComment      = $('.rowComment');
        this.rowStop         = $('.rowStop');
        this.rowShortcuts    = $('.rowShortcuts');
        this.shortcutBtns    = $('.shortcutBtns');
        this.addBtn          = $('.addInstanceBtn');
        this.closeBtn        = $('.closeInstanceBtn');

        this.serverId          = null;   // tm_active_timers.id
        this.customerId        = null;
        this.customerName      = '';
        this.project           = '';
        this.activity          = '';
        this.startTime         = '';
        this.countdownValue    = 1800;
        this.countdownInterval = null;
        this.saveChain         = Promise.resolve();   // Speichervorgänge nacheinander
        this.commentTimer      = null;                // verzögertes Speichern des Kommentars

        this.wire();
        this.updateCountdownDisplay();
    }

    /* --- Countdown (30-Min-Erinnerung, je Instanz eigen) --- */
    updateCountdownDisplay() {
        const el = this.countdownEl;
        if (!el) return;
        el.textContent = this.countdownValue;
        el.classList.toggle('warning', this.countdownValue <= 600 && this.countdownValue > 120);
        el.classList.toggle('urgent',  this.countdownValue <= 120);
    }
    startCountdown() {
        clearInterval(this.countdownInterval);
        this.countdownInterval = setInterval(() => {
            this.countdownValue--;
            this.updateCountdownDisplay();
            if (this.countdownValue <= 0) {
                clearInterval(this.countdownInterval);
                Dialog.alert(t('tracker.timeRunning'));
                this.countdownValue = 1800;
                this.updateCountdownDisplay();
                this.startCountdown();
            }
        }, 1000);
    }
    resetCountdown() {
        clearInterval(this.countdownInterval);
        this.countdownValue = 1800;
        this.updateCountdownDisplay();
        this.startCountdown();
    }
    recomputeCountdownFromStart() {
        if (!this.startTime) return;
        const [h, m, sec] = this.startTime.split(':').map(Number);
        const d = new Date(); d.setHours(h, m, sec || 0, 0);
        const elapsed = Math.floor((new Date() - d) / 1000);
        this.countdownValue = Math.max(0, 1800 - elapsed);
        clearInterval(this.countdownInterval);
        this.updateCountdownDisplay();
        if (this.countdownValue > 0) this.startCountdown();
    }

    /* --- Anzeige / Hilfen --- */
    updateRunningDisplay() {
        this.customerDisplay.textContent = this.customerName;
        this.projectDisplay.textContent  = this.project;
        this.activityDisplay.textContent = this.activity;
    }
    setStartTime(timeStr) {
        this.startTime = timeStr;
        this.startTimeEl.textContent = timeStr || '--:--:--';
        if (this.startTimePicker) this.startTimePicker.value = timeStr ? timeStr.slice(0, 5) : '';
    }
    showRunningRows() {
        this.rowComment.classList.remove('hidden');
        this.rowStop.classList.remove('hidden');
    }
    updateShortcuts() {
        const matching = (window.SHORTCUTS || []).filter(s =>
            s.activity === this.activity &&
            (!s.customer_id || String(s.customer_id) === String(this.customerId)));
        this.shortcutBtns.innerHTML = '';
        if (matching.length) {
            matching.forEach(s => {
                const btn = document.createElement('button');
                btn.type = 'button'; btn.className = 'btn'; btn.style.fontSize = '12px';
                btn.textContent = s.shortcut_text;
                btn.addEventListener('click', () => {
                    this.inputComment.value = s.shortcut_text;
                    this.inputComment.dispatchEvent(new Event('input'));
                });
                this.shortcutBtns.appendChild(btn);
            });
            this.rowShortcuts.classList.remove('hidden');
        } else {
            this.rowShortcuts.classList.add('hidden');
        }
    }

    /* Laufenden Timer serverseitig speichern/aktualisieren (liefert serverId).
       Aufrufe laufen nacheinander, damit beim schnellen Tippen kein zweiter
       Timer angelegt wird, solange die serverId noch fehlt. */
    persist() {
        clearTimeout(this.commentTimer);
        this.commentTimer = null;
        this.saveChain = this.saveChain.catch(() => {}).then(async () => {
            if (!this.activity || !this.startTime) return;
            const res = await api('save_timer', {
                id:            this.serverId || '',
                customer_id:   this.customerId || '',
                customer_name: this.customerName,
                activity:      this.activity,
                project:       this.project,
                start_time:    this.startTime,
                comment:       this.inputComment.value.trim(),
            });
            if (res.success && res.data && res.data.id) this.serverId = res.data.id;
        });
        return this.saveChain;
    }
    /* Kommentar kurz nach dem Tippen zwischenspeichern */
    scheduleCommentSave() {
        clearTimeout(this.commentTimer);
        this.commentTimer = setTimeout(() => this.persist(), 600);
    }
    /* Noch ausstehende Speichervorgänge abschließen (z. B. vor dem Neuladen) */
    flush() {
        return this.commentTimer ? this.persist() : this.saveChain.catch(() => {});
    }

    wire() {
        this.resetBtn.addEventListener('click', () => this.resetCountdown());

        /* Schritt 1 – Kunde gewählt */
        this.selectCustomer.addEventListener('change', () => {
            const opt = this.selectCustomer.selectedOptions[0];
            if (!this.selectCustomer.value) return;
            this.customerId   = this.selectCustomer.value;
            this.customerName = opt.dataset.name || opt.text;
            this.project      = '';
            this.selectCustomer.disabled = true;

            const projects = (window.CUSTOMER_PROJECTS || {})[this.customerId] || [];
            if (projects.length > 1) {
                this.selectProject.innerHTML = '<option value="">' + escHtml(t('tracker.chooseProject')) + '</option>';
                projects.forEach(p => { const o = document.createElement('option'); o.value = p.name; o.textContent = p.name; this.selectProject.appendChild(o); });
                this.selectProject.classList.remove('hidden');
            } else {
                if (projects.length === 1) this.project = projects[0].name;
                this.selectActivity.classList.remove('hidden');
            }
        });

        /* Schritt 2 – Projekt gewählt */
        this.selectProject.addEventListener('change', () => {
            if (!this.selectProject.value) return;
            this.project = this.selectProject.value;
            this.selectProject.disabled = true;
            this.selectActivity.classList.remove('hidden');
        });

        /* Schritt 3 – Tätigkeit gewählt → Timer startet */
        this.selectActivity.addEventListener('change', async () => {
            if (!this.selectActivity.value) return;
            this.activity  = this.selectActivity.value;
            this.startTime = nowTime();
            this.selectActivity.disabled = true;
            this.updateRunningDisplay();
            this.setStartTime(this.startTime);
            this.stopBtn.disabled   = true;
            this.inputComment.value = '';
            this.showRunningRows();
            this.updateShortcuts();
            this.resetCountdown();
            await this.persist();   // legt Timer an → serverId
        });

        /* Stop – Eintrag speichern */
        this.stopBtn.addEventListener('click', async () => {
            clearInterval(this.countdownInterval);
            const res = await api('send_work', {
                timer_id:    this.serverId || '',
                customer_id: this.customerId || '',
                activity:    this.activity,
                project:     this.project,
                comment:     this.inputComment.value.trim(),
                start_time:  this.startTime,
                stop_time:   nowTime(),
                stop_date:   nowDate(),
            });
            if (res.success) {
                clearTimeout(this.commentTimer);
                this.commentTimer = null;
                await reloadAfterSavingTrackers(this);
            } else {
                Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
                this.startCountdown();
            }
        });

        /* Kommentar-Pflichtfeld: Stop erst aktiv, wenn Text vorhanden */
        this.inputComment.addEventListener('input', () => {
            this.stopBtn.disabled = this.inputComment.value.trim() === '';
            if (this.activity) this.scheduleCommentSave();
        });
        this.inputComment.addEventListener('blur', () => { if (this.commentTimer) this.persist(); });

        /* Startzeit ändern */
        if (this.startTimePicker) {
            this.startTimePicker.addEventListener('change', async () => {
                if (!this.startTimePicker.value || !this.activity) return;
                this.setStartTime(this.startTimePicker.value + ':00');
                await this.persist();
                this.recomputeCountdownFromStart();
            });
        }
        if (this.startTimeWrap && this.startTimePicker) {
            this.startTimeWrap.addEventListener('click', () => {
                if (this.startTimePicker.showPicker) { try { this.startTimePicker.showPicker(); } catch (e) {} }
            });
        }

        /* „+" – weitere Instanz · „×" – diese Instanz schließen */
        if (this.addBtn)   this.addBtn.addEventListener('click', () => addTrackerInstance());
        if (this.closeBtn) this.closeBtn.addEventListener('click', () => this.close());
    }

    async close() {
        if (this.serverId) {
            if (!await Dialog.confirm(t('tracker.confirmCloseRunning'), { danger: true })) return;
            await api('delete_timer', { id: this.serverId });
        }
        clearInterval(this.countdownInterval);
        const idx = trackers.indexOf(this);
        if (idx >= 0) trackers.splice(idx, 1);
        this.root.remove();
    }

    /* Beim Neuladen: laufenden Timer aus tm_active_timers wiederherstellen */
    restore(s) {
        this.serverId     = s.id || null;
        this.customerId   = s.customer_id   || null;
        this.customerName = s.customer_name || '';
        this.activity     = s.activity      || '';
        this.project      = s.project       || '';
        this.startTime    = s.start_time    || '';

        if (this.customerId) { this.selectCustomer.value = this.customerId; this.selectCustomer.disabled = true; }

        const projects = (window.CUSTOMER_PROJECTS || {})[this.customerId] || [];
        if (projects.length > 1) {
            this.selectProject.innerHTML = '<option value="">' + escHtml(t('tracker.chooseProject')) + '</option>';
            projects.forEach(p => { const o = document.createElement('option'); o.value = p.name; o.textContent = p.name; this.selectProject.appendChild(o); });
            this.selectProject.value = this.project;
            this.selectProject.classList.remove('hidden');
            this.selectProject.disabled = true;
        }
        this.selectActivity.value = this.activity;
        this.selectActivity.classList.remove('hidden');
        this.selectActivity.disabled = true;

        this.updateRunningDisplay();
        this.setStartTime(this.startTime);
        if (this.startTime) {
            const [h, m, sec] = this.startTime.split(':').map(Number);
            const d = new Date(); d.setHours(h, m, sec, 0);
            const elapsed = Math.floor((new Date() - d) / 1000);
            this.countdownValue = Math.max(0, 1800 - elapsed);
        }
        this.inputComment.value = s.comment || '';
        this.stopBtn.disabled = this.inputComment.value.trim() === '';
        this.showRunningRows();
        this.updateShortcuts();
        this.updateCountdownDisplay();
        if (this.countdownValue > 0) this.startCountdown();
    }
}

/* ------------------------------------------------------------------
   Inline edit
------------------------------------------------------------------ */
function showEdit(id) {
    document.getElementById('row-'  + id).classList.add('hidden');
    document.getElementById('edit-' + id).classList.remove('hidden');
}

function hideEdit(id) {
    document.getElementById('row-'  + id).classList.remove('hidden');
    document.getElementById('edit-' + id).classList.add('hidden');
}

/* Ende-Feld eines Eintrags auf die aktuelle Zeit setzen */
function setEndNow(id) {
    const editRow = document.getElementById('edit-' + id);
    if (!editRow) return;
    const input = editRow.querySelector('.edit-end');
    if (input) input.value = nowDate() + ' ' + nowTime();
}

async function saveEdit(id) {
    const editRow    = document.getElementById('edit-' + id);
    const date       = editRow.querySelector('.edit-date').value;
    const start      = editRow.querySelector('.edit-start').value.trim();
    const end        = editRow.querySelector('.edit-end').value.trim();
    const comment    = editRow.querySelector('.edit-comment').value.trim();
    const customerId = editRow.querySelector('.edit-customer').value;
    const activity   = editRow.querySelector('.edit-activity').value;
    const project    = editRow.querySelector('.edit-project').value.trim();

    const res = await api('update_entry', {
        id,
        date,
        start_datetime: start,
        end_datetime:   end,
        comment,
        customer_id:    customerId,
        activity,
        project,
    });

    if (res.success) {
        location.reload();
    } else {
        Dialog.alert(t('common.saveError') + ' ' + (res.error || t('common.unknownError')));
    }
}

/* ------------------------------------------------------------------
   Delete (soft delete)
------------------------------------------------------------------ */
function showDeleteConfirm(id) {
    document.getElementById('actions-'         + id).classList.add('hidden');
    document.getElementById('actions-confirm-' + id).classList.remove('hidden');
}

function cancelDelete(id) {
    document.getElementById('actions-confirm-' + id).classList.add('hidden');
    document.getElementById('actions-'         + id).classList.remove('hidden');
}

async function confirmDelete(id) {
    const res = await api('delete_entry', { id });
    if (res.success) {
        const row     = document.getElementById('row-'  + id);
        const editRow = document.getElementById('edit-' + id);
        if (row)     row.remove();
        if (editRow) editRow.remove();
    } else {
        Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
        cancelDelete(id);
    }
}

/* ------------------------------------------------------------------
   Time helper
------------------------------------------------------------------ */
function nowTime() {
    const d = new Date();
    return [
        String(d.getHours()).padStart(2, '0'),
        String(d.getMinutes()).padStart(2, '0'),
        String(d.getSeconds()).padStart(2, '0'),
    ].join(':');
}

function nowDate() {
    const d = new Date();
    return d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0');
}

/* ------------------------------------------------------------------
   Main
------------------------------------------------------------------ */
document.addEventListener('DOMContentLoaded', () => {

    /* ---- Login page ---- */
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd  = new FormData(loginForm);
            const res = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:   'login',
                    username: fd.get('username'),
                    password: fd.get('password'),
                }),
            });
            const json = await res.json();
            if (json.success) {
                location.reload();
            } else {
                const errEl = document.getElementById('loginError');
                errEl.textContent = json.error || t('login.failed');
                errEl.classList.remove('hidden');
            }
        });

        /* ---- Passwort vergessen ---- */
        const forgotLink   = document.getElementById('forgotLink');
        const backToLogin  = document.getElementById('backToLogin');
        const forgotPanel  = document.getElementById('forgotPanel');
        const loginSwitch  = document.querySelector('.login-switch');

        forgotLink.addEventListener('click', (e) => {
            e.preventDefault();
            loginForm.classList.add('hidden');
            loginSwitch.classList.add('hidden');
            forgotPanel.classList.remove('hidden');
        });

        backToLogin.addEventListener('click', (e) => {
            e.preventDefault();
            forgotPanel.classList.add('hidden');
            loginForm.classList.remove('hidden');
            loginSwitch.classList.remove('hidden');
        });

        document.getElementById('forgotSubmit').addEventListener('click', async () => {
            const emailInput = document.getElementById('forgotEmail');
            const email      = emailInput.value.trim();
            const msgEl      = document.getElementById('forgotMessage');
            const btn        = document.getElementById('forgotSubmit');

            // Lokale Vorprüfung
            if (!email) {
                msgEl.className   = 'login-error';
                msgEl.textContent = t('login.enterEmail');
                msgEl.classList.remove('hidden');
                return;
            }

            btn.disabled        = true;
            btn.textContent     = t('login.sending');
            msgEl.classList.add('hidden');

            try {
                const res  = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'request_password_reset', email }),
                });
                const json = await res.json();

                msgEl.classList.remove('hidden');
                if (json.success) {
                    msgEl.className   = 'success-message';
                    msgEl.textContent = t('login.resetSent');
                    emailInput.value  = '';
                } else {
                    msgEl.className   = 'login-error';
                    msgEl.textContent = json.error || t('login.sendError');
                }
            } catch (err) {
                msgEl.classList.remove('hidden');
                msgEl.className   = 'login-error';
                msgEl.textContent = t('login.connError');
            } finally {
                btn.disabled    = false;
                btn.textContent = t('login.sendLink');
            }
        });

        return;
    }

    /* ---- App page: Tracker-Instanzen (parallele Zeiterfassung) ---- */
    const primaryTrackerEl = document.querySelector('#trackerHost .tracker-instance[data-primary="1"]');
    if (!primaryTrackerEl) return;

    const primaryTracker = new Tracker(primaryTrackerEl);
    trackers.push(primaryTracker);

    // Laufende Timer wiederherstellen: erster in die primäre Instanz, weitere als Zusatzblöcke.
    const restoreTimers = window.ACTIVE_TIMERS || [];
    if (restoreTimers.length) {
        primaryTracker.restore(restoreTimers[0]);
        for (let i = 1; i < restoreTimers.length; i++) addTrackerInstance(restoreTimers[i]);
    }

    /* ---- Settings view ---- */
    const btnSettings    = document.getElementById('btnSettings');
    const settingsView   = document.getElementById('settingsView');
    const settingsClose  = document.getElementById('settingsClose');
    const fontSizeSlider = document.getElementById('fontSizeSlider');
    const fontSizeLbl    = document.getElementById('fontSizeValue');
    const themeChoice    = document.getElementById('themeChoice');

    function applyZoom(pct) {
        document.documentElement.style.setProperty('--app-zoom', pct / 100);
        if (fontSizeLbl)    fontSizeLbl.textContent = pct + '%';
        if (fontSizeSlider) fontSizeSlider.value    = pct;
        localStorage.setItem('tm_zoom', pct);
    }

    const savedZoom = localStorage.getItem('tm_zoom');
    if (savedZoom) applyZoom(Number(savedZoom));

    function applyTheme(theme) {
        theme = (theme === 'light') ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('tm_theme', theme);
        if (themeChoice) {
            themeChoice.querySelectorAll('.theme-btn').forEach((b) => {
                b.classList.toggle('active', b.dataset.themeChoice === theme);
            });
        }
    }
    applyTheme(localStorage.getItem('tm_theme') || 'dark');

    if (themeChoice) {
        themeChoice.querySelectorAll('.theme-btn').forEach((b) => {
            b.addEventListener('click', () => applyTheme(b.dataset.themeChoice));
        });
    }

    if (btnSettings && settingsView) {
        btnSettings.addEventListener('click', () => settingsView.classList.remove('hidden'));
    }
    if (settingsClose && settingsView) {
        settingsClose.addEventListener('click', () => settingsView.classList.add('hidden'));
    }
    if (settingsView) {
        settingsView.addEventListener('click', (e) => {
            if (e.target === settingsView) settingsView.classList.add('hidden');
        });
    }

    if (fontSizeSlider) {
        fontSizeSlider.addEventListener('input', () => applyZoom(Number(fontSizeSlider.value)));
    }

    /* ---- Aufträge: erfassen ---- */
    const orderCustomer   = document.getElementById('orderCustomer');
    const orderBody       = document.getElementById('orderBody');
    const orderFilesInput = document.getElementById('orderFiles');
    const orderSaveBtn    = document.getElementById('orderSaveBtn');
    const orderMsg        = document.getElementById('orderMsg');
    const orderFields     = document.getElementById('orderFields');

    if (orderCustomer && orderFields) {
        orderCustomer.addEventListener('change', () => {
            orderFields.style.display = orderCustomer.value ? 'flex' : 'none';
        });
    }

    if (orderSaveBtn) {
        orderSaveBtn.addEventListener('click', async () => {
            const customerId = orderCustomer.value;
            const bodyText   = orderBody.textContent.trim();
            orderMsg.className = 'order-msg';

            if (!customerId) {
                orderMsg.textContent = t('orders.chooseCustomerFirst');
                orderMsg.classList.add('err');
                return;
            }
            if (!bodyText && orderFilesInput.files.length === 0) {
                orderMsg.textContent = t('orders.enterTextOrFile');
                orderMsg.classList.add('err');
                return;
            }

            orderSaveBtn.disabled = true;
            orderMsg.textContent  = t('common.saving');

            const fd = new FormData();
            fd.append('customer_id', customerId);
            fd.append('body', orderBody.innerHTML);
            for (const f of orderFilesInput.files) fd.append('files[]', f);

            const res = await apiForm('create_order', fd);
            if (res.success) {
                location.reload();
            } else {
                orderSaveBtn.disabled = false;
                orderMsg.textContent  = res.error || t('common.saveError');
                orderMsg.classList.add('err');
            }
        });
    }

    /* ---- Projekt-Select bei Kundenwechsel im Edit-Formular aktualisieren ---- */
    document.addEventListener('change', (e) => {
        if (!e.target.classList.contains('edit-customer')) return;
        const form       = e.target.closest('.edit-form');
        const projectSel = form && form.querySelector('.edit-project');
        if (!projectSel) return;
        const projects   = (window.CUSTOMER_PROJECTS || {})[e.target.value] || [];
        projectSel.innerHTML = '<option value="">' + escHtml(t('common.noProject')) + '</option>';
        projects.forEach(p => {
            const o = document.createElement('option');
            o.value = p.name;
            o.textContent = p.name;
            projectSel.appendChild(o);
        });
    });

    /* ---- Date picker for entry list ---- */
    const datePicker = document.getElementById('datePicker');
    if (datePicker) {
        datePicker.addEventListener('change', () => {
            if (datePicker.value) {
                location.href = 'index.php?date=' + datePicker.value;
            }
        });
    }
});

/* ================================================================
   Bookmarks – Firefox-artige Lesezeichenleiste
   ================================================================ */
function bmToggleSection() {
    const sec = document.getElementById('bookmarksSection');
    if (!sec) return;
    sec.classList.toggle('hidden');
    const open = !sec.classList.contains('hidden');
    try { localStorage.setItem('tm_bookmarks_open', open ? '1' : '0'); } catch (e) {}
    const btn = document.getElementById('btnBookmarks');
    if (btn) btn.classList.toggle('active', open);
    if (!open) bmCloseAll();
}

function bmCloseNested(menu) {
    if (!menu) return;
    menu.classList.add('hidden');
    if (menu.parentElement) menu.parentElement.classList.remove('open');
    menu.querySelectorAll('.bm-menu').forEach(m => m.classList.add('hidden'));
    menu.querySelectorAll('.bm-folder.open').forEach(f => f.classList.remove('open'));
}

function bmCloseAll() {
    document.querySelectorAll('.bm-panel').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.bm-top-btn.open').forEach(b => b.classList.remove('open'));
    document.querySelectorAll('.bm-panel .bm-menu').forEach(m => m.classList.add('hidden'));
    document.querySelectorAll('.bm-folder.open').forEach(f => f.classList.remove('open'));
}

/* Oberste Ebene: voll-breites Panel unter der Leiste auf-/zuklappen */
function bmTogglePanel(ev, id) {
    ev.stopPropagation();
    const panel = document.getElementById('bm-panel-' + id);
    const btn   = ev.currentTarget;
    if (!panel) return;
    const willOpen = panel.classList.contains('hidden');
    document.querySelectorAll('.bm-panel').forEach(p => { if (p !== panel) p.classList.add('hidden'); });
    document.querySelectorAll('.bm-top-btn.open').forEach(b => { if (b !== btn) b.classList.remove('open'); });
    if (willOpen) { panel.classList.remove('hidden'); if (btn) btn.classList.add('open'); }
    else { panel.classList.add('hidden'); if (btn) btn.classList.remove('open'); }
}

/* Unterordner innerhalb eines Panels: inline (Akkordeon) auf-/zuklappen */
function bmToggleFolder(ev, id) {
    ev.stopPropagation();
    const menu = document.getElementById('bm-menu-' + id);
    if (!menu) return;
    const folder = menu.parentElement;
    const willOpen = menu.classList.contains('hidden');
    const parentMenu = folder.parentElement;
    if (parentMenu) {
        parentMenu.querySelectorAll(':scope > .bm-folder > .bm-menu').forEach(m => { if (m !== menu) bmCloseNested(m); });
    }
    if (willOpen) { menu.classList.remove('hidden'); folder.classList.add('open'); }
    else { bmCloseNested(menu); }
}

document.addEventListener('click', ev => {
    if (!ev.target.closest('#bookmarksSection')
        && !ev.target.closest('#bmAddModal')
        && !ev.target.closest('#bmFolderModal')) bmCloseAll();
});

/* ---- Neuen Link zu einem Ordner hinzufügen (Popup) ---- */
let bmAddBtn = null;
let bmAddParent = null;

function bmOpenAdd(btn, parentId) {
    bmAddBtn = btn;
    bmAddParent = parentId;
    const modal = document.getElementById('bmAddModal');
    if (!modal) return;
    document.getElementById('bmAddUrl').value = '';
    document.getElementById('bmAddTitle').value = '';
    const msg = document.getElementById('bmAddMsg');
    if (msg) { msg.textContent = ''; msg.className = 'bm-modal-msg'; }
    modal.classList.remove('hidden');
    document.getElementById('bmAddUrl').focus();
}

function bmCloseAdd() {
    const modal = document.getElementById('bmAddModal');
    if (modal) modal.classList.add('hidden');
    bmAddBtn = null;
    bmAddParent = null;
}

async function bmSaveAdd() {
    const url   = document.getElementById('bmAddUrl').value.trim();
    const title = document.getElementById('bmAddTitle').value.trim();
    const msg   = document.getElementById('bmAddMsg');
    if (!url) {
        if (msg) { msg.textContent = t('bookmarks.urlRequired'); msg.className = 'bm-modal-msg err'; }
        return;
    }
    const saveBtn = document.getElementById('bmAddSaveBtn');
    if (saveBtn) saveBtn.disabled = true;
    try {
        const res = await api('add_bookmark', {
            parent_id: (bmAddParent === 'loose' ? '' : bmAddParent),
            url, title,
        });
        if (res.success) {
            bmInsertLink(bmAddBtn, res.data);
            bmCloseAdd();
        } else if (msg) {
            msg.textContent = res.error || t('common.saveError');
            msg.className = 'bm-modal-msg err';
        }
    } catch (e) {
        if (msg) { msg.textContent = t('common.saveError'); msg.className = 'bm-modal-msg err'; }
    }
    if (saveBtn) saveBtn.disabled = false;
}

function bmInsertLink(addBtn, b) {
    if (!addBtn || !b) return;
    const container = addBtn.parentElement;
    const empty = container.querySelector('.bm-menu-empty');
    if (empty) empty.remove();
    const a = document.createElement('a');
    a.className = 'bm-link';
    a.href = b.url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.title = b.title;
    a.textContent = b.title;
    container.insertBefore(a, addBtn);
}

/* ---- Neuen Ordner anlegen (Popup) ---- */
function bmOpenAddFolder() {
    const modal = document.getElementById('bmFolderModal');
    if (!modal) return;
    document.getElementById('bmFolderName').value = '';
    const msg = document.getElementById('bmFolderMsg');
    if (msg) { msg.textContent = ''; msg.className = 'bm-modal-msg'; }
    modal.classList.remove('hidden');
    document.getElementById('bmFolderName').focus();
}

function bmCloseFolder() {
    const modal = document.getElementById('bmFolderModal');
    if (modal) modal.classList.add('hidden');
}

async function bmSaveFolder() {
    const name = document.getElementById('bmFolderName').value.trim();
    const msg  = document.getElementById('bmFolderMsg');
    if (!name) {
        if (msg) { msg.textContent = t('bookmarks.folderRequired'); msg.className = 'bm-modal-msg err'; }
        return;
    }
    const saveBtn = document.getElementById('bmFolderSaveBtn');
    if (saveBtn) saveBtn.disabled = true;
    try {
        const res = await api('add_bookmark_folder', { title: name });
        if (res.success) {
            location.reload();
        } else if (msg) {
            msg.textContent = res.error || t('common.saveError');
            msg.className = 'bm-modal-msg err';
        }
    } catch (e) {
        if (msg) { msg.textContent = t('common.saveError'); msg.className = 'bm-modal-msg err'; }
    }
    if (saveBtn) saveBtn.disabled = false;
}

/* Klick auf den Hintergrund eines Popups schliesst es */
document.addEventListener('click', ev => {
    if (!ev.target) return;
    if (ev.target.id === 'bmAddModal')    bmCloseAdd();
    if (ev.target.id === 'bmFolderModal') bmCloseFolder();
});

document.addEventListener('DOMContentLoaded', () => {
    const sec = document.getElementById('bookmarksSection');
    if (!sec) return;
    let open = false;
    try { open = localStorage.getItem('tm_bookmarks_open') === '1'; } catch (e) {}
    if (open) {
        sec.classList.remove('hidden');
        const btn = document.getElementById('btnBookmarks');
        if (btn) btn.classList.add('active');
    }
});

/* ================================================================
   Info-Bereich – globaler Freitext mit kleinem WYSIWYG-Editor
   ================================================================ */
function renderInfoView() {
    const view = document.getElementById('infoView');
    if (!view) return;
    const html = (window.INFO_TEXT || '').trim();
    if (html) {
        view.innerHTML = html;
        view.classList.remove('info-empty');
    } else {
        view.textContent = t('info.empty');
        view.classList.add('info-empty');
    }
}

function showInfoEdit() {
    const body = document.getElementById('infoBody');
    if (!body) return;
    body.innerHTML = window.INFO_TEXT || '';
    document.getElementById('infoView').classList.add('hidden');
    document.getElementById('infoEditBtn').classList.add('hidden');
    document.getElementById('infoEdit').classList.remove('hidden');
    body.focus();
}

function hideInfoEdit() {
    document.getElementById('infoEdit').classList.add('hidden');
    document.getElementById('infoView').classList.remove('hidden');
    document.getElementById('infoEditBtn').classList.remove('hidden');
    const msg = document.getElementById('infoMsg');
    if (msg) { msg.textContent = ''; msg.className = 'order-msg'; }
}

async function saveInfo() {
    const body = document.getElementById('infoBody');
    const msg  = document.getElementById('infoMsg');
    if (!body) return;
    const html = body.innerHTML.trim();
    try {
        const res = await api('save_info_text', { text: html });
        if (res.success) {
            window.INFO_TEXT = html;
            renderInfoView();
            hideInfoEdit();
        } else if (msg) {
            msg.textContent = res.error || t('common.saveError');
            msg.className = 'order-msg err';
        }
    } catch (e) {
        if (msg) { msg.textContent = t('common.saveError'); msg.className = 'order-msg err'; }
    }
}

document.addEventListener('DOMContentLoaded', renderInfoView);

/* ================================================================
   Auftrags-Bearbeitungsliste per Drag & Drop sortieren.
   Jede Zeile besteht aus der order-row plus (versteckter) osub-Zeile;
   nach dem Verschieben werden die Paare wieder zusammengefuehrt.
   ================================================================ */
function normalizeOrderRows(tbody) {
    tbody.querySelectorAll('tr.order-row').forEach(row => {
        const sub = document.getElementById('osub-' + row.dataset.id);
        if (sub) row.after(sub);
    });
}

function persistOrderDrag(tbody, dragged) {
    const rows = [...tbody.querySelectorAll('tr.order-row')];
    const i    = rows.indexOf(dragged);
    if (i < 0) return;
    const prev = i > 0 ? rows[i - 1].dataset.id : '';
    const next = i < rows.length - 1 ? rows[i + 1].dataset.id : '';
    api('reorder_orders', { id: dragged.dataset.id, prev, next });
}

document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.getElementById('ordersTbody');
    if (!tbody) return;
    let dragged = null;

    tbody.addEventListener('dragstart', e => {
        const handle = e.target.closest('.order-drag');
        if (!handle) { e.preventDefault(); return; }
        dragged = handle.closest('tr.order-row');
        if (!dragged) return;
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', dragged.dataset.id); } catch (_) {}
        setTimeout(() => dragged.classList.add('dragging'), 0);
    });

    tbody.addEventListener('dragend', () => {
        if (dragged) dragged.classList.remove('dragging');
        tbody.querySelectorAll('.drag-over-top').forEach(r => r.classList.remove('drag-over-top'));
        dragged = null;
    });

    tbody.addEventListener('dragover', e => {
        if (!dragged) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const row = e.target.closest('tr.order-row');
        tbody.querySelectorAll('.drag-over-top').forEach(r => r.classList.remove('drag-over-top'));
        if (row && row !== dragged) row.classList.add('drag-over-top');
    });

    tbody.addEventListener('drop', e => {
        if (!dragged) return;
        e.preventDefault();
        const row = e.target.closest('tr.order-row');
        tbody.querySelectorAll('.drag-over-top').forEach(r => r.classList.remove('drag-over-top'));
        if (row && row !== dragged) {
            const rect  = row.getBoundingClientRect();
            const after = (e.clientY - rect.top) > rect.height / 2;
            tbody.insertBefore(dragged, after ? row.nextElementSibling : row);
        }
        normalizeOrderRows(tbody);
        persistOrderDrag(tbody, dragged);
    });
});
