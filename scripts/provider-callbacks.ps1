# Build the Compose override for this checkout. An occupied provider port must not
# stop the workspace from starting or be advertised as an automatic callback.
param([Parameter(Mandatory)][int]$WorkspacePort)
$ErrorActionPreference = 'Stop'
$endpoints = Get-Content (Join-Path $PSScriptRoot '../ai-router/callback-endpoints.json') -Raw | ConvertFrom-Json
$uris = @($endpoints.PSObject.Properties | ForEach-Object { [uri]$_.Value })
$owned = @()
$router = docker compose ps -q ai-router
if ($LASTEXITCODE -ne 0) { throw 'Could not inspect the current AI router.' }
if ($router) {
    $bindings = docker inspect --format '{{json .NetworkSettings.Ports}}' $router | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0) { throw 'Could not inspect the current callback ports.' }
    $owned = @($bindings.'8091/tcp' | Where-Object { $_ } | ForEach-Object { "$($_.HostIp)|$($_.HostPort)" })
}
$ports = @()
$enabled = @()
foreach ($uri in $uris) {
    $number = $uri.Port
    if ($number -in $enabled) { continue }
    if ($number -eq $WorkspacePort) {
        # The GitLab registered callback shares the default workspace port.
        if ($number -eq 8080) { $enabled += $number }
        continue
    }
    $addresses = @('127.0.0.1')
    if ($uri.Host -eq 'localhost' -and [Net.Sockets.Socket]::OSSupportsIPv6) { $addresses += '::1' }
    $busy = $false
    foreach ($address in $addresses) {
        if ("$address|$number" -in $owned) { continue }
        # Windows also reserves port ranges without an active listener. Check the
        # actual bind on each loopback address, not just the list of listening PIDs.
        $probe = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Parse($address), $number)
        try { $probe.ExclusiveAddressUse = $true; $probe.Start() }
        catch [Net.Sockets.SocketException] { $busy = $true }
        finally { $probe.Stop() }
        if ($busy) { break }
    }
    if ($busy) { Write-Warning "Provider callback port $number is unavailable; automatic return on this port is off."; continue }
    foreach ($address in $addresses) {
        $bind = if ($address -eq '::1') { '[::1]' } else { $address }
        $ports += "${bind}:${number}:8091"
    }
    $enabled += $number
}
$environment = @{OSIRIS_BROWSER_CALLBACKS='1'; OSIRIS_BROWSER_CALLBACK_PORTS=($enabled -join ',')}
@{services=@{'ai-router'=@{ports=$ports; environment=$environment}}} | ConvertTo-Json -Depth 5 -Compress
