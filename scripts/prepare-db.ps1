param([switch]$EmptyDemo)

$ErrorActionPreference = 'Stop'
$repoPath = Split-Path -Parent $PSScriptRoot

function Invoke-LocalSql([string]$Sql, [string]$Database = 'osiris_dev') {
    $result = $Sql | docker compose exec -T db psql -X -A -t -U osiris_dev -d $Database -v ON_ERROR_STOP=1
    if ($LASTEXITCODE -ne 0) { throw "SQL failed in local database $Database." }
    return ($result -join "`n").Trim()
}

$databaseName = if ($EmptyDemo) { 'osiris_empty_demo' } else { 'osiris_dev' }
if ($EmptyDemo -and (Invoke-LocalSql "SELECT count(*) FROM pg_database WHERE datname='osiris_empty_demo';") -eq '0') {
    docker compose exec -T db createdb -U osiris_dev -O osiris_dev $databaseName
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the empty fixture database.' }
}
$tables = Invoke-LocalSql "SELECT count(*) FROM pg_tables WHERE schemaname='public';" $databaseName
if ($tables -eq '0') {
    # Commit the initial schema and optional demo dataset together. An interrupted
    # first launch is retryable; relaunching never restores intentionally deleted rows.
    $bootstrap = @(
        'BEGIN;'
        Get-Content -LiteralPath (Join-Path $repoPath 'database/schema.sql') -Raw
        if (-not $EmptyDemo) {
            Get-Content -LiteralPath (Join-Path $repoPath 'database/seeds/demo.sql') -Raw
        }
        'COMMIT;'
    ) -join "`n"
    $null = Invoke-LocalSql $bootstrap $databaseName
} elseif ((Invoke-LocalSql "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename IN ('users','projects','sessions');" $databaseName) -ne '3') {
    throw 'Unexpected database schema; refusing to initialize over existing tables.'
}
$migrations = @(Get-ChildItem -LiteralPath (Join-Path $repoPath 'database/migrations') -File -Filter '*.sql' | Sort-Object Name)
if (!$migrations) { throw 'No database migrations found.' }
foreach ($migration in $migrations) {
    $null = Invoke-LocalSql (Get-Content -LiteralPath $migration.FullName -Raw) $databaseName
}
$hasRole = Invoke-LocalSql "SELECT count(*) FROM pg_roles WHERE rolname='osiris_app';" $databaseName
if ($hasRole -eq '0') {
    $password = [IO.File]::ReadAllText((Join-Path $repoPath '.local/db.key')).Trim()
    if ($password -cnotmatch '^[A-Za-z0-9+/]{43}=$') { throw 'Invalid application password file.' }
    $null = Invoke-LocalSql "CREATE ROLE osiris_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '$password';" $databaseName
}
$null = Invoke-LocalSql (Get-Content -LiteralPath (Join-Path $repoPath 'database/app-grants.sql') -Raw) $databaseName
Write-Output $databaseName
