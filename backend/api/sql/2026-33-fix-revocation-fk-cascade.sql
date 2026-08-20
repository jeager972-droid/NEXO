-- =============================================================================
-- 2026-33: Fix FK sensor_revocation_requests -> edge_devices con ON DELETE CASCADE
-- =============================================================================
-- PROBLEMA: sensor_revocation_requests.device_id REFERENCES edge_devices(device_id)
-- sin ON DELETE CASCADE. Cuando el worker de revocación automática intenta
-- DELETE FROM edge_devices, Postgres bloquea con error 23503 porque la fila en
-- sensor_revocation_requests aún referencia el device_id. Esto cascada 500s
-- a todos los endpoints que usan la misma conexión PDO.
--
-- FIX: Cambiar la FK a ON DELETE CASCADE para que al borrar un device,
-- sus revocaciones se borren automáticamente.
-- =============================================================================

-- 1. Limpiar revocaciones pendientes huérfanas (device ya no existe)
DELETE FROM sensor_revocation_requests
WHERE device_id NOT IN (SELECT device_id FROM edge_devices);

-- 2. Marcar como completed las revocaciones pendientes cuyo countdown ya venció
--    pero que no se pudieron procesar por el bug FK
UPDATE sensor_revocation_requests
SET completed = TRUE, completed_at = NOW()
WHERE completed = FALSE AND cancelled = FALSE AND executes_at <= NOW();

-- 3. Eliminar revocaciones pendientes que aún tienen countdown activo
--    (se re-crearán si el usuario vuelve a solicitar la eliminación)
DELETE FROM sensor_revocation_requests
WHERE completed = FALSE AND cancelled = FALSE;

-- 4. Recrear la FK con ON DELETE CASCADE
ALTER TABLE sensor_revocation_requests
    DROP CONSTRAINT IF EXISTS sensor_revocation_requests_device_id_fkey;

ALTER TABLE sensor_revocation_requests
    ADD CONSTRAINT sensor_revocation_requests_device_id_fkey
    FOREIGN KEY (device_id) REFERENCES edge_devices(device_id) ON DELETE CASCADE;
