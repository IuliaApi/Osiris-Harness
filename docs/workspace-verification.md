# Workspace verification

Run the suite on the revision you want to verify. It starts its own PHP servers, PostgreSQL databases and synthetic accounts. You need PowerShell and Docker with Linux containers; you do not need a running development app, a provider key or host PHP, Python or Chromium.

## Run the isolated suite

From the repository root:

```powershell
.\test.ps1 -Browser
```

Omit `-Browser` to run the same service checks without the Chromium walkthrough. The runner builds its images, mounts the contract and fixtures read-only and creates a private Docker network. It exercises both seeded and empty databases and removes its disposable resources on exit. Initial image builds need network access; test traffic stays inside the test environment.

| Check | What to look for |
| --- | --- |
| Resource discovery and collections | Start at `/api`, follow the returned project, session and task links, and compare visible rows to SQL. The unpaged `/sessions` response is an array. Page links preserve filters, ordering and limits. |
| New account and private chat | Register a member, start a chat with no projects, then sign out and back in. The chat persists without creating a project. Other accounts cannot read its messages, and registration cannot claim an existing username or assign a privileged role. |
| Authentication and isolation | A signed-out caller receives no protected data. A second owner cannot read or change another owner's private session, including nested resources or context. A viewer cannot write a public project. Missing or mismatched CSRF tokens leave data unchanged. |
| Representations | Compare parsed JSON, XML and YAML values. Vary the request and response formats independently for resource creation and updates. Check unsupported media, invalid bodies and invalid queries against the [API contract](openapi.yaml). |
| Concurrent updates and deletion | Two writers using the same task version must get one successful update and one `412`. Normal deletion refuses a session with children. Failure partway through an explicit content deletion must roll back both the rows and the audit entries. |
| Tools and chat | Request a virtual workspace operation, then approve and execute it. Premature or repeated execution must not change the file. Check credential isolation and dispatch limits. A synthetic Ollama response exercises persistence of a pair of chat turns, including rollback on failure. |
| Agent continuation | The Ollama protocol fixture requests a write, waits for a reviewed apply, reads the stored value and uses it in its answer. Replaying an old step or continuing too early must not consume another budget unit. A newer human edit must survive a stale proposal. Inspect the tool trace on the saved assistant message, including after starting another turn. |
| Project context | The service combines PostgreSQL data with public GitHub metadata. Test fixtures supply success, timeout, malformed, quota and failure responses. When an upstream fails, local data remains available and the response explains the external status. |
| Chat workbench | Type `/`, filter commands, and use `/model` to search the provider's inventory. Give two threads different permission modes and switch back: drafts and modes stay separate. With Ask, reload a pending proposal and deny it, then allow a fresh proposal inline and check the resulting model reply. |
| Browser | Sign in, create a project and session, append a message, transition a task and approve a virtual file write. Exercise [API Checks](api-checks.md), sign out and check the recovery page for an unknown route. |

The [HTTP and SQL assertions](../scripts/test-workspace-api.py) and [browser walkthrough](../scripts/test-workspace-ui.py) run inside the test network. Use the PowerShell runner to supply their databases, accounts and environment. The [integration workflow](../.github/workflows/workspace-integration.yml) runs the browser-enabled command and retains its transcript.

On an interactive Windows desktop, run `.\desktop.ps1 -InstallOnly`, then `.\.local\desktop-venv\Scripts\python.exe -m unittest discover -s desktop`. The protocol tests cover origin restrictions, expired codes, revocation and stale frames. The native test creates its own two-control window and checks that a click in the capture sends text only to the intended control, including on a scaled display.

## Verify operator startup and persistence

Use a fresh checkout without a development `.env` or `.local` directory. In a new PowerShell session, give the rehearsal its own Compose project so Docker creates a separate data volume. The published ports must be free:

```powershell
$env:COMPOSE_PROJECT_NAME = 'osiris-check-' + [guid]::NewGuid().ToString('N')
.\run.ps1 -Port 8081
```

Use `http://127.0.0.1:8081` and provision two synthetic owners as described in the [operator guide](../README.md). Run all provisioning and restart commands from this checkout in the same PowerShell session.

1. Sign in as the first owner. Create a private project and session, save the session ID and follow its `context` link. To check live GitHub access, set the project's repository URL to a public repository and look for `provenance=live` with returned metadata. A fixture or `unavailable` response does not verify network access.
2. In a separate browser profile sign in as the other owner. Verify the private session is absent from their list. A direct API read or update using its saved URL must return `404`. As the first owner, confirm the session title did not change.
3. Log out of the second account. Run `docker compose down` without `-v`, then `.\run.ps1 -Port 8081`. Check that the first owner's session still has the saved ID and title and that the logged-out cookie cannot read protected data.

## Report a failure

Record the revision, command, exit status, Docker/Compose versions, first failing assertion and whether the upstream was a fixture or live. For an HTTP failure include the method, route, status and `X-Request-Id`. On an operator deployment, use that ID to find the request in `docker compose logs api`. If a rejected write changed data, note the expected and actual rows using synthetic IDs. Do not attach passwords, keys, cookies, CSRF tokens, prompts, raw HAR files or environment dumps.

The [archived run at `b170cfe70c`](history/workspace-run-b170cfe70c.md) has examples of recorded results and request IDs from one revision.