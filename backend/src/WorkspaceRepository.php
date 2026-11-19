<?php

declare(strict_types=1);

final class WorkspaceRepository
{
    private const FIELDS = [
        'projects' => ['id', 'user_id', 'name', 'language', 'repository_url', 'visibility', 'created_at', 'updated_at'],
        'sessions' => ['id', 'project_id', 'title', 'status', 'created_at', 'updated_at'],
        'messages' => ['id', 'session_id', 'role', 'content', 'agent_events', 'created_at'],
        'tasks' => ['id', 'session_id', 'title', 'status', 'result', 'version', 'created_at', 'updated_at'],
    ];
    private const WRITABLE = [
        'projects' => ['userId' => 'user_id', 'name' => 'name', 'language' => 'language', 'repositoryUrl' => 'repository_url', 'visibility' => 'visibility'],
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

    public function __construct(private readonly PDO $db, private readonly Auth $auth) {}

    public function exists(string $type, string $id): bool
    {
        return $this->get($type, $id) !== null;
    }

    public function get(string $type, string $id): ?array
    {
        [$from,$args] = $this->auth->relation($type);
        $sql = 'SELECT access_level, ' . implode(', ', self::FIELDS[$type]) . " FROM $from WHERE id = ?";
        $statement = $this->db->prepare($sql);
        $statement->execute([...$args,$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->scopedShape($type,$row);
    }

    /** The unpaginated session endpoint returns one ordered snapshot, not stitched pages. */
    public function sessionsInCreationOrder(): Generator
    {
        [$relation, $params] = $this->auth->relation('sessions');
        $fields = implode(', ', self::FIELDS['sessions']);
        $rows = executeSql($this->db, "SELECT access_level, $fields FROM $relation ORDER BY created_at, id", $params);
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            yield $this->scopedShape('sessions', $row);
        }
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
        [$from,$args] = $this->auth->relation($type);
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
            if (trim($query['q']) === '' || utf8Length($query['q']) > 200) { throw new ApiError(400, 'q must be 1–200 characters'); }
            $search = match ($type) { 'projects' => 'name', 'messages' => 'content', default => 'title' };
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
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM $from$condition");
        $totalStmt->execute($args);
        $total = (int) $totalStmt->fetchColumn();
        $column = self::SORTS[$type][$sort];
        $sql = 'SELECT access_level, ' . implode(', ', self::FIELDS[$type]) . " FROM $from$condition ORDER BY $column $order, id $order LIMIT ? OFFSET ?";
        $statement = $this->db->prepare($sql);
        $pos = 1;
        foreach ($args as $value) { $statement->bindValue($pos++, $value); }
        $statement->bindValue($pos++, $limit, PDO::PARAM_INT);
        $statement->bindValue($pos, $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map(fn(array $row) => $this->scopedShape($type,$row), $statement->fetchAll(PDO::FETCH_ASSOC));
        return ['items' => $items, 'page' => [
            'total' => $total, 'limit' => $limit, 'offset' => $offset,
            'hasMore' => $offset + count($items) < $total,
        ]];
    }

    public function create(string $type, array $payload, array $fixed = []): array
    {
        if ($type === 'projects') {
            if (!$this->auth->canCreateWorkspaceResources()) { throw new ApiError(403, 'Cannot create projects', 'forbidden'); }
            if (($payload['visibility'] ?? 'private') === 'public' && !in_array($this->auth->user()['role'], ['owner','admin'], true)) {
                throw new ApiError(403, 'Cannot publish projects', 'forbidden');
            }
            $fixed['userId'] = $this->auth->user()['id'];
        }
        $fields = $this->validatePayload($type, $payload, false, $fixed);
        if ($type === 'sessions') {
            if (isset($fields['project_id'])) {
                $this->auth->requireAccess('projects', $fields['project_id'], 'write');
            } else {
                if (!$this->auth->canCreateWorkspaceResources()) { throw new ApiError(403, 'Cannot create private sessions', 'forbidden'); }
                $fields['owner_id'] = $this->auth->user()['id'];
            }
        }
        if ($type === 'messages' || $type === 'tasks') { $this->auth->requireAccess('sessions',$fields['session_id'],'write'); }
        if ($type === 'tasks' && (($fields['status'] ?? 'pending') !== 'pending' || isset($fields['result']))) {
            throw new ApiError(422, 'New tasks must be pending with no result', 'validation_error');
        }
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
        $this->auth->requireAccess($type,$id,$type === 'projects' && array_key_exists('visibility',$payload) ? 'manage' : 'write');
        if (($type === 'projects' && array_key_exists('userId',$payload)) || ($type === 'sessions' && array_key_exists('projectId',$payload))) {
            throw new ApiError(422, 'Resource ownership and parent cannot be changed', 'validation_error');
        }
        if ($type === 'messages') { throw new ApiError(405, 'Messages are immutable'); }
        if ($type === 'tasks' && array_key_exists('sessionId', $payload)) { throw new ApiError(422, 'Task sessionId cannot be changed'); }
        $fields = $this->validatePayload($type, $payload, true);
        if ($fields === []) { throw new ApiError(422, 'PATCH requires at least one editable field'); }
        if ($type === 'tasks') {
            $expected = requiredResourceVersion();
            $task = $this->get('tasks', $id);
            if ($task === null) { return null; }
            if ($task['version'] !== $expected) { rejectStaleVersion(); }
            $fields = self::validateTaskTransition($task['status'], $fields);
            $sets = array_map(static fn(string $column) => "$column = ?", array_keys($fields));
            $stmt = $this->db->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ', version = version + 1, updated_at = clock_timestamp() WHERE id = ? AND version = ? RETURNING ' . implode(', ', self::FIELDS['tasks']));
            $stmt->execute([...array_values($fields), $id, $expected]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) { rejectStaleVersion(); }
            return $this->shape($row);
        }
        $sets = array_map(static fn(string $column) => "$column = ?", array_keys($fields));
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $statement = $this->db->prepare('UPDATE ' . $type . ' SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $statement->execute([...array_values($fields), $id]);
        return $this->get($type, $id);
    }

    public function delete(string $type, string $id): bool
    {
        $this->auth->requireAccess($type,$id,'manage');
        if ($type === 'tasks') {
            $stmt = $this->db->prepare('DELETE FROM tasks WHERE id = ? AND version = ?');
            $stmt->execute([$id, requiredResourceVersion()]);
            if ($stmt->rowCount() === 0) { rejectStaleVersion(); }
            return true;
        }
        $statement = $this->db->prepare("DELETE FROM $type WHERE id = ?");
        $statement->execute([$id]);
        return $statement->rowCount() > 0;
    }

    public function deleteSessionWithContents(string $id): bool
    {
        $this->auth->requireAccess('sessions',$id,'manage');
        foreach (['messages', 'tasks'] as $type) {
            $statement = $this->db->prepare("DELETE FROM $type WHERE session_id = ?");
            $statement->execute([$id]);
        }
        $statement = $this->db->prepare('DELETE FROM sessions WHERE id=?');
        $statement->execute([$id]);
        return $statement->rowCount() > 0;
    }

    /** Fetch only the recent conversational roles, bounded before reaching the provider. */
    public function recentConversation(string $sessionId): array
    {
        [$from,$args] = $this->auth->relation('messages');
        $stmt = $this->db->prepare("SELECT role, content FROM $from WHERE session_id = ? AND role IN ('user','assistant') ORDER BY created_at DESC, id DESC LIMIT 12");
        $stmt->execute([...$args,$sessionId]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Called in the same transaction as successful settlement of the dispatch reservation. */
    public function recordExchange(string $sessionId, string $prompt, string $answer, array $events = []): array
    {
        $stmt = $this->db->prepare('INSERT INTO messages (id, session_id, role, content, agent_events, created_at) VALUES (?, ?, ?, ?, ?, clock_timestamp())');
        $userId = $this->newId('messages');
        $assistantId = $this->newId('messages');
        $stmt->execute([$userId, $sessionId, 'user', $prompt, null]);
        $stmt->execute([$assistantId, $sessionId, 'assistant', $answer, $events === [] ? null : json_encode($events, JSON_THROW_ON_ERROR)]);
        return ['userMessage' => $this->get('messages', $userId), 'assistantMessage' => $this->get('messages', $assistantId)];
    }

    public function projectForSession(array $session): ?array
    {
        return $session['projectId'] === null ? null : $this->get('projects', $session['projectId']);
    }

    public function composeSession(array $session, array $include): array
    {
        foreach ($include as $part) {
            if ($part === 'project') { $session['project'] = $this->projectForSession($session); }
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
        if (array_is_list($payload) && $payload !== []) { throw new ApiError(422, 'Request body must be an object'); }
        $allowed = self::WRITABLE[$type];
        foreach ($payload as $key => $_) {
            if (!isset($allowed[$key]) && !(!$patch && $key === 'id')) { throw new ApiError(422, "Unknown or read-only field: $key"); }
        }
        foreach ($fixed as $key => $value) {
            if (array_key_exists($key,$payload) && $payload[$key] !== $value) { throw new ApiError(422, "Conflicting $key", 'validation_error'); }
            $payload[$key] = $value;
        }
        $required = [
            'projects' => ['userId', 'name'], 'sessions' => ['title'],
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
            if ($value === null && ($nullable || $key === 'projectId')) { $out[$column] = null; continue; }
            if (!is_string($value) || (!$nullable && trim($value) === '') || utf8Length($value) > 10000) {
                throw new ApiError(422, "$key must be a nonempty string (max 10000 characters), or null if optional");
            }
            if (!isRepresentableText($value)) {
                throw new ApiError(422, "$key contains unsupported control characters");
            }
            if ($key === 'status') {
                $values = $type === 'tasks' ? ['pending', 'running', 'completed', 'failed'] : ['active', 'completed', 'archived'];
                if (!in_array($value, $values, true)) { throw new ApiError(422, 'Invalid status'); }
            }
            if ($key === 'role' && !in_array($value, ['system', 'user', 'assistant', 'tool'], true)) { throw new ApiError(422, 'Invalid message role'); }
            if ($key === 'visibility' && !in_array($value, ['private','public'], true)) { throw new ApiError(422, 'Invalid visibility', 'validation_error'); }
            if ($key === 'repositoryUrl' && $value !== '' && !preg_match('~\Ahttps?://~i', $value)) { throw new ApiError(422, 'repositoryUrl must be HTTP(S)'); }
            $out[$column] = $value;
        }
        return $out;
    }

    public static function validateTaskTransition(string $current, array $fields): array
    {
        $next = $fields['status'] ?? $current;
        $allowed = match ($current) {
            'pending' => ['pending','running'],
            'running' => ['running','completed','failed'],
            'failed' => ['failed','pending'],
            default => [],
        };
        if (!in_array($next, $allowed, true)) { throw new ApiError(409, 'Invalid task state transition', 'invalid_task_state'); }
        if (isset($fields['result']) && !in_array($next, ['failed','completed'], true)) {
            throw new ApiError(422, 'Only a failed or completed task may have a result', 'validation_error');
        }
        if ($next === 'pending' && $current === 'failed') { $fields['result'] = null; }
        return $fields;
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

    private function scopedShape(string $type, array $row): array
    {
        $this->auth->remember($type,$row['id'],(int) $row['access_level']);
        unset($row['access_level']);
        return $this->shape($row);
    }

    private function shape(array $row): array
    {
        $data = [];
        foreach ($row as $key => $value) {
            if ($key === 'agent_events') {
                if ($value !== null) { $data['agentEvents'] = json_decode($value, true, flags: JSON_THROW_ON_ERROR); }
                continue;
            }
            $jsonKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            if ($key === 'version') { $value = (int) $value; }
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
