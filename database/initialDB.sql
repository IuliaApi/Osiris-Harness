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
COMMENT ON TABLE sessions IS 'Coding-session metadata. Conversation messages and tool state are future work.';

COMMIT;
