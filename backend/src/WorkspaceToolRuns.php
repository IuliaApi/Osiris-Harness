<?php

declare(strict_types=1);

/** Approved file operations use session-scoped database rows, never host paths. */
final class WorkspaceToolRuns
{
    public const BASENAME_PATTERN = '[A-Za-z0-9][A-Za-z0-9_.-]{0,63}';
    public const MAX_FILE_BYTES = 8192;
    private const RUN_FIELDS = 'id, session_id AS "sessionId", operation, path, content, status, version, requested_by AS "requestedBy", approved_by AS "approvedBy", executed_by AS "executedBy", created_at AS "createdAt", approved_at AS "approvedAt", executed_at AS "executedAt", result';
    private const LOCKED_FIELDS = self::RUN_FIELDS . ', base_digest AS "baseDigest"';

    public static function handleRequest(PDO $db, Auth $auth, string $method, string $path): void
    {
        if (!preg_match('~\A/sessions/([^/]+)/tool-runs(?:/(run_[0-9a-f]{24})(?:/(approve|execute))?)?\z~D',$path,$m)) { return; }
        $sessionId = rawurldecode($m[1]); $runId = $m[2] ?? null; $action = $m[3] ?? null;
        if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D',$sessionId)) { throw new ApiError(404,'Session not found','not_found'); }
        requireHttpMethod($method, $runId === null ? ['GET','POST'] : ($action === null ? ['GET'] : ['POST']));
        $auth->requireAccess('sessions',$sessionId,$method === 'GET' ? 'read' : 'write');
        $query = queryParams();
        $base = '/sessions/' . rawurlencode($sessionId) . '/tool-runs';
        if ($method === 'GET' && $runId === null) { respond(self::listRuns($db,$auth,$sessionId,$base,$query)); }
        if ($query) { throw new ApiError(400,'This operation accepts no query parameters'); }
        if ($method === 'GET') {
            $row = self::findRun($db,$sessionId,$runId,false);
        } else {
            $body = readRequestBody();
            $row = inDatabaseTransaction($db, function () use ($db,$auth,$sessionId,$runId,$action,$body) {
                $auth->requireAccess('sessions',$sessionId,'write');
                if ($runId === null) {
                    return self::request($db, $auth, $sessionId, $body);
                }
                if ($body !== []) { throw new ApiError(422,'Action accepts only an empty object','validation_error'); }
                $expected = requiredResourceVersion();
                $run = self::findRun($db,$sessionId,$runId,true);
                if ((int) $run['version'] !== $expected) { rejectStaleVersion(); }
                return self::transition($db, $auth, $run, $action);
            });
        }
        $run = self::shapeRun($row,$base,$auth->can('sessions',$sessionId,'write'));
        header('ETag: "' . $run['version'] . '"');
        if ($runId === null) { header('Location: ' . $run['_links']['self']['href']); }
        respond($run, $runId === null ? 201 : 200);
    }

    private static function request(PDO $db, Auth $auth, string $sessionId, array $body, ?string $baseDigest = null): array
    {
        [$operation, $path, $content] = self::parseToolRequest($body);
        $id = 'run_' . bin2hex(random_bytes(12));
        $row = executeSql($db, 'INSERT INTO tool_runs(id,session_id,operation,path,content,requested_by,base_digest) VALUES (?,?,?,?,?,?,?) RETURNING ' . self::LOCKED_FIELDS,
            [$id, $sessionId, $operation, $path, $content, $auth->user()['id'], $baseDigest])->fetch(PDO::FETCH_ASSOC);
        $auth->audit('tool.request', $id);
        return $row;
    }

    /** Called only under the agent's thread/ACL lock, after inference. The base comes from the file used to construct this write. */
    public static function requestAgentWrite(PDO $db, Auth $auth, string $sessionId, string $path, string $content, string $baseDigest): array
    {
        $run = self::request($db, $auth, $sessionId, ['operation'=>'workspace.write', 'path'=>$path, 'content'=>$content], $baseDigest);
        $mode = ChatPermissions::mode($db, $sessionId, $auth->user()['id']);
        if ($mode === 'yolo' || ($mode === 'allow' && $baseDigest === 'missing')) {
            $run = self::transition($db, $auth, $run, 'approve', $mode);
            $run = self::transition($db, $auth, $run, 'execute', $mode);
        }
        return $run;
    }

    /** The caller owns the row lock (or just inserted the row). Both paths advance one version per action. */
    private static function transition(PDO $db, Auth $auth, array $run, string $action, ?string $policy = null): array
    {
        $id = $run['id'];
        $actor = $auth->user()['id'];
        if ($action === 'approve' && $run['status'] === 'requested') {
            $set = "status='approved',approved_by=?,approved_at=clock_timestamp()";
            $args = [$actor, $id];
        } elseif ($action === 'execute' && $run['status'] === 'approved') {
            if ($run['operation'] === 'workspace.read') {
                $result = executeSql($db, 'SELECT content FROM workspace_files WHERE session_id=? AND path=?', [$run['sessionId'], $run['path']])->fetchColumn();
                if ($result === false) { throw new ApiError(409,'Workspace file does not exist','workspace_file_missing'); }
            } else {
                self::applyWrite($db, $run['sessionId'], $run);
                $result = 'Wrote ' . strlen($run['content']) . ' bytes';
            }
            $set = "status='completed',executed_by=?,executed_at=clock_timestamp(),result=?";
            $args = [$actor, $result, $id];
        } else {
            throw new ApiError(409,'Action not permitted in the current run state','invalid_run_state');
        }
        $row = executeSql($db, "UPDATE tool_runs SET $set,version=version+1 WHERE id=? RETURNING " . self::LOCKED_FIELDS, $args)->fetch(PDO::FETCH_ASSOC);
        $auth->audit('tool.' . $action . ($policy === null ? '' : '.policy.' . $policy), $id);
        return $row;
    }

    private static function applyWrite(PDO $db, string $sessionId, array $run): void
    {
        $path = $run['path'];
        $content = $run['content'];
        $insert = 'INSERT INTO workspace_files(session_id,path,content) VALUES (?,?,?) ON CONFLICT (session_id,path) ';
        if ($run['baseDigest'] === null) {
            // Manual proposals retain their original overwrite semantics.
            executeSql($db, $insert . 'DO UPDATE SET content=EXCLUDED.content', [$sessionId, $path, $content]);
            return;
        }
        // Check and apply in one statement, including the concurrent-create case.
        $applied = $run['baseDigest'] === 'missing'
            ? executeSql($db, $insert . 'DO NOTHING', [$sessionId, $path, $content])
            : executeSql($db, "UPDATE workspace_files SET content=? WHERE session_id=? AND path=? AND encode(sha256(convert_to(content,'UTF8')),'hex')=?",
                [$content, $sessionId, $path, $run['baseDigest']]);
        if ($applied->rowCount() === 0) {
            throw new ApiError(409, 'Workspace file has changed; request a new proposal', 'workspace_base_changed');
        }
    }

    public static function parseToolRequest(array $body): array
    {
        $op = $body['operation'] ?? null; $path = $body['path'] ?? null;
        if (!in_array($op, ['workspace.read','workspace.write'],true) || !is_string($path) || !preg_match('/\A' . self::BASENAME_PATTERN . '\z/D',$path)) {
            throw new ApiError(422,'Choose workspace.read or workspace.write and one ASCII basename (1–64 characters)','validation_error');
        }
        $write = $op === 'workspace.write';
        if (array_diff(array_keys($body), $write ? ['operation','path','content'] : ['operation','path']) || ($write && (!is_string($body['content'] ?? null) || strlen($body['content']) > self::MAX_FILE_BYTES || !isRepresentableText($body['content'])))) {
            throw new ApiError(422,'Invalid tool arguments; writes require UTF-8 text of at most 8192 bytes','validation_error');
        }
        return [$op,$path,$write ? $body['content'] : null];
    }

    private static function findRun(PDO $db, string $sessionId, string $runId, bool $lock): array
    {
        $fields = $lock ? self::LOCKED_FIELDS : self::RUN_FIELDS;
        $row = executeSql($db, 'SELECT ' . $fields . ' FROM tool_runs WHERE session_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$sessionId,$runId])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) { throw new ApiError(404,'Tool run not found','not_found'); }
        return $row;
    }

    private static function shapeRun(array $row, string $base, bool $write): array
    {
        $href = $base . '/' . $row['id'];
        unset($row['baseDigest']);
        $row['version'] = (int) $row['version'];
        foreach (['createdAt','approvedAt','executedAt'] as $time) {
            if ($row[$time] !== null) { $row[$time] = (new DateTimeImmutable($row[$time]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'); }
        }
        $row['_links'] = ['self' => apiLink($href)];
        if ($write && $row['status'] === 'requested') { $row['_links']['approve'] = apiLink($href . '/approve','POST'); }
        if ($write && $row['status'] === 'approved') { $row['_links']['execute'] = apiLink($href . '/execute','POST'); }
        return $row;
    }

    private static function listRuns(PDO $db, Auth $auth, string $sessionId, string $base, array $query): array
    {
        $limit = $query['limit'] ?? '25'; $offset = $query['offset'] ?? '0';
        if (array_diff(array_keys($query),['offset','limit']) || !preg_match('/\A[1-9][0-9]{0,2}\z/D',$limit) || (int) $limit > 100 || !preg_match('/\A(0|[1-9][0-9]{0,6})\z/D',$offset) || (int) $offset > 1000000) { throw new ApiError(400,'Invalid tool run pagination'); }
        $limit = (int) $limit; $offset = (int) $offset;
        $write = $auth->can('sessions',$sessionId,'write');
        $total = (int) executeSql($db,'SELECT count(*) FROM tool_runs WHERE session_id=?',[$sessionId])->fetchColumn();
        $rows = executeSql($db,'SELECT ' . self::RUN_FIELDS . ' FROM tool_runs WHERE session_id=? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',[$sessionId,$limit,$offset])->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map(static fn($row) => self::shapeRun($row,$base,$write), $rows);
        $page = ['limit' => $limit, 'offset' => $offset, 'total' => $total, 'hasMore' => $offset + count($items) < $total];
        $links = paginationLinks($query,$page);
        $actions = pageControls($links);
        if ($write) { $actions['create'] = apiLink($base,'POST'); }
        return ['items' => $items,'page' => $page,'links' => $links,'_links' => $actions];
    }
}
