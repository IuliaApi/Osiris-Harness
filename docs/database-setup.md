# Start the Osiris database

The Rust CLI runs locally. It will call the Axum API, which connects to PostgreSQL. Your Osiris account is a row in `users`; it is separate from the PostgreSQL administrator account used to set up this database.

Each developer can create a local `osiris` database using the same checked-in SQL files. Later, both CLIs can connect to one hosted API, backed by one shared database. Schema and synthetic seed files belong in Git; database passwords and live database contents do not.

## 1. Install PostgreSQL

Use the installer linked on the [official PostgreSQL Windows page](https://www.postgresql.org/download/windows/). Include the server and pgAdmin, keep port `5432`, and save your chosen administrator password privately. PostgreSQL is not bundled with this repository.

## 2. Create the database

Open pgAdmin, connect to the local server with the password you chose, then right-click **Databases → Create → Database**. Name it `osiris` and save.

Select **osiris → Tools → Query Tool**. Make sure the query tab is connected to `osiris`, rather than the default `postgres` database.

## 3. Create tables and insert demo records

In Query Tool, use **Open File** to open and execute these files in order:

1. `database/initialDB.sql` — creates `users`, `projects`, and `sessions`, their keys, constraints, and indexes.
2. `database/seed.sql` — inserts two synthetic accounts, one project per account, and five sessions.

Run these once on a fresh database. They intentionally fail on duplicates rather than silently replacing records. If a script fails and pgAdmin reports an aborted transaction, execute `ROLLBACK;` before trying another query.

IDs are text, matching the existing API design (`usr_…`, `prj_…`, `ses_…`). These seed IDs are readable examples. The future API should generate unique IDs, such as a prefix plus UUID, rather than depending on these fixed values.

```text
users                         projects                     sessions
usr_demo_one ────────────────► prj_ai ──────────────────► ses_101
                                                        ├► ses_102
                                                        └► ses_103
usr_demo_two ────────────────► prj_web ─────────────────────► ses_201
                                                        └► ses_202
```

Each session belongs to a project, and each project belongs to one account. A user's laptop and desktop access the same account; there is no device-specific ownership table.

## 4. Inspect the records

Run this in Query Tool to see the relationships:

```sql
SELECT u.username, p.name AS project, s.id, s.title, s.status
FROM users u
JOIN projects p ON p.user_id = u.id
JOIN sessions s ON s.project_id = p.id
ORDER BY u.username, s.id;
```

Expected: three sessions for `demo_one`, two for `demo_two`.

Open and execute `database/queries.sql` next. It demonstrates parameterized list/detail queries: account one sees three sessions, account two sees two, and account two cannot retrieve `ses_101` through the ownership-scoped query.

This query pattern does not implement authentication or database-level row security. The future API must derive the user ID from the authenticated account and always apply the ownership condition. These demo users have no passwords or login functionality yet.