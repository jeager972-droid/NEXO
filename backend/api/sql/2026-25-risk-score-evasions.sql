-- =============================================================================
-- 2026-25-risk-score-evasions.sql
-- =============================================================================
-- PROPÓSITO: Incluir evasiones internas (EVASION_INTERNA) en el cálculo de
--            risk_score de fn_calculate_student_risk y fn_recalculate_school_metrics.
--
-- HALLAZGO: VF-030 — fn_calculate_student_risk no cuenta evasiones
-- SEVERIDAD: P2 (lógica de negocio incompleta)
--
-- CAMBIO:
--   ANTES: score = (late * 5) + (absence * 15) + max(0, (events - 20) * 0.5)
--   DESPUÉS: score = (late * 5) + (absence * 15) + (evasion * 10) + max(0, (events - 20) * 0.5)
--
--   Peso por evasión: 10 (entre tardanza=5 y ausencia=15)
--   Razón: evasión es más grave que tardanza pero menos que ausencia completa.
--
--   El conteo de evasiones se almacena en metadata_json.evasion_count
--   (no se añade columna a student_behavior_metrics para evitar schema change).
--
-- COMPATIBILIDAD: Estudiantes sin evasiones tienen evasion_count=0, por lo que
--                 su score no cambia. Solo estudiantes con evasiones suben.
--
-- DEPENDENCIAS: attendance_incidents con incident_type='EVASION_INTERNA'
--               (creadas por worker_evasion_detector.php)
-- =============================================================================

-- =============================================================================
-- fn_calculate_student_risk — versión con evasiones
-- =============================================================================
CREATE OR REPLACE FUNCTION fn_calculate_student_risk(
    p_student_id  UUID,
    p_school_id   UUID,
    p_window_days INTEGER DEFAULT 30
) RETURNS JSONB AS $$
DECLARE
    v_late_count    INTEGER;
    v_absence_count INTEGER;
    v_evasion_count INTEGER;
    v_total_events  INTEGER;
    v_risk_score    NUMERIC(5,2);
    v_risk_level    VARCHAR(20);
    v_threshold     CONSTANT NUMERIC(5,2) := 70.00;
    v_metric_id     UUID;
BEGIN
    SELECT
        (SELECT COUNT(*) FROM attendance_incidents
         WHERE student_id = p_student_id AND school_id = p_school_id
           AND incident_type = 'LATE_ARRIVAL'
           AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL),
        (SELECT COUNT(*) FROM attendance_incidents
         WHERE student_id = p_student_id AND school_id = p_school_id
           AND incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE')
           AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL),
        (SELECT COUNT(*) FROM attendance_incidents
         WHERE student_id = p_student_id AND school_id = p_school_id
           AND incident_type = 'EVASION_INTERNA'
           AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL),
        (SELECT COUNT(*) FROM biometric_events
         WHERE student_id = p_student_id AND school_id = p_school_id
           AND event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL)
    INTO v_late_count, v_absence_count, v_evasion_count, v_total_events;

    v_risk_score := LEAST(100.00,
        (v_late_count * 5.0)
        + (v_absence_count * 15.0)
        + (v_evasion_count * 10.0)
        + GREATEST(0, (v_total_events - 20) * 0.5)
    );

    v_risk_level := CASE
        WHEN v_risk_score >= 80 THEN 'CRITICAL'
        WHEN v_risk_score >= 60 THEN 'HIGH'
        WHEN v_risk_score >= 30 THEN 'MEDIUM'
        ELSE 'LOW'
    END;

    INSERT INTO student_behavior_metrics(
        school_id, student_id, calculated_at,
        late_count, absence_count, total_events,
        risk_score, risk_level, calculation_window_days, metadata_json
    )
    VALUES(
        p_school_id, p_student_id, NOW(),
        v_late_count, v_absence_count, v_total_events,
        v_risk_score, v_risk_level, p_window_days,
        jsonb_build_object(
            'threshold', v_threshold,
            'window_days', p_window_days,
            'evasion_count', v_evasion_count
        )
    )
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
        SELECT uuid_generate_v4(), p_school_id, p_student_id, 'RISK_ALERT_' || v_risk_level, NOW(),
               jsonb_build_object('risk_score', v_risk_score, 'evasion_count', v_evasion_count)
        WHERE NOT EXISTS (
            SELECT 1 FROM attendance_incidents
            WHERE student_id = p_student_id AND school_id = p_school_id
              AND incident_type LIKE 'RISK_ALERT%'
              AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '7 days'
        );
    END IF;

    RETURN jsonb_build_object(
        'risk_score', v_risk_score,
        'risk_level', v_risk_level,
        'late_count', v_late_count,
        'absence_count', v_absence_count,
        'evasion_count', v_evasion_count,
        'total_events', v_total_events
    );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

-- =============================================================================
-- fn_recalculate_school_metrics — versión con evasiones
-- =============================================================================
CREATE OR REPLACE FUNCTION fn_recalculate_school_metrics(p_school_id UUID) RETURNS INTEGER AS $$
DECLARE
    v_count INTEGER;
BEGIN
    INSERT INTO student_behavior_metrics(
        school_id, student_id, calculated_at,
        late_count, absence_count, total_events,
        risk_score, risk_level, calculation_window_days, metadata_json
    )
    SELECT
        p_school_id, s.student_id, NOW(),
        COALESCE(be.late_count, 0),
        COALESCE(be.absence_count, 0),
        COALESCE(bev.total_events, 0),
        LEAST(100.00,
            COALESCE(be.late_count, 0) * 5.0
            + COALESCE(be.absence_count, 0) * 15.0
            + COALESCE(bev.evasion_count, 0) * 10.0
            + GREATEST(0, (COALESCE(bev.total_events, 0) - 20) * 0.5)
        ),
        CASE
            WHEN LEAST(100.00,
                COALESCE(be.late_count, 0) * 5.0
                + COALESCE(be.absence_count, 0) * 15.0
                + COALESCE(bev.evasion_count, 0) * 10.0
                + GREATEST(0, (COALESCE(bev.total_events, 0) - 20) * 0.5)
            ) >= 80 THEN 'CRITICAL'
            WHEN LEAST(100.00,
                COALESCE(be.late_count, 0) * 5.0
                + COALESCE(be.absence_count, 0) * 15.0
                + COALESCE(bev.evasion_count, 0) * 10.0
                + GREATEST(0, (COALESCE(bev.total_events, 0) - 20) * 0.5)
            ) >= 60 THEN 'HIGH'
            WHEN LEAST(100.00,
                COALESCE(be.late_count, 0) * 5.0
                + COALESCE(be.absence_count, 0) * 15.0
                + COALESCE(bev.evasion_count, 0) * 10.0
                + GREATEST(0, (COALESCE(bev.total_events, 0) - 20) * 0.5)
            ) >= 30 THEN 'MEDIUM'
            ELSE 'LOW'
        END,
        30,
        jsonb_build_object('recalculated_at', NOW(), 'evasion_count', COALESCE(bev.evasion_count, 0))
    FROM students s
    LEFT JOIN(
        SELECT s2.student_id,
               COALESCE(ai.late_count, 0) AS late_count,
               COALESCE(ai2.absence_count, 0) AS absence_count,
               COALESCE(bev_stat.total_events, 0) AS total_events,
               COALESCE(ev_stat.evasion_count, 0) AS evasion_count
        FROM students s2
        LEFT JOIN (
            SELECT student_id, COUNT(*) AS late_count
            FROM attendance_incidents
            WHERE incident_type = 'LATE_ARRIVAL'
              AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days'
            GROUP BY student_id
        ) ai ON ai.student_id = s2.student_id
        LEFT JOIN (
            SELECT student_id, COUNT(*) AS absence_count
            FROM attendance_incidents
            WHERE incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE')
              AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days'
            GROUP BY student_id
        ) ai2 ON ai2.student_id = s2.student_id
        LEFT JOIN (
            SELECT student_id, COUNT(*) AS total_events
            FROM biometric_events
            WHERE event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days'
            GROUP BY student_id
        ) bev_stat ON bev_stat.student_id = s2.student_id
        LEFT JOIN (
            SELECT student_id, COUNT(*) AS evasion_count
            FROM attendance_incidents
            WHERE incident_type = 'EVASION_INTERNA'
              AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days'
            GROUP BY student_id
        ) ev_stat ON ev_stat.student_id = s2.student_id
    ) be ON be.student_id = s.student_id
    WHERE s.school_id = p_school_id AND s.active = TRUE
    ON CONFLICT(student_id, calculation_window_days) DO UPDATE SET
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
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

-- =============================================================================
-- VERIFICACIÓN POST-MIGRACIÓN:
--
-- 1. Verificar que un estudiante sin evasiones tiene el mismo score que antes:
--    SELECT fn_calculate_student_risk('<student_uuid>', '<school_uuid>');
--    -> evasion_count debe ser 0, risk_score igual al anterior
--
-- 2. Verificar que un estudiante con evasiones tiene score más alto:
--    INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at)
--    VALUES (uuid_generate_v4(), '<school>', '<student>', 'EVASION_INTERNA', NOW());
--    SELECT fn_calculate_student_risk('<student_uuid>', '<school_uuid>');
--    -> evasion_count debe ser >=1, risk_score debe ser 10 puntos mayor
-- =============================================================================
