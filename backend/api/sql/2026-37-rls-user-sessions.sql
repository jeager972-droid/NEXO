-- =============================================================================
-- 2026-37: RLS en user_sessions (multi-tenant)
--
-- PROBLEMA (C6):
--   user_sessions tiene user_id (que viaja a users.school_id) pero NO tiene
--   RLS habilitado. Las otras 34+ tablas multi-tenant sí lo tienen. Si hay un
--   bypass del middleware o SQL injection, un atacante podría acceder a sesiones
--   de otras escuelas.
--
-- SOLUCIÓN:
--   Habilitar RLS en user_sessions con políticas que hacen JOIN con users para
--   obtener school_id. Las políticas usan get_current_school_id() que lee
--   app.current_school_id seteado por el middleware.
--
--   Nota: user_sessions no tiene school_id directo, así que las políticas
--   usan EXISTS (SELECT 1 FROM users WHERE user_id = user_sessions.user_id
--   AND school_id = get_current_school_id()).
--
--   Excepción: SYSTEM_WORKER y roles del sistema pueden leer sin restricción
--   (necesario para limpieza de sesiones expiradas por workers).
-- =============================================================================

ALTER TABLE user_sessions ENABLE ROW LEVEL SECURITY;

-- SELECT: solo sesiones de usuarios de la escuela actual
DROP POLICY IF EXISTS user_sessions_select ON user_sessions;
CREATE POLICY user_sessions_select ON user_sessions FOR SELECT
    USING (
        get_current_role() = 'SYSTEM_WORKER'
        OR EXISTS (
            SELECT 1 FROM users u
            WHERE u.user_id = user_sessions.user_id
              AND u.school_id = get_current_school_id()
        )
    );

-- INSERT: solo sesiones para usuarios de la escuela actual
DROP POLICY IF EXISTS user_sessions_insert ON user_sessions;
CREATE POLICY user_sessions_insert ON user_sessions FOR INSERT
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM users u
            WHERE u.user_id = user_sessions.user_id
              AND u.school_id = get_current_school_id()
        )
    );

-- UPDATE: solo sesiones de usuarios de la escuela actual
DROP POLICY IF EXISTS user_sessions_update ON user_sessions;
CREATE POLICY user_sessions_update ON user_sessions FOR UPDATE
    USING (
        get_current_role() = 'SYSTEM_WORKER'
        OR EXISTS (
            SELECT 1 FROM users u
            WHERE u.user_id = user_sessions.user_id
              AND u.school_id = get_current_school_id()
        )
    );

-- DELETE: solo sesiones de usuarios de la escuela actual (o SYSTEM_WORKER)
DROP POLICY IF EXISTS user_sessions_delete ON user_sessions;
CREATE POLICY user_sessions_delete ON user_sessions FOR DELETE
    USING (
        get_current_role() = 'SYSTEM_WORKER'
        OR EXISTS (
            SELECT 1 FROM users u
            WHERE u.user_id = user_sessions.user_id
              AND u.school_id = get_current_school_id()
        )
    );

-- Verificar que sensor_revocation_requests ya tiene RLS (migración 2026-29)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_tables
        WHERE tablename = 'sensor_revocation_requests'
          AND rowsecurity = TRUE
    ) THEN
        RAISE NOTICE 'ADVERTENCIA: sensor_revocation_requests no tiene RLS. Aplicar migración 2026-29.';
    END IF;
END $$;

-- Registrar migración
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-37-rls-user-sessions.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'RLS en user_sessions para multi-tenant'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Habilita RLS en user_sessions con políticas que hacen JOIN con users para obtener school_id. Verifica sensor_revocation_requests.'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro.';
    END IF;
END $$;
