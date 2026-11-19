# Resource workflows: delivery checklist

Archived coursework. This is the checklist as prepared for the resource-workflow snapshot. Its commands, security limits, paths and submission status do not apply to the current checkout.

> **Submission status: NOT VERIFIED.** This package was prepared as a later review of the October 29, 2026 Build 2 requirements. It does not demonstrate an on-time submission. Do not mark any unchecked item as completed without actual evidence. A Git push alone does not establish LEA submission.

## 1. Repository snapshot

- Repository supplied with the project: `https://github.com/IuliaApi/Osiris-Harness.git`.
- Uploaded Build 1 archive's HEAD: `efe95ac91c74a77f7a3e3a22d0ac65989416cb81` (`git rev-parse HEAD` from the uploaded archive).
- This packet's Build 2 changes are prepared on top of that baseline. The final **local** review SHA is printed in the accompanying delivery note (if included) and verified by `git rev-parse HEAD` in the distributed Git checkout. **It is not a published GitHub SHA until a team member pushes it.**
- Preferred release tag, after local runtime verification and before LEA submission: `build-2` if available and not previously used; otherwise use a **new** tag (e.g. `build-2-reviewed`). Do not force-move or delete a submitted tag.
- **Teacher access:** unchecked; team must verify visibility, branch/tag and any invitation/permissions on GitHub. This environment cannot confirm teacher access.

Evidence to attach after team action: GitHub commit page/permalink, exact full SHA, tag permalink, screenshot or confirmation of teacher access.

## 2. Build manifest

See the [archived manifest](resource-workflow-manifest.json) for planned B2-01…B2-12 IDs, implementation vs verification status, changes from Build 1, and explicit B3-01…B3-06 tickets. Record actual contributor and reviewer names in the manifest; placeholders are not evidence of review.

## 3. Reproduction / safe configuration

- Runtime images in the snapshot's `backend/Dockerfile` and `compose.yaml`: **PHP 8.4 CLI Alpine** with `pdo_pgsql`, **PostgreSQL 16 Alpine**, optional **Adminer 5**. Requires **Docker Engine/Desktop with Compose v2** and **PowerShell 7** (`pwsh`) for the scripted launch/tests. Host PHP 8.4 is optional for standalone helper tests.
- Repository-local config keys: `OSIRIS_DATABASE_NAME`, `OSIRIS_PORT` (from `.env.example`) and `OSIRIS_GITHUB_MODE` (`online` / controlled `offline` failure mode). The `OPENAI_API_KEY` and `ANTHROPIC_API_KEY` are optional and must remain private; `OSIRIS_OLLAMA_ENABLED` and per-provider model IDs are optional. `DATABASE_URL` is provided **inside the container** by Compose. Credentials are **local demo-only** and not suitable for remote hosting.
- `./run.ps1`: starts DB, applies additive `database/build2.sql` to an existing Build 1 local database and preserves existing rows; loads canonical demo fixtures if absent, then starts API on `http://127.0.0.1:8080`.
- `./run.ps1 -EmptyDemo -Port 8081`: isolated empty fixture, no destructive reset.
- A **fresh** DB initializes with `database/schema.sql` (five tables). An **existing Build 1** DB is migrated with `database/build2.sql` (`CREATE IF NOT EXISTS`); inspect custom schemas first. `scripts/prepare-db.ps1` checks seed ID relationships and refuses ambiguous reseeds.
- `php scripts/test-representations.php`: standalone format/failure-helper tests (host PHP). Optional `python scripts/test-semantic-formats.py` requires host Python and PyYAML and parses actual PHP-emitted formats for equivalence. `python scripts/test-http-no-db.py`: loopback-only PHP HTTP error/log checks without a DB. `./test.ps1`: Docker-backed regression and mutation checks using **new, disposable, named test databases**, not production; script cleans up its own fixtures.
- The PHP built-in server and all host ports are loopback-bound. **No authentication/authorization** in Build 2. Do not expose the instance, use actual private prompts or put secrets in records.

## 4. Contract and saved requests

- OpenAPI: the snapshot's `docs/openapi.yaml` documented the original item/list 200/404/empty behavior.
- Postman: the snapshot's `requests/Osiris.local.postman_collection.json` used `http://127.0.0.1:8080` and `http://127.0.0.1:8081`. Stored writes modified the local DB.
- Implemented output formats for item/collection/composite GET: `application/json`, `application/xml`, `application/yaml`. Write bodies: `application/json` only. `406` for unsupported `Accept`; `415` for unsupported `Content-Type`; `DELETE` success returns `204` with no body. See the [archived matrix](resource-workflows.md#representation--negotiation-matrix).

## 5. Test evidence — execution status

| Evidence | Status in delivered packet | How to prove it |
| --- | --- | --- |
| Original list (populated / empty) and item (200 / 404), database-sourced | Test cases prepared; **DB run pending** | `./test.ps1` output; attach SQL fixture and HTTP status logs |
| Create → read back → PATCH → read back → DELETE, FK conflicts | Test cases prepared; **DB run pending** | `./test.ps1`; include records and HTTP statuses |
| Filtering, tie-breaking, next/previous links, bad/empty/page bounds | Test cases prepared; **DB run pending** | `./test.ps1` and browser screenshots |
| JSON/XML/YAML helpers and upstream offline path | **Local helper and cross-format semantic check PASS** | `php scripts/test-representations.php`; the delivery checklist cited `evidence/local-checks.txt` (not included in this archive) |
| Media-type failures 406/415 and request-correlation ID | **406 and request-log correlation tested over local PHP HTTP**; 415 DB-backed HTTP case still pending | `python scripts/test-http-no-db.py`; then `./test.ps1` plus Docker request log |
| GitHub 200 response, timeout/failure and PHP/JS consumer demo | Failure helper checked; **live success pending** | `./run.ps1`, set project repo URL, `php scripts/context-consumer.php ses_101`, browser context button |

The [archived worksheet](resource-workflow-worksheet.md) distinguishes pending tests from observed results.

## 6. Known limitations / Build 3 scope

- Authentication and authorization are **not enforced**: current user/project association is database metadata only.
- No LLM execution, external model secrets, tool sandbox, background worker or automated task progress.
- Embedded messages/tasks are first-page-only (25 each); use nested GET collections to paginate further.
- Query paging is offset-based: concurrent writes can shift pages; 100-item maximum limit and 1,000,000 maximum offset.
- External composite uses **public** GitHub repository metadata only and returns partial local data on upstream errors. Live 200 success unverified here.
- PHP/GitHub helper syntax was checked; actual Docker/Powershell/PostgreSQL HTTP tests remain to be run. Build 3 tracks CI, security enforcement, stronger auditing, rate limits and model/tool execution; Build 2 functionality is **not** deferred wholesale.

## 7. Submission record (team fills after actual LEA submission)

- Submitted by: **not provided**
- Contributor(s): **not provided**
- Reviewer(s) / sign-off: **not provided**
- Submitted GitHub URL / commit SHA / tag: **not yet confirmed on remote**
- Teacher repository access confirmed by: **pending**
- LEA submission item/path: **not provided**
- LEA submission timestamp (with timezone): **not provided**
- LEA receipt / screenshot / confirmation link: **not provided**

Once tests pass, commit/push the reviewed source to the intended branch, record the **remote** SHA and immutable tag, verify teacher access, and submit through the course's LEA deliverable—not through GitHub alone.
