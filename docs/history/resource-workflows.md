# Resource workflows: implementation and handoff

Archived coursework from the introduction of session writes and related resources. Paths and examples below describe that implementation and the dated extensions. See the [current API contract](../openapi.yaml) for authorization and media types today.

## What changed

Build 1's `GET /sessions` (full ordered array) and `GET /sessions/{sessionId}` (six fields) remain the no-query contract. Build 2 adds four domain resources: existing `projects` and `sessions`, plus the new persisted `messages` and `tasks`. Requests are routed from one PHP entry point to a whitelist-based PDO data access layer; parameter values are bound (including `LIMIT` and `OFFSET`). Query sort columns are selected from static whitelists. The browser provides a practical harness workspace for projects → sessions → conversation messages and tasks.

Database hierarchy:

```
users (preexisting)
  └── projects
        └── sessions
              ├── messages (system/user/assistant/tool turns)
              └── tasks (pending/running/completed/failed)
```

No model API, tool runner, background worker, assistant inference or automatic task progression is in this build. The UI records user-supplied state. This separation is intentional: the harness needs durable, inspectable state before execution orchestration can be added safely.

## Migration and data protection

For an established Build 1 database, `scripts/prepare-db.ps1` applies `database/build2.sql` using `CREATE TABLE IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`. No DROP or TRUNCATE statements are used. Fresh databases use `database/schema.sql` with five tables. PostgreSQL foreign keys ensure messages/tasks cannot be orphaned. To delete a session, delete its dependent messages/tasks first. Seed IDs are retained without overwriting existing rows. Check any local schema customization before applying the migration.

## Examples

```
GET /sessions?status=active&projectId=prj_ai&sort=updatedAt&order=desc&limit=5&offset=0
GET /sessions/ses_101?view=full&include=project,messages,tasks
GET /projects/prj_ai/sessions?limit=10
POST /sessions {"projectId":"prj_ai","title":"Investigate regression"}
PATCH /sessions/ses_101 {"status":"completed"}
POST /sessions/ses_101/messages {"role":"assistant","content":"Analyzed stack trace"}
POST /sessions/ses_101/tasks {"title":"Run unit tests","status":"pending"}
PATCH /sessions/ses_101/tasks/{taskId} {"status":"running"}
```

## Verification / implementation notes

- Syntax: `php -l backend/public/index.php`.
- Data-backed read checks: `./test.ps1` from PowerShell with Docker; verifies original Build 1 SQL-backed session data, empty database, a filtered/paginated collection, invalid sort and project collection.
- Write smoke checks in an isolated database: POST project for `usr_demo_one`; POST session with its ID; POST message and task under it; PATCH task from pending to running to completed; GET composed session and verify FK relationships; DELETE session should return 409 while children exist; DELETE child records, then session, then project. Verify unrelated seed rows remain unchanged.
- Security boundary: server is loopback-bound in Compose. No authentication/authorization exists; all local callers see all records across both users. Content is not encrypted at the application layer; do not store credentials, private prompts or production data.
- JSON message body has a 64 KiB limit; individual text values max 10,000 characters. Offset paging is consistent for a static snapshot but may shift on concurrent inserts. Composition intentionally embeds at most 25 messages and 25 tasks; retrieve additional pages from child collection endpoints.
- API `GET` is read-only; writes are explicit and validated. Request logging emits basic metadata, never message content or SQL parameters. API has no undo, optimistic locking or transactional multi-action endpoint.

## Remaining Build 3 work

User authentication and ownership authorization; safe model/provider integration and secrets management; structured tool invocations with authorization/sandboxing; task-run state machine with concurrency controls; data lifecycle and retention policies; end-to-end integration tests under CI, rate limits and deeper observability.

## Rubric alignment addendum (November 4, 2026)

The original Build 2 read/write implementation above is extended with **representations, an external composite, explicit navigation links and tests**. The submission deadline stated in the course checklist was **October 29, 2026**; this is a later implementation update, not evidence of an on-time submission.

### Representation / negotiation matrix

| Endpoint group | Input `Content-Type` | Response `Accept` | Notes |
| --- | --- | --- | --- |
| `GET` items, collections, `/sessions/{id}/context` | none | `application/json`, `application/xml`, `application/yaml` | Missing `Accept` / `*/*` defaults to JSON; supported aliases `text/xml` and `text/yaml`. `q` preference respected. |
| `POST` / `PATCH` | `application/json` only | JSON, XML, YAML | Invalid/missing write media type -> `415`; malformed JSON -> `400`; semantic validation -> `422`; successful `POST` -> `201` + `Location`. |
| `DELETE` | none | none | `204`, intentionally no response body. |
| Errors | none | JSON, XML, YAML | Same `error`, `code`, `requestId` fields; unsupported `Accept` -> `406` with JSON explanation. |
| `/` browser | none | `text/html` | Plain static HTML, not part of JSON/XML/YAML negotiation. |

JSON output uses native types. XML wraps documents in `<response xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" kind="object|array">`, uses `kind="object|array"` on nested containers, `<item>` for list entries, `xsi:nil="true"` for null and `type="integer|number|boolean"` on non-string scalars. YAML uses YAML 1.2-compatible scalar quoting and native null/boolean/numeric values. All three present the same property names and values; XML/YAML consumers must interpret their respective type notation. `python scripts/test-semantic-formats.py` (optional host Python + PyYAML) parses real PHP-emitted representations and checks nested semantic equivalence. Arrays are represented as repeated `<item>` (XML) or `-` sequences (YAML). A missing field means missing data, while explicit null remains null. Error bodies follow the selected supported media type.

Collection responses contain `items`, `page` (`total`, `limit`, `offset`, `hasMore`) **and `links`** (`self`, `next`, `previous`), except the unchanged original no-query `GET /sessions` legacy array. Links are relative URLs, preserve all query controls, and set the next/previous offset. Empty, overrun and last pages have `next: null`. Ties always sort by unique `id`, matching order direction. The UI follows the returned links rather than rebuilding parameters.

### External composite: PostgreSQL + GitHub API

`GET /sessions/{sessionId}/context` combines the stored session, project, first page of message/task history (plus page metadata) from **PostgreSQL**, and **public GitHub repository metadata** from the GitHub REST API. The project must have `repositoryUrl`, e.g. `https://github.com/php/php-src`; update the project using:

```bash
curl -X PATCH 'http://127.0.0.1:8080/projects/prj_ai' \
  -H 'Content-Type: application/json' \
  -d '{"repositoryUrl":"https://github.com/php/php-src"}'
curl -H 'Accept: application/json' 'http://127.0.0.1:8080/sessions/ses_101/context'
php scripts/context-consumer.php ses_101
```

GitHub request path is constructed from an **allowlisted HTTPS `github.com/{owner}/{repo}`** URL, not arbitrary URLs, blocking SSRF to private addresses. Only `https://api.github.com/repos/...` is called; owner/repo path segments are validated and length-bounded. The upstream request has a **2-second timeout**, **no redirects**, a **64 KiB maximum response**, a user-agent, bounded fields returned to clients and no secrets. The external data is neither stored in the DB nor trusted as instructions for an AI tool runner.

**Failure policy:** when GitHub times out, fails, returns non-200, gives invalid JSON, or the project has no supported repository, the **HTTP request still returns `200`** with available PostgreSQL data. `external.status` is `unavailable` (with a safe machine-readable `reason`) or `not_configured`; `external.repository` is `null`. Internal DB errors still return standard `500`/`404`. To demonstrate a deterministic upstream failure without depending on network conditions, set `$env:OSIRIS_GITHUB_MODE='offline'` in PowerShell before `./run.ps1` (and clear it after). This makes GitHub return `external.reason = upstream_disabled_for_demo`. The test harness sets this mode for its isolated write fixture.

**Consumers:** the browser's “Load PostgreSQL + GitHub context” button uses JavaScript `fetch` and displays response + request ID. `scripts/context-consumer.php` is a standalone PHP HTTP client with a 4s timeout and JSON decoding; it prints local and external data-source states.

### Demonstration script / evidence

1. **Unmodified Build 1 GETs:** `GET /sessions`, `GET /sessions/ses_101`; compare canonical seed IDs, schema and UTC timestamps. The legacy list is a plain array. `test.ps1` runs both regression cases in isolated DBs, including the empty list.
2. **Collection behavior:** `GET /sessions?status=active&sort=title&order=desc&limit=1&offset=0` and follow the `links.next` URL; verify same filters, sort and unique results. `limit=0` or `sort=unknown` -> `400`; `offset=99` -> empty items with no next link. The browser also follows the links.
3. **Persisted workflow:** create project/session, append user message, create task, PATCH task `completed`, GET composed session and check task status, delete child resources then session/project. FK-conflicting parent delete -> `409`.
4. **Negotiation:** request `GET /sessions/ses_101` with `Accept: application/json`, `application/xml`, `application/yaml`; invalid `Accept: image/png` -> `406`; POST `Content-Type: text/plain` -> `415`.
5. **External composite:** PATCH a real public GitHub repo URL on the project and GET `/sessions/ses_101/context`; identify `sources.local` and `sources.external`. Demonstrate `OSIRIS_GITHUB_MODE=offline` as an explicit controlled upstream failure.
6. **Run tests:** `php scripts/test-representations.php` (no Docker needed); `./test.ps1` (Docker/PostgreSQL). Record the outcomes with logs and HTTP status screenshots for submission. **The Docker-backed checks were prepared but not executed in this sandbox.**

### Security groundwork / Build 3 boundary

Proposed roles: `viewer` (read public projects only), `member` (read/write projects and sessions owned by their account), `owner` (delete/manage project members), `admin` (local administrative actions, not arbitrary cross-tenant access). Existing `projects.user_id` is the starting ownership field; session/message/task ownership derives from the project. **None of these checks is enforced in Build 2**, so all endpoints should be treated as development-only/private and bound to loopback. Build 3 will add login with salted password hashing, server-side sessions stored securely (HttpOnly, SameSite cookies with CSRF protection), role/ownership middleware, protected routes and negative authorization tests. If external API clients are required, issue scoped expiring access tokens, not permanent tokens in browser storage.

**Specific Build 3 backlog:** authentication and ownership authorization; project membership/visibility and audited privileged writes; model-provider execution with managed secrets and explicit tool approvals; task concurrency/state machine, release-grade logging and rate limiting; CI-backed Docker/PostgreSQL integration runs; operational error review and XML/YAML schema validation using real clients. See the [coverage board](resource-workflow-coverage.md) and [manifest](resource-workflow-manifest.json) for what had and had not been verified.

### Release packet additions

The [delivery checklist](resource-workflow-delivery.md) maps the shared course delivery list to files, commands, test evidence, limitations, reviewer sign-off and **unverified** teacher access / remote tag / LEA submission. The [demonstration worksheet](resource-workflow-worksheet.md) covers all six requested scenarios with fields for actual test outcomes, request IDs and network success. The [manifest](resource-workflow-manifest.json) uses numbered B2 tasks and B3 tickets; it is not a claim that all runtime checks passed. The Postman collection included an isolated eleven-step write/read/delete workflow and intentional `415`/`422` errors. The simple browser could also update a project's public repository URL and delete individual message/task children before deleting the session.


## Post-deadline developer-workspace extension (November 5, 2026)

The browser now has a redesigned dark dashboard, interactive terminal-style chat page, and a provider-selection Settings page. Real model requests use the PHP backend to connect to the OpenAI Responses, Anthropic Messages, or local Ollama Chat API (selected per chat request). Only provider/model preferences are kept in browser local storage; provider API keys stay in an ignored `.env` file supplied to the API container. The PHP terminal client `scripts/osiris-cli.php` uses the same API. The endpoint `POST /sessions/{sessionId}/chat` accepts JSON with `message` (1–6000 characters), optional `provider` (`openai`, `anthropic`, `ollama`), and optional validated `model`. It saves the user + assistant turns transactionally only when an upstream answer succeeds. There is **no demo AI answer**, no command execution and no internal reasoning display. API errors are structured and logged with request IDs.

The local provider-configuration and parser unit tests pass; **actual provider network calls and Docker/PostgreSQL chat integration remain unverified** in this authoring environment. To test with a real provider, follow the settings instructions in `README.md`, start the app, choose a session, send a prompt and read it back from the messages collection. Check costs and provider model access first. This extension does not retroactively change the course deadline.
