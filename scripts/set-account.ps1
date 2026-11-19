[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Id,
    [Parameter(Mandatory=$true)][string]$Username,
    [Parameter(Mandatory=$true)][ValidateSet('viewer','member','owner','admin')][string]$Role
)
$ErrorActionPreference = 'Stop'
Push-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
$oldEncoding = $OutputEncoding
try {
    $runtimeUrl = docker compose exec -T api printenv DATABASE_URL
    if ($LASTEXITCODE -ne 0 -or $runtimeUrl -notmatch '^postgres://osiris_app@db:5432/(osiris_dev|osiris_empty_demo)$') { throw 'Start the local API before provisioning an account.' }
    $target = $Matches[1]
    $operatorUrl = "DATABASE_URL=postgres://osiris_dev:osiris_dev_local_only@db:5432/$target"
    $accepted = $false
    while (-not $accepted) {
        $secret = Read-Host "Choose a password for $Username (12-72 UTF-8 bytes)" -AsSecureString
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
        $plainPassword = $null
        try {
            $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
            $byteCount = [Text.Encoding]::UTF8.GetByteCount($plainPassword)
            if ($byteCount -lt 12) {
                Write-Host 'Password is too short. Use at least 12 characters for an ASCII password, then try again.' -ForegroundColor Yellow
                continue
            }
            if ($byteCount -gt 72 -or $plainPassword -match '[\x00\r\n]') {
                Write-Host 'Use one password of at most 72 UTF-8 bytes, without line breaks. Try again.' -ForegroundColor Yellow
                continue
            }
            $OutputEncoding = New-Object System.Text.UTF8Encoding($false)
            $plainPassword | docker compose exec -T -e $operatorUrl api php scripts/provision-user.php $Id $Username $Role
            if ($LASTEXITCODE -ne 0) { throw 'Account provisioning failed. Confirm the API is running, then retry this setup command.' }
            $accepted = $true
            Write-Host "Ready. Sign in as $Username at http://127.0.0.1:8080/login" -ForegroundColor Green
        } finally {
            $plainPassword = $null
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
            $secret.Dispose()
        }
    }

} finally { $OutputEncoding = $oldEncoding; Pop-Location }
