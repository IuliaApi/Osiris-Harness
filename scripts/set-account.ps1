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
    $secret = Read-Host "Password for $Username (12-72 UTF-8 bytes)" -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
    try {
        $OutputEncoding = New-Object System.Text.UTF8Encoding($false)
        [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) | docker compose exec -T -e $operatorUrl api php scripts/provision-user.php $Id $Username $Role
        if ($LASTEXITCODE -ne 0) { throw 'Account provisioning failed.' }
    } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer); $secret.Dispose() }
} finally { $OutputEncoding = $oldEncoding; Pop-Location }
