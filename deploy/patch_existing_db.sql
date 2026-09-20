-- =============================================================================
-- patch_existing_db.sql — parcha una base de datos NEXO EXISTENTE al esquema
-- consolidado actual. Solo para DBs creadas antes de la consolidación (ETAPA 7).
-- Idempotente — se puede correr varias veces sin daño.
--
-- Uso:  psql $DATABASE_URL -f deploy/patch_existing_db.sql
--   o en Render: Dashboard → Postgres → Connect → External psql command
-- =============================================================================

-- notifications.dedup_key — dedup de notificaciones (workers usan ON CONFLICT)
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS dedup_key VARCHAR(64);
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS origin_type VARCHAR(40);
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS origin_id UUID;
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS read_at TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS idx_notifications_origin ON notifications(origin_type, origin_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_notifications_dedup
    ON notifications (dedup_key) WHERE dedup_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, created_at DESC);

-- Chat «Pregúntale a Nexus» — historial + políticas institucionales
CREATE TABLE IF NOT EXISTS chat_messages (
    message_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id    UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    user_id      UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    role         VARCHAR(20) NOT NULL CHECK (role IN ('user','assistant')),
    content      TEXT NOT NULL,
    payload_json JSONB,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_chat_messages_user_ts
    ON chat_messages(user_id, created_at DESC);
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS session_id UUID;
CREATE INDEX IF NOT EXISTS idx_chat_messages_session
    ON chat_messages(session_id) WHERE session_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS school_chat_policies (
    policy_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id   UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    policy_key  VARCHAR(80) NOT NULL,
    enabled     BOOLEAN NOT NULL DEFAULT TRUE,
    updated_by  UUID REFERENCES users(user_id),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(school_id, policy_key)
);

ALTER TABLE chat_messages ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS cm_select ON chat_messages;
DROP POLICY IF EXISTS cm_insert ON chat_messages;
CREATE POLICY cm_select ON chat_messages FOR SELECT
    USING(school_id = get_current_school_id() OR get_current_role() IN ('SYSTEM_WORKER','SUPER_ADMIN'));
CREATE POLICY cm_insert ON chat_messages FOR INSERT
    WITH CHECK(school_id = get_current_school_id() OR get_current_role() IN ('SYSTEM_WORKER','EDGE_NODE'));

ALTER TABLE school_chat_policies ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS scp_select ON school_chat_policies;
DROP POLICY IF EXISTS scp_insert ON school_chat_policies;
DROP POLICY IF EXISTS scp_update ON school_chat_policies;
CREATE POLICY scp_select ON school_chat_policies FOR SELECT
    USING(school_id = get_current_school_id() OR get_current_role() IN ('SYSTEM_WORKER','SUPER_ADMIN'));
CREATE POLICY scp_insert ON school_chat_policies FOR INSERT
    WITH CHECK(school_id = get_current_school_id() OR get_current_role() IN ('SUPER_ADMIN'));
CREATE POLICY scp_update ON school_chat_policies FOR UPDATE
    USING(school_id = get_current_school_id() OR get_current_role() IN ('SUPER_ADMIN'));
