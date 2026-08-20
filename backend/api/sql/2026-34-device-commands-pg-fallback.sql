-- =============================================================================
-- 2026-34: Tabla device_commands como fallback cuando Redis no está disponible
-- =============================================================================
-- PROBLEMA: Los comandos a dispositivos edge (ENROLL_REQUEST, AUTHORIZE_EXIT, etc.)
-- se encolan exclusivamente en Redis. Cuando Redis cae, los comandos se pierden
-- y el backend retorna 500.
--
-- FIX: Crear tabla device_commands en PostgreSQL como fallback. Cuando Redis
-- no esté disponible, los comandos se guardan aquí y el edge los recoge via
-- /devices/commands (que ya hace rPop de Redis + ahora también SELECT de esta tabla).
-- =============================================================================

CREATE TABLE IF NOT EXISTS device_commands (
    command_id    BIGSERIAL PRIMARY KEY,
    device_id     UUID NOT NULL REFERENCES edge_devices(device_id) ON DELETE CASCADE,
    command       TEXT NOT NULL,
    payload       JSONB NOT NULL DEFAULT '{}'::jsonb,
    issued_at     BIGINT NOT NULL,
    issued_by     UUID,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    delivered_at  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_device_commands_pending
    ON device_commands(device_id, delivered_at)
    WHERE delivered_at IS NULL;
