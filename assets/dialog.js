/* ============================================================
   Wiederverwendbare HTML-Dialoge (Ersatz für alert/confirm)
   Nutzung:
     await Dialog.alert('Nachricht');
     if (await Dialog.confirm('Wirklich?')) { ... }
   Optionen (2. Argument):
     { title, okText, cancelText, danger:true }
   ============================================================ */
(function () {
    'use strict';
    if (window.Dialog) return;

    // Übersetzung mit Fallback: nutzt window.I18N, sonst deutsche Defaults
    // (z. B. im Admin-Bereich, wo i18n noch nicht geladen ist).
    function T(key, def) {
        return (window.I18N && window.I18N[key]) || def;
    }

    // ---- Styles einmalig injizieren ----
    var style = document.createElement('style');
    style.textContent =
        '.tm-dialog-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);' +
        'display:flex;align-items:center;justify-content:center;z-index:10000;' +
        'opacity:0;transition:opacity .15s ease;padding:16px}' +
        '.tm-dialog-overlay.tm-open{opacity:1}' +
        '.tm-dialog{background:#fff;border-radius:10px;width:100%;max-width:420px;' +
        'box-shadow:0 12px 40px rgba(0,0,0,.25);overflow:hidden;font-family:inherit;' +
        'transform:translateY(8px) scale(.98);transition:transform .15s ease}' +
        '.tm-dialog-overlay.tm-open .tm-dialog{transform:none}' +
        '.tm-dialog-head{padding:18px 22px 0}' +
        '.tm-dialog-title{font-size:15px;font-weight:700;color:#1e293b;margin:0}' +
        '.tm-dialog-body{padding:10px 22px 20px;font-size:14px;color:#374151;' +
        'line-height:1.5;white-space:pre-wrap;word-break:break-word}' +
        '.tm-dialog-foot{display:flex;justify-content:flex-end;gap:8px;padding:0 22px 20px}' +
        '.tm-dialog-btn{border:none;border-radius:6px;padding:9px 18px;font-size:13px;' +
        'font-weight:600;cursor:pointer;font-family:inherit}' +
        '.tm-dialog-btn-cancel{background:#e2e8f0;color:#334155}' +
        '.tm-dialog-btn-cancel:hover{background:#cbd5e1}' +
        '.tm-dialog-btn-ok{background:#2563eb;color:#fff}' +
        '.tm-dialog-btn-ok:hover{background:#1d4ed8}' +
        '.tm-dialog-btn-danger{background:#dc2626;color:#fff}' +
        '.tm-dialog-btn-danger:hover{background:#b91c1c}';
    (document.head || document.documentElement).appendChild(style);

    function build(opts) {
        return new Promise(function (resolve) {
            var prevFocus = document.activeElement;

            var overlay = document.createElement('div');
            overlay.className = 'tm-dialog-overlay';

            var dialog = document.createElement('div');
            dialog.className = 'tm-dialog';
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');

            var head = document.createElement('div');
            head.className = 'tm-dialog-head';
            var title = document.createElement('h3');
            title.className = 'tm-dialog-title';
            title.textContent = opts.title;
            head.appendChild(title);

            var body = document.createElement('div');
            body.className = 'tm-dialog-body';
            body.textContent = opts.message;

            var foot = document.createElement('div');
            foot.className = 'tm-dialog-foot';

            var cancelBtn = null;
            if (opts.showCancel) {
                cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'tm-dialog-btn tm-dialog-btn-cancel';
                cancelBtn.textContent = opts.cancelText;
                foot.appendChild(cancelBtn);
            }
            var okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.className = 'tm-dialog-btn ' + (opts.danger ? 'tm-dialog-btn-danger' : 'tm-dialog-btn-ok');
            okBtn.textContent = opts.okText;
            foot.appendChild(okBtn);

            dialog.appendChild(head);
            dialog.appendChild(body);
            dialog.appendChild(foot);
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);

            requestAnimationFrame(function () { overlay.classList.add('tm-open'); });

            function close(result) {
                overlay.classList.remove('tm-open');
                document.removeEventListener('keydown', onKey);
                setTimeout(function () {
                    overlay.remove();
                    if (prevFocus && prevFocus.focus) { try { prevFocus.focus(); } catch (e) {} }
                }, 150);
                resolve(result);
            }
            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); close(opts.showCancel ? false : true); }
                else if (e.key === 'Enter') { e.preventDefault(); close(true); }
            }

            okBtn.addEventListener('click', function () { close(true); });
            if (cancelBtn) cancelBtn.addEventListener('click', function () { close(false); });
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) close(opts.showCancel ? false : true);
            });
            document.addEventListener('keydown', onKey);

            okBtn.focus();
        });
    }

    window.Dialog = {
        alert: function (message, opts) {
            opts = opts || {};
            return build({
                title:      opts.title  || T('dialog.notice', 'Hinweis'),
                message:    String(message),
                okText:     opts.okText || T('dialog.ok', 'OK'),
                showCancel: false,
                danger:     !!opts.danger
            });
        },
        confirm: function (message, opts) {
            opts = opts || {};
            return build({
                title:      opts.title      || T('dialog.confirm', 'Bestätigung'),
                message:    String(message),
                okText:     opts.okText     || T('dialog.ok', 'OK'),
                cancelText: opts.cancelText || T('dialog.cancel', 'Abbrechen'),
                showCancel: true,
                danger:     !!opts.danger
            });
        }
    };
})();
