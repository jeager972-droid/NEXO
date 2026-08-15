-- =============================================================================
-- 2026-27-notifications-dedup.sql
-- =============================================================================
-- PROPÓSITO: Añadir columna dedup_key a notifications + unique index para
--            prevenir notificaciones duplicadas.
--
-- HALLAZGO: VF-010 — Notificaciones sin deduplicación
-- SEVERIDAD: P1
--
-- CAMBIO:
--   1. ALTER TABLE notifications ADD COLUMN dedup_key VARCHAR(64)
--   2. CREATE UNIQUE INDEX ON notifications (dedup_key) WHERE dedup_key IS NOT NULL
--   3. Los INSERTs que usen dedup_key + ON CONFLICT (dedup_key) DO NOTHING
--      serán deduplicados automáticamente.
--   4. Los INSERTs que no seteen dedup_key (NULL) no se ven afectados.
--
-- SEGURIDAD: ALTER TABLE ADD COLUMN es aditivo (no afecta datos existentes).
--            El unique index es parcial (WHERE dedup_key IS NOT NULL) por lo que
--            no afecta notificaciones existentes sin dedup_key.
--
-- COMPATIBILIDAD: Los INSERTs existentes que no incluyan dedup_key seguirán
--                 funcionando sin cambios (dedup_key será NULL por defecto).
-- =============================================================================

ALTER TABLE notifications ADD COLUMN IF NOT EXISTS dedup_key VARCHAR(64);

CREATE UNIQUE INDEX IF NOT EXISTS uq_notifications_dedup
ON notifications (dedup_key)
WHERE dedup_key IS NOT NULL;

-- =============================================================================
-- VERIFICACIÓN POST-MIGRACIÓN:
--
-- SELECT column_name FROM information_schema.columns
-- WHERE table_name = 'notifications' AND column_name = 'dedup_key';
-- Expected: 1 row
--
-- SELECT indexname FROM pg_indexes WHERE tablename = 'notifications'
--   AND indexname = 'uq_notifications_dedup';
-- Expected: 1 row
-- =============================================================================
