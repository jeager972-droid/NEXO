-- =============================================================================
-- FIX: aplica lo que falló en nexo_full_migration.sql por problema de orden
-- (get_current_school_id y register_migration se definían DESPUÉS de usarse)
-- =============================================================================
-- Este archivo es redundante si ya tienes la versión corregida de nexo_full_migration.sql
-- Solo ejecutar si la migración original falló por errores de orden o tipos
-- =============================================================================

-- 1. Política SELECT que faltó en system_telemetry
DROP POLICY IF EXISTS telemetry_select_super_rector ON system_telemetry;
CREATE POLICY telemetry_select_super_rector ON system_telemetry
    FOR SELECT USING (get_current_school_id() IS NOT NULL);

-- 2. Registrar la migración (con casts explícitos para evitar el error de
--    resolución de tipos que dio CURRENT_USER como tipo "name")
SELECT register_migration(
    'nexo_full_migration.sql'::VARCHAR,
    '2026-05'::VARCHAR,
    'Esquema base completo consolidado: tablas, índices, constraints, triggers, funciones, RLS, particiones, seed mínimo'::TEXT,
    NULL::VARCHAR,
    CURRENT_USER::VARCHAR,
    NULL::INTEGER,
    'Incluye consolidación de múltiples migraciones antiguas. Generación UUID. (Aplicado vía fix_migration.sql por error de orden en script original)'::TEXT
);

-- 3. Verificación
SELECT policyname, cmd FROM pg_policies WHERE tablename = 'system_telemetry';
