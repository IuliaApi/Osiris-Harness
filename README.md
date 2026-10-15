# Osiris Build 1

A local PHP scaffold with two database-backed session reads and a browser page. PostgreSQL stores users, projects, and sessions. The API returns every session across owners, without filters or authentication. It has no write endpoints.

## Run

Install Docker Desktop with Compose and PowerShell. From the repository root:

```powershell
.\run.ps1
```

Open http://127.0.0.1:8080/. The script starts PostgreSQL, loads the synthetic demo SQL if absent, builds the PHP image, and runs the API in the foreground. Existing rows and local additions stay intact. Ctrl+C stops the API; the database stays running. The PHP development server is for local use.

For a separate empty database, run in another terminal:

```powershell
.\run.ps1 -EmptyDemo -Port 8081
```

Open http://127.0.0.1:8081/. Empty-demo setup refuses a nonempty database and never clears the populated database.

## Page and API

The page has a session list and an ID lookup. It displays the HTTP status and JSON response.

| Method | Path | Response |
| --- | --- | --- |
| GET | `/sessions` | `200` with an array, including `[]` for an empty database |
| GET | `/sessions/{sessionId}` | `200` with one object, or `404` if missing |

`GET /` serves the page. Other methods on known routes return `405` with `Allow: GET`; unknown routes return `404`.

All PHP is in [`backend/public/index.php`](backend/public/index.php). `SessionController::list()` and `get()` execute PDO `SELECT` queries directly. The page is [`web/index.html`](web/index.html). The database files are [`schema.sql`](database/schema.sql), [`seeds/demo.sql`](database/seeds/demo.sql), and [`queries.sql`](database/queries.sql). The seed has five canonical sessions; a preserved local database can contain more.

## Checks

```powershell
.\test.ps1
```

The checks use Docker and PowerShell, without host PHP. They lint the single PHP file, send real HTTP requests to temporary API containers backed by two isolated test databases, and compare responses with SQL. Cleanup runs even if a check fails. The four required cases and recorded results are in the [Build 1 delivery record](docs/build-1.md).

## Database browser

```powershell
docker compose up -d adminer
```

Open http://127.0.0.1:8083/. Select PostgreSQL, server `db`, username `osiris_dev`, password `osiris_dev_local_only`, and database `osiris_dev` (or `osiris_empty_demo`). These are public local-development credentials. Adminer is optional under the Compose `tools` profile.

[`.env.example`](.env.example) contains only the local database name and HTTP port; it contains no secrets or identity setting. Copy it to `.env` for direct Compose use if needed. The run script selects its database and port through `-EmptyDemo` and `-Port`.

- [Database schema diagram](docs/schema.svg) ([edit in draw.io](docs/schema.drawio))
- [Build 1 demo, manifest, checks, and backlog](docs/build-1.md)
- [OpenAPI 3.0.3 contract: the two GET operations](docs/openapi.yaml)
