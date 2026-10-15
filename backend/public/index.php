<?php

declare(strict_types=1);

final readonly class SessionController
{
    private const COLUMNS = 'id, project_id, title, status, created_at, updated_at';

    public function __construct(private PDO $database)
    {
    }

    public function list(): array
    {
        $rows = $this->database->query('SELECT ' . self::COLUMNS . ' FROM sessions ORDER BY created_at, id');
        return array_map($this->record(...), $rows->fetchAll(PDO::FETCH_ASSOC));
    }

    public function get(string $id): ?array
    {
        $query = $this->database->prepare('SELECT ' . self::COLUMNS . ' FROM sessions WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->record($row);
    }

    private function record(array $row): array
    {
        return [
            'id' => $row['id'],
            'projectId' => $row['project_id'],
            'title' => $row['title'],
            'status' => $row['status'],
            'createdAt' => $this->timestamp($row['created_at']),
            'updatedAt' => $this->timestamp($row['updated_at']),
        ];
    }

    private function timestamp(string $value): string
    {
        $time = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        $fraction = rtrim($time->format('u'), '0');
        return $time->format('Y-m-d\TH:i:s') . ($fraction === '' ? '' : '.' . $fraction) . 'Z';
    }
}

function connectDatabase(): PDO
{
    $url = getenv('DATABASE_URL');
    $parts = parse_url($url === false
        ? 'postgres://osiris_dev:osiris_dev_local_only@127.0.0.1:55438/osiris_dev'
        : $url);
    if ($parts === false) {
        throw new RuntimeException('Invalid DATABASE_URL');
    }
    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? 5432;
    $name = ltrim($parts['path'] ?? '', '/');
    $username = rawurldecode($parts['user'] ?? '');
    $localTarget = ($host === 'db' && $port === 5432)
        || (in_array($host, ['127.0.0.1', 'localhost'], true) && $port === 55438);
    if (($parts['scheme'] ?? '') !== 'postgres' || !$localTarget || $username !== 'osiris_dev'
        || preg_match('/\Aosiris_(?:dev|empty_demo|test_[a-f0-9]{12})\z/', $name) !== 1
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Only the local Osiris databases are allowed');
    }
    $database = new PDO(
        "pgsql:host=$host;port=$port;dbname=$name;connect_timeout=5;sslmode=disable",
        $username,
        rawurldecode($parts['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $database->exec("SET TIME ZONE 'UTC'");
    $database->exec("SET statement_timeout = '5s'");
    $database->exec('SET default_transaction_read_only = on');
    return $database;
}

function sendJson(array $value, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    $path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
    $itemRoute = preg_match('~\A/sessions/([^/]+)\z~', $path, $matches) === 1;
    if ($path !== '/' && $path !== '/sessions' && !$itemRoute) {
        sendJson(['error' => 'Route not found'], 404);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        sendJson(['error' => 'Method not allowed'], 405);
    }
    if ($path === '/') {
        $page = file_get_contents(dirname(__DIR__, 2) . '/web/index.html');
        if ($page === false) {
            throw new RuntimeException('Web page not found');
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $page;
        exit;
    }
    $controller = new SessionController(connectDatabase());
    if (!$itemRoute) {
        sendJson($controller->list());
    }
    $id = rawurldecode($matches[1]);
    if (str_contains($id, "\0") || preg_match('//u', $id) !== 1) {
        sendJson(['error' => 'Session not found'], 404);
    }
    $session = $controller->get($id);
    if ($session === null) {
        sendJson(['error' => 'Session not found'], 404);
    }
    sendJson($session);
} catch (Throwable $error) {
    error_log((string) $error);
    sendJson(['error' => 'Database or server operation failed'], 500);
}
