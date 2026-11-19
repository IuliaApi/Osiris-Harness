<?php

declare(strict_types=1);

const GITHUB_CONTEXT_MAX_BYTES = 65536;

/** Only process configuration selects a transport. No request parameter, URL or header does. */
function fetchGitHubRepository(?string $repositoryUrl): array
{
    $none = ['source' => 'none', 'provenance' => 'none', 'repository' => null];
    if ($repositoryUrl === null || $repositoryUrl === '') {
        return $none + ['status' => 'not_configured'];
    }
    if (strlen($repositoryUrl) > 180 || !preg_match(
        '~\Ahttps://github\.com/([a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?)/([a-z0-9_.-]{1,104})/?\z~iD',
        $repositoryUrl, $match
    )) {
        return $none + ['status' => 'unavailable', 'reason' => 'unsupported_repository_url'];
    }
    $repo = preg_replace('/\.git\z/i', '', $match[2]);
    if ($repo === '' || $repo === '.' || $repo === '..' || strlen($repo) > 100) {
        return $none + ['status' => 'unavailable', 'reason' => 'unsupported_repository_url'];
    }
    $name = $match[1] . '/' . $repo;
    $mode = getenv('OSIRIS_GITHUB_MODE', true);
    if ($mode === 'offline') {
        return $none + ['status' => 'unavailable', 'reason' => 'upstream_disabled_for_demo'];
    }
    if ($mode === false || $mode === '' || $mode === 'online') {
        if (!function_exists('curl_init')) {
            return $none + ['status' => 'unavailable', 'reason' => 'upstream_transport_unavailable'];
        }
        $response = githubLiveRequest($name);
        $origin = ['source' => 'GitHub REST API', 'provenance' => 'live'];
    } else {
        $response = githubFixtureResponse($mode, $name);
        if ($response === null) {
            return $none + ['status' => 'unavailable', 'reason' => 'invalid_server_mode'];
        }
        $origin = ['source' => 'GitHub transport fixture', 'provenance' => 'fixture'];
    }
    return $origin + githubInterpretResponse($name, $response);
}

/** Fixed HTTPS origin, certificate/hostname verification, no redirects, credentials or automatic retries. */
function githubLiveRequest(string $name): array
{
    $handle = curl_init('https://api.github.com/repos/' . $name);
    if ($handle === false) { return ['error' => 'upstream_transport_unavailable']; }
    $body = '';
    $headerBytes = 0;
    $tooLarge = false;
    $quota = false;
    $configured = curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
        CURLOPT_USERAGENT => 'Osiris-Harness',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 2000,
        CURLOPT_TIMEOUT_MS => 2000, // Whole request, not a resettable per-read timeout.
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headerBytes, &$tooLarge, &$quota): int {
            $size = strlen($line);
            $headerBytes += $size;
            if ($headerBytes > 32768) { $tooLarge = true; return 0; }
            if (str_starts_with($line, 'HTTP/')) { $quota = false; }
            if (preg_match('/\A(?:x-ratelimit-remaining:\s*0\s*|retry-after:\s*\S[^\r\n]*\s*)\z/i', $line)) {
                $quota = true;
            }
            return $size;
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            $size = strlen($chunk);
            if (strlen($body) + $size > GITHUB_CONTEXT_MAX_BYTES) { $tooLarge = true; return 0; }
            $body .= $chunk;
            return $size;
        },
    ]);
    if (!$configured) { return ['error' => 'upstream_transport_unavailable']; }
    if (curl_exec($handle) === false) {
        if ($tooLarge) { return ['error' => 'upstream_response_too_large']; }
        return ['error' => curl_errno($handle) === CURLE_OPERATION_TIMEDOUT ? 'upstream_timeout' : 'upstream_network_error'];
    }
    return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body, 'quota' => $quota];
}

/** Transport doubles feed the same response policy as HTTPS. They never open a network connection. */
function githubFixtureResponse(string $mode, string $name): ?array
{
    return match ($mode) {
        'fixture-success' => ['status' => 200, 'body' => json_encode([
            'full_name' => $name, 'html_url' => 'https://github.com/' . $name, 'private' => false,
            'description' => 'Deterministic GitHub transport fixture; not live metadata.',
            'default_branch' => 'main', 'stargazers_count' => 12, 'open_issues_count' => 3,
        ], JSON_THROW_ON_ERROR)],
        'fixture-timeout' => ['error' => 'upstream_timeout'],
        'fixture-malformed' => ['status' => 200, 'body' => '{"full_name":'],
        'fixture-quota' => ['status' => 403, 'quota' => true, 'body' => '{"message":"API rate limit exceeded"}'],
        'fixture-failure' => ['status' => 503, 'body' => 'Service Unavailable'],
        default => null,
    };
}

/** Project a validated, public, identity-matched record; never forward a raw response or redirect URL. */
function githubInterpretResponse(string $name, array $response): array
{
    $failure = ['status' => 'unavailable', 'repository' => null];
    if (isset($response['error'])) { return $failure + ['reason' => $response['error']]; }
    $status = $response['status'];
    $body = $response['body'];
    if (strlen($body) > GITHUB_CONTEXT_MAX_BYTES) { return $failure + ['reason' => 'upstream_response_too_large']; }
    if ($status !== 200) {
        // GitHub may report secondary limits in the error message even without a Retry-After header.
        $message = $status === 403 ? (json_decode($body, true, 16)['message'] ?? null) : null;
        $limited = $status === 429 || ($status === 403 && (($response['quota'] ?? false)
            || (is_string($message) && preg_match('/\b(?:secondary|API) rate limit\b/i', $message))));
        return $failure + ['reason' => $limited ? 'upstream_rate_limited' : 'upstream_http_' . $status];
    }
    $payload = json_decode($body, true, 32);
    $invalid = $failure + ['reason' => 'invalid_upstream_response'];
    if (!is_array($payload) || ($payload['private'] ?? null) !== false
        || !is_string($payload['full_name'] ?? null) || strcasecmp($name, $payload['full_name']) !== 0
        || ($payload['html_url'] ?? null) !== 'https://github.com/' . $payload['full_name']
        || !array_key_exists('description', $payload)
        || !is_string($payload['default_branch'] ?? null) || $payload['default_branch'] === ''
        || !is_int($payload['stargazers_count'] ?? null) || $payload['stargazers_count'] < 0
        || !is_int($payload['open_issues_count'] ?? null) || $payload['open_issues_count'] < 0) {
        return $invalid;
    }
    foreach (['description' => 2048, 'default_branch' => 255] as $field => $maxBytes) {
        $value = $payload[$field];
        if ($value !== null && (!is_string($value) || strlen($value) > $maxBytes
            || preg_match('/[\p{Cc}\x{FFFE}\x{FFFF}]/u', $value))) { return $invalid; }
    }
    return ['status' => 'ok', 'repository' => [
        'fullName' => $payload['full_name'], 'url' => $payload['html_url'],
        'description' => $payload['description'], 'defaultBranch' => $payload['default_branch'],
        'stargazersCount' => $payload['stargazers_count'], 'openIssuesCount' => $payload['open_issues_count'],
    ]];
}
