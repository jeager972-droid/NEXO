-- =============================================================================
-- 2026-38: Recálculo de risk score en tiempo real tras cada incidente
--
-- PROBLEMA (A6):
--   El risk_score solo se recalcula a las 2 AM (cron). Un estudiante puede
--   acumular 5 inasistencias durante el día y su risk_score no se actualiza
--   hasta la madrugada. Las alertas RISK_ALERT_ se retrasan.
--
-- SOLUCIÓN:
--   Trigger AFTER INSERT en attendance_incidents que llama a
--   fn_calcalculate_student_risk de forma asíncrona (pg_notify) o síncrona.
--   Usamos síncrono porque el cálculo es rápido (<10ms por estudiante) y
--   garantiza que el risk_score esté actualizado inmediatamente.
--
--   El trigger solo recalcula para incidentes que afectan el risk_score:
--   LATE_ARRIVAL, INASISTENCIA, UNAUTHORIZED_ABSENCE, EVASION_INTERNA.
--   No recalcula para RISK_ALERT_ (evita recursión) ni PERMISO.
-- =============================================================================

-- Función que recalcula el risk score del estudiante tras un incidente
CREATE OR REPLACE FUNCTION fn_recalc_risk_on_incident()
RETURNS TRIGGER AS $$
BEGIN
    -- Solo recalcular para tipos que afectan el risk score
    IF NEW.incident_type IN ('LATE_ARRIVAL', 'INASISTENCIA', 'UNAUTHORIZED_ABSENCE', 'EVASION_INTERNA') THEN
        -- Recalcular en ventana de 30 días (default del engine)
        PERFORM fn_calculate_student_risk(NEW.student_id, NEW.school_id, 30);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SET search_path = public, pg_temp;

-- Trigger AFTER INSERT en attendance_incidents
DROP TRIGGER IF EXISTS trg_recalc_risk_on_incident ON attendance_incidents;
CREATE TRIGGER trg_recalc_risk_on_incident
    AFTER INSERT ON attendance_incidents
    FOR EACH ROW
    EXECUTE FUNCTION fn_recalc_risk_on_incident();

-- Registrar migración
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-38-risk-score-realtime.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'Recálculo de risk score en tiempo real tras cada incidente'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Trigger AFTER INSERT en attendance_incidents que llama fn_calculate_student_risk para LATE_ARRIVAL, INASISTENCIA, UNAUTHORIZED_ABSENCE, EVASION_INTERNA.'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro.';
    END IF;
END $$;
