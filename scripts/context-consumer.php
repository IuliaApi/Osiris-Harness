<?php

declare(strict_types=1);

// CLI PHP consumer: run `php scripts/context-consumer.php ses_101` while the local API is running.
$id = $argv[1] ?? 'ses_101';
$port = getenv('OSIRIS_PORT') ?: '8080';
if (!preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $id) || !preg_match('/\A[0-9]{2,5}\z/D', $port) || (int) $port > 65535) {
    fwrite(STDERR, "Invalid session ID or port\n"); exit(2);
}
$context = stream_context_create(['http' => [
    'method' => 'GET', 'header' => "Accept: application/json\r\n", 'timeout' => 4,
    'ignore_errors' => true, 'follow_location' => 0,
]]);
$url = 'http://127.0.0.1:' . $port . '/sessions/' . rawurlencode($id) . '/context';
$raw = @file_get_contents($url, false, $context);
if ($raw === false) { fwrite(STDERR, "API unavailable\n"); exit(1); }
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['session'])) { fwrite(STDERR, "API error: " . substr($raw, 0, 300) . "\n"); exit(1); }
echo 'Session: ' . $data['session']['title'] . "\n";
echo 'PostgreSQL project: ' . $data['project']['name'] . "\n";
echo 'Messages on first page: ' . count($data['messages']['items']) . "\n";
echo 'Tasks on first page: ' . count($data['tasks']['items']) . "\n";
echo 'GitHub service status: ' . $data['external']['status'] . "\n";
if (isset($data['external']['reason'])) { echo 'Failure policy: partial context (' . $data['external']['reason'] . ")\n"; }
if (isset($data['external']['repository']['fullName'])) { echo 'Repository: ' . $data['external']['repository']['fullName'] . "\n"; }
