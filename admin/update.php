<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

define('GH_REPO',  cfg('github_repo', ''));
define('ROOT_DIR', dirname(__DIR__));

// Dateien/Verzeichnisse, die beim Update niemals überschrieben werden
$PROTECTED = ['config.php', '_installer/installed.lock', 'invoices'];

function ghHeaders(): array
{
    $token   = cfg('github_token', '');
    $headers = ['User-Agent: TimeManager-Updater/' . APP_VERSION,
                'Accept: application/vnd.github+json'];
    if ($token !== '') {
        $headers[] = "Authorization: Bearer {$token}";
    }
    return ['http' => ['header' => implode("\r\n", $headers), 'timeout' => 15]];
}

function fetchRelease(): ?array
{
    $json = @file_get_contents(
        'https://api.github.com/repos/' . GH_REPO . '/releases/latest',
        false,
        stream_context_create(ghHeaders())
    );
    if ($json === false) return null;
    return json_decode($json, true) ?: null;
}

function isProtected(string $rel, array $protected): bool
{
    $rel = str_replace('\\', '/', $rel);
    foreach ($protected as $p) {
        if ($rel === $p || str_starts_with($rel, rtrim($p, '/') . '/')) {
            return true;
        }
    }
    return false;
}

function deleteDir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function mergeDir(string $src, string $dst, array $protected): array
{
    $copied = [];
    $iter   = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $item) {
        $rel  = ltrim(str_replace([$src, '\\'], ['', '/'], $item->getPathname()), '/');
        $dest = $dst . '/' . $rel;
        if (isProtected($rel, $protected)) continue;
        if ($item->isDir()) {
            if (!is_dir($dest)) mkdir($dest, 0755, true);
        } else {
            copy($item->getPathname(), $dest);
            $copied[] = $rel;
        }
    }
    return $copied;
}

function runMigrations(): array
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tm_migrations` (
        `id`         INT          NOT NULL AUTO_INCREMENT,
        `filename`   VARCHAR(255) NOT NULL,
        `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_filename` (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $applied = $pdo->query('SELECT filename FROM tm_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files   = glob(ROOT_DIR . '/_migrations/*.sql') ?: [];
    sort($files);

    $ran = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) continue;
        $pdo->exec((string)file_get_contents($file));
        $pdo->prepare('INSERT INTO tm_migrations (filename) VALUES (?)')->execute([$name]);
        $ran[] = $name;
    }
    return $ran;
}

// ----------------------------------------------------------------
// POST: Update durchführen
// ----------------------------------------------------------------
$result  = null;
$csrfOk  = isset($_POST['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'perform') {
    if (!$csrfOk) {
        $result = ['ok' => false, 'msg' => 'Ungültiger Sicherheitstoken.'];
    } else {
        set_time_limit(180);
        ignore_user_abort(true);

        try {
            // 1. Release-Info holen
            $release = fetchRelease();
            if (GH_REPO === '') throw new RuntimeException('GitHub-Repository nicht konfiguriert (Administration → Konfiguration → System).');
            if (!$release) throw new RuntimeException('GitHub API nicht erreichbar.');

            $dlUrl = '';
            foreach ($release['assets'] ?? [] as $asset) {
                if (str_ends_with($asset['name'], '.zip')) {
                    $dlUrl = $asset['browser_download_url'];
                    break;
                }
            }
            if ($dlUrl === '') throw new RuntimeException('Kein ZIP-Asset im Release gefunden.');

            // 2. ZIP herunterladen
            $zipData = @file_get_contents($dlUrl, false, stream_context_create(ghHeaders()));
            if ($zipData === false) throw new RuntimeException('ZIP-Download fehlgeschlagen.');

            $zipFile = sys_get_temp_dir() . '/tm_update_' . uniqid() . '.zip';
            file_put_contents($zipFile, $zipData);

            // 3. Entpacken
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) throw new RuntimeException('ZIP-Datei konnte nicht geöffnet werden.');

            $tempDir = sys_get_temp_dir() . '/tm_extract_' . uniqid();
            mkdir($tempDir, 0755, true);
            $zip->extractTo($tempDir);
            $zip->close();
            unlink($zipFile);

            // 4. Dateien kopieren
            global $PROTECTED;
            $copied = mergeDir($tempDir, ROOT_DIR, $PROTECTED);
            deleteDir($tempDir);

            // 5. Migrationen ausführen
            $migrations = runMigrations();

            $newVersion = is_readable(ROOT_DIR . '/VERSION')
                ? trim(file_get_contents(ROOT_DIR . '/VERSION'))
                : '?';

            $result = [
                'ok'         => true,
                'version'    => $newVersion,
                'files'      => count($copied),
                'migrations' => $migrations,
            ];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'msg' => $e->getMessage()];
            if (isset($tempDir) && is_dir($tempDir)) deleteDir($tempDir);
            if (isset($zipFile) && file_exists($zipFile)) unlink($zipFile);
        }
    }
}

// ----------------------------------------------------------------
// GET: Release-Info laden
// ----------------------------------------------------------------
$release     = null;
$fetchError  = null;
if ($result === null) {
    if (GH_REPO === '') {
        $fetchError = 'GitHub-Repository nicht konfiguriert. Bitte unter Administration → Konfiguration → System den Wert "GitHub Repository" eintragen (z.B. benutzer/time-manager).';
    } else {
        $release = fetchRelease();
        if ($release === null) $fetchError = 'GitHub API nicht erreichbar. Bitte Netzwerkverbindung prüfen.';
    }
}

$latestTag  = ltrim($release['tag_name'] ?? '', 'v');
$hasUpdate  = $latestTag !== '' && version_compare($latestTag, APP_VERSION, '>');
$upToDate   = $latestTag !== '' && !$hasUpdate;
$dlUrl      = '';
foreach ($release['assets'] ?? [] as $asset) {
    if (str_ends_with($asset['name'], '.zip')) { $dlUrl = $asset['browser_download_url']; break; }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>System-Update – Time Manager</title>
<link rel="stylesheet" href="../assets/style.css">
<style>
.update-card { background:#fff; border:1px solid var(--card-border); border-radius:var(--radius);
               padding:28px 32px; max-width:640px; margin:0 auto 24px; }
.update-card h2 { font-size:15px; font-weight:700; margin-bottom:16px; }
.ver-row { display:flex; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.ver-box { flex:1; min-width:140px; background:#f8fafc; border:1px solid #e2e8f0;
           border-radius:6px; padding:14px 18px; }
.ver-box .label { font-size:11px; color:#6b7280; margin-bottom:4px; }
.ver-box .val   { font-size:22px; font-weight:700; color:#1e293b; }
.badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px;
         border-radius:12px; font-size:12px; font-weight:600; }
.badge-ok      { background:#dcfce7; color:#15803d; }
.badge-update  { background:#fef9c3; color:#854d0e; }
.badge-error   { background:#fee2e2; color:#b91c1c; }
.btn-update { background:#2563eb; color:#fff; border:none; border-radius:6px;
              padding:11px 24px; font-size:14px; font-weight:600; cursor:pointer;
              display:inline-flex; align-items:center; gap:8px; }
.btn-update:hover { background:#1d4ed8; }
.btn-update:disabled { background:#93c5fd; cursor:not-allowed; }
.migration-list { margin:8px 0 0 18px; font-size:13px; color:#374151; }
.result-box { border-radius:8px; padding:20px 24px; margin-bottom:20px; }
.result-ok   { background:#f0fdf4; border:1px solid #bbf7d0; }
.result-fail { background:#fef2f2; border:1px solid #fecaca; }
.result-box h3 { font-size:15px; font-weight:700; margin-bottom:10px; }
.spinner { display:inline-block; width:16px; height:16px; border:2px solid #fff;
           border-top-color:transparent; border-radius:50%; animation:spin .7s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
</style>
</head>
<body>
<div class="admin-page">

    <div class="admin-header">
        <h1>System-Update</h1>
        <a href="index.php" class="btn-logout">← Administration</a>
    </div>

    <div style="padding:24px">

    <?php if ($result !== null): ?>
        <!-- ---- Ergebnis nach Update ---- -->
        <?php if ($result['ok']): ?>
        <div class="result-box result-ok">
            <h3>✓ Update erfolgreich!</h3>
            <p style="font-size:13px;color:#374151">
                System wurde auf Version <strong><?= h($result['version']) ?></strong> aktualisiert.
                <?= $result['files'] ?> Dateien wurden eingespielt.
            </p>
            <?php if (!empty($result['migrations'])): ?>
            <p style="font-size:13px;color:#374151;margin-top:8px">Migrationen ausgeführt:</p>
            <ul class="migration-list">
                <?php foreach ($result['migrations'] as $m): ?>
                <li><?= h($m) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <a href="index.php" class="btn-update" style="text-decoration:none">← Zur Administration</a>

        <?php else: ?>
        <div class="result-box result-fail">
            <h3>✗ Update fehlgeschlagen</h3>
            <p style="font-size:13px;color:#b91c1c"><?= h($result['msg']) ?></p>
        </div>
        <a href="update.php" class="btn-update" style="text-decoration:none">Erneut versuchen</a>
        <?php endif; ?>

    <?php else: ?>
        <!-- ---- Versionsinfo & Update-Formular ---- -->
        <div class="update-card">
            <h2>Versionsübersicht</h2>

            <div class="ver-row">
                <div class="ver-box">
                    <div class="label">Installierte Version</div>
                    <div class="val"><?= h(APP_VERSION) ?></div>
                </div>
                <div class="ver-box">
                    <div class="label">Aktuelle Version (GitHub)</div>
                    <div class="val"><?= $latestTag !== '' ? h($latestTag) : '–' ?></div>
                </div>
            </div>

            <?php if ($fetchError): ?>
                <span class="badge badge-error">⚠ <?= h($fetchError) ?></span>

            <?php elseif ($upToDate): ?>
                <span class="badge badge-ok">✓ System ist aktuell</span>

            <?php elseif ($hasUpdate): ?>
                <span class="badge badge-update">↑ Update verfügbar: v<?= h($latestTag) ?></span>
                <p style="font-size:13px;color:#6b7280;margin:12px 0 20px">
                    Das Update überschreibt alle Programmdateien außer
                    <code>config.php</code>, <code>_installer/installed.lock</code> und dem
                    <code>invoices/</code>-Verzeichnis. Anschließend werden ausstehende
                    Datenbankmigrationen automatisch ausgeführt.
                </p>
                <form method="post" id="updateForm">
                    <input type="hidden" name="action"     value="perform">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <button type="submit" class="btn-update" id="updateBtn">
                        ↑ Jetzt auf v<?= h($latestTag) ?> updaten
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($hasUpdate && $dlUrl !== ''): ?>
        <div class="update-card" style="font-size:12px;color:#6b7280">
            <strong>Release-Name:</strong> <?= h($release['name'] ?? '') ?><br>
            <?php if (!empty($release['body'])): ?>
            <strong>Änderungen:</strong><br>
            <pre style="white-space:pre-wrap;font-size:12px;margin-top:4px"><?= h($release['body']) ?></pre>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    </div>
</div>

<script>
document.getElementById('updateForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('updateBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Update wird eingespielt…';
});
</script>
</body>
</html>
