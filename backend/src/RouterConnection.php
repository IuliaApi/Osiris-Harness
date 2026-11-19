<?php

declare(strict_types=1);

final class RouterConnection
{
    public static function enabled(): bool { return getenv('OSIRIS_AI_ROUTER') === '1'; }

    public static function headers(): array
    {
        $key = trim((string) @file_get_contents(getenv('OSIRIS_AI_ROUTER_KEY_FILE') ?: '/run/osiris/router.key', false, null, 0, 128));
        if (!preg_match('~\A[A-Za-z0-9+/]{43}=\z~D', $key)) { throw new ApiError(503, 'AI router authentication unavailable', 'ai_not_configured'); }
        return ['Authorization: Bearer ' . $key];
    }

    /** Internal service only. Routes are constants selected by the application. */
    public static function request(string $route, #[SensitiveParameter] ?array $body = null): array
    {
        if (!self::enabled()) { throw new ApiError(404, 'Provider sign-in is not enabled', 'not_found'); }
        $client = curl_init('http://ai-router:8090/' . $route);
        $raw = '';
        curl_setopt_array($client, [CURLOPT_HTTPHEADER=>[...self::headers(), 'Content-Type: application/json'], CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROXY=>'', CURLOPT_CONNECTTIMEOUT_MS=>1500, CURLOPT_TIMEOUT_MS=>20000,
            CURLOPT_WRITEFUNCTION=>static function ($unused, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > 262144) { return 0; }
                $raw .= $chunk;
                return strlen($chunk);
            }]);
        if ($body !== null) { curl_setopt($client, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
        $ok = curl_exec($client);
        $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
        $data = $ok ? json_decode($raw, true, 32) : null;
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw match ($status) {
                404,410 => new ApiError(404, 'Sign-in method or transaction not available', 'login_not_found'),
                400,422 => new ApiError(422, 'This sign-in method or response is not available', 'login_input_invalid'),
                409 => new ApiError(409, 'This sign-in step is no longer current', 'login_state_changed'),
                429 => new ApiError(429, 'Too many active sign-ins; wait before starting again', 'login_rate_limit'),
                default => new ApiError(503, 'Provider authentication service unavailable', 'provider_login_unavailable'),
            };
        }
        return $data;
    }
}
