<?php

declare(strict_types=1);

final class ThreadCommands
{
    private const NO_GOAL = ['goal'=>null, 'status'=>'none'];
    private readonly string $user;
    private readonly WorkspaceRepository $store;

    private function __construct(private readonly PDO $db, private readonly Auth $auth, private readonly string $session)
    {
        $this->user = $auth->user()['id'];
        $this->store = new WorkspaceRepository($db, $auth);
    }

    public static function handleRequest(PDO $db, Auth $auth, string $method, string $path): void
    {
        if (!preg_match('~\A/sessions/([A-Za-z0-9_-]{1,100})/(goal|asides)\z~D', $path, $m)) { return; }
        $goal = $m[2] === 'goal';
        requireHttpMethod($method, $goal ? ['GET','PUT'] : ['GET','POST']);
        $auth->requireAccess('sessions', $m[1], $method === 'GET' ? 'read' : 'write');
        if (queryParams() !== []) { throw new ApiError(400, 'Thread commands accept no query parameters'); }
        $api = new self($db, $auth, $m[1]);
        if ($method === 'GET') {
            if ($goal) { respond($api->goal()); }
            $items = executeSql($db, 'SELECT id,question,answer,provider,model FROM thread_asides
                WHERE session_id=? AND user_id=? ORDER BY created_at DESC,id DESC LIMIT 20', [$api->session,$api->user]);
            respond(['items'=>$items->fetchAll(PDO::FETCH_ASSOC)]);
        }
        $body = readRequestBody();
        respond($goal ? $api->updateGoal($body) : $api->ask($body));
    }

    /** Snapshot at turn start: later edits affect the next main turn, never its tool continuations. */
    public static function mainHistory(PDO $db, WorkspaceRepository $store, string $session, string $user): array
    {
        $history = $store->recentConversation($session);
        $goal = executeSql($db, "SELECT goal FROM thread_goals WHERE session_id=? AND user_id=? AND status='active'", [$session,$user])->fetchColumn();
        if ($goal !== false) { $history[] = ['role'=>'user', 'content'=>"Active goal:\n$goal"]; }
        return $history;
    }

    private function goal(): array
    {
        return executeSql($this->db, 'SELECT goal,status FROM thread_goals WHERE session_id=? AND user_id=?', [$this->session,$this->user])->fetch(PDO::FETCH_ASSOC) ?: self::NO_GOAL;
    }

    private function requireActive(): array
    {
        $this->auth->requireAccess('sessions', $this->session, 'write');
        $session = $this->store->get('sessions', $this->session);
        if ($session['status'] !== 'active') { throw new ApiError(409, 'This session is not active', 'invalid_session_state'); }
        return $session;
    }

    private function updateGoal(array $body): array
    {
        return inDatabaseTransaction($this->db, function () use ($body) {
            $this->requireActive();
            $scope = [$this->session,$this->user];
            if (array_keys($body) === ['goal']) {
                $text = $body['goal'];
                if (!is_string($text) || trim($text) === '' || utf8Length($text) > 2000 || !isRepresentableText($text)) {
                    throw new ApiError(422, 'Supply a goal of 1–2000 characters', 'validation_error');
                }
                $text = trim($text);
                executeSql($this->db, "INSERT INTO thread_goals(session_id,user_id,goal,status) VALUES (?,?,?,'active')
                    ON CONFLICT (session_id,user_id) DO UPDATE SET goal=EXCLUDED.goal,status='active'", [...$scope,$text]);
                return ['goal'=>$text,'status'=>'active'];
            }
            $action = $body['action'] ?? null;
            if (array_keys($body) !== ['action'] || !in_array($action, ['pause','resume','done','clear'], true)) {
                throw new ApiError(422, 'Supply only a goal or one goal action', 'validation_error');
            }
            $current = $this->goal();
            $next = match ($action) {
                'pause' => $current['status'] === 'active' ? 'paused' : null,
                'resume' => $current['status'] === 'paused' ? 'active' : null,
                'done' => in_array($current['status'], ['active','paused'], true) ? 'done' : null,
                'clear' => $current['status'] !== 'none' ? 'none' : null,
            };
            if ($next === null) { throw new ApiError(409, 'Action unavailable in the current goal state', 'invalid_goal_state'); }
            if ($next === 'none') {
                executeSql($this->db, 'DELETE FROM thread_goals WHERE session_id=? AND user_id=?', $scope);
                return self::NO_GOAL;
            }
            executeSql($this->db, 'UPDATE thread_goals SET status=? WHERE session_id=? AND user_id=?', [$next,...$scope]);
            return ['goal'=>$current['goal'],'status'=>$next];
        });
    }

    private function ask(array $body): array
    {
        if (!isset($body['provider'], $body['model'])) { throw new ApiError(422, 'Select a provider and model', 'validation_error'); }
        $replyTo = $body['replyTo'] ?? null;
        if (array_key_exists('replyTo', $body) && (!is_string($replyTo) || !preg_match('/\Aaside_[a-f0-9]{24}\z/D', $replyTo))) {
            throw new ApiError(422, 'Invalid side question identifier', 'validation_error');
        }
        unset($body['replyTo']);
        [$question,$provider,$model] = SessionChat::parseInput($body);
        [$dispatch,$key,$session,$project,$history] = inDatabaseTransaction($this->db, function () use ($replyTo,$provider,$model) {
            $session = $this->requireActive();
            $history = $this->store->recentConversation($this->session);
            if ($replyTo !== null) {
                $prior = executeSql($this->db, 'SELECT question,answer FROM thread_asides WHERE id=? AND session_id=? AND user_id=?', [$replyTo,$this->session,$this->user])->fetch(PDO::FETCH_ASSOC);
                if (!$prior) { throw new ApiError(404, 'Side question not found', 'not_found'); }
                $history[] = ['role'=>'user','content'=>$prior['question']];
                $history[] = ['role'=>'assistant','content'=>$prior['answer']];
            }
            $key = SessionChat::credential($this->db,$this->user,$provider);
            $dispatch = SessionChat::reserve($this->db,$this->user,$this->session,$provider,$model);
            return [$dispatch,$key,$session,$this->store->projectForSession($session),$history];
        });
        try {
            $reply = AIProvider::reply($question,$history,$session,$project,$provider,$model,$key);
            return inDatabaseTransaction($this->db, function () use ($question,$reply,$provider,$dispatch) {
                $this->auth->authenticate(Auth::cookieToken());
                $this->requireActive();
                $id = 'aside_' . bin2hex(random_bytes(12));
                ['text' => $answer, 'model' => $model] = $reply;
                executeSql($this->db, 'INSERT INTO thread_asides(id,session_id,user_id,question,answer,provider,model) VALUES (?,?,?,?,?,?,?)',
                    [$id,$this->session,$this->user,$question,$answer,$provider,$model]);
                SessionChat::settle($this->db,$dispatch,'answered');
                return compact('id','question','answer','provider','model');
            });
        } catch (Throwable $error) {
            inDatabaseTransaction($this->db, fn() => SessionChat::settle($this->db,$dispatch,'failed'));
            throw $error;
        }
    }
}
