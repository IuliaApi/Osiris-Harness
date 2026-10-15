param([switch]$EmptyDemo)

$ErrorActionPreference = 'Stop'
$repoPath = Split-Path -Parent $PSScriptRoot

function Invoke-LocalSql([string]$Sql, [string]$Database = 'osiris_dev') {
    $result = $Sql | docker compose exec -T db psql -X -A -t -U osiris_dev -d $Database -v ON_ERROR_STOP=1
    if ($LASTEXITCODE -ne 0) { throw "SQL failed in local database $Database." }
    return ($result -join "`n").Trim()
}

if ($EmptyDemo) {
    $databaseName = 'osiris_empty_demo'
    if ((Invoke-LocalSql "SELECT count(*) FROM pg_database WHERE datname='osiris_empty_demo';") -eq '0') {
        docker compose exec -T db createdb -U osiris_dev -O osiris_dev $databaseName
        if ($LASTEXITCODE -ne 0) { throw 'Could not create the empty fixture database.' }
    }
    $tableCount = Invoke-LocalSql "SELECT count(*) FROM pg_tables WHERE schemaname='public';" $databaseName
    if ($tableCount -eq '0') {
        $null = Invoke-LocalSql (Get-Content -LiteralPath (Join-Path $repoPath 'database\schema.sql') -Raw) $databaseName
    } elseif ($tableCount -ne '3' -or (Invoke-LocalSql "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename IN ('users','projects','sessions');" $databaseName) -ne '3') {
        throw 'The empty fixture has an unexpected schema. No data was changed.'
    }
    if ((Invoke-LocalSql 'SELECT (SELECT count(*) FROM projects)+(SELECT count(*) FROM sessions);' $databaseName) -ne '0') {
        throw 'The empty fixture contains records. Refusing to clear it.'
    }
    Write-Output $databaseName
    return
}

$fixtureState = Invoke-LocalSql @'
WITH counts AS (
 SELECT
  (SELECT count(*) FROM users WHERE id IN ('usr_demo_one','usr_demo_two')) AS users,
  (SELECT count(*) FROM projects WHERE id IN ('prj_ai','prj_web')) AS projects,
  (SELECT count(*) FROM sessions WHERE id IN ('ses_101','ses_102','ses_103','ses_201','ses_202')) AS sessions
)
SELECT CASE
 WHEN users+projects+sessions=0 THEN 'new'
 WHEN users=2 AND projects=2 AND sessions=5
  AND EXISTS (SELECT 1 FROM projects WHERE id='prj_ai' AND user_id='usr_demo_one')
  AND EXISTS (SELECT 1 FROM projects WHERE id='prj_web' AND user_id='usr_demo_two')
  AND (SELECT count(*) FROM sessions WHERE id IN ('ses_101','ses_102','ses_103') AND project_id='prj_ai')=3
  AND (SELECT count(*) FROM sessions WHERE id IN ('ses_201','ses_202') AND project_id='prj_web')=2
 THEN 'existing' ELSE 'conflict' END FROM counts;
'@
if ($fixtureState -eq 'new') {
    $null = Invoke-LocalSql (Get-Content -LiteralPath (Join-Path $repoPath 'database\seeds\demo.sql') -Raw)
    Write-Host 'Loaded the five demo sessions.'
} elseif ($fixtureState -eq 'conflict') {
    throw 'Demo IDs are partial or have different relationships. Existing records were kept.'
}
Write-Output 'osiris_dev'
