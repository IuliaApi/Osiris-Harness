BEGIN;
CREATE TABLE IF NOT EXISTS chat_permissions (
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    mode TEXT NOT NULL CHECK (mode IN ('ask','allow','yolo')),
    PRIMARY KEY (session_id, user_id)
);
COMMIT;
