BEGIN;
ALTER TABLE sessions
    ALTER COLUMN project_id DROP NOT NULL,
    ADD COLUMN IF NOT EXISTS owner_id TEXT REFERENCES users(id);

-- Existing project sessions already have exactly one parent; private sessions
-- belong directly to a user and must not also inherit a project's permissions.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'sessions'::regclass AND conname = 'sessions_one_parent') THEN
        ALTER TABLE sessions ADD CONSTRAINT sessions_one_parent CHECK (num_nonnulls(project_id, owner_id) = 1);
    END IF;
END $$;
CREATE INDEX IF NOT EXISTS sessions_owner_created_idx ON sessions(owner_id, created_at, id) WHERE owner_id IS NOT NULL;
COMMENT ON TABLE sessions IS 'Coding sessions belonging to either a project or an individual user; each has its own messages and tasks.';
COMMIT;
