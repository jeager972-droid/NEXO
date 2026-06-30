-- ============================================================
-- T5: Motor de Patrones (Student Behavior Metrics + Risk Score)
-- ============================================================

-- 1. Tabla de métricas de comportamiento
CREATE TABLE IF NOT EXISTS student_behavior_metrics (
    metric_id UUID DEFAULT uuid_generate_v4() PRIMARY KEY,
    school_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    calculated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    -- Métricas base (últimos 30 días)
    late_count INTEGER NOT NULL DEFAULT 0,
    absence_count INTEGER NOT NULL DEFAULT 0,
    total_events INTEGER NOT NULL DEFAULT 0,
    
    -- Score calculado (0-100)
    risk_score NUMERIC(5,2) NOT NULL DEFAULT 0.00,
    risk_level VARCHAR(20) CHECK (risk_level IN ('LOW', 'MEDIUM', 'HIGH', 'CRITICAL')),
    
    -- Metadatos
    calculation_window_days INTEGER NOT NULL DEFAULT 30,
    metadata_json JSONB,
    
    CONSTRAINT fk_behavior_school FOREIGN KEY (school_id) REFERENCES schools(school_id),
    CONSTRAINT fk_behavior_student FOREIGN KEY (student_id) REFERENCES students(student_id),
    CONSTRAINT uq_behavior_student_window UNIQUE (student_id, calculation_window_days)
);

CREATE INDEX IF NOT EXISTS idx_behavior_risk_score
    ON student_behavior_metrics(school_id, risk_level, calculated_at DESC);

CREATE INDEX IF NOT EXISTS idx_behavior_student
    ON student_behavior_metrics(student_id, calculated_at DESC);

-- 2. Función para calcular métricas y risk score de un estudiante
CREATE OR REPLACE FUNCTION fn_calculate_student_risk(
    p_student_id INTEGER,
    p_school_id INTEGER,
    p_window_days INTEGER DEFAULT 30
)
RETURNS JSONB AS $$
DECLARE
    v_late_count INTEGER;
    v_absence_count INTEGER;
    v_total_events INTEGER;
    v_risk_score NUMERIC(5,2);
    v_risk_level VARCHAR(20);
    v_threshold CONSTANT NUMERIC(5,2) := 70.00; -- Umbral para insertar incidente
    v_metric_id UUID;
BEGIN
    -- Contar eventos del estudiante en la ventana
    SELECT 
        COUNT(*) FILTER (WHERE event_type LIKE 'INGRESO_TARDE%') AS late_count,
        COUNT(*) FILTER (WHERE event_type LIKE 'INASISTENCIA%') AS absence_count,
        COUNT(*) AS total_events
    INTO v_late_count, v_absence_count, v_total_events
    FROM biometric_events
    WHERE student_id = p_student_id
      AND school_id = p_school_id
      AND event_timestamp >= NOW() - (p_window_days || ' days')::INTERVAL;

    -- Calcular Score de Riesgo (fórmula ponderada)
    v_risk_score := LEAST(100.00, 
        (v_late_count * 5.0) + 
        (v_absence_count * 15.0) + 
        GREATEST(0, (v_total_events - 20) * 0.5)
    );

    -- Clasificar nivel
    v_risk_level := CASE
        WHEN v_risk_score >= 80 THEN 'CRITICAL'
        WHEN v_risk_score >= 60 THEN 'HIGH'
        WHEN v_risk_score >= 30 THEN 'MEDIUM'
        ELSE 'LOW'
    END;

    -- Upsert métrica
    INSERT INTO student_behavior_metrics (
        school_id, student_id, calculated_at, late_count, absence_count,
        total_events, risk_score, risk_level, calculation_window_days, metadata_json
    ) VALUES (
        p_school_id, p_student_id, NOW(), v_late_count, v_absence_count,
        v_total_events, v_risk_score, v_risk_level, p_window_days,
        jsonb_build_object('threshold', v_threshold, 'window_days', p_window_days)
    )
    ON CONFLICT (student_id, calculation_window_days) DO UPDATE SET
        calculated_at = EXCLUDED.calculated_at,
        late_count = EXCLUDED.late_count,
        absence_count = EXCLUDED.absence_count,
        total_events = EXCLUDED.total_events,
        risk_score = EXCLUDED.risk_score,
        risk_level = EXCLUDED.risk_level,
        metadata_json = EXCLUDED.metadata_json
    RETURNING metric_id INTO v_metric_id;

    -- Si supera umbral, insertar incidente de asistencia (solo si no existe incidente reciente)
    IF v_risk_score >= v_threshold THEN
        INSERT INTO attendance_incidents (
            incident_id, school_id, student_id, incident_type, detected_at, metadata_json
        )
        SELECT 
            uuid_generate_v4(), p_school_id, p_student_id,
            'RISK_ALERT_' || v_risk_level,
            NOW(),
            jsonb_build_object(
                'risk_score', v_risk_score,
                'metric_id', v_metric_id,
                'late_count', v_late_count,
                'absence_count', v_absence_count,
                'trigger_threshold', v_threshold
            )
        WHERE NOT EXISTS (
            SELECT 1 FROM attendance_incidents
            WHERE student_id = p_student_id
              AND school_id = p_school_id
              AND incident_type LIKE 'RISK_ALERT%'
              AND detected_at >= NOW() - INTERVAL '7 days'
        );
    END IF;

    RETURN jsonb_build_object(
        'metric_id', v_metric_id,
        'risk_score', v_risk_score,
        'risk_level', v_risk_level,
        'late_count', v_late_count,
        'absence_count', v_absence_count,
        'threshold_exceeded', v_risk_score >= v_threshold
    );
END;
$$ LANGUAGE plpgsql;

-- 3. Procedimiento VECTORIZADO para recalcular métricas de toda una escuela
-- FIX: Elimina bucle FOR. Procesa todos los estudiantes en una sola consulta set-based.
CREATE OR REPLACE FUNCTION fn_recalculate_school_metrics(p_school_id INTEGER)
RETURNS INTEGER AS $$
DECLARE
    v_count INTEGER;
BEGIN
    INSERT INTO student_behavior_metrics (
        school_id, student_id, calculated_at,
        late_count, absence_count, total_events,
        risk_score, risk_level, calculation_window_days,
        metadata_json
    )
    SELECT
        p_school_id,
        s.student_id,
        NOW(),
        COALESCE(be.late_count, 0),
        COALESCE(be.absence_count, 0),
        COALESCE(be.total_events, 0),
        LEAST(100.00,
            COALESCE(be.late_count, 0) * 5.0 +
            COALESCE(be.absence_count, 0) * 15.0 +
            GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)
        ),
        CASE
            WHEN LEAST(100.00,
                COALESCE(be.late_count, 0) * 5.0 +
                COALESCE(be.absence_count, 0) * 15.0 +
                GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)
            ) >= 80 THEN 'CRITICAL'
            WHEN LEAST(100.00,
                COALESCE(be.late_count, 0) * 5.0 +
                COALESCE(be.absence_count, 0) * 15.0 +
                GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)
            ) >= 60 THEN 'HIGH'
            WHEN LEAST(100.00,
                COALESCE(be.late_count, 0) * 5.0 +
                COALESCE(be.absence_count, 0) * 15.0 +
                GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)
            ) >= 30 THEN 'MEDIUM'
            ELSE 'LOW'
        END,
        30,
        jsonb_build_object('recalculated_at', NOW())
    FROM students s
    LEFT JOIN (
        SELECT
            student_id,
            COUNT(*) FILTER (WHERE event_type LIKE 'INGRESO_TARDE%') AS late_count,
            COUNT(*) FILTER (WHERE event_type LIKE 'INASISTENCIA%') AS absence_count,
            COUNT(*) AS total_events
        FROM biometric_events
        WHERE event_timestamp >= NOW() - INTERVAL '30 days'
        GROUP BY student_id
    ) be ON be.student_id = s.student_id
    WHERE s.school_id = p_school_id AND s.active = TRUE
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
$$ LANGUAGE plpgsql;
