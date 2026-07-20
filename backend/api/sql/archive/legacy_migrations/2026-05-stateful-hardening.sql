-- ============================================================
-- NEXO Hardening Migration 2026-05: Statefulness + Indexed lookups
-- - Rate limiting persistente en Postgres (multi-contenedor safe)
-- - JWT blocklist persistente en Postgres
-- - whatsapp_phone_normalized indexado con trigger (elimina O(N) regex)
-- ============================================================

-- 1) Rate Limit table (shared across containers)
CREATE TABLE IF NOT EXISTS rate_limits (
    rl_key       TEXT PRIMARY KEY,
    window_start TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    hits         INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_rate_limits_window_start ON rate_limits(window_start);

-- 2) JWT blocklist (revocación distribuida)
CREATE TABLE IF NOT EXISTS jwt_blocklist (
    jti        TEXT PRIMARY KEY,
    revoked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_jwt_blocklist_expires_at ON jwt_blocklist(expires_at);

-- 3) Phone normalization para guardianes (elimina escaneo regex secuencial)
ALTER TABLE guardians
    ADD COLUMN IF NOT EXISTS whatsapp_phone_normalized TEXT;

-- Backfill existente (idempotente)
UPDATE guardians
   SET whatsapp_phone_normalized = regexp_replace(COALESCE(whatsapp_phone, ''), '[^0-9+]', '', 'g')
 WHERE whatsapp_phone_normalized IS NULL
    OR whatsapp_phone_normalized <> regexp_replace(COALESCE(whatsapp_phone, ''), '[^0-9+]', '', 'g');

CREATE INDEX IF NOT EXISTS idx_guardians_whatsapp_normalized
    ON guardians(whatsapp_phone_normalized);

-- Trigger para mantenerla sincronizada
CREATE OR REPLACE FUNCTION fn_guardians_normalize_phone()
RETURNS TRIGGER AS $$
BEGIN
    NEW.whatsapp_phone_normalized := regexp_replace(COALESCE(NEW.whatsapp_phone, ''), '[^0-9+]', '', 'g');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_guardians_normalize_phone ON guardians;
CREATE TRIGGER trg_guardians_normalize_phone
    BEFORE INSERT OR UPDATE OF whatsapp_phone ON guardians
    FOR EACH ROW EXECUTE FUNCTION fn_guardians_normalize_phone();
