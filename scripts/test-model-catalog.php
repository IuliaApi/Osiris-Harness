<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

function check(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo "PASS $label\n";
}

function fixtureKey(string $id): ?string
{
    return $id === 'ollama' ? null : "fixture-$id-private";
}

/** Each response round uses the same HTTP-code and raw-body boundary as the real transport. */
function catalogFixture(array $rounds, ?callable $credential = null): array
{
    $seen = [];
    $catalog = ModelCatalog::discover($credential ?? fixtureKey(...), static function (array $requests, int $timeout) use (&$seen, &$rounds): array {
        if (!$rounds || $timeout <= 0 || $timeout > 2500) { throw new RuntimeException('Unexpected catalog transfer'); }
        $seen[] = $requests;
        return array_shift($rounds);
    });
    if ($rounds || array_keys($catalog) !== ['providers'] || !array_is_list($catalog['providers'])) {
        throw new RuntimeException('Incomplete inventory or unexpected envelope');
    }
    return [array_column($catalog['providers'], null, 'id'), $seen];
}

function page(array $fields): array { return [200, json_encode($fields, JSON_THROW_ON_ERROR)]; }

$first = [
    'openai' => page(['data' => array_map(static fn($id) => ['id' => $id], [
        'o3', 'dall-e-3', 'text-embedding-3-small', 'gpt-4o-audio-preview', 'gpt-realtime', 'o3', 'gpt-4.1-mini',
        'gpt-4o-mini-tts', 'omni-moderation-latest', 'davinci-002', 'gpt-image-1', 'sora-2', 'whisper-1',
    ])]),
    'anthropic' => page(['data' => [['id' => 'claude-z']], 'has_more' => true, 'last_id' => 'cursor:1/2']),
    'ollama' => page(['models' => [
        ['name' => 'qwen3:latest'], ['name' => 'nomic-embed-text:latest'], ['name' => 'qwen3:latest'],
        ['model' => 'org/llama:7b'], ['name' => 'opaque:1', 'details' => ['family' => 'bert']],
        ['name' => 'vector', 'capabilities' => ['embedding']], ['name' => 'invalid model'], ['name' => '123'],
    ]]),
];
[$providers, $seen] = catalogFixture([$first, ['anthropic' => page(['has_more' => false, 'data' => [['id' => 'claude-a'], ['id' => 'claude-z']]])]]);
check(array_keys($providers) === ['openai', 'anthropic', 'ollama'] && array_column($providers, 'status') === array_fill(0, 3, 'available'), 'Stable provider grouping and status');
check($seen[0] === [
    'openai' => ['https://api.openai.com/v1/models', ['Authorization: Bearer ' . fixtureKey('openai')]],
    'anthropic' => ['https://api.anthropic.com/v1/models?limit=100', ['x-api-key: fixture-anthropic-private', 'anthropic-version: 2023-06-01']],
    'ollama' => ['http://host.docker.internal:11434/api/tags', []],
], 'First round queries all configured providers using only their own headers');
check($seen[1] === ['anthropic' => ['https://api.anthropic.com/v1/models?limit=100&after_id=cursor%3A1%2F2', $seen[0]['anthropic'][1]]]
    && $providers['anthropic']['models'] === ['claude-a', 'claude-z'], 'Follow encoded Anthropic cursor, then deduplicate and sort across pages');
check($providers['openai']['models'] === ['gpt-4.1-mini', 'o3'] && $providers['ollama']['models'] === ['123', 'org/llama:7b', 'qwen3:latest'],
    'Chat-compatible IDs returned in lexical order as strings; no inferred defaults');

foreach ([
    'missing transfer' => [],
    'HTTP authorization error' => ['anthropic' => [401, 'fixture-anthropic-private']],
    'HTTP rate limit' => ['anthropic' => [429, 'quota']],
    'redirect' => ['anthropic' => [302, '']],
    'malformed JSON' => ['anthropic' => [200, '{']],
    'error payload' => ['anthropic' => page(['error' => ['message' => 'private-upstream-detail'], 'data' => [], 'has_more' => false])],
    'invalid data type' => ['anthropic' => [200, '{"has_more":false,"data":{}}']],
    'invalid record' => ['anthropic' => page(['has_more' => false, 'data' => [['id' => 'good'], ['id' => 17]]])],
    'untyped pagination' => ['anthropic' => page(['has_more' => 'true', 'data' => []])],
    'missing cursor' => ['anthropic' => page(['has_more' => true, 'data' => [['id' => 'claude-b']]])],
    'repeated cursor' => ['anthropic' => $first['anthropic']],
    'oversized response' => ['anthropic' => [200, str_repeat(' ', 524288) . '{"has_more":false,"data":[]}']],
    'credential echoed as ID' => ['anthropic' => page(['has_more' => false, 'data' => [['id' => 'claude-fixture-anthropic-private']]])],
    'credential echoed as cursor' => ['anthropic' => page(['has_more' => true, 'last_id' => 'fixture-anthropic-private', 'data' => [['id' => 'claude-b']]])],
] as $label => $second) {
    [$p] = catalogFixture([$first, $second]);
    check($p['anthropic']['status'] === 'unavailable' && $p['anthropic']['models'] === []
        && $p['ollama'] === $providers['ollama'] && $p['openai'] === $providers['openai']
        && !str_contains(json_encode($p), 'private'), "$label: discard partial list, preserve healthy providers, never expose upstream details");
}

putenv('OPENAI_API_KEY=ignored-global-credential');
putenv('OSIRIS_OLLAMA_ENABLED=0');
[$p] = catalogFixture([], static function (string $id): ?string {
    if ($id === 'ollama') {
        // A disabled local provider never touches the database or the transport.
        $unused = new class extends PDO { public function __construct() {} };
        return SessionChat::credential($unused, 'test-actor', $id);
    }
    throw new ApiError(503, 'fixture', $id === 'openai' ? 'ai_not_configured' : 'vault_unavailable');
});
check(array_column($p, 'status') === ['not_configured', 'unavailable', 'not_configured'] && array_column($p, 'models') === [[], [], []],
    'Missing account key, unreadable account key, and disabled local provider never trigger transfers');
putenv('OPENAI_API_KEY');
putenv('OSIRIS_OLLAMA_ENABLED');

$records = [];
for ($i = 0; $i < 2049; $i++) { $records[] = ['id' => 'gpt-' . $i]; }
[$p] = catalogFixture([['openai' => page(['data' => $records]), 'ollama' => page(['models' => []])]]);
check(count($p['openai']['models']) === 2048 && $p['openai']['truncated'] === true && $p['openai']['status'] === 'available', 'Catalog record cap marks a partial inventory');
check($p['ollama']['models'] === [] && $p['ollama']['status'] === 'available' && !isset($p['ollama']['truncated']), 'Successful empty local inventory stays empty');
$rounds = [];
for ($i = 1; $i <= 20; $i++) { $rounds[] = ['anthropic' => page(['has_more' => true, 'last_id' => "cursor-$i", 'data' => [['id' => "claude-$i"]]])]; }
[$p] = catalogFixture($rounds);
check(count($p['anthropic']['models']) === 20 && $p['anthropic']['truncated'] === true, 'Endless pagination stops at the page cap');
