-- ============================================================
-- F16: Particionamiento de biometric_events por rango de fecha
-- ============================================================

-- 1. Si biometric_events existe como tabla regular, renombrar a legacy
DO $$
DECLARE
    v_relkind CHAR;
BEGIN
    SELECT c.relkind INTO v_relkind
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE c.relname = 'biometric_events'
      AND n.nspname = 'public';

    IF v_relkind = 'r' THEN
        ALTER TABLE biometric_events RENAME TO biometric_events_legacy;
        RAISE NOTICE 'Tabla biometric_events renombrada a biometric_events_legacy';
    END IF;
END $$;

-- 2. Crear tabla particionada (solo si no existe ya como particionada)
CREATE TABLE IF NOT EXISTS biometric_events (
    event_id UUID NOT NULL,
    school_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_timestamp TIMESTAMPTZ NOT NULL,
    source_device VARCHAR(100),
    raw_payload_json JSONB
) PARTITION BY RANGE (event_timestamp);

-- 3. Particiones mensuales (ajustar según necesidad operativa)
CREATE TABLE IF NOT EXISTS biometric_events_2026_05 PARTITION OF biometric_events
    FOR VALUES FROM ('2026-05-01') TO ('2026-06-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_06 PARTITION OF biometric_events
    FOR VALUES FROM ('2026-06-01') TO ('2026-07-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_07 PARTITION OF biometric_events
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_08 PARTITION OF biometric_events
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');

-- Partición default para capturar registros fuera de rango definido
CREATE TABLE IF NOT EXISTS biometric_events_default PARTITION OF biometric_events DEFAULT;

-- 4. Índices críticos sobre la tabla particionada (se propagan a cada partición)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_biometric_events_school_student_time
    ON biometric_events (school_id, student_id, event_timestamp DESC);

-- 5. Índices adicionales ya cubiertos en otros archivos; se mantienen aquí por referencia
--    (ejecutar solo si aún no existen)
-- CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_synced ON audit_trail (synced) WHERE synced = 0;
-- CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rate_limit_ip_window ON rate_limits (rl_key, window_start);
-- CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_jwt_blocklist_jti_exp ON jwt_blocklist (jti, expires_at);
-- CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_students_school_doc ON students (school_id, document_number);

-- 6. Migración opcional de datos legacy (ejecutar con cuidado tras verificar esquemas compatibles):
-- INSERT INTO biometric_events SELECT * FROM biometric_events_legacy;
-- DROP TABLE biometric_events_legacy;
