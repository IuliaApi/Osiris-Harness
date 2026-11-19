<?php

declare(strict_types=1);

require_once __DIR__ . '/LocalClient.php';

$args = array_slice($argv, 1);
$requireLive = in_array('--require-live', $args, true);
$ids = array_values(array_filter($args, static fn($v) => $v !== '--require-live'));
if (count($ids) > 1) { fwrite(STDERR, "Usage: php scripts/context-consumer.php [SESSION_ID] [--require-live]\n"); exit(2); }
try {
    LocalClient::authenticated(static function (LocalClient $client) use ($ids, $requireLive): void {
        $session = $client->session($ids[0] ?? 'ses_101');
        $data = $client->follow($session, 'context');
        $external = $data['external'];
        echo 'Session: ' . $data['session']['title'] . "\n";
        echo $data['sources']['local'] . ' project: ' . ($data['project']['name'] ?? '(none)') . "\n";
        echo 'Messages on first page: ' . count($data['messages']['items']) . "\n";
        echo 'Tasks on first page: ' . count($data['tasks']['items']) . "\n";
        echo "External: {$external['source']}; provenance: {$external['provenance']}; status: {$external['status']}\n";
        if (isset($external['reason'])) { echo 'Partial context: ' . $external['reason'] . "\n"; }
        if (isset($external['repository']['fullName'])) { echo 'Repository: ' . $external['repository']['fullName'] . "\n"; }
        if ($requireLive && ($data['sources']['local'] !== 'PostgreSQL' || $data['sources']['external'] !== 'GitHub REST API'
            || $external['source'] !== 'GitHub REST API' || $external['provenance'] !== 'live' || $external['status'] !== 'ok')) {
            throw new RuntimeException('Live PostgreSQL + GitHub evidence was required but not returned.');
        }
    });
} catch (Throwable $e) {
    fwrite(STDERR, "Context unavailable: {$e->getMessage()}\n"); exit(1);
}
