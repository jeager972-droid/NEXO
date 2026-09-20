-- 004_chat.sql — Nexus Chat (intent-based NLU)
-- Historial de conversación por usuario + auditoría vía global_audit_logs.

CREATE TABLE IF NOT EXISTS chat_messages (
    message_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id    UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    user_id      UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    role         VARCHAR(20) NOT NULL CHECK (role IN ('user','assistant')),
    content      TEXT NOT NULL,
    payload_json JSONB,          -- {intent, confidence, cards, actions}
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_chat_messages_user_ts
    ON chat_messages(user_id, created_at DESC);
COMMENT ON TABLE chat_messages IS 'Historial «Pregúntale a Nexus» — intent, confianza y payload por mensaje. Retención sugerida 90 días (worker de purga).';

-- Políticas institucionales del asistente: rector/coordinador deciden qué
-- capacidades están habilitadas para cada rol. Defaults = matriz base.
CREATE TABLE IF NOT EXISTS school_chat_policies (
    policy_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id   UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    policy_key  VARCHAR(80) NOT NULL,
    enabled     BOOLEAN NOT NULL DEFAULT TRUE,
    updated_by  UUID REFERENCES users(user_id),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(school_id, policy_key)
);
COMMENT ON TABLE school_chat_policies IS 'Interruptores del asistente Nexus por escuela — qué puede pedir cada rol (riesgo, campos de estudiante, agregados, acciones derivadas, smalltalk).';
