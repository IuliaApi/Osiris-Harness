[CmdletBinding()]
param(
    [switch]$EmptyDemo,
    [switch]$Tools,
    [switch]$Open,
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
    $keyDirectory = Join-Path $PSScriptRoot '.local'
    $null = [IO.Directory]::CreateDirectory($keyDirectory)
    foreach ($filename in 'vault.key','db.key') {
        $keyPath = Join-Path $keyDirectory $filename
        if (Test-Path -LiteralPath $keyPath -PathType Leaf) { continue }
        $bytes = New-Object byte[] 32
        $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
        try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
        $file = [IO.File]::Open($keyPath, [IO.FileMode]::CreateNew)
        try {
            $encoded = [Text.Encoding]::ASCII.GetBytes([Convert]::ToBase64String($bytes))
            $file.Write($encoded, 0, $encoded.Length)
        } finally { $file.Dispose() }
    }
    docker compose up -d --wait db
    if ($LASTEXITCODE -ne 0) { throw 'The local database did not start.' }
    $env:OSIRIS_DATABASE_NAME = & (Join-Path $PSScriptRoot 'scripts\prepare-db.ps1') -EmptyDemo:$EmptyDemo
    $env:OSIRIS_PORT = [string]$Port
    docker compose up -d --build --wait api
    if ($LASTEXITCODE -ne 0) { throw 'Could not start the API. Check docker compose logs api and whether the port is occupied.' }
    Write-Host "Osiris: http://127.0.0.1:$Port/"
    if ($Tools) {
        docker compose --profile tools up -d --wait adminer
        if ($LASTEXITCODE -ne 0) { throw 'Could not start Adminer.' }
        Write-Host 'Adminer: http://127.0.0.1:8083/'
        if ($Open) { Start-Process 'http://127.0.0.1:8083/' }
    }
    if ($Open) { Start-Process "http://127.0.0.1:$Port/" }
} finally {
    foreach ($name in $environmentNames) {
        [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
    }
    Pop-Location
}
