[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$script:checks = 0
$script:failedChecks = 0
$databases = New-Object 'System.Collections.Generic.List[string]'
$containers = New-Object 'System.Collections.Generic.List[string]'
$client = $null
$cleanupErrors = @()

function Invoke-Docker {
    param([string[]]$Arguments)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw "docker $($Arguments -join ' ') failed (exit $LASTEXITCODE)." }
}

function Invoke-Sql {
    param([string]$Database, [string]$Sql)
    $Sql | & docker compose exec -T db psql -X -q -t -A -U osiris_dev -d $Database -v ON_ERROR_STOP=1
    if ($LASTEXITCODE -ne 0) { throw "SQL failed in $Database (exit $LASTEXITCODE)." }
}

function Assert-Check {
    param([bool]$Condition, [string]$Name)
    $script:checks++
    if (-not $Condition) {
        $script:failedChecks++
        Write-Host "FAIL: $Name" -ForegroundColor Red
    }
}

function Invoke-Http {
    param([string]$Method, [string]$Url)
    $request = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::new($Method), $Url)
    $response = $null
    try {
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        $contentType = ''
        if ($null -ne $response.Content.Headers.ContentType) {
            $contentType = $response.Content.Headers.ContentType.MediaType
        }
        [pscustomobject]@{
            Status = [int]$response.StatusCode
            Body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
            ContentType = $contentType
            Allow = @($response.Content.Headers.Allow) -join ', '
        }
    } finally {
        if ($null -ne $response) { $response.Dispose() }
        $request.Dispose()
    }
}

function Start-TestApi {
    param([string]$Database)
    $name = "osiris-build1-$($Database.Substring(12))"
    $present = @(Invoke-Docker -Arguments @('container', 'ls', '-a', '-q', '--filter', "name=^/$name`$"))
    if ($present.Count -gt 0) { throw "Refusing to reuse an existing container: $name" }
    $containers.Add($name)
    $output = @(Invoke-Docker -Arguments @(
        'compose', 'run', '--rm', '-d', '--no-deps', '--name', $name,
        '-p', '127.0.0.1::8080', '-e', "DATABASE_URL=postgres://osiris_dev:osiris_dev_local_only@db:5432/$Database", 'api'
    ))
    $id = ($output | Select-Object -Last 1).Trim()
    if ($id -notmatch '^[0-9a-f]{12,64}$') { throw "No container ID returned for $name." }
    $bindings = @(Invoke-Docker -Arguments @('port', $id, '8080/tcp'))
    if ($bindings.Count -ne 1 -or $bindings[0].Trim() -notmatch '^127\.0\.0\.1:(\d+)$') {
        throw "Expected one ephemeral loopback port for $name; got $bindings."
    }
    $url = "http://127.0.0.1:$($Matches[1])"
    $deadline = [DateTime]::UtcNow.AddSeconds(30)
    $last = 'No response'
    do {
        try {
            $response = Invoke-Http -Method GET -Url "$url/sessions"
            if ($response.Status -eq 200) { return $url }
            $last = "HTTP $($response.Status): $($response.Body)"
        } catch { $last = $_.Exception.Message }
        Start-Sleep -Milliseconds 250
    } while ([DateTime]::UtcNow -lt $deadline)
    throw "API readiness failed for ${Database}: $last"
}

function Convert-Json {
    param([string]$Json)
    $options = @{}
    if ((Get-Command ConvertFrom-Json).Parameters.ContainsKey('DateKind')) { $options.DateKind = 'String' }
    foreach ($value in (ConvertFrom-Json -InputObject $Json @options)) { $value }
}

function Test-PublicObject {
    param($Actual, $Expected)
    if ($null -eq $Actual) { return $false }
    $actualKeys = @($Actual.PSObject.Properties.Name | Sort-Object) -join ','
    $expectedKeys = @($Expected.PSObject.Properties.Name | Sort-Object) -join ','
    if ($actualKeys -cne $expectedKeys) { return $false }
    foreach ($key in $Expected.PSObject.Properties.Name) {
        if ($null -eq $Actual.$key -or $Actual.$key.GetType() -ne $Expected.$key.GetType() -or
            $Actual.$key -cne $Expected.$key) { return $false }
    }
    return $true
}

function Test-HttpCase {
    param([string]$Name, [string]$Url, [int]$Status, $Expected, [switch]$Array)
    $failedBefore = $script:failedChecks
    $expectedCount = @($Expected).Count
    $expectedText = if ($Array) { "HTTP $Status; $expectedCount items matching SQL" } else { "HTTP $Status; exact object" }
    $actualText = 'No response'
    try {
        $response = Invoke-Http -Method GET -Url $Url
        $actualText = "HTTP $($response.Status); $($response.Body)"
        Assert-Check ($response.Status -eq $Status) "$Name status"
        Assert-Check ($response.ContentType -eq 'application/json') "$Name JSON content type"
        $value = Convert-Json $response.Body
        if ($Array) {
            Assert-Check ($response.Body.TrimStart().StartsWith('[')) "$Name JSON array"
            $rows = @(Convert-Json $response.Body)
            Assert-Check ($rows.Count -eq $expectedCount) "$Name item count"
            $same = $rows.Count -eq $expectedCount
            foreach ($expectedRow in @($Expected)) {
                $found = @($rows | Where-Object { $_.id -ceq $expectedRow.id })
                if ($found.Count -ne 1 -or -not (Test-PublicObject $found[0] $expectedRow)) { $same = $false }
            }
            Assert-Check $same "$Name exact public rows from SQL"
            Assert-Check ((@($rows.id) -join ',') -ceq (@($Expected.id) -join ',')) "$Name SQL list order"
            $actualText = "HTTP $($response.Status); $($rows.Count) items; SQL match=$same"
        } else {
            $same = Test-PublicObject $value $Expected
            Assert-Check $same "$Name exact public object"
        }
        if ($Status -eq 200 -and $expectedCount -gt 0) {
            $dates = [regex]::Matches($response.Body, '"(?:createdAt|updatedAt)"\s*:\s*"([^"]+)"')
            $validDates = $dates.Count -eq (2 * $expectedCount)
            foreach ($date in $dates) {
                if ($date.Groups[1].Value -cnotmatch '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$') { $validDates = $false }
            }
            Assert-Check $validDates "$Name exact UTC seed timestamp format"
        }
    } catch {
        Assert-Check $false "$Name response: $($_.Exception.Message)"
    }
    $result = if ($script:failedChecks -eq $failedBefore) { 'PASS' } else { 'FAIL' }
    Write-Host "${Name}: expected $expectedText; actual $actualText; $result"
}

Push-Location -LiteralPath $PSScriptRoot
try {
    Write-Host "Build 1 verification UTC: $([DateTime]::UtcNow.ToString('o'))"
    Add-Type -AssemblyName System.Net.Http
    $handler = [System.Net.Http.HttpClientHandler]::new()
    $handler.UseProxy = $false
    $client = [System.Net.Http.HttpClient]::new($handler)
    $client.Timeout = [TimeSpan]::FromSeconds(3)

    Invoke-Docker -Arguments @('compose', 'up', '-d', '--wait', 'db') | Out-Host
    Invoke-Docker -Arguments @('compose', 'build', 'api') | Out-Host
    Invoke-Docker -Arguments @('compose', 'run', '--rm', '--no-deps', 'api', 'php', '-l', 'backend/public/index.php') | Out-Host

    foreach ($fixture in @('populated', 'empty')) {
        $database = 'osiris_test_' + [Guid]::NewGuid().ToString('N').Substring(0, 12)
        $exists = (Invoke-Sql postgres "SELECT count(*) FROM pg_database WHERE datname = '$database';") -join ''
        if ($exists.Trim() -ne '0') { throw "Refusing to use an existing database: $database" }
        Invoke-Sql postgres "CREATE DATABASE $database TEMPLATE template0;" | Out-Host
        $databases.Add($database)
        Invoke-Sql $database (Get-Content -LiteralPath 'database/schema.sql' -Raw) | Out-Host
        if ($fixture -eq 'populated') {
            $populatedDatabase = $database
            Invoke-Sql $database (Get-Content -LiteralPath 'database/seeds/demo.sql' -Raw) | Out-Host
        } else { $emptyDatabase = $database }
    }

    $sessionSql = @'
SELECT COALESCE(json_agg(row_to_json(public_session) ORDER BY public_session."createdAt", public_session.id), '[]'::json)
FROM (
    SELECT id, project_id AS "projectId", title, status,
        to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS "createdAt",
        to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS "updatedAt"
    FROM sessions
) AS public_session;
'@
    $fixtureJson = (Invoke-Sql $populatedDatabase $sessionSql) -join "`n"
    $expected = @(Convert-Json $fixtureJson)
    $existing = @($expected | Where-Object { $_.id -ceq 'ses_101' })
    if ($expected.Count -ne 5 -or $existing.Count -ne 1) { throw 'Demo SQL must supply five sessions including ses_101.' }
    $emptyJson = (Invoke-Sql $emptyDatabase $sessionSql) -join ''
    if ($emptyJson.Trim() -ne '[]') { throw 'The empty fixture is not empty.' }

    $baseUrl = Start-TestApi $populatedDatabase
    $emptyBaseUrl = Start-TestApi $emptyDatabase
    Test-HttpCase -Name 'Existing session' -Url "$baseUrl/sessions/ses_101" -Status 200 -Expected $existing[0]
    Test-HttpCase -Name 'Missing session' -Url "$baseUrl/sessions/ses_missing" -Status 404 -Expected ([pscustomobject]@{ error = 'Session not found' })
    Test-HttpCase -Name 'Populated list' -Url "$baseUrl/sessions" -Status 200 -Expected $expected -Array
    Test-HttpCase -Name 'Empty list' -Url "$emptyBaseUrl/sessions" -Status 200 -Expected @() -Array

    $page = Invoke-Http GET "$baseUrl/"
    Assert-Check ($page.Status -eq 200 -and $page.ContentType -eq 'text/html') 'GET / serves HTML'
    foreach ($tag in @('button', 'input')) {
        Assert-Check ($page.Body -match "<$tag\b") "Page has a $tag control"
    }
    Assert-Check ($page.Body -match '/sessions') 'Page uses the sessions API'
    foreach ($path in @('/', '/sessions', '/sessions/ses_101')) {
        $response = Invoke-Http POST "$baseUrl$path"
        Assert-Check ($response.Status -eq 405 -and $response.Allow -ceq 'GET') "POST $path returns 405 with Allow: GET"
        Assert-Check ($response.ContentType -eq 'application/json') "POST $path JSON content type"
    }
    $unknown = Invoke-Http GET "$baseUrl/build1-not-a-route"
    Assert-Check ($unknown.Status -eq 404 -and $unknown.ContentType -eq 'application/json') 'Unknown route returns JSON 404'
    $afterJson = (Invoke-Sql $populatedDatabase $sessionSql) -join "`n"
    Assert-Check ($afterJson -ceq $fixtureJson) 'HTTP checks did not mutate the populated sessions'
    Assert-Check (((Invoke-Sql $emptyDatabase $sessionSql) -join '').Trim() -ceq '[]') 'HTTP checks did not mutate the empty sessions'
} finally {
    foreach ($container in $containers) {
        try {
            $present = @(Invoke-Docker -Arguments @('container', 'ls', '-a', '-q', '--filter', "name=^/$container`$"))
            if ($present.Count -gt 0) { Invoke-Docker -Arguments @('stop', '--timeout', '5', $container) | Out-Host }
        } catch { $cleanupErrors += "Container ${container}: $($_.Exception.Message)" }
    }
    foreach ($database in $databases) {
        try {
            if ($database -cnotmatch '^osiris_test_[0-9a-f]{12}$') { throw 'Unsafe database name; refusing cleanup.' }
            Invoke-Sql postgres "DROP DATABASE $database WITH (FORCE);" | Out-Host
        } catch { $cleanupErrors += "Database ${database}: $($_.Exception.Message)" }
    }
    if ($null -ne $client) { $client.Dispose() }
    Pop-Location
    foreach ($errorText in $cleanupErrors) { Write-Warning $errorText }
    Write-Host "Total checks: $script:checks; failed: $script:failedChecks; cleanup errors: $($cleanupErrors.Count)."
}
if ($cleanupErrors.Count -gt 0) { throw 'Build 1 test resource cleanup failed.' }
if ($script:failedChecks -gt 0) { throw "$script:failedChecks Build 1 checks failed." }
