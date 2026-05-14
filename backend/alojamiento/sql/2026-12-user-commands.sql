-- ============================================================
-- Tabla user_commands para auditoría de operaciones ejecutadas
-- ============================================================

CREATE TABLE IF NOT EXISTS user_commands (
    command_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id INTEGER REFERENCES schools(school_id) ON DELETE CASCADE,
    executed_by_user_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    command_type TEXT NOT NULL,
    command_payload JSONB DEFAULT '{}',
    executed_at TIMESTAMPTZ DEFAULT NOW(),
    metadata_json JSONB DEFAULT '{}'
);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_user_commands_school_executed
    ON user_commands(school_id, executed_at DESC);
