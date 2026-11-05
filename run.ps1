[CmdletBinding()]
param(
    [switch]$EmptyDemo,
    [ValidateRange(1024,65535)]
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$environmentNames = 'OSIRIS_DATABASE_NAME', 'OSIRIS_PORT'
$previousEnvironment = @{}
foreach ($name in $environmentNames) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
Push-Location -LiteralPath $PSScriptRoot
try {
    docker compose up -d --wait db
    if ($LASTEXITCODE -ne 0) { throw 'The local database did not start.' }
    # Start local Adminer database viewer for live PostgreSQL demonstration.
    docker compose --profile tools up -d --wait adminer
    if ($LASTEXITCODE -ne 0) { throw 'The Adminer database viewer did not start (port 8083 may be occupied).' }
    Write-Host 'Database viewer (Adminer): http://127.0.0.1:8083/'
    Write-Host 'Adminer login: PostgreSQL | server db | user osiris_dev | database osiris_dev'
    # Open the requested local DB viewer in the user's default Windows browser.
    try { Start-Process 'http://127.0.0.1:8083/' -ErrorAction Stop } catch { Write-Warning 'Could not open browser automatically; use http://127.0.0.1:8083/' }
    $env:OSIRIS_DATABASE_NAME = & (Join-Path $PSScriptRoot 'scripts\prepare-db.ps1') -EmptyDemo:$EmptyDemo
    $env:OSIRIS_PORT = [string]$Port
    docker compose build api
    if ($LASTEXITCODE -ne 0) { throw 'Could not build the PHP image.' }
    Write-Host "Osiris Build 2: http://127.0.0.1:$Port/"
    docker compose run --rm --service-ports --no-deps api
    if ($LASTEXITCODE -notin @(0, 130)) { throw 'The PHP server exited with an error.' }
} finally {
    foreach ($name in $environmentNames) {
        [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
    }
    Pop-Location
}
