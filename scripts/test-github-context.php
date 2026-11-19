<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/backend/src/GitHubContext.php';

function checkGitHub(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    echo "PASS: $label\n";
}

// This standalone process deliberately makes NO network or database calls.
$name = 'php/php-src';
$url = 'https://github.com/' . $name;
putenv('OSIRIS_GITHUB_MODE=fixture-success');
$success = fetchGitHubRepository($url . '.git/');
checkGitHub($success['source'] === 'GitHub transport fixture' && $success['provenance'] === 'fixture'
    && $success['status'] === 'ok' && !isset($success['reason']) && $success['repository'] === [
        'fullName' => $name, 'url' => $url, 'description' => 'Deterministic GitHub transport fixture; not live metadata.',
        'defaultBranch' => 'main', 'stargazersCount' => 12, 'openIssuesCount' => 3,
    ], 'Fixture success projects bounded fields with unmistakable provenance');
foreach (['fixture-timeout' => 'upstream_timeout', 'fixture-malformed' => 'invalid_upstream_response',
    'fixture-quota' => 'upstream_rate_limited', 'fixture-failure' => 'upstream_http_503'] as $mode => $reason) {
    putenv('OSIRIS_GITHUB_MODE=' . $mode);
    $external = fetchGitHubRepository($url);
    checkGitHub($external['status'] === 'unavailable' && $external['reason'] === $reason
        && $external['repository'] === null && $external['source'] === 'GitHub transport fixture'
        && $external['provenance'] === 'fixture', "$mode is a partial result: $reason");
}
putenv('OSIRIS_GITHUB_MODE=offline');
$_GET['simulateUpstreamFailure'] = '1';
$_GET['OSIRIS_GITHUB_MODE'] = 'fixture-success';
$_SERVER['HTTP_OSIRIS_GITHUB_MODE'] = 'fixture-success';
$offline = fetchGitHubRepository($url);
checkGitHub($offline['status'] === 'unavailable' && $offline['reason'] === 'upstream_disabled_for_demo'
    && $offline['source'] === 'none' && $offline['provenance'] === 'none', 'Offline and client query/header overrides never claim an external contact');
putenv('OSIRIS_GITHUB_MODE=unknown-mode');
$unknown = fetchGitHubRepository($url);
checkGitHub($unknown['reason'] === 'invalid_server_mode' && $unknown['provenance'] === 'none', 'Unrecognized server transport fails closed');
putenv('OSIRIS_GITHUB_MODE=fixture-success');
checkGitHub(fetchGitHubRepository(null)['status'] === 'not_configured' && fetchGitHubRepository('')['source'] === 'none', 'No repository is not a failure or a fixture result');
foreach (['http://github.com/php/php-src', 'https://github.com.example/php/php-src', 'https://api.github.com/repos/php/php-src',
    'https://user@github.com/php/php-src', 'https://github.com:443/php/php-src', 'https://github.com/php/php-src#main',
    'https://github.com/php/php-src?fields=all', 'https://github.com//php/php-src', 'https://github.com/../php-src',
    'https://github.com/php/..', 'https://github.com/php/...git', 'https://github.com/php/%2e%2e',
    'https://github.com/php%2fphp-src', 'https://github.com/php/php-src/issues', $url . "\r\nInjected: yes",
    'https://github.com/' . str_repeat('a', 40) . '/repo', 'https://github.com/php/' . str_repeat('r', 101)] as $badUrl) {
    $invalid = fetchGitHubRepository($badUrl);
    if (($invalid['reason'] ?? null) !== 'unsupported_repository_url' || $invalid['provenance'] !== 'none') {
        throw new RuntimeException('Invalid URL reached transport: ' . json_encode($badUrl));
    }
}
echo "PASS: Invalid URLs, credentials, origins, escapes, path traversal and overlong segments rejected before either transport\n";

function checkFailure(string $name, array $response, string $reason): void
{
    $result = githubInterpretResponse($name, $response);
    checkGitHub($result === ['status' => 'unavailable', 'repository' => null, 'reason' => $reason], 'Policy ' . $reason);
}
checkFailure($name, ['status' => 200, 'body' => str_repeat('x', GITHUB_CONTEXT_MAX_BYTES + 1)], 'upstream_response_too_large');
checkFailure($name, ['error' => 'upstream_network_error'], 'upstream_network_error');
foreach ([301, 403, 404, 500] as $status) {
    checkFailure($name, ['status' => $status, 'body' => '<private-upstream-error>'], 'upstream_http_' . $status);
}
checkFailure($name, ['status' => 429, 'body' => ''], 'upstream_rate_limited');
checkFailure($name, ['status' => 403, 'quota' => true, 'body' => ''], 'upstream_rate_limited');
checkFailure($name, ['status' => 403, 'body' => '{"message":"You have exceeded a secondary rate limit."}'], 'upstream_rate_limited');
$record = json_decode(githubFixtureResponse('fixture-success', $name)['body'], true, flags: JSON_THROW_ON_ERROR);
foreach ([['private' => true], ['full_name' => 'wrong/repo', 'html_url' => 'https://github.com/wrong/repo'],
    ['html_url' => 'https://example.org/untrusted'], ['html_url' => ['url' => $url]], ['full_name' => 12],
    ['description' => ['not text']], ['description' => "Invalid XML\x01"], ['description' => str_repeat('x', 2049)],
    ['default_branch' => str_repeat('b', 256)], ['default_branch' => null], ['stargazers_count' => '12'], ['open_issues_count' => -1]] as $changes) {
    $response = ['status' => 200, 'body' => json_encode(array_replace($record, $changes), JSON_THROW_ON_ERROR)];
    if (githubInterpretResponse($name, $response) !== ['status' => 'unavailable', 'repository' => null, 'reason' => 'invalid_upstream_response']) {
        throw new RuntimeException('Untrusted metadata accepted: ' . implode(', ', array_keys($changes)));
    }
}
echo "PASS: Mismatched, private, unsafe, wrongly typed and oversized 200 payloads cannot become repository metadata\n";
$record['description'] = null;
$record['unexpected'] = 'not for consumers';
$body = json_encode($record, JSON_THROW_ON_ERROR);
$result = githubInterpretResponse(strtoupper($name), ['status' => 200, 'body' => str_pad($body, GITHUB_CONTEXT_MAX_BYTES)]);
checkGitHub($result['status'] === 'ok' && $result['repository']['description'] === null && !isset($result['repository']['unexpected']),
    'Exactly 64 KiB, optional null description and canonical GitHub casing supported; unknown fields dropped');
