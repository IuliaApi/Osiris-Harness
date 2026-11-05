# Osiris AI Terminal — November 5 extension

The interface is a **terminal-style chat**, not a shell. The redesigned dark dashboard and AI Settings page are included in `web/index.html`. The PHP backend integrates with real AI providers and stores successful conversation exchanges in PostgreSQL. No provider returns a synthetic fallback reply, no internal reasoning is exposed and no tools/commands are executed.

## How to use on Windows

1. Make sure Docker Desktop is running; from the extracted project folder run `Copy-Item .env.example .env` (if `.env` already exists, edit it rather than overwrite it).
2. For **OpenAI**, enter your own `OPENAI_API_KEY` in `.env`. For **Anthropic**, enter your own `ANTHROPIC_API_KEY`. For **Ollama**, install it on Windows, run `ollama pull llama3.2`, keep the service running and leave `OSIRIS_OLLAMA_ENABLED=1`. Docker sends requests to the Windows host on port 11434.
3. Keep `.env` private. Start or restart the application with `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\run.ps1` in the project directory (only run scripts you trust).
4. Open <http://127.0.0.1:8080>, choose **AI Settings**, select a provider and an available model, and save. The browser stores only the selected provider/model, **not keys**.
5. Open **AI Terminal**, select or create an active session, and submit a normal question. On success, check the response and reload to confirm both turns were saved in PostgreSQL. To use the actual command-line consumer with host PHP: `php scripts/osiris-cli.php ses_101 openai gpt-4.1-mini`.

The `GET /api/runtime` route reports configured providers, not verified live connectivity. Provider configuration and upstream success are separate states: a key may be wrong or a local Ollama model may not be downloaded. When a provider fails, the UI shows a sanitized error and neither chat turn is stored.

## HTTP contract

`POST /sessions/{sessionId}/chat` with `Content-Type: application/json`:

```json
{"message":"How can I test my PHP routes?","provider":"openai","model":"gpt-4.1-mini"}
```

`message` must be 1–6000 characters; `provider` is `openai`, `anthropic` or `ollama`; `model` is a validated model ID. Provider/model may be omitted to use server-side defaults. Responses contain `sessionId`, the persisted `userMessage` and `assistantMessage`, `provider`, and `model`. Request `Accept: application/json`, `application/xml`, or `application/yaml`. Invalid JSON/media types, invalid providers, missing sessions, archived sessions, missing credentials, upstream errors, and timeouts return structured errors with request IDs.

The backend uses at most 12 recent user/assistant messages and bounded project/session metadata. OpenAI uses the Responses API (`store: false`), Anthropic uses Messages API, and local Ollama uses its chat API (`stream: false`). Upstream URLs are fixed in the backend; the browser cannot supply arbitrary URLs. Third-party APIs may charge for requests and receive conversation content. Do not enter secrets into chat messages.

## Verification boundaries

Executed locally: PHP lint, provider status/response parser unit tests (`php scripts/test-ai-provider.php`), no-DB HTTP routing tests (`python scripts/test-http-no-db.py`), Node browser JS syntax check, representation fixtures and OpenAPI parsing. **Not executed here:** successful OpenAI/Anthropic/Ollama network calls, live PostgreSQL-backed chat, Docker regression tests and a Windows browser interaction walkthrough. Run `./test.ps1` on the student's Docker machine and record the results in `docs/demo-evidence.md`.

Authentication, authorization, rate limiting, cost controls, filesystem access, command permissions, tool sandboxing and autonomous agent execution are **Build 3** concerns. Do not deploy this unauthenticated local-only development API to a publicly reachable network.
