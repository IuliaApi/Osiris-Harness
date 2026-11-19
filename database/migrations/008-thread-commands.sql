BEGIN;
CREATE TABLE IF NOT EXISTS thread_goals (
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    goal TEXT NOT NULL CHECK (char_length(goal) BETWEEN 1 AND 2000),
    status TEXT NOT NULL CHECK (status IN ('active','paused','done')),
    PRIMARY KEY (session_id, user_id)
);
CREATE TABLE IF NOT EXISTS thread_asides (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    question TEXT NOT NULL CHECK (char_length(question) BETWEEN 1 AND 6000),
    answer TEXT NOT NULL CHECK (char_length(answer) BETWEEN 1 AND 10000),
    provider TEXT NOT NULL CHECK (provider IN ('openai','anthropic','ollama')),
    model TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX IF NOT EXISTS thread_asides_history ON thread_asides(session_id,user_id,created_at DESC,id DESC);
COMMIT;
