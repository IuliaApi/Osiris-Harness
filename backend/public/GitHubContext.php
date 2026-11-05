<?php

declare(strict_types=1);

/** One constrained upstream: public GitHub repository metadata, never arbitrary user-provided URLs. */
function fetchGitHubRepository(?string $repositoryUrl, bool $simulateFailure = false): array
{
    $base = ['source' => 'GitHub REST API', 'status' => 'not_configured', 'repository' => null];
    // Explicit deterministic demo mode: never calls GitHub; preserves PostgreSQL composite.
    if ($simulateFailure) { return ['source' => 'GitHub REST API', 'status' => 'unavailable', 'reason' => 'simulated_upstream_failure', 'repository' => null]; }
    if ($repositoryUrl === null || $repositoryUrl === '') { return $base; }
    $path = parse_url($repositoryUrl);
    if ($path === false || strtolower($path['scheme'] ?? '') !== 'https'
        || strtolower($path['host'] ?? '') !== 'github.com' || isset($path['port'], $path['user'])
        || isset($path['port']) || isset($path['user']) || isset($path['pass'])
        || isset($path['query']) || isset($path['fragment'])) {
        return ['source' => 'GitHub REST API', 'status' => 'unavailable', 'reason' => 'unsupported_repository_url', 'repository' => null];
    }
    $repoPath = preg_replace('/\.git$/i', '', trim($path['path'] ?? '', '/'));
    if (!preg_match('~\A([A-Za-z0-9_.-]{1,39})/([A-Za-z0-9_.-]{1,100})\z~D', $repoPath, $matches)) {
        return ['source' => 'GitHub REST API', 'status' => 'unavailable', 'reason' => 'unsupported_repository_url', 'repository' => null];
    }
    $url = 'https://api.github.com/repos/' . rawurlencode($matches[1]) . '/' . rawurlencode($matches[2]);
    if (getenv('OSIRIS_GITHUB_MODE') === 'offline') {
        return ['source' => 'GitHub REST API', 'status' => 'unavailable', 'reason' => 'upstream_disabled_for_demo', 'repository' => null];
    }
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 2, 'follow_location' => 0, 'ignore_errors' => true,
        'header' => "Accept: application/vnd.github+json\r\nUser-Agent: Osiris-Harness-Build2\r\n",
    ]]);
    $previous = set_error_handler(static fn() => true);
    try {
        $handle = fopen($url, 'rb', false, $context);
        if ($handle === false) { return ['source' => 'GitHub REST API', 'status' => 'unavailable', 'reason' => 'network_or_timeout', 'repository' => null]; }
        try {
            $raw = stream_get_contents($handle, 65537);
            $meta = stream_get_meta_data($handle);
            $headers = $meta['wrapper_data'] ?? [];
        } finally { fclose($handle); }
    } finally { restore_error_handler(); }
    $code = 0;
    foreach ($headers as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { $code = (int) $m[1]; }
    }
    if ($code !== 200 || $raw === false || strlen($raw) > 65536 || ($meta['timed_out'] ?? false)) {
        return ['source' => 'GitHub REST API', 'status' => 'unavailable', 'reason' => $code === 200 ? 'network_or_timeout' : 'upstream_http_' . $code, 'repository' => null];
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['full_name'], $payload['html_url'])) {
        return ['source' => 'GitHub REST API', 'status' => 'unavailable', 'reason' => 'invalid_upstream_response', 'repository' => null];
    }
    return ['source' => 'GitHub REST API', 'status' => 'ok', 'repository' => [
        'fullName' => $payload['full_name'], 'url' => $payload['html_url'],
        'description' => $payload['description'] ?? null, 'defaultBranch' => $payload['default_branch'] ?? null,
        'stargazersCount' => (int) ($payload['stargazers_count'] ?? 0),
        'openIssuesCount' => (int) ($payload['open_issues_count'] ?? 0),
    ]];
}
