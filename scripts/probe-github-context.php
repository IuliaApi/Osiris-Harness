<?php

declare(strict_types=1);

// Opt-in, read-only live upstream probe. This does not authenticate to, or verify, the local PostgreSQL composite.
// php scripts/probe-github-context.php [https://github.com/owner/repo]
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/backend/src/GitHubContext.php';
if ($argc > 2) { fwrite(STDERR, "Usage: php scripts/probe-github-context.php [https://github.com/owner/repo]\n"); exit(2); }
$mode = getenv('OSIRIS_GITHUB_MODE', true);
if ($mode !== false && $mode !== '' && $mode !== 'online') {
    fwrite(STDERR, "Live probe requires OSIRIS_GITHUB_MODE=online or unset; fixture/offline responses are not live evidence.\n");
    exit(2);
}
$external = fetchGitHubRepository($argv[1] ?? 'https://github.com/php/php-src');
$ok = $external['provenance'] === 'live' && $external['source'] === 'GitHub REST API' && $external['status'] === 'ok';
$evidence = [
    'probe' => 'upstream only (not a PostgreSQL or browser integration check)',
    'observedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'source' => $external['source'], 'provenance' => $external['provenance'],
    'status' => $external['status'],
];
if ($ok) {
    $evidence['endpoint'] = 'https://api.github.com/repos/' . $external['repository']['fullName'];
    $evidence['httpStatus'] = 200;
    $evidence['repository'] = $external['repository']['url'];
    $evidence['stargazersCount'] = $external['repository']['stargazersCount'];
} else {
    $evidence['reason'] = $external['reason'] ?? 'repository_not_configured';
}
fwrite($ok ? STDOUT : STDERR, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
exit($ok ? 0 : 1);
