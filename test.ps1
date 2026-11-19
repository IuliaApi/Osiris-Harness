[CmdletBinding()]
param([switch]$Browser)

# A separate Docker network and disposable database cluster for every run.
# No Compose interpolation, host credentials, host ports or development volumes.
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$prefix = 'osiris-qa-' + [guid]::NewGuid().ToString('N')
$db = "$prefix-db"; $api = "$prefix-api"; $runner = "$prefix-runner"
$appImage = "${prefix}:app"; $qaImage = "${prefix}:qa"; $unitImage = "${prefix}:unit"
$routerImage = "${prefix}:router"
$password = 'QA-' + [guid]::NewGuid().ToString('N')
$dbUrl = 'postgres://osiris_dev:lab-local-only@db:5432/'
$rolePassword = [guid]::NewGuid().ToString('N')
$populated = 'osiris_test_populated'
$empty = 'osiris_test_empty'
$keyBytes = [byte[]]::new(32)
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $rng.GetBytes($keyBytes) } finally { $rng.Dispose() }
$labKey = [Convert]::ToBase64String($keyBytes)
$routerKey = [Convert]::ToBase64String(([guid]::NewGuid().ToByteArray() + [guid]::NewGuid().ToByteArray()))
$containers = @()
$images = @()
$networkCreated = $false
$cleanupFailed = $false

function Invoke-LabDocker([string[]]$Argv) {
    & docker @Argv
    if ($LASTEXITCODE -ne 0) { throw "Docker $($Argv[0]) failed (exit $LASTEXITCODE)." }
}

function Start-App([string]$Name, [string]$Alias, [string]$Database) {
    Invoke-LabDocker @('run','-d','--network',$prefix,'--network-alias',$Alias,'--name',$Name,
        '--add-host','api.openai.com:127.0.0.1','--add-host','api.anthropic.com:127.0.0.1',
        '-e','http_proxy=','-e','https_proxy=','-e','all_proxy=','-e','HTTP_PROXY=','-e','HTTPS_PROXY=','-e','ALL_PROXY=',
        '-e',"DATABASE_URL=postgres://osiris_app@db:5432/$Database",'-e','OSIRIS_GITHUB_MODE=offline',
        '-e',"QA_ROLE_SECRET=$rolePassword",'-e','OSIRIS_DB_PASSWORD_FILE=/tmp/qa-role.key',
        '-e','OPENAI_API_KEY=','-e','ANTHROPIC_API_KEY=','-e','OSIRIS_OLLAMA_ENABLED=0',
        '-e','OSIRIS_DAILY_CHAT_LIMIT=2','-e',"OSIRIS_VAULT_KEY=$labKey",
        $appImage,'sh','-ec','umask 077; printf %s "$QA_ROLE_SECRET" > /tmp/qa-role.key; unset QA_ROLE_SECRET; exec env PHP_CLI_SERVER_WORKERS=4 php -d expose_php=0 -S 0.0.0.0:8080 -t backend/public backend/public/index.php 2>&1') | Out-Null
    $script:containers += $Name
}

function Provision([string]$Container, [string]$Id, [string]$Username, [string]$Role) {
    $database = if ($Container -eq $api) { $populated } else { $empty }
    $password | docker exec -i -e "DATABASE_URL=$dbUrl$database" $Container php scripts/provision-user.php $Id $Username $Role
    if ($LASTEXITCODE -ne 0) { throw "Failed to provision synthetic $Role." }
}

Push-Location -LiteralPath $PSScriptRoot
try {
    Write-Host "Workspace integration tests (HTTP/PostgreSQL), started $([DateTime]::UtcNow.ToString('o'))"
    Invoke-LabDocker @('build','-q','-f','backend/Dockerfile','-t',$appImage,'.') | Out-Host
    $images += $appImage
    Invoke-LabDocker @('build','-q','-f','scripts/Dockerfile.qa','--target','unit','--build-arg',"APP_IMAGE=$appImage",'-t',$unitImage,'scripts') | Out-Host
    $images = @($unitImage,$appImage)
    Invoke-LabDocker @('run','--rm','--network','none',$unitImage,'sh','-ec',
        'for f in backend/bootstrap.php backend/src/*.php backend/public/*.php; do php -l "$f"; done; for s in test-ai-provider test-model-catalog test-workspace-policies test-representations test-github-context; do php scripts/$s.php; done; for s in test-semantic-formats test-ollama-transport test-http-no-db; do python3 scripts/$s.py; done')
    $qaBuild = @('build','-q','-f','scripts/Dockerfile.qa','-t',$qaImage)
    if ($Browser) { $qaBuild += @('--target','browser') } else { $qaBuild += @('--target','http') }
    Invoke-LabDocker ($qaBuild + 'scripts') | Out-Host
    $images += $qaImage
    Invoke-LabDocker @('network','create','--internal',$prefix) | Out-Null
    $networkCreated = $true
    Invoke-LabDocker @('build','-q','-f','ai-router/Dockerfile','-t',$routerImage,'.') | Out-Host
    $images += $routerImage
    Invoke-LabDocker @('run','--rm','--network','none','-e','HOME=/tmp/test-home',$routerImage,'bun','test')
    Invoke-LabDocker @('run','-d','--network',$prefix,'--network-alias','ai-router','--name',"$prefix-router",
        '-e','HOME=/tmp/test-home','-e','OSIRIS_AI_ROUTER_KEY_FILE=/tmp/service.key','-e',"QA_SERVICE_KEY=$routerKey",
        $routerImage,'sh','-ec','umask 077; printf %s "$QA_SERVICE_KEY" > /tmp/service.key; unset QA_SERVICE_KEY; exec bun run server.ts') | Out-Null
    $containers += "$prefix-router"
    $dbMount = "type=bind,src=$PSScriptRoot/database,dst=/fixtures,readonly"
    Invoke-LabDocker @('run','-d','--name',$db,'--network',$prefix,'--network-alias','db',
        '--mount',$dbMount,'--tmpfs','/var/lib/postgresql/data',
        '-e','POSTGRES_USER=osiris_dev','-e','POSTGRES_PASSWORD=lab-local-only','-e',"POSTGRES_DB=$populated",
        '--health-cmd',"pg_isready -h 127.0.0.1 -U osiris_dev -d $populated",'--health-interval','1s',
        '--health-timeout','3s','--health-retries','40','postgres:16-alpine') | Out-Null
    $containers += $db
    $deadline = [datetime]::UtcNow.AddSeconds(60)
    do {
        $health = Invoke-LabDocker @('inspect','--format','{{.State.Health.Status}}',$db)
        if ($health -eq 'healthy') { break }
        if ($health -eq 'unhealthy' -or [datetime]::UtcNow -ge $deadline) { throw 'Isolated PostgreSQL startup failed.' }
        Start-Sleep -Milliseconds 300
    } while ($true)

    $migrations = @(Get-ChildItem -LiteralPath database/migrations -File -Filter '*.sql' | Sort-Object Name)
    if (!$migrations) { throw 'No database migrations found.' }
    Invoke-LabDocker @('exec',$db,'createdb','-U','osiris_dev',$empty)
    Invoke-LabDocker @('exec',$db,'psql','-Xq','-v','ON_ERROR_STOP=1','-U','osiris_dev','-d',$populated,'-c',
        "CREATE ROLE osiris_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '$rolePassword'")
    foreach ($database in $populated,$empty) {
        $psql = @('exec',$db,'psql','-Xq','-v','ON_ERROR_STOP=1','-U','osiris_dev','-d',$database)
        $bootstrap = @('--single-transaction','-f','/fixtures/schema.sql')
        if ($database -eq $populated) { $bootstrap += @('-f','/fixtures/seeds/demo.sql') }
        Invoke-LabDocker ($psql + $bootstrap) | Out-Host
        foreach ($migration in $migrations) {
            Invoke-LabDocker ($psql + @('-f',"/fixtures/migrations/$($migration.Name)")) | Out-Host
        }
        Invoke-LabDocker ($psql + @('-f','/fixtures/app-grants.sql')) | Out-Host
    }
    Start-App $api 'api' $populated
    Start-App "$prefix-empty" 'empty' $empty
    Provision $api usr_demo_one demo_one owner
    Provision $api usr_demo_two demo_two owner
    Provision $api usr_qa_member qa_member member
    Provision $api usr_qa_viewer qa_viewer viewer
    Provision $api usr_qa_admin qa_admin admin
    Provision "$prefix-empty" usr_qa_empty qa_empty member

    # Each fixture has a server-selected mode; clients cannot switch upstreams.
    $port = 8090
    foreach ($mode in 'success','timeout','malformed','quota','failure') {
        Invoke-LabDocker @('exec','-d','-e',"OSIRIS_GITHUB_MODE=fixture-$mode",$api,
            'php','-S',"0.0.0.0:$port",'-t','backend/public','backend/public/index.php')
        $port++
    }
    if ($Browser) { Provision $api usr_qa_browser qa_browser owner }
    Invoke-LabDocker @('exec','-d','-e','OSIRIS_AI_ROUTER=1','-e','OSIRIS_AI_ROUTER_KEY_FILE=/tmp/service.key','-e',"QA_SERVICE_KEY=$routerKey",$api,
        'sh','-ec','umask 077; printf %s "$QA_SERVICE_KEY" > /tmp/service.key; unset QA_SERVICE_KEY; exec php -S 0.0.0.0:8096 -t backend/public backend/public/index.php')
    # This server alone permits Ollama, through the private synthetic HTTP double.
    Invoke-LabDocker @('exec','-d','-e','OSIRIS_OLLAMA_ENABLED=1','-e','OSIRIS_DAILY_CHAT_LIMIT=9','-e','http_proxy=http://qa-runner:11435',
        '-e','no_proxy=','-e','NO_PROXY=','-e','PHP_CLI_SERVER_WORKERS=2',$api,'php','-S','0.0.0.0:8095','-t','backend/public','backend/public/index.php')
    Invoke-LabDocker @('run','-d','--name',$runner,'--network',$prefix,'--network-alias','qa-runner','--mount',$dbMount,
        '--mount',"type=bind,src=$PSScriptRoot/docs,dst=/repo/docs,readonly",
        '--mount',"type=bind,src=$PSScriptRoot/scripts,dst=/repo/scripts,readonly",
        '-e',"QA_PASSWORD=$password",'-e',"DATABASE_URL=$dbUrl$populated",
        '-e','OSIRIS_TEST_BASE_URL=http://api:8080','-e','OSIRIS_TEST_USERNAME=qa_browser','-e',"OSIRIS_TEST_PASSWORD=$password",
        '-e','OSIRIS_VIEWER_USERNAME=qa_viewer','-e',"OSIRIS_VIEWER_PASSWORD=$password",
        $qaImage,'sleep','infinity') | Out-Null
    $containers += $runner
    Invoke-LabDocker @('exec',$runner,'python','-u','scripts/test-workspace-api.py')
    # The API process sends its real stderr to stdout. Never echo raw logs:
    # the checker verifies both correlation and absence of synthetic secrets.
    # Check the producer before piping: $LASTEXITCODE only reflects the last
    # native command in a pipeline, even if Docker returned a partial log.
    $apiLog = Invoke-LabDocker @('logs',$api)
    $apiLog | docker exec -i $runner python scripts/test-workspace-api.py --check-logs
    if ($LASTEXITCODE -ne 0) { throw 'Safe-log assertions failed.' }
    if ($Browser) {
        Invoke-LabDocker @('exec','-e','OSIRIS_TEST_BASE_URL=http://api:8095',$runner,'python','-u','scripts/test-workspace-ui.py')
    }
} finally {
    [array]::Reverse($containers)
    foreach ($name in $containers) { & docker rm -fv $name | Out-Null; if ($LASTEXITCODE -ne 0) { $cleanupFailed = $true } }
    if ($networkCreated) { & docker network rm $prefix | Out-Null; if ($LASTEXITCODE -ne 0) { $cleanupFailed = $true } }
    foreach ($image in $images) { & docker image rm $image | Out-Null; if ($LASTEXITCODE -ne 0) { $cleanupFailed = $true } }
    Pop-Location
    if ($cleanupFailed) { throw 'Workspace integration cleanup failed.' }
}
Write-Host 'Workspace integration tests passed; disposable resources removed.'
