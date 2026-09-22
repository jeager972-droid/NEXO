-- =============================================================================
-- NEXO — FACTORY RESET (consolidado, schema actual)
-- =============================================================================
-- Limpia TODA la información operativa (estudiantes, acudientes, grupos, aulas,
-- horarios, eventos biométricos, incidentes, riesgo, mensajería, auditoría…).
--
-- PRESERVA:
--   - schools, departments, municipalities        (institución)
--   - roles, permissions, role_permissions        (seguridad)
--   - users NO-GUARDIAN + staff_records           (credenciales y personal:
--                                                 rectores, coordinadores, docentes,
--                                                 secretaría, seguridad, auxiliares)
--   - risk_event_types, twilio_message_types      (catálogos del sistema)
--   - schema_migrations                           (trazabilidad)
--   - school_notification_routes, school_action_policies,
--     school_chat_policies, ota_updates           (config institucional)
--   - chat_messages                               (historial de usuarios preservados)
--
-- Uso:  psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f sql/factory_reset.sql
-- =============================================================================

BEGIN;

-- 1. Data operativa y de configuración generada por onboarding.
--    CASCADE alcanza cualquier tabla dependiente no listada explícitamente.
TRUNCATE TABLE
    -- sesiones y credenciales efímeras (las credenciales de users quedan intactas)
    user_sessions,
    verification_codes,
    jwt_blocklist,
    rate_limits,
    -- personas y estructura académica
    guardians,
    students,
    guardian_student_relationships,
    student_fingerprints,
    academic_groups,
    student_group_assignments,
    teacher_group_access,
    teacher_alert_rules,
    classrooms,
    subjects,
    schedules,
    daily_schedule_config,
    -- onboarding de horarios (el seed lo recrea)
    school_schedule_config,
    school_time_blocks,
    technical_modality_config,
    -- edge / dispositivos
    edge_devices,
    device_commands,
    sensor_revocation_requests,
    ota_deployments,
    -- eventos e incidentes (padres particionados: cubre todas las particiones)
    biometric_events,
    attendance_incidents,
    sos_alerts,
    security_incidents,
    school_panic_events,
    -- autorizaciones y permisos
    school_exit_authorizations,
    class_exit_authorizations,
    pedagogical_trip_authorizations,
    -- mensajería y notificaciones
    notifications,
    internal_messages,
    twilio_messages,
    user_commands,
    -- auditoría y reportes
    student_record_audit,
    global_audit_logs,
    report_exports,
    -- motor de riesgo + seguimiento
    student_behavior_metrics,
    risk_policies,
    risk_rules,
    risk_event_level_mapping,
    risk_combination_rules,
    risk_active_snapshot,
    risk_alerts,
    risk_justifications,
    risk_audit_log,
    student_tracking,
    student_tracking_notes,
    -- misceláneo operativo
    school_calendar,
    system_telemetry,
    contact_leads
CASCADE;

-- 2. Usuarios exclusivamente ACUDIENTE (los estudiantes ya cayeron arriba).
--    Los demás roles (RECTOR, COORDINATOR, TEACHER, SECRETARY, COUNSELOR,
--    SECURITY, AUXILIARY, SUPER_ADMIN…) se conservan con sus credenciales.
DELETE FROM users
WHERE role_id IN (SELECT role_id FROM roles WHERE role_name = 'GUARDIAN');

-- 3. Reset de banderas de onboarding institucional — el seed (o el flujo UI)
--    las vuelve a marcar al completar la configuración.
UPDATE schools SET
    onboarding_completed        = FALSE,
    groups_onboarding_completed = FALSE,
    groups_onboarding_year      = NULL,
    risk_config_completed       = FALSE;

-- Nota: schools.sensor_master_key_hash se conserva (pertenece a la llave
-- maestra del operador, no a datos de estudiantes).

COMMIT;
