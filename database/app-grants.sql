-- Operator-only migration: schema ownership stays with the migration account.
BEGIN;
GRANT USAGE ON SCHEMA public TO osiris_app;
GRANT SELECT ON users TO osiris_app;
-- New accounts inherit the member default. Role assignment and password resets stay operator-only.
GRANT INSERT (id, username, email, password_hash) ON users TO osiris_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON projects, sessions, messages, tasks, project_members,
    provider_credentials, chat_budgets, chat_dispatches, workspace_files, tool_runs, agent_runs,
    auth_rate_limits TO osiris_app;
GRANT SELECT, INSERT, UPDATE ON chat_permissions TO osiris_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON thread_goals TO osiris_app;
GRANT SELECT, INSERT ON thread_asides TO osiris_app;
GRANT SELECT, INSERT, DELETE ON auth_sessions, auth_audit TO osiris_app;
GRANT USAGE ON SEQUENCE auth_audit_id_seq TO osiris_app;
COMMIT;
