BEGIN;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS model TEXT
    CHECK (model IS NULL OR (role = 'assistant' AND btrim(model) <> ''));

-- Older replies stored only the dispatched ID. A successful final dispatch
-- links the exact reply; intermediate tool calls and asides have no such link.
UPDATE messages m SET model = d.model
FROM chat_dispatches d
WHERE m.model IS NULL AND m.role = 'assistant' AND m.id = d.assistant_message_id
    AND m.session_id = d.session_id AND d.outcome = 'answered';

-- Budget retention may have removed the dispatch while the completed run survives.
UPDATE messages m SET model = r.model
FROM agent_runs r
WHERE m.model IS NULL AND m.role = 'assistant' AND m.id = r.assistant_message_id
    AND m.session_id = r.session_id AND r.status = 'completed';
COMMIT;
