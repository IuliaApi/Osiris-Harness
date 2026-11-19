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
