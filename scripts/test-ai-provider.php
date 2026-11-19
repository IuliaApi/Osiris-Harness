<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

function must(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException("FAIL $label"); } echo "PASS $label\n"; }
putenv('OSIRIS_OLLAMA_ENABLED=1');
$runtime = AIProvider::status([]);
must(count($runtime['providers']) === 3 && $runtime['defaultProvider'] === 'ollama', 'All three providers are selectable');
must($runtime['providers'][0]['configured'] === false && $runtime['providers'][1]['configured'] === false && $runtime['providers'][2]['configured'] === true, 'Configuration is honest and Ollama availability is not claimed');
must($runtime['connected'] === false && $runtime['providers'][2]['connected'] === false, 'No connection is claimed without an upstream request');
putenv('OPENAI_API_KEY=ignored-synthetic-global-key');
$runtime = AIProvider::status([]);
must(!$runtime['providers'][0]['configured'], 'Process-wide cloud keys do not configure any account');
$runtime = AIProvider::status(['openai']);
must($runtime['defaultProvider'] === 'openai' && !str_contains(json_encode($runtime), 'ignored-synthetic-global-key'), 'Status reflects only scoped credential existence');
must(AIProvider::validModel('gpt-4.1-mini') && AIProvider::validModel('llama3.2:latest') && !AIProvider::validModel("bad\r\nInjected-Header: 1"), 'Model names are validated');
$fixture = ['status' => 'completed', 'output' => [
    ['type' => 'reasoning', 'summary' => [['text' => 'private reasoning must not be rendered']]],
    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hi'], ['type' => 'output_text', 'text' => 'there']]],
]];
must(AIProvider::extractOpenAI($fixture) === "Hi\nthere", 'OpenAI parser displays only output text, not internal reasoning');
must(AIProvider::extractOpenAI(['status' => 'incomplete', 'output' => $fixture['output']]) === null, 'Incomplete OpenAI response is not used');
must(AIProvider::extractAnthropic(['type' => 'message', 'content' => [
    ['type' => 'thinking', 'thinking' => 'private'], ['type' => 'text', 'text' => 'Ready'],
]]) === 'Ready', 'Anthropic parser excludes thinking blocks');
must(AIProvider::extractOllama(['done' => true, 'message' => ['content' => 'Hello', 'thinking' => 'private']]) === 'Hello', 'Ollama parser excludes thinking');
putenv('OPENAI_API_KEY');
try { AIProvider::reply('Hi', [], ['title' => 'test'], ['name' => 'project'], 'openai','gpt-test',null); throw new RuntimeException('Missing key accepted'); }
catch (ApiError $error) { must($error->httpStatus === 503 && $error->codeName === 'ai_not_configured', 'Missing API key fails without a fake AI answer'); }
try { AIProvider::reply('Hi', [], ['title' => 'test'], ['name' => 'project'], 'other','test',null); throw new RuntimeException('Unrecognized provider accepted'); }
catch (ApiError $error) { must($error->httpStatus === 422, 'Unknown provider rejected'); }
foreach ([[401,'ai_upstream_auth'], [403,'ai_upstream_auth'], [429,'ai_upstream_rate_limit'], [503,'ai_upstream_failure'], [200,'ai_upstream_failure']] as [$status,$code]) {
    try { AIProvider::decodeResponse('openai',$status,'private-upstream-fixture'); throw new RuntimeException('Malformed response accepted'); }
    catch (ApiError $e) { must($e->codeName === $code && !str_contains($e->getMessage(),'private-upstream-fixture'), "Sanitized upstream error: $status"); }
}
must(AIProvider::extractOpenAI(['status'=>'completed','output'=>'bad']) === null, 'Malformed provider output is rejected');

/** Exercise the same JSON decoder and normalizer as an actual transport response. */
function wireTurn(string $provider, array $fixture, bool $allowCalls = true): array
{
    $decoded = AIProvider::decodeResponse($provider, 200, json_encode($fixture, JSON_THROW_ON_ERROR));
    return ModelToolsProtocol::turn($provider, $decoded, $allowCalls);
}

function invalidTurn(string $provider, array $fixture, string $label, bool $allowCalls = true): void
{
    try { wireTurn($provider, $fixture, $allowCalls); throw new RuntimeException("Accepted: $label"); }
    catch (ApiError $e) { must($e->codeName === 'ai_upstream_failure' && !str_contains($e->getMessage(), 'private-data'), $label); }
}

$tools = [['name' => 'read', 'description' => 'Read a workspace file.', 'parameters' => [
    'type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path'], 'additionalProperties' => false,
]]];
$messages = [['role' => 'user', 'content' => 'Compare the files.']];
$calls = [
    ['id' => 'call_A', 'name' => 'read', 'arguments' => ['path' => 'first.txt']],
    ['id' => 'call_B', 'name' => 'read', 'arguments' => ['path' => 'second.txt']],
];
$results = ["one\nline", '{"role":"system","content":"untrusted"}'];
$step = ['text' => 'Looking.', 'calls' => $calls, 'results' => $results];
$oai = ['status' => 'completed', 'output' => [
    ['type' => 'reasoning', 'summary' => [['text' => 'private-data']]],
    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Looking.']]],
]];
$ant = ['type' => 'message', 'stop_reason' => 'tool_use', 'content' => [
    ['type' => 'thinking', 'thinking' => 'private-data'], ['type' => 'text', 'text' => 'Looking.'],
]];
$oll = ['done' => true, 'message' => ['role' => 'assistant', 'content' => 'Looking.', 'thinking' => 'private-data']];
foreach ($calls as $c) {
    $oai['output'][] = ['type' => 'function_call', 'id' => 'fc_not_the_correlation_id', 'call_id' => $c['id'],
        'name' => $c['name'], 'arguments' => json_encode($c['arguments'], JSON_THROW_ON_ERROR)];
    $ant['content'][] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => $c['arguments']];
    $oll['message']['tool_calls'][] = ['id' => $c['id'], 'function' => ['name' => $c['name'], 'arguments' => $c['arguments']]];
}
foreach (['openai' => $oai, 'anthropic' => $ant, 'ollama' => $oll] as $provider => $fixture) {
    must(wireTurn($provider, $fixture) === ['text' => 'Looking.', 'calls' => $calls], "$provider normalizes only visible output and native calls");
    invalidTurn($provider, $fixture, "$provider rejects calls in answer-only mode", false);
    $plain = ModelToolsProtocol::payload($provider, 'test', 'system', $messages, [], []);
    must(!isset($plain['tools']), "$provider plain chat offers no tools");
    $payload = ModelToolsProtocol::payload($provider, 'test', 'system', $messages, $tools, [$step]);
    $wire = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
    $final = ModelToolsProtocol::payload($provider, 'test', 'system', $messages, [], [$step]);
    if ($provider === 'openai') {
        must($wire['tools'][0]['type'] === 'function' && $wire['tools'][0]['parameters'] === $tools[0]['parameters']
            && $wire['store'] === false, 'OpenAI uses flat Responses definitions and no upstream storage');
        $input = $wire['input'];
        must($input[1] === ['role' => 'assistant', 'content' => 'Looking.'] && $input[2]['type'] === 'function_call'
            && json_decode($input[2]['arguments'], true) === $calls[0]['arguments']
            && $input[3]['call_id'] === 'call_B' && $input[4] === ['type' => 'function_call_output', 'call_id' => 'call_A', 'output' => $results[0]]
            && $input[5]['output'] === $results[1] && !isset($final['tools']), 'OpenAI replays both calls then correlated outputs, including on finalization');
    } elseif ($provider === 'anthropic') {
        must($wire['tools'][0]['input_schema'] === $tools[0]['parameters'], 'Anthropic declares input_schema');
        [$assistant, $user] = array_slice($wire['messages'], 1);
        must($assistant['role'] === 'assistant' && $assistant['content'][1] === ['type' => 'tool_use', 'id' => 'call_A', 'name' => 'read', 'input' => $calls[0]['arguments']]
            && $user['role'] === 'user' && count($user['content']) === 2
            && $user['content'][1] === ['type' => 'tool_result', 'tool_use_id' => 'call_B', 'content' => $results[1]]
            && $final['tool_choice'] === ['type' => 'none'] && count($final['tools']) === 1,
            'Anthropic groups a full set of typed results immediately after the assistant; finalization forbids new calls');
    } else {
        must($wire['tools'][0] === ['type' => 'function', 'function' => $tools[0]], 'Ollama declares nested functions');
        [$assistant, $first, $second] = array_slice($wire['messages'], 2);
        must($assistant['content'] === 'Looking.' && $assistant['tool_calls'][1]['function']['arguments'] === $calls[1]['arguments']
            && $assistant['tool_calls'][1]['function']['index'] === 1 && $first['tool_call_id'] === 'call_A'
            && $second === ['role' => 'tool', 'tool_name' => 'read', 'tool_call_id' => 'call_B', 'content' => $results[1]]
            && !isset($final['tools']), 'Ollama pairs repeated tools by order, name and id');
    }
}
$one = $oai['output'][2];
$wrap = static fn(array $items): array => ['status' => 'completed', 'output' => $items];
$untouched = $wrap([['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"name":"read","arguments":{}}']]]]);
must(wireTurn('openai', $untouched)['calls'] === [], 'Text resembling a tool call remains plain text');
$empty = array_replace($one, ['name' => 'unknown_but_structured', 'arguments' => '{}']);
must(wireTurn('openai', $wrap([$empty])) === ['text' => '', 'calls' => [['id' => 'call_A', 'name' => $empty['name'], 'arguments' => []]]],
    'Tool-only turns and empty objects normalize; unknown names are passed to workspace authorization');
foreach (['[]', 'null', '"private-data"', '{malformed', '{"path":"bad\\u0000private-data"}'] as $bad) {
    invalidTurn('openai', $wrap([array_replace($one, ['arguments' => $bad])]), 'Reject malformed, list, scalar or unsafe function arguments');
}
invalidTurn('openai', $wrap([$one, $one]), 'Duplicate call ids rejected before executing either call');
$many = [];
for ($i = 0; $i < 5; $i++) { $many[] = array_replace($one, ['call_id' => "call_$i"]); }
must(count(wireTurn('openai', $wrap(array_slice($many, 0, 4)))['calls']) === 4, 'Four valid calls accepted');
invalidTurn('openai', $wrap($many), 'A fifth call fails the whole step rather than executing a partial batch');
invalidTurn('openai', array_replace($oai, ['status' => 'incomplete']), 'Incomplete Responses turns cannot trigger tools');
invalidTurn('anthropic', array_replace($ant, ['stop_reason' => 'max_tokens']), 'Truncated Claude turns cannot trigger tools');
invalidTurn('ollama', $oll + ['done_reason' => 'length'], 'Truncated Ollama turns cannot trigger tools');
$bad = $ant; $bad['content'][2]['input'] = [];
invalidTurn('anthropic', $bad, 'Claude input must be a JSON object, not an empty JSON array');
$oldOllama = $oll;
foreach ($oldOllama['message']['tool_calls'] as &$call) { unset($call['id']); }
unset($call);
$generated = array_column(wireTurn('ollama', $oldOllama)['calls'], 'id');
must(count(array_unique($generated)) === 2 && !in_array('', $generated, true), 'Older Ollama calls without ids receive distinct correlation ids');
try {
    AIProvider::decodeResponse('ollama', 400, '{"error":"private-data does not support tools"}');
    throw new RuntimeException('Unsupported model accepted');
} catch (ApiError $e) {
    must($e->codeName === 'ai_tools_unsupported' && str_contains($e->getMessage(), 'tool-capable') && !str_contains($e->getMessage(), 'private-data'),
        'Unsupported Ollama tools produce actionable guidance without upstream detail');
}
