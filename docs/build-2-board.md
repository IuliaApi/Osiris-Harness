# Build 2 rubric board / demonstration evidence

Status key: **implemented** = source code exists; **locally checked** = check executed in this editing environment; **runtime verification pending** = needs Docker/PostgreSQL live run. Never interpret source presence as proof that a network/database end-to-end test passed.

| Rubric item | Status | Implementation/evidence | Required live confirmation |
| --- | --- | --- | --- |
| Original item/list GET, pages, safe additive migration | Implemented | `backend/public/index.php`, `web/index.html`, `database/build2.sql`, `run.ps1`, `scripts/prepare-db.ps1`, `test.ps1` | Run `test.ps1` populated and empty DB fixture tests |
| Domain create/update/delete and persisted AI-harness workflow | Implemented | Project/session/message/task Store operations, browser forms | POST/PATCH/GET/DELETE assertions in updated `test.ps1` |
| Deferred list filters, sorting, bounded pagination, unique tie-breaker | Implemented | `Store::list`, `paginationLinks`, browser follows `links` | Empty, invalid, 1-row pages, overrun in `test.ps1` |
| Shared validation/error policy, parameterized SQL, allowlist columns, request logs | Implemented | `ApiError`, `queryParams`, `inputJson`, `Store` and shutdown logger | No-DB HTTP 404/405/406 + request-log correlation passed; still trigger `400`/`415`/`422`/`409` with DB test |
| JSON/XML/YAML output, Accept negotiation, JSON input only | Implemented and locally checked | `backend/public/Representation.php`, `scripts/test-representations.php`, `scripts/test-semantic-formats.py` (passed locally) | HTTP serialization assertions in `test.ps1` |
| External composite and partial upstream failure | Implemented and locally checked for failure helper | `backend/public/GitHubContext.php`, `/sessions/{id}/context`, JavaScript and PHP consumer | Validate real public GitHub response; deterministic offline case in `test.ps1` |
| Security groundwork and Build 3 separation | Documented | `docs/build-2.md` roles, owner model and auth plan | Code and integration tests for enforcement **Build 3** |
| OpenAPI, Postman, board, manifest, release handoff | Implemented and parsed locally | `docs/openapi.yaml`, `requests/Osiris.local.postman_collection.json` (complete CRUD lifecycle), `docs/release-packet.md`, `docs/demo-evidence.md`, `build-manifest.json` | Demo screenshots, remote SHA, teacher access and LEA receipt |

## Explicit Build 3 tickets

- B3-01: Login, logout, salted password hashes, secure sessions, CSRF.
- B3-02: Owner/member/viewer/admin authorization, project visibility and route matrix, negative tests.
- B3-03: Add identity-bound provider credential storage, spending limits, tool approvals, safe filesystem access and sandboxed execution (basic OpenAI/Anthropic/Ollama chat adapters already implemented).
- B3-04: Task concurrency/versioning, execution provenance, lifecycle constraints.
- B3-05: Automated PostgreSQL integration checks in CI plus XML/YAML parser interoperability checks.
- B3-06: Rate limits, audit trail, retention, structured log sink and credential redaction.

The October 29, 2026 course deadline preceded this November 4 follow-up. The project still needs a live demonstration on a Docker-capable developer machine. See `docs/demo-evidence.md` for unfilled result fields; **pending is not pass**. The course states 23% implementation across three builds, not a separate Build 2 mark.

**November 5 UI/AI extension:** modern dark dashboard, terminal-style JavaScript client, AI Settings (OpenAI/Anthropic/Ollama selection), PostgreSQL-backed chat route and PHP CLI are **implemented**. Local PHP lint, parser tests and no-DB HTTP checks passed. Real model API connections and live PostgreSQL chat tests remain **unverified**. No tool execution or internal reasoning display is included.
