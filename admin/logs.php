<?php
require_once __DIR__ . '/auth.php';

define('LOG_DIR', dirname(__DIR__) . '/log');

// ----------------------------------------------------------------
// Optional: Inhalt einer einzelnen Logdatei anzeigen
// ----------------------------------------------------------------
$viewFile    = null;
$viewContent = null;
$viewError   = null;

if (isset($_GET['file']) && $_GET['file'] !== '') {
    $name = basename((string)$_GET['file']);          // Pfad-Traversal verhindern
    if (!preg_match('/^[A-Za-z0-9_.\-]+\.log$/', $name)) {
        $viewError = 'Ungültiger Dateiname.';
    } else {
        $path = LOG_DIR . '/' . $name;
        $real = realpath($path);
        if ($real === false || strpos($real, realpath(LOG_DIR) . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            $viewError = 'Logdatei nicht gefunden.';
        } else {
            $viewFile    = $name;
            $viewContent = (string)file_get_contents($real);
        }
    }
}

// ----------------------------------------------------------------
// Liste aller Logdateien
// ----------------------------------------------------------------
$logs = [];
if (is_dir(LOG_DIR)) {
    foreach (glob(LOG_DIR . '/*.log') ?: [] as $path) {
        $logs[] = [
            'name'  => basename($path),
            'size'  => filesize($path),
            'mtime' => filemtime($path),
        ];
    }
    // Neueste zuerst
    usort($logs, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

function fmtSize(int $bytes): string
{
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logs – Administration</title>
<script src="../assets/theme-init.js"></script>
<link rel="stylesheet" href="../assets/style.css">
<style>
.log-view {
    background: #1a1a1a;
    color: #d4d4d4;
    padding: 18px 20px;
    border-radius: 6px;
    font-family: 'Consolas', 'Menlo', 'Courier New', monospace;
    font-size: 12.5px;
    line-height: 1.55;
    white-space: pre-wrap;
    word-break: break-word;
    overflow-x: auto;
    max-height: 70vh;
    overflow-y: auto;
}
.log-meta { font-size: 12px; color: var(--text-muted); margin-bottom: 12px; }
.col-num { white-space: nowrap; }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <div>
            <h1>Logs</h1>
            <div class="admin-breadcrumb">
                <a href="index.php">Administration</a> &rsaquo; Logs
                <?php if ($viewFile): ?>&rsaquo; <?= h($viewFile) ?><?php endif; ?>
            </div>
        </div>
        <a href="../index.php" class="btn-logout">&#8592; Zur App</a>
    </div>

    <div class="admin-section">

    <?php if ($viewError): ?>
        <div class="admin-msg admin-msg--err" style="margin-bottom:16px"><?= h($viewError) ?></div>
        <a href="logs.php" class="btn">&#8592; Zurück zur Übersicht</a>

    <?php elseif ($viewFile !== null): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap">
            <h2 style="margin:0"><?= h($viewFile) ?></h2>
            <a href="logs.php" class="btn">&#8592; Zurück zur Übersicht</a>
        </div>
        <div class="log-view"><?= h($viewContent !== '' ? $viewContent : '(Logdatei ist leer)') ?></div>

    <?php else: ?>
        <h2>Logdateien</h2>

        <?php if (empty($logs)): ?>
            <p class="empty-message">Noch keine Logdateien vorhanden.</p>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th>Dateiname</th>
                        <th class="col-num">Geändert</th>
                        <th class="col-num">Größe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr class="entry-row">
                        <td><?= h($l['name']) ?></td>
                        <td class="col-num"><?= h(date('d.m.Y H:i:s', $l['mtime'])) ?></td>
                        <td class="col-num"><?= h(fmtSize((int)$l['size'])) ?></td>
                        <td style="white-space:nowrap">
                            <a href="logs.php?file=<?= h(rawurlencode($l['name'])) ?>" class="btn">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    </div>

</div>
</body>
</html>
