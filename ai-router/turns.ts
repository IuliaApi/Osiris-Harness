import type { Api, AssistantMessage, Context, Model, Tool, ToolCall } from '@oh-my-pi/pi-ai';

export class RouteError extends Error {
  constructor(public status: number, public code: string, message: string) { super(message); }
}
export const invalidRequest = () => new RouteError(422, 'validation_error', 'Invalid router request');
export const invalidTurn = () => new RouteError(502, 'ai_upstream_failure', 'AI provider returned an invalid or incomplete model turn');
export function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
export function fields(value: unknown, allowed: string[]): value is Record<string, unknown> {
  return record(value) && Object.keys(value).every(key => allowed.includes(key));
}
const unsupported = /[^\u0009\u000a\u000d\u0020-\ud7ff\ue000-\ufffd\u{10000}-\u{10ffff}]/u;
export function text(value: unknown, limit: number): value is string {
  return typeof value === 'string' && value.length <= 2 * limit && !unsupported.test(value)
    && (value.length <= limit || [...value].length <= limit);
}
const namePattern = /^[a-zA-Z0-9_-]{1,64}$/;
function jsonValue(value: unknown, depth = 1): boolean {
  if (depth > 32) return false;
  if (Array.isArray(value)) return value.every(item => jsonValue(item, depth + 1));
  if (record(value)) return Object.entries(value).every(([key, item]) => /^[A-Za-z_][A-Za-z0-9_.-]*$/.test(key)
    && !['__proto__', 'prototype', 'constructor'].includes(key) && jsonValue(item, depth + 1));
  return value === null || typeof value === 'boolean' || (typeof value === 'number' && Number.isFinite(value))
    || (typeof value === 'string' && !unsupported.test(value));
}
type Call = Pick<ToolCall, 'id' | 'name' | 'arguments'>;
function call(value: unknown, ids: Set<string>, error: () => Error): Call {
  if (!record(value) || typeof value.id !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(value.id) || ids.has(value.id)
    || typeof value.name !== 'string' || !namePattern.test(value.name) || !record(value.arguments)
    || Object.hasOwn(value.arguments, '__parseError') || !jsonValue(value.arguments)) throw error();
  ids.add(value.id);
  return {id: value.id, name: value.name, arguments: value.arguments};
}
const usage = {input: 0, output: 0, cacheRead: 0, cacheWrite: 0, totalTokens: 0,
  cost: {input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total: 0}};

export function conversation(body: Record<string, unknown>, model: Model<Api>) {
  const {system, messages, tools = [], steps = []} = body;
  if (!text(system, 5000) || !Array.isArray(messages) || !messages.length || messages.length > 13
    || !Array.isArray(tools) || tools.length > 5 || !Array.isArray(steps) || steps.length > 4
    || (steps.length === 4 && tools.length)) throw invalidRequest();
  const context: Context = {systemPrompt: [system], messages: []};
  const ids = new Set<string>(), names = new Set<string>();
  const timestamp = Date.now();
  const assistant = (content: AssistantMessage['content'], stopReason: 'stop' | 'toolUse'): AssistantMessage =>
    ({role: 'assistant', api: model.api, model: model.id, provider: model.provider, usage, timestamp, content, stopReason});
  for (const msg of messages) {
    if (!fields(msg, ['role', 'content']) || !text(msg.content, 6000)) throw invalidRequest();
    if (msg.role === 'assistant') context.messages.push(assistant([{type: 'text', text: msg.content}], 'stop'));
    else if (msg.role === 'user') context.messages.push({role: 'user', content: msg.content, timestamp});
    else throw invalidRequest();
  }
  if (context.messages.at(-1)?.role !== 'user') throw invalidRequest();
  for (const tool of tools) {
    if (!fields(tool, ['name', 'description', 'parameters']) || typeof tool.name !== 'string' || !namePattern.test(tool.name)
      || names.has(tool.name) || !text(tool.description, 1024) || !record(tool.parameters) || tool.parameters.type !== 'object'
      || !record(tool.parameters.properties) || !jsonValue(tool.parameters)) throw invalidRequest();
    names.add(tool.name);
    (context.tools ??= []).push({name: tool.name, description: tool.description, parameters: tool.parameters as Tool['parameters'], strict: false});
  }
  const replayNames = new Set<string>();
  for (const step of steps) {
    if (!fields(step, ['text', 'calls', 'results']) || !text(step.text, 10000) || !Array.isArray(step.calls)
      || !step.calls.length || step.calls.length > 4 || !Array.isArray(step.results) || step.results.length !== step.calls.length) throw invalidRequest();
    const calls = step.calls.map(c => call(c, ids, invalidRequest));
    const content: AssistantMessage['content'] = step.text ? [{type: 'text', text: step.text}] : [];
    for (const c of calls) { content.push({type: 'toolCall', ...c}); replayNames.add(c.name); }
    context.messages.push(assistant(content, 'toolUse'));
    for (const [i, c] of calls.entries()) {
      const output = step.results[i];
      if (!text(output, 3000)) throw invalidRequest();
      context.messages.push({role: 'toolResult', toolCallId: c.id, toolName: c.name, content: [{type: 'text', text: output}], isError: output.startsWith('Error: '), timestamp});
    }
  }
  // Claude requires definitions for tool_use blocks even on the answer-only round.
  if (model.api === 'anthropic-messages' && !tools.length && replayNames.size) {
    context.tools = [...replayNames].map(name => ({name, description: '', parameters: {type: 'object', properties: {}} as Tool['parameters']}));
  }
  return {context, ids, allowCalls: tools.length !== 0};
}

export function finish(blocks: AssistantMessage['content'], reason: string, ids: Set<string>, allowCalls: boolean, key?: string) {
  if (!Array.isArray(blocks) || (reason !== 'stop' && reason !== 'toolUse')) throw invalidTurn();
  const parts: string[] = [], calls: Call[] = [];
  for (const block of blocks) {
    if (!block) throw invalidTurn();
    if (block.type === 'text') {
      if (!text(block.text, 10000)) throw invalidTurn();
      parts.push(block.text);
    } else if (block.type === 'toolCall') {
      if (!allowCalls || calls.length === 4 || block.rawBlock !== undefined || block.customWireName !== undefined) throw invalidTurn();
      calls.push(call(block, ids, invalidTurn));
    }
  }
  let answer = parts.join('\n');
  if (!text(answer, 10000) || (reason === 'toolUse') !== Boolean(calls.length)) throw invalidTurn();
  if (!answer.trim()) answer = '';
  if (!answer && !calls.length) throw new RouteError(502, 'ai_upstream_failure', 'AI provider returned no usable text answer');
  if (key && (answer.includes(key) || calls.some(c => c.id.includes(key) || c.name.includes(key)
    || JSON.stringify(c.arguments).includes(JSON.stringify(key).slice(1, -1))))) throw invalidTurn();
  return {text: answer, calls};
}
