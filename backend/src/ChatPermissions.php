<?php

declare(strict_types=1);

final class ChatPermissions
{
    public static function mode(PDO $db, string $sessionId, string $userId): string
    {
        $mode = executeSql($db, 'SELECT mode FROM chat_permissions WHERE session_id=? AND user_id=?', [$sessionId, $userId])->fetchColumn();
        return $mode === false ? 'ask' : $mode;
    }

    public static function handleRequest(PDO $db, Auth $auth, string $method, string $path): void
    {
        if (!preg_match('~\A/sessions/([A-Za-z0-9_-]{1,100})/permissions\z~D', $path, $m)) { return; }
        requireHttpMethod($method, ['GET', 'PUT']);
        $sessionId = $m[1];
        $userId = $auth->user()['id'];
        $auth->requireAccess('sessions', $sessionId, $method === 'GET' ? 'read' : 'write');
        if (queryParams() !== []) { throw new ApiError(400, 'Permissions accept no query parameters'); }
        if ($method === 'GET') { respond(['mode' => self::mode($db, $sessionId, $userId)]); }

        $body = readRequestBody();
        $mode = $body['mode'] ?? null;
        if (array_keys($body) !== ['mode'] || !in_array($mode, ['ask','allow','yolo'], true)) {
            throw new ApiError(422, 'Supply only mode: ask, allow or yolo', 'validation_error');
        }
        inDatabaseTransaction($db, static function () use ($db, $auth, $sessionId, $userId, $mode): void {
            // Same thread/ACL locks as agent execution, including the first preference write.
            $auth->requireAccess('sessions', $sessionId, 'write');
            executeSql($db, 'INSERT INTO chat_permissions(session_id,user_id,mode) VALUES (?,?,?)
                ON CONFLICT(session_id,user_id) DO UPDATE SET mode=EXCLUDED.mode', [$sessionId, $userId, $mode]);
            $auth->audit('chat.permissions.' . $mode, $sessionId);
        });
        respond(['mode' => $mode]);
    }
}
