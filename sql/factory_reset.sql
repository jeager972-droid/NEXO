-- =============================================================================
-- NEXO — FACTORY RESET SCRIPT
-- =============================================================================
-- Este script limpia TODA la información operativa de la base de datos (estudiantes,
-- acudientes, grupos, materias, eventos biométricos, etc.)
-- MANTIENE ÚNICAMENTE:
--   - Escuelas (schools)
--   - Roles y Permisos
--   - Usuarios del personal (Rectores, Coordinadores, Docentes, Admins)
-- =============================================================================

BEGIN;

-- 1. Eliminar toda la data operativa, configuraciones de horarios, riesgos y eventos.
-- El uso de CASCADE limpiará automáticamente cualquier tabla dependiente.
TRUNCATE TABLE
    user_sessions,
    guardians,
    students,
    academic_groups,
    classrooms,
    subjects,
    edge_devices,
    biometric_events,
    notifications,
    attendance_incidents,
    internal_messages,
    twilio_messages,
    user_commands,
    sos_alerts,
    security_incidents,
    school_exit_authorizations,
    class_exit_authorizations,
    pedagogical_trip_authorizations,
    student_record_audit,
    global_audit_logs,
    report_exports,
    student_behavior_metrics,
    school_calendar,
    risk_policies,
    risk_active_snapshot,
    risk_alerts,
    risk_justifications,
    risk_audit_log,
    student_tracking,
    student_tracking_notes,
    school_panic_events,
    system_telemetry,
    contact_leads,
    rate_limits,
    jwt_blocklist,
    verification_codes,
    school_schedule_config,
    school_time_blocks,
    technical_modality_config
CASCADE;

-- 2. Eliminar cualquier usuario que sea exclusivamente ACUDIENTE.
-- Los estudiantes ya fueron borrados en el TRUNCATE CASCADE anterior.
DELETE FROM users 
WHERE role_id IN (
    SELECT role_id FROM roles WHERE role_name = 'GUARDIAN'
);

-- 3. Reiniciar el estado de todas las escuelas para forzar el Onboarding obligatorio
-- al momento de iniciar sesión.
UPDATE schools SET
    onboarding_completed = FALSE,
    groups_onboarding_completed = FALSE,
    groups_onboarding_year = NULL,
    risk_config_completed = FALSE,
    sensor_master_key_hash = NULL;

COMMIT;
