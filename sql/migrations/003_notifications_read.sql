-- Migración 003 — estado de lectura de notificaciones
-- El PWA marcaba leído solo en localStorage/sessionStorage: al reingresar,
-- todo volvía a contar como pendiente. read_at lo persiste en servidor.
BEGIN;

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS read_at TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_notifications_unread
    ON notifications(user_id, created_at DESC) WHERE read_at IS NULL;

COMMIT;
