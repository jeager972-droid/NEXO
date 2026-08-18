-- =============================================================================
-- 2026-31: Asignación de sensor a usuario específico (no rol genérico)
--
-- CAMBIOS:
--   1. edge_devices: - assigned_role, + assigned_user_id (FK a users)
--
-- Reemplaza la columna assigned_role (VARCHAR) por assigned_user_id (UUID)
-- que referencia a un usuario específico. Solo ese usuario puede usar el sensor.
-- =============================================================================

-- 1. Añadir assigned_user_id
ALTER TABLE edge_devices ADD COLUMN IF NOT EXISTS assigned_user_id UUID REFERENCES users(user_id);

-- 2. Migrar datos existentes: intentar mapear assigned_role a un usuario
-- (no se puede mapear automáticamente, se deja NULL para reasignar manualmente)

-- 3. Eliminar assigned_role
ALTER TABLE edge_devices DROP COLUMN IF EXISTS assigned_role;

CREATE INDEX IF NOT EXISTS idx_edge_devices_assigned_user ON edge_devices(assigned_user_id);

COMMENT ON COLUMN edge_devices.assigned_user_id IS 'Usuario específico asignado al sensor. Solo este usuario puede usar el sensor para enrolamiento y comandos.';

-- 4. Registrar migración
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-31-device-assigned-user.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'Reemplaza assigned_role por assigned_user_id para asignar sensor a usuario específico'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Elimina assigned_role, añade assigned_user_id FK a users'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro de migración.';
    END IF;
END $$;
