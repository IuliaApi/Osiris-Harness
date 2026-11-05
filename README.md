> **UI update (Build 2):** The lightweight Linear-inspired browser workspace is at `/login`, `/dashboard`, `/workspace` and `/chat`. See [docs/simple-ui.md](docs/simple-ui.md). The browser login is *demo-only*, not a real ChatGPT account sign-in; API authentication is still planned for Build 3. Chat calls only OpenAI on the backend and requires an OpenAI API key.

# Osiris AI Harness — Build 2

A local, **PostgreSQL-backed AI coding-harness workspace** built on Build 1. Projects hold coding sessions; sessions hold conversation messages and tasks. Build 2 persists and organizes state, and the November 5 workspace extension adds a modern dark **Overview**, interactive **AI Terminal**, **AI Settings**, and real server-side model adapters for OpenAI, Anthropic Claude and local Ollama. No response is simulated. The terminal is a chat interface, *not* an operating-system shell. Authentication and filesystem tool execution are not included; keep the development app bound to localhost.
## Start

Requires Docker Desktop, Docker Compose and PowerShell. From the project root:

```powershell
.\run.ps1
```

Open <http://127.0.0.1:8080/>. `run.ps1` launches PostgreSQL and runs the additive `database/build2.sql` migration on the **existing local database without dropping rows**. The five canonical sessions are only seeded if absent. As in Build 1, use `.\run.ps1 -EmptyDemo -Port 8081` for a separate blank database; its new tables are created without deleting existing data. Existing databases created from Build 1 remain supported.

`database/schema.sql` creates all five tables for fresh fixtures. `database/build2.sql` upgrades the original three-table database. The migration is repeatable. Foreign keys prevent deleting a project with sessions or a session with messages/tasks; these requests return `409`, not silent cascades. `POST` / `PATCH` write to PostgreSQL, and database read-only mode from Build 1 is removed.

## AI Terminal and AI Settings (November 5 extension)

The **Overview**, **AI Terminal**, **Sessions**, **Projects**, **API Explorer** and **AI Settings** pages are available at <http://127.0.0.1:8080/>. The terminal accepts normal chat and local *UI commands* (`/help`, `/sessions`, `/new`, `/task`, `/context`, `/status`, `/clear`). It does not execute shell commands, inspect local files or edit code. Successful chat exchanges are stored together in PostgreSQL; unsuccessful upstream requests save neither turn. The PHP CLI consumer uses the same API.

**Set up a real model:** copy the example config to an ignored local `.env` file, then enter **your own** credential for a provider you want to use. Never share or commit keys:

```powershell
Copy-Item .env.example .env
notepad .env
```

| Provider | Server-side setup | Default model | API |
| --- | --- | --- | --- |
| OpenAI | `OPENAI_API_KEY=...` | `gpt-4.1-mini` | OpenAI Responses API |
| Anthropic Claude | `ANTHROPIC_API_KEY=...` | `claude-haiku-5-5` | Anthropic Messages API |
| Ollama local | Install Ollama for Windows, run `ollama pull llama3.2`, keep it running | `llama3.2` | `http://host.docker.internal:11434/api/chat` from the container |

Restart the app after editing `.env` (stop the server with Ctrl+C, then `./run.ps1`). Open **AI Settings** to select a provider and model; only that preference is stored in the browser, while keys stay in the PHP container. `/api/runtime` reports the three providers and whether configured, but **does not prove a network connection**. The first successful chat proves the integration; wrong keys, absent models, timeouts and upstream errors return safe HTTP errors, never fake text. Check provider access and costs before sending prompts.

Chat endpoint: `POST /sessions/{sessionId}/chat` with JSON `{"message":"Explain this error", "provider":"openai", "model":"gpt-4.1-mini"}` (provider and model optional). It returns saved `userMessage` and `assistantMessage`, `provider` and `model`. Output is negotiated through Accept: JSON/XML/YAML; input must be JSON. Chat uses at most 12 recent turns and a limited session/project context. No internal reasoning is displayed or stored. Provider requests are bounded, with timeouts and safe errors.

CLI alternative (PHP installed on Windows): `php scripts/osiris-cli.php ses_101 openai gpt-4.1-mini`. Change providers in the CLI with `/provider anthropic` or `/provider ollama`; choose models with `/model MODEL`. This is a network client to Osiris, not a real operating-system shell.

**Security and course boundary:** this is a development-only local prototype. It does not yet authenticate users, authorize access to sessions, limit spending, edit repositories, run shell commands or implement a tool sandbox. Keep it on localhost. Build 3 must add identity, permission checks and execution approvals before any file or command tools are implemented. The chat extension was added **after the October 29 Build 2 deadline**, not evidence of original on-time submission.

## API operations

| Resource | Methods | Path |
| --- | --- | --- |
| Projects | GET, POST | `/projects` |
| Project | GET, PATCH, DELETE | `/projects/{projectId}` |
| Project sessions | GET, POST | `/projects/{projectId}/sessions` |
| Sessions | GET, POST | `/sessions` |
| Session | GET, PATCH, DELETE | `/sessions/{sessionId}` |
| Messages | GET, POST | `/sessions/{sessionId}/messages` |
| Message | GET, DELETE | `/sessions/{sessionId}/messages/{messageId}` |
| Tasks | GET, POST | `/sessions/{sessionId}/tasks` |
| Task | GET, PATCH, DELETE | `/sessions/{sessionId}/tasks/{taskId}` |

`POST` accepts JSON and returns `201` plus `Location`. `PATCH` changes only provided fields. `DELETE` returns `204` with no body. IDs can be supplied or auto-generated. The parent project/user/session must exist; foreign-key violations and duplicates return `409`. Message edits are not supported (conversation entries are immutable). Available status values are constrained by the database.

### Deferred collections now implemented

`GET /sessions` **without query parameters preserves Build 1's full plain JSON array**. With any query parameter, collection responses are `{ "items": [...], "page": { "total": 5, "limit": 25, "offset": 0, "hasMore": false } }`. Other collections always use this envelope.

- `limit=1..100` (default 25) and `offset=0..1000000` (default 0); total reflects filters before pagination.
- `sort=createdAt|updatedAt|title|status|id` for sessions, `sort=createdAt|updatedAt|name|id` for projects, appropriate subsets for messages/tasks. `order=asc|desc`. Sort ties break on `id`.
- `projectId`, `status`, `userId`, `language`, `sessionId`, `role` filter on resources that have those fields; `q` searches title, project name, or message content via case-insensitive substring matching.
- `view=summary|full` controls which representation fields are returned. `include=project,messages,tasks` is session-only; it composes related records in one GET. Embedded message/task collections are bounded to 25 items each and report a separate `messagesPage`/`tasksPage` so the client can explicitly paginate via the nested GET endpoints.

Unknown, duplicate, or invalid query parameters return `400`. Invalid request bodies return `400`, `413`, `415` or `422`. Missing resources return `404`; unsupported methods `405` with `Allow`; database constraint conflicts `409`; unexpected server failures `500`. JSON errors include `error`, `code`, `requestId`; every HTTP response includes `X-Request-Id`. Server logs include request method/path, status, duration and correlation ID; database errors log SQLSTATE but not SQL values or message text.

### Quick API sequence

```bash
curl 'http://127.0.0.1:8080/sessions?projectId=prj_ai&status=active&sort=createdAt&order=desc&limit=10&offset=0'
curl 'http://127.0.0.1:8080/sessions/ses_101?include=project,messages,tasks'
curl -X POST 'http://127.0.0.1:8080/sessions/ses_101/messages' -H 'Content-Type: application/json' -d '{"role":"user","content":"Inspect the failing test suite"}'
curl -X POST 'http://127.0.0.1:8080/sessions/ses_101/tasks' -H 'Content-Type: application/json' -d '{"title":"Investigate failure","status":"pending"}'
```

Use the browser dashboard to find/filter sessions, open composed detail, create/update/delete projects and sessions, append/delete messages, and create/update/delete tasks. To delete a session or project with dependent rows, first delete its children; PostgreSQL prevents accidental cascading deletion. See [`docs/build-2.md`](docs/build-2.md) for test scope, limits, and integration checklist; [`docs/openapi.yaml`](docs/openapi.yaml) for the API contract; [`docs/build-1.md`](docs/build-1.md) for the immutable Build 1 history.

## Build 2 rubric additions: formats, composition and evidence

The original Build 1 `GET /sessions` (no query) still returns its plain array, and `GET /sessions/{id}` still returns an item. Controlled collections now return `items`, `page` and query-preserving relative `links` (`next`/`previous`), followed by the browser. All item/collection GETs accept `application/json`, `application/xml` and `application/yaml`. `POST` / `PATCH` accept **JSON bodies only**; unsupported `Accept` returns `406`, unsupported write `Content-Type` returns `415`. See [Build 2 API matrix](docs/build-2.md#representation--negotiation-matrix).

A true **local + external composite** is now available at `GET /sessions/{sessionId}/context`. It reads the PostgreSQL session/project/messages/tasks and bounded public GitHub repository metadata (project `repositoryUrl` must be `https://github.com/owner/repo`). It has a strict GitHub host allowlist, two-second upstream timeout, no redirects, 64 KiB cap and **partial-data-on-failure** response. Use the browser's context button or `php scripts/context-consumer.php ses_101`; set `$env:OSIRIS_GITHUB_MODE='offline'` before `./run.ps1` to demonstrate the controlled failure (`external.status=unavailable`). Seed projects have no GitHub URL by default; PATCH a public URL before demoing the live integration.

[Rubric/evidence board](docs/build-2-board.md) · [Build manifest](build-manifest.json) · [OpenAPI](docs/openapi.yaml) · [Postman requests](requests/Osiris.local.postman_collection.json).

## Verification

```powershell
.\test.ps1
```

This runs isolated Docker-backed Build 1 regressions plus Build 2 writes, validation, pagination, formats and the controlled GitHub-failure composite. First run `php scripts/test-representations.php` for non-Docker unit checks; optionally run `python scripts/test-semantic-formats.py` with PyYAML to prove semantic JSON/XML/YAML equivalence. Run `php scripts/test-ai-provider.php` for multi-provider configuration/response parsing unit checks. Run `python scripts/test-http-no-db.py` for an actual loopback-only PHP HTTP test of the HTML route, 404/405/406 errors and request-correlated logs (no PostgreSQL required). Runtime tests require Docker; syntax validation can be run separately with `php -l backend/public/index.php`. Check `docs/build-2.md` for additional write-operation smoke requests. Never run write smoke requests against production data.

## Release packet and submission boundary

The Build 2 handoff includes a [release-packet checklist](docs/release-packet.md), a [six-step demo evidence worksheet](docs/demo-evidence.md), the [status board](docs/build-2-board.md), the [manifest](build-manifest.json), and saved Postman requests for the full CRUD lifecycle. The packet identifies the uploaded Build 1 Git SHA and how to obtain the reviewed Build 2 Git SHA. **The GitHub push, teacher access, Docker-backed tests and LEA submission cannot be confirmed from this package.** Do not mark those as complete until evidence exists.


### Live database viewer (Build 2)

Running `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\run.ps1` starts Adminer on [http://127.0.0.1:8083/](http://127.0.0.1:8083/) and attempts to open it in your browser. You can also use **Open database** on Dashboard or Build 2 Dashboard.

Adminer credentials for the **local-only development** PostgreSQL service: System **PostgreSQL**, Server **db**, Username **osiris_dev**, Password **osiris_dev_local_only**, Database **osiris_dev**. Never deploy these development credentials or expose Adminer publicly. In `-EmptyDemo` mode the database is named `osiris_empty_demo`.

The **Simulate upstream outage** button on Build 2 Dashboard makes a real `GET /sessions/{id}/context?simulateUpstreamFailure=1` request. It locates a valid session through the database, creates a session in an existing project only if necessary, and displays `external.status=unavailable` and `external.reason=simulated_upstream_failure` with HTTP 200 and preserved PostgreSQL data.
