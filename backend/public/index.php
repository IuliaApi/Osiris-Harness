<?php

declare(strict_types=1);

/** Local PostgreSQL-backed workspace API and browser entry point. */
require_once dirname(__DIR__) . '/bootstrap.php';

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Link: </api>; rel="service"');
header('Referrer-Policy: same-origin');
$requestId = bin2hex(random_bytes(8));
header('X-Request-Id: ' . $requestId);
$started = microtime(true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// IDs, arbitrary unmatched paths, query strings and exception messages may contain private data.
$group = explode('/', ltrim($path, '/'), 2)[0];
$logRoute = in_array($group, ['auth', 'api', 'projects', 'sessions'], true) ? $group : 'page';
register_shutdown_function(static function () use ($requestId, $started, $method, $logRoute): void {
    error_log(json_encode(['event' => 'http_request', 'requestId' => $requestId, 'method' => $method,
        'route' => $logRoute, 'status' => http_response_code(), 'durationMs' => round((microtime(true) - $started) * 1000)]));
});
try {
    if (preg_match('~\\A/errors/([45][0-9]{2})/([a-z][a-z0-9_]{0,63})\\z~', $path, $errorPage)) {
        if (ErrorPage::url((int) $errorPage[1], $errorPage[2]) !== $path) throw new ApiError(404, 'Route not found', 'not_found');
        requireHttpMethod($method, ['GET', 'HEAD']);
        respond(['error' => 'Workspace request failed', 'code' => $errorPage[2], 'requestId' => $requestId], (int) $errorPage[1]);
    }
    $asset = match ($path) {
        '/', '/login', '/register', '/dashboard', '/workspace', '/chat', '/manage', '/api-checks' => ['index.html', 'text/html'],
        '/assets/workspace.css' => ['workspace.css', 'text/css'],
        '/assets/workspace.js' => ['workspace.js', 'text/javascript'],
        '/assets/model-choice.js' => ['model-choice.js', 'text/javascript'],
        '/assets/provider-login.js' => ['provider-login.js', 'text/javascript'],
        '/assets/api-checks.js' => ['api-checks.js', 'text/javascript'],
        '/assets/chat-workbench.js' => ['chat-workbench.js', 'text/javascript'],
        '/assets/thread-messages.js' => ['thread-messages.js', 'text/javascript'],
        '/assets/command-menu.js' => ['command-menu.js', 'text/javascript'],
        '/assets/terminal-prompt.js' => ['terminal-prompt.js', 'text/javascript'],
        '/assets/agent-console.js' => ['agent-console.js', 'text/javascript'],
        '/assets/desktop-panel.js' => ['desktop-panel.js', 'text/javascript'],
        '/assets/desktop-panel.css' => ['desktop-panel.css', 'text/css'],
        '/assets/side-questions.js' => ['side-questions.js', 'text/javascript'],
        default => null,
    };
    if ($asset !== null) {
        requireHttpMethod($method, ['GET']);
        [$file, $contentType] = $asset;
        header("Content-Type: $contentType; charset=utf-8");
        if (readfile(dirname(__DIR__, 2) . '/web/' . $file) === false) {
            throw new RuntimeException('Workspace asset unavailable');
        }
        exit;
    }
    if (preg_match('~^/(?:auth|api|projects|sessions)(?:/|$)~D', $path) !== 1) {
        throw new ApiError(404, 'Route not found', 'not_found');
    }
    // Browser reads can encounter a real API error, such as an expired session.
    // Successful responses still negotiate data formats. Mutations negotiate
    // before executing, so unsupported clients cannot cause writes.
    if (!in_array($method, ['GET', 'HEAD'], true) || !ErrorPage::preferred()) responseMediaType();
    if (str_starts_with($path,'/auth/') || $path === '/auth') {
        $authMethod = match ($path) {
            '/auth/register','/auth/login','/auth/logout' => 'POST', '/auth/me' => 'GET',
            default => throw new ApiError(404, 'Route not found', 'not_found'),
        };
        if ($method !== $authMethod) { header('Allow: ' . $authMethod); throw new ApiError(405, 'Method not allowed'); }
        if (queryParams() !== []) { throw new ApiError(400, 'Authentication routes take no query parameters'); }
        if ($path === '/auth/login' || $path === '/auth/register') {
            Auth::sameOrigin();
            $body = readRequestBody();
            $auth = new Auth(connectWorkspaceDatabase(), $requestId);
            if ($path === '/auth/register') {
                $identity = $auth->register($body);
                header('Location: /auth/me');
                respond($identity, 201);
            }
            respond($auth->login($body));
        }
    }
    $token = Auth::cookieToken();
    $db = connectWorkspaceDatabase();
    $auth = new Auth($db,$requestId);
    $auth->authenticate($token);
    if (!in_array($method,['GET','HEAD','OPTIONS'],true)) { $auth->guardMutation(); }
    if ($path === '/auth/me') { respond($auth->identity()); }
    if ($path === '/auth/logout') { $auth->logout(); http_response_code(204); exit; }
    if (in_array($path, ['/api', '/api/runtime', '/api/models'], true)) {
        if ($method !== 'GET') { header('Allow: GET'); throw new ApiError(405, 'Method not allowed'); }
        if (queryParams() !== []) { throw new ApiError(400, 'This endpoint takes no query parameters'); }
        if ($path === '/api') { respond(apiDiscovery($auth)); }
        if ($path === '/api/models') { respond(ModelCatalog::list($db, $auth)); }
        respond(['harness' => 'Osiris', 'database' => 'PostgreSQL', 'ai' => SessionChat::runtime($db, $auth->user()),
            'capabilities' => ['session_chat' => true, 'database_history' => true, 'shell_execution' => false,
                'filesystem_access' => false, 'approved_virtual_workspace' => true, 'github_composite' => true],
            '_links' => ['self' => apiLink($path), 'service' => apiLink('/api')]]);
    }
    if ($path === '/api/maintenance/prune') {
        if ($method !== 'POST') { header('Allow: POST'); throw new ApiError(405, 'Method not allowed'); }
        if ($auth->user()['role'] !== 'admin') { throw new ApiError(403, 'Administrator permission required', 'forbidden'); }
        if (queryParams() !== [] || file_get_contents('php://input', false, null, 0, 1) !== '') {
            throw new ApiError(400, 'Maintenance takes no parameters or body');
        }
        $db->beginTransaction();
        Auth::prune($db);
        $auth->audit('maintenance.prune');
        $db->commit();
        http_response_code(204);
        exit;
    }
    if ($path === '/api/budget') {
        requireHttpMethod($method, ['GET']);
        if (queryParams() !== []) { throw new ApiError(400, 'Budget accepts no query parameters'); }
        respond(SessionChat::budget($db, $auth->user()['id']));
    }
    ProviderLogin::handleRequest($db, $auth, $method, $path);
    ProviderCredentials::handleRequest($db, $auth, $method, $path);
    WorkspaceToolRuns::handleRequest($db, $auth, $method, $path);
    ChatPermissions::handleRequest($db, $auth, $method, $path);
    ThreadCommands::handleRequest($db, $auth, $method, $path);
    AgentRuns::handleRequest($db, $auth, $method, $path);
    if (preg_match('~^/projects/([^/]+)/members(?:/([^/]+))?$~D', $path, $match)) {
        $auth->membership(rawurldecode($match[1]), isset($match[2]) ? rawurldecode($match[2]) : null, $method);
    }
    $store = new WorkspaceRepository($db, $auth);
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
    } elseif ($type === 'sessions' && in_array($segments[2] ?? null, ['messages', 'tasks'], true)) {
        $fixed['sessionId'] = rawurldecode($segments[1]);
        $type = $segments[2];
        $id = isset($segments[3]) ? rawurldecode($segments[3]) : null;
    } elseif (count($segments) !== 1) { throw new ApiError(404, 'Route not found', 'not_found'); }
    foreach ([$id, ...array_values($fixed)] as $part) {
        if ($part !== null && ($part === '' || strlen($part) > 100 || str_contains($part, "\0") || preg_match('//u', $part) !== 1)) {
            throw new ApiError(404, 'Route not found', 'not_found');
        }
    }
    $allowed = match (true) {
        $contextResource => ['GET'],
        $chatResource => ['POST'],
        $id === null => ['GET', 'POST'],
        $type === 'messages' => ['GET', 'DELETE'],
        default => ['GET', 'PATCH', 'DELETE'],
    };
    if (!in_array($method, $allowed, true)) { header('Allow: ' . implode(', ', $allowed)); throw new ApiError(405, 'Method not allowed'); }
    $query = queryParams();
    $deleteContents = $method === 'DELETE' && $type === 'sessions' && $id !== null
        && $fixed === [] && $query === ['deleteContents' => '1'];
    if ($method !== 'GET' && $query !== [] && !$deleteContents) {
        throw new ApiError(400, 'Unsupported query parameters for this request');
    }
    if ($fixed !== []) {
        $parentType = isset($fixed['projectId']) ? 'projects' : 'sessions';
        $parent = reset($fixed);
        if (!$store->exists($parentType, $parent)) { throw new ApiError(404, 'Parent not found', 'not_found'); }
    }
    if ($chatResource) {
        $auth->requireAccess('sessions', $id, 'write');
        respond(SessionChat::chat($db, $auth, $store, $id, readRequestBody()));
    }
    if ($method === 'GET') {
        if ($contextResource) {
            if ($query !== []) { throw new ApiError(400, 'Context resource takes no query parameters'); }
            $session = $store->get('sessions', $id);
            if ($session === null) { throw new ApiError(404, 'Resource not found', 'not_found'); }
            $project = $store->projectForSession($session);
            $children = [];
            foreach (['messages', 'tasks'] as $kind) {
                $collection = $store->list($kind, [], ['sessionId' => $id]);
                $collection['items'] = array_map(fn($row) => apiItem($store, $auth, $kind, $row), $collection['items']);
                $collection['_links'] = ['collection' => apiLink('/sessions/' . rawurlencode($id) . '/' . $kind)];
                $children[$kind] = $collection;
            }
            $external = fetchGitHubRepository($project['repositoryUrl'] ?? null);
            respond([
                '_links' => ['self' => apiLink($path), 'session' => apiLink('/sessions/' . rawurlencode($id))],
                'session' => apiItem($store, $auth, 'sessions', $session),
                'project' => $project === null ? null : apiItem($store, $auth, 'projects', $project),
                ...$children,
                'external' => $external,
                'sources' => ['local' => 'PostgreSQL', 'external' => $external['source']],
            ]);
        }
        $include = requestedSessionIncludes($query, $type);
        if ($id !== null) {
            foreach ($query as $key => $_) { if (!in_array($key, ['view', 'include'], true)) { throw new ApiError(400, "Unsupported query parameter: $key"); } }
            $item = $store->get($type, $id);
            if ($item === null || ($fixed !== [] && $item['sessionId'] !== $fixed['sessionId'])) { throw new ApiError(404, 'Resource not found', 'not_found'); }
            if ($type === 'tasks') { header('ETag: "' . $item['version'] . '"'); }
            respond(apiItem($store, $auth, $type, $item, $query, $include));
        }
        if ($type === 'sessions' && $fixed === [] && $query === []) {
            $items = [];
            foreach ($store->sessionsInCreationOrder() as $row) {
                $items[] = apiItem($store, $auth, 'sessions', $row);
            }
            respond($items);
        }
        $result = $store->list($type, $query, $fixed);
        $result['items'] = array_map(fn($row) => apiItem($store, $auth, $type, $row, $query, $include), $result['items']);
        $result['links'] = paginationLinks($query, $result['page']);
        $result['_links'] = pageControls($result['links']);
        $canCreate = $fixed === [] ? $auth->canCreateWorkspaceResources()
            : $auth->can(isset($fixed['projectId']) ? 'projects' : 'sessions', reset($fixed), 'write');
        if ($canCreate) {
            $result['_links']['create'] = apiLink($path, 'POST');
        }
        respond($result);
    }
    // Serialize permission changes with data mutations; no network I/O inside this transaction.
    $db->beginTransaction();
    if ($method === 'POST') {
        $created = $store->create($type, readRequestBody(), $fixed);
        $db->commit();
        $resource = apiItem($store, $auth, $type, $created);
        header('Location: ' . $resource['_links']['self']['href']);
        if ($type === 'tasks') { header('ETag: "' . $created['version'] . '"'); }
        respond($resource, 201);
    }
    if (isset($fixed['sessionId']) && ($store->get($type, $id)['sessionId'] ?? null) !== $fixed['sessionId']) {
        throw new ApiError(404, 'Resource not found', 'not_found');
    }
    if ($method === 'PATCH') {
        $updated = $store->update($type, $id, readRequestBody());
        if ($updated === null) { throw new ApiError(404, 'Resource not found', 'not_found'); }
        $db->commit();
        if ($type === 'tasks') { header('ETag: "' . $updated['version'] . '"'); }
        respond(apiItem($store, $auth, $type, $updated));
    }
    if (!($deleteContents ? $store->deleteSessionWithContents($id) : $store->delete($type, $id))) { throw new ApiError(404, 'Resource not found', 'not_found'); }
    $db->commit();
    http_response_code(204);
    exit;
} catch (ApiError $error) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    respond(['error' => $error->getMessage(), 'code' => $error->codeName, 'requestId' => $requestId], $error->httpStatus);
} catch (PDOException $error) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    error_log(json_encode(['event' => 'database_error', 'requestId' => $requestId, 'sqlState' => $error->getCode()]));
    $state = $error->getCode();
    if (in_array($state, ['40001', '40P01'], true)) { respond(['error' => 'Concurrent change; reload the resource before retrying', 'code' => 'concurrent_change', 'requestId' => $requestId], 409); }
    if ($state === '23505') { respond(['error' => 'Resource already exists', 'code' => 'conflict', 'requestId' => $requestId], 409); }
    if ($state === '23503') { respond(['error' => 'Referenced record is missing or resource has dependents', 'code' => 'conflict', 'requestId' => $requestId], 409); }
    if ($state === '23514' || $state === '23502' || $state === '22001') { respond(['error' => 'Invalid resource data', 'code' => 'validation_error', 'requestId' => $requestId], 422); }
    respond(['error' => 'Database or server operation failed', 'code' => 'internal_error', 'requestId' => $requestId], 500);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    error_log(json_encode(['event' => 'internal_error', 'requestId' => $requestId, 'class' => get_class($error)]));
    respond(['error' => 'Database or server operation failed', 'code' => 'internal_error', 'requestId' => $requestId], 500);
}
