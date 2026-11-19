<?php

declare(strict_types=1);

/** Cookie and CSRF state live only for the lifetime of this CLI invocation. */
final class LocalClient
{
    private string $cookie = '';
    private string $csrf = '';
    public readonly array $root;

    private function __construct(private readonly string $baseUrl) {}

    public static function authenticated(callable $work): void
    {
        $port = getenv('OSIRIS_PORT') ?: '8080';
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) { throw new RuntimeException('Invalid OSIRIS_PORT'); }
        $username = getenv('OSIRIS_USERNAME');
        $password = getenv('OSIRIS_PASSWORD');
        if ($username === false || $username === '' || $password === false || $password === '') {
            throw new RuntimeException('Supply OSIRIS_USERNAME and OSIRIS_PASSWORD in the client process environment.');
        }
        $client = new self('http://127.0.0.1:' . (int)$port);
        $login = $client->request('POST', '/auth/login', ['username' => $username, 'password' => $password]);
        unset($password);
        $client->csrf = $login['csrfToken'] ?? '';
        if ($client->csrf === '' || $client->cookie === '') { throw new RuntimeException('Login did not establish a cookie and CSRF token.'); }
        try {
            $client->root = $client->request('GET', '/api');
            $work($client);
        } finally {
            $client->request('POST', '/auth/logout');
        }
    }

    public function session(string $id): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $id)) { throw new RuntimeException('Invalid session ID.'); }
        return $this->request('GET', '/sessions/' . rawurlencode($id) . '?view=full');
    }

    public function follow(array $resource, string $rel, string $method = 'GET', ?array $payload = null): array
    {
        $link = $resource['_links'][$rel] ?? null;
        if (($link['method'] ?? '') !== $method || !is_string($link['href'] ?? null)) {
            throw new RuntimeException("Action '$rel' is not available for this account and resource.");
        }
        return $this->request($method, $link['href'], $payload);
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('~[\x00-\x20\\\\]~', $path)) {
            throw new RuntimeException('Invalid local API link.');
        }
        $headers = ['Accept: application/json'];
        if ($this->cookie !== '') { $headers[] = 'Cookie: ' . $this->cookie; }
        if ($method !== 'GET' && $this->csrf !== '') { $headers[] = 'X-CSRF-Token: ' . $this->csrf; }
        if ($payload !== null) { $headers[] = 'Content-Type: application/json'; }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => $headers, 'ignore_errors' => true, 'follow_location' => 0,
            'timeout' => 70, 'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
        ]]);
        $raw = @file_get_contents($this->baseUrl . $path, false, $context);
        if ($raw === false) { throw new RuntimeException('Could not reach the local Osiris server.'); }
        $status = preg_match('~^HTTP/\S+ (\d{3})~', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0;
        foreach ($http_response_header as $header) {
            if (preg_match('~^Set-Cookie:\s*(osiris_session=[^;\s]+)~i', $header, $m)) { $this->cookie = $m[1]; }
        }
        if ($status === 204) { return []; }
        $data = json_decode($raw, true);
        if ($status < 200 || $status >= 300) { throw new RuntimeException(($data['error'] ?? 'Request failed') . " (HTTP $status)"); }
        if (!is_array($data)) { throw new RuntimeException('Invalid JSON reply.'); }
        return $data;
    }
}
