-- ============================================================================
-- MIGRACIÓN ITERACIÓN 3 — NEXO
-- Ejecutar en producción con psql:
--   psql -U <usuario> -d <database> -f migration_iteracion3.sql
-- ============================================================================

-- 1. Agregar columna metadata_json a daily_schedule_config
ALTER TABLE daily_schedule_config ADD COLUMN IF NOT EXISTS metadata_json JSONB;

-- 2. Agregar permisos nuevos
INSERT INTO permissions (permission_id, permission_code, description) VALUES
    (uuid_generate_v4(), 'operations.fusionar_bloque', 'Merge class blocks for sensor logic'),
    (uuid_generate_v4(), 'operations.extender_bloque', 'Extend current block end time for the day')
ON CONFLICT (permission_code) DO NOTHING;

-- 3. Asignar permisos a roles
-- fusionar_bloque: TEACHER
INSERT INTO role_permissions (role_permission_id, role_id, permission_id)
SELECT uuid_generate_v4(), r.role_id, p.permission_id
FROM roles r, permissions p
WHERE r.role_name = 'TEACHER' AND p.permission_code = 'operations.fusionar_bloque'
ON CONFLICT DO NOTHING;

-- extender_bloque: RECTOR
INSERT INTO role_permissions (role_permission_id, role_id, permission_id)
SELECT uuid_generate_v4(), r.role_id, p.permission_id
FROM roles r, permissions p
WHERE r.role_name = 'RECTOR' AND p.permission_code = 'operations.extender_bloque'
ON CONFLICT DO NOTHING;

-- extender_bloque: COORDINATOR
INSERT INTO role_permissions (role_permission_id, role_id, permission_id)
SELECT uuid_generate_v4(), r.role_id, p.permission_id
FROM roles r, permissions p
WHERE r.role_name = 'COORDINATOR' AND p.permission_code = 'operations.extender_bloque'
ON CONFLICT DO NOTHING;

-- 4. Actualizar funciones SQL de riesgo con timezone Bogotá
CREATE OR REPLACE FUNCTION fn_calculate_student_risk(p_student_id UUID, p_school_id UUID, p_window_days INTEGER DEFAULT 30) RETURNS JSONB AS $$
DECLARE
    v_late_count INTEGER;
    v_absence_count INTEGER;
    v_total_events INTEGER;
    v_risk_score NUMERIC(5,2);
    v_risk_level VARCHAR(20);
    v_threshold CONSTANT NUMERIC(5,2) := 70.00;
    v_metric_id UUID;
BEGIN
    SELECT
        (SELECT COUNT(*) FROM attendance_incidents WHERE student_id = p_student_id AND school_id = p_school_id AND incident_type = 'LATE_ARRIVAL' AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL),
        (SELECT COUNT(*) FROM attendance_incidents WHERE student_id = p_student_id AND school_id = p_school_id AND incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE') AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL),
        (SELECT COUNT(*) FROM biometric_events WHERE student_id = p_student_id AND school_id = p_school_id AND event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL)
    INTO v_late_count, v_absence_count, v_total_events;

    v_risk_score := LEAST(100.00, (v_late_count * 5.0) + (v_absence_count * 15.0) + GREATEST(0, (v_total_events - 20) * 0.5));
    v_risk_level := CASE
        WHEN v_risk_score >= 80 THEN 'CRITICAL'
        WHEN v_risk_score >= 60 THEN 'HIGH'
        WHEN v_risk_score >= 30 THEN 'MEDIUM'
        ELSE 'LOW'
    END;

    INSERT INTO student_behavior_metrics(school_id, student_id, calculated_at, late_count, absence_count, total_events, risk_score, risk_level, calculation_window_days, metadata_json)
    VALUES(p_school_id, p_student_id, NOW(), v_late_count, v_absence_count, v_total_events, v_risk_score, v_risk_level, p_window_days, jsonb_build_object('threshold', v_threshold, 'window_days', p_window_days))
    ON CONFLICT(student_id, calculation_window_days) DO UPDATE SET
        calculated_at = EXCLUDED.calculated_at,
        late_count = EXCLUDED.late_count,
        absence_count = EXCLUDED.absence_count,
        total_events = EXCLUDED.total_events,
        risk_score = EXCLUDED.risk_score,
        risk_level = EXCLUDED.risk_level,
        metadata_json = EXCLUDED.metadata_json
    RETURNING metric_id INTO v_metric_id;

    IF v_risk_score >= v_threshold THEN
        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
        SELECT uuid_generate_v4(), p_school_id, p_student_id, 'RISK_ALERT_' || v_risk_level, NOW(), jsonb_build_object('risk_score', v_risk_score)
        WHERE NOT EXISTS (
            SELECT 1 FROM attendance_incidents
            WHERE student_id = p_student_id AND school_id = p_school_id
              AND incident_type LIKE 'RISK_ALERT%'
              AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '7 days'
        );
    END IF;

    RETURN jsonb_build_object('risk_score', v_risk_score, 'risk_level', v_risk_level, 'late_count', v_late_count, 'absence_count', v_absence_count, 'total_events', v_total_events);
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION fn_recalculate_school_metrics(p_school_id UUID) RETURNS INTEGER AS $$
DECLARE
    v_count INTEGER;
BEGIN
    INSERT INTO student_behavior_metrics(school_id, student_id, calculated_at, late_count, absence_count, total_events, risk_score, risk_level, calculation_window_days, metadata_json)
    SELECT
        p_school_id,
        s.student_id,
        NOW(),
        COALESCE(be.late_count, 0),
        COALESCE(be.absence_count, 0),
        COALESCE(be.total_events, 0),
        LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)),
        CASE
            WHEN LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)) >= 80 THEN 'CRITICAL'
            WHEN LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)) >= 60 THEN 'HIGH'
            WHEN LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)) >= 30 THEN 'MEDIUM'
            ELSE 'LOW'
        END,
        30,
        jsonb_build_object('recalculated_at', NOW())
    FROM students s
    LEFT JOIN (
        SELECT s2.student_id,
            COALESCE(ai.late_count, 0) AS late_count,
            COALESCE(ai2.absence_count, 0) AS absence_count,
            COALESCE(bev.total_events, 0) AS total_events
        FROM students s2
        LEFT JOIN (SELECT student_id, COUNT(*) AS late_count FROM attendance_incidents WHERE incident_type = 'LATE_ARRIVAL' AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days' GROUP BY student_id) ai ON ai.student_id = s2.student_id
        LEFT JOIN (SELECT student_id, COUNT(*) AS absence_count FROM attendance_incidents WHERE incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE') AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days' GROUP BY student_id) ai2 ON ai2.student_id = s2.student_id
        LEFT JOIN (SELECT student_id, COUNT(*) AS total_events FROM biometric_events WHERE event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days' GROUP BY student_id) bev ON bev.student_id = s2.student_id
        WHERE s2.school_id = p_school_id AND s2.active = TRUE AND s2.deleted_at IS NULL
    ) be ON be.student_id = s.student_id
    WHERE s.school_id = p_school_id AND s.active = TRUE AND s.deleted_at IS NULL
    ON CONFLICT (student_id, calculation_window_days) DO UPDATE SET
        calculated_at = EXCLUDED.calculated_at,
        late_count = EXCLUDED.late_count,
        absence_count = EXCLUDED.absence_count,
        total_events = EXCLUDED.total_events,
        risk_score = EXCLUDED.risk_score,
        risk_level = EXCLUDED.risk_level,
        metadata_json = EXCLUDED.metadata_json;

    GET DIAGNOSTICS v_count = ROW_COUNT;
    RETURN v_count;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- 5. Verificación
SELECT 'migration_complete' AS status,
       (SELECT COUNT(*) FROM permissions WHERE permission_code IN ('operations.fusionar_bloque', 'operations.extender_bloque')) AS new_permissions,
       (SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'daily_schedule_config' AND column_name = 'metadata_json') AS metadata_json_column;
