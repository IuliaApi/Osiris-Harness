import type { ToolCall } from '@oh-my-pi/pi-ai';
import { validModel } from './catalog';
import { invalidTurn, record } from './turns';

function argumentsObject(value: unknown): Record<string, unknown> {
  if (typeof value === 'string') {
    try { value = JSON.parse(value); } catch { throw invalidTurn(); }
  }
  if (!record(value)) throw invalidTurn();
  return value;
}
function nativeCall(id: unknown, name: unknown, args: unknown): ToolCall {
  if (typeof id !== 'string' || typeof name !== 'string') throw invalidTurn();
  return {type: 'toolCall', id, name, arguments: argumentsObject(args)};
}
function* events(raw: string) {
  for (const frame of raw.split(/\r?\n\r?\n/)) {
    const data = frame.split(/\r?\n/).filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n');
    if (data && data !== '[DONE]') yield JSON.parse(data);
  }
}

// Multiplexed SDK gateways forward one of several native SSE envelopes. Google
// also exposes the version on the wire but not on the normalized SDK message.
export function gatewayEventModel(api: string, data: string): string | undefined {
  if (data === '[DONE]') return;
  let event;
  try { event = JSON.parse(data); } catch { return; }
  if (!event) return;
  let id;
  if (api === 'google-gemini-cli') id = event.response?.modelVersion;
  else if (api === 'google-generative-ai') id = event.modelVersion;
  else if (event.type === 'response.created' || event.type === 'response.completed') id = event.response?.model;
  else if (event.type === 'message_start') id = event.message?.model;
  else if (Array.isArray(event.choices) && event.choices.length) id = event.model;
  else if (api === 'factory-droid-agent') id = event.modelVersion;
  return validModel(id) ? id : undefined;
}

// pi-ai repairs malformed JSON, can heal text into synthetic calls, and mints
// provider-specific IDs. Never execute a call without a completed, typed native
// envelope. Replay the provider's arguments/ID, not the SDK's repaired preview.
export function nativeTurn(api: string, raw: string) {
  let model: string | undefined;
  const calls: ToolCall[] = [];
  if (api === 'ollama-chat') {
    let done = false;
    for (const line of raw.split('\n')) {
      if (!line.trim()) continue;
      const chunk = JSON.parse(line);
      if (done || chunk.error || chunk.message?.role !== 'assistant') throw invalidTurn();
      if (validModel(chunk.model)) model = chunk.model;
      for (const c of chunk.message.tool_calls ?? []) {
        if (!record(c.function?.arguments)) throw invalidTurn();
        calls.push(nativeCall(c.id ?? `call_${crypto.randomUUID()}`, c.function.name, c.function.arguments));
      }
      if (chunk.done) {
        if (chunk.done !== true || ![undefined, 'stop', 'tool_calls'].includes(chunk.done_reason)) throw invalidTurn();
        done = true;
      }
    }
    if (!done) throw invalidTurn();
  } else if (api === 'openai-responses' || api === 'openai-codex-responses') {
    let terminal: unknown[] | undefined;
    const finished = [];
    for (const e of events(raw)) {
      if (e.type === 'response.created' && validModel(e.response?.model)) model = e.response.model;
      if (e.type === 'response.output_item.done' && e.item?.type === 'function_call') finished.push(e.item);
      if (e.type === 'response.completed' && e.response?.status === 'completed' && Array.isArray(e.response.output)) {
        terminal = e.response.output;
        if (validModel(e.response.model)) model = e.response.model;
      }
      if (e.type === 'response.incomplete' || e.type === 'response.failed' || e.type === 'error') throw invalidTurn();
    }
    if (!terminal) throw invalidTurn();
    const finalCalls = terminal.filter(record).filter(item => item.type === 'function_call');
    for (const item of finalCalls.length ? finalCalls : finished) {
      if ((item.status !== undefined && item.status !== 'completed') || typeof item.arguments !== 'string') throw invalidTurn();
      calls.push(nativeCall(item.call_id, item.name, item.arguments));
    }
  } else if (api === 'openai-completions') {
    let reason: string | undefined;
    const fragments = new Map<number, {id: string; name: string; args: string}>();
    for (const e of events(raw)) {
      if (e.error || !Array.isArray(e.choices) || e.choices.length > 1) throw invalidTurn();
      const choice = e.choices[0];
      if (!choice) continue; // Stream usage trailer.
      if (reason || choice.index !== 0) throw invalidTurn();
      if (validModel(e.model)) model = e.model;
      for (const c of choice.delta?.tool_calls ?? []) {
        if (!Number.isInteger(c.index) || c.index < 0 || c.index > 3 || (c.type && c.type !== 'function')) throw invalidTurn();
        let f = fragments.get(c.index);
        if (!f) { f = {id: '', name: '', args: ''}; fragments.set(c.index, f); }
        if (c.id !== undefined) { if (typeof c.id !== 'string' || (f.id && c.id !== f.id)) throw invalidTurn(); f.id = c.id; }
        if (c.function?.name !== undefined) { if (typeof c.function.name !== 'string') throw invalidTurn(); f.name += c.function.name; }
        if (c.function?.arguments !== undefined) { if (typeof c.function.arguments !== 'string') throw invalidTurn(); f.args += c.function.arguments; }
      }
      if (choice.finish_reason != null) reason = choice.finish_reason;
    }
    if (reason !== (fragments.size ? 'tool_calls' : 'stop')) throw invalidTurn();
    for (let i = 0; i < fragments.size; i++) {
      const f = fragments.get(i);
      if (!f) throw invalidTurn();
      calls.push(nativeCall(f.id, f.name, f.args));
    }
  } else {
    let done = false, reason: string | undefined;
    const pending = new Map<number, {id: string; name: string; input: unknown; json: string}>();
    for (const e of events(raw)) {
      if (e.type === 'error' || done) throw invalidTurn();
      if (e.type === 'message_start' && validModel(e.message?.model)) model = e.message.model;
      if (e.type === 'content_block_start' && e.content_block?.type === 'tool_use') {
        if (pending.has(e.index) || !record(e.content_block.input)) throw invalidTurn();
        pending.set(e.index, {id: e.content_block.id, name: e.content_block.name, input: e.content_block.input, json: ''});
      } else if (e.type === 'content_block_delta' && e.delta?.type === 'input_json_delta' && pending.has(e.index)) {
        if (typeof e.delta.partial_json !== 'string') throw invalidTurn();
        pending.get(e.index)!.json += e.delta.partial_json;
      } else if (e.type === 'content_block_stop' && pending.has(e.index)) {
        const b = pending.get(e.index)!;
        calls.push(nativeCall(b.id, b.name, b.json || b.input));
        pending.delete(e.index);
      } else if (e.type === 'message_delta') reason = e.delta?.stop_reason;
      else if (e.type === 'message_stop') done = true;
    }
    if (!done || pending.size || !['end_turn', 'stop_sequence', 'tool_use'].includes(reason ?? '') || (reason === 'tool_use') !== Boolean(calls.length)) throw invalidTurn();
  }
  return {calls, model};
}


