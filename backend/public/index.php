<?php

declare(strict_types=1);

/** Osiris Build 2: database-backed REST operations for an AI coding harness. */
require_once __DIR__ . '/Representation.php';
require_once __DIR__ . '/GitHubContext.php';
require_once __DIR__ . '/AIProvider.php';

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message, public readonly string $codeName = 'invalid_request')
    {
        parent::__construct($message);
    }
}

final class Store
{
    private const FIELDS = [
        'projects' => ['id', 'user_id', 'name', 'language', 'repository_url', 'created_at', 'updated_at'],
        'sessions' => ['id', 'project_id', 'title', 'status', 'created_at', 'updated_at'],
        'messages' => ['id', 'session_id', 'role', 'content', 'created_at'],
        'tasks' => ['id', 'session_id', 'title', 'status', 'result', 'created_at', 'updated_at'],
    ];
    private const WRITABLE = [
        'projects' => ['userId' => 'user_id', 'name' => 'name', 'language' => 'language', 'repositoryUrl' => 'repository_url'],
        'sessions' => ['projectId' => 'project_id', 'title' => 'title', 'status' => 'status'],
        'messages' => ['sessionId' => 'session_id', 'role' => 'role', 'content' => 'content'],
        'tasks' => ['sessionId' => 'session_id', 'title' => 'title', 'status' => 'status', 'result' => 'result'],
    ];
    private const FILTERS = [
        'projects' => ['userId' => 'user_id', 'language' => 'language'],
        'sessions' => ['projectId' => 'project_id', 'status' => 'status'],
        'messages' => ['sessionId' => 'session_id', 'role' => 'role'],
        'tasks' => ['sessionId' => 'session_id', 'status' => 'status'],
    ];
    private const SORTS = [
        'projects' => ['createdAt' => 'created_at', 'updatedAt' => 'updated_at', 'name' => 'name', 'id' => 'id'],
        'sessions' => ['createdAt' => 'created_at', 'updatedAt' => 'updated_at', 'title' => 'title', 'status' => 'status', 'id' => 'id'],
        'messages' => ['createdAt' => 'created_at', 'id' => 'id'],
        'tasks' => ['createdAt' => 'created_at', 'updatedAt' => 'updated_at', 'title' => 'title', 'status' => 'status', 'id' => 'id'],
    ];

    public function __construct(private readonly PDO $db) {}

    public function exists(string $type, string $id): bool
    {
        return $this->get($type, $id) !== null;
    }

    public function get(string $type, string $id): ?array
    {
        $sql = 'SELECT ' . implode(', ', self::FIELDS[$type]) . " FROM $type WHERE id = ?";
        $statement = $this->db->prepare($sql);
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->shape($row);
    }

    public function list(string $type, array $query, array $fixed = []): array
    {
        $allowed = array_merge(array_keys(self::FILTERS[$type]), ['sort', 'order', 'limit', 'offset', 'view', 'include', 'q']);
        foreach ($query as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_string($value)) {
                throw new ApiError(400, "Unsupported or repeated query parameter: $key");
            }
        }
        $where = [];
        $args = [];
        foreach (self::FILTERS[$type] as $key => $column) {
            if (isset($query[$key]) || isset($fixed[$key])) {
                $value = $fixed[$key] ?? $query[$key];
                if (isset($fixed[$key], $query[$key]) && $fixed[$key] !== $query[$key]) {
                    throw new ApiError(400, "Conflicting $key filter");
                }
                if ($value === '') { throw new ApiError(400, "$key cannot be empty"); }
                $where[] = "$column = ?";
                $args[] = $value;
            }
        }
        if (isset($query['q'])) {
            if (trim($query['q']) === '' || mbSafeLen($query['q']) > 200) { throw new ApiError(400, 'q must be 1–200 characters'); }
            $search = in_array($type, ['projects', 'tasks'], true) ? 'name' : 'title';
            if ($type === 'messages') { $search = 'content'; }
            if ($type === 'tasks') { $search = 'title'; }
            $where[] = "$search ILIKE ?";
            $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query['q']) . '%';
        }
        $sort = $query['sort'] ?? 'createdAt';
        if (!isset(self::SORTS[$type][$sort])) { throw new ApiError(400, 'Invalid sort field'); }
        $order = strtolower($query['order'] ?? 'asc');
        if (!in_array($order, ['asc', 'desc'], true)) { throw new ApiError(400, 'order must be asc or desc'); }
        $limit = $this->boundedInt($query['limit'] ?? '25', 'limit', 1, 100);
        $offset = $this->boundedInt($query['offset'] ?? '0', 'offset', 0, 1000000);
        $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM $type$condition");
        $totalStmt->execute($args);
        $total = (int) $totalStmt->fetchColumn();
        $column = self::SORTS[$type][$sort];
        $sql = 'SELECT ' . implode(', ', self::FIELDS[$type]) . " FROM $type$condition ORDER BY $column $order, id $order LIMIT ? OFFSET ?";
        $statement = $this->db->prepare($sql);
        $pos = 1;
        foreach ($args as $value) { $statement->bindValue($pos++, $value); }
        $statement->bindValue($pos++, $limit, PDO::PARAM_INT);
        $statement->bindValue($pos, $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map($this->shape(...), $statement->fetchAll(PDO::FETCH_ASSOC));
        return ['items' => $items, 'page' => [
            'total' => $total, 'limit' => $limit, 'offset' => $offset,
            'hasMore' => $offset + count($items) < $total,
        ]];
    }

    public function create(string $type, array $payload, array $fixed = []): array
    {
        $fields = $this->validatePayload($type, $payload, false, $fixed);
        $id = $payload['id'] ?? $this->newId($type);
        if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $id)) { throw new ApiError(422, 'id must be 1–100 URL-safe characters'); }
        $fields = ['id' => $id] + $fields;
        $columns = array_keys($fields);
        $statement = $this->db->prepare('INSERT INTO ' . $type . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $statement->execute(array_values($fields));
        return $this->get($type, $id);
    }

    public function update(string $type, string $id, array $payload): ?array
    {
        if ($type === 'messages') { throw new ApiError(405, 'Messages are immutable'); }
        if ($type === 'tasks' && array_key_exists('sessionId', $payload)) { throw new ApiError(422, 'Task sessionId cannot be changed'); }
        if (!$this->exists($type, $id)) { return null; }
        $fields = $this->validatePayload($type, $payload, true);
        if ($fields === []) { throw new ApiError(422, 'PATCH requires at least one editable field'); }
        $sets = array_map(static fn(string $column) => "$column = ?", array_keys($fields));
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $statement = $this->db->prepare('UPDATE ' . $type . ' SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $statement->execute([...array_values($fields), $id]);
        return $this->get($type, $id);
    }

    public function delete(string $type, string $id): bool
    {
        $statement = $this->db->prepare("DELETE FROM $type WHERE id = ?");
        $statement->execute([$id]);
        return $statement->rowCount() > 0;
    }

    /** Fetch only the recent conversational roles, bounded before reaching the provider. */
    public function recentConversation(string $sessionId): array
    {
        $stmt = $this->db->prepare("SELECT role, content FROM messages WHERE session_id = ? AND role IN ('user','assistant') ORDER BY created_at DESC, id DESC LIMIT 12");
        $stmt->execute([$sessionId]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Commit both turns together; an unsuccessful upstream request stores neither. */
    public function recordExchange(string $sessionId, string $prompt, string $answer): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO messages (id, session_id, role, content, created_at) VALUES (?, ?, ?, ?, clock_timestamp())');
            $userId = $this->newId('messages');
            $assistantId = $this->newId('messages');
            $stmt->execute([$userId, $sessionId, 'user', $prompt]);
            $stmt->execute([$assistantId, $sessionId, 'assistant', $answer]);
            $user = $this->get('messages', $userId);
            $assistant = $this->get('messages', $assistantId);
            $this->db->commit();
            return ['userMessage' => $user, 'assistantMessage' => $assistant];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $exception;
        }
    }

    public function composeSession(array $session, array $include): array
    {
        foreach ($include as $part) {
            if ($part === 'project') { $session['project'] = $this->get('projects', $session['projectId']); }
            if ($part === 'messages' || $part === 'tasks') {
                $result = $this->list($part, $part === 'messages' ? ['limit' => '50', 'sort' => 'createdAt', 'order' => 'desc'] : ['limit' => '25'], ['sessionId' => $session['id']]);
                $session[$part] = $part === 'messages' ? array_reverse($result['items']) : $result['items'];
                $session[$part . 'Page'] = $result['page'];
            }
        }
        return $session;
    }

    private function validatePayload(string $type, array $payload, bool $patch, array $fixed = []): array
    {
        if (array_is_list($payload) && $payload !== []) { throw new ApiError(422, 'JSON body must be an object'); }
        $allowed = self::WRITABLE[$type];
        foreach ($payload as $key => $_) {
            if (!isset($allowed[$key]) && !(!$patch && $key === 'id')) { throw new ApiError(422, "Unknown or read-only field: $key"); }
        }
        foreach ($fixed as $key => $value) {
            if (isset($payload[$key]) && $payload[$key] !== $value) { throw new ApiError(422, "Conflicting $key"); }
            $payload[$key] = $value;
        }
        $required = [
            'projects' => ['userId', 'name'], 'sessions' => ['projectId', 'title'],
            'messages' => ['sessionId', 'role', 'content'], 'tasks' => ['sessionId', 'title'],
        ];
        if (!$patch) {
            foreach ($required[$type] as $key) { if (!isset($payload[$key])) { throw new ApiError(422, "Missing required field: $key"); } }
        }
        $out = [];
        foreach ($allowed as $key => $column) {
            if (!array_key_exists($key, $payload)) { continue; }
            $value = $payload[$key];
            $nullable = in_array($key, ['language', 'repositoryUrl', 'result'], true);
            if ($value === null && $nullable) { $out[$column] = null; continue; }
            if (!is_string($value) || (!$nullable && trim($value) === '') || mbSafeLen($value) > 10000) {
                throw new ApiError(422, "$key must be a nonempty string (max 10000 characters), or null if optional");
            }
            if (preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value) === 1) {
                throw new ApiError(422, "$key contains unsupported control characters");
            }
            if ($key === 'status') {
                $values = $type === 'tasks' ? ['pending', 'running', 'completed', 'failed'] : ['active', 'completed', 'archived'];
                if (!in_array($value, $values, true)) { throw new ApiError(422, 'Invalid status'); }
            }
            if ($key === 'role' && !in_array($value, ['system', 'user', 'assistant', 'tool'], true)) { throw new ApiError(422, 'Invalid message role'); }
            if ($key === 'repositoryUrl' && $value !== '' && !preg_match('~\Ahttps?://~i', $value)) { throw new ApiError(422, 'repositoryUrl must be HTTP(S)'); }
            $out[$column] = $value;
        }
        return $out;
    }

    private function boundedInt(string $value, string $label, int $min, int $max): int
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) || strlen($value) > 9 || (int) $value < $min || (int) $value > $max) {
            throw new ApiError(400, "$label must be between $min and $max");
        }
        return (int) $value;
    }

    private function newId(string $type): string
    {
        return ['projects' => 'prj_', 'sessions' => 'ses_', 'messages' => 'msg_', 'tasks' => 'tsk_'][$type] . bin2hex(random_bytes(12));
    }

    private function shape(array $row): array
    {
        $data = [];
        foreach ($row as $key => $value) {
            $jsonKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            if ($value !== null && str_ends_with($key, '_at')) {
                $time = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
                $fraction = rtrim($time->format('u'), '0');
                $value = $time->format('Y-m-d\TH:i:s') . ($fraction === '' ? '' : '.' . $fraction) . 'Z';
            }
            $data[$jsonKey] = $value;
        }
        return $data;
    }
}

function mbSafeLen(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }

function db(): PDO
{
    $url = getenv('DATABASE_URL');
    $parts = parse_url($url === false ? 'postgres://osiris_dev:osiris_dev_local_only@127.0.0.1:55438/osiris_dev' : $url);
    if ($parts === false) { throw new RuntimeException('Invalid DATABASE_URL'); }
    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? 5432;
    $name = ltrim($parts['path'] ?? '', '/');
    $username = rawurldecode($parts['user'] ?? '');
    $local = ($host === 'db' && $port === 5432) || (in_array($host, ['127.0.0.1', 'localhost'], true) && $port === 55438);
    if (($parts['scheme'] ?? '') !== 'postgres' || !$local || $username !== 'osiris_dev'
        || preg_match('/\Aosiris_(?:dev|empty_demo|test_[a-f0-9]{12})\z/D', $name) !== 1
        || isset($parts['query']) || isset($parts['fragment'])) { throw new RuntimeException('Only local Osiris databases are allowed'); }
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name;connect_timeout=5;sslmode=disable", $username, rawurldecode($parts['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET TIME ZONE 'UTC'");
    $pdo->exec("SET statement_timeout = '5s'");
    return $pdo;
}

function respond(mixed $value, int $status = 200): never
{
    sendRepresentation($value, $status);
}

function paginationLinks(array $query, array $page): array
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $build = static function (int $offset) use ($query, $page, $path): string {
        $params = $query;
        $params['limit'] = (string) $page['limit'];
        $params['offset'] = (string) $offset;
        return $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    };
    return [
        'self' => $build($page['offset']),
        'next' => $page['hasMore'] ? $build($page['offset'] + $page['limit']) : null,
        'previous' => $page['offset'] > 0 ? $build(max(0, $page['offset'] - $page['limit'])) : null,
    ];
}

function inputJson(): array
{
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') { throw new ApiError(415, 'Content-Type must be application/json'); }
    $size = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($size > 65536) { throw new ApiError(413, 'JSON body exceeds 64 KiB'); }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) { throw new ApiError(413, 'JSON body exceeds 64 KiB'); }
    try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError(400, 'Malformed JSON'); }
    if (!is_array($data) || (strlen(trim($raw)) > 0 && trim($raw)[0] !== '{')) { throw new ApiError(422, 'JSON body must be an object'); }
    return $data;
}

function queryParams(): array
{
    $params = [];
    foreach (explode('&', $_SERVER['QUERY_STRING'] ?? '') as $pair) {
        if ($pair === '') { continue; }
        $split = explode('=', $pair, 2);
        $key = urldecode($split[0]);
        if (array_key_exists($key, $params)) { throw new ApiError(400, "Repeated query parameter: $key"); }
        $params[$key] = urldecode($split[1] ?? '');
    }
    return $params;
}

function representation(array $item, array $query, string $type): array
{
    $view = $query['view'] ?? 'full';
    if (!in_array($view, ['full', 'summary'], true)) { throw new ApiError(400, 'view must be full or summary'); }
    if ($view === 'summary') {
        $keys = $type === 'projects' ? ['id', 'name', 'language'] : ($type === 'messages' ? ['id', 'role', 'createdAt'] : ['id', 'sessionId', 'projectId', 'title', 'status', 'createdAt']);
        return array_intersect_key($item, array_flip($keys));
    }
    return $item;
}

function includes(array $query, string $type): array
{
    if (!isset($query['include']) || $query['include'] === '') { return []; }
    if ($type !== 'sessions') { throw new ApiError(400, 'include is supported only for sessions'); }
    $parts = explode(',', $query['include']);
    if (count($parts) !== count(array_unique($parts)) || array_diff($parts, ['project', 'messages', 'tasks'])) {
        throw new ApiError(400, 'include supports project,messages,tasks');
    }
    return $parts;
}

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$requestId = bin2hex(random_bytes(8));
header('X-Request-Id: ' . $requestId);
$started = microtime(true);
register_shutdown_function(static function () use ($requestId, $started): void {
    error_log(json_encode(['event' => 'http_request', 'requestId' => $requestId, 'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET', 'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), 'status' => http_response_code(), 'durationMs' => round((microtime(true) - $started) * 1000)], JSON_UNESCAPED_SLASHES));
});
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
try {
    if (in_array($path, ['/', '/login', '/dashboard', '/workspace', '/chat', '/build-2'], true)) {
        if ($method !== 'GET') { header('Allow: GET'); throw new ApiError(405, 'Method not allowed'); }
        $page = file_get_contents(dirname(__DIR__, 2) . '/web/index.html');
        if ($page === false) { throw new RuntimeException('Web page not found'); }
        header('Content-Type: text/html; charset=utf-8');
        echo $page;
        exit;
    }
    if ($path === '/api/runtime') {
        if ($method !== 'GET') { header('Allow: GET'); throw new ApiError(405, 'Method not allowed'); }
        responseMediaType();
        if (queryParams() !== []) { throw new ApiError(400, 'Runtime endpoint takes no query parameters'); }
        respond(['harness' => 'Osiris', 'database' => 'PostgreSQL', 'ai' => AIProvider::status(),
            'capabilities' => ['session_chat' => true, 'database_history' => true, 'shell_execution' => false,
                'filesystem_access' => false, 'github_composite' => true]]);
    }
    $segments = explode('/', trim($path, '/'));
    if (count($segments) < 1 || count($segments) > 4 || !in_array($segments[0], ['projects', 'sessions'], true)) { throw new ApiError(404, 'Route not found', 'not_found'); }
    $type = $segments[0];
    $fixed = [];
    $id = null;
    $contextResource = false;
    $chatResource = false;
    if (count($segments) === 2) { $id = rawurldecode($segments[1]); }
    elseif (count($segments) === 3 && $type === 'projects' && $segments[2] === 'sessions') {
        $fixed['projectId'] = rawurldecode($segments[1]); $type = 'sessions';
    } elseif (count($segments) === 3 && $type === 'sessions' && $segments[2] === 'context') {
        $id = rawurldecode($segments[1]); $contextResource = true;
    } elseif (count($segments) === 3 && $type === 'sessions' && $segments[2] === 'chat') {
        $id = rawurldecode($segments[1]); $chatResource = true;
    } elseif (count($segments) === 3 && $type === 'sessions' && in_array($segments[2], ['messages', 'tasks'], true)) {
        $fixed['sessionId'] = rawurldecode($segments[1]); $type = $segments[2];
    } elseif (count($segments) === 4 && $type === 'sessions' && in_array($segments[2], ['messages', 'tasks'], true)) {
        $fixed['sessionId'] = rawurldecode($segments[1]); $type = $segments[2];
        $id = rawurldecode($segments[3]);
    } elseif (count($segments) !== 1) { throw new ApiError(404, 'Route not found', 'not_found'); }
    foreach ([$id, ...array_values($fixed)] as $part) {
        if ($part !== null && ($part === '' || strlen($part) > 100 || str_contains($part, "\0") || preg_match('//u', $part) !== 1)) {
            throw new ApiError(404, 'Route not found', 'not_found');
        }
    }
    $allowed = $contextResource ? ['GET'] : ($chatResource ? ['POST'] : ($id !== null ? ['GET', 'PATCH', 'DELETE'] : ['GET', 'POST']));
    if ($type === 'messages' && $id !== null) { $allowed = ['GET', 'DELETE']; }
    if (!in_array($method, $allowed, true)) { header('Allow: ' . implode(', ', $allowed)); throw new ApiError(405, 'Method not allowed'); }
    // Negotiate before database activity so unsupported Accept returns a deterministic 406.
    if ($method === 'GET' || $method === 'POST' || $method === 'PATCH') { responseMediaType(); }
    $store = new Store(db());
    if ($fixed !== []) {
        $parentType = isset($fixed['projectId']) ? 'projects' : 'sessions';
        $parent = reset($fixed);
        if (!$store->exists($parentType, $parent)) { throw new ApiError(404, 'Parent not found', 'not_found'); }
    }
    if ($chatResource) {
        if (queryParams() !== []) { throw new ApiError(400, 'Chat endpoint takes no query parameters'); }
        $body = inputJson();
        foreach ($body as $field => $_) {
            if (!in_array($field, ['message'], true)) {
                throw new ApiError(422, 'Unknown chat field: ' . $field, 'validation_error');
            }
        }
        if (!isset($body['message']) || !is_string($body['message']) ||
            trim($body['message']) === '' || mbSafeLen($body['message']) > 6000) {
            throw new ApiError(422, 'message must be a nonempty string of at most 6000 characters', 'validation_error');
        }
        $provider = 'openai';
        $model = $body['model'] ?? null;
        if (!is_string($provider) || !in_array($provider, ['openai', 'anthropic', 'ollama'], true)
            || ($model !== null && (!is_string($model) || !AIProvider::validModel($model)))) {
            throw new ApiError(422, 'Invalid provider or model', 'validation_error');
        }
        $session = $store->get('sessions', $id);
        if ($session === null) { throw new ApiError(404, 'Session not found', 'not_found'); }
        if ($session['status'] !== 'active') { throw new ApiError(409, 'Only active sessions can receive chat messages', 'invalid_session_state'); }
        $project = $store->get('projects', $session['projectId']);
        $previous = $store->recentConversation($id);
        $answer = AIProvider::reply(trim($body['message']), $previous, $session, $project, $provider, $model);
        $turns = $store->recordExchange($id, trim($body['message']), $answer['text']);
        respond(['sessionId' => $id, ...$turns, 'provider' => $answer['provider'], 'model' => $answer['model']]);
    }
    if ($method === 'GET') {
        $query = queryParams();
        if ($contextResource) {
            if ($query !== [] && $query !== ['simulateUpstreamFailure' => '1']) { throw new ApiError(400, 'Context resource accepts only simulateUpstreamFailure=1'); }
            $session = $store->get('sessions', $id);
            if ($session === null) { throw new ApiError(404, 'Resource not found', 'not_found'); }
            $project = $store->get('projects', $session['projectId']);
            $messages = $store->list('messages', [], ['sessionId' => $id]);
            $tasks = $store->list('tasks', [], ['sessionId' => $id]);
            respond([
                'session' => $session, 'project' => $project,
                'messages' => $messages, 'tasks' => $tasks,
                'external' => fetchGitHubRepository($project['repositoryUrl'] ?? null, isset($query['simulateUpstreamFailure'])),
                'sources' => ['local' => 'PostgreSQL', 'external' => 'GitHub REST API'],
            ]);
        }
        $include = includes($query, $type);
        if ($id !== null) {
            foreach ($query as $key => $_) { if (!in_array($key, ['view', 'include'], true)) { throw new ApiError(400, "Unsupported query parameter: $key"); } }
            $item = $store->get($type, $id);
            if ($item === null || ($fixed !== [] && $item['sessionId'] !== $fixed['sessionId'])) { throw new ApiError(404, 'Resource not found', 'not_found'); }
            $item = representation($item, $query, $type);
            if ($include !== []) { $item = $store->composeSession($item, $include); }
            respond($item);
        }
        $result = $store->list($type, $query, $fixed);
        $result['items'] = array_map(static function (array $item) use ($query, $type, $include, $store): array {
            $item = representation($item, $query, $type);
            return $include === [] ? $item : $store->composeSession($item, $include);
        }, $result['items']);
        $result['links'] = paginationLinks($query, $result['page']);
        // Legacy /sessions remains a plain array when no controls are supplied.
        if ($type === 'sessions' && $fixed === [] && $query === []) {
            $all = $store->list('sessions', ['limit' => '100', 'offset' => '0']);
            // Keep the original unbounded Build 1 list for existing clients.
            $items = $all['items'];
            for ($offset = 100; $offset < $all['page']['total']; $offset += 100) {
                $next = $store->list('sessions', ['limit' => '100', 'offset' => (string) $offset]);
                array_push($items, ...$next['items']);
            }
            respond($items);
        }
        respond($result);
    }
    if ($method === 'POST') {
        $created = $store->create($type, inputJson(), $fixed);
        header('Location: /' . ($type === 'messages' || $type === 'tasks' ? 'sessions/' . rawurlencode($created['sessionId']) . '/' . $type : $type) . '/' . rawurlencode($created['id']));
        respond($created, 201);
    }
    if ($method === 'PATCH') {
        if ($fixed !== [] && (!$store->get($type, $id) || $store->get($type, $id)['sessionId'] !== $fixed['sessionId'])) { throw new ApiError(404, 'Resource not found', 'not_found'); }
        $updated = $store->update($type, $id, inputJson());
        if ($updated === null) { throw new ApiError(404, 'Resource not found', 'not_found'); }
        respond($updated);
    }
    if ($fixed !== [] && (!$store->get($type, $id) || $store->get($type, $id)['sessionId'] !== $fixed['sessionId'])) { throw new ApiError(404, 'Resource not found', 'not_found'); }
    if (!$store->delete($type, $id)) { throw new ApiError(404, 'Resource not found', 'not_found'); }
    http_response_code(204);
    exit;
} catch (ApiError $error) {
    if ($error->httpStatus === 406) {
        http_response_code(406);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $error->getMessage(), 'code' => $error->codeName, 'requestId' => $requestId]);
        exit;
    }
    respond(['error' => $error->getMessage(), 'code' => $error->codeName, 'requestId' => $requestId], $error->httpStatus);
} catch (PDOException $error) {
    error_log(json_encode(['requestId' => $requestId, 'method' => $method, 'path' => $path, 'sqlState' => $error->getCode(), 'durationMs' => round((microtime(true) - $started) * 1000)], JSON_UNESCAPED_SLASHES));
    $state = $error->getCode();
    if ($state === '23505') { respond(['error' => 'Resource already exists', 'code' => 'conflict', 'requestId' => $requestId], 409); }
    if ($state === '23503') { respond(['error' => 'Referenced record is missing or resource has dependents', 'code' => 'conflict', 'requestId' => $requestId], 409); }
    if ($state === '23514' || $state === '23502' || $state === '22001') { respond(['error' => 'Invalid resource data', 'code' => 'validation_error', 'requestId' => $requestId], 422); }
    respond(['error' => 'Database or server operation failed', 'code' => 'internal_error', 'requestId' => $requestId], 500);
} catch (Throwable $error) {
    error_log(json_encode(['requestId' => $requestId, 'method' => $method, 'path' => $path, 'error' => $error->getMessage(), 'durationMs' => round((microtime(true) - $started) * 1000)], JSON_UNESCAPED_SLASHES));
    respond(['error' => 'Database or server operation failed', 'code' => 'internal_error', 'requestId' => $requestId], 500);
}
