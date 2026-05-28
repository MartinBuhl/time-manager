<?php
require_once __DIR__ . '/auth.php';
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administration – Time Manager</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <h1>Administration</h1>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div class="admin-grid">

        <a href="customers.php" class="admin-card">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
            </svg>
            Kunden
        </a>

        <a href="trash.php" class="admin-card">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M15 4V3H9v1H4v2h1v13c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V6h1V4h-5zm2 15H7V6h10v13z"/>
            </svg>
            Papierkorb
        </a>

        <a href="users.php" class="admin-card">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
            </svg>
            Benutzer
        </a>

        <a href="config.php" class="admin-card">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7.02 7.02 0 0 0-1.62-.94l-.36-2.54A.484.484 0 0 0 14 2h-4c-.25 0-.46.18-.49.42l-.36 2.54a7.4 7.4 0 0 0-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.35 1.04.65 1.62.94l.36 2.54c.05.24.26.42.5.42h4c.25 0 .46-.18.49-.42l.36-2.54a7.4 7.4 0 0 0 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 0 1 8.4 12 3.6 3.6 0 0 1 12 8.4 3.6 3.6 0 0 1 15.6 12 3.6 3.6 0 0 1 12 15.6z"/>
            </svg>
            Konfiguration
        </a>

        <a href="entries.php" class="admin-card">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/>
            </svg>
            Arbeitszeit
        </a>

        <a href="billing.php" class="admin-card">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M20 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
            </svg>
            Abrechnung
        </a>

        <a href="invoices.php" class="admin-card">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
            </svg>
            Rechnungen
        </a>

        <a href="statistics.php" class="admin-card">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M5 9.2h3V19H5zM10.6 5h2.8v14h-2.8zm5.6 8H19v6h-2.8z"/>
            </svg>
            Statistik
        </a>

        <a href="mailspool.php" class="admin-card">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
            </svg>
            Mailspool
        </a>

        <button type="button" class="admin-card" onclick="openInfoModal()">
            <svg viewBox="0 0 24 24" width="36" height="36">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
            </svg>
            Info
        </button>

    </div>

</div>

<!-- Info-Modal -->
<div id="infoModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
     z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;width:100%;max-width:440px;
                margin:16px;box-shadow:0 8px 32px rgba(0,0,0,.2);overflow:hidden">
        <div style="background:#1e293b;color:#fff;padding:20px 24px;display:flex;
                    align-items:center;justify-content:space-between">
            <span style="font-weight:700;font-size:15px">Time Manager – Info</span>
            <button onclick="closeInfoModal()" style="background:none;border:none;color:#94a3b8;
                    font-size:20px;cursor:pointer;line-height:1">✕</button>
        </div>
        <div style="padding:24px">
            <div style="display:flex;gap:12px;margin-bottom:20px">
                <div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px">
                    <div style="font-size:11px;color:#6b7280;margin-bottom:2px">Installierte Version</div>
                    <div style="font-size:20px;font-weight:700"><?= h(APP_VERSION) ?></div>
                </div>
                <div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px">
                    <div style="font-size:11px;color:#6b7280;margin-bottom:2px">Aktuell verfügbar</div>
                    <div id="infoLatest" style="font-size:20px;font-weight:700;color:#94a3b8">…</div>
                </div>
            </div>
            <div id="infoStatus" style="font-size:13px;color:#6b7280;margin-bottom:16px">
                Prüfe auf Updates…
            </div>
            <div id="infoActions"></div>
        </div>
    </div>
</div>

<style>
button.admin-card { background:#fff;border:1px solid var(--card-border,#e5e7eb);
    cursor:pointer;font-family:inherit;font-size:inherit;text-align:center;
    width:100%;color:inherit; }
</style>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

function openInfoModal() {
    document.getElementById('infoModal').style.display = 'flex';
    checkVersion();
}
function closeInfoModal() {
    document.getElementById('infoModal').style.display = 'none';
}
document.getElementById('infoModal').addEventListener('click', function(e) {
    if (e.target === this) closeInfoModal();
});

async function checkVersion() {
    document.getElementById('infoLatest').textContent  = '…';
    document.getElementById('infoStatus').textContent  = 'Prüfe auf Updates…';
    document.getElementById('infoActions').innerHTML   = '';
    try {
        const res  = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF },
            body: new URLSearchParams({ action: 'check_update' }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fehler');

        const d = data.data;
        document.getElementById('infoLatest').textContent = d.latest || '?';
        document.getElementById('infoLatest').style.color = '';

        if (d.has_update) {
            document.getElementById('infoStatus').innerHTML =
                '<span style="color:#854d0e;font-weight:600">↑ Update verfügbar</span>';
            document.getElementById('infoActions').innerHTML =
                '<a href="update.php" style="display:inline-flex;align-items:center;gap:6px;' +
                'background:#2563eb;color:#fff;text-decoration:none;padding:10px 20px;' +
                'border-radius:6px;font-size:13px;font-weight:600">' +
                '↑ Jetzt auf v' + d.latest + ' updaten</a>';
        } else {
            document.getElementById('infoStatus').innerHTML =
                '<span style="color:#15803d;font-weight:600">✓ System ist aktuell</span>';
        }
    } catch (e) {
        document.getElementById('infoLatest').textContent = '–';
        document.getElementById('infoStatus').innerHTML =
            '<span style="color:#b91c1c">⚠ ' + e.message + '</span>';
    }
}
</script>
</body>
</html>
