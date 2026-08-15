-- =============================================================================
-- NEXO Full Migration | Idempotent | PostgreSQL 15+
-- =============================================================================
-- RESPONSABILIDAD:
--   Crea el esquema de base de datos completo y autoritativo para NEXO:
--   tablas base, índices, constraints, particiones por rango (event_timestamp,
--   detected_at, sent_at, executed_at, emitted_at), funciones helper,
--   triggers, Row-Level Security (RLS) y seed mínimo. También crea la tabla
--   schema_migrations para tracking de cambios futuros.
--
-- EJECUTAR:
--   psql $DATABASE_URL -f nexo_full_migration.sql
--
-- REQUISITOS:
--   - PostgreSQL 15+ con extensiones uuid-ossp y pgcrypto.
--   - Variables de entorno APP_NEXO_HMAC_SECRET / NEXO_HMAC_SECRET usadas por
--     triggers y funciones de hash chain (configuradas luego en db.php).
--
-- NOTAS:
--   - Idempotente: usa CREATE IF NOT EXISTS / ON CONFLICT.
--   - Particiones: biometric_events, attendance_incidents, internal_messages,
--     twilio_messages, user_commands, sos_alerts.
--   - RLS: todas las tablas multi-tenant tienen ENABLE ROW LEVEL SECURITY y
--     políticas basadas en app.current_school_id / app.current_role.
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- =============================================================================
-- SCHEMA MIGRATION TRACKING SYSTEM
-- Tabla de control de versiones de migraciones ejecutadas.
-- Cada migración debe registrarse aquí tras su ejecución exitosa.
-- =============================================================================
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    filename          VARCHAR(255) NOT NULL UNIQUE,
    version_label     VARCHAR(50),           -- ej: '2026-07', 'v1.2.3'
    description       TEXT,
    checksum          VARCHAR(64),           -- SHA-256 del archivo ejecutado
    executed_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    executed_by       VARCHAR(100),          -- usuario o proceso que ejecutó
    execution_time_ms INTEGER,               -- duración de la migración
    success           BOOLEAN NOT NULL DEFAULT TRUE,
    rollback_script   TEXT,                  -- SQL para revertir (opcional)
    notes             TEXT
);

CREATE INDEX IF NOT EXISTS idx_schema_migrations_executed_at
    ON schema_migrations(executed_at DESC);

CREATE INDEX IF NOT EXISTS idx_schema_migrations_version
    ON schema_migrations(version_label);

-- Función helper: verificar si una migración ya fue ejecutada
CREATE OR REPLACE FUNCTION migration_was_executed(p_filename VARCHAR(255))
RETURNS BOOLEAN AS $$
BEGIN
    RETURN EXISTS(
        SELECT 1 FROM schema_migrations
        WHERE filename = p_filename AND success = TRUE
    );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Función helper: registrar una migración ejecutada
CREATE OR REPLACE FUNCTION register_migration(
    p_filename          VARCHAR(255),
    p_version_label     VARCHAR(50) DEFAULT NULL,
    p_description       TEXT DEFAULT NULL,
    p_checksum          VARCHAR(64) DEFAULT NULL,
    p_executed_by       VARCHAR(100) DEFAULT NULL,
    p_execution_time_ms INTEGER DEFAULT NULL,
    p_notes             TEXT DEFAULT NULL
)
RETURNS UUID AS $$
DECLARE
    v_id UUID;
BEGIN
    INSERT INTO schema_migrations (
        filename, version_label, description, checksum,
        executed_by, execution_time_ms, notes
    ) VALUES (
        p_filename, p_version_label, p_description, p_checksum,
        p_executed_by, p_execution_time_ms, p_notes
    )
    ON CONFLICT (filename) DO UPDATE SET
        success = TRUE,
        executed_at = NOW(),
        execution_time_ms = COALESCE(EXCLUDED.execution_time_ms, schema_migrations.execution_time_ms),
        notes = COALESCE(EXCLUDED.notes, schema_migrations.notes)
    RETURNING migration_id INTO v_id;
    RETURN v_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- DOCUMENTATION
COMMENT ON TABLE users IS 'Core user accounts with authentication credentials. Multi-tenant by school_id.';
COMMENT ON TABLE students IS 'Student enrollment records. Soft-deletable. Multi-tenant by school_id.';
COMMENT ON TABLE guardians IS 'Guardian/parent profiles linked to users. WhatsApp phone is primary contact method.';
COMMENT ON TABLE biometric_events IS 'Biometric scan events from edge devices. Partitioned by event_timestamp for performance.';
COMMENT ON TABLE school_panic_events IS 'Emergency panic button events. Triggers device deactivation cascade.';

-- BASE TABLES
CREATE TABLE IF NOT EXISTS permissions (permission_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), permission_code VARCHAR(120) UNIQUE NOT NULL, description TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS departments (department_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), department_name VARCHAR(120) UNIQUE NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS municipalities (municipality_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), department_id UUID NOT NULL REFERENCES departments(department_id), municipality_name VARCHAR(120) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_municipality_department ON municipalities(department_id);
CREATE TABLE IF NOT EXISTS schools (school_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), municipality_id UUID NOT NULL REFERENCES municipalities(municipality_id), dane_code VARCHAR(50) UNIQUE, school_name VARCHAR(255) NOT NULL, address TEXT, phone VARCHAR(30), email VARCHAR(255), active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_school_municipality ON schools(municipality_id);
CREATE TABLE IF NOT EXISTS roles (role_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), role_name VARCHAR(100) UNIQUE NOT NULL, description TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS role_permissions (role_permission_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), role_id UUID NOT NULL REFERENCES roles(role_id), permission_id UUID NOT NULL REFERENCES permissions(permission_id), created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_role_permission_role ON role_permissions(role_id);
CREATE TABLE IF NOT EXISTS users (user_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), role_id UUID NOT NULL REFERENCES roles(role_id), document_number VARCHAR(30) NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, email VARCHAR(255) UNIQUE, phone VARCHAR(30), password_hash TEXT NOT NULL, password_salt TEXT NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE, last_login_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ, deleted_at TIMESTAMPTZ, CONSTRAINT uq_users_school_document UNIQUE (school_id, document_number));
CREATE INDEX IF NOT EXISTS idx_users_school ON users(school_id); CREATE INDEX IF NOT EXISTS idx_users_role ON users(role_id);
CREATE TABLE IF NOT EXISTS user_sessions (session_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), user_id UUID NOT NULL REFERENCES users(user_id), refresh_token_hash TEXT NOT NULL, ip_address INET, user_agent TEXT, expires_at TIMESTAMPTZ NOT NULL, revoked BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), revoked_at TIMESTAMPTZ);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON user_sessions(user_id);
CREATE TABLE IF NOT EXISTS staff_records (staff_record_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), user_id UUID NOT NULL REFERENCES users(user_id), hired_at DATE, position_name VARCHAR(120), employee_code VARCHAR(120), active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_staff_school ON staff_records(school_id);
CREATE INDEX IF NOT EXISTS idx_staff_user ON staff_records(user_id);
CREATE TABLE IF NOT EXISTS guardians (guardian_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), user_id UUID UNIQUE NOT NULL REFERENCES users(user_id), whatsapp_phone VARCHAR(30) NOT NULL, emergency_contact BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS students (student_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), document_number VARCHAR(30) NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, birth_date DATE, biometric_hash TEXT, active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ, deleted_at TIMESTAMPTZ, CONSTRAINT uq_students_school_document UNIQUE (school_id, document_number));
CREATE INDEX IF NOT EXISTS idx_students_school ON students(school_id);
ALTER TABLE students ADD COLUMN IF NOT EXISTS work_shift VARCHAR(50) DEFAULT 'mañana';
COMMENT ON COLUMN students.work_shift IS 'mañana, tarde, completa. Usado para detección automática de ausentes por jornada';
CREATE TABLE IF NOT EXISTS guardian_student_relationships (relationship_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), guardian_id UUID NOT NULL REFERENCES guardians(guardian_id), student_id UUID NOT NULL REFERENCES students(student_id), relationship_type VARCHAR(80), primary_guardian BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS academic_groups (group_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), group_name VARCHAR(120) NOT NULL, grade_level VARCHAR(50), academic_year INTEGER NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS student_group_assignments (assignment_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), student_id UUID NOT NULL REFERENCES students(student_id), group_id UUID NOT NULL REFERENCES academic_groups(group_id), active BOOLEAN NOT NULL DEFAULT TRUE, start_date DATE, end_date DATE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS classrooms (classroom_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), classroom_name VARCHAR(120) NOT NULL, building VARCHAR(120), created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS subjects (subject_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), subject_name VARCHAR(120) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS schedules (schedule_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), group_id UUID NOT NULL REFERENCES academic_groups(group_id), classroom_id UUID NOT NULL REFERENCES classrooms(classroom_id), teacher_user_id UUID NOT NULL REFERENCES users(user_id), subject_id UUID NOT NULL REFERENCES subjects(subject_id), day_of_week INTEGER NOT NULL, block_number INTEGER NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS daily_schedule_config (
    config_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id UUID NOT NULL REFERENCES schools(school_id),
    group_id UUID NOT NULL REFERENCES academic_groups(group_id),
    config_date DATE NOT NULL,
    has_classes BOOLEAN NOT NULL DEFAULT TRUE,
    expected_entry_time TIME,
    expected_exit_time TIME,
    created_by_user_id UUID REFERENCES users(user_id),
    metadata_json JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(school_id, group_id, config_date)
);
-- Asegurar columna metadata_json en instalaciones existentes
ALTER TABLE daily_schedule_config ADD COLUMN IF NOT EXISTS metadata_json JSONB;
ALTER TABLE daily_schedule_config ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS dsc_select ON daily_schedule_config;
CREATE POLICY dsc_select ON daily_schedule_config FOR SELECT USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS dsc_insert ON daily_schedule_config;
CREATE POLICY dsc_insert ON daily_schedule_config FOR INSERT WITH CHECK(school_id = get_current_school_id());
DROP POLICY IF EXISTS dsc_update ON daily_schedule_config;
CREATE POLICY dsc_update ON daily_schedule_config FOR UPDATE USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS dsc_delete ON daily_schedule_config;
CREATE POLICY dsc_delete ON daily_schedule_config FOR DELETE USING(school_id = get_current_school_id());
CREATE TABLE IF NOT EXISTS edge_devices (device_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), classroom_id UUID REFERENCES classrooms(classroom_id), device_name VARCHAR(120) NOT NULL, public_key TEXT, active BOOLEAN NOT NULL DEFAULT TRUE, last_sync_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS biometric_events (event_id UUID NOT NULL, school_id UUID NOT NULL, student_id UUID, device_id UUID NOT NULL, classroom_id UUID, schedule_id UUID, event_type VARCHAR(120) NOT NULL, event_result VARCHAR(120) NOT NULL, confidence_score NUMERIC(5,2), sync_hash TEXT, event_signature TEXT, event_fingerprint VARCHAR(64), event_timestamp TIMESTAMPTZ NOT NULL, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), PRIMARY KEY(event_id, event_timestamp)) PARTITION BY RANGE(event_timestamp);
CREATE TABLE IF NOT EXISTS notifications (notification_id UUID DEFAULT uuid_generate_v4() PRIMARY KEY, school_id UUID NOT NULL, user_id UUID NOT NULL, title VARCHAR(200) NOT NULL, message TEXT NOT NULL, type VARCHAR(50) NOT NULL DEFAULT 'INFO', metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, created_at DESC);
CREATE TABLE IF NOT EXISTS attendance_incidents (incident_id UUID NOT NULL, school_id UUID NOT NULL, student_id UUID NOT NULL, related_event_id UUID, incident_type VARCHAR(120) NOT NULL, detected_at TIMESTAMPTZ NOT NULL, resolved BOOLEAN NOT NULL DEFAULT FALSE, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), PRIMARY KEY(incident_id, detected_at)) PARTITION BY RANGE(detected_at);
CREATE TABLE IF NOT EXISTS internal_messages (message_id UUID NOT NULL, school_id UUID NOT NULL, sender_user_id UUID NOT NULL, receiver_user_id UUID NOT NULL, subject VARCHAR(255), message_content TEXT NOT NULL, sent_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), read_at TIMESTAMPTZ, metadata_json JSONB, PRIMARY KEY(message_id, sent_at)) PARTITION BY RANGE(sent_at);
CREATE INDEX IF NOT EXISTS idx_internal_messages_sent_at ON internal_messages(sent_at DESC);
CREATE TABLE IF NOT EXISTS twilio_message_types (type_code VARCHAR(100) PRIMARY KEY, description TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS twilio_messages (twilio_message_id UUID NOT NULL DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, student_id UUID, guardian_id UUID, sender_user_id UUID, type_code VARCHAR(100) NOT NULL, direction VARCHAR(20) NOT NULL, phone_number VARCHAR(30) NOT NULL, message_content TEXT NOT NULL, provider_message_sid VARCHAR(255), delivery_status VARCHAR(100), sent_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), received_at TIMESTAMPTZ, metadata_json JSONB, PRIMARY KEY(twilio_message_id, sent_at)) PARTITION BY RANGE(sent_at);
CREATE TABLE IF NOT EXISTS user_commands (command_id UUID NOT NULL DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, executed_by_user_id UUID NOT NULL, command_type VARCHAR(120) NOT NULL, target_entity_type VARCHAR(120), target_entity_id UUID, command_payload JSONB, executed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), metadata_json JSONB, PRIMARY KEY(command_id, executed_at)) PARTITION BY RANGE(executed_at);
CREATE TABLE IF NOT EXISTS sos_alerts (alert_id UUID NOT NULL, school_id UUID NOT NULL, emitted_by_user_id UUID NOT NULL, classroom_id UUID, alert_type VARCHAR(120) NOT NULL, alert_description TEXT, resolved BOOLEAN NOT NULL DEFAULT FALSE, resolved_by_user_id UUID, emitted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), resolved_at TIMESTAMPTZ, metadata_json JSONB, PRIMARY KEY(alert_id, emitted_at)) PARTITION BY RANGE(emitted_at);
CREATE TABLE IF NOT EXISTS security_incidents (incident_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, related_student_id UUID, related_user_id UUID, incident_type VARCHAR(120) NOT NULL, severity_level VARCHAR(50), description TEXT, detected_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), resolved BOOLEAN NOT NULL DEFAULT FALSE, resolved_at TIMESTAMPTZ, metadata_json JSONB);
CREATE TABLE IF NOT EXISTS school_exit_authorizations (authorization_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, student_id UUID NOT NULL, authorized_by_user_id UUID NOT NULL, authorization_reason TEXT, exit_time TIMESTAMPTZ NOT NULL, expected_return_time TIMESTAMPTZ, actual_return_time TIMESTAMPTZ, status VARCHAR(100) NOT NULL, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS class_exit_authorizations (authorization_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, student_id UUID NOT NULL, authorized_by_user_id UUID NOT NULL, schedule_id UUID, authorization_reason TEXT, exit_time TIMESTAMPTZ NOT NULL, return_time TIMESTAMPTZ, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS pedagogical_trip_authorizations (authorization_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, student_id UUID NOT NULL, authorized_by_user_id UUID NOT NULL, destination TEXT NOT NULL, departure_time TIMESTAMPTZ NOT NULL, return_time TIMESTAMPTZ, purpose TEXT, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS student_record_audit (audit_id UUID NOT NULL, school_id UUID NOT NULL, student_id UUID, performed_by_user_id UUID NOT NULL, action_type VARCHAR(120) NOT NULL, previous_data JSONB, new_data JSONB, performed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), metadata_json JSONB, PRIMARY KEY(audit_id, performed_at)) PARTITION BY RANGE(performed_at);
CREATE TABLE IF NOT EXISTS global_audit_logs (log_id UUID NOT NULL, school_id UUID, performed_by_user_id UUID, action_type VARCHAR(120) NOT NULL, entity_type VARCHAR(120), entity_id UUID, action_details JSONB, ip_address INET, user_agent TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), PRIMARY KEY(log_id, created_at)) PARTITION BY RANGE(created_at);
CREATE TABLE IF NOT EXISTS report_exports (report_export_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, generated_by_user_id UUID NOT NULL, report_type VARCHAR(120) NOT NULL, file_format VARCHAR(50) NOT NULL, storage_path TEXT, generated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), metadata_json JSONB);
CREATE TABLE IF NOT EXISTS student_behavior_metrics (metric_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), student_id UUID NOT NULL REFERENCES students(student_id), calculated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), late_count INTEGER NOT NULL DEFAULT 0, absence_count INTEGER NOT NULL DEFAULT 0, total_events INTEGER NOT NULL DEFAULT 0, risk_score NUMERIC(5,2) NOT NULL DEFAULT 0.00, risk_level VARCHAR(20) CHECK(risk_level IN('LOW','MEDIUM','HIGH','CRITICAL')), calculation_window_days INTEGER NOT NULL DEFAULT 30, metadata_json JSONB, CONSTRAINT uq_behavior_student_window UNIQUE(student_id,calculation_window_days));
CREATE TABLE IF NOT EXISTS student_tracking (tracking_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE, student_id UUID NOT NULL REFERENCES students(student_id) ON DELETE CASCADE, status VARCHAR(50) NOT NULL DEFAULT 'en proceso', created_at TIMESTAMP WITH TIME ZONE DEFAULT (NOW() AT TIME ZONE 'America/Bogota'), updated_at TIMESTAMP WITH TIME ZONE DEFAULT (NOW() AT TIME ZONE 'America/Bogota'));
CREATE TABLE IF NOT EXISTS student_tracking_notes (note_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), tracking_id UUID NOT NULL REFERENCES student_tracking(tracking_id) ON DELETE CASCADE, user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE, note_text TEXT NOT NULL, created_at TIMESTAMP WITH TIME ZONE DEFAULT (NOW() AT TIME ZONE 'America/Bogota'));
CREATE TABLE IF NOT EXISTS school_panic_events (panic_event_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), triggered_by_user_id UUID NOT NULL REFERENCES users(user_id), triggered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), devices_deactivated INTEGER NOT NULL DEFAULT 0, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_school_panic_events_school_triggered ON school_panic_events(school_id, triggered_at DESC);
CREATE TABLE IF NOT EXISTS system_telemetry (id BIGSERIAL PRIMARY KEY, session_id UUID NOT NULL, app_version TEXT NOT NULL DEFAULT 'unknown', platform TEXT NOT NULL DEFAULT 'web' CHECK (platform IN ('web', 'desktop', 'android', 'ios')), event_type TEXT NOT NULL CHECK (event_type IN ('JS_ERROR', 'API_LATENCY', 'BIOMETRIC_LATENCY', 'APP_PING', 'RENDER_SLOW')), severity TEXT NOT NULL DEFAULT 'info' CHECK (severity IN ('debug', 'info', 'warn', 'error')), payload JSONB NOT NULL DEFAULT '{}', user_agent TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_telemetry_created_at ON system_telemetry (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_telemetry_event_type ON system_telemetry (event_type);
CREATE INDEX IF NOT EXISTS idx_telemetry_severity ON system_telemetry (severity) WHERE severity IN ('warn', 'error');
CREATE INDEX IF NOT EXISTS idx_telemetry_session ON system_telemetry (session_id);
CREATE INDEX IF NOT EXISTS idx_telemetry_payload_gin ON system_telemetry USING GIN (payload);
ALTER TABLE system_telemetry ENABLE ROW LEVEL SECURITY;
-- FIX 4: system_telemetry no tiene school_id. Policy global: solo SYSTEM_WORKER y SUPER_ADMIN.
DROP POLICY IF EXISTS telemetry_select_super_rector ON system_telemetry;
DROP POLICY IF EXISTS telemetry_select_admin ON system_telemetry;
CREATE POLICY telemetry_select_admin ON system_telemetry FOR SELECT USING (get_current_role() IN ('SYSTEM_WORKER', 'SUPER_ADMIN'));
DROP POLICY IF EXISTS telemetry_insert_authenticated ON system_telemetry;
CREATE POLICY telemetry_insert_authenticated ON system_telemetry FOR INSERT WITH CHECK (current_setting('app.current_role', true) IS NOT NULL AND current_setting('app.current_role', true) != '');
CREATE INDEX IF NOT EXISTS idx_tracking_school_status ON student_tracking(school_id, status);
CREATE INDEX IF NOT EXISTS idx_tracking_notes_tid ON student_tracking_notes(tracking_id);

-- contact_leads (landing page form submissions)
CREATE TABLE IF NOT EXISTS contact_leads (
    lead_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nombre       VARCHAR(200)  NOT NULL,
    cargo        VARCHAR(100)  NOT NULL,
    institucion  VARCHAR(300)  NOT NULL,
    municipio    VARCHAR(200)  NOT NULL,
    email        VARCHAR(254)  NOT NULL,
    whatsapp     VARCHAR(30)   NOT NULL,
    mensaje      TEXT,
    ip_address   VARCHAR(45),
    created_at   TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_contact_leads_email ON contact_leads(email);
CREATE INDEX IF NOT EXISTS idx_contact_leads_created_at ON contact_leads(created_at);

-- MIGRATION TABLES
CREATE TABLE IF NOT EXISTS rate_limits (rl_key TEXT PRIMARY KEY, window_start TIMESTAMPTZ NOT NULL DEFAULT NOW(), hits INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_rate_limits_window_start ON rate_limits(window_start);
CREATE TABLE IF NOT EXISTS jwt_blocklist (jti TEXT PRIMARY KEY, revoked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), expires_at TIMESTAMPTZ NOT NULL);
CREATE INDEX IF NOT EXISTS idx_jwt_blocklist_expires_at ON jwt_blocklist(expires_at);

CREATE TABLE IF NOT EXISTS verification_codes (
    code_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    purpose VARCHAR(50) NOT NULL,
    target_value TEXT NOT NULL,
    code VARCHAR(10) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    verified_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_verification_codes_user_purpose ON verification_codes(user_id, purpose, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_verification_codes_active ON verification_codes(code, expires_at) WHERE used = FALSE;

-- ADDITIONAL COLUMNS
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo_url TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS work_shift VARCHAR(50);
ALTER TABLE users ADD COLUMN IF NOT EXISTS backup_email VARCHAR(255);
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_verified BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE guardians ADD COLUMN IF NOT EXISTS whatsapp_phone_normalized TEXT;
UPDATE guardians SET whatsapp_phone_normalized = regexp_replace(COALESCE(whatsapp_phone,''),'[^0-9+]','','g') WHERE whatsapp_phone_normalized IS NULL OR whatsapp_phone_normalized <> regexp_replace(COALESCE(whatsapp_phone,''),'[^0-9+]','','g');
ALTER TABLE edge_devices ADD COLUMN IF NOT EXISTS token_hash VARCHAR(255), ADD COLUMN IF NOT EXISTS last_ping TIMESTAMPTZ, ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'unknown', ADD COLUMN IF NOT EXISTS last_seen_timestamp TIMESTAMPTZ, ADD COLUMN IF NOT EXISTS location TEXT;
ALTER TABLE global_audit_logs ADD COLUMN IF NOT EXISTS chain_hash TEXT, ADD COLUMN IF NOT EXISTS prev_audit_id UUID, ADD COLUMN IF NOT EXISTS description TEXT;

-- INDEXES
CREATE INDEX IF NOT EXISTS idx_guardians_whatsapp_normalized ON guardians(whatsapp_phone_normalized);
CREATE INDEX IF NOT EXISTS idx_edge_devices_school_active ON edge_devices(school_id, active);
CREATE INDEX IF NOT EXISTS idx_users_email_lower ON users(LOWER(email)) WHERE email IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_users_active_school_role ON users(active, school_id, role_id);
CREATE INDEX IF NOT EXISTS idx_sessions_refresh_hash ON user_sessions(refresh_token_hash);
CREATE INDEX IF NOT EXISTS idx_sessions_expires_revoked ON user_sessions(expires_at, revoked);
CREATE INDEX IF NOT EXISTS idx_guardian_rel_student_primary ON guardian_student_relationships(student_id, primary_guardian);
CREATE INDEX IF NOT EXISTS idx_guardian_rel_guardian ON guardian_student_relationships(guardian_id);
CREATE INDEX IF NOT EXISTS idx_groups_school_year_level ON academic_groups(school_id, academic_year, grade_level, group_name);
CREATE INDEX IF NOT EXISTS idx_schedule_group_day_block ON schedules(group_id, day_of_week, block_number);
CREATE INDEX IF NOT EXISTS idx_schedule_teacher_day_block ON schedules(teacher_user_id, day_of_week, block_number);
-- FIX 3: Índices faltantes para optimización de queries
CREATE INDEX IF NOT EXISTS idx_schedule_classroom ON schedules(classroom_id);
CREATE INDEX IF NOT EXISTS idx_schedule_subject ON schedules(subject_id);
CREATE INDEX IF NOT EXISTS idx_edge_devices_classroom ON edge_devices(classroom_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_user ON global_audit_logs(performed_by_user_id);
CREATE INDEX IF NOT EXISTS idx_biometric_events_school_type_ts ON biometric_events(school_id, event_type, event_timestamp DESC);
CREATE INDEX IF NOT EXISTS idx_attendance_incidents_school_type_detected ON attendance_incidents(school_id, incident_type, detected_at DESC);
CREATE INDEX IF NOT EXISTS idx_sos_alerts_school_resolved_emitted ON sos_alerts(school_id, resolved, emitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_students_school_last_first ON students(school_id, last_name, first_name);
CREATE INDEX IF NOT EXISTS idx_student_group_assignments_student_active ON student_group_assignments(student_id, active);
CREATE INDEX IF NOT EXISTS idx_student_group_assignments_group_active ON student_group_assignments(group_id, active);
CREATE INDEX IF NOT EXISTS idx_report_exports_school_generated ON report_exports(school_id, generated_at DESC);
CREATE INDEX IF NOT EXISTS idx_security_incidents_school_detected ON security_incidents(school_id, detected_at DESC, resolved);
CREATE INDEX IF NOT EXISTS idx_biometric_events_school_student_time ON biometric_events(school_id, student_id, event_timestamp DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_biometric_events_fingerprint ON biometric_events(event_fingerprint, event_timestamp) WHERE event_fingerprint IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_students_document_number ON students(document_number);
CREATE INDEX IF NOT EXISTS idx_behavior_risk_score ON student_behavior_metrics(school_id, risk_level, calculated_at DESC);
CREATE INDEX IF NOT EXISTS idx_behavior_student ON student_behavior_metrics(student_id, calculated_at DESC);
CREATE INDEX IF NOT EXISTS idx_twilio_messages_school_sent ON twilio_messages(school_id, sent_at DESC);
CREATE INDEX IF NOT EXISTS idx_user_commands_school_exec ON user_commands(school_id, executed_at DESC);
CREATE INDEX IF NOT EXISTS idx_global_audit_school_created ON global_audit_logs(school_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_student_audit_school_performed ON student_record_audit(school_id, performed_at DESC);

-- UNIQUE CONSTRAINTS
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_role_permissions_role_permission') THEN ALTER TABLE role_permissions ADD CONSTRAINT uq_role_permissions_role_permission UNIQUE(role_id,permission_id); END IF; END $$;
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_guardian_student_relationship') THEN ALTER TABLE guardian_student_relationships ADD CONSTRAINT uq_guardian_student_relationship UNIQUE(guardian_id,student_id); END IF; END $$;
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_academic_group_school_year_name') THEN ALTER TABLE academic_groups ADD CONSTRAINT uq_academic_group_school_year_name UNIQUE(school_id,academic_year,group_name); END IF; END $$;

-- FIX (BUG-1): student_group_assignments missing UNIQUE constraint
-- The INSERT in routes/students.php uses ON CONFLICT (student_id, group_id)
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_sga_student_group') THEN ALTER TABLE student_group_assignments ADD CONSTRAINT uq_sga_student_group UNIQUE(student_id, group_id); END IF; END $$;

-- FIX: Remove global UNIQUE on users.document_number and add composite UNIQUE with school_id (multi-tenant support)
DO $$ BEGIN 
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname='users_document_number_key') THEN 
        ALTER TABLE users DROP CONSTRAINT users_document_number_key; 
    END IF; 
END $$;
DO $$ BEGIN 
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_users_school_document') THEN 
        ALTER TABLE users ADD CONSTRAINT uq_users_school_document UNIQUE (school_id, document_number); 
    END IF; 
END $$;

-- PARTITIONS
CREATE TABLE IF NOT EXISTS biometric_events_2026_05 PARTITION OF biometric_events FOR VALUES FROM('2026-05-01') TO('2026-06-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_06 PARTITION OF biometric_events FOR VALUES FROM('2026-06-01') TO('2026-07-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_07 PARTITION OF biometric_events FOR VALUES FROM('2026-07-01') TO('2026-08-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_08 PARTITION OF biometric_events FOR VALUES FROM('2026-08-01') TO('2026-09-01');
-- FIX 1: Particiones mensuales para próximos 12 meses (2026-09 a 2027-08)
CREATE TABLE IF NOT EXISTS biometric_events_2026_09 PARTITION OF biometric_events FOR VALUES FROM('2026-09-01') TO('2026-10-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_10 PARTITION OF biometric_events FOR VALUES FROM('2026-10-01') TO('2026-11-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_11 PARTITION OF biometric_events FOR VALUES FROM('2026-11-01') TO('2026-12-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_12 PARTITION OF biometric_events FOR VALUES FROM('2026-12-01') TO('2027-01-01');
CREATE TABLE IF NOT EXISTS biometric_events_2027_01 PARTITION OF biometric_events FOR VALUES FROM('2027-01-01') TO('2027-02-01');
CREATE TABLE IF NOT EXISTS biometric_events_2027_02 PARTITION OF biometric_events FOR VALUES FROM('2027-02-01') TO('2027-03-01');
CREATE TABLE IF NOT EXISTS biometric_events_2027_03 PARTITION OF biometric_events FOR VALUES FROM('2027-03-01') TO('2027-04-01');
CREATE TABLE IF NOT EXISTS biometric_events_2027_04 PARTITION OF biometric_events FOR VALUES FROM('2027-04-01') TO('2027-05-01');
CREATE TABLE IF NOT EXISTS biometric_events_2027_05 PARTITION OF biometric_events FOR VALUES FROM('2027-05-01') TO('2027-06-01');
CREATE TABLE IF NOT EXISTS biometric_events_2027_06 PARTITION OF biometric_events FOR VALUES FROM('2027-06-01') TO('2027-07-01');
CREATE TABLE IF NOT EXISTS biometric_events_2027_07 PARTITION OF biometric_events FOR VALUES FROM('2027-07-01') TO('2027-08-01');
CREATE TABLE IF NOT EXISTS biometric_events_2027_08 PARTITION OF biometric_events FOR VALUES FROM('2027-08-01') TO('2027-09-01');
CREATE TABLE IF NOT EXISTS biometric_events_default PARTITION OF biometric_events DEFAULT;
CREATE TABLE IF NOT EXISTS attendance_incidents_default PARTITION OF attendance_incidents DEFAULT;
CREATE TABLE IF NOT EXISTS internal_messages_default PARTITION OF internal_messages DEFAULT;
CREATE TABLE IF NOT EXISTS twilio_messages_default PARTITION OF twilio_messages DEFAULT;
CREATE TABLE IF NOT EXISTS user_commands_default PARTITION OF user_commands DEFAULT;
CREATE TABLE IF NOT EXISTS sos_alerts_default PARTITION OF sos_alerts DEFAULT;
CREATE TABLE IF NOT EXISTS student_record_audit_default PARTITION OF student_record_audit DEFAULT;
CREATE TABLE IF NOT EXISTS global_audit_logs_default PARTITION OF global_audit_logs DEFAULT;

-- PHONE NORMALIZATION TRIGGER
CREATE OR REPLACE FUNCTION fn_guardians_normalize_phone() RETURNS TRIGGER AS $$ BEGIN NEW.whatsapp_phone_normalized := regexp_replace(COALESCE(NEW.whatsapp_phone,''),'[^0-9+]','','g'); RETURN NEW; END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_guardians_normalize_phone ON guardians;
CREATE TRIGGER trg_guardians_normalize_phone BEFORE INSERT OR UPDATE OF whatsapp_phone ON guardians FOR EACH ROW EXECUTE FUNCTION fn_guardians_normalize_phone();

-- AUDIT CHAIN
CREATE OR REPLACE FUNCTION fn_calculate_audit_hash(p_prev_hash TEXT, p_school_id UUID, p_actor_id UUID, p_event_type TEXT, p_description TEXT, p_ip_address TEXT, p_created_at TIMESTAMPTZ) RETURNS TEXT AS $$ DECLARE v_secret TEXT; v_payload TEXT; BEGIN v_secret := current_setting('app.nexo_hmac_secret', true); IF v_secret IS NULL OR v_secret = '' OR v_secret = 'default-secret-change-me' THEN RAISE EXCEPTION 'app.nexo_hmac_secret no configurado. Abortando para prevenir compromiso de cadena de auditoría.'; END IF; v_payload := COALESCE(p_prev_hash,'GENESIS')||'|'||COALESCE(p_school_id::TEXT,'NULL')||'|'||COALESCE(p_actor_id::TEXT,'NULL')||'|'||COALESCE(p_event_type,'')||'|'||COALESCE(p_description,'')||'|'||COALESCE(p_ip_address,'')||'|'||COALESCE(p_created_at::TEXT,''); RETURN encode(hmac(v_payload,v_secret,'sha256'),'hex'); END; $$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

CREATE OR REPLACE FUNCTION fn_audit_chain_trigger() RETURNS TRIGGER AS $$ DECLARE v_prev_hash TEXT; v_prev_id UUID; BEGIN SELECT log_id, chain_hash INTO v_prev_id, v_prev_hash FROM global_audit_logs WHERE(school_id IS NOT DISTINCT FROM NEW.school_id) ORDER BY created_at DESC, log_id DESC LIMIT 1 FOR UPDATE; NEW.prev_audit_id := v_prev_id; NEW.chain_hash := fn_calculate_audit_hash(v_prev_hash, NEW.school_id, NEW.performed_by_user_id, NEW.action_type, NEW.action_details::TEXT, NEW.ip_address::TEXT, NEW.created_at); RETURN NEW; END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_audit_chain ON global_audit_logs; CREATE TRIGGER trg_audit_chain BEFORE INSERT ON global_audit_logs FOR EACH ROW EXECUTE FUNCTION fn_audit_chain_trigger();

CREATE OR REPLACE FUNCTION fn_validate_audit_chain(p_school_id UUID DEFAULT NULL) RETURNS JSONB AS $$ DECLARE v_expected_hash TEXT; v_prev_hash TEXT; v_record RECORD; v_broken_at UUID := NULL; v_count INTEGER := 0; v_valid_count INTEGER := 0; BEGIN v_prev_hash := NULL; FOR v_record IN SELECT log_id, school_id, performed_by_user_id, action_type, action_details, ip_address, created_at, chain_hash, prev_audit_id FROM global_audit_logs WHERE(p_school_id IS NULL OR school_id = p_school_id) ORDER BY created_at ASC, log_id ASC LOOP v_count := v_count + 1; v_expected_hash := fn_calculate_audit_hash(v_prev_hash, v_record.school_id, v_record.performed_by_user_id, v_record.action_type, v_record.action_details::TEXT, v_record.ip_address::TEXT, v_record.created_at); IF v_record.chain_hash = v_expected_hash THEN v_valid_count := v_valid_count + 1; ELSE v_broken_at := v_record.log_id; EXIT; END IF; v_prev_hash := v_record.chain_hash; END LOOP; IF v_broken_at IS NOT NULL THEN RETURN jsonb_build_object('status','compromised','broken_at_audit_id',v_broken_at,'total_checked',v_count,'valid_up_to',v_valid_count - 1); ELSE RETURN jsonb_build_object('status','ok','total_records',v_count,'school_id',p_school_id); END IF; END; $$ LANGUAGE plpgsql;
UPDATE global_audit_logs SET chain_hash = 'LEGACY_'||md5(log_id::TEXT) WHERE chain_hash IS NULL;

-- BEHAVIOR METRICS (UUID adapted)
CREATE OR REPLACE FUNCTION fn_calculate_student_risk(p_student_id UUID, p_school_id UUID, p_window_days INTEGER DEFAULT 30) RETURNS JSONB AS $$ DECLARE v_late_count INTEGER; v_absence_count INTEGER; v_total_events INTEGER; v_risk_score NUMERIC(5,2); v_risk_level VARCHAR(20); v_threshold CONSTANT NUMERIC(5,2) := 70.00; v_metric_id UUID; BEGIN SELECT (SELECT COUNT(*) FROM attendance_incidents WHERE student_id = p_student_id AND school_id = p_school_id AND incident_type = 'LATE_ARRIVAL' AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL), (SELECT COUNT(*) FROM attendance_incidents WHERE student_id = p_student_id AND school_id = p_school_id AND incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE') AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL), (SELECT COUNT(*) FROM biometric_events WHERE student_id = p_student_id AND school_id = p_school_id AND event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota') - (p_window_days || ' days')::INTERVAL) INTO v_late_count, v_absence_count, v_total_events; v_risk_score := LEAST(100.00, (v_late_count * 5.0) + (v_absence_count * 15.0) + GREATEST(0, (v_total_events - 20) * 0.5)); v_risk_level := CASE WHEN v_risk_score >= 80 THEN 'CRITICAL' WHEN v_risk_score >= 60 THEN 'HIGH' WHEN v_risk_score >= 30 THEN 'MEDIUM' ELSE 'LOW' END; INSERT INTO student_behavior_metrics(school_id, student_id, calculated_at, late_count, absence_count, total_events, risk_score, risk_level, calculation_window_days, metadata_json) VALUES(p_school_id, p_student_id, NOW(), v_late_count, v_absence_count, v_total_events, v_risk_score, v_risk_level, p_window_days, jsonb_build_object('threshold', v_threshold, 'window_days', p_window_days)) ON CONFLICT(student_id, calculation_window_days) DO UPDATE SET calculated_at = EXCLUDED.calculated_at, late_count = EXCLUDED.late_count, absence_count = EXCLUDED.absence_count, total_events = EXCLUDED.total_events, risk_score = EXCLUDED.risk_score, risk_level = EXCLUDED.risk_level, metadata_json = EXCLUDED.metadata_json RETURNING metric_id INTO v_metric_id; IF v_risk_score >= v_threshold THEN INSERT INTO attendance_incidents(incident_id, school_id, student_id, incident_type, detected_at, metadata_json) SELECT uuid_generate_v4(), p_school_id, p_student_id, 'RISK_ALERT_' || v_risk_level, NOW(), jsonb_build_object('risk_score', v_risk_score, 'metric_id', v_metric_id, 'late_count', v_late_count, 'absence_count', v_absence_count, 'trigger_threshold', v_threshold) WHERE NOT EXISTS(SELECT 1 FROM attendance_incidents WHERE student_id = p_student_id AND school_id = p_school_id AND incident_type LIKE 'RISK_ALERT%' AND detected_at >= NOW() - INTERVAL '7 days'); END IF; RETURN jsonb_build_object('metric_id', v_metric_id, 'risk_score', v_risk_score, 'risk_level', v_risk_level, 'late_count', v_late_count, 'absence_count', v_absence_count, 'threshold_exceeded', v_risk_score >= v_threshold); END; $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION fn_recalculate_school_metrics(p_school_id UUID) RETURNS INTEGER AS $$ DECLARE v_count INTEGER; BEGIN INSERT INTO student_behavior_metrics(school_id, student_id, calculated_at, late_count, absence_count, total_events, risk_score, risk_level, calculation_window_days, metadata_json) SELECT p_school_id, s.student_id, NOW(), COALESCE(be.late_count, 0), COALESCE(be.absence_count, 0), COALESCE(be.total_events, 0), LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)), CASE WHEN LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)) >= 80 THEN 'CRITICAL' WHEN LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)) >= 60 THEN 'HIGH' WHEN LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)) >= 30 THEN 'MEDIUM' ELSE 'LOW' END, 30, jsonb_build_object('recalculated_at', NOW()) FROM students s LEFT JOIN(SELECT s2.student_id, COALESCE(ai.late_count, 0) AS late_count, COALESCE(ai2.absence_count, 0) AS absence_count, COALESCE(bev.total_events, 0) AS total_events FROM students s2 LEFT JOIN (SELECT student_id, COUNT(*) AS late_count FROM attendance_incidents WHERE incident_type = 'LATE_ARRIVAL' AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days' GROUP BY student_id) ai ON ai.student_id = s2.student_id LEFT JOIN (SELECT student_id, COUNT(*) AS absence_count FROM attendance_incidents WHERE incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE') AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days' GROUP BY student_id) ai2 ON ai2.student_id = s2.student_id LEFT JOIN (SELECT student_id, COUNT(*) AS total_events FROM biometric_events WHERE event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days' GROUP BY student_id) bev ON bev.student_id = s2.student_id) be ON be.student_id = s.student_id WHERE s.school_id = p_school_id AND s.active = TRUE ON CONFLICT(student_id, calculation_window_days) DO UPDATE SET calculated_at = EXCLUDED.calculated_at, late_count = EXCLUDED.late_count, absence_count = EXCLUDED.absence_count, total_events = EXCLUDED.total_events, risk_score = EXCLUDED.risk_score, risk_level = EXCLUDED.risk_level, metadata_json = EXCLUDED.metadata_json; GET DIAGNOSTICS v_count = ROW_COUNT; RETURN v_count; END; $$ LANGUAGE plpgsql;

-- RLS HELPERS (moved before usage to fix order dependency)
CREATE OR REPLACE FUNCTION get_current_school_id() RETURNS UUID AS $$ DECLARE v_school_id TEXT; BEGIN v_school_id := current_setting('app.current_school_id', true); IF v_school_id IS NULL OR v_school_id = '' THEN RETURN NULL; END IF; RETURN v_school_id::UUID; EXCEPTION WHEN OTHERS THEN RETURN NULL; END; $$ LANGUAGE plpgsql SECURITY DEFINER;
CREATE OR REPLACE FUNCTION get_current_role() RETURNS TEXT AS $$ DECLARE v_role TEXT; BEGIN v_role := current_setting('app.current_role', true); IF v_role IS NULL OR v_role = '' THEN RETURN NULL; END IF; RETURN v_role; EXCEPTION WHEN OTHERS THEN RETURN NULL; END; $$ LANGUAGE plpgsql SECURITY DEFINER;
ALTER TABLE students ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS students_select ON students; DROP POLICY IF EXISTS students_insert ON students; DROP POLICY IF EXISTS students_update ON students; DROP POLICY IF EXISTS students_delete ON students;
CREATE POLICY students_select ON students FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY students_insert ON students FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY students_update ON students FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY students_delete ON students FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE biometric_events ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS be_select ON biometric_events; DROP POLICY IF EXISTS be_insert ON biometric_events; DROP POLICY IF EXISTS be_update ON biometric_events; DROP POLICY IF EXISTS be_delete ON biometric_events;
CREATE POLICY be_select ON biometric_events FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY be_insert ON biometric_events FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY be_update ON biometric_events FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY be_delete ON biometric_events FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE attendance_incidents ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ai_select ON attendance_incidents; DROP POLICY IF EXISTS ai_insert ON attendance_incidents; DROP POLICY IF EXISTS ai_update ON attendance_incidents; DROP POLICY IF EXISTS ai_delete ON attendance_incidents;
CREATE POLICY ai_select ON attendance_incidents FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY ai_insert ON attendance_incidents FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY ai_update ON attendance_incidents FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY ai_delete ON attendance_incidents FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE sos_alerts ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sos_select ON sos_alerts; DROP POLICY IF EXISTS sos_insert ON sos_alerts; DROP POLICY IF EXISTS sos_update ON sos_alerts; DROP POLICY IF EXISTS sos_delete ON sos_alerts;
CREATE POLICY sos_select ON sos_alerts FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY sos_insert ON sos_alerts FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY sos_update ON sos_alerts FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY sos_delete ON sos_alerts FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE global_audit_logs ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS gal_select ON global_audit_logs; DROP POLICY IF EXISTS gal_insert ON global_audit_logs;
CREATE POLICY gal_select ON global_audit_logs FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY gal_insert ON global_audit_logs FOR INSERT WITH CHECK(school_id = get_current_school_id());

ALTER TABLE twilio_messages ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tm_select ON twilio_messages; DROP POLICY IF EXISTS tm_insert ON twilio_messages; DROP POLICY IF EXISTS tm_update ON twilio_messages; DROP POLICY IF EXISTS tm_delete ON twilio_messages;
CREATE POLICY tm_select ON twilio_messages FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY tm_insert ON twilio_messages FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY tm_update ON twilio_messages FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY tm_delete ON twilio_messages FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE edge_devices ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ed_select ON edge_devices; DROP POLICY IF EXISTS ed_insert ON edge_devices; DROP POLICY IF EXISTS ed_update ON edge_devices; DROP POLICY IF EXISTS ed_delete ON edge_devices;
CREATE POLICY ed_select ON edge_devices FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY ed_insert ON edge_devices FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY ed_update ON edge_devices FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY ed_delete ON edge_devices FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE student_behavior_metrics ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sbm_select ON student_behavior_metrics; DROP POLICY IF EXISTS sbm_insert ON student_behavior_metrics; DROP POLICY IF EXISTS sbm_update ON student_behavior_metrics; DROP POLICY IF EXISTS sbm_delete ON student_behavior_metrics;
CREATE POLICY sbm_select ON student_behavior_metrics FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY sbm_insert ON student_behavior_metrics FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY sbm_update ON student_behavior_metrics FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY sbm_delete ON student_behavior_metrics FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE user_commands ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS uc_select ON user_commands; DROP POLICY IF EXISTS uc_insert ON user_commands; DROP POLICY IF EXISTS uc_update ON user_commands; DROP POLICY IF EXISTS uc_delete ON user_commands;
CREATE POLICY uc_select ON user_commands FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY uc_insert ON user_commands FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY uc_update ON user_commands FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY uc_delete ON user_commands FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE guardian_student_relationships ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS gsr_select ON guardian_student_relationships; DROP POLICY IF EXISTS gsr_insert ON guardian_student_relationships; DROP POLICY IF EXISTS gsr_delete ON guardian_student_relationships;
CREATE POLICY gsr_select ON guardian_student_relationships FOR SELECT USING(EXISTS(SELECT 1 FROM students s WHERE s.student_id = guardian_student_relationships.student_id AND s.school_id = get_current_school_id()) OR get_current_role() = 'SYSTEM_WORKER');
CREATE POLICY gsr_insert ON guardian_student_relationships FOR INSERT WITH CHECK(EXISTS(SELECT 1 FROM students s WHERE s.student_id = guardian_student_relationships.student_id AND s.school_id = get_current_school_id()));
CREATE POLICY gsr_delete ON guardian_student_relationships FOR DELETE USING(EXISTS(SELECT 1 FROM students s WHERE s.student_id = guardian_student_relationships.student_id AND s.school_id = get_current_school_id()));

ALTER TABLE student_group_assignments ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sga_select ON student_group_assignments; DROP POLICY IF EXISTS sga_insert ON student_group_assignments; DROP POLICY IF EXISTS sga_update ON student_group_assignments; DROP POLICY IF EXISTS sga_delete ON student_group_assignments;
CREATE POLICY sga_select ON student_group_assignments FOR SELECT USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sga_insert ON student_group_assignments FOR INSERT WITH CHECK(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sga_update ON student_group_assignments FOR UPDATE USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sga_delete ON student_group_assignments FOR DELETE USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()));

ALTER TABLE schedules ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sch_select ON schedules; DROP POLICY IF EXISTS sch_insert ON schedules; DROP POLICY IF EXISTS sch_update ON schedules; DROP POLICY IF EXISTS sch_delete ON schedules;
CREATE POLICY sch_select ON schedules FOR SELECT USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = schedules.group_id AND ag.school_id = get_current_school_id()) OR get_current_role() = 'SYSTEM_WORKER');
CREATE POLICY sch_insert ON schedules FOR INSERT WITH CHECK(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = schedules.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sch_update ON schedules FOR UPDATE USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = schedules.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sch_delete ON schedules FOR DELETE USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = schedules.group_id AND ag.school_id = get_current_school_id()));

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS users_select ON users; DROP POLICY IF EXISTS users_insert ON users; DROP POLICY IF EXISTS users_update ON users; DROP POLICY IF EXISTS users_delete ON users;
CREATE POLICY users_select ON users FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY users_insert ON users FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY users_update ON users FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY users_delete ON users FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE guardians ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS guardians_select ON guardians; DROP POLICY IF EXISTS guardians_insert ON guardians; DROP POLICY IF EXISTS guardians_update ON guardians; DROP POLICY IF EXISTS guardians_delete ON guardians;
CREATE POLICY guardians_select ON guardians FOR SELECT USING(EXISTS(SELECT 1 FROM users u WHERE u.user_id = guardians.user_id AND u.school_id = get_current_school_id()));
CREATE POLICY guardians_insert ON guardians FOR INSERT WITH CHECK(EXISTS(SELECT 1 FROM users u WHERE u.user_id = guardians.user_id AND u.school_id = get_current_school_id()));
CREATE POLICY guardians_update ON guardians FOR UPDATE USING(EXISTS(SELECT 1 FROM users u WHERE u.user_id = guardians.user_id AND u.school_id = get_current_school_id()));
CREATE POLICY guardians_delete ON guardians FOR DELETE USING(EXISTS(SELECT 1 FROM users u WHERE u.user_id = guardians.user_id AND u.school_id = get_current_school_id()));

ALTER TABLE school_panic_events ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS spe_select ON school_panic_events; DROP POLICY IF EXISTS spe_insert ON school_panic_events; DROP POLICY IF EXISTS spe_update ON school_panic_events; DROP POLICY IF EXISTS spe_delete ON school_panic_events;
CREATE POLICY spe_select ON school_panic_events FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY spe_insert ON school_panic_events FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY spe_update ON school_panic_events FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY spe_delete ON school_panic_events FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE student_tracking ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS st_select ON student_tracking; DROP POLICY IF EXISTS st_insert ON student_tracking; DROP POLICY IF EXISTS st_update ON student_tracking; DROP POLICY IF EXISTS st_delete ON student_tracking;
CREATE POLICY st_select ON student_tracking FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY st_insert ON student_tracking FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY st_update ON student_tracking FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY st_delete ON student_tracking FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE student_tracking_notes ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS stn_select ON student_tracking_notes; DROP POLICY IF EXISTS stn_insert ON student_tracking_notes; DROP POLICY IF EXISTS stn_delete ON student_tracking_notes;
CREATE POLICY stn_select ON student_tracking_notes FOR SELECT USING(EXISTS(SELECT 1 FROM student_tracking st WHERE st.tracking_id = student_tracking_notes.tracking_id AND st.school_id = get_current_school_id()));
CREATE POLICY stn_insert ON student_tracking_notes FOR INSERT WITH CHECK(EXISTS(SELECT 1 FROM student_tracking st WHERE st.tracking_id = student_tracking_notes.tracking_id AND st.school_id = get_current_school_id()));
CREATE POLICY stn_delete ON student_tracking_notes FOR DELETE USING(EXISTS(SELECT 1 FROM student_tracking st WHERE st.tracking_id = student_tracking_notes.tracking_id AND st.school_id = get_current_school_id()));

ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS notif_select ON notifications; DROP POLICY IF EXISTS notif_insert ON notifications; DROP POLICY IF EXISTS notif_delete ON notifications;
CREATE POLICY notif_select ON notifications FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY notif_insert ON notifications FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY notif_delete ON notifications FOR DELETE USING(school_id = get_current_school_id());

ALTER TABLE jwt_blocklist ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS jbl_select ON jwt_blocklist; DROP POLICY IF EXISTS jbl_insert ON jwt_blocklist;
-- FIX (BUG-8): jwt_blocklist es tabla de sistema para revocación JWT, no datos de usuario.
-- Las operaciones revokeJwt() e isJwtRevoked() se ejecutan antes de requireAuth() configure el rol.
CREATE POLICY jbl_select ON jwt_blocklist FOR SELECT USING(true);
CREATE POLICY jbl_insert ON jwt_blocklist FOR INSERT WITH CHECK(true);

-- FIX 2: subjects es tabla de catálogo global (sin school_id). RLS con lectura global.
ALTER TABLE subjects ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS subjects_select ON subjects;
CREATE POLICY subjects_select ON subjects FOR SELECT USING(true);

-- SEED DATA
INSERT INTO departments(department_id, department_name) VALUES(uuid_generate_v4(), 'Bogotá D.C.') ON CONFLICT DO NOTHING;
INSERT INTO municipalities(municipality_id, department_id, municipality_name) SELECT uuid_generate_v4(), d.department_id, 'Bogotá D.C.' FROM departments d WHERE d.department_name = 'Bogotá D.C.' ON CONFLICT DO NOTHING;
INSERT INTO schools(school_id, municipality_id, dane_code, school_name, address, phone, email, active) SELECT uuid_generate_v4(), m.municipality_id, '000000000', 'Institución Educativa NEXO', 'Calle 1 # 1-1', '6010000000', 'contacto@nexo.edu', TRUE FROM municipalities m JOIN departments d ON d.department_id = m.department_id WHERE d.department_name = 'Bogotá D.C.' ON CONFLICT DO NOTHING;

INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'RECTOR', 'School principal') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'COORDINATOR', 'Academic / disciplinary coordinator') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'TEACHER', 'Classroom teacher') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'SECRETARY', 'Administrative secretary') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'SECURITY', 'Security guard / gatekeeper') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'AUXILIARY', 'Administrative auxiliary') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'COUNSELOR', 'School counselor / psychologist') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'GUARDIAN', 'Student guardian / parent') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'SUPER_ADMIN', 'Global system administrator') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'SYSTEM_WORKER', 'Internal system worker / background process') ON CONFLICT(role_name) DO NOTHING;

-- ADMIN USER (password: admin123 | generate hash with: php -r "echo password_hash('admin123', PASSWORD_BCRYPT);")
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, password_hash, password_salt, active)
SELECT uuid_generate_v4(), s.school_id, r.role_id, '111111111', 'Admin', 'NEXO', 'admin@nexo.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'salt', TRUE
FROM schools s, roles r WHERE r.role_name = 'RECTOR' ON CONFLICT(email) DO NOTHING;

-- =============================================================================
-- PERMISSIONS (canonical — inglés, alineado con PHP)
-- =============================================================================
INSERT INTO permissions (permission_id, permission_code, description) VALUES
    (uuid_generate_v4(), 'dashboard.teacher_view', 'View dashboard filtered by assigned groups'),
    (uuid_generate_v4(), 'dashboard.global_view', 'View full institution dashboard'),
    (uuid_generate_v4(), 'operations.sos', 'Generate SOS alert'),
    (uuid_generate_v4(), 'operations.inasistencia', 'Report absence and notify guardian'),
    (uuid_generate_v4(), 'operations.citacion', 'Send guardian citation via WhatsApp'),
    (uuid_generate_v4(), 'operations.autorizar_salida', 'Authorize student exit'),
    (uuid_generate_v4(), 'operations.permiso', 'Generate class exit permission'),
    (uuid_generate_v4(), 'operations.solicitud', 'Send internal request to another user'),
    (uuid_generate_v4(), 'operations.daño', 'Report institutional damage'),
    (uuid_generate_v4(), 'operations.pedagogica', 'Register group pedagogical exit'),
    (uuid_generate_v4(), 'operations.horario', 'Notify group schedule change'),
    (uuid_generate_v4(), 'operations.incidente', 'Report disciplinary incident'),
    (uuid_generate_v4(), 'operations.seguimiento', 'Request counselor tracking'),
    (uuid_generate_v4(), 'operations.fusionar_bloque', 'Merge class blocks for sensor logic'),
    (uuid_generate_v4(), 'operations.extender_bloque', 'Extend current block end time for the day'),
    (uuid_generate_v4(), 'operations.situacion_critica', 'Report critical situation to rector and coordinator'),
    (uuid_generate_v4(), 'consultations.teacher_view', 'View queries filtered by assigned groups'),
    (uuid_generate_v4(), 'consultations.global_view', 'View all institution queries'),
    (uuid_generate_v4(), 'reports.preview', 'View biometric report preview'),
    (uuid_generate_v4(), 'reports.export', 'Export institutional reports'),
    (uuid_generate_v4(), 'devices.manage', 'Register, revoke and command EDGE devices'),
    (uuid_generate_v4(), 'devices.admin_health', 'View global device health check'),
    (uuid_generate_v4(), 'audit.view', 'View full audit modules'),
    (uuid_generate_v4(), 'audit.integrity', 'Validate audit hash chain integrity'),
    (uuid_generate_v4(), 'security.panic', 'Activate emergency panic mode'),
    (uuid_generate_v4(), 'admin.recalc_risk', 'Recalculate student risk metrics'),
    (uuid_generate_v4(), 'admin.users_manage', 'Manage system users'),
    (uuid_generate_v4(), 'students.create', 'Create and edit students'),
    (uuid_generate_v4(), 'students.view', 'View student list'),
    (uuid_generate_v4(), 'tracking.manage', 'Manage student tracking cases'),
    (uuid_generate_v4(), 'behavior.view_risk', 'View behavioral risk metrics')
ON CONFLICT (permission_code) DO NOTHING;

-- =============================================================================
-- ROLE_PERMISSIONS (helper function + assignments)
-- =============================================================================
CREATE OR REPLACE FUNCTION assign_permission_to_role(p_role_name VARCHAR, p_permission_code VARCHAR)
RETURNS VOID AS $$
DECLARE
    v_role_id UUID;
    v_permission_id UUID;
BEGIN
    SELECT role_id INTO v_role_id FROM roles WHERE role_name = p_role_name;
    SELECT permission_id INTO v_permission_id FROM permissions WHERE permission_code = p_permission_code;
    IF v_role_id IS NOT NULL AND v_permission_id IS NOT NULL THEN
        INSERT INTO role_permissions (role_id, permission_id)
        VALUES (v_role_id, v_permission_id)
        ON CONFLICT (role_id, permission_id) DO NOTHING;
    END IF;
END;
$$ LANGUAGE plpgsql;

-- RECTOR: all permissions (rol máximo)
SELECT assign_permission_to_role('RECTOR', 'dashboard.global_view');
SELECT assign_permission_to_role('RECTOR', 'operations.sos');
SELECT assign_permission_to_role('RECTOR', 'operations.inasistencia');
SELECT assign_permission_to_role('RECTOR', 'operations.citacion');
SELECT assign_permission_to_role('RECTOR', 'operations.autorizar_salida');
SELECT assign_permission_to_role('RECTOR', 'operations.permiso');
SELECT assign_permission_to_role('RECTOR', 'operations.solicitud');
SELECT assign_permission_to_role('RECTOR', 'operations.daño');
SELECT assign_permission_to_role('RECTOR', 'operations.pedagogica');
SELECT assign_permission_to_role('RECTOR', 'operations.horario');
SELECT assign_permission_to_role('RECTOR', 'operations.incidente');
SELECT assign_permission_to_role('RECTOR', 'operations.seguimiento');
SELECT assign_permission_to_role('RECTOR', 'operations.extender_bloque');
SELECT assign_permission_to_role('RECTOR', 'operations.situacion_critica');
SELECT assign_permission_to_role('RECTOR', 'consultations.global_view');
SELECT assign_permission_to_role('RECTOR', 'reports.preview');
SELECT assign_permission_to_role('RECTOR', 'reports.export');
SELECT assign_permission_to_role('RECTOR', 'devices.manage');
SELECT assign_permission_to_role('RECTOR', 'audit.view');
SELECT assign_permission_to_role('RECTOR', 'audit.integrity');
SELECT assign_permission_to_role('RECTOR', 'security.panic');
SELECT assign_permission_to_role('RECTOR', 'admin.recalc_risk');
SELECT assign_permission_to_role('RECTOR', 'admin.users_manage');
SELECT assign_permission_to_role('RECTOR', 'students.create');
SELECT assign_permission_to_role('RECTOR', 'students.view');
SELECT assign_permission_to_role('RECTOR', 'tracking.manage');
SELECT assign_permission_to_role('RECTOR', 'behavior.view_risk');

-- COORDINATOR: similar to RECTOR minus admin functions
SELECT assign_permission_to_role('COORDINATOR', 'dashboard.global_view');
SELECT assign_permission_to_role('COORDINATOR', 'operations.sos');
SELECT assign_permission_to_role('COORDINATOR', 'operations.inasistencia');
SELECT assign_permission_to_role('COORDINATOR', 'operations.citacion');
SELECT assign_permission_to_role('COORDINATOR', 'operations.autorizar_salida');
SELECT assign_permission_to_role('COORDINATOR', 'operations.permiso');
SELECT assign_permission_to_role('COORDINATOR', 'operations.solicitud');
SELECT assign_permission_to_role('COORDINATOR', 'operations.daño');
SELECT assign_permission_to_role('COORDINATOR', 'operations.pedagogica');
SELECT assign_permission_to_role('COORDINATOR', 'operations.horario');
SELECT assign_permission_to_role('COORDINATOR', 'operations.incidente');
SELECT assign_permission_to_role('COORDINATOR', 'operations.seguimiento');
SELECT assign_permission_to_role('COORDINATOR', 'operations.extender_bloque');
SELECT assign_permission_to_role('COORDINATOR', 'operations.situacion_critica');
SELECT assign_permission_to_role('COORDINATOR', 'consultations.global_view');
SELECT assign_permission_to_role('COORDINATOR', 'reports.preview');
SELECT assign_permission_to_role('COORDINATOR', 'reports.export');
SELECT assign_permission_to_role('COORDINATOR', 'devices.manage');
SELECT assign_permission_to_role('COORDINATOR', 'audit.view');
SELECT assign_permission_to_role('COORDINATOR', 'audit.integrity');
SELECT assign_permission_to_role('COORDINATOR', 'security.panic');
SELECT assign_permission_to_role('COORDINATOR', 'students.create');
SELECT assign_permission_to_role('COORDINATOR', 'students.view');
SELECT assign_permission_to_role('COORDINATOR', 'tracking.manage');
SELECT assign_permission_to_role('COORDINATOR', 'behavior.view_risk');

-- TEACHER: classroom operations only
SELECT assign_permission_to_role('TEACHER', 'dashboard.teacher_view');
SELECT assign_permission_to_role('TEACHER', 'operations.inasistencia');
SELECT assign_permission_to_role('TEACHER', 'operations.citacion');
SELECT assign_permission_to_role('TEACHER', 'operations.permiso');
SELECT assign_permission_to_role('TEACHER', 'operations.pedagogica');
SELECT assign_permission_to_role('TEACHER', 'operations.horario');
SELECT assign_permission_to_role('TEACHER', 'operations.incidente');
SELECT assign_permission_to_role('TEACHER', 'operations.seguimiento');
SELECT assign_permission_to_role('TEACHER', 'operations.fusionar_bloque');
SELECT assign_permission_to_role('TEACHER', 'operations.situacion_critica');
SELECT assign_permission_to_role('TEACHER', 'consultations.teacher_view');
SELECT assign_permission_to_role('TEACHER', 'reports.preview');
SELECT assign_permission_to_role('TEACHER', 'students.view');
SELECT assign_permission_to_role('TEACHER', 'behavior.view_risk');

-- SECRETARY: student management, reports, queries
SELECT assign_permission_to_role('SECRETARY', 'dashboard.global_view');
SELECT assign_permission_to_role('SECRETARY', 'students.create');
SELECT assign_permission_to_role('SECRETARY', 'students.view');
SELECT assign_permission_to_role('SECRETARY', 'consultations.global_view');
SELECT assign_permission_to_role('SECRETARY', 'reports.preview');
SELECT assign_permission_to_role('SECRETARY', 'reports.export');
SELECT assign_permission_to_role('SECRETARY', 'operations.solicitud');
SELECT assign_permission_to_role('SECRETARY', 'operations.situacion_critica');

-- COUNSELOR: tracking, behavior, consultations
SELECT assign_permission_to_role('COUNSELOR', 'dashboard.teacher_view');
SELECT assign_permission_to_role('COUNSELOR', 'tracking.manage');
SELECT assign_permission_to_role('COUNSELOR', 'behavior.view_risk');
SELECT assign_permission_to_role('COUNSELOR', 'consultations.teacher_view');
SELECT assign_permission_to_role('COUNSELOR', 'consultations.global_view');
SELECT assign_permission_to_role('COUNSELOR', 'operations.seguimiento');
SELECT assign_permission_to_role('COUNSELOR', 'operations.situacion_critica');
SELECT assign_permission_to_role('COUNSELOR', 'students.view');

-- SECURITY: view only
SELECT assign_permission_to_role('SECURITY', 'students.view');
SELECT assign_permission_to_role('SECURITY', 'consultations.global_view');
SELECT assign_permission_to_role('SECURITY', 'reports.preview');

-- AUXILIARY: similar to secretary minus student creation
SELECT assign_permission_to_role('AUXILIARY', 'dashboard.global_view');
SELECT assign_permission_to_role('AUXILIARY', 'students.view');
SELECT assign_permission_to_role('AUXILIARY', 'consultations.global_view');
SELECT assign_permission_to_role('AUXILIARY', 'reports.preview');
SELECT assign_permission_to_role('AUXILIARY', 'operations.solicitud');

DROP FUNCTION IF EXISTS assign_permission_to_role(VARCHAR, VARCHAR);

-- =============================================================================
-- ONBOARDING DE HORARIOS INSTITUCIONALES + LÓGICA DE EVASIÓN
-- Tablas: school_schedule_config (multi-jornada), school_time_blocks
-- Columnas: schools.onboarding_completed, class_exit_authorizations.status
-- Funciones: is_student_present_today, has_active_permiso, get_active_permiso_info
-- =============================================================================

-- school_schedule_config — Config horarios por jornada (PK school_id + work_shift)
CREATE TABLE IF NOT EXISTS school_schedule_config (
    school_id              UUID NOT NULL REFERENCES schools(school_id),
    rotates_classrooms     BOOLEAN NOT NULL DEFAULT FALSE,
    work_shift             VARCHAR(50) NOT NULL DEFAULT 'mañana',
    entry_time             TIME,
    exit_time              TIME,
    recess_start_time      TIME,
    recess_end_time        TIME,
    onboarding_completed   BOOLEAN NOT NULL DEFAULT FALSE,
    onboarding_completed_by UUID REFERENCES users(user_id),
    onboarding_completed_at TIMESTAMPTZ,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (school_id, work_shift)
);

COMMENT ON TABLE school_schedule_config IS 'Configuración de horarios por jornada. Una fila por (school_id, work_shift). Creada durante el onboarding obligatorio.';
COMMENT ON COLUMN school_schedule_config.rotates_classrooms IS 'TRUE = colegio rota de salones. FALSE = colegio no rota.';
COMMENT ON COLUMN school_schedule_config.work_shift IS 'Jornada: mañana, tarde, noche, completa. Una fila por jornada.';

ALTER TABLE school_schedule_config ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS school_schedule_config_select ON school_schedule_config;
CREATE POLICY school_schedule_config_select ON school_schedule_config
    FOR SELECT USING (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_schedule_config_insert ON school_schedule_config;
CREATE POLICY school_schedule_config_insert ON school_schedule_config
    FOR INSERT WITH CHECK (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_schedule_config_update ON school_schedule_config;
CREATE POLICY school_schedule_config_update ON school_schedule_config
    FOR UPDATE USING (school_id = get_current_school_id());

-- school_time_blocks — Bloques horarios por jornada
CREATE TABLE IF NOT EXISTS school_time_blocks (
    block_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id     UUID NOT NULL REFERENCES schools(school_id),
    work_shift    VARCHAR(50) NOT NULL DEFAULT 'mañana',
    block_number  INTEGER NOT NULL,
    block_name    VARCHAR(100),
    start_time    TIME NOT NULL,
    end_time      TIME NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE school_time_blocks IS 'Bloques horarios por jornada. Solo se usa cuando rotates_classrooms=TRUE.';
COMMENT ON COLUMN school_time_blocks.work_shift IS 'Jornada a la que pertenece este bloque horario.';

-- Constraint único: (school_id, work_shift, block_number)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'school_time_blocks_school_workshift_block_key') THEN
        ALTER TABLE school_time_blocks ADD CONSTRAINT school_time_blocks_school_workshift_block_key UNIQUE(school_id, work_shift, block_number);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_school_time_blocks_shift ON school_time_blocks(school_id, work_shift, block_number);

ALTER TABLE school_time_blocks ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS school_time_blocks_select ON school_time_blocks;
CREATE POLICY school_time_blocks_select ON school_time_blocks
    FOR SELECT USING (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_time_blocks_insert ON school_time_blocks;
CREATE POLICY school_time_blocks_insert ON school_time_blocks
    FOR INSERT WITH CHECK (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_time_blocks_update ON school_time_blocks;
CREATE POLICY school_time_blocks_update ON school_time_blocks
    FOR UPDATE USING (school_id = get_current_school_id());
DROP POLICY IF EXISTS school_time_blocks_delete ON school_time_blocks;
CREATE POLICY school_time_blocks_delete ON school_time_blocks
    FOR DELETE USING (school_id = get_current_school_id());

-- schools — ADD onboarding_completed
ALTER TABLE schools ADD COLUMN IF NOT EXISTS onboarding_completed BOOLEAN NOT NULL DEFAULT FALSE;
COMMENT ON COLUMN schools.onboarding_completed IS 'TRUE cuando el coordinador/rector completó el onboarding de horarios institucionales';

-- class_exit_authorizations — ADD status
ALTER TABLE class_exit_authorizations ADD COLUMN IF NOT EXISTS status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE';
COMMENT ON COLUMN class_exit_authorizations.status IS 'ACTIVE = permiso vigente, EXPIRED = expiró sin retorno, COMPLETED = estudiante regresó, CANCELLED = cancelado';

CREATE INDEX IF NOT EXISTS idx_class_exit_auth_status ON class_exit_authorizations(school_id, student_id, status, exit_time);

-- Función: is_student_present_today
CREATE OR REPLACE FUNCTION is_student_present_today(
    p_school_id UUID,
    p_student_id UUID
) RETURNS BOOLEAN AS $$
DECLARE
    v_count INTEGER;
    v_bogota_date DATE;
BEGIN
    v_bogota_date := (NOW() AT TIME ZONE 'America/Bogota')::date;
    SELECT COUNT(*) INTO v_count
    FROM biometric_events
    WHERE school_id = p_school_id
      AND student_id = p_student_id
      AND event_timestamp >= v_bogota_date
      AND event_timestamp < (v_bogota_date + INTERVAL '1 day')
      AND event_type LIKE 'INGRESO_%';
    RETURN v_count > 0;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Función: has_active_permiso
CREATE OR REPLACE FUNCTION has_active_permiso(
    p_school_id UUID,
    p_student_id UUID
) RETURNS BOOLEAN AS $$
DECLARE
    v_count INTEGER;
    v_now TIMESTAMPTZ;
BEGIN
    v_now := NOW();
    SELECT COUNT(*) INTO v_count
    FROM class_exit_authorizations
    WHERE school_id = p_school_id
      AND student_id = p_student_id
      AND status = 'ACTIVE'
      AND exit_time <= v_now
      AND (return_time IS NULL OR return_time >= v_now);
    RETURN v_count > 0;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Función: get_active_permiso_info
CREATE OR REPLACE FUNCTION get_active_permiso_info(
    p_school_id UUID,
    p_student_id UUID
) RETURNS TABLE(
    authorization_id UUID,
    exit_time TIMESTAMPTZ,
    return_time TIMESTAMPTZ,
    authorization_reason TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT c.authorization_id, c.exit_time, c.return_time, c.authorization_reason
    FROM class_exit_authorizations c
    WHERE c.school_id = p_school_id
      AND c.student_id = p_student_id
      AND c.status = 'ACTIVE'
      AND c.exit_time <= NOW()
      AND (c.return_time IS NULL OR c.return_time >= NOW())
    ORDER BY c.exit_time DESC
    LIMIT 1;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- =============================================================================
-- REGISTRO DE LA MIGRACIÓN BASE
-- =============================================================================
SELECT register_migration(
    'nexo_full_migration.sql'::VARCHAR,
    '2026-08'::VARCHAR,
    'Esquema base completo consolidado: tablas, índices, constraints, triggers, funciones, RLS, particiones, seed mínimo + onboarding horarios multi-jornada + evasión'::TEXT,
    NULL::VARCHAR,
    CURRENT_USER::VARCHAR,
    NULL::INTEGER,
    'Incluye school_schedule_config (multi-jornada), school_time_blocks, class_exit_authorizations.status, funciones helper, y consolidación de migraciones anteriores.'::TEXT
);
