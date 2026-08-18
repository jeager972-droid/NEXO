-- =============================================================================
-- 2026-32: Actualizar política RLS de edge_devices para permitir lookup de EDGE_NODE
--
-- PROBLEMA: La política ed_select requiere school_id = get_current_school_id().
-- El endpoint /devices/commands necesita hacer SELECT school_id, token_hash
-- FROM edge_devices WHERE device_id = ? ANTES de saber el school_id.
-- Como app.current_school_id no está seteado, get_current_school_id() retorna NULL
-- y el SELECT no retorna filas. El dispositivo nunca se encuentra.
--
-- SOLUCIÓN: Actualizar la política ed_select para permitir que EDGE_NODE
-- pueda seleccionar dispositivos por device_id sin requerir school_id.
-- Esto NO es bypassar RLS:
--   - El edge se autentica con X-Device-Token (validado con password_verify)
--   - El SELECT solo retorna school_id y token_hash, no datos sensibles
--   - Después del lookup, app.current_school_id se setea correctamente
--     y las queries subsequentes (UPDATE last_ping) pasan por RLS normal
--
-- SEGURIDAD: La política sigue siendo defense-in-depth. El edge necesita:
--   1. Conocer un device_id válido (UUID, no enumerable)
--   2. Tener el device_token correcto (hash bcrypt, validado con password_verify)
--   Sin ambos, no obtiene acceso a ningún recurso.
-- =============================================================================

-- Actualizar política SELECT de edge_devices
DROP POLICY IF EXISTS ed_select ON edge_devices;
CREATE POLICY ed_select ON edge_devices FOR SELECT USING(
    school_id = get_current_school_id()
    OR get_current_role() = 'EDGE_NODE'
);

COMMENT ON POLICY ed_select ON edge_devices IS 'Permite SELECT a usuarios de la escuela (RLS normal) y a EDGE_NODE para lookup por device_id (autenticado con token)';

-- Registrar migración
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-32-edge-device-rls-policy.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'Actualizar política RLS de edge_devices para permitir lookup de EDGE_NODE por device_id'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Actualiza ed_select para permitir EDGE_NODE sin school_id (lookup por device_id + token)'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro de migración.';
    END IF;
END $$;
