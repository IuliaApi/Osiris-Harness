<?php
declare(strict_types=1);

function apiLink(string $href, string $method = 'GET'): array
{
    return ['href' => $href, 'method' => $method];
}

function apiDiscovery(Auth $auth): array
{
    $links = [
        'self' => apiLink('/api'), 'me' => apiLink('/auth/me'), 'logout' => apiLink('/auth/logout', 'POST'),
        'projects' => apiLink('/projects'), 'sessions' => apiLink('/sessions'),
        'runtime' => apiLink('/api/runtime'), 'models' => apiLink('/api/models'), 'budget' => apiLink('/api/budget'),
    ];
    if ($auth->canCreateWorkspaceResources()) {
        $links['createProject'] = apiLink('/projects', 'POST');
        $links['createSession'] = apiLink('/sessions', 'POST');
        $links['credentialOpenai'] = apiLink('/api/credentials/openai', 'PUT');
        $links['credentialAnthropic'] = apiLink('/api/credentials/anthropic', 'PUT');
    }
    if ($auth->user()['role'] === 'admin') { $links['prune'] = apiLink('/api/maintenance/prune', 'POST'); }
    return ['name' => 'Osiris', '_links' => $links];
}

/** Construct transitions from the full row, even when the client requests summary fields. */
function resourceLinks(string $type, array $item, Auth $auth): array
{
    $id = rawurlencode($item['id']);
    $nested = in_array($type, ['messages', 'tasks'], true);
    $session = $nested ? '/sessions/' . rawurlencode($item['sessionId']) : null;
    $self = ($session ?? '') . '/' . $type . '/' . $id;
    $links = ['self' => apiLink($self)];
    $write = $auth->can($type, $item['id'], 'write');
    if ($write && $type !== 'messages' && !($type === 'tasks' && $item['status'] === 'completed')) {
        $links['update'] = apiLink($self, 'PATCH');
    }
    $manage = $auth->can($type, $item['id'], 'manage');
    if ($manage) {
        $links['delete'] = apiLink($self . ($type === 'sessions' ? '?deleteContents=1' : ''), 'DELETE');
    }
    if ($type === 'projects') {
        $links['sessions'] = apiLink($self . '/sessions');
        if ($write) { $links['createSession'] = apiLink($self . '/sessions', 'POST'); }
        if ($manage) {
            $links['members'] = apiLink($self . '/members');
            $links['setVisibility'] = apiLink($self, 'PATCH');
        }
    } elseif ($type === 'sessions') {
        if ($item['projectId'] !== null) {
            $links['project'] = apiLink('/projects/' . rawurlencode($item['projectId']));
        }
        foreach (['context', 'messages', 'tasks'] as $rel) { $links[$rel] = apiLink($self . '/' . $rel); }
        $links['toolRuns'] = apiLink($self . '/tool-runs');
        $links['agent'] = apiLink($self . '/agent');
        $links['permissions'] = apiLink($self . '/permissions');
        $links['goal'] = apiLink($self . '/goal');
        $links['asides'] = apiLink($self . '/asides');
        if ($write) {
            $links['setPermissions'] = apiLink($self . '/permissions', 'PUT');
            $links['createMessage'] = apiLink($self . '/messages', 'POST');
            $links['createTask'] = apiLink($self . '/tasks', 'POST');
            $links['requestTool'] = apiLink($self . '/tool-runs', 'POST');
            if ($item['status'] === 'active') {
                $links['chat'] = apiLink($self . '/chat', 'POST');
                $links['startAgent'] = apiLink($self . '/agent', 'POST');
                $links['setGoal'] = apiLink($self . '/goal', 'PUT');
                $links['askAside'] = apiLink($self . '/asides', 'POST');
            }
        }
    } else {
        $links['session'] = apiLink($session);
    }
    return $links;
}

function apiItem(WorkspaceRepository $store, Auth $auth, string $type, array $item, array $query = [], array $include = []): array
{
    $out = selectResourceFields($item, $query, $type);
    if ($include !== []) {
        $composed = $store->composeSession($item, $include);
        foreach ($include as $part) {
            if ($part === 'project') {
                $out[$part] = $composed[$part] === null ? null : apiItem($store, $auth, 'projects', $composed[$part]);
            } else {
                $out[$part] = array_map(fn($child) => apiItem($store, $auth, $part, $child), $composed[$part]);
                $out[$part . 'Page'] = $composed[$part . 'Page'];
            }
        }
    }
    $out['_links'] = resourceLinks($type, $item, $auth);
    return $out;
}

function pageControls(array $links): array
{
    $controls = [];
    foreach ($links as $rel => $href) { if ($href !== null) { $controls[$rel] = apiLink($href); } }
    return $controls;
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

function selectResourceFields(array $item, array $query, string $type): array
{
    $view = $query['view'] ?? 'full';
    if (!in_array($view, ['full', 'summary'], true)) { throw new ApiError(400, 'view must be full or summary'); }
    if ($view === 'summary') {
        $keys = match ($type) {
            'projects' => ['id', 'name', 'language'],
            'messages' => ['id', 'role', 'model', 'createdAt'],
            'sessions' => ['id', 'projectId', 'title', 'status', 'createdAt'],
            'tasks' => ['id', 'sessionId', 'title', 'status', 'version', 'createdAt'],
        };
        return array_intersect_key($item, array_flip($keys));
    }
    return $item;
}

function requestedSessionIncludes(array $query, string $type): array
{
    if (!isset($query['include']) || $query['include'] === '') { return []; }
    if ($type !== 'sessions') { throw new ApiError(400, 'include is supported only for sessions'); }
    $parts = explode(',', $query['include']);
    if (count($parts) !== count(array_unique($parts)) || array_diff($parts, ['project', 'messages', 'tasks'])) {
        throw new ApiError(400, 'include supports project,messages,tasks');
    }
    return $parts;
}
