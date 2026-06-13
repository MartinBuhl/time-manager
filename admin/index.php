<?php
require_once __DIR__ . '/auth.php';

$layoutRow = db()->prepare('SELECT admin_layout FROM tm_users WHERE id = ? LIMIT 1');
$layoutRow->execute([$adminUserId]);
$savedLayout = $layoutRow->fetchColumn() ?: null;
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administration – Time Manager</title>
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css?v=<?php echo APP_VERSION; ?>">
<style>
.admin-zone {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 16px;
    min-height: 90px;
    padding: 4px 2px;
    transition: background .15s;
}
.admin-zone.drag-over {
    background: #eff6ff;
    border-radius: var(--radius);
}
.admin-card {
    cursor: grab;
    user-select: none;
    position: relative;
}
.admin-card:active { cursor: grabbing; }
.admin-card.dragging {
    opacity: .35;
    outline: 2px dashed #2563eb;
    outline-offset: 2px;
}
.drag-handle {
    position: absolute;
    top: 6px;
    right: 8px;
    font-size: 12px;
    color: #cbd5e1;
    line-height: 1;
    pointer-events: none;
}
.zone-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 20px 0 16px;
    color: #94a3b8;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.zone-divider::before,
.zone-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e2e8f0;
}
.zone-label {
    font-size: 11px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 10px;
}
button.admin-card {
    border: none;
    cursor: grab;
    font-family: inherit;
    font-size: inherit;
    color: var(--text);
}
button.admin-card:active { cursor: grabbing; }
/* Info-Modal */
#infoModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
    z-index:1000; align-items:center; justify-content:center; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <h1>Administration</h1>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div style="padding: 0 0 24px">

        <div class="zone-label">Favoriten</div>
        <div id="zone-top" class="admin-zone"></div>

        <div class="zone-divider">Weitere</div>

        <div id="zone-bottom" class="admin-zone"></div>

    </div>

</div>

<!-- Info-Modal -->
<div id="infoModal">
    <div style="background:#fff;border-radius:10px;width:100%;max-width:440px;
                margin:16px;box-shadow:0 8px 32px rgba(0,0,0,.2);overflow:hidden">
        <div style="background:#1e293b;color:#fff;padding:20px 24px;display:flex;
                    align-items:center;justify-content:space-between">
            <span style="font-weight:700;font-size:15px">Time Manager – Info</span>
            <button onclick="closeInfoModal()" style="background:none;border:none;color:#94a3b8;
                    font-size:20px;cursor:pointer;line-height:1">&#x2715;</button>
        </div>
        <div style="padding:24px">
            <div style="display:flex;gap:12px;margin-bottom:20px">
                <div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px">
                    <div style="font-size:11px;color:#6b7280;margin-bottom:2px">Installierte Version</div>
                    <div style="font-size:20px;font-weight:700;color:#1e293b"><?= h(APP_VERSION) ?></div>
                </div>
                <div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px">
                    <div style="font-size:11px;color:#6b7280;margin-bottom:2px">Aktuell verfügbar</div>
                    <div id="infoLatest" style="font-size:20px;font-weight:700;color:#374151">…</div>
                </div>
            </div>
            <div id="infoStatus" style="font-size:13px;color:#374151;margin-bottom:16px">
                Prüfe auf Updates…
            </div>
            <div id="infoActions"></div>
        </div>
    </div>
</div>

<script>
const CSRF        = <?= json_encode($_SESSION['csrf_token']) ?>;
const SAVED_LAYOUT = <?= $savedLayout ?? 'null' ?>;

// ----------------------------------------------------------------
// Kartendefinitionen
// ----------------------------------------------------------------
const CARDS = {
    customers: {
        label: 'Kunden', href: 'customers.php',
        icon: '<path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>',
    },
    billing: {
        label: 'Abrechnung', href: 'billing.php',
        icon: '<path d="M20 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>',
    },
    invoices: {
        label: 'Rechnungen', href: 'invoices.php',
        icon: '<path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>',
    },
    entries: {
        label: 'Arbeitszeit', href: 'entries.php',
        icon: '<path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/>',
    },
    trash: {
        label: 'Papierkorb', href: 'trash.php',
        icon: '<path d="M15 4V3H9v1H4v2h1v13c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V6h1V4h-5zm2 15H7V6h10v13z"/>',
    },
    users: {
        label: 'Benutzer', href: 'users.php',
        icon: '<path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>',
    },
    config: {
        label: 'Konfiguration', href: 'config.php',
        icon: '<path d="M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7.02 7.02 0 0 0-1.62-.94l-.36-2.54A.484.484 0 0 0 14 2h-4c-.25 0-.46.18-.49.42l-.36 2.54a7.4 7.4 0 0 0-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.35 1.04.65 1.62.94l.36 2.54c.05.24.26.42.5.42h4c.25 0 .46-.18.49-.42l.36-2.54a7.4 7.4 0 0 0 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 0 1 8.4 12 3.6 3.6 0 0 1 12 8.4 3.6 3.6 0 0 1 15.6 12 3.6 3.6 0 0 1 12 15.6z"/>',
    },
    statistics: {
        label: 'Statistik', href: 'statistics.php',
        icon: '<path d="M5 9.2h3V19H5zM10.6 5h2.8v14h-2.8zm5.6 8H19v6h-2.8z"/>',
    },
    mailspool: {
        label: 'Mailspool', href: 'mailspool.php',
        icon: '<path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>',
    },
    logs: {
        label: 'Logs', href: 'logs.php',
        icon: '<path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM8 13h8v1.5H8V13zm0 3h8v1.5H8V16z"/>',
    },
    backup: {
        label: 'Backup', href: 'backup.php',
        icon: '<path d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/>',
    },
    info: {
        label: 'Info', href: null, onclick: 'openInfoModal()',
        icon: '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>',
    },
};

const DEFAULT_LAYOUT = {
    top:    ['customers', 'billing', 'invoices', 'entries'],
    bottom: ['trash', 'users', 'config', 'statistics', 'mailspool', 'logs', 'backup', 'info'],
};
// ----------------------------------------------------------------
// Layout laden / speichern
// ----------------------------------------------------------------
function loadLayout() {
    if (SAVED_LAYOUT && Array.isArray(SAVED_LAYOUT.top) && Array.isArray(SAVED_LAYOUT.bottom)) {
        // Neue Karten die noch nicht in der gespeicherten Liste sind → bottom
        const known = [...SAVED_LAYOUT.top, ...SAVED_LAYOUT.bottom];
        const extra = Object.keys(CARDS).filter(k => !known.includes(k));
        return { top: SAVED_LAYOUT.top, bottom: [...SAVED_LAYOUT.bottom, ...extra] };
    }
    return DEFAULT_LAYOUT;
}

function saveLayout() {
    const top    = [...document.getElementById('zone-top').querySelectorAll('.admin-card')].map(el => el.dataset.id);
    const bottom = [...document.getElementById('zone-bottom').querySelectorAll('.admin-card')].map(el => el.dataset.id);
    fetch('api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: new URLSearchParams({ action: 'save_admin_layout', layout: JSON.stringify({ top, bottom }) }),
    });
}

// ----------------------------------------------------------------
// Karten erstellen
// ----------------------------------------------------------------
function makeCard(id) {
    const def = CARDS[id];
    if (!def) return null;

    let el;
    if (def.href) {
        el = document.createElement('a');
        el.href = def.href;
    } else {
        el = document.createElement('button');
        el.type = 'button';
        if (def.onclick) el.setAttribute('onclick', def.onclick);
    }

    el.className   = 'admin-card';
    el.dataset.id  = id;
    el.draggable   = true;
    el.innerHTML   =
        '<span class="drag-handle">&#8942;&#8942;</span>' +
        '<svg viewBox="0 0 24 24" width="36" height="36">' + def.icon + '</svg>' +
        def.label;
    return el;
}

function renderZone(zoneEl, ids) {
    zoneEl.innerHTML = '';
    ids.forEach(id => {
        const card = makeCard(id);
        if (card) zoneEl.appendChild(card);
    });
}

// ----------------------------------------------------------------
// Drag & Drop
// ----------------------------------------------------------------
let dragged    = null;
let dragClone  = null;

function getDragAfterElement(zone, x, y) {
    const cards = [...zone.querySelectorAll('.admin-card:not(.dragging)')];
    return cards.reduce((closest, child) => {
        const box    = child.getBoundingClientRect();
        const midY   = box.top  + box.height / 2;
        const midX   = box.left + box.width  / 2;
        const distY  = y - midY;
        const distX  = x - midX;
        const dist   = Math.hypot(distX, distY);
        // Insert before this card if we're in the top-left half
        if (distY < 0 && dist < closest.dist) {
            return { dist, element: child };
        }
        return closest;
    }, { dist: Infinity }).element;
}

function initDragDrop() {
    document.querySelectorAll('.admin-card').forEach(card => {
        card.addEventListener('dragstart', e => {
            dragged = card;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.dataset.id);
            setTimeout(() => card.classList.add('dragging'), 0);
        });

        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            document.querySelectorAll('.admin-zone').forEach(z => z.classList.remove('drag-over'));
            dragged = null;
            saveLayout();
        });
    });

    document.querySelectorAll('.admin-zone').forEach(zone => {
        zone.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            zone.classList.add('drag-over');
        });

        zone.addEventListener('dragleave', e => {
            if (!zone.contains(e.relatedTarget)) {
                zone.classList.remove('drag-over');
            }
        });

        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('drag-over');
            if (!dragged) return;

            const after = getDragAfterElement(zone, e.clientX, e.clientY);
            if (after) {
                zone.insertBefore(dragged, after);
            } else {
                zone.appendChild(dragged);
            }
            saveLayout();
        });
    });
}

// ----------------------------------------------------------------
// Init
// ----------------------------------------------------------------
(function() {
    const layout = loadLayout();
    renderZone(document.getElementById('zone-top'),    layout.top);
    renderZone(document.getElementById('zone-bottom'), layout.bottom);
    initDragDrop();
})();

// ----------------------------------------------------------------
// Info-Modal
// ----------------------------------------------------------------
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
    document.getElementById('infoLatest').textContent = '…';
    document.getElementById('infoStatus').textContent = 'Prüfe auf Updates…';
    document.getElementById('infoActions').innerHTML  = '';
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

        if (d.has_update) {
            document.getElementById('infoStatus').innerHTML =
                '<span style="color:#854d0e;font-weight:600">&#x2191; Update verfügbar</span>';
            document.getElementById('infoActions').innerHTML =
                '<a href="update.php" style="display:inline-flex;align-items:center;gap:6px;' +
                'background:#2563eb;color:#fff;text-decoration:none;padding:10px 20px;' +
                'border-radius:6px;font-size:13px;font-weight:600">' +
                '&#x2191; Jetzt auf v' + d.latest + ' updaten</a>';
        } else {
            document.getElementById('infoStatus').innerHTML =
                '<span style="color:#15803d;font-weight:600">&#x2713; System ist aktuell</span>';
        }
    } catch(e) {
        document.getElementById('infoLatest').textContent = '–';
        document.getElementById('infoStatus').innerHTML =
            '<span style="color:#b91c1c">&#x26A0; ' + e.message + '</span>';
    }
}
</script>
</body>
</html>
