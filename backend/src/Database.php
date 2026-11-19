<?php

declare(strict_types=1);

/** Only explicitly local development/test databases may receive these credentials. */
function connectWorkspaceDatabase(bool $operator = false): PDO
{
    $url = getenv('DATABASE_URL');
    $parts = is_string($url) ? parse_url($url) : false;
    if ($parts === false) { throw new RuntimeException('Invalid DATABASE_URL'); }
    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? 5432;
    $name = ltrim($parts['path'] ?? '', '/');
    $username = rawurldecode($parts['user'] ?? '');
    $local = ($host === 'db' && $port === 5432) || (in_array($host, ['127.0.0.1', 'localhost'], true) && $port === 55438);
    if (($parts['scheme'] ?? '') !== 'postgres' || !$local
        || preg_match('/\Aosiris_(?:dev|empty_demo|test_(?:populated|empty))\z/D', $name) !== 1
        || isset($parts['query']) || isset($parts['fragment'])) { throw new RuntimeException('Only local Osiris databases are allowed'); }
    if ($username === 'osiris_app' && !$operator && !isset($parts['pass'])) {
        $file = getenv('OSIRIS_DB_PASSWORD_FILE');
        $raw = is_string($file) && $file !== '' && !str_contains($file, '://') && is_file($file)
            ? @file_get_contents($file, false, null, 0, 259) : false;
        if (!is_string($raw) || preg_match('~\A([A-Za-z0-9+/=]{16,256})(?:\r?\n)?\z~D', $raw, $match) !== 1) {
            throw new RuntimeException('Invalid application database credential file');
        }
        $password = $match[1];
    } elseif ($username === 'osiris_dev' && isset($parts['pass']) && ($operator && PHP_SAPI === 'cli')) {
        $password = rawurldecode($parts['pass']);
    } else {
        throw new RuntimeException('Invalid database role or credential source');
    }
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name;connect_timeout=5;sslmode=disable", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET TIME ZONE 'UTC'");
    $pdo->exec("SET statement_timeout = '5s'");
    return $pdo;
}

function executeSql(PDO $db, string $query, array $args = []): PDOStatement
{
    $stmt = $db->prepare($query);
    $stmt->execute($args);
    return $stmt;
}

function inDatabaseTransaction(PDO $db, callable $work): mixed
{
    $db->beginTransaction();
    try {
        $result = $work();
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
}
