# Build 2 Dashboard (live classroom demonstration)

Visit **http://localhost:8080/build-2** after starting the Docker app. Use the local demo login to access it.

The dashboard has six reproducible steps:

1. Original `GET /sessions` (legacy array), `GET /sessions/{id}` and 404 missing item.
2. Filter/sort/paginate `GET /sessions?status=...&sort=...&order=asc&limit=1&offset=0` and follow `links.next`, preserving query controls.
3. Create a session using POST, update using PATCH, read the persisted session using GET (creates actual database data).
4. Request same session using Accept `application/json`, `application/xml` and `application/yaml` plus invalid sort 400 and unsupported Accept 406. GET representations only; writes use application/json.
5. GET `/sessions/{id}/context` combines PostgreSQL with GitHub REST metadata; `GET /sessions/{id}/context?simulateUpstreamFailure=1` produces deterministic upstream failure without calling GitHub and keeps the database response intact. In normal mode a project needs a public GitHub URL for a populated external result. Timeouts are 2 seconds and responses bounded to 64 KiB.
6. Browser HTTP smoke checks. Full database regression suite: `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\test.ps1` (on Windows with Docker and PostgreSQL).

The UI displays raw HTTP status and `X-Request-Id` for each request. A feature being listed as implemented is **not** proof it passed live PostgreSQL integration. Capture results in `docs/demo-evidence.md` and obtain the actual commit SHA and submission receipt.

## Build 3 boundary

Proper login/session security, authorization and ownership enforcement, approved filesystem/shell agent execution, task concurrency controls, full CI integration suite, rate limits, audit logs and secure API key handling. The local demo login is **not authentication**.


Database viewer: `run.ps1` starts Adminer at `http://127.0.0.1:8083/` and opens it in a browser. Select PostgreSQL / db / osiris_dev / osiris_dev_local_only / osiris_dev. This is a local development viewer only. The Build 2 Dashboard includes an **Open database** link. The explicit requirement-coverage and Build 3 work panels have been removed from the **UI** as requested; tracking files remain in the repo. The outage demo now resolves its session from the live database and shows a deterministic GitHub failure without losing local data.
