-- ============================================================
-- T1: Inmutabilidad (Audit Chain) - HMAC-SHA256 Blockchain
-- ============================================================

-- 1. Asegurar columna chain_hash en global_audit_logs
ALTER TABLE global_audit_logs
    ADD COLUMN IF NOT EXISTS chain_hash TEXT,
    ADD COLUMN IF NOT EXISTS prev_audit_id UUID;

-- 2. Índice para validación rápida de cadena
CREATE INDEX IF NOT EXISTS idx_audit_chain_prev
    ON global_audit_logs(prev_audit_id) WHERE prev_audit_id IS NOT NULL;

-- 3. Función para calcular HMAC-SHA256 de la fila
CREATE OR REPLACE FUNCTION fn_calculate_audit_hash(
    p_prev_hash TEXT,
    p_school_id INTEGER,
    p_actor_id INTEGER,
    p_event_type TEXT,
    p_description TEXT,
    p_ip_address TEXT,
    p_created_at TIMESTAMPTZ
)
RETURNS TEXT AS $$
DECLARE
    v_secret TEXT;
    v_payload TEXT;
BEGIN
    v_secret := COALESCE(current_setting('app.nexo_hmac_secret', true), 'default-secret-change-me');
    v_payload := COALESCE(p_prev_hash, 'GENESIS') || '|' ||
                 COALESCE(p_school_id::TEXT, 'NULL') || '|' ||
                 COALESCE(p_actor_id::TEXT, 'NULL') || '|' ||
                 COALESCE(p_event_type, '') || '|' ||
                 COALESCE(p_description, '') || '|' ||
                 COALESCE(p_ip_address, '') || '|' ||
                 COALESCE(p_created_at::TEXT, '');
    RETURN encode(hmac(v_payload, v_secret, 'sha256'), 'hex');
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- 4. Trigger que calcula el hash encadenado antes de insertar
CREATE OR REPLACE FUNCTION fn_audit_chain_trigger()
RETURNS TRIGGER AS $$
DECLARE
    v_prev_hash TEXT;
    v_prev_id UUID;
BEGIN
    -- Obtener el hash anterior de la misma escuela (o global si no hay escuela)
    -- FOR UPDATE bloquea la fila para evitar forks en la cadena bajo concurrencia
    SELECT audit_id, chain_hash
      INTO v_prev_id, v_prev_hash
      FROM global_audit_logs
     WHERE (school_id IS NOT DISTINCT FROM NEW.school_id)
     ORDER BY created_at DESC, audit_id DESC
     LIMIT 1
     FOR UPDATE;

    NEW.prev_audit_id := v_prev_id;
    NEW.chain_hash := fn_calculate_audit_hash(
        v_prev_hash,
        NEW.school_id,
        NEW.actor_id,
        NEW.event_type,
        NEW.description,
        NEW.ip_address,
        NEW.created_at
    );

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_audit_chain ON global_audit_logs;
CREATE TRIGGER trg_audit_chain
    BEFORE INSERT ON global_audit_logs
    FOR EACH ROW EXECUTE FUNCTION fn_audit_chain_trigger();

-- 5. Función de validación de cadena (devuelve JSON con estado)
CREATE OR REPLACE FUNCTION fn_validate_audit_chain(p_school_id INTEGER DEFAULT NULL)
RETURNS JSONB AS $$
DECLARE
    v_current_hash TEXT;
    v_expected_hash TEXT;
    v_prev_hash TEXT;
    v_record RECORD;
    v_broken_at UUID := NULL;
    v_count INTEGER := 0;
    v_valid_count INTEGER := 0;
BEGIN
    v_prev_hash := NULL;

    FOR v_record IN
        SELECT audit_id, school_id, actor_id, event_type, description,
               ip_address, created_at, chain_hash, prev_audit_id
        FROM global_audit_logs
        WHERE (p_school_id IS NULL OR school_id = p_school_id)
        ORDER BY created_at ASC, audit_id ASC
    LOOP
        v_count := v_count + 1;
        v_expected_hash := fn_calculate_audit_hash(
            v_prev_hash,
            v_record.school_id,
            v_record.actor_id,
            v_record.event_type,
            v_record.description,
            v_record.ip_address,
            v_record.created_at
        );

        IF v_record.chain_hash = v_expected_hash THEN
            v_valid_count := v_valid_count + 1;
        ELSE
            v_broken_at := v_record.audit_id;
            EXIT;
        END IF;

        v_prev_hash := v_record.chain_hash;
    END LOOP;

    IF v_broken_at IS NOT NULL THEN
        RETURN jsonb_build_object(
            'status', 'compromised',
            'broken_at_audit_id', v_broken_at,
            'total_checked', v_count,
            'valid_up_to', v_valid_count - 1
        );
    ELSE
        RETURN jsonb_build_object(
            'status', 'ok',
            'total_records', v_count,
            'school_id', p_school_id
        );
    END IF;
END;
$$ LANGUAGE plpgsql;

-- 6. Backfill: recalcular chain_hash para registros existentes sin hash
UPDATE global_audit_logs
   SET chain_hash = 'LEGACY_' || md5(audit_id::TEXT)
 WHERE chain_hash IS NULL;
