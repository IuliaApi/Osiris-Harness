# Osiris Harness

Osiris is a local PHP/PostgreSQL workspace for projects and coding conversations. Its browser terminal sends real model requests, runs tools on files belonging to the current thread, and records their results. It is not a shell on your machine.

## Run on localhost

Install Docker Desktop (Linux containers, Compose v2) and PowerShell. Start Docker, then from the checkout:

```powershell
.\run.ps1 -Open
```

Open <http://127.0.0.1:8080/> and select **Create account**, or go directly to <http://127.0.0.1:8080/register>. Choose a username and password, then select **New chat** on the dashboard or in Chat. The conversation is private to your account; you don't need a project. Use **New session** to start a conversation inside a project instead.

The launcher builds the API and applies the migrations. On a fresh database it also loads two synthetic users, two projects and five sessions. Neither fixture user has a default password, and registration cannot claim their usernames. To sign in as a fixture owner instead, provision it from the operator's shell:

```powershell
.\scripts\set-account.ps1 -Id usr_demo_one -Username demo_one -Role owner
```

The password is read without echo, not placed on the command line. Provision `usr_demo_two` / `demo_two` separately to demonstrate isolation. Do not reset someone else's account to run tests. `owner` manages its own projects; `member` can collaborate, `viewer` reads public projects and `admin` does not bypass project boundaries.

Use `.\run.ps1 -Tools -Open` for the optional Adminer at <http://127.0.0.1:8083/>. Local-only settings: PostgreSQL, server `db`, user `osiris_dev`, password `osiris_dev_local_only`, database `osiris_dev`. Adminer connects as a database operator, **not** an application user. Do not give its credentials to application clients. Stop with `docker compose --profile tools down` (without `-v`, data is retained). `-EmptyDemo` selects `osiris_empty_demo` without loading demo records.

## Use the terminal

Start a **New chat** and type `/` to see the commands. Keep typing to filter, use the arrow keys to navigate, or click a result. `/model` opens a searchable list grouped by provider: installed Ollama models and models your account's cloud keys can access. A provider that cannot be reached is marked unavailable. Selecting a model changes this thread without starting a model request.

The composer shows the active provider and model even when the thread is idle.

Commands report settings and errors outside the transcript. During a model call the terminal shows the selected model and elapsed time; tool calls and their results appear as they finish. The activity indicator does not display private model reasoning.

`/goal <objective>` saves a goal for this account and thread and starts a turn if the agent is idle. An active goal also informs later main turns. `/goal` opens its controls; `/goal pause`, `/goal resume`, `/goal done` and `/goal clear` change its state. Pausing a goal does not cancel a request already in progress.

`/btw <question>` asks the selected model a separate question using the thread’s saved conversation. `/btw` opens recent side answers. Choose **Follow up on selected answer** to include that exchange in another question. Side exchanges use the normal dispatch budget, but do not enter main chat history or call tools.

Use `/permissions` to choose what the agent may do in this chat. **Ask** (default) prompts before writing. **Allow** can create new files but asks before replacing existing content. **YOLO** can create and edit without asking. All three can list, search and read the chat's files; none grants host filesystem or shell access. The setting is saved for your account and this chat, not for other threads or collaborators. Changing it does not approve a pending request.

With tools on, try `List the files in this workspace using glob.` When a write needs permission, inspect its contents in the transcript. **Allow once** applies it and continues the turn; **Deny** stops the turn. A stale proposal cannot overwrite a newer edit. Use `/tools off` for plain conversation.

You can leave a turn and return to it. Reloading never starts a paid request. Cancel stops further steps; a provider request already sent may still finish and consume budget. Each turn has at most four tool rounds and one final answer request. **Ctrl+K** (Mac: **⌘K**) still opens workspace navigation.

### Share a Windows app

On the same Windows PC, run `.\desktop.ps1` in a separate terminal (Python 3.11+ with Tk required). This installs the pinned Windows dependencies in `.local/desktop-venv`. If Osiris uses a different port, pass `-Port` to both launchers. Nothing is shared on startup.

Select the app window in the companion, enable sharing, and copy its one-time code. In a writable chat at `http://127.0.0.1:8080`, run `/desktop` and pair. Refresh the image, click in it, or send text and individual keys. The browser controls only the selected window through the loopback companion; desktop input is manual, independent of the model and all file permission modes. Stop sharing in the native window to revoke access immediately. Close the companion when finished.

Use a normal, visible app window. Minimized, elevated, protected, or GPU-rendered windows may not support capture or targeted input. There is no fallback to a screenshot of the whole monitor or system-wide keyboard input.

## Use the API

Send `{ "username": "…", "password": "…" }` to `POST /auth/register` for a new account (`201`), or `POST /auth/login` for an existing one (`200`). Both return a session cookie and `csrfToken`. Send the cookie on subsequent requests and `X-CSRF-Token` on writes. `GET /auth/me` restores the signed-in client; `POST /auth/logout` revokes its session. Registration always creates a `member`; only the operator can assign privileged roles. Supply credentials in request bodies, never URLs.

`GET /api` is the authenticated entry point. Follow `_links`: each relation carries a site-relative `href` and HTTP `method`. Follow the root `createSession` link with `{ "title": "New chat" }` to start a private conversation (`projectId: null`). To start a project conversation, use the project's `createSession` link. Both return a `chat` link for sending messages. The server enforces permissions on every request.

For tool-capable clients, follow `startAgent` with `{ "message": "...", "provider": "ollama", "model": "qwen3:1.7b", "tools": true }`. Each `step` link takes `{}` and runs at most one model request. Read the `self` link after an interrupted connection; never blindly replay a step. `review` means one or more writes await approval and application. The `agent` link recovers the current account's last turn in this thread.

Follow the root `models` link to enumerate models without performing inference. The session's `permissions` link returns your current mode; `setPermissions` accepts `{"mode":"ask"}`, `{"mode":"allow"}` or `{"mode":"yolo"}` for writable chats. When a turn needs consent, its event's `toolRun` link identifies the exact proposed operation.

For task updates, follow the task's `update` link and pass its current `ETag` in `If-Match`. If the version is stale, fetch the task again and decide whether the change still applies.

`GET /sessions` still returns a plain array, now filtered to the caller. Pass collection controls such as `?limit=2&sort=title&order=asc` to receive `items`, `page`, and links. Items support summary/full views; a session can include its project, messages and tasks. A normal DELETE refuses dependent rows. The separate **Delete session** control explicitly requests a transactional deletion of the session and its contents. Unknown browser pages return a 404 screen; API errors carry `error`, `code` and `requestId`.

The [API Checks page](docs/api-checks.md) exercises the running service with the signed-in user's permissions. See the [OpenAPI contract](docs/openapi.yaml) for formats, statuses and preconditions. Use the [Postman owner workflow](requests/Osiris.local.postman_collection.json) against synthetic data only.

## External services and configuration

`.env.example` lists the server defaults. Cloud keys belong to individual accounts: enter a key in **Manage → Model settings & credentials**. The API never returns it or shares server environment keys with users. The launcher creates `.local/vault.key` for encryption. Restrict access to the operator and back it up separately from the database; without the original vault key, users must re-enter their credentials.

- In Docker Compose, chat routes OpenAI, Anthropic and local Ollama through the internal `ai-router` service using [`@oh-my-pi/pi-ai`](https://github.com/can1357/oh-my-pi/tree/main/packages/ai), pinned to version 19.0.0. PHP still enforces account permissions, decrypts only the caller’s key, reserves a dispatch budget and saves the exchange. The router has no published host port and requires `.local/router.key`, generated by `run.ps1`. Provider endpoints are fixed; keys and upstream diagnostics are never returned to clients. Automatic extra provider requests are blocked. PHP can still use its direct adapters when `OSIRIS_AI_ROUTER` is unset outside Compose.
- In Chat, select **OpenAI** and the model, then open **API key settings** to save your key privately. Only provider/model preferences are stored in the browser.
- Chat calls the selected OpenAI, Anthropic or local Ollama adapter. “Configured” is not proof of connectivity or available credit. Install an Ollama model on the host before using it. The quota unit is one provider dispatch, **not dollars**. Set billing controls on the provider account as well. Do not automatically retry a timed-out paid call.
- A session's `context` link composes its database records with bounded public GitHub metadata from the project `repositoryUrl`. A missing URL or upstream failure leaves the local data readable. A fixture result is not a live GitHub response.
- With tools enabled, native model tool calls can inspect this session's virtual files. Their results are sent to the selected provider. Writes and exact edits follow this chat's permission policy.

For local use, install Ollama on the host and run `ollama pull qwen3:1.7b`. Select `ollama/qwen3:1.7b` with `/model` in an active thread. The model must support native tool calls for tools-on mode. Cloud models use the signed-in account's key instead.

With PHP CLI installed on the host, `php scripts/osiris-cli.php ses_101 ollama qwen3:1.7b` uses the single-reply chat endpoint (no agent tools). `php scripts/context-consumer.php ses_101 --require-live` requires a visible project with a public GitHub repository URL. Both commands read `OSIRIS_USERNAME` and `OSIRIS_PASSWORD` from the client process environment; do not commit the password or pass it in a URL.

Sessions keep their conversation and virtual files until explicitly deleted. Deleting a session with contents also removes its tasks and tool history. Authentication cookies expire after eight hours; logout revokes them immediately. Login or admin maintenance clears expired sessions and rate windows, and removes audit events older than 30 days. A new dispatch prunes budget and dispatch records older than 30 UTC days. These are activity-triggered retention rules, not a scheduled purge of an idle database or its backups.

## Migrations, QA and release

`scripts/prepare-db.ps1` initializes a fresh database from `database/schema.sql`, loads the optional demo data, then applies the numbered SQL files in `database/migrations/` in order. On an existing database it applies the migrations without reseeding. The API uses `osiris_app`; the operator applies migrations and grants. Back up the database and the `.local` service key files separately with restricted access. Restore into a separate database if recovery is necessary.

Run `.\test.ps1 -Browser` for [workspace verification](docs/workspace-verification.md), including Chromium, or omit `-Browser` for HTTP/PostgreSQL checks. Neither uses the development volume or a real provider key. The supplied PHP server, database credentials and optional Adminer are for loopback use only.

The HTTP entry point is `backend/public/index.php`; database access, chat, credentials, tool approvals and representations live in `backend/src/`. The browser shell, styles and scripts are in `web/`. [Historical records](docs/history/README.md) are kept separately from the current operating instructions.

### AI router checks

After starting the app with `.\run.ps1`, run `docker compose exec ai-router bun test` for router validation and OpenAI adapter tests. These use explicit synthetic test responses and never call a paid provider. The existing `.\test.ps1 -Browser` suite covers the authenticated PHP workflows with direct adapters; a successful live chat with your own key is a separate connectivity check. Router health only confirms the service is running.

Run `node scripts/test-chat-ui.cjs` with Chrome installed for chat UI checks against explicitly synthetic API responses. Set `CHROME_PATH` to use a different Chromium executable. This does not require an account, database, or provider key.
