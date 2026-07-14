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

/* „Bearbeitet" – Auftrag heute als bearbeitet markieren (bis morgen ausblenden) */
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

/* Status eines heute bearbeiteten Kunden zurücksetzen */
async function resetWorked(customerId) {
    const res = await api('reset_order_worked', { customer_id: customerId });
    if (res.success) location.reload();
    else Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
}

/* ------------------------------------------------------------------
   Countdown
------------------------------------------------------------------ */
let countdownValue    = 1800;
let countdownInterval = null;

function updateCountdownDisplay() {
    const el = document.getElementById('countdown');
    if (!el) return;
    el.textContent = countdownValue;
    el.classList.toggle('warning', countdownValue <= 600 && countdownValue > 120);
    el.classList.toggle('urgent',  countdownValue <= 120);
}

function startCountdown() {
    clearInterval(countdownInterval);
    countdownInterval = setInterval(() => {
        countdownValue--;
        updateCountdownDisplay();
        if (countdownValue <= 0) {
            clearInterval(countdownInterval);
            Dialog.alert(t('tracker.timeRunning'));
            countdownValue = 1800;
            updateCountdownDisplay();
            startCountdown();
        }
    }, 1000);
}

function resetCountdown() {
    clearInterval(countdownInterval);
    countdownValue = 1800;
    updateCountdownDisplay();
    startCountdown();
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
   Tracker helpers
------------------------------------------------------------------ */
function lockSelect(sel) {
    sel.disabled = true;
}

function showSelect(sel) {
    sel.classList.remove('hidden');
}

function showRunningRows() {
    document.getElementById('rowComment').classList.remove('hidden');
    document.getElementById('rowStop')   .classList.remove('hidden');
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

    /* ---- App page ---- */
    const selectCustomer   = document.getElementById('selectCustomer');
    const selectProject    = document.getElementById('selectProject');
    const selectActivity  = document.getElementById('selectActivity');
    const inputComment    = document.getElementById('inputComment');
    const resetBtn         = document.getElementById('resetBtn');
    const stopBtn          = document.getElementById('stopBtn');
    const customerDisplay  = document.getElementById('customerDisplay');
    const projectDisplay   = document.getElementById('projectDisplay');
    const activityDisplay  = document.getElementById('activityDisplay');
    const startTimeEl      = document.getElementById('startTime');
    const startTimePicker  = document.getElementById('startTimePicker');

    function updateRunningDisplay() {
        customerDisplay.textContent = selectedCustomerName;
        projectDisplay.textContent  = selectedProject;
        activityDisplay.textContent = selectedActivity;
    }

    /* Startzeit anzeigen + Zeitwähler synchron halten (Format HH:MM:SS) */
    function setStartTime(timeStr) {
        trackingStartTime = timeStr;
        startTimeEl.textContent = timeStr || '--:--:--';
        if (startTimePicker) startTimePicker.value = timeStr ? timeStr.slice(0, 5) : '';
    }

    /* Countdown anhand der aktuellen Startzeit neu berechnen */
    function recomputeCountdownFromStart() {
        if (!trackingStartTime) return;
        const [h, m, sec] = trackingStartTime.split(':').map(Number);
        const savedDate = new Date();
        savedDate.setHours(h, m, sec || 0, 0);
        const elapsed = Math.floor((new Date() - savedDate) / 1000);
        countdownValue = Math.max(0, 1800 - elapsed);
        clearInterval(countdownInterval);
        updateCountdownDisplay();
        if (countdownValue > 0) startCountdown();
    }

    /* Startzeit per Zeitwähler ändern */
    if (startTimePicker) {
        startTimePicker.addEventListener('change', async () => {
            if (!startTimePicker.value || !selectedActivity) return;
            setStartTime(startTimePicker.value + ':00');

            await api('save_start_state', {
                customer_id:   selectedCustomerId || '',
                customer_name: selectedCustomerName,
                activity:      selectedActivity,
                project:       selectedProject,
                start_time:    trackingStartTime,
            });

            recomputeCountdownFromStart();
        });
    }

    if (!selectCustomer) return;

    let selectedCustomerId   = null;
    let selectedCustomerName = '';
    let selectedProject      = '';
    let selectedActivity     = '';
    let trackingStartTime    = '';

    function updateShortcuts() {
        const container = document.getElementById('shortcutBtns');
        const row       = document.getElementById('rowShortcuts');
        if (!container || !row) return;

        const matching = (window.SHORTCUTS || []).filter(s =>
            s.activity === selectedActivity &&
            (!s.customer_id || String(s.customer_id) === String(selectedCustomerId))
        );

        container.innerHTML = '';
        if (matching.length > 0) {
            matching.forEach(s => {
                const btn = document.createElement('button');
                btn.type      = 'button';
                btn.className = 'btn';
                btn.style.fontSize = '12px';
                btn.textContent    = s.shortcut_text;
                btn.addEventListener('click', () => {
                    const input = document.getElementById('inputComment');
                    input.value = s.shortcut_text;
                    input.dispatchEvent(new Event('input'));
                });
                container.appendChild(btn);
            });
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
        }
    }

    /* Reset button */
    resetBtn.addEventListener('click', resetCountdown);

    /* Step 1 – customer selected */
    selectCustomer.addEventListener('change', () => {
        const opt = selectCustomer.selectedOptions[0];
        if (!selectCustomer.value) return;

        selectedCustomerId   = selectCustomer.value;
        selectedCustomerName = opt.dataset.name || opt.text;
        selectedProject      = '';

        lockSelect(selectCustomer);

        const projects = (window.CUSTOMER_PROJECTS || {})[selectedCustomerId] || [];

        if (projects.length > 1) {
            selectProject.innerHTML = '<option value="">' + escHtml(t('tracker.chooseProject')) + '</option>';
            projects.forEach(function(p) {
                const o = document.createElement('option');
                o.value = p.name;
                o.textContent = p.name;
                selectProject.appendChild(o);
            });
            showSelect(selectProject);
        } else {
            if (projects.length === 1) {
                selectedProject = projects[0].name;
            }
            showSelect(selectActivity);
        }
    });

    /* Step 2 – project selected */
    selectProject.addEventListener('change', () => {
        if (!selectProject.value) return;
        selectedProject = selectProject.value;
        lockSelect(selectProject);
        showSelect(selectActivity);
    });

    /* Step 3 – activity selected → start timer */
    selectActivity.addEventListener('change', async () => {
        if (!selectActivity.value) return;

        selectedActivity  = selectActivity.value;
        trackingStartTime = nowTime();

        lockSelect(selectActivity);

        updateRunningDisplay();
        setStartTime(trackingStartTime);

        stopBtn.disabled   = true;
        inputComment.value = '';

        showRunningRows();
        updateShortcuts();
        resetCountdown();

        await api('save_start_state', {
            customer_id:   selectedCustomerId || '',
            customer_name: selectedCustomerName,
            activity:      selectedActivity,
            project:       selectedProject,
            start_time:    trackingStartTime,
        });
    });

    /* Stop – save entry */
    stopBtn.addEventListener('click', async () => {
        clearInterval(countdownInterval);

        const comment   = inputComment.value.trim();
        const stopTime  = nowTime();
        const stopDate  = nowDate();

        const res = await api('send_work', {
            customer_id: selectedCustomerId || '',
            activity:    selectedActivity,
            project:     selectedProject,
            comment,
            start_time:  trackingStartTime,
            stop_time:   stopTime,
            stop_date:   stopDate,
        });

        if (res.success) {
            location.reload();
        } else {
            Dialog.alert(t('common.error') + ': ' + (res.error || t('common.unknownError')));
            startCountdown();
        }
    });

    /* Kommentar-Pflichtfeld: Stop-Button nur aktiv wenn Inhalt vorhanden */
    inputComment.addEventListener('input', () => {
        stopBtn.disabled = inputComment.value.trim() === '';
    });

    /* Restore state (page reload while tracking) */
    if (window.USER_STATE) {
        const s = window.USER_STATE;

        selectedCustomerId   = s.customer_id   || null;
        selectedCustomerName = s.customer_name || '';
        selectedActivity     = s.activity      || '';
        selectedProject      = s.project       || '';
        trackingStartTime    = s.start_time    || '';

        // Kunde wiederherstellen und sperren
        if (selectedCustomerId) {
            selectCustomer.value = selectedCustomerId;
            lockSelect(selectCustomer);
        }

        // Projekt wiederherstellen falls mehrere vorhanden
        const projects = (window.CUSTOMER_PROJECTS || {})[selectedCustomerId] || [];
        if (projects.length > 1) {
            selectProject.innerHTML = '<option value="">' + escHtml(t('tracker.chooseProject')) + '</option>';
            projects.forEach(function(p) {
                const o = document.createElement('option');
                o.value = p.name;
                o.textContent = p.name;
                selectProject.appendChild(o);
            });
            selectProject.value = selectedProject;
            showSelect(selectProject);
            lockSelect(selectProject);
        }

        // Tätigkeit wiederherstellen und sperren
        selectActivity.value = selectedActivity;
        showSelect(selectActivity);
        lockSelect(selectActivity);

        updateRunningDisplay();
        setStartTime(trackingStartTime);

        if (trackingStartTime) {
            const [h, m, sec] = trackingStartTime.split(':').map(Number);
            const savedDate = new Date();
            savedDate.setHours(h, m, sec, 0);
            const elapsed = Math.floor((new Date() - savedDate) / 1000);
            countdownValue = Math.max(0, 1800 - elapsed);
        }

        stopBtn.disabled = true;

        showRunningRows();
        updateShortcuts();
        updateCountdownDisplay();

        if (countdownValue > 0) startCountdown();
    } else {
        updateCountdownDisplay();
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
