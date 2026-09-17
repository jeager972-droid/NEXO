-- =============================================================================
-- 002_cierre_verificaciones.sql — Cierre de verificaciones V-xxx (post Bloque D)
-- =============================================================================
-- Cambios:
--   1. class_exit_authorizations.actual_return_time (V-009/063)
--   2. student_tracking: dependency/assigned_to/origin (V-069/151)
--   3. risk_rules.detect_only (V-377)
--   4. RLS teacher_alert_rules + school_notification_routes
--   5. twilio_message_types: HORARIO/SEGUIMIENTO/ABSENCE_FOLLOWUP
--   6. fn_risk_level_rank + fn_evaluate_student_risk con combinaciones reales,
--      detect_only y derivación automática a student_tracking (V-166/378/436/564)
-- Idempotente: IF NOT EXISTS / CREATE OR REPLACE / ON CONFLICT.
-- =============================================================================

BEGIN;

-- 1. Hora real de retorno + contexto esperado en permisos de salida de clase
ALTER TABLE class_exit_authorizations ADD COLUMN IF NOT EXISTS actual_return_time TIMESTAMPTZ;
ALTER TABLE class_exit_authorizations ADD COLUMN IF NOT EXISTS schedule_id UUID;

-- 2. Seguimiento con dependencia responsable y origen
ALTER TABLE student_tracking ADD COLUMN IF NOT EXISTS dependency VARCHAR(60);
ALTER TABLE student_tracking ADD COLUMN IF NOT EXISTS assigned_to_user_id UUID REFERENCES users(user_id);
ALTER TABLE student_tracking ADD COLUMN IF NOT EXISTS origin_type VARCHAR(40) DEFAULT 'manual';
ALTER TABLE student_tracking ADD COLUMN IF NOT EXISTS origin_id UUID;
CREATE INDEX IF NOT EXISTS idx_tracking_origin ON student_tracking(origin_type, origin_id);

-- 3. Regla de riesgo en modo "detectar sin alertar"
ALTER TABLE risk_rules ADD COLUMN IF NOT EXISTS detect_only BOOLEAN NOT NULL DEFAULT FALSE;

-- 3b. Auditoría de despliegues OTA (columna requerida por SchemaIntegrityTest)
ALTER TABLE ota_deployments ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

-- 4. RLS faltantes
ALTER TABLE teacher_alert_rules ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tar_select ON teacher_alert_rules;
DROP POLICY IF EXISTS tar_insert ON teacher_alert_rules;
DROP POLICY IF EXISTS tar_update ON teacher_alert_rules;
DROP POLICY IF EXISTS tar_delete ON teacher_alert_rules;
CREATE POLICY tar_select ON teacher_alert_rules FOR SELECT USING(school_id = get_current_school_id() OR get_current_role() IN ('SYSTEM_WORKER','SUPER_ADMIN'));
CREATE POLICY tar_insert ON teacher_alert_rules FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY tar_update ON teacher_alert_rules FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY tar_delete ON teacher_alert_rules FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE school_notification_routes ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS snr_select ON school_notification_routes;
DROP POLICY IF EXISTS snr_insert ON school_notification_routes;
DROP POLICY IF EXISTS snr_update ON school_notification_routes;
DROP POLICY IF EXISTS snr_delete ON school_notification_routes;
CREATE POLICY snr_select ON school_notification_routes FOR SELECT USING(school_id = get_current_school_id() OR get_current_role() IN ('SYSTEM_WORKER','SUPER_ADMIN'));
CREATE POLICY snr_insert ON school_notification_routes FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY snr_update ON school_notification_routes FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY snr_delete ON school_notification_routes FOR DELETE USING(school_id = get_current_school_id());

-- 5. Tipos de mensaje nuevos
INSERT INTO twilio_message_types (type_code, description) VALUES
    ('HORARIO',            'Modificación de jornada/horario del día'),
    ('SEGUIMIENTO',        'Derivación/seguimiento de caso'),
    ('ABSENCE_FOLLOWUP',   'Recordatorio/escalación por falta de respuesta')
ON CONFLICT (type_code) DO NOTHING;

-- 6. Motor de riesgo: combinaciones reales + detect_only + derivación a tracking
CREATE OR REPLACE FUNCTION fn_risk_level_rank(p_level TEXT) RETURNS INT AS $$
    SELECT CASE p_level
        WHEN 'MUY_ALTA'  THEN 4
        WHEN 'ALTA'      THEN 3
        WHEN 'MODERADA'  THEN 2
        WHEN 'LEVE'      THEN 1
        ELSE 0
    END;
$$ LANGUAGE SQL IMMUTABLE;

CREATE OR REPLACE FUNCTION fn_evaluate_student_risk(
    p_student_id UUID,
    p_school_id  UUID
) RETURNS JSONB AS $$
DECLARE
    v_policy_id UUID;
    v_categories TEXT[];
    v_cat TEXT;
    v_score NUMERIC(8,2);
    v_count INTEGER;
    v_clustering NUMERIC(5,2);
    v_details JSONB;
    v_level VARCHAR;
    v_results JSONB := '{}'::jsonb;
    v_max_level VARCHAR := 'NONE';
    v_max_score NUMERIC(8,2) := 0.0;
    v_combo_record RECORD;
    v_cat_cond JSONB;
    v_cat_level VARCHAR;
    v_combo_all_met BOOLEAN;
    v_trigger_reason TEXT := NULL;
    v_detect_only BOOLEAN := FALSE;
    v_alert_id UUID;
    v_cooldown_until TIMESTAMPTZ;
    v_should_alert BOOLEAN := FALSE;
    v_alert_level VARCHAR;
    v_escalation VARCHAR;
    v_cooldown_days INTEGER;
BEGIN
    SELECT policy_id INTO v_policy_id
    FROM risk_policies
    WHERE school_id = p_school_id AND is_active = TRUE
    ORDER BY version DESC LIMIT 1;
    IF v_policy_id IS NULL THEN
        RETURN jsonb_build_object('error', 'no_active_policy');
    END IF;
    v_categories := ARRAY['asistencia', 'evasion', 'comportamiento'];
    FOREACH v_cat IN ARRAY v_categories LOOP
        SELECT active_score, event_count, clustering_factor, event_details, triggered_level
        INTO v_score, v_count, v_clustering, v_details, v_level
        FROM fn_calculate_category_risk(p_student_id, p_school_id, v_cat, v_policy_id);
        INSERT INTO risk_active_snapshot
            (school_id, student_id, category, active_score, event_count,
             clustering_factor, last_event_at, last_calculated_at, policy_id, metadata_json)
        VALUES (p_school_id, p_student_id, v_cat, v_score, v_count, v_clustering,
                NULL, NOW(), v_policy_id,
                jsonb_build_object('triggered_level', v_level, 'details', v_details))
        ON CONFLICT (school_id, student_id, category) DO UPDATE SET
            active_score = EXCLUDED.active_score,
            event_count = EXCLUDED.event_count,
            clustering_factor = EXCLUDED.clustering_factor,
            last_calculated_at = EXCLUDED.last_calculated_at,
            policy_id = EXCLUDED.policy_id,
            metadata_json = EXCLUDED.metadata_json;
        v_results := v_results || jsonb_build_object(
            v_cat, jsonb_build_object(
                'score', v_score, 'count', v_count,
                'clustering', v_clustering, 'level', v_level
            )
        );
        IF v_level != 'NONE' THEN
            IF v_max_level = 'NONE' OR
               (v_level = 'MUY_ALTA') OR
               (v_level = 'ALTA' AND v_max_level IN ('NONE','LEVE','MODERADA')) OR
               (v_level = 'MODERADA' AND v_max_level IN ('NONE','LEVE')) OR
               (v_level = 'LEVE' AND v_max_level = 'NONE') THEN
                v_max_level := v_level;
                v_max_score := v_score;
            END IF;
        END IF;
    END LOOP;
    -- Capa de combinación: correlación entre categorías. Una combinación se
    -- dispara cuando TODAS las categorías listadas alcanzan su min_level y
    -- puede elevar el nivel resultante por encima de la detección individual.
    FOR v_combo_record IN
        SELECT * FROM risk_combination_rules
        WHERE policy_id = v_policy_id AND is_active = TRUE
    LOOP
        v_combo_all_met := jsonb_array_length(
            COALESCE(v_combo_record.condition_json->'categories', '[]'::jsonb)) > 0;
        FOR v_cat_cond IN
            SELECT * FROM jsonb_array_elements(
                COALESCE(v_combo_record.condition_json->'categories', '[]'::jsonb))
        LOOP
            v_cat_level := COALESCE(
                v_results -> (v_cat_cond->>'category') ->> 'level', 'NONE');
            IF fn_risk_level_rank(v_cat_level) < fn_risk_level_rank(v_cat_cond->>'min_level') THEN
                v_combo_all_met := FALSE;
                EXIT;
            END IF;
        END LOOP;
        IF v_combo_all_met
           AND fn_risk_level_rank(v_combo_record.result_level) > fn_risk_level_rank(v_max_level) THEN
            v_max_level := v_combo_record.result_level;
            v_trigger_reason := 'Combinación: ' || v_combo_record.rule_name
                                || ' — ' || v_combo_record.result_reason;
        END IF;
    END LOOP;
    IF v_max_level != 'NONE' THEN
        v_alert_level := v_max_level;
        v_escalation := CASE v_max_level
            WHEN 'LEVE'      THEN 'ALERTA_PEDAGOGICA'
            WHEN 'MODERADA'  THEN 'SEGUIMIENTO'
            WHEN 'ALTA'      THEN 'INTERVENCION_PRIORITARIA'
            WHEN 'MUY_ALTA'  THEN 'ATENCION_INMEDIATA'
        END;
        SELECT cooldown_until INTO v_cooldown_until
        FROM risk_alerts
        WHERE student_id = p_student_id
          AND school_id = p_school_id
          AND alert_level = v_alert_level
          AND status = 'abierta'
        ORDER BY created_at DESC LIMIT 1;
        v_should_alert := TRUE;
        IF v_cooldown_until IS NOT NULL AND v_cooldown_until > NOW() THEN
            v_should_alert := FALSE;
        END IF;
        IF v_should_alert THEN
            SELECT cooldown_days, detect_only INTO v_cooldown_days, v_detect_only
            FROM risk_rules
            WHERE policy_id = v_policy_id AND risk_level = v_alert_level;
            IF COALESCE(v_detect_only, FALSE) THEN
                -- V-377: detectar sin alertar — queda en snapshot e incidente
                -- auditable, pero no genera risk_alert ni notificación.
                INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                VALUES (uuid_generate_v4(), p_school_id, p_student_id,
                        'RISK_DETECTED_' || v_alert_level, NOW(),
                        jsonb_build_object('risk_score', v_max_score, 'detect_only', TRUE,
                                           'trigger', COALESCE(v_trigger_reason, 'umbral')));
            ELSE
                INSERT INTO risk_alerts (
                    school_id, student_id, alert_level, escalation_state,
                    trigger_category, trigger_rule, trigger_score,
                    policy_id, involved_events, status, cooldown_until, metadata_json
                ) VALUES (
                    p_school_id, p_student_id, v_alert_level, v_escalation,
                    v_cat, COALESCE(v_trigger_reason, 'Riesgo activo superó umbral de ' || v_alert_level),
                    v_max_score, v_policy_id,
                    v_details, 'abierta',
                    CASE WHEN v_cooldown_days > 0
                         THEN NOW() + (v_cooldown_days || ' days')::INTERVAL
                         ELSE NULL END,
                    jsonb_build_object(
                        'engine_version', '3.0',
                        'policy_version', (SELECT version FROM risk_policies WHERE policy_id = v_policy_id),
                        'categories', v_results
                    )
                ) RETURNING alert_id INTO v_alert_id;
                INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                VALUES (uuid_generate_v4(), p_school_id, p_student_id,
                        'RISK_ALERT_' || v_alert_level, NOW(),
                        jsonb_build_object('alert_id', v_alert_id, 'risk_score', v_max_score));
                -- V-069/V-151: derivación automática — una alerta que nace en
                -- estado SEGUIMIENTO instancia el caso de seguimiento si no hay
                -- uno abierto para el estudiante.
                IF v_escalation = 'SEGUIMIENTO'
                   AND NOT EXISTS (SELECT 1 FROM student_tracking
                                   WHERE student_id = p_student_id
                                     AND school_id = p_school_id
                                     AND status = 'en proceso') THEN
                    INSERT INTO student_tracking (school_id, student_id, status, dependency, origin_type, origin_id)
                    VALUES (p_school_id, p_student_id, 'en proceso', 'coordinacion', 'risk_alert', v_alert_id);
                END IF;
            END IF;
        END IF;
    END IF;
    RETURN jsonb_build_object(
        'student_id', p_student_id,
        'policy_id', v_policy_id,
        'categories', v_results,
        'max_level', v_max_level,
        'max_score', v_max_score,
        'detect_only', v_detect_only,
        'alert_generated', v_should_alert AND v_max_level != 'NONE' AND NOT v_detect_only,
        'alert_id', v_alert_id
    );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Helper idempotente (existe en schema.sql; se redefine por si la BD destino
-- fue creada antes de su introducción)
CREATE OR REPLACE FUNCTION assign_permission_to_role(p_role_name VARCHAR, p_permission_code VARCHAR)
RETURNS VOID AS $$
DECLARE v_role_id UUID; v_permission_id UUID;
BEGIN
    SELECT role_id INTO v_role_id FROM roles WHERE role_name = p_role_name;
    SELECT permission_id INTO v_permission_id FROM permissions WHERE permission_code = p_permission_code;
    IF v_role_id IS NOT NULL AND v_permission_id IS NOT NULL THEN
        INSERT INTO role_permissions (role_id, permission_id) VALUES (v_role_id, v_permission_id)
        ON CONFLICT (role_id, permission_id) DO NOTHING;
    END IF;
END;
$$ LANGUAGE plpgsql;

-- 7a. V-342/344: consentimiento/base jurídica del tratamiento biométrico (habeas data)
ALTER TABLE students ADD COLUMN IF NOT EXISTS consent_status VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE';
ALTER TABLE students ADD COLUMN IF NOT EXISTS consent_channel VARCHAR(30);
ALTER TABLE students ADD COLUMN IF NOT EXISTS consent_recorded_at TIMESTAMPTZ;
ALTER TABLE students ADD COLUMN IF NOT EXISTS consent_recorded_by UUID;
ALTER TABLE students ADD COLUMN IF NOT EXISTS consent_document_ref VARCHAR(255);
ALTER TABLE students DROP CONSTRAINT IF EXISTS chk_students_consent_status;
ALTER TABLE students ADD CONSTRAINT chk_students_consent_status CHECK (consent_status IN ('PENDIENTE','OTORGADO','REVOCADO','NO_APLICA'));

-- V-150: status de tracking con workflow formal (NOT VALID: no rompe filas
-- legadas con valores fuera del enum; el código ya solo escribe estos valores)
ALTER TABLE student_tracking DROP CONSTRAINT IF EXISTS chk_tracking_status;
ALTER TABLE student_tracking ADD CONSTRAINT chk_tracking_status
    CHECK (status IN ('en proceso','resuelto','descartado','escalado')) NOT VALID;

-- V-028: vínculo estructurado de notificación → acontecimiento origen
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS origin_type VARCHAR(40);
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS origin_id UUID;
CREATE INDEX IF NOT EXISTS idx_notifications_origin ON notifications(origin_type, origin_id);

CREATE OR REPLACE FUNCTION fn_notifications_origin()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.origin_id IS NULL AND NEW.metadata_json IS NOT NULL THEN
        IF NEW.metadata_json ? 'security_incident_id' THEN
            NEW.origin_type := 'security_incident';
            NEW.origin_id := (NEW.metadata_json->>'security_incident_id')::uuid;
        ELSIF NEW.metadata_json ? 'incident_id' THEN
            NEW.origin_type := 'incident';
            NEW.origin_id := (NEW.metadata_json->>'incident_id')::uuid;
        ELSIF NEW.metadata_json ? 'alert_id' THEN
            NEW.origin_type := 'risk_alert';
            NEW.origin_id := (NEW.metadata_json->>'alert_id')::uuid;
        ELSIF NEW.metadata_json ? 'tracking_id' THEN
            NEW.origin_type := 'student_tracking';
            NEW.origin_id := (NEW.metadata_json->>'tracking_id')::uuid;
        ELSIF NEW.metadata_json ? 'authorization_id' THEN
            NEW.origin_type := 'authorization';
            NEW.origin_id := (NEW.metadata_json->>'authorization_id')::uuid;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_notifications_origin ON notifications;
CREATE TRIGGER trg_notifications_origin
    BEFORE INSERT ON notifications
    FOR EACH ROW
    EXECUTE FUNCTION fn_notifications_origin();

-- V-013/041/058/406: políticas de acción configurables por escuela
CREATE TABLE IF NOT EXISTS school_action_policies (
    policy_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id   UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    event_type  VARCHAR(60) NOT NULL,
    action      VARCHAR(60) NOT NULL DEFAULT 'DEFAULT',
    enabled     BOOLEAN NOT NULL DEFAULT TRUE,
    params      JSONB,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_action_policy UNIQUE (school_id, event_type)
);
CREATE INDEX IF NOT EXISTS idx_sap_school ON school_action_policies(school_id, event_type);

-- 7. V-127: secretaría puede citar al acudiente (canal directo)
SELECT assign_permission_to_role('SECRETARY', 'operations.citacion');
-- 7b. V-046/077: el docente puede extender el bloque en curso (no solo fusionarlo)
SELECT assign_permission_to_role('TEACHER', 'operations.extender_bloque');

COMMIT;
