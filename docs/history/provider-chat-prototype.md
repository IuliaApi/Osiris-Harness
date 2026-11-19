# Provider chat prototype

The November 5, 2026 terminal-style chat interface called OpenAI, Anthropic or Ollama from PHP. Its provider/model preference lived in the browser; cloud keys came from the server environment. The prototype did not authenticate users or run tools. See the [operator guide](../../README.md) for the current per-user credential and approval model.

The browser and PHP CLI sent a message and an optional provider/model to `POST /sessions/{sessionId}/chat`. The backend used up to 12 recent conversation messages and bounded project/session metadata. It stored a pair of turns only on a successful upstream response. `GET /api/runtime` reported provider configuration, which did not verify a connection.

The prototype record reported PHP lint, provider parser tests, HTTP routing checks without a database, a browser JavaScript syntax check, representation fixtures and OpenAPI parsing. It did not report a successful live provider request, a PostgreSQL-backed chat exchange, a Docker regression run or a Windows browser walkthrough. The [November 19 record](workspace-run-b170cfe70c.md) documents a later Ollama run on a different revision.
