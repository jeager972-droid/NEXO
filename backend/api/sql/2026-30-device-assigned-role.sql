-- =============================================================================
-- 2026-30: Asignación de rol a sensores biométricos
--
-- CAMBIOS:
--   1. edge_devices: + assigned_role (VARCHAR, nullable)
--
-- Permite asignar opcionalmente un rol a un sensor para que los usuarios
-- con ese rol (ej: SECRETARY) envíen comandos de enrolamiento al sensor
-- que les corresponde, en lugar de al primer sensor activo.
-- =============================================================================

ALTER TABLE edge_devices ADD COLUMN IF NOT EXISTS assigned_role VARCHAR(50);

COMMENT ON COLUMN edge_devices.assigned_role IS 'Rol opcional asignado al sensor. Si está seteado, los usuarios con ese rol usan este sensor para enrolamiento y comandos.';

-- Registrar migración
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-30-device-assigned-role.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'Asignación opcional de rol a sensores biométricos para enrutar comandos de enrolamiento'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Añade assigned_role a edge_devices'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro de migración.';
    END IF;
END $$;
