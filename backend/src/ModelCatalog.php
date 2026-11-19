<?php

declare(strict_types=1);

final class ModelCatalog
{
    private const URLS = [
        'openai' => 'https://api.openai.com/v1/models',
        'anthropic' => 'https://api.anthropic.com/v1/models?limit=100',
        'ollama' => 'http://host.docker.internal:11434/api/tags',
    ];
    private const BUDGET_MS = 5500;
    private const REQUEST_MS = 2400;
    private const MAX_BYTES = 524288;
    private const MAX_RECORDS = 2048;
    private const MAX_PAGES = 20;

    public static function list(PDO $db, Auth $auth): array
    {
        $actor = $auth->user()['id'];
        $owned = executeSql($db, 'SELECT provider FROM provider_credentials WHERE user_id=?', [$actor])->fetchAll(PDO::FETCH_COLUMN);
        return self::discover(static fn(string $id): ?string => SessionChat::credential($db, $actor, $id, false), self::fetch(...), $owned);
    }

    /** @internal Batch transport returns [HTTP status, body] per successful transfer; omitted entries failed. */
    public static function discover(callable $credential, callable $fetch, array $owned = []): array
    {
        $deadline = hrtime(true) + self::BUDGET_MS * 1000000;
        $providers = $requests = $keys = [];
        foreach (AIProvider::status($owned)['providers'] as ['id' => $id, 'label' => $label]) {
            $providers[$id] = ['id' => $id, 'label' => $label, 'models' => [], 'status' => 'unavailable'];
            try {
                // The shared chat credential lookup also enforces the local-provider switch.
                $key = $credential($id);
            } catch (ApiError $error) {
                if ($error->codeName === 'ai_not_configured') { $providers[$id]['status'] = 'not_configured'; }
                continue;
            } catch (PDOException) { continue; }
            if ($key !== null && str_starts_with($key,ProviderCredentials::LOGIN_PREFIX)) {
                if (!RouterConnection::enabled()) { continue; }
                $requests[$id] = ['http://ai-router:8090/models', [...RouterConnection::headers(), 'Content-Type: application/json'],
                    json_encode(['provider'=>$id,'authType'=>'login','apiKey'=>substr($key,strlen(ProviderCredentials::LOGIN_PREFIX))], JSON_THROW_ON_ERROR)];
                continue;
            }
            if ($key !== null) { $keys[] = $key; }
            $headers = match ($id) {
                'openai' => ['Authorization: Bearer ' . $key],
                'anthropic' => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
                'ollama' => [],
            };
            $requests[$id] = [self::URLS[$id], $headers];
        }

        $models = $counts = $cursors = [];
        for ($page = 1; $requests; $page++) {
            $remaining = (int) floor(($deadline - hrtime(true)) / 1000000);
            if ($remaining <= 0) { break; }
            $responses = $fetch($requests, min($remaining, self::REQUEST_MS));
            $next = [];
            foreach ($requests as $id => [, $headers]) {
                [$code, $raw] = $responses[$id] ?? [0, ''];
                if ($code !== 200 || strlen($raw) > self::MAX_BYTES) { continue; }
                $body = json_decode($raw, false, 32);
                if (!$body instanceof stdClass || isset($body->error)) { continue; }
                $sdk = isset($requests[$id][2]);
                $records = $sdk ? ($body->models ?? null) : ($id === 'ollama' ? ($body->models ?? null) : ($body->data ?? null));
                if ($sdk && ($body->source ?? null) === 'catalog') { $providers[$id]['source'] = 'catalog'; }
                if (!is_array($records)) { continue; }

                $cursor = null;
                if (!$sdk && $id === 'anthropic') {
                    if (!is_bool($body->has_more ?? null)) { continue; }
                    if ($body->has_more) {
                        $cursor = $body->last_id ?? null;
                        if (!$records || !is_string($cursor) || !preg_match('/\A[!-~]{1,256}\z/D', $cursor)
                            || isset($cursors[$cursor]) || self::containsKey($cursor, $keys)) { continue; }
                        $cursors[$cursor] = true;
                    }
                }

                $counts[$id] ??= 0;
                $models[$id] ??= [];
                $truncated = false;
                foreach ($records as $record) {
                    if ($counts[$id] === self::MAX_RECORDS) { $truncated = true; break; }
                    $counts[$id]++;
                    $model = !$sdk && $id === 'ollama' ? ($record->name ?? $record->model ?? null) : ($record->id ?? null);
                    // An invalid envelope is a failure, not a successful empty inventory.
                    if (!is_string($model) || $model === '' || self::containsKey($model, $keys)) { continue 2; }
                    if (AIProvider::validModel($model, $id) && ($sdk || self::chatModel($id, $model, $record))) {
                        $models[$id][$model] = $model;
                    }
                }
                if ($cursor !== null && !$truncated) {
                    if ($page < self::MAX_PAGES && $counts[$id] < self::MAX_RECORDS) {
                        $next[$id] = [self::URLS[$id] . '&after_id=' . rawurlencode($cursor), $headers];
                        continue;
                    }
                    $truncated = true;
                }
                sort($models[$id], SORT_STRING);
                $providers[$id]['models'] = $models[$id];
                $providers[$id]['status'] = 'available';
                if ($truncated) { $providers[$id]['truncated'] = true; }
            }
            $requests = $next;
        }
        return ['providers' => array_values($providers)];
    }

    private static function containsKey(string $value, #[SensitiveParameter] array $keys): bool
    {
        foreach ($keys as $key) { if (str_contains($value, $key)) { return true; } }
        return false;
    }

    private static function chatModel(string $id, string $model, stdClass $record): bool
    {
        if ($id === 'openai') {
            // /models also lists dedicated media, embedding, moderation and legacy completions endpoints.
            return !preg_match('/(?:^|[-:.\/])(?:audio|transcribe|realtime|tts|whisper|embedding|moderation|image|dall-e|sora|davinci|babbage|curie|ada|instruct|search-preview|search-api)(?:[-:.\/]|$)/i', $model);
        }
        if ($id === 'ollama') {
            if (is_array($record->capabilities ?? null)) { return in_array('completion', $record->capabilities, true); }
            $family = $record->details->family ?? '';
            return !(is_string($family) && preg_match('/^(?:bert|nomic-bert(?:-moe)?|xlm-roberta|clip)$/i', $family))
                && !preg_match('/(?:^|[-:.\/])(?:embed[a-z0-9]*|rerank[a-z0-9]*|bge|bgem3|e5|minilm)(?:[-:.\/]|$)/i', $model);
        }
        return true;
    }

    /** Start configured providers together, so an unreachable host cannot delay the start of the others. */
    private static function fetch(#[SensitiveParameter] array $requests, int $timeoutMs): array
    {
        $multi = curl_multi_init();
        $handles = $bodies = $responses = [];
        try {
            foreach ($requests as $id => [$url, $headers]) {
                $client = curl_init($url);
                if ($client === false) { continue; }
                $bodies[$id] = '';
                $sdk = isset($requests[$id][2]);
                if (!curl_setopt_array($client, [
                    CURLOPT_HTTPHEADER => ['Accept: application/json', ...$headers],
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => $sdk || $id === 'ollama' ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
                    CURLOPT_CONNECTTIMEOUT_MS => min(1200, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs,
                    CURLOPT_PRIVATE => $id,
                    CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$bodies, $id): int {
                        $size = strlen($chunk);
                        if (strlen($bodies[$id]) + $size > self::MAX_BYTES) { return 0; }
                        $bodies[$id] .= $chunk;
                        return $size;
                    },
                ] + ($sdk ? [CURLOPT_PROXY => '', CURLOPT_POSTFIELDS => $requests[$id][2]] : [])) || curl_multi_add_handle($multi, $client) !== CURLM_OK) { continue; }
                $handles[] = $client;
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($status === CURLM_OK && $running && curl_multi_select($multi, 0.1) === -1) { usleep(1000); }
            } while ($status === CURLM_OK && $running);
            while ($done = curl_multi_info_read($multi)) {
                if ($done['result'] !== CURLE_OK) { continue; }
                $client = $done['handle'];
                $id = curl_getinfo($client, CURLINFO_PRIVATE);
                $responses[$id] = [curl_getinfo($client, CURLINFO_RESPONSE_CODE), $bodies[$id]];
            }
        } finally {
            foreach ($handles as $client) { curl_multi_remove_handle($multi, $client); }
        }
        return $responses;
    }
}
