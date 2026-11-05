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
    param([string]$Method, [string]$Url, [string]$Body, [string]$Accept = 'application/json', [string]$ContentType = 'application/json')
    $request = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::new($Method), $Url)
    $request.Headers.Accept.ParseAdd($Accept)
    if ($PSBoundParameters.ContainsKey('Body')) {
        $request.Content = [System.Net.Http.StringContent]::new($Body, [Text.Encoding]::UTF8, $ContentType)
    }
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
            RequestId = @($response.Headers.GetValues('X-Request-Id')) -join ''
        }
    } finally {
        if ($null -ne $response) { $response.Dispose() }
        $request.Dispose()
    }
}

function Start-TestApi {
    param([string]$Database, [switch]$Offline)
    $name = "osiris-build1-$($Database.Substring(12))"
    $present = @(Invoke-Docker -Arguments @('container', 'ls', '-a', '-q', '--filter', "name=^/$name`$"))
    if ($present.Count -gt 0) { throw "Refusing to reuse an existing container: $name" }
    $containers.Add($name)
    $dockerArguments = @(
        'compose', 'run', '--rm', '-d', '--no-deps', '--name', $name,
        '-p', '127.0.0.1::8080', '-e', "DATABASE_URL=postgres://osiris_dev:osiris_dev_local_only@db:5432/$Database", '-e', 'OPENAI_API_KEY=' , '-e', 'ANTHROPIC_API_KEY='
    )
    if ($Offline) { $dockerArguments += @('-e', 'OSIRIS_GITHUB_MODE=offline') }
    $dockerArguments += 'api'
    $output = @(Invoke-Docker -Arguments $dockerArguments)
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
    Write-Host "Build 2 verification UTC: $([DateTime]::UtcNow.ToString('o'))"
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
    $emptyBaseUrl = Start-TestApi $emptyDatabase -Offline
    Test-HttpCase -Name 'Existing session' -Url "$baseUrl/sessions/ses_101" -Status 200 -Expected $existing[0]
    $missing = Invoke-Http -Method GET -Url "$baseUrl/sessions/ses_missing"
    Assert-Check ($missing.Status -eq 404 -and (ConvertFrom-Json $missing.Body).code -eq 'not_found') 'Missing session returns structured 404'
    $filtered = Invoke-Http -Method GET -Url "$baseUrl/sessions?status=active&sort=title&order=desc&limit=1"
    $filteredData = ConvertFrom-Json $filtered.Body
    Assert-Check ($filtered.Status -eq 200 -and $filteredData.page.total -eq 2 -and $filteredData.items.Count -eq 1) 'Filtered page and total'
    $invalid = Invoke-Http -Method GET -Url "$baseUrl/sessions?sort=oops"
    Assert-Check ($invalid.Status -eq 400) 'Invalid sort returns 400'
    $projects = Invoke-Http -Method GET -Url "$baseUrl/projects"
    Assert-Check ($projects.Status -eq 200) 'Project list available'
    Test-HttpCase -Name 'Populated list' -Url "$baseUrl/sessions" -Status 200 -Expected $expected -Array
    Test-HttpCase -Name 'Empty list' -Url "$emptyBaseUrl/sessions" -Status 200 -Expected @() -Array

    $page = Invoke-Http GET "$baseUrl/"
    Assert-Check ($page.Status -eq 200 -and $page.ContentType -eq 'text/html') 'GET / serves HTML'
    foreach ($tag in @('button', 'input')) {
        Assert-Check ($page.Body -match "<$tag\b") "Page has a $tag control"
    }
    Assert-Check ($page.Body -match '/sessions') 'Page uses the sessions API'
    foreach ($path in @('/', '/sessions/ses_101')) {
        $response = Invoke-Http POST "$baseUrl$path"
        $expectedAllow = if ($path -eq '/') { 'GET' } else { 'GET, PATCH, DELETE' }
        Assert-Check ($response.Status -eq 405 -and $response.Allow -ceq $expectedAllow) "POST $path returns 405 with Allow: $expectedAllow"
        Assert-Check ($response.ContentType -eq 'application/json') "POST $path JSON content type"
    }
    $unknown = Invoke-Http GET "$baseUrl/build1-not-a-route"
    Assert-Check ($unknown.Status -eq 404 -and $unknown.ContentType -eq 'application/json') 'Unknown route returns JSON 404'
    $afterJson = (Invoke-Sql $populatedDatabase $sessionSql) -join "`n"
    Assert-Check ($afterJson -ceq $fixtureJson) 'HTTP checks did not mutate the populated sessions'
    Assert-Check (((Invoke-Sql $emptyDatabase $sessionSql) -join '').Trim() -ceq '[]') 'HTTP checks did not mutate the empty sessions'
    # Build 2 writes and representation/composition checks use the isolated EMPTY fixture.
    Invoke-Sql $emptyDatabase "INSERT INTO users (id, username, email) VALUES ('usr_check', 'check', 'check@example.test');" | Out-Host
    $project = Invoke-Http POST "$emptyBaseUrl/projects" '{"id":"prj_check","userId":"usr_check","name":"Harness check","repositoryUrl":"https://github.com/php/php-src"}'
    Assert-Check ($project.Status -eq 201) 'POST project persists'
    $session = Invoke-Http POST "$emptyBaseUrl/projects/prj_check/sessions" '{"id":"ses_check","title":"Run focused regression"}'
    Assert-Check ($session.Status -eq 201) 'POST nested session persists'
    $message = Invoke-Http POST "$emptyBaseUrl/sessions/ses_check/messages" '{"id":"msg_check","role":"user","content":"Investigate test failure"}'
    Assert-Check ($message.Status -eq 201) 'POST message persists'
    $task = Invoke-Http POST "$emptyBaseUrl/sessions/ses_check/tasks" '{"id":"tsk_check","title":"Run tests","status":"pending"}'
    Assert-Check ($task.Status -eq 201) 'POST task persists'
    $patch = Invoke-Http PATCH "$emptyBaseUrl/sessions/ses_check/tasks/tsk_check" '{"status":"completed","result":"PASS"}'
    Assert-Check ($patch.Status -eq 200 -and (ConvertFrom-Json $patch.Body).status -eq 'completed') 'PATCH task persisted'
    $readBack = Invoke-Http GET "$emptyBaseUrl/sessions/ses_check?include=project,messages,tasks"
    $detail = ConvertFrom-Json $readBack.Body
    Assert-Check ($detail.project.id -eq 'prj_check' -and $detail.messages[0].id -eq 'msg_check' -and $detail.tasks[0].status -eq 'completed') 'Composed read backs writes'
    $pageOne = Invoke-Http GET "$baseUrl/sessions?status=active&sort=title&order=desc&limit=1&offset=0"
    $pageData = ConvertFrom-Json $pageOne.Body
    Assert-Check ($pageData.page.total -eq 2 -and $pageData.links.next -match 'status=active' -and $pageData.links.next -match 'sort=title') 'Next link preserves filter and sort'
    $pageTwo = Invoke-Http GET "$baseUrl$($pageData.links.next)"
    Assert-Check ((ConvertFrom-Json $pageTwo.Body).items.Count -eq 1) 'Follow next link'
    $lastPage = Invoke-Http GET "$baseUrl/sessions?limit=1&offset=99"
    $overrun = ConvertFrom-Json $lastPage.Body
    Assert-Check ($overrun.items.Count -eq 0 -and $null -eq $overrun.links.next -and $null -ne $overrun.links.previous) 'Out-of-range page is empty, no next link'
    $zeroMatches = Invoke-Http GET "$baseUrl/sessions?status=archived&projectId=prj_web&limit=1"
    $none = ConvertFrom-Json $zeroMatches.Body
    Assert-Check ($none.page.total -eq 0 -and $none.items.Count -eq 0 -and $null -eq $none.links.next) 'Filtered collection with zero matches'
    $duplicate = Invoke-Http GET "$baseUrl/sessions?sort=title&sort=id"
    Assert-Check ($duplicate.Status -eq 400) 'Repeated filter validation'
    $negative = Invoke-Http GET "$baseUrl/sessions?limit=0"
    Assert-Check ($negative.Status -eq 400) 'Bounded pagination'
    $xml = Invoke-Http GET "$emptyBaseUrl/sessions/ses_check" -Accept 'application/xml'
    Assert-Check ($xml.Status -eq 200 -and $xml.ContentType -eq 'application/xml' -and $xml.Body -match '<title>Run focused regression</title>') 'XML representation'
    $yaml = Invoke-Http GET "$emptyBaseUrl/sessions/ses_check" -Accept 'application/yaml'
    Assert-Check ($yaml.Status -eq 200 -and $yaml.ContentType -eq 'application/yaml' -and $yaml.Body -match 'title: "Run focused regression"') 'YAML representation'
    $unsupported = Invoke-Http GET "$emptyBaseUrl/sessions/ses_check" -Accept 'image/png'
    Assert-Check ($unsupported.Status -eq 406 -and $unsupported.ContentType -eq 'application/json') 'Not acceptable status and safe format'
    $mediaError = ConvertFrom-Json $unsupported.Body
    Assert-Check ($unsupported.RequestId -match '^[0-9a-f]{16}$' -and $mediaError.requestId -ceq $unsupported.RequestId) 'Request-correlated 406 failure'
    $invalidContent = Invoke-Http -Method POST -Url "$emptyBaseUrl/sessions" -Body '{"projectId":"prj_check","title":"Bad media"}' -ContentType 'text/plain'
    Assert-Check ($invalidContent.Status -eq 415 -and (ConvertFrom-Json $invalidContent.Body).requestId -ceq $invalidContent.RequestId) 'Unsupported write Content-Type gives correlated 415'
    $badType = Invoke-Http POST "$emptyBaseUrl/sessions" -Body '{"projectId":"prj_check"}'
    Assert-Check ($badType.Status -eq 422) 'Missing required title validation'
    $badSort = Invoke-Http GET "$baseUrl/sessions?sort=secrets"
    Assert-Check ($badSort.Status -eq 400) 'Sort identifiers are allowlisted'
    $pageBoundary = Invoke-Http GET "$baseUrl/sessions?limit=101"
    Assert-Check ($pageBoundary.Status -eq 400) 'Upper page size is bounded'
    $context = Invoke-Http GET "$emptyBaseUrl/sessions/ses_check/context"
    $data = ConvertFrom-Json $context.Body
    Assert-Check ($context.Status -eq 200 -and $data.sources.local -eq 'PostgreSQL' -and $data.sources.external -eq 'GitHub REST API' -and $data.external.reason -eq 'upstream_disabled_for_demo') 'Live/controlled partial composite response'
    # Chat is never simulated: verify validation, configured provider list, and
    # no DB writes when the chosen upstream provider lacks credentials.
    $runtime = Invoke-Http GET "$emptyBaseUrl/api/runtime"
    $runtimeData = ConvertFrom-Json $runtime.Body
    $providers = @($runtimeData.ai.providers | ForEach-Object { $_.id })
    Assert-Check ($runtime.Status -eq 200 -and $providers -contains 'openai' -and $providers -contains 'anthropic' -and $providers -contains 'ollama' -and $runtimeData.capabilities.shell_execution -eq $false) 'Runtime lists AI providers and no shell execution'
    $chatBadMethod = Invoke-Http GET "$emptyBaseUrl/sessions/ses_check/chat"
    Assert-Check ($chatBadMethod.Status -eq 405 -and $chatBadMethod.Allow -ceq 'POST') 'Chat endpoint allows only POST'
    $chatBad = Invoke-Http POST "$emptyBaseUrl/sessions/ses_check/chat" '{"message":""}'
    Assert-Check ($chatBad.Status -eq 422) 'Chat rejects empty prompts'
    $chatInvalid = Invoke-Http POST "$emptyBaseUrl/sessions/ses_check/chat" '{"message":"Hi", "provider":"unknown"}'
    Assert-Check ($chatInvalid.Status -eq 422) 'Chat rejects unknown providers'
    $chatResult = Invoke-Http POST "$emptyBaseUrl/sessions/ses_check/chat" '{"message":"Explain PostgreSQL indexes","provider":"openai"}'
    $chat = ConvertFrom-Json $chatResult.Body
    Assert-Check ($chatResult.Status -eq 503 -and $chat.code -eq 'ai_not_configured') 'Missing key gives a safe 503, never a simulated AI reply'
    $chatReadBack = Invoke-Http GET "$emptyBaseUrl/sessions/ses_check?include=messages"
    $chatDetails = ConvertFrom-Json $chatReadBack.Body
    Assert-Check ($chatDetails.messagesPage.total -eq 1 -and $chatDetails.messages.Count -eq 1) 'Failed AI calls do not save a user/assistant exchange'
    $archive = Invoke-Http PATCH "$emptyBaseUrl/sessions/ses_check" '{"status":"archived"}'
    $chatArchived = Invoke-Http POST "$emptyBaseUrl/sessions/ses_check/chat" '{"message":"Should be refused"}'
    Assert-Check ($archive.Status -eq 200 -and $chatArchived.Status -eq 409) 'Archived sessions reject new chat turns'
    $restore = Invoke-Http PATCH "$emptyBaseUrl/sessions/ses_check" '{"status":"active"}'
    Assert-Check ($restore.Status -eq 200) 'Session restored for cleanup'
    $conflict = Invoke-Http DELETE "$emptyBaseUrl/sessions/ses_check"
    Assert-Check ($conflict.Status -eq 409) 'Foreign-key dependent deletion conflicts'
    Assert-Check ((Invoke-Http DELETE "$emptyBaseUrl/sessions/ses_check/messages/msg_check").Status -eq 204) 'Delete message'
    Assert-Check ((Invoke-Http DELETE "$emptyBaseUrl/sessions/ses_check/tasks/tsk_check").Status -eq 204) 'Delete task'
    Assert-Check ((Invoke-Http DELETE "$emptyBaseUrl/sessions/ses_check").Status -eq 204) 'Delete session'
    Assert-Check ((Invoke-Http DELETE "$emptyBaseUrl/projects/prj_check").Status -eq 204) 'Delete project'
    Assert-Check ((Invoke-Http GET "$emptyBaseUrl/sessions/ses_check").Status -eq 404) 'Delete persisted'
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
if ($cleanupErrors.Count -gt 0) { throw 'Build 2 test resource cleanup failed.' }
if ($script:failedChecks -gt 0) { throw "$script:failedChecks Build 2 checks failed." }
