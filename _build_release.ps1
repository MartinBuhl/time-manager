# Time Manager – Release ZIP bauen
# Aufruf: .\_build_release.ps1
# Erzeugt: time-manager-vX.X.X.zip (inkl. vendor/, ohne config.php etc.)

$version = (Get-Content "$PSScriptRoot\VERSION").Trim()
$zipName = "time-manager-v$version.zip"
$zipPath = "$PSScriptRoot\..\$zipName"

# Ausschliessen
$exclude = @(
    '_build_release.ps1',
    '.git',
    '.gitignore',
    'config.php',
    'invoices',
    '_installer\installed.lock',
    'migrate.php',
    'migrate_run.php',
    'migrate_test.php',
    'migrate_customers.php',
    'backup_tm_entries.php',
    'arbeitszeit.sql'
)

Write-Host "Baue Release $version ..." -ForegroundColor Cyan

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

$items = Get-ChildItem -Path $PSScriptRoot -Force |
    Where-Object { $exclude -notcontains $_.Name }

Compress-Archive -Path ($items.FullName) -DestinationPath $zipPath

Write-Host "Fertig: $zipName" -ForegroundColor Green
Write-Host ""
Write-Host "Naechste Schritte:" -ForegroundColor Yellow
Write-Host "  1. git tag v$version && git push origin main --tags"
Write-Host "  2. Auf GitHub: Releases -> New release -> Tag v$version auswaehlen"
Write-Host "  3. ZIP als Asset hochladen: $zipName"
