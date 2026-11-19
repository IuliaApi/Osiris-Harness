# Resource workflows: demonstration worksheet

Archived coursework. This was a worksheet for the earlier unauthenticated application. Its commands and example paths are historical; blank fields are not evidence of successful database or network tests.

| Step | Commands / UI action | Expected evidence | Recorded actual result |
| --- | --- | --- | --- |
| 1 · Build 1 continuity | `GET /sessions`, `GET /sessions/ses_101`, `GET /sessions/ses_missing`; `./run.ps1 -EmptyDemo -Port 8081` then `GET /sessions` on 8081 | 200 populated, 200 item from PostgreSQL, 404 structured error, 200 `[]` empty | **Pending** |
| 2 · Collection | `GET /sessions?status=active&sort=title&order=desc&limit=1&offset=0`; follow `links.next` exactly | filter, sort and limit preserved; distinct ID; next/previous correct; empty/invalid/overrun behavior | **Pending** |
| 3 · Persisted operations | Create project/session in browser, append message, make/complete task; GET composed session; delete children then session/project | 201, 200 persisted read back, 200 PATCH, FK 409 while children exist, 204 deletes, 404 after deletion | **Pending** |
| 4 · Representations/errors | Item `Accept: application/json`, `application/xml`, `application/yaml`, `image/png`; POST `Content-Type: text/plain` | same logical data in 3 formats; 406/415 safe errors and request IDs | **Pending** |
| 5 · Composite | Set `repositoryUrl=https://github.com/php/php-src`; GET `/sessions/ses_101/context`; PHP consumer; rerun with `OSIRIS_GITHUB_MODE=offline` | PostgreSQL + GitHub public metadata; documented partial failure with `external.reason` and persisted local data | **Pending** |
| 6 · Regression / Build 3 | `php scripts/test-representations.php`; `./test.ps1`; review `docs/build-2-board.md` | recorded checks, failures and known B3-01…B3-06 tickets | **Helpers and no-DB HTTP checked; DB suite pending** |

## Fill after running

- Timestamp/timezone: ______________
- Git commit SHA/tag actually demonstrated: ______________
- Docker version / Compose version / PowerShell version: ______________
- Test command, check count, pass/fail status: ______________
- SQL fixture results / HTTP response IDs: ______________
- Request ID and matching Docker log line: ______________
- Live GitHub result (or upstream limitation): ______________
- Browser and PHP consumer screenshots: ______________
- LEA submission evidence: ______________

Run `./test.ps1` in the extracted repository on a machine with Docker/Compose and PowerShell 7. It creates its own disposable test databases but **does not** reset the main developer DB. A passing helper test alone is insufficient for Build 2 verification.

## Optional post-deadline AI Terminal demonstration (not part of original due-date evidence)

With your own local `.env` key, open the AI Terminal, select an active session, ask a real coding question, and confirm the new `user`/`assistant` turns appear after a page reload. Use `/api/runtime` to confirm the selected provider is configured (not necessarily connected), then test the actual model reply. Run `php scripts/osiris-cli.php ses_101 openai` if host PHP is installed. Try all three providers when configured; no reasoning details should be shown. Record provider response status and request ID without copying the API key. **Real upstream verification: pending student execution.**
