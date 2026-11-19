<?php

declare(strict_types=1);

final class Auth
{
    private const COOKIE = 'osiris_session';
    private const LIFETIME = 8 * 3600;
    // Same bcrypt cost as real accounts; an unknown name still incurs a verification.
    private const DUMMY_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    private array $actor = [];
    private array $levels = [];
    private string $token = '';

    public function __construct(private readonly PDO $db, private readonly string $requestId) {}

    private function statement(string $sql, array $args = []): PDOStatement
    {
        $s = $this->db->prepare($sql);
        $s->execute($args);
        return $s;
    }

    public static function cookieToken(): string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new ApiError(401, 'Authentication required', 'unauthorized');
        }
        return $token;
    }

    private function cookie(string $token, int $expires): void
    {
        setcookie(self::COOKIE, $token, ['expires' => $expires, 'path' => '/', 'httponly' => true,
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Strict']);
    }

    private function activate(array $actor, string $token): void
    {
        $this->actor = ['id' => $actor['id'], 'username' => $actor['username'], 'role' => $actor['role']];
        $this->token = $token;
        $this->statement("SELECT set_config('osiris.actor', ?, false), set_config('osiris.request', ?, false)", [$actor['id'], $this->requestId]);
    }

    public function authenticate(string $token): void
    {
        $actor = $this->statement('SELECT u.id,u.username,u.role FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE token_hash=? AND expires_at>now() AND u.password_hash IS NOT NULL', [hash('sha256', $token)])->fetch(PDO::FETCH_ASSOC);
        if (!$actor) { throw new ApiError(401, 'Authentication required', 'unauthorized'); }
        $this->activate($actor, $token);
    }

    public static function sameOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site' || ($origin !== null && strcasecmp($origin, $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')) !== 0)) {
            throw new ApiError(403, 'Cross-origin mutation rejected', 'csrf_failed');
        }
    }

    /** One atomic fixed window per bucket. Never trust forwarded IP headers. */
    private function limit(string $key, int $seconds, int $maximum): void
    {
        $sql = "INSERT INTO auth_rate_limits AS r(bucket,expires_at,hits) VALUES (?,now()+interval '1 second' * ?::integer,1)
            ON CONFLICT(bucket) DO UPDATE SET hits=CASE WHEN r.expires_at<=now() THEN 1 ELSE LEAST(r.hits+1,?::integer) END,
            expires_at=CASE WHEN r.expires_at<=now() THEN EXCLUDED.expires_at ELSE r.expires_at END
            RETURNING hits, GREATEST(1,ceil(extract(epoch FROM expires_at-now()))) AS retry";
        $row = $this->statement($sql, [hash('sha256', $key), $seconds, $maximum + 1])->fetch(PDO::FETCH_ASSOC);
        if ((int) $row['hits'] > $maximum) {
            header('Retry-After: ' . (int) $row['retry']);
            throw new ApiError(429, 'Too many requests; try again later', 'rate_limited');
        }
    }

    /** Serializes login with operator credential rotation without granting UPDATE on users to the API. */
    public static function lockAccount(PDO $db, string $username): void
    {
        $db->prepare("SELECT pg_advisory_xact_lock(hashtext('account'),hashtext(?))")->execute([$username]);
    }

    public function login(array $body): array
    {
        self::sameOrigin();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'local';
        $this->limit('login-ip:' . $ip, 300, 60);
        $name = $body['username'] ?? null;
        $password = $body['password'] ?? null;
        if (array_diff(array_keys($body), ['username','password']) || !is_string($name) || $name === '' || strlen($name) > 100 || str_contains($name,"\0") || !is_string($password) || $password === '' || strlen($password) > 72 || str_contains($password,"\0")) {
            throw new ApiError(422, 'Expected username and password', 'validation_error');
        }
        $this->limit('login-account:' . $ip . ':' . $name, 300, 10);
        // Cleanup follows the cheap IP limiter. The same task is available via the maintenance CLI.
        self::prune($this->db);
        $this->db->beginTransaction();
        try {
            // A concurrent password reset must revoke this login too.
            self::lockAccount($this->db, $name);
            $user = $this->statement('SELECT id,username,role,password_hash FROM users WHERE username=?', [$name])->fetch(PDO::FETCH_ASSOC);
            $matches = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
            if (!$matches || !isset($user['password_hash'])) {
                $this->audit('login.denied');
                $this->db->commit();
                throw new ApiError(401, 'Invalid credentials', 'unauthorized');
            }
            $token = bin2hex(random_bytes(32));
            $prior = $_COOKIE[self::COOKIE] ?? '';
            if (is_string($prior) && preg_match('/\A[a-f0-9]{64}\z/D', $prior)) {
                $this->statement('DELETE FROM auth_sessions WHERE token_hash=?', [hash('sha256', $prior)]);
            }
            $this->statement("INSERT INTO auth_sessions(token_hash,user_id,expires_at) VALUES (?,?,now()+interval '8 hours')", [hash('sha256', $token), $user['id']]);
            $this->activate($user, $token);
            $this->audit('login.success');
            $this->db->commit();
            $this->cookie($token, time() + self::LIFETIME);
            return $this->identity();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    public static function prune(PDO $db): void
    {
        $db->exec('DELETE FROM auth_sessions WHERE expires_at <= now()');
        $db->exec('DELETE FROM auth_rate_limits WHERE expires_at <= now()');
        $db->exec("DELETE FROM auth_audit WHERE created_at < now()-interval '30 days'");
    }

    public function user(): array { return $this->actor; }
    private function csrf(): string { return hash_hmac('sha256', 'osiris.csrf.v1', $this->token); }
    public function identity(): array { return ['user' => $this->actor, 'csrfToken' => $this->csrf()]; }

    public function guardMutation(): void
    {
        self::sameOrigin();
        if (!hash_equals($this->csrf(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { throw new ApiError(403, 'Invalid CSRF token', 'csrf_failed'); }
        $this->limit('mutation:' . $this->actor['id'], 60, 120);
    }

    public function logout(): void
    {
        $this->db->beginTransaction();
        try {
            $this->statement('DELETE FROM auth_sessions WHERE token_hash=?', [hash('sha256', $this->token)]);
            $this->audit('logout');
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        $this->cookie('', 1);
    }

    public function audit(string $event, ?string $id = null): void
    {
        $this->statement('INSERT INTO auth_audit(request_id,actor_id,event,target_id) VALUES (?,?,?,?)', [$this->requestId, $this->actor['id'] ?? null, $event, $id]);
    }

    public function canCreateProject(): bool { return isset($this->actor['role']) && $this->actor['role'] !== 'viewer'; }

    /** A scoped relation, used for detail, lists, filtering, counts and access checks alike. */
    public function relation(string $type): array
    {
        [$source, $p] = match ($type) {
            'projects' => ['projects r', 'r'],
            'sessions' => ['sessions r JOIN projects p ON p.id=r.project_id', 'p'],
            'messages', 'tasks' => ["$type r JOIN sessions s ON s.id=r.session_id JOIN projects p ON p.id=s.project_id", 'p'],
        };
        $sql = "(SELECT r.*, CASE WHEN a.role <> 'viewer' AND $p.user_id=a.id AND a.role IN ('owner','admin') THEN 3
            WHEN a.role <> 'viewer' AND ($p.user_id=a.id OR m.role='member') THEN 2 ELSE 1 END AS access_level
            FROM $source CROSS JOIN (SELECT ?::text AS id, ?::text AS role) a
            LEFT JOIN project_members m ON m.project_id=$p.id AND m.user_id=a.id
            WHERE $p.visibility='public' OR (a.role<>'viewer' AND ($p.user_id=a.id OR m.user_id IS NOT NULL))) scoped";
        return [$sql, [$this->actor['id'], $this->actor['role']]];
    }

    public function remember(string $type, string $id, int $level): void { $this->levels[$type][$id] = $level; }

    private function level(string $type, string $id): int
    {
        [$from,$args] = $this->relation($type);
        return (int) $this->statement("SELECT access_level FROM $from WHERE id=?", [...$args,$id])->fetchColumn();
    }

    private static function minimum(string $permission): int { return match ($permission) { 'read' => 1, 'write' => 2, 'manage' => 3 }; }

    public function can(string $type, string $id, string $permission): bool
    {
        $level = $this->levels[$type][$id] ??= $this->level($type, $id);
        return $level >= self::minimum($permission);
    }

    /** For a transaction-bound mutation, serialize ACL changes with the protected write. */
    public function requireAccess(string $type, string $id, string $permission): void
    {
        if ($this->db->inTransaction() && $permission !== 'read') {
            $source = match ($type) {
                'projects' => 'projects p WHERE p.id=?',
                'sessions' => 'projects p JOIN sessions s ON s.project_id=p.id WHERE s.id=?',
                'messages','tasks' => "projects p JOIN sessions s ON s.project_id=p.id JOIN $type r ON r.session_id=s.id WHERE r.id=?",
            };
            $this->statement("SELECT p.id FROM $source FOR UPDATE OF p", [$id]);
        }
        $level = $this->levels[$type][$id] = $this->level($type, $id);
        if ($level === 0) { throw new ApiError(404, 'Resource not found', 'not_found'); }
        if ($level < self::minimum($permission)) { throw new ApiError(403, 'Insufficient project permissions', 'forbidden'); }
    }

    public function membership(string $project, ?string $userId, string $method): never
    {
        $allowed = $userId === null ? ['GET','POST'] : ['DELETE'];
        if (!in_array($method,$allowed,true)) { header('Allow: ' . implode(', ', $allowed)); throw new ApiError(405, 'Method not allowed'); }
        if (queryParams() !== []) { throw new ApiError(400, 'Membership routes take no query parameters'); }
        foreach ([$project,$userId] as $id) { if ($id !== null && !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D',$id)) { throw new ApiError(404, 'Resource not found', 'not_found'); } }
        $base = '/projects/' . rawurlencode($project) . '/members';
        $member = static fn(array $row): array => [...$row, '_links'=>['delete'=>['href'=>$base . '/' . rawurlencode($row['userId']),'method'=>'DELETE']]];
        if ($method === 'GET') {
            $this->requireAccess('projects',$project,'manage');
            $rows = $this->statement('SELECT user_id AS "userId",role FROM project_members WHERE project_id=? ORDER BY user_id', [$project]);
            respond(['items'=>array_map($member,$rows->fetchAll(PDO::FETCH_ASSOC)), '_links'=>['setMember'=>['href'=>$base,'method'=>'POST']]]);
        }
        $this->db->beginTransaction();
        try {
            $this->requireAccess('projects',$project,'manage');
            if ($method === 'POST') {
                $body = readRequestBody();
                if (array_diff(array_keys($body), ['userId','role']) || !is_string($body['userId'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $body['userId']) !== 1 || !in_array($body['role'] ?? null, ['viewer','member'],true)) { throw new ApiError(422,'Expected userId and membership role','validation_error'); }
                $userId = $body['userId'];
                if ($this->actor['id'] === $userId) { throw new ApiError(422,'The project owner cannot be a delegated member','validation_error'); }
                if (!$this->statement('SELECT 1 FROM users WHERE id=?',[$userId])->fetchColumn()) { throw new ApiError(404,'User not found','not_found'); }
                $this->statement('INSERT INTO project_members(project_id,user_id,role) VALUES (?,?,?) ON CONFLICT(project_id,user_id) DO UPDATE SET role=EXCLUDED.role', [$project,$userId,$body['role']]);
                $this->db->commit();
                respond($member(['userId'=>$userId,'role'=>$body['role']]));
            }
            if (!$this->statement('DELETE FROM project_members WHERE project_id=? AND user_id=?', [$project,$userId])->rowCount()) { throw new ApiError(404,'Membership not found','not_found'); }
            $this->db->commit();
            http_response_code(204);
            exit;
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
    }
}
