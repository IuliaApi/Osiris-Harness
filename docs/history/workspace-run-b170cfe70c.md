# Workspace verification at `b170cfe70c`

On November 19, 2026, `.\test.ps1 -Browser` exited `0` in a detached checkout of `b170cfe70cf4969110336bcdb2c9cc20932c9770` with no development `.env`. The [original console transcript](workspace-integration-b170cfe70c.txt) begins at 17:22:19 UTC. The host used Windows PowerShell 5.1.26100.9818, Docker Engine 29.8.0 and Compose 5.5.1. This was a local run of the CI command; there is no hosted Actions result in this record.

## Isolated suite

The suite checked 289 HTTP exchanges and correlated 275 requests to the main API's logs. It checked that a synthetic password, key, cookie, CSRF token and prompt did not appear in those logs. POST and PATCH each passed all nine JSON/XML/YAML input-output combinations. Two writers raced on one task version: one committed, the other received `412`, and the final row matched the API. A forced session-deletion failure rolled back child deletions and audit entries. The browser signed in, created a project and session, wrote a message, transitioned a task, approved and executed a workspace write, then signed out.

The suite used deterministic GitHub failures and a synthetic Ollama service for rollback tests. A successful explicit `provider=ollama` request, with both turns checked against SQL, covered a regression where the explicit provider field had been rejected. During rehearsal, the Chromium test's “Session title” locator also matched the search box and demo form. The recorded run used the corrected exact accessible label.

## Operator startup and live services

The same checkout separately ran `run.ps1` with a new Compose project, empty PostgreSQL volume, newly generated keys and two synthetic owners. Only the API was published in this rehearsal, on a different loopback port. These request IDs came from the operator rehearsal, not the isolated suite transcript:

| Action | Observed result | Request ID |
| --- | --- | --- |
| First owner followed `createSession` on a new private project | `201`; session created | `62101a7d8091f5ba` |
| First owner followed the session's `context` link | `200`; PostgreSQL and live GitHub metadata for `octocat/Hello-World` | `16407cedf92fd0b9` |
| Second owner followed the same link | `404`; no private context | `0822c9dbdfab9f7e` |
| Second owner attempted to rename the session | `404`; SQL still returned the title `Restart evidence` | `ed53ddd33507930e` |
| Both containers recreated with the volume retained; first owner followed `self` | `200`; same ID and title | `6a2fb3b2a522aced` |
| Second owner's logged-out cookie reused after restart | `401` | `a9a05d40ad88295d` |

The original report matched the first three read/write checks after creation to log entries with route group, method, status, request ID and duration.

For a separate chat check, the candidate source ZIP was unpacked and launched against a second fresh database. An authenticated request used the host's Ollama `smollm2:135m` model (ID `9077fe9d2ae1`) without a cloud key. Request `f46b4e04449638b5` returned `200` and a 63-byte assistant reply. Both turns matched a direct PostgreSQL read. The check established dispatch and persistence for that model; it did not evaluate answer quality or establish a paid-provider success.

## Reproduction and course record

To investigate this exact result in a clone that contains the commit, run `git worktree add --detach ../osiris-workspace-repro b170cfe70cf4969110336bcdb2c9cc20932c9770`, enter that directory and run `.\test.ps1 -Browser`. For another revision use its own [verification instructions](../workspace-verification.md).

The original handoff listed November 12 for the plan, November 27 for the QA report, December 2 through 4 for the demonstration and December 10 for the final report/user guide. It recorded a 23% implementation weight across the three course builds. The run does not establish on-time plan submission, independent peer sign-off, remote publication or course submission.
