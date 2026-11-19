<?php

declare(strict_types=1);

/** Approved file operations use session-scoped database rows, never host paths. */
final class WorkspaceToolRuns
{
    private const RUN_FIELDS = 'id, session_id AS "sessionId", operation, path, content, status, version, requested_by AS "requestedBy", approved_by AS "approvedBy", executed_by AS "executedBy", created_at AS "createdAt", approved_at AS "approvedAt", executed_at AS "executedAt", result';

    public static function handleRequest(PDO $db, Auth $auth, string $method, string $path): void
    {
        $actor = $auth->user();
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
            $row = inDatabaseTransaction($db, function () use ($db,$auth,$actor,$sessionId,$runId,$action,$body) {
                $auth->requireAccess('sessions',$sessionId,'write');
                if ($runId === null) {
                    [$operation,$fileName,$content] = self::parseToolRequest($body);
                    $key = 'run_' . bin2hex(random_bytes(12));
                    executeSql($db,'INSERT INTO tool_runs(id,session_id,operation,path,content,requested_by) VALUES (?,?,?,?,?,?)',[$key,$sessionId,$operation,$fileName,$content,$actor['id']]);
                    $auth->audit('tool.request',$key);
                    return self::findRun($db,$sessionId,$key,false);
                }
                if ($body !== []) { throw new ApiError(422,'Action accepts only an empty object','validation_error'); }
                $expected = requiredResourceVersion();
                $run = self::findRun($db,$sessionId,$runId,true);
                if ((int) $run['version'] !== $expected) { rejectStaleVersion(); }
                if ($action === 'approve' && $run['status'] === 'requested') {
                    executeSql($db,"UPDATE tool_runs SET status='approved',version=version+1,approved_by=?,approved_at=clock_timestamp() WHERE id=?",[$actor['id'],$runId]);
                } elseif ($action === 'execute' && $run['status'] === 'approved') {
                    if ($run['operation'] === 'workspace.read') {
                        $result = executeSql($db,'SELECT content FROM workspace_files WHERE session_id=? AND path=?',[$sessionId,$run['path']])->fetchColumn();
                        if ($result === false) { throw new ApiError(409,'Workspace file does not exist','workspace_file_missing'); }
                    } else {
                        executeSql($db,'INSERT INTO workspace_files(session_id,path,content) VALUES (?,?,?) ON CONFLICT (session_id,path) DO UPDATE SET content=EXCLUDED.content',[$sessionId,$run['path'],$run['content']]);
                        $result = 'Wrote ' . strlen($run['content']) . ' bytes';
                    }
                    executeSql($db,"UPDATE tool_runs SET status='completed',version=version+1,executed_by=?,executed_at=clock_timestamp(),result=? WHERE id=?",[$actor['id'],$result,$runId]);
                } else { throw new ApiError(409,'Action not permitted in the current run state','invalid_run_state'); }
                $auth->audit('tool.' . $action,$runId);
                return self::findRun($db,$sessionId,$runId,false);
            });
        }
        $run = self::shapeRun($row,$base,$auth->can('sessions',$sessionId,'write'));
        header('ETag: "' . $run['version'] . '"');
        if ($runId === null) { header('Location: ' . $run['_links']['self']['href']); }
        respond($run, $runId === null ? 201 : 200);
    }

    public static function parseToolRequest(array $body): array
    {
        $op = $body['operation'] ?? null; $path = $body['path'] ?? null;
        if (!in_array($op, ['workspace.read','workspace.write'],true) || !is_string($path) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/D',$path)) {
            throw new ApiError(422,'Choose workspace.read or workspace.write and one ASCII basename (1–64 characters)','validation_error');
        }
        $write = $op === 'workspace.write';
        if (array_diff(array_keys($body), $write ? ['operation','path','content'] : ['operation','path']) || ($write && (!is_string($body['content'] ?? null) || strlen($body['content']) > 8192 || !isRepresentableText($body['content'])))) {
            throw new ApiError(422,'Invalid tool arguments; writes require UTF-8 text of at most 8192 bytes','validation_error');
        }
        return [$op,$path,$write ? $body['content'] : null];
    }

    private static function findRun(PDO $db, string $sessionId, string $runId, bool $lock): array
    {
        $row = executeSql($db, 'SELECT ' . self::RUN_FIELDS . ' FROM tool_runs WHERE session_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$sessionId,$runId])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) { throw new ApiError(404,'Tool run not found','not_found'); }
        return $row;
    }

    private static function shapeRun(array $row, string $base, bool $write): array
    {
        $href = $base . '/' . $row['id'];
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
