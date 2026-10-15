# Build 1 delivery record

Build 1 contains one PHP file, two session-read operations, a browser page, and a relational schema with synthetic data. `SessionController::list()` and `get()` in `backend/public/index.php` execute PDO `SELECT` queries directly. There are no repository classes, identity selection, authentication, filters, write endpoints, or CLI.

```text
Browser -> backend/public/index.php -> SessionController -> PDO -> PostgreSQL
Browser <- HTTP status and JSON
```

## Nine-step demo

Requires Docker Desktop with Compose and PowerShell. Run commands from the repository root. Start the app with `.\run.ps1` if it is not already running.

1. Open the repository and team Project board. Show Build 1 tasks, owners/reviewers, completed PRs, and the submitted SHA from `git rev-parse HEAD`. Check that this commit is visible on GitHub before calling it submitted.
2. Show the [schema diagram](schema.svg), `database/schema.sql`, and `database/seeds/demo.sql`. The [draw.io source](schema.drawio) is editable through File > Open from > Device at https://app.diagrams.net/. Explain `users.id -> projects.user_id` and `projects.id -> sessions.project_id`: each parent can have many children.
3. Import `docs/openapi.yaml` into https://editor.swagger.io/. Show the two GET operations, required `sessionId`, object versus array shapes, and populated, empty, and missing examples.
4. Open http://127.0.0.1:8080/, press F12, and select Network. Click "Load sessions". Inspect `GET /sessions`, status `200`, `Content-Type: application/json; charset=utf-8`, and the displayed list.
5. Click a session, or enter `ses_101` in "Session ID" and click "Get session". Show the item request, status `200`, and its JSON object on the page.
6. Run `Get-Content database/queries.sql -Raw | docker compose exec -T db psql -X -U osiris_dev -d osiris_dev -v ON_ERROR_STOP=1`. Compare ID `ses_101` and title `Investigate crash` in SQL, the list, and the item. In `backend/public/index.php`, show routing, `SessionController`, and the PDO queries.
7. Enter `ses_missing` and submit. Show `404`, `{"error":"Session not found"}`, and the page's error message.
8. In another terminal run `.\run.ps1 -EmptyDemo -Port 8081`. Open http://127.0.0.1:8081/ and click "Load sessions". Show `200`, `[]`, and "No sessions found." Return to port 8080 and confirm its records remain. The seed was never cleared, so no restoration command is needed.
9. Import `requests/Osiris.local.postman_collection.json` into Postman and run its four saved GET requests. Run `.\test.ps1` for isolated SQL/HTTP checks. Show the results below and the planned Build 2/3 board tasks. Stop each foreground API with Ctrl+C when finished.

Optional Adminer: run `docker compose up -d adminer`, then open http://127.0.0.1:8083/. Use PostgreSQL, server `db`, username `osiris_dev`, password `osiris_dev_local_only`, and database `osiris_dev` or `osiris_empty_demo`. These are public local-development credentials.

## Schema and response agreement

[`database/schema.sql`](../database/schema.sql) defines `users` to `projects` to `sessions` as one-to-many relationships. Primary keys identify rows; indexed foreign keys preserve relationships. Required text cannot be blank, usernames/emails are unique, session status is constrained, and update timestamps cannot precede creation timestamps. Both demonstration databases use this same three-table schema.

[`database/seeds/demo.sql`](../database/seeds/demo.sql) provides two synthetic users, two projects, and five sessions. The API exposes every session, including both owners' rows. The reported local dataset has six sessions; startup preserves local additions. Five-row expectations apply to a fresh canonical test fixture, not every running database.

| Session | Project | Owner | Title | Status |
| --- | --- | --- | --- | --- |
| `ses_103` | `prj_ai` | `usr_demo_one` | Review error messages | archived |
| `ses_101` | `prj_ai` | `usr_demo_one` | Investigate crash | active |
| `ses_102` | `prj_ai` | `usr_demo_one` | Add tests | completed |
| `ses_201` | `prj_web` | `usr_demo_two` | Fix navigation | active |
| `ses_202` | `prj_web` | `usr_demo_two` | Test contact form | completed |

List order is `created_at`, then `id`, ascending. With the current seed dates it is `ses_103`, `ses_101`, `ses_202`, `ses_102`, `ses_201`; `ses_201` was created at `2026-10-12T09:00:00Z`. Local edits and extra rows can change the displayed list.

`GET /sessions/ses_101` returns this canonical object:

```json
{
  "id": "ses_101",
  "projectId": "prj_ai",
  "title": "Investigate crash",
  "status": "active",
  "createdAt": "2026-10-10T14:00:00Z",
  "updatedAt": "2026-10-14T15:30:00Z"
}
```

SQL `project_id`, `created_at`, and `updated_at` map to JSON `projectId`, `createdAt`, and `updatedAt`. Timestamps use RFC 3339 UTC with `Z`, omitting fractional seconds when zero. A missing item returns `404`; an empty list returns `200` with `[]`. Known routes reject other methods with `405` and `Allow: GET`. Unknown routes return `404`. There are no deferred-operation stubs.

The standalone [OpenAPI 3.0.3 file](openapi.yaml) defines only `GET /sessions` and `GET /sessions/{sessionId}`, a required path parameter, array/object schemas, and the four case examples.

## Four focused checks

`test.ps1` uses Docker and PowerShell. It lints the single PHP file, creates two isolated `osiris_test_<12hex>` databases from the canonical schema, seeds one, and starts temporary API containers on loopback ports. It checks real HTTP responses against SQL-derived expectations. Cleanup runs in `finally`, including after failures; the development and empty-demo databases are not test fixtures. Host PHP and Rust tools are not required.

| Case | Expected HTTP result | Actual | SQL agreement |
| --- | --- | --- | --- |
| Existing `ses_101` | `200`; exact six-field object above | PASS: `200`; exact object | Every public field and timestamp matches SQL |
| Missing session | `404`; `{"error":"Session not found"}` | PASS: `404`; exact error object | No matching row |
| Populated list | `200`; all five canonical rows across owners, in SQL order | PASS: `200`; five objects | All fields, IDs, count, timestamps, and order match SQL |
| Empty list | `200`; `[]` | PASS: `200`; `[]` | Isolated sessions table has zero rows |

The isolated test run passed 33 checks, with no failed checks or cleanup errors. Browser checks also confirmed both reads, missing-item feedback, and the empty-state message. The live development database had six sessions; all existing users, projects, and sessions were preserved unchanged. Newman passed four requests and eight assertions. OpenAPI validation passed.

## Delivery manifest

| Item | Path or value | Owner | Reviewer |
| --- | --- | --- | --- |
| PHP scaffold and two reads | `backend/public/index.php`; `backend/Dockerfile`; `backend/composer.json` | Unassigned | Unassigned |
| Page | `web/index.html` | Unassigned | Unassigned |
| Schema, seed, read queries | `database/schema.sql`; `database/seeds/demo.sql`; `database/queries.sql`; `docs/schema.drawio`; `docs/schema.svg` | Unassigned | Unassigned |
| Local setup | `compose.yaml`; `run.ps1`; `scripts/prepare-db.ps1`; `.env.example` | Unassigned | Unassigned |
| Four-case verification | `test.ps1`; results above | Unassigned | Unassigned |
| Contract and handoff | `README.md`; `docs/openapi.yaml`; `docs/build-1.md` | Unassigned | Unassigned |

| Revision record | Value |
| --- | --- |
| Base commit | `b99532a02ef60869d5c35745835ec3714b5faaf7` |
| Build SHA | Run `git rev-parse HEAD` on this checkout and include the printed SHA in the submission. |
| Submission | Verify that the same SHA is visible on GitHub. Follow the LEA submission instructions; a Git push alone is not a submission record. |
| Runtime verification time | `2026-10-15T19:00:35Z`; SQL/HTTP checks against isolated fixtures. |
| Contract/link verification | Swagger CLI validation, local Markdown links, two-GET/four-example checks, and static seed/example agreement passed. Recorded UTC: `2026-10-15T18:53:21Z`. |
| PR / board links | Unassigned |
| History | Feature branches were reconstructed from the existing implementation on October 15, 2026. Backdated commit timestamps do not represent the original development chronology. |

## Planned backlog

All entries below are planned work, absent from the live API and OpenAPI contract. Methods and URIs are planning targets, not callable operations. Owners and reviewers have not been assigned.

| Build | Work | Planned method / URI | Owner / reviewer |
| --- | --- | --- | --- |
| 2 | Project CRUD | `GET, POST /projects`; `GET, PATCH, DELETE /projects/{projectId}` | Unassigned / Unassigned |
| 2 | Session writes | `POST /sessions`; `PATCH, DELETE /sessions/{sessionId}` | Unassigned / Unassigned |
| 2 | Filters, sorting, pagination | `GET /sessions`; query contract to be defined | Unassigned / Unassigned |
| 2 | Representations and composition | `GET /sessions/{sessionId}`; `GET /projects/{projectId}/sessions`; representation contract to be defined | Unassigned / Unassigned |
| 2 | Related messages/tasks and their schema | `GET, POST /sessions/{sessionId}/messages`; `GET, POST /sessions/{sessionId}/tasks` | Unassigned / Unassigned |
| 3 | Accounts and authentication | `POST /users`; `POST /auth/login`; `POST /auth/logout`; `GET, PATCH, DELETE /users/me` | Unassigned / Unassigned |
| 3 | Authorization, security, and QA | All planned methods/URIs; ownership rules, credential handling, error and failure tests | Unassigned / Unassigned |
