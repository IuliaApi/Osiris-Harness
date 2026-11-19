[CmdletBinding()]
param(
    [ValidateRange(1024,65535)][int]$Port = 8080,
    [switch]$InstallOnly
)
$ErrorActionPreference = 'Stop'
if ($env:OS -ne 'Windows_NT') { throw 'The desktop companion requires an interactive Windows session.' }
$venv = Join-Path $PSScriptRoot '.local/desktop-venv'
$python = Join-Path $venv 'Scripts/python.exe'
if (-not (Test-Path -LiteralPath $python)) {
    & py -3 -m venv $venv
    if ($LASTEXITCODE -ne 0) { throw 'Install Python 3.11 or later with the Windows launcher and Tcl/Tk.' }
}
& $python -m pip --disable-pip-version-check install --only-binary=:all: -r (Join-Path $PSScriptRoot 'desktop/requirements.txt')
if ($LASTEXITCODE -ne 0) { throw 'Could not install the Windows desktop dependencies.' }
if (-not $InstallOnly) {
    & $python (Join-Path $PSScriptRoot 'desktop/companion.py') --origin "http://127.0.0.1:$Port"
    if ($LASTEXITCODE -ne 0) { throw 'The desktop companion exited with an error.' }
}
