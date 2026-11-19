BEGIN;
ALTER TABLE provider_credentials DROP CONSTRAINT IF EXISTS provider_credentials_provider_check,
    ADD CONSTRAINT provider_credentials_provider_check CHECK (provider ~ '^[a-z0-9][a-z0-9.-]{0,79}$'),
    ADD COLUMN IF NOT EXISTS auth_type TEXT NOT NULL DEFAULT 'apiKey' CHECK (auth_type IN ('apiKey','login'));
ALTER TABLE chat_dispatches DROP CONSTRAINT IF EXISTS chat_dispatches_provider_check,
    ADD CONSTRAINT chat_dispatches_provider_check CHECK (provider ~ '^[a-z0-9][a-z0-9.-]{0,79}$');
ALTER TABLE agent_runs DROP CONSTRAINT IF EXISTS agent_runs_provider_check,
    ADD CONSTRAINT agent_runs_provider_check CHECK (provider ~ '^[a-z0-9][a-z0-9.-]{0,79}$');
ALTER TABLE thread_asides DROP CONSTRAINT IF EXISTS thread_asides_provider_check,
    ADD CONSTRAINT thread_asides_provider_check CHECK (provider ~ '^[a-z0-9][a-z0-9.-]{0,79}$');
COMMIT;
