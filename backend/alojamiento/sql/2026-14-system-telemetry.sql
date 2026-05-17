-- ============================================================
-- NEXO — Migración 14: Telemetría Privada del Desarrollador
-- Tabla: system_telemetry
-- RLS : Solo SUPER_RECTOR puede leer. Cualquier rol autenticado
--       puede insertar (controlado también en PHP).
-- PII : Esta tabla NO almacena datos personales identificables.
-- ============================================================

BEGIN;

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

-- ── Índices ────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_telemetry_created_at
    ON system_telemetry (created_at DESC);

CREATE INDEX IF NOT EXISTS idx_telemetry_event_type
    ON system_telemetry (event_type);

CREATE INDEX IF NOT EXISTS idx_telemetry_severity
    ON system_telemetry (severity)
    WHERE severity IN ('warn', 'error');

CREATE INDEX IF NOT EXISTS idx_telemetry_session
    ON system_telemetry (session_id);

-- Índice GIN para búsquedas sobre el JSONB payload
CREATE INDEX IF NOT EXISTS idx_telemetry_payload_gin
    ON system_telemetry USING GIN (payload);

-- ── Row Level Security ─────────────────────────────────────
ALTER TABLE system_telemetry ENABLE ROW LEVEL SECURITY;

-- Lectura exclusiva para SUPER_RECTOR
DROP POLICY IF EXISTS telemetry_select_super_rector ON system_telemetry;
CREATE POLICY telemetry_select_super_rector ON system_telemetry
    FOR SELECT
    USING (
        current_setting('app.current_role', true) = 'SUPER_RECTOR'
    );

-- Escritura para cualquier sesión autenticada (rol seteado por requireAuth)
DROP POLICY IF EXISTS telemetry_insert_authenticated ON system_telemetry;
CREATE POLICY telemetry_insert_authenticated ON system_telemetry
    FOR INSERT
    WITH CHECK (
        current_setting('app.current_role', true) IS NOT NULL
        AND current_setting('app.current_role', true) != ''
    );

-- ── Retención automática (ejecutar via pg_cron o cleanup worker) ──
-- DELETE FROM system_telemetry WHERE created_at < NOW() - INTERVAL '90 days';

COMMENT ON TABLE system_telemetry IS
    'Telemetría técnica privada de la app NEXO. Sin PII. Solo lectura: SUPER_RECTOR.';

COMMIT;
