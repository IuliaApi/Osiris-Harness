BEGIN;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS agent_events JSONB CHECK (jsonb_typeof(agent_events) = 'array');

-- One recoverable turn per actor/thread. Completed tool traces live with the
-- assistant message, so starting the next turn does not erase the transcript.
CREATE TABLE IF NOT EXISTS agent_runs (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    prompt TEXT NOT NULL,
    provider TEXT NOT NULL CHECK (provider IN ('ollama','openai','anthropic')),
    model TEXT NOT NULL,
    tools_enabled BOOLEAN NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('ready','working','review','completed','failed','cancelled')),
    history JSONB NOT NULL,
    steps JSONB NOT NULL DEFAULT '[]',
    error TEXT,
    user_message_id TEXT REFERENCES messages(id) ON DELETE CASCADE,
    assistant_message_id TEXT REFERENCES messages(id) ON DELETE CASCADE,
    UNIQUE (session_id, user_id)
);
COMMIT;
