BEGIN;

INSERT INTO users (id, username, email) VALUES
    ('usr_demo_one', 'demo_one', 'one@example.test'),
    ('usr_demo_two', 'demo_two', 'two@example.test');

INSERT INTO projects (id, user_id, name, language, repository_url) VALUES
    ('prj_ai', 'usr_demo_one', 'Ai', 'Rust', NULL),
    ('prj_web', 'usr_demo_two', 'website', 'TypeScript', NULL);

INSERT INTO sessions (id, project_id, title, status, created_at, updated_at) VALUES
    ('ses_101', 'prj_ai', 'Investigate crash', 'active',
        '2026-10-10T14:00:00Z', '2026-10-14T15:30:00Z'),
    ('ses_102', 'prj_ai', 'Add tests', 'completed',
        '2026-10-11T10:00:00Z', '2026-10-12T11:00:00Z'),
    ('ses_103', 'prj_ai', 'Review error messages', 'archived',
        '2026-10-09T09:00:00Z', '2026-10-13T16:00:00Z'),
    ('ses_201', 'prj_web', 'Fix navigation', 'active',
        '2026-10-12T09:00:00Z', '2026-10-14T12:00:00Z'),
    ('ses_202', 'prj_web', 'Test contact form', 'completed',
        '2026-10-11T08:00:00Z', '2026-10-13T10:00:00Z');

COMMIT;
