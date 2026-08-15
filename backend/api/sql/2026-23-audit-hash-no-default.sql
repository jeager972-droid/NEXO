-- =============================================================================
-- 2026-23-audit-hash-no-default.sql
-- =============================================================================
-- PROPÓSITO: Eliminar el fallback a 'default-secret-change-me' en
--            fn_calculate_audit_hash para que la cadena de auditoría no sea
--            falsificable si el setting app.nexo_hmac_secret no está configurado.
--
-- HALLAZGO: VF-003 (NEXO-INFRA-002) — HMAC default en fn_calculate_audit_hash
-- SEVERIDAD: P0 CRITICAL
--
-- CAMBIO:
--   ANTES: v_secret := COALESCE(current_setting('app.nexo_hmac_secret',true),
--                               'default-secret-change-me');
--   DESPUÉS: v_secret := current_setting('app.nexo_hmac_secret', true);
--           IF v_secret IS NULL OR v_secret = '' OR v_secret = 'default-secret-change-me' THEN
--               RAISE EXCEPTION 'app.nexo_hmac_secret no configurado. Abortando para prevenir compromiso de cadena de auditoría.';
--           END IF;
--
-- SEGURIDAD: fail-closed. Si el setting no está, la función falla en lugar de
--            usar un secret predecible.
--
-- COMPATIBILIDAD: db.php:60-62 ya setea app.nexo_hmac_secret para todas las
--                 conexiones API y workers (vía getenv('APP_NEXO_HMAC_SECRET')).
--                 worker_audit.php:104-108 ya valida que el secret no sea default
--                 y hace exit(1) si lo es.
--                 Por lo tanto, esta función solo fallará si:
--                 - Una conexión directa a BD (psql) sin set_config intenta insertar
--                   en global_audit_logs (lo cual es CORRECTO — debe fallar)
--                 - APP_NEXO_HMAC_SECRET no está configurada en .env (lo cual es
--                   CORRECTO — el sistema no debe operar sin secret)
--
-- NOTA: Esta migration hace CREATE OR REPLACE FUNCTION, por lo que actualiza
--       la función existente sin afectar datos.
-- =============================================================================

CREATE OR REPLACE FUNCTION fn_calculate_audit_hash(
    p_prev_hash    TEXT,
    p_school_id    UUID,
    p_actor_id     UUID,
    p_event_type   TEXT,
    p_description  TEXT,
    p_ip_address   TEXT,
    p_created_at   TIMESTAMPTZ
) RETURNS TEXT AS $$
DECLARE
    v_secret  TEXT;
    v_payload TEXT;
BEGIN
    v_secret := current_setting('app.nexo_hmac_secret', true);
    IF v_secret IS NULL OR v_secret = '' OR v_secret = 'default-secret-change-me' THEN
        RAISE EXCEPTION 'app.nexo_hmac_secret no configurado. Abortando para prevenir compromiso de cadena de auditoría.';
    END IF;
    v_payload := COALESCE(p_prev_hash,'GENESIS')
        || '|' || COALESCE(p_school_id::TEXT,'NULL')
        || '|' || COALESCE(p_actor_id::TEXT,'NULL')
        || '|' || COALESCE(p_event_type,'')
        || '|' || COALESCE(p_description,'')
        || '|' || COALESCE(p_ip_address,'')
        || '|' || COALESCE(p_created_at::TEXT,'');
    RETURN encode(hmac(v_payload, v_secret, 'sha256'), 'hex');
END;
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

-- =============================================================================
-- VERIFICACIÓN POST-MIGRACIÓN:
--
-- 1. Sin setting (debe fallar):
--    SELECT fn_calculate_audit_hash(NULL, NULL, NULL, 'TEST', 'test', '0.0.0.0', NOW());
--    Expected: ERROR: app.nexo_hmac_secret no configurado.
--
-- 2. Con setting (debe retornar hash):
--    SELECT set_config('app.nexo_hmac_secret', 'test-secret', false);
--    SELECT fn_calculate_audit_hash(NULL, NULL, NULL, 'TEST', 'test', '0.0.0.0', NOW());
--    Expected: hash hex string de 64 caracteres
-- =============================================================================
