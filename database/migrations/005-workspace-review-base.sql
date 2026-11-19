BEGIN;
ALTER TABLE tool_runs ADD COLUMN IF NOT EXISTS base_digest TEXT
    CHECK (base_digest IS NULL OR (operation = 'workspace.write' AND (base_digest = 'missing' OR base_digest ~ '^[0-9a-f]{64}$')));
COMMENT ON COLUMN tool_runs.base_digest IS 'Agent write precondition: missing or SHA-256 of UTF-8 content. NULL for manual requests.';
-- Bound the ordered prefix scanned by glob/grep independently of database locale.
CREATE INDEX IF NOT EXISTS workspace_files_session_path_c ON workspace_files(session_id, path COLLATE "C");
COMMIT;
