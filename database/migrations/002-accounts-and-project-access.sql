-- Existing accounts remain disabled until provisioned locally. No seed credential.
BEGIN;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_hash text;
ALTER TABLE users ADD COLUMN IF NOT EXISTS role text NOT NULL DEFAULT 'member' CHECK (role IN ('viewer','member','owner','admin'));
ALTER TABLE projects ADD COLUMN IF NOT EXISTS visibility text NOT NULL DEFAULT 'private' CHECK (visibility IN ('private','public'));
CREATE TABLE IF NOT EXISTS project_members (
    project_id text NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id text NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role text NOT NULL CHECK (role IN ('viewer','member')),
    PRIMARY KEY (project_id,user_id)
);
CREATE TABLE IF NOT EXISTS auth_sessions (
    token_hash text PRIMARY KEY CHECK (length(token_hash) = 64),
    user_id text NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at timestamptz NOT NULL
);
CREATE INDEX IF NOT EXISTS auth_sessions_user ON auth_sessions(user_id);
CREATE INDEX IF NOT EXISTS auth_sessions_expiry ON auth_sessions(expires_at);
CREATE TABLE IF NOT EXISTS auth_rate_limits (
    bucket text PRIMARY KEY,
    expires_at timestamptz NOT NULL,
    hits integer NOT NULL CHECK (hits > 0)
);
CREATE INDEX IF NOT EXISTS auth_rate_expiry ON auth_rate_limits(expires_at);
CREATE TABLE IF NOT EXISTS auth_audit (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    request_id text NOT NULL,
    actor_id text,
    event text NOT NULL,
    target_id text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX IF NOT EXISTS auth_audit_created ON auth_audit(created_at);

-- Mutations and their audit records commit or roll back together. Never retain
-- before/after row images, request bodies, passwords, chat text or credentials.
CREATE OR REPLACE FUNCTION osiris_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    actor text := nullif(current_setting('osiris.actor',true),'');
    req text := coalesce(nullif(current_setting('osiris.request',true),''),'local');
    target text;
BEGIN
    IF TG_TABLE_NAME = 'project_members' THEN
        IF TG_OP = 'DELETE' THEN target := OLD.project_id || '/' || OLD.user_id;
        ELSE target := NEW.project_id || '/' || NEW.user_id; END IF;
    ELSE
        IF TG_OP = 'DELETE' THEN target := OLD.id; ELSE target := NEW.id; END IF;
    END IF;
    INSERT INTO auth_audit(request_id,actor_id,event,target_id) VALUES(req,actor,TG_TABLE_NAME || '.' || lower(TG_OP),target);
    RETURN NULL;
END $$;
DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['projects','sessions','messages','tasks','project_members'] LOOP
        EXECUTE format('CREATE OR REPLACE TRIGGER osiris_audit AFTER INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION osiris_audit_mutation()',t);
    END LOOP;
END $$;
COMMENT ON TABLE users IS 'Local application accounts. Passwords are salted adaptive hashes; a null hash disables login.';
COMMIT;
