-- =============================================================================
-- 2026-29: Onboarding de grupos + gestión de sensores biométricos
--
-- CAMBIOS:
--   1. schools: + groups_onboarding_completed, groups_onboarding_year, sensor_master_key_hash
--   2. edge_devices: + configured, + group_id (FK a academic_groups)
--   3. Nueva tabla: sensor_revocation_requests (revocación con countdown 1h)
--
-- NOTA: No se eliminan columnas existentes. active se mantiene por compatibilidad
--       con el edge que hace ping. configured es la columna que la UI usa.
-- =============================================================================

-- 1. schools: añadir columnas de onboarding de grupos y master key
ALTER TABLE schools ADD COLUMN IF NOT EXISTS groups_onboarding_completed BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE schools ADD COLUMN IF NOT EXISTS groups_onboarding_year INTEGER;
ALTER TABLE schools ADD COLUMN IF NOT EXISTS sensor_master_key_hash VARCHAR(255);

COMMENT ON COLUMN schools.groups_onboarding_completed IS 'TRUE cuando el rector completó el onboarding de grupos académicos';
COMMENT ON COLUMN schools.groups_onboarding_year IS 'Año electivo para el que se configuraron los grupos';
COMMENT ON COLUMN schools.sensor_master_key_hash IS 'Hash bcrypt de la llave maestra para reconfigurar sensores';

-- 2. edge_devices: añadir configured y group_id
ALTER TABLE edge_devices ADD COLUMN IF NOT EXISTS configured BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE edge_devices ADD COLUMN IF NOT EXISTS group_id UUID REFERENCES academic_groups(group_id);

CREATE INDEX IF NOT EXISTS idx_edge_devices_group ON edge_devices(group_id);

COMMENT ON COLUMN edge_devices.configured IS 'TRUE cuando el rector ha configurado el sensor con su token';

-- 3. Nueva tabla: sensor_revocation_requests
CREATE TABLE IF NOT EXISTS sensor_revocation_requests (
    revocation_id    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    device_id        UUID NOT NULL REFERENCES edge_devices(device_id),
    school_id        UUID NOT NULL REFERENCES schools(school_id),
    requested_by     UUID NOT NULL REFERENCES users(user_id),
    requested_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    executes_at      TIMESTAMPTZ NOT NULL,
    cancelled        BOOLEAN NOT NULL DEFAULT FALSE,
    cancelled_by     UUID REFERENCES users(user_id),
    cancelled_at     TIMESTAMPTZ,
    completed        BOOLEAN NOT NULL DEFAULT FALSE,
    completed_at     TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_revocation_pending ON sensor_revocation_requests(school_id, completed, cancelled);

-- 4. RLS para sensor_revocation_requests (solo si las funciones helper existen)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'get_current_school_id') THEN
        ALTER TABLE sensor_revocation_requests ENABLE ROW LEVEL SECURITY;
        DROP POLICY IF EXISTS srr_select ON sensor_revocation_requests;
        CREATE POLICY srr_select ON sensor_revocation_requests
            FOR SELECT USING (school_id = get_current_school_id() OR get_current_role() = 'SYSTEM_WORKER');
        DROP POLICY IF EXISTS srr_insert ON sensor_revocation_requests;
        CREATE POLICY srr_insert ON sensor_revocation_requests
            FOR INSERT WITH CHECK (school_id = get_current_school_id());
        DROP POLICY IF EXISTS srr_update ON sensor_revocation_requests;
        CREATE POLICY srr_update ON sensor_revocation_requests
            FOR UPDATE USING (school_id = get_current_school_id());
    ELSE
        RAISE NOTICE 'Funciones get_current_* no existen. Saltando RLS para sensor_revocation_requests.';
    END IF;
END $$;

-- 5. Registrar migración (solo si la función register_migration existe)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-29-onboarding-groups-sensors.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'Onboarding de grupos académicos (rector) + gestión de sensores biométricos con revocación con countdown y master key'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Añade groups_onboarding a schools, configured+group_id a edge_devices, tabla sensor_revocation_requests'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro de migración.';
    END IF;
END $$;
