BEGIN;

CREATE TABLE users (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL UNIQUE CHECK (btrim(username) <> ''),
    email TEXT NOT NULL UNIQUE CHECK (btrim(email) <> ''),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE projects (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id),
    name TEXT NOT NULL CHECK (btrim(name) <> ''),
    language TEXT,
    repository_url TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (updated_at >= created_at)
);

CREATE TABLE sessions (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL REFERENCES projects(id),
    title TEXT NOT NULL CHECK (btrim(title) <> ''),
    status TEXT NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'completed', 'archived')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (updated_at >= created_at)
);

CREATE INDEX projects_user_id_idx ON projects(user_id);
CREATE INDEX sessions_project_id_idx ON sessions(project_id);

COMMENT ON TABLE users IS 'Application accounts; not PostgreSQL login roles. Authentication is not implemented yet.';
COMMENT ON TABLE projects IS 'Projects belong to users. Each project can have multiple coding sessions.';
COMMENT ON TABLE sessions IS 'Coding-session metadata. Messages and tracked tasks are stored in separate Build 2 tables.';

COMMIT;

-- Build 2's additive tables also apply on first-time database initialization.
BEGIN;
CREATE TABLE messages (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE RESTRICT,
    role TEXT NOT NULL CHECK (role IN ('system', 'user', 'assistant', 'tool')),
    content TEXT NOT NULL CHECK (btrim(content) <> ''),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE tasks (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE RESTRICT,
    title TEXT NOT NULL CHECK (btrim(title) <> ''),
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed')),
    result TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (updated_at >= created_at)
);
CREATE INDEX messages_session_created_idx ON messages(session_id, created_at, id);
CREATE INDEX tasks_session_created_idx ON tasks(session_id, created_at, id);
CREATE INDEX sessions_created_id_idx ON sessions(created_at, id);
CREATE INDEX sessions_project_created_idx ON sessions(project_id, created_at, id);
COMMIT;
