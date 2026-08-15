-- =============================================================================
-- 2026-22-security-definer-search-path.sql
-- =============================================================================
-- PROPÓSITO: Añadir SET search_path a todas las funciones SECURITY DEFINER
--            para prevenir privilege escalation vía search_path hijacking.
--
-- HALLAZGO: VF-002 (NEXO-INFRA-001) — 8+2 funciones SECURITY DEFINER sin search_path
-- SEVERIDAD: P0 CRITICAL
--
-- FUNCIONES AFECTADAS (nexo_full_migration.sql):
--   1. migration_was_executed(VARCHAR)
--   2. register_migration(VARCHAR, VARCHAR, TEXT, VARCHAR, VARCHAR, INTEGER, TEXT)
--   3. fn_calculate_audit_hash(TEXT, UUID, UUID, TEXT, TEXT, TEXT, TIMESTAMPTZ)
--   4. get_current_school_id()
--   5. get_current_role()
--   6. is_student_present_today(UUID, UUID)
--   7. has_active_permiso(UUID, UUID)
--   8. get_active_permiso_info(UUID, UUID)
--
-- FUNCIONES AFECTADAS (migration_iteracion3.sql — sobrescriben nexo_full):
--   9. fn_calculate_student_risk(UUID, UUID, INTEGER)
--  10. fn_recalculate_school_metrics(UUID)
--
-- MÉTODO: ALTER FUNCTION ... SET search_path = public, pg_catalog
--         Esto es ADITIVO — no recrea la función, no cambia su lógica,
--         solo fija el search_path al ejecutarse.
--
-- SEGURIDAD: pg_catalog se incluye para que funciones built-in (uuid_generate_v4,
--            encode, hmac, NOW, COALESCE, etc.) siempre se resuelvan.
--            public es donde viven las tablas de NEXO.
--            Un atacante no puede crear objetos en pg_catalog.
-- =============================================================================

-- 1. migration_was_executed
ALTER FUNCTION migration_was_executed(p_filename VARCHAR) SET search_path = public, pg_catalog;

-- 2. register_migration
ALTER FUNCTION register_migration(
    p_filename          VARCHAR,
    p_version_label     VARCHAR,
    p_description       TEXT,
    p_checksum          VARCHAR,
    p_executed_by       VARCHAR,
    p_execution_time_ms INTEGER,
    p_notes             TEXT
) SET search_path = public, pg_catalog;

-- 3. fn_calculate_audit_hash
ALTER FUNCTION fn_calculate_audit_hash(
    p_prev_hash    TEXT,
    p_school_id    UUID,
    p_actor_id     UUID,
    p_event_type   TEXT,
    p_description  TEXT,
    p_ip_address   TEXT,
    p_created_at   TIMESTAMPTZ
) SET search_path = public, pg_catalog;

-- 4. get_current_school_id
ALTER FUNCTION get_current_school_id() SET search_path = public, pg_catalog;

-- 5. get_current_role
ALTER FUNCTION get_current_role() SET search_path = public, pg_catalog;

-- 6. is_student_present_today
ALTER FUNCTION is_student_present_today(
    p_school_id  UUID,
    p_student_id UUID
) SET search_path = public, pg_catalog;

-- 7. has_active_permiso
ALTER FUNCTION has_active_permiso(
    p_school_id  UUID,
    p_student_id UUID
) SET search_path = public, pg_catalog;

-- 8. get_active_permiso_info
ALTER FUNCTION get_active_permiso_info(
    p_school_id  UUID,
    p_student_id UUID
) SET search_path = public, pg_catalog;

-- 9. fn_calculate_student_risk (sobrescrita en migration_iteracion3.sql)
ALTER FUNCTION fn_calculate_student_risk(
    p_student_id  UUID,
    p_school_id   UUID,
    p_window_days INTEGER
) SET search_path = public, pg_catalog;

-- 10. fn_recalculate_school_metrics (sobrescrita en migration_iteracion3.sql)
ALTER FUNCTION fn_recalculate_school_metrics(
    p_school_id UUID
) SET search_path = public, pg_catalog;

-- =============================================================================
-- VERIFICACIÓN POST-MIGRACIÓN:
--
-- SELECT p.proname AS function_name, pg_get_function_arguments(p.oid) AS args,
--        pg_get_functiondef(p.oid) LIKE '%SET search_path = public, pg_catalog%' AS has_search_path
-- FROM pg_proc p JOIN pg_namespace n ON p.pronamespace = n.oid
-- WHERE n.nspname = 'public' AND p.prosecdef = true
-- ORDER BY p.proname;
-- Expected: 10 rows, all with has_search_path = true
-- =============================================================================
