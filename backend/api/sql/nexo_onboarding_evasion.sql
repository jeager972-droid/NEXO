-- =============================================================================
-- nexo_onboarding_evasion.sql
-- =============================================================================
-- Migración: Onboarding de horarios institucionales + lógica de evasión.
--
-- NUEVAS TABLAS:
--   school_schedule_config   — Configuración de horarios a nivel institución
--   school_time_blocks       — Bloques horarios (para colegios que rotan salones)
--
-- MODIFICACIONES:
--   schools                  — ADD onboarding_completed
--   class_exit_authorizations — ADD status (ACTIVE/EXPIRED/COMPLETED/CANCELLED)
--
-- NUEVOS PERMISOS:
--   operations.situacion_critica ya existe. No se requieren nuevos permisos.
--
-- NUEVOS INCIDENT_TYPES (sin CHECK constraint, son VARCHAR libre):
--   EVASION_INTERNA, PERMISO_EXPIRADO, SALIDA_RECREO
--
-- RLS: Todas las nuevas tablas tienen RLS basado en get_current_school_id().
-- =============================================================================

-- =============================================================================
-- 1. school_schedule_config — Config horarios institucionales
-- =============================================================================
CREATE TABLE IF NOT EXISTS school_schedule_config (
    school_id              UUID PRIMARY KEY REFERENCES schools(school_id),
    rotates_classrooms     BOOLEAN NOT NULL DEFAULT FALSE,
    work_shift             VARCHAR(50) NOT NULL DEFAULT 'mañana',
    entry_time             TIME,
    exit_time              TIME,
    recess_start_time      TIME,
    recess_end_time        TIME,
    onboarding_completed   BOOLEAN NOT NULL DEFAULT FALSE,
    onboarding_completed_by UUID REFERENCES users(user_id),
    onboarding_completed_at TIMESTAMPTZ,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE school_schedule_config IS 'Configuración de horarios a nivel institución. Creada durante el onboarding obligatorio de coordinador/rector.';
COMMENT ON COLUMN school_schedule_config.rotates_classrooms IS 'TRUE = colegio rota de salones (estudiantes ponen huella entre clases). FALSE = colegio no rota (estudiante entra una vez y sale al final).';
COMMENT ON COLUMN school_schedule_config.work_shift IS 'mañana, tarde, completa';
COMMENT ON COLUMN school_schedule_config.entry_time IS 'Hora de entrada de la jornada del coordinador/rector';
COMMENT ON COLUMN school_schedule_config.exit_time IS 'Hora de salida de la jornada';
COMMENT ON COLUMN school_schedule_config.recess_start_time IS 'Inicio del receso/almuerzo';
COMMENT ON COLUMN school_schedule_config.recess_end_time IS 'Fin del receso/almuerzo';

-- RLS para school_schedule_config
ALTER TABLE school_schedule_config ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS school_schedule_config_select ON school_schedule_config;
CREATE POLICY school_schedule_config_select ON school_schedule_config
    FOR SELECT USING (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_schedule_config_insert ON school_schedule_config;
CREATE POLICY school_schedule_config_insert ON school_schedule_config
    FOR INSERT WITH CHECK (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_schedule_config_update ON school_schedule_config;
CREATE POLICY school_schedule_config_update ON school_schedule_config
    FOR UPDATE USING (school_id = get_current_school_id());

-- =============================================================================
-- 2. school_time_blocks — Bloques horarios (colegios que rotan salones)
-- =============================================================================
CREATE TABLE IF NOT EXISTS school_time_blocks (
    block_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id     UUID NOT NULL REFERENCES schools(school_id),
    block_number  INTEGER NOT NULL,
    block_name    VARCHAR(100),
    start_time    TIME NOT NULL,
    end_time      TIME NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(school_id, block_number)
);

COMMENT ON TABLE school_time_blocks IS 'Bloques horarios institucionales. Solo se usa cuando rotates_classrooms=TRUE. Representa las N horas de clase del día.';
COMMENT ON COLUMN school_time_blocks.block_number IS 'Número de bloque (1, 2, 3, ...). Orden cronológico.';

CREATE INDEX IF NOT EXISTS idx_school_time_blocks_school ON school_time_blocks(school_id, block_number);

-- RLS para school_time_blocks
ALTER TABLE school_time_blocks ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS school_time_blocks_select ON school_time_blocks;
CREATE POLICY school_time_blocks_select ON school_time_blocks
    FOR SELECT USING (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_time_blocks_insert ON school_time_blocks;
CREATE POLICY school_time_blocks_insert ON school_time_blocks
    FOR INSERT WITH CHECK (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_time_blocks_update ON school_time_blocks;
CREATE POLICY school_time_blocks_update ON school_time_blocks
    FOR UPDATE USING (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_time_blocks_delete ON school_time_blocks;
CREATE POLICY school_time_blocks_delete ON school_time_blocks
    FOR DELETE USING (school_id = get_current_school_id());

-- =============================================================================
-- 3. schools — ADD onboarding_completed (campo rápido para queries)
-- =============================================================================
ALTER TABLE schools ADD COLUMN IF NOT EXISTS onboarding_completed BOOLEAN NOT NULL DEFAULT FALSE;
COMMENT ON COLUMN schools.onboarding_completed IS 'TRUE cuando el coordinador/rector completó el onboarding de horarios institucionales';

-- =============================================================================
-- 4. class_exit_authorizations — ADD status para tracking de permisos activos
-- =============================================================================
ALTER TABLE class_exit_authorizations ADD COLUMN IF NOT EXISTS status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE';
COMMENT ON COLUMN class_exit_authorizations.status IS 'ACTIVE = permiso vigente, EXPIRED = expiró sin retorno, COMPLETED = estudiante regresó, CANCELLED = cancelado';

CREATE INDEX IF NOT EXISTS idx_class_exit_auth_status ON class_exit_authorizations(school_id, student_id, status, exit_time);

-- =============================================================================
-- 5. Permiso: operations.seguimiento ya existe para RECTOR y COORDINATOR.
--    Verificar y asegurar.
-- =============================================================================
-- RECTOR ya tiene operations.seguimiento (línea 532 del migration base)
-- COORDINATOR ya tiene operations.seguimiento (línea 560 del migration base)
-- RECTOR ya tiene operations.citacion (línea 524)
-- COORDINATOR ya tiene operations.citacion (línea 552)
-- No se requieren cambios adicionales.

-- =============================================================================
-- 6. Función helper: is_student_present_today
--    Usada por operations.php para validar presencia del estudiante.
-- =============================================================================
CREATE OR REPLACE FUNCTION is_student_present_today(
    p_school_id UUID,
    p_student_id UUID
) RETURNS BOOLEAN AS $$
DECLARE
    v_count INTEGER;
    v_bogota_date DATE;
BEGIN
    v_bogota_date := (NOW() AT TIME ZONE 'America/Bogota')::date;

    SELECT COUNT(*) INTO v_count
    FROM biometric_events
    WHERE school_id = p_school_id
      AND student_id = p_student_id
      AND event_timestamp >= v_bogota_date
      AND event_timestamp < (v_bogota_date + INTERVAL '1 day')
      AND event_type LIKE 'INGRESO_%';

    RETURN v_count > 0;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- =============================================================================
-- 7. Función helper: has_active_permiso
--    Verifica si un estudiante tiene un permiso activo (no expirado) ahora.
-- =============================================================================
CREATE OR REPLACE FUNCTION has_active_permiso(
    p_school_id UUID,
    p_student_id UUID
) RETURNS BOOLEAN AS $$
DECLARE
    v_count INTEGER;
    v_now TIMESTAMPTZ;
BEGIN
    v_now := NOW();

    SELECT COUNT(*) INTO v_count
    FROM class_exit_authorizations
    WHERE school_id = p_school_id
      AND student_id = p_student_id
      AND status = 'ACTIVE'
      AND exit_time <= v_now
      AND (return_time IS NULL OR return_time >= v_now);

    RETURN v_count > 0;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- =============================================================================
-- 8. Función helper: get_active_permiso_info
--    Retorna info del permiso activo para un estudiante (para evasión worker).
-- =============================================================================
CREATE OR REPLACE FUNCTION get_active_permiso_info(
    p_school_id UUID,
    p_student_id UUID
) RETURNS TABLE(
    authorization_id UUID,
    exit_time TIMESTAMPTZ,
    return_time TIMESTAMPTZ,
    authorization_reason TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT c.authorization_id, c.exit_time, c.return_time, c.authorization_reason
    FROM class_exit_authorizations c
    WHERE c.school_id = p_school_id
      AND c.student_id = p_student_id
      AND c.status = 'ACTIVE'
      AND c.exit_time <= NOW()
      AND (c.return_time IS NULL OR c.return_time >= NOW())
    ORDER BY c.exit_time DESC
    LIMIT 1;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- =============================================================================
-- REGISTRO DE LA MIGRACIÓN
-- =============================================================================
SELECT register_migration(
    'nexo_onboarding_evasion.sql'::VARCHAR,
    '2026-08'::VARCHAR,
    'Onboarding horarios institucionales + lógica evasión: school_schedule_config, school_time_blocks, class_exit_authorizations.status, funciones helper'::TEXT,
    NULL::VARCHAR,
    CURRENT_USER::VARCHAR,
    NULL::INTEGER,
    'Añade tablas para onboarding obligatorio de horarios, bloques horarios para colegios que rotan, tracking de estado de permisos, y funciones para validación de presencia y permisos activos.'::TEXT
);
