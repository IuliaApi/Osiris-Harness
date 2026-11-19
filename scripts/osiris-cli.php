<?php

declare(strict_types=1);

/**
 * Osiris CLI: real HTTP consumer of the same PostgreSQL-backed chat endpoint.
 * php scripts/osiris-cli.php ses_101 [provider] [model]
 * Set OSIRIS_USERNAME and OSIRIS_PASSWORD in this process's environment.
 */
require_once __DIR__ . '/LocalClient.php';
$sessionId = $argv[1] ?? 'ses_101';
$selected = $argv[2] ?? null;
$model = $argv[3] ?? null;
if (!preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $sessionId)) {
    fwrite(STDERR, "Usage: php scripts/osiris-cli.php SESSION_ID [PROVIDER] [MODEL]\n");
    exit(2);
}
$chat = static function (LocalClient $client) use ($sessionId, $selected, $model): void {
    $runtime = $client->follow($client->root, 'runtime');
    $session = $client->session($sessionId);
    if (($session['_links']['chat']['method'] ?? null) !== 'POST') { throw new RuntimeException('Chat is not available for this session and account.'); }
    $providers = array_column($runtime['ai']['providers'], null, 'id');
    $selected ??= $runtime['ai']['defaultProvider'];
    if (!isset($providers[$selected])) { throw new RuntimeException('Available providers: ' . implode(', ',array_keys($providers))); }
    $model ??= $providers[$selected]['defaultModel'];
    echo "\nOSIRIS — AI chat terminal (client of the PHP API)\n";
    echo "Session: {$session['title']} / {$session['id']}\n";
    echo "Provider: $selected / $model\n";
    echo "Type /help for commands. Chat uses real providers only.\n\n";
    while (true) {
        echo 'osiris> ';
        $line = fgets(STDIN);
        if ($line === false) { echo "\n"; break; }
        $text = trim($line);
        if (in_array($text, ['/exit', '/quit'], true)) { break; }
        if ($text === '/help') { echo "Chat text or /provider " . implode('|',array_keys($providers)) . ", /model MODEL, /status, /exit.\n"; continue; }
        if ($text === '/status') { echo "Provider: $selected / $model; configured: " . ($providers[$selected]['configured'] ? 'yes (connection unverified)' : 'no') . "\n"; continue; }
        if (str_starts_with($text, '/provider ')) {
            $choice = trim(substr($text, 10));
            if (!isset($providers[$choice])) { echo "Available providers: " . implode(', ',array_keys($providers)) . "\n"; continue; }
            $selected = $choice; $model = $providers[$choice]['defaultModel'];
            echo "Selected $selected / $model\n"; continue;
        }
        if (str_starts_with($text, '/model ')) {
            $newModel = trim(substr($text, 7));
            // The API enforces the provider-specific model grammar and eligibility.
            if ($newModel === '') { echo "Use /model MODEL.\n"; continue; }
            $model = $newModel; echo "Model: $model\n"; continue;
        }
        if ($text === '') { continue; }
        if (str_starts_with($text, '/')) { echo "Unknown command. Type /help.\n"; continue; }
        if ($model === '') { echo "Use /model MODEL to select a model for $selected first.\n"; continue; }
        try {
            $result = $client->follow($session, 'chat', 'POST',
                ['message' => $text, 'provider' => $selected, 'model' => $model]);
            echo "\nOSIRIS [{$result['provider']} / {$result['model']}]\n";
            echo ($result['assistantMessage']['content'] ?? 'Empty reply') . "\n\n";
        } catch (Throwable $error) { echo "ERROR: {$error->getMessage()}\n\n"; }
    }
};
try {
    LocalClient::authenticated($chat);
} catch (Throwable $error) {
    fwrite(STDERR, "Cannot start Osiris CLI: {$error->getMessage()}\n");
    exit(1);
}
