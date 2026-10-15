SELECT id, project_id, title, status, created_at, updated_at
FROM sessions
ORDER BY created_at, id;

PREPARE osiris_session(text) AS
SELECT id, project_id, title, status, created_at, updated_at
FROM sessions
WHERE id = $1;

EXECUTE osiris_session('ses_101');
EXECUTE osiris_session('ses_missing');
DEALLOCATE osiris_session;
