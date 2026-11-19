BEGIN;
CREATE TABLE IF NOT EXISTS messages (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE RESTRICT,
    role TEXT NOT NULL CHECK (role IN ('system', 'user', 'assistant', 'tool')),
    content TEXT NOT NULL CHECK (btrim(content) <> ''),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS tasks (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE RESTRICT,
    title TEXT NOT NULL CHECK (btrim(title) <> ''),
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed')),
    result TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (updated_at >= created_at)
);
CREATE INDEX IF NOT EXISTS messages_session_created_idx ON messages(session_id, created_at, id);
CREATE INDEX IF NOT EXISTS tasks_session_created_idx ON tasks(session_id, created_at, id);
CREATE INDEX IF NOT EXISTS sessions_created_id_idx ON sessions(created_at, id);
CREATE INDEX IF NOT EXISTS sessions_project_created_idx ON sessions(project_id, created_at, id);
COMMENT ON TABLE sessions IS 'Coding sessions belonging to projects; each session has its own messages and tasks.';
COMMENT ON TABLE messages IS 'Conversation turns in a coding session.';
COMMENT ON TABLE tasks IS 'Tasks tracked within a coding session.';
COMMIT;
