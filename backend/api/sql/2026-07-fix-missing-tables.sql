-- ============================================================
-- FIX (2026-07-03): Crear tablas faltantes que causan errores 500
-- Tablas: school_panic_events, system_telemetry
-- ============================================================

BEGIN;

-- Tabla school_panic_events (ya existe en 2026-20-fix-panic-button-session-revocation.sql)
CREATE TABLE IF NOT EXISTS school_panic_events (
    panic_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id UUID NOT NULL REFERENCES schools(school_id),
    triggered_by_user_id UUID NOT NULL REFERENCES users(user_id),
    triggered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    devices_deactivated INTEGER NOT NULL DEFAULT 0,
    metadata_json JSONB
);

CREATE INDEX IF NOT EXISTS idx_school_panic_events_school_time 
ON school_panic_events(school_id, triggered_at DESC);

CREATE INDEX IF NOT EXISTS idx_school_panic_events_latest 
ON school_panic_events(school_id, triggered_at DESC);

-- Tabla system_telemetry (ya existe en 2026-14-system-telemetry.sql)
CREATE TABLE IF NOT EXISTS system_telemetry (
    id          BIGSERIAL       PRIMARY KEY,
    session_id  UUID            NOT NULL,
    app_version TEXT            NOT NULL DEFAULT 'unknown',
    platform    TEXT            NOT NULL DEFAULT 'web'
                                CHECK (platform IN ('web', 'desktop', 'android', 'ios')),
    event_type  TEXT            NOT NULL
                                CHECK (event_type IN (
                                    'JS_ERROR',
                                    'API_LATENCY',
                                    'BIOMETRIC_LATENCY',
                                    'APP_PING',
                                    'RENDER_SLOW'
                                )),
    severity    TEXT            NOT NULL DEFAULT 'info'
                                CHECK (severity IN ('debug', 'info', 'warn', 'error')),
    payload     JSONB           NOT NULL DEFAULT '{}',
    user_agent  TEXT,
    created_at  TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_telemetry_created_at
    ON system_telemetry (created_at DESC);

CREATE INDEX IF NOT EXISTS idx_telemetry_event_type
    ON system_telemetry (event_type);

CREATE INDEX IF NOT EXISTS idx_telemetry_severity
    ON system_telemetry (severity)
    WHERE severity IN ('warn', 'error');

CREATE INDEX IF NOT EXISTS idx_telemetry_session
    ON system_telemetry (session_id);

CREATE INDEX IF NOT EXISTS idx_telemetry_payload_gin
    ON system_telemetry USING GIN (payload);

-- RLS para system_telemetry
ALTER TABLE system_telemetry ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS telemetry_select_super_rector ON system_telemetry;
CREATE POLICY telemetry_select_super_rector ON system_telemetry
    FOR SELECT
    USING (
        current_setting('app.current_role', true) = 'SUPER_RECTOR'
    );

DROP POLICY IF EXISTS telemetry_insert_authenticated ON system_telemetry;
CREATE POLICY telemetry_insert_authenticated ON system_telemetry
    FOR INSERT
    WITH CHECK (
        current_setting('app.current_role', true) IS NOT NULL
        AND current_setting('app.current_role', true) != ''
    );

COMMENT ON TABLE system_telemetry IS
    'Telemetría técnica privada de la app NEXO. Sin PII. Solo lectura: SUPER_RECTOR.';

COMMIT;
