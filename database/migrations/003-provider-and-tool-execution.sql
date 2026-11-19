BEGIN;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0);

CREATE TABLE IF NOT EXISTS provider_credentials (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider TEXT NOT NULL CHECK (provider IN ('openai','anthropic')),
    sealed_key TEXT NOT NULL,
    PRIMARY KEY (user_id, provider)
);

-- Units are dispatched requests, not tokens or dollars. An uncertain upstream
-- outcome still consumes one unit: retries cannot bypass the daily limit.
CREATE TABLE IF NOT EXISTS chat_budgets (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    day DATE NOT NULL,
    spent INTEGER NOT NULL DEFAULT 0 CHECK (spent >= 0),
    reserved INTEGER NOT NULL DEFAULT 0 CHECK (reserved >= 0),
    PRIMARY KEY (user_id, day)
);
CREATE TABLE IF NOT EXISTS chat_dispatches (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    day DATE NOT NULL,
    session_id TEXT REFERENCES sessions(id) ON DELETE SET NULL,
    user_message_id TEXT REFERENCES messages(id) ON DELETE SET NULL,
    assistant_message_id TEXT REFERENCES messages(id) ON DELETE SET NULL,
    provider TEXT NOT NULL CHECK (provider IN ('openai','anthropic','ollama')),
    model TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMPTZ,
    outcome TEXT CHECK (outcome IN ('answered','failed')),
    CHECK ((completed_at IS NULL) = (outcome IS NULL)),
    FOREIGN KEY (user_id, day) REFERENCES chat_budgets(user_id, day) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS chat_budgets_retention ON chat_budgets(day);

-- The workspace is intentionally virtual: names are keys, never host paths.
CREATE TABLE IF NOT EXISTS workspace_files (
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    path TEXT NOT NULL CHECK (path ~ '^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$'),
    content TEXT NOT NULL CHECK (octet_length(content) <= 8192),
    PRIMARY KEY (session_id, path)
);
CREATE TABLE IF NOT EXISTS tool_runs (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    operation TEXT NOT NULL CHECK (operation IN ('workspace.read','workspace.write')),
    path TEXT NOT NULL CHECK (path ~ '^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$'),
    content TEXT CHECK (octet_length(content) <= 8192),
    CHECK ((content IS NOT NULL) = (operation = 'workspace.write')),
    status TEXT NOT NULL DEFAULT 'requested' CHECK (status IN ('requested','approved','completed')),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    requested_by TEXT NOT NULL REFERENCES users(id),
    approved_by TEXT REFERENCES users(id),
    executed_by TEXT REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMPTZ,
    executed_at TIMESTAMPTZ,
    result TEXT,
    CHECK ((status <> 'requested') = (approved_by IS NOT NULL)),
    CHECK ((status <> 'requested') = (approved_at IS NOT NULL)),
    CHECK ((status = 'completed') = (executed_by IS NOT NULL)),
    CHECK ((status = 'completed') = (executed_at IS NOT NULL)),
    CHECK ((status = 'completed') = (result IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS tool_runs_session_created ON tool_runs(session_id, created_at DESC, id DESC);
COMMENT ON TABLE tool_runs IS 'Session-scoped approved synthetic workspace operations; removed with their session.';
COMMENT ON TABLE tasks IS 'Versioned task state: pending to running, running to completed or failed, failed to pending for retry.';
COMMIT;
