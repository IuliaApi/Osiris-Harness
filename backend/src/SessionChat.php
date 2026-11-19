<?php

declare(strict_types=1);

final class SessionChat
{
    public static function runtime(PDO $db, array $actor): array
    {
        $owned = executeSql($db, 'SELECT provider FROM provider_credentials WHERE user_id = ?', [$actor['id']])->fetchAll(PDO::FETCH_COLUMN);
        $runtime = AIProvider::status($owned);
        foreach ($runtime['providers'] as &$p) {
            if ($actor['role'] !== 'viewer' && in_array($p['id'], ProviderCredentials::PROVIDERS, true)) {
                $url = '/api/credentials/' . $p['id'];
                $p['_links']['setCredential'] = apiLink($url, 'PUT');
                if ($p['configured']) { $p['_links']['deleteCredential'] = apiLink($url, 'DELETE'); }
            }
        }
        unset($p);
        $runtime['_links'] = ['budget' => apiLink('/api/budget')];
        return $runtime;
    }

    private static function limit(): int
    {
        $env = getenv('OSIRIS_DAILY_CHAT_LIMIT');
        if ($env === false || $env === '') { return 20; }
        if (!ctype_digit($env) || (int) $env < 1 || (int) $env > 10000) { throw new ApiError(503, 'Invalid dispatch limit configuration', 'budget_unavailable'); }
        return (int) $env;
    }

    public static function budget(PDO $db, string $user): array
    {
        $row = executeSql($db, "SELECT d.day, COALESCE(b.spent,0) AS spent, COALESCE(b.reserved,0) AS reserved FROM (SELECT (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::date AS day) d LEFT JOIN chat_budgets b ON b.day = d.day AND b.user_id = ?", [$user])->fetch(PDO::FETCH_ASSOC);
        $spent = (int) $row['spent']; $reserved = (int) $row['reserved']; $limit = self::limit();
        return ['limit' => $limit, 'spent' => $spent, 'reserved' => $reserved, 'remaining' => max(0, $limit - $spent - $reserved), 'day' => $row['day'], 'unit' => 'provider_dispatch', '_links' => ['self' => apiLink('/api/budget')]];
    }

    /** No lock is held during a remote call. An interrupted worker leaves the reservation charged for that UTC day. */
    private static function reserve(PDO $db, string $user, string $sessionId, string $provider, string $model): string
    {
        $id = 'call_' . bin2hex(random_bytes(12));
        $day = executeSql($db, "INSERT INTO chat_budgets AS b (user_id,day,reserved) VALUES (?, (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::date,1) ON CONFLICT (user_id,day) DO UPDATE SET reserved = b.reserved + 1 WHERE b.reserved + b.spent < ? RETURNING day", [$user, self::limit()])->fetchColumn();
        if ($day === false) {
            header('Retry-After: ' . (86400 - time() % 86400));
            throw new ApiError(429, 'Daily provider dispatch budget exhausted', 'budget_exhausted');
        }
        executeSql($db, 'INSERT INTO chat_dispatches (id,user_id,day,session_id,provider,model) VALUES (?,?,?,?,?,?)', [$id,$user,$day,$sessionId,$provider,$model]);
        executeSql($db, "DELETE FROM chat_budgets WHERE day < (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::date - 30");
        return $id;
    }

    private static function settle(PDO $db, string $id, string $outcome, ?array $turns = null): void
    {
        $count = executeSql($db,
            'WITH charged AS (
                UPDATE chat_dispatches SET completed_at = clock_timestamp(), outcome = ?, user_message_id = ?, assistant_message_id = ?
                WHERE id = ? AND outcome IS NULL RETURNING user_id, day
            ) UPDATE chat_budgets b SET reserved = reserved - 1, spent = spent + 1 FROM charged c WHERE b.user_id = c.user_id AND b.day = c.day',
            [$outcome, $turns['userMessage']['id'] ?? null, $turns['assistantMessage']['id'] ?? null, $id])->rowCount();
        if ($count !== 1) { throw new RuntimeException('Dispatch reservation not available'); }
    }

    public static function chat(PDO $db, Auth $auth, WorkspaceRepository $store, string $sessionId, array $body): array
    {
        if (array_diff(array_keys($body), ['message','provider','model']) || !is_string($body['message'] ?? null) || trim($body['message']) === '' || utf8Length($body['message']) > 6000 || !isRepresentableText($body['message'])) {
            throw new ApiError(422, 'Chat requires a message of 1–6000 characters and accepts only provider and model options', 'validation_error');
        }
        $provider = array_key_exists('provider', $body) ? $body['provider'] : 'openai';
        if (!in_array($provider, [...ProviderCredentials::PROVIDERS,'ollama'], true)) { throw new ApiError(422, 'Unknown chat provider', 'validation_error'); }
        $model = array_key_exists('model', $body) ? $body['model'] : AIProvider::modelFor($provider);
        if (!is_string($model) || !AIProvider::validModel($model)) { throw new ApiError(422, 'Invalid model identifier', 'validation_error'); }
        $user = $auth->user()['id']; $prompt = trim($body['message']);
        [$id,$key,$history,$session,$project] = inDatabaseTransaction($db, function () use ($db,$auth,$store,$sessionId,$user,$provider,$model) {
            $auth->requireAccess('sessions',$sessionId,'write');
            $session = $store->get('sessions',$sessionId);
            if (($session['status'] ?? '') !== 'active') { throw new ApiError(409, 'Only active sessions can receive chat', 'invalid_session_state'); }
            $key = null;
            if ($provider !== 'ollama') {
                $sealed = executeSql($db, 'SELECT sealed_key FROM provider_credentials WHERE user_id = ? AND provider = ?', [$user,$provider])->fetchColumn();
                if ($sealed === false) { throw new ApiError(503, 'No key configured for this account and provider', 'ai_not_configured'); }
                $key = ProviderCredentials::unseal($user,$provider,$sealed);
            } elseif (getenv('OSIRIS_OLLAMA_ENABLED') === '0') { throw new ApiError(503, 'Ollama is disabled', 'ai_not_configured'); }
            return [self::reserve($db,$user,$sessionId,$provider,$model), $key, $store->recentConversation($sessionId), $session, $store->get('projects',$session['projectId'])];
        });
        try {
            $answer = AIProvider::reply($prompt,$history,$session,$project,$provider,$model,$key);
            $turns = inDatabaseTransaction($db, function () use ($db,$auth,$store,$sessionId,$prompt,$answer,$id) {
                $auth->requireAccess('sessions',$sessionId,'write');
                $state = executeSql($db, 'SELECT status FROM sessions WHERE id = ? FOR UPDATE', [$sessionId])->fetchColumn();
                if ($state !== 'active') { throw new ApiError(409, 'Session is no longer active', 'invalid_session_state'); }
                $turns = $store->recordExchange($sessionId,$prompt,$answer['text']);
                self::settle($db,$id,'answered',$turns);
                return $turns;
            });
        } catch (Throwable $error) {
            inDatabaseTransaction($db, fn() => self::settle($db,$id,'failed'));
            throw $error;
        }
        foreach ($turns as &$turn) { $turn['_links'] = resourceLinks('messages',$turn,$auth); }
        unset($turn);
        return ['sessionId' => $sessionId, ...$turns, 'provider' => $provider, 'model' => $model,
            '_links' => ['session' => apiLink('/sessions/' . rawurlencode($sessionId)), 'budget' => apiLink('/api/budget')]];
    }
}
