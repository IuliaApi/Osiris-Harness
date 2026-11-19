<?php

declare(strict_types=1);

/** A step is exactly one metered provider request; never hold a DB lock during inference. */
final class AgentRuns
{
    private const TOOL_ROUNDS = 4;
    private readonly string $user;
    private readonly WorkspaceRepository $store;
    private readonly string $base;

    private function __construct(private readonly PDO $db, private readonly Auth $auth, private readonly string $session)
    {
        $this->user = $auth->user()['id'];
        $this->store = new WorkspaceRepository($db, $auth);
        $this->base = '/sessions/' . rawurlencode($session) . '/agent';
    }

    public static function handleRequest(PDO $db, Auth $auth, string $method, string $path): void
    {
        if (!preg_match('~\A/sessions/([A-Za-z0-9_-]{1,100})/agent(?:/(turn_[a-f0-9]{24})(?:/(step|cancel))?)?\z~D', $path, $m)) { return; }
        $api = new self($db, $auth, $m[1]);
        $id = $m[2] ?? null;
        $action = $m[3] ?? null;
        requireHttpMethod($method, $id === null ? ['GET','POST'] : ($action === null ? ['GET'] : ['POST']));
        $auth->requireAccess('sessions', $api->session, $method === 'GET' ? 'read' : 'write');
        $query = queryParams();
        if ($action === 'step') {
            if (array_keys($query) !== ['round'] || !preg_match('/\A[0-4]\z/D', $query['round'])) { throw new ApiError(400, 'Follow the step link for the current round'); }
        } elseif ($query !== []) { throw new ApiError(400, 'Unexpected query parameters'); }
        if ($method === 'GET') {
            $row = $api->load($id);
            $run = $row === null ? null : $api->view($row);
            respond($id === null ? ['run' => $run] : $run);
        }
        $body = readRequestBody();
        if ($id === null) {
            $row = $api->start($body);
            $run = $api->view($row);
            header('Location: ' . $run['_links']['self']['href']);
            respond($run, 201);
        }
        if ($body !== []) { throw new ApiError(422, 'Action accepts only an empty object', 'validation_error'); }
        $row = $action === 'step' ? $api->step($id, (int) $query['round']) : $api->cancel($id);
        respond($api->view($row));
    }

    private function load(?string $id): ?array
    {
        $row = executeSql($this->db, 'SELECT * FROM agent_runs WHERE session_id=? AND user_id=?', [$this->session,$this->user])->fetch(PDO::FETCH_ASSOC);
        if ($id !== null && (!$row || $row['id'] !== $id)) { throw new ApiError(404, 'Agent turn not found', 'not_found'); }
        if (!$row) { return null; }
        $row['steps'] = json_decode($row['steps'], true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }

    private static function events(array $steps, ?string $toolRunBase = null): array
    {
        $events = [];
        foreach ($steps as $step) {
            foreach ($step['calls'] as $i => $call) {
                $target = $call['arguments']['path'] ?? $call['arguments']['pattern'] ?? '';
                $event = ['name'=>$call['name'], 'path'=>is_string($target) && strlen($target) <= 256 && isRepresentableText($target) ? $target : '', 'output'=>$step['results'][$i]];
                if (isset($step['reviews'][$i])) {
                    $event['runId'] = $step['reviews'][$i];
                    if ($toolRunBase !== null) { $event['_links'] = ['toolRun'=>apiLink($toolRunBase . $event['runId'])]; }
                }
                $events[] = $event;
            }
        }
        return $events;
    }

    private function view(array $row): array
    {
        $href = $this->base . '/' . $row['id'];
        $links = ['self'=>apiLink($href)];
        if ($this->auth->can('sessions', $this->session, 'write')) {
            if (in_array($row['status'], ['ready','review'], true)) { $links['step'] = apiLink($href . '/step?round=' . count($row['steps']), 'POST'); }
            if (in_array($row['status'], ['ready','working','review'], true)) { $links['cancel'] = apiLink($href . '/cancel', 'POST'); }
        }
        return ['id'=>$row['id'], 'sessionId'=>$this->session, 'prompt'=>$row['prompt'], 'provider'=>$row['provider'], 'model'=>$row['model'],
            'tools'=>$row['tools_enabled'], 'status'=>$row['status'], 'events'=>self::events($row['steps'], '/sessions/' . rawurlencode($this->session) . '/tool-runs/'),
            'error'=>$row['error'], '_links'=>$links];
    }

    private function requireActive(): array
    {
        // Acquires the thread and ACL-bearing parent locks for the transaction.
        $this->auth->requireAccess('sessions', $this->session, 'write');
        $session = $this->store->get('sessions', $this->session);
        if ($session['status'] !== 'active') { throw new ApiError(409, 'Only active sessions can run the agent', 'invalid_session_state'); }
        return $session;
    }

    private function start(array $body): array
    {
        $tools = $body['tools'] ?? null;
        if (!is_bool($tools)) { throw new ApiError(422, 'Select whether to enable workspace tools', 'validation_error'); }
        unset($body['tools']);
        [$prompt,$provider,$model] = SessionChat::parseInput($body);
        return inDatabaseTransaction($this->db, function () use ($tools,$prompt,$provider,$model) {
            $this->requireActive();
            $old = $this->load(null);
            if ($old && !in_array($old['status'], ['completed','failed','cancelled'], true)) { throw new ApiError(409, 'Resume or cancel the existing turn first', 'turn_active'); }
            if ($old) { executeSql($this->db, 'DELETE FROM agent_runs WHERE id=?', [$old['id']]); }
            $id = 'turn_' . bin2hex(random_bytes(12));
            $history = ThreadCommands::mainHistory($this->db, $this->store, $this->session, $this->user);
            executeSql($this->db, "INSERT INTO agent_runs(id,session_id,user_id,prompt,provider,model,tools_enabled,status,history) VALUES (?,?,?,?,?,?,?,'ready',?)",
                [$id,$this->session,$this->user,$prompt,$provider,$model,$tools ? 'true' : 'false',json_encode($history, JSON_THROW_ON_ERROR)]);
            return $this->load($id);
        });
    }

    private function cancel(string $id): array
    {
        return inDatabaseTransaction($this->db, function () use ($id) {
            $this->auth->requireAccess('sessions', $this->session, 'write');
            $row = $this->load($id);
            if (!in_array($row['status'], ['ready','working','review'], true)) { return $row; }
            if ($row['status'] === 'review') {
                // Earlier rounds could only advance after all their writes completed.
                $step = &$row['steps'][array_key_last($row['steps'])];
                foreach ($step['reviews'] as $i => $runId) {
                    $revoked = executeSql($this->db, "DELETE FROM tool_runs WHERE session_id=? AND requested_by=? AND id=? AND status IN ('requested','approved')",
                        [$this->session,$this->user,$runId])->rowCount();
                    if ($revoked) {
                        unset($step['reviews'][$i]);
                        $step['results'][$i] = 'Cancelled without applying';
                        $this->auth->audit('tool.cancel', $runId);
                    }
                }
                $this->appliedResults($step); // Keep the actual outcomes of any writes already applied.
            }
            executeSql($this->db, "UPDATE agent_runs SET status='cancelled',history='[]',steps=? WHERE id=?", [json_encode($row['steps'], JSON_THROW_ON_ERROR),$id]);
            return $this->load($id);
        });
    }

    /** Resolve pending proposals from their persisted results, not from the client. */
    private function appliedResults(array &$step): void
    {
        foreach ($step['reviews'] as $i => $runId) {
            $run = executeSql($this->db, 'SELECT status,result FROM tool_runs WHERE session_id=? AND id=?', [$this->session,$runId])->fetch(PDO::FETCH_ASSOC);
            if (!$run || $run['status'] !== 'completed') { throw new ApiError(409, 'Approve and apply the proposed changes before continuing, or cancel the turn', 'review_required'); }
            $step['results'][$i] = $run['result'];
        }
    }

    private function step(string $id, int $round): array
    {
        $db = $this->db;
        [$row,$dispatch,$key,$session,$project] = inDatabaseTransaction($db, function () use ($db,$id,$round) {
            $session = $this->requireActive();
            $row = $this->load($id);
            if (!in_array($row['status'], ['ready','review'], true) || count($row['steps']) !== $round) {
                throw new ApiError(409, 'This step is no longer current; reload the turn', 'stale_step');
            }
            if ($row['status'] === 'review') { $this->appliedResults($row['steps'][$round - 1]); }
            $key = SessionChat::credential($db,$this->user,$row['provider']);
            $dispatch = SessionChat::reserve($db,$this->user,$this->session,$row['provider'],$row['model']);
            executeSql($db, "UPDATE agent_runs SET status='working',steps=? WHERE id=?", [json_encode($row['steps'], JSON_THROW_ON_ERROR),$id]);
            return [$row,$dispatch,$key,$session,$this->store->projectForSession($session)];
        });
        try {
            $tools = $row['tools_enabled'] && $round < self::TOOL_ROUNDS ? AgentTools::definitions() : [];
            $answer = AIProvider::step($row['prompt'],json_decode($row['history'],true,flags: JSON_THROW_ON_ERROR),$session,$project,$row['provider'],$row['model'],$key,$tools,$row['steps']);
            return inDatabaseTransaction($db, function () use ($db,$id,$dispatch,$answer) {
                $this->auth->authenticate(Auth::cookieToken());
                $this->requireActive();
                $row = $this->load($id);
                if ($row['status'] === 'cancelled') {
                    SessionChat::settle($db,$dispatch,'failed');
                    return $row;
                }
                if ($row['status'] !== 'working') { throw new ApiError(409, 'Agent turn changed', 'stale_step'); }
                $steps = $row['steps'];
                $turns = null;
                if ($answer['calls'] === []) {
                    $turns = $this->store->recordExchange($this->session,$row['prompt'],$answer['text'],self::events($steps));
                    executeSql($db, "UPDATE agent_runs SET status='completed',history='[]',user_message_id=?,assistant_message_id=? WHERE id=?", [$turns['userMessage']['id'],$turns['assistantMessage']['id'],$id]);
                } else {
                    $results = []; $reviews = []; $status = 'ready';
                    foreach ($answer['calls'] as $index => $call) {
                        $result = AgentTools::execute($db,$this->auth,$this->session,$call['name'],$call['arguments']);
                        $results[] = $result['output'];
                        if (isset($result['runId'])) { $reviews[$index] = $result['runId']; }
                        if ($result['needsReview'] ?? false) { $status = 'review'; }
                    }
                    $steps[] = ['text'=>$answer['text'], 'calls'=>$answer['calls'], 'results'=>$results, 'reviews'=>$reviews];
                    executeSql($db,'UPDATE agent_runs SET status=?,steps=? WHERE id=?',[$status,json_encode($steps, JSON_THROW_ON_ERROR),$id]);
                }
                SessionChat::settle($db,$dispatch,'answered',$turns);
                return $this->load($id);
            });
        } catch (Throwable $error) {
            inDatabaseTransaction($db, function () use ($db,$id,$dispatch,$error) {
                SessionChat::settle($db,$dispatch,'failed');
                $message = $error instanceof ApiError ? $error->getMessage() : 'Agent step failed';
                executeSql($db,"UPDATE agent_runs SET status='failed',history='[]',error=? WHERE id=? AND status='working'",[$message,$id]);
            });
            throw $error;
        }
    }
}
