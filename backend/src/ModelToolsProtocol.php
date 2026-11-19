<?php

declare(strict_types=1);

/** Stateless translation between one Osiris turn and the provider's native wire format. */
final class ModelToolsProtocol
{
    private const MAX_CALLS = 4;

    public static function payload(string $provider, string $model, string $system, array $messages, array $tools, array $steps): array
    {
        $declarations = [];
        foreach ($tools as $tool) {
            $schema = (array) $tool['parameters'];
            if (isset($schema['properties'])) { $schema['properties'] = (object) $schema['properties']; }
            $definition = ['name' => $tool['name'], 'description' => $tool['description']];
            $declarations[] = match ($provider) {
                'openai' => ['type' => 'function', ...$definition, 'parameters' => (object) $schema, 'strict' => false],
                'anthropic' => [...$definition, 'input_schema' => (object) $schema],
                'ollama' => ['type' => 'function', 'function' => [...$definition, 'parameters' => (object) $schema]],
            };
        }
        $pastNames = [];
        foreach ($steps as $step) {
            $text = $step['text']; $calls = $step['calls']; $results = $step['results'];
            if (count($calls) !== count($results)) { throw new LogicException('Missing workspace tool result'); }
            if ($provider === 'openai') {
                if ($text !== '') { $messages[] = ['role' => 'assistant', 'content' => $text]; }
                foreach ($calls as $call) {
                    $messages[] = ['type' => 'function_call', 'call_id' => $call['id'], 'name' => $call['name'],
                        'arguments' => json_encode((object) $call['arguments'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
                }
                foreach ($calls as $i => $call) {
                    $messages[] = ['type' => 'function_call_output', 'call_id' => $call['id'], 'output' => $results[$i]];
                }
            } elseif ($provider === 'anthropic') {
                $blocks = $text === '' ? [] : [['type' => 'text', 'text' => $text]];
                $outputs = [];
                foreach ($calls as $i => $call) {
                    $blocks[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => (object) $call['arguments']];
                    $outputs[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => $results[$i]];
                    $pastNames[$call['name']] = true;
                }
                $messages[] = ['role' => 'assistant', 'content' => $blocks];
                if ($outputs) { $messages[] = ['role' => 'user', 'content' => $outputs]; }
            } else {
                $assistant = ['role' => 'assistant', 'content' => $text];
                foreach ($calls as $i => $call) {
                    $assistant['tool_calls'][] = ['id' => $call['id'], 'type' => 'function', 'function' => [
                        'index' => $i, 'name' => $call['name'], 'arguments' => (object) $call['arguments']]];
                }
                $messages[] = $assistant;
                foreach ($calls as $i => $call) {
                    $messages[] = ['role' => 'tool', 'tool_name' => $call['name'], 'tool_call_id' => $call['id'], 'content' => $results[$i]];
                }
            }
        }
        $body = match ($provider) {
            'openai' => ['model' => $model, 'instructions' => $system, 'input' => $messages, 'store' => false, 'max_output_tokens' => 2048],
            'anthropic' => ['model' => $model, 'system' => $system, 'messages' => $messages, 'max_tokens' => 2048],
            'ollama' => ['model' => $model, 'stream' => false, 'think' => false, 'options' => ['num_predict' => 2048],
                'messages' => [['role' => 'system', 'content' => $system], ...$messages]],
        };
        if (!$declarations && $pastNames) {
            // Messages requires definitions when replaying tool_use blocks, even in an answer-only turn.
            $body['tool_choice'] = ['type' => 'none'];
            foreach (array_keys($pastNames) as $name) {
                $declarations[] = ['name' => (string) $name, 'input_schema' => ['type' => 'object']];
            }
        }
        if ($declarations) { $body['tools'] = $declarations; }
        return $body;
    }

    public static function turn(string $provider, array $response, bool $allowCalls): array
    {
        $parts = []; $calls = [];
        switch ($provider) {
            case 'openai':
                if (($response['status'] ?? '') !== 'completed' || !is_array($response['output'] ?? null)) { throw self::invalid(); }
                foreach ($response['output'] as $item) {
                    $item = (array) $item;
                    if (($item['type'] ?? null) === 'message') {
                        if (!is_array($item['content'] ?? null)) { throw self::invalid(); }
                        foreach ($item['content'] as $block) {
                            $block = (array) $block;
                            if (($block['type'] ?? '') === 'output_text') { $parts[] = self::text($block['text'] ?? null); }
                        }
                    } elseif (($item['type'] ?? null) === 'function_call') {
                        if (($item['status'] ?? 'completed') !== 'completed' || !is_string($item['arguments'] ?? null)) { throw self::invalid(); }
                        try { $arguments = json_decode($item['arguments'], false, 32, JSON_THROW_ON_ERROR); }
                        catch (JsonException) { throw self::invalid(); }
                        self::addCall($calls, $allowCalls, $item['call_id'] ?? null, $item['name'] ?? null, $arguments);
                    }
                }
                break;
            case 'anthropic':
                $reason = $response['stop_reason'] ?? null;
                if (($response['type'] ?? '') !== 'message' || !is_array($response['content'] ?? null)
                    || !in_array($reason, [null, 'end_turn', 'stop_sequence', 'tool_use'], true)) { throw self::invalid(); }
                foreach ($response['content'] as $block) {
                    $block = (array) $block;
                    if (($block['type'] ?? '') === 'text') { $parts[] = self::text($block['text'] ?? null); }
                    elseif (($block['type'] ?? '') === 'tool_use') {
                        self::addCall($calls, $allowCalls, $block['id'] ?? null, $block['name'] ?? null, $block['input'] ?? null);
                    }
                }
                if (($reason === 'tool_use') !== (bool) $calls) { throw self::invalid(); }
                break;
            case 'ollama':
                $message = (array) ($response['message'] ?? []);
                if (($response['done'] ?? null) !== true || ($response['done_reason'] ?? 'stop') !== 'stop'
                    || ($message['role'] ?? 'assistant') !== 'assistant') { throw self::invalid(); }
                if (isset($message['content'])) { $parts[] = self::text($message['content']); }
                $nativeCalls = $message['tool_calls'] ?? [];
                if (!is_array($nativeCalls)) { throw self::invalid(); }
                foreach ($nativeCalls as $call) {
                    $call = (array) $call; $function = (array) ($call['function'] ?? []);
                    // Older Ollama versions correlate results by name and position, without emitting an id.
                    self::addCall($calls, $allowCalls, $call['id'] ?? 'call_' . bin2hex(random_bytes(12)),
                        $function['name'] ?? null, $function['arguments'] ?? null);
                }
                break;
            default: throw new LogicException('Unknown model protocol');
        }
        $text = implode("\n", $parts);
        if (!isRepresentableText($text) || (strlen($text) > 10000 && preg_match('/\A.{10001}/us', $text))) {
            throw new ApiError(502, 'AI answer was invalid or exceeded the 10000-character message limit', 'ai_upstream_failure');
        }
        if (trim($text) === '') { $text = ''; }
        if ($text === '' && !$calls) { throw new ApiError(502, 'AI provider returned no usable answer', 'ai_upstream_failure'); }
        return ['text' => $text, 'calls' => array_values($calls)];
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) { throw self::invalid(); }
        return $value;
    }

    private static function addCall(array &$calls, bool $allowed, mixed $id, mixed $name, mixed $arguments): void
    {
        if (!$allowed || count($calls) >= self::MAX_CALLS || !is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $id)
            || isset($calls[$id]) || !is_string($name) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $name)
            || !$arguments instanceof stdClass) { throw self::invalid(); }
        // normalizeInput rejects unsafe strings/keys and preserves nested empty objects.
        try { $args = normalizeInput($arguments); }
        catch (ApiError) { throw self::invalid(); }
        $calls[$id] = ['id' => $id, 'name' => $name, 'arguments' => $args];
    }

    private static function invalid(): ApiError
    {
        return new ApiError(502, 'AI provider returned an invalid or incomplete model turn', 'ai_upstream_failure');
    }
}
