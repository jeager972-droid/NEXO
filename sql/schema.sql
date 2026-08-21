-- =============================================================================
-- NEXO — Esquema canónico completo y autocontenido
-- =============================================================================
-- Este archivo es el ÚNICO esquema necesario para inicializar una base de
-- datos PostgreSQL 15+ completamente vacía. Contiene:
--   - Extensiones
--   - Tabla de control de migraciones
--   - Todas las tablas con columnas, tipos, constraints, FKs
--   - Índices (incluyendo únicos y parciales)
--   - Funciones (helpers + lógica de negocio + motor de riesgo v3.0)
--   - Triggers
--   - Row Level Security + policies (todas las tablas multi-tenant)
--   - Particiones (iniciales + función de creación automática)
--   - Política de retención
--   - Seed mínimo (roles, permisos, admin, geografía)
--
-- EJECUTAR:
--   psql $DATABASE_URL -f schema.sql
--
-- IDEMPOTENTE: Todas las sentencias usan IF NOT EXISTS / ON CONFLICT.
--
-- GENERADO: 2026-08-20 — Consolidación final. Integra todas las migraciones
--   2026-20 a 2026-40 incluyendo Motor de Riesgo Pedagógico v3.0,
--   teacher_group_access, device_commands, guardian_limit, RLS en todas
--   las tablas multi-tenant, y edge_devices con EDGE_NODE.
-- =============================================================================

-- =============================================================================
-- EXTENSIONES
-- =============================================================================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- =============================================================================
-- SCHEMA MIGRATION TRACKING
-- =============================================================================
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    filename          VARCHAR(255) NOT NULL UNIQUE,
    version_label     VARCHAR(50),
    description       TEXT,
    checksum          VARCHAR(64),
    executed_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    executed_by       VARCHAR(100),
    execution_time_ms INTEGER,
    success           BOOLEAN NOT NULL DEFAULT TRUE,
    rollback_script   TEXT,
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
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

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
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

-- =============================================================================
-- COMMENTS (movidos al final del esquema, después de crear todas las tablas)
-- =============================================================================

-- =============================================================================
-- TABLAS BASE (orden por dependencias)
-- =============================================================================
CREATE TABLE IF NOT EXISTS permissions (
    permission_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    permission_code VARCHAR(120) UNIQUE NOT NULL,
    description     TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS departments (
    department_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    department_name VARCHAR(120) UNIQUE NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS municipalities (
    municipality_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    department_id   UUID NOT NULL REFERENCES departments(department_id),
    municipality_name VARCHAR(120) NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_municipality_department ON municipalities(department_id);

CREATE TABLE IF NOT EXISTS schools (
    school_id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    municipality_id            UUID NOT NULL REFERENCES municipalities(municipality_id),
    dane_code                  VARCHAR(50) UNIQUE,
    school_name                VARCHAR(255) NOT NULL,
    address                    TEXT,
    phone                      VARCHAR(30),
    email                      VARCHAR(255),
    active                     BOOLEAN NOT NULL DEFAULT TRUE,
    onboarding_completed       BOOLEAN NOT NULL DEFAULT FALSE,
    groups_onboarding_completed BOOLEAN NOT NULL DEFAULT FALSE,
    groups_onboarding_year     INTEGER,
    sensor_master_key_hash     VARCHAR(255),
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_school_municipality ON schools(municipality_id);

COMMENT ON COLUMN schools.onboarding_completed IS 'TRUE cuando el coordinador/rector completó el onboarding de horarios institucionales';
COMMENT ON COLUMN schools.groups_onboarding_completed IS 'TRUE cuando el rector completó el onboarding de grupos académicos (grados + nomenclatura + grupos por grado)';
COMMENT ON COLUMN schools.groups_onboarding_year IS 'Año electivo para el que se configuraron los grupos. Cada 1 de enero se resetea si el año no coincide';
COMMENT ON COLUMN schools.sensor_master_key_hash IS 'Hash bcrypt de la llave maestra para reconfigurar tokens de sensores';

CREATE TABLE IF NOT EXISTS roles (
    role_id     UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    role_name   VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS role_permissions (
    role_permission_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    role_id            UUID NOT NULL REFERENCES roles(role_id),
    permission_id      UUID NOT NULL REFERENCES permissions(permission_id),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_role_permission_role ON role_permissions(role_id);

-- Constraint único: (role_id, permission_id)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_role_permissions_role_permission') THEN
        ALTER TABLE role_permissions ADD CONSTRAINT uq_role_permissions_role_permission UNIQUE(role_id, permission_id);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS users (
    user_id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id        UUID NOT NULL REFERENCES schools(school_id),
    role_id          UUID NOT NULL REFERENCES roles(role_id),
    document_number  VARCHAR(30) NOT NULL,
    first_name       VARCHAR(120) NOT NULL,
    last_name        VARCHAR(120) NOT NULL,
    email            VARCHAR(255) UNIQUE,
    phone            VARCHAR(30),
    password_hash    TEXT NOT NULL,
    password_salt    TEXT NOT NULL,
    active           BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at    TIMESTAMPTZ,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ,
    deleted_at       TIMESTAMPTZ,
    profile_photo_url TEXT,
    work_shift       VARCHAR(50),
    backup_email     VARCHAR(255),
    email_verified   BOOLEAN NOT NULL DEFAULT FALSE,
    phone_verified   BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT uq_users_school_document UNIQUE (school_id, document_number)
);
CREATE INDEX IF NOT EXISTS idx_users_school ON users(school_id);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role_id);
CREATE INDEX IF NOT EXISTS idx_users_email_lower ON users(LOWER(email)) WHERE email IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_users_active_school_role ON users(active, school_id, role_id);

CREATE TABLE IF NOT EXISTS user_sessions (
    session_id         UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id            UUID NOT NULL REFERENCES users(user_id),
    refresh_token_hash TEXT NOT NULL,
    ip_address         INET,
    user_agent         TEXT,
    expires_at         TIMESTAMPTZ NOT NULL,
    revoked            BOOLEAN NOT NULL DEFAULT FALSE,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    revoked_at         TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON user_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_refresh_hash ON user_sessions(refresh_token_hash);
CREATE INDEX IF NOT EXISTS idx_sessions_expires_revoked ON user_sessions(expires_at, revoked);

CREATE TABLE IF NOT EXISTS staff_records (
    staff_record_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id       UUID NOT NULL REFERENCES schools(school_id),
    user_id         UUID NOT NULL REFERENCES users(user_id),
    hired_at        DATE,
    position_name   VARCHAR(120),
    employee_code   VARCHAR(120),
    active          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_staff_school ON staff_records(school_id);
CREATE INDEX IF NOT EXISTS idx_staff_user ON staff_records(user_id);

CREATE TABLE IF NOT EXISTS guardians (
    guardian_id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id                  UUID UNIQUE NOT NULL REFERENCES users(user_id),
    whatsapp_phone           VARCHAR(30) NOT NULL,
    emergency_contact        BOOLEAN NOT NULL DEFAULT FALSE,
    whatsapp_phone_normalized TEXT,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_guardians_whatsapp_normalized ON guardians(whatsapp_phone_normalized);

CREATE TABLE IF NOT EXISTS students (
    student_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id       UUID NOT NULL REFERENCES schools(school_id),
    document_number VARCHAR(30) NOT NULL,
    first_name      VARCHAR(120) NOT NULL,
    last_name       VARCHAR(120) NOT NULL,
    birth_date      DATE,
    biometric_hash  TEXT,
    active          BOOLEAN NOT NULL DEFAULT TRUE,
    work_shift      VARCHAR(50) DEFAULT 'mañana',
    grade_level     VARCHAR(50),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ,
    deleted_at      TIMESTAMPTZ,
    CONSTRAINT uq_students_school_document UNIQUE (school_id, document_number)
);
CREATE INDEX IF NOT EXISTS idx_students_school ON students(school_id);
CREATE INDEX IF NOT EXISTS idx_students_school_last_first ON students(school_id, last_name, first_name);
CREATE INDEX IF NOT EXISTS idx_students_document_number ON students(document_number);
CREATE INDEX IF NOT EXISTS idx_students_school_grade ON students(school_id, grade_level) WHERE deleted_at IS NULL;

COMMENT ON COLUMN students.work_shift IS 'mañana, tarde, completa. Usado para detección automática de ausentes por jornada';
COMMENT ON COLUMN students.grade_level IS 'Grado actual del estudiante. Sincronizado al asignar grupo. Permite reconstruir student_group_assignments tras onboarding.';

CREATE TABLE IF NOT EXISTS guardian_student_relationships (
    relationship_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    guardian_id       UUID NOT NULL REFERENCES guardians(guardian_id),
    student_id        UUID NOT NULL REFERENCES students(student_id),
    relationship_type VARCHAR(80),
    primary_guardian  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_guardian_rel_student_primary ON guardian_student_relationships(student_id, primary_guardian);
CREATE INDEX IF NOT EXISTS idx_guardian_rel_guardian ON guardian_student_relationships(guardian_id);

-- Constraint único: (guardian_id, student_id)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_guardian_student_relationship') THEN
        ALTER TABLE guardian_student_relationships ADD CONSTRAINT uq_guardian_student_relationship UNIQUE(guardian_id, student_id);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS academic_groups (
    group_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id     UUID NOT NULL REFERENCES schools(school_id),
    group_name    VARCHAR(120) NOT NULL,
    grade_level   VARCHAR(50),
    work_shift    VARCHAR(50) DEFAULT 'mañana',
    academic_year INTEGER NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_groups_school_year_level ON academic_groups(school_id, academic_year, grade_level, group_name);
CREATE INDEX IF NOT EXISTS idx_groups_school_year_shift ON academic_groups(school_id, academic_year, work_shift);

COMMENT ON COLUMN academic_groups.work_shift IS 'Jornada del grupo: mañana, tarde, noche, completa. Asignada en el onboarding por grado.';

-- Constraint único: (school_id, academic_year, group_name)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_academic_group_school_year_name') THEN
        ALTER TABLE academic_groups ADD CONSTRAINT uq_academic_group_school_year_name UNIQUE(school_id, academic_year, group_name);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS student_group_assignments (
    assignment_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    student_id    UUID NOT NULL REFERENCES students(student_id),
    group_id      UUID NOT NULL REFERENCES academic_groups(group_id),
    active        BOOLEAN NOT NULL DEFAULT TRUE,
    start_date    DATE,
    end_date      DATE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_student_group_assignments_student_active ON student_group_assignments(student_id, active);
CREATE INDEX IF NOT EXISTS idx_student_group_assignments_group_active ON student_group_assignments(group_id, active);

-- Constraint único: (student_id, group_id)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_sga_student_group') THEN
        ALTER TABLE student_group_assignments ADD CONSTRAINT uq_sga_student_group UNIQUE(student_id, group_id);
    END IF;
END $$;

-- Acceso de docentes a grupos por año electivo (independiente de schedules)
CREATE TABLE IF NOT EXISTS teacher_group_access (
    access_id        UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id        UUID NOT NULL REFERENCES schools(school_id),
    group_id         UUID NOT NULL REFERENCES academic_groups(group_id),
    teacher_user_id  UUID NOT NULL REFERENCES users(user_id),
    work_shift       VARCHAR(50) NOT NULL DEFAULT 'mañana',
    academic_year    INTEGER NOT NULL DEFAULT (EXTRACT(YEAR FROM NOW())::INT),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_teacher_group_year UNIQUE (teacher_user_id, group_id, academic_year)
);
CREATE INDEX IF NOT EXISTS idx_tga_teacher ON teacher_group_access(teacher_user_id, academic_year);
CREATE INDEX IF NOT EXISTS idx_tga_group ON teacher_group_access(group_id, academic_year);
CREATE INDEX IF NOT EXISTS idx_tga_school_shift ON teacher_group_access(school_id, work_shift, academic_year);

COMMENT ON TABLE teacher_group_access IS 'Acceso de docentes a grupos por año electivo. Independiente de schedules (horarios reales).';
COMMENT ON COLUMN teacher_group_access.work_shift IS 'Jornada del grupo al que se asigna. Denormalizada de academic_groups.work_shift para filtros rápidos.';

CREATE TABLE IF NOT EXISTS classrooms (
    classroom_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id      UUID NOT NULL REFERENCES schools(school_id),
    classroom_name VARCHAR(120) NOT NULL,
    building       VARCHAR(120),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS subjects (
    subject_id   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    subject_name VARCHAR(120) NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS schedules (
    schedule_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    group_id         UUID NOT NULL REFERENCES academic_groups(group_id),
    classroom_id     UUID NOT NULL REFERENCES classrooms(classroom_id),
    teacher_user_id  UUID NOT NULL REFERENCES users(user_id),
    subject_id       UUID NOT NULL REFERENCES subjects(subject_id),
    day_of_week      INTEGER NOT NULL,
    block_number     INTEGER NOT NULL,
    start_time       TIME NOT NULL,
    end_time         TIME NOT NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_schedule_group_day_block ON schedules(group_id, day_of_week, block_number);
CREATE INDEX IF NOT EXISTS idx_schedule_teacher_day_block ON schedules(teacher_user_id, day_of_week, block_number);
CREATE INDEX IF NOT EXISTS idx_schedule_classroom ON schedules(classroom_id);
CREATE INDEX IF NOT EXISTS idx_schedule_subject ON schedules(subject_id);

CREATE TABLE IF NOT EXISTS daily_schedule_config (
    config_id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id          UUID NOT NULL REFERENCES schools(school_id),
    group_id           UUID NOT NULL REFERENCES academic_groups(group_id),
    config_date        DATE NOT NULL,
    has_classes        BOOLEAN NOT NULL DEFAULT TRUE,
    expected_entry_time TIME,
    expected_exit_time  TIME,
    created_by_user_id UUID REFERENCES users(user_id),
    metadata_json      JSONB,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(school_id, group_id, config_date)
);

CREATE TABLE IF NOT EXISTS edge_devices (
    device_id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id            UUID NOT NULL REFERENCES schools(school_id),
    classroom_id         UUID REFERENCES classrooms(classroom_id),
    group_id             UUID REFERENCES academic_groups(group_id),
    device_name          VARCHAR(120) NOT NULL,
    public_key           TEXT,
    active               BOOLEAN NOT NULL DEFAULT TRUE,
    configured           BOOLEAN NOT NULL DEFAULT FALSE,
    last_sync_at         TIMESTAMPTZ,
    token_hash           VARCHAR(255),
    last_ping            TIMESTAMPTZ,
    status               VARCHAR(50) DEFAULT 'unknown',
    last_seen_timestamp  TIMESTAMPTZ,
    location             TEXT,
    assigned_user_id     UUID REFERENCES users(user_id),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_edge_devices_school_active ON edge_devices(school_id, active);
CREATE INDEX IF NOT EXISTS idx_edge_devices_classroom ON edge_devices(classroom_id);
CREATE INDEX IF NOT EXISTS idx_edge_devices_group ON edge_devices(group_id);

COMMENT ON COLUMN edge_devices.configured IS 'TRUE cuando el rector ha configurado el sensor con su token (lo ha vinculado físicamente). Distingue de active que indica si el dispositivo está operativo';

-- Tabla de revocación de sensores con countdown de 1 hora
CREATE TABLE IF NOT EXISTS sensor_revocation_requests (
    revocation_id    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    device_id        UUID NOT NULL REFERENCES edge_devices(device_id) ON DELETE CASCADE,
    school_id        UUID NOT NULL REFERENCES schools(school_id),
    requested_by     UUID NOT NULL REFERENCES users(user_id),
    requested_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    executes_at      TIMESTAMPTZ NOT NULL,
    cancelled        BOOLEAN NOT NULL DEFAULT FALSE,
    cancelled_by     UUID REFERENCES users(user_id),
    cancelled_at     TIMESTAMPTZ,
    completed        BOOLEAN NOT NULL DEFAULT FALSE,
    completed_at     TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_revocation_pending ON sensor_revocation_requests(school_id, completed, cancelled);

-- Fallback de comandos a dispositivos edge cuando Redis no está disponible
CREATE TABLE IF NOT EXISTS device_commands (
    command_id    BIGSERIAL PRIMARY KEY,
    device_id     UUID NOT NULL REFERENCES edge_devices(device_id) ON DELETE CASCADE,
    command       TEXT NOT NULL,
    payload       JSONB NOT NULL DEFAULT '{}'::jsonb,
    issued_at     BIGINT NOT NULL,
    issued_by     UUID,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    delivered_at  TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_device_commands_pending
    ON device_commands(device_id, delivered_at)
    WHERE delivered_at IS NULL;

-- =============================================================================
-- TABLAS DE EVENTOS PARTICIONADAS
-- =============================================================================
CREATE TABLE IF NOT EXISTS biometric_events (
    event_id          UUID NOT NULL,
    school_id         UUID NOT NULL,
    student_id        UUID,
    device_id         UUID NOT NULL,
    classroom_id      UUID,
    schedule_id       UUID,
    event_type        VARCHAR(120) NOT NULL,
    event_result      VARCHAR(120) NOT NULL,
    confidence_score  NUMERIC(5,2),
    sync_hash         TEXT,
    event_signature   TEXT,
    event_fingerprint VARCHAR(64),
    event_timestamp   TIMESTAMPTZ NOT NULL,
    metadata_json     JSONB,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(event_id, event_timestamp)
) PARTITION BY RANGE(event_timestamp);

CREATE INDEX IF NOT EXISTS idx_biometric_events_school_type_ts
    ON biometric_events(school_id, event_type, event_timestamp DESC);
CREATE INDEX IF NOT EXISTS idx_biometric_events_school_student_time
    ON biometric_events(school_id, student_id, event_timestamp DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_biometric_events_fingerprint
    ON biometric_events(event_fingerprint, event_timestamp) WHERE event_fingerprint IS NOT NULL;

CREATE TABLE IF NOT EXISTS notifications (
    notification_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id       UUID NOT NULL,
    user_id         UUID NOT NULL,
    title           VARCHAR(200) NOT NULL,
    message         TEXT NOT NULL,
    type            VARCHAR(50) NOT NULL DEFAULT 'INFO',
    metadata_json   JSONB,
    dedup_key       VARCHAR(64),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS uq_notifications_dedup
    ON notifications (dedup_key) WHERE dedup_key IS NOT NULL;

CREATE TABLE IF NOT EXISTS attendance_incidents (
    incident_id       UUID NOT NULL,
    school_id         UUID NOT NULL,
    student_id        UUID NOT NULL,
    related_event_id  UUID,
    group_id          UUID,
    incident_type     VARCHAR(120) NOT NULL,
    detected_at       TIMESTAMPTZ NOT NULL,
    resolved          BOOLEAN NOT NULL DEFAULT FALSE,
    metadata_json     JSONB,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(incident_id, detected_at)
) PARTITION BY RANGE(detected_at);

CREATE INDEX IF NOT EXISTS idx_attendance_incidents_school_type_detected
    ON attendance_incidents(school_id, incident_type, detected_at DESC);
CREATE INDEX IF NOT EXISTS idx_attendance_incidents_group
    ON attendance_incidents(group_id) WHERE group_id IS NOT NULL;
-- VF-011: Índice para detección de duplicados LATE_ARRIVAL por estudiante/día/escuela
-- NOTA: No puede ser UNIQUE en tabla particionada porque PG requiere incluir la
-- columna de partición (detected_at) en el unique constraint, lo que rompería la
-- granularidad por día. El dedup se maneja a nivel aplicación (worker_biometric.php
-- usa ON CONFLICT DO NOTHING en la partición específica, o SELECT FOR UPDATE).
-- El índice acelera la verificación de duplicados.
-- fn_bogota_date se define más adelante en la sección de funciones helper.
-- Si se ejecuta este archivo por partes, crear fn_bogota_date primero.

CREATE TABLE IF NOT EXISTS internal_messages (
    message_id      UUID NOT NULL,
    school_id       UUID NOT NULL,
    sender_user_id  UUID NOT NULL,
    receiver_user_id UUID NOT NULL,
    subject         VARCHAR(255),
    message_content TEXT NOT NULL,
    sent_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    read_at         TIMESTAMPTZ,
    metadata_json   JSONB,
    PRIMARY KEY(message_id, sent_at)
) PARTITION BY RANGE(sent_at);
CREATE INDEX IF NOT EXISTS idx_internal_messages_sent_at ON internal_messages(sent_at DESC);

CREATE TABLE IF NOT EXISTS twilio_message_types (
    type_code    VARCHAR(100) PRIMARY KEY,
    description  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS twilio_messages (
    twilio_message_id    UUID NOT NULL DEFAULT uuid_generate_v4(),
    school_id            UUID NOT NULL,
    student_id           UUID,
    guardian_id          UUID,
    sender_user_id       UUID,
    type_code            VARCHAR(100) NOT NULL,
    direction            VARCHAR(20) NOT NULL,
    phone_number         VARCHAR(30) NOT NULL,
    message_content      TEXT NOT NULL,
    provider_message_sid VARCHAR(255),
    delivery_status      VARCHAR(100),
    sent_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    received_at          TIMESTAMPTZ,
    metadata_json        JSONB,
    PRIMARY KEY(twilio_message_id, sent_at)
) PARTITION BY RANGE(sent_at);
CREATE INDEX IF NOT EXISTS idx_twilio_messages_school_sent ON twilio_messages(school_id, sent_at DESC);

CREATE TABLE IF NOT EXISTS user_commands (
    command_id          UUID NOT NULL DEFAULT uuid_generate_v4(),
    school_id           UUID NOT NULL,
    executed_by_user_id UUID NOT NULL,
    command_type        VARCHAR(120) NOT NULL,
    target_entity_type  VARCHAR(120),
    target_entity_id    UUID,
    command_payload     JSONB,
    executed_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    metadata_json       JSONB,
    PRIMARY KEY(command_id, executed_at)
) PARTITION BY RANGE(executed_at);
CREATE INDEX IF NOT EXISTS idx_user_commands_school_exec ON user_commands(school_id, executed_at DESC);

CREATE TABLE IF NOT EXISTS sos_alerts (
    alert_id           UUID NOT NULL,
    school_id          UUID NOT NULL,
    emitted_by_user_id UUID NOT NULL,
    classroom_id       UUID,
    alert_type         VARCHAR(120) NOT NULL,
    alert_description  TEXT,
    resolved           BOOLEAN NOT NULL DEFAULT FALSE,
    resolved_by_user_id UUID,
    emitted_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at        TIMESTAMPTZ,
    metadata_json      JSONB,
    PRIMARY KEY(alert_id, emitted_at)
) PARTITION BY RANGE(emitted_at);
CREATE INDEX IF NOT EXISTS idx_sos_alerts_school_resolved_emitted ON sos_alerts(school_id, resolved, emitted_at DESC);

CREATE TABLE IF NOT EXISTS security_incidents (
    incident_id        UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id          UUID NOT NULL,
    related_student_id UUID,
    related_user_id    UUID,
    incident_type      VARCHAR(120) NOT NULL,
    severity_level     VARCHAR(50),
    description        TEXT,
    detected_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved           BOOLEAN NOT NULL DEFAULT FALSE,
    resolved_at        TIMESTAMPTZ,
    metadata_json      JSONB
);
CREATE INDEX IF NOT EXISTS idx_security_incidents_school_detected ON security_incidents(school_id, detected_at DESC, resolved);

CREATE TABLE IF NOT EXISTS school_exit_authorizations (
    authorization_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id             UUID NOT NULL,
    student_id            UUID NOT NULL,
    authorized_by_user_id UUID NOT NULL,
    authorization_reason  TEXT,
    exit_time             TIMESTAMPTZ NOT NULL,
    expected_return_time  TIMESTAMPTZ,
    actual_return_time    TIMESTAMPTZ,
    status                VARCHAR(100) NOT NULL,
    metadata_json         JSONB,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS class_exit_authorizations (
    authorization_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id             UUID NOT NULL,
    student_id            UUID NOT NULL,
    authorized_by_user_id UUID NOT NULL,
    schedule_id           UUID,
    authorization_reason  TEXT,
    exit_time             TIMESTAMPTZ NOT NULL,
    return_time           TIMESTAMPTZ,
    status                VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
    metadata_json         JSONB,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_class_exit_auth_status ON class_exit_authorizations(school_id, student_id, status, exit_time);

COMMENT ON COLUMN class_exit_authorizations.status IS 'ACTIVE = permiso vigente, EXPIRED = expiró sin retorno, COMPLETED = estudiante regresó, CANCELLED = cancelado';

CREATE TABLE IF NOT EXISTS pedagogical_trip_authorizations (
    authorization_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id             UUID NOT NULL,
    student_id            UUID NOT NULL,
    authorized_by_user_id UUID NOT NULL,
    destination           TEXT NOT NULL,
    departure_time        TIMESTAMPTZ NOT NULL,
    return_time           TIMESTAMPTZ,
    purpose               TEXT,
    metadata_json         JSONB,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS student_record_audit (
    audit_id            UUID NOT NULL,
    school_id           UUID NOT NULL,
    student_id          UUID,
    performed_by_user_id UUID NOT NULL,
    action_type         VARCHAR(120) NOT NULL,
    previous_data       JSONB,
    new_data            JSONB,
    performed_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    metadata_json       JSONB,
    PRIMARY KEY(audit_id, performed_at)
) PARTITION BY RANGE(performed_at);
CREATE INDEX IF NOT EXISTS idx_student_audit_school_performed ON student_record_audit(school_id, performed_at DESC);

CREATE TABLE IF NOT EXISTS global_audit_logs (
    log_id              UUID NOT NULL,
    school_id           UUID,
    performed_by_user_id UUID,
    action_type         VARCHAR(120) NOT NULL,
    entity_type         VARCHAR(120),
    entity_id           UUID,
    action_details      JSONB,
    ip_address          INET,
    user_agent          TEXT,
    chain_hash          TEXT,
    prev_audit_id       UUID,
    description         TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(log_id, created_at)
) PARTITION BY RANGE(created_at);
CREATE INDEX IF NOT EXISTS idx_global_audit_school_created ON global_audit_logs(school_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_user ON global_audit_logs(performed_by_user_id);

CREATE TABLE IF NOT EXISTS report_exports (
    report_export_id    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id           UUID NOT NULL,
    generated_by_user_id UUID NOT NULL,
    report_type         VARCHAR(120) NOT NULL,
    file_format         VARCHAR(50) NOT NULL,
    storage_path        TEXT,
    generated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    metadata_json       JSONB
);
CREATE INDEX IF NOT EXISTS idx_report_exports_school_generated ON report_exports(school_id, generated_at DESC);

CREATE TABLE IF NOT EXISTS student_behavior_metrics (
    metric_id               UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id               UUID NOT NULL REFERENCES schools(school_id),
    student_id              UUID NOT NULL REFERENCES students(student_id),
    calculated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    late_count              INTEGER NOT NULL DEFAULT 0,
    absence_count           INTEGER NOT NULL DEFAULT 0,
    total_events            INTEGER NOT NULL DEFAULT 0,
    risk_score              NUMERIC(5,2) NOT NULL DEFAULT 0.00,
    risk_level              VARCHAR(20) CHECK(risk_level IN('LOW','MEDIUM','HIGH','CRITICAL')),
    calculation_window_days INTEGER NOT NULL DEFAULT 30,
    metadata_json           JSONB,
    CONSTRAINT uq_behavior_student_window UNIQUE(student_id, calculation_window_days)
);
CREATE INDEX IF NOT EXISTS idx_behavior_risk_score ON student_behavior_metrics(school_id, risk_level, calculated_at DESC);
CREATE INDEX IF NOT EXISTS idx_behavior_student ON student_behavior_metrics(student_id, calculated_at DESC);

-- =============================================================================
-- MOTOR DE ANÁLISIS DE RIESGO PEDAGÓGICO v3.0
-- =============================================================================
-- Catálogo universal de tipos de evento (sin school_id — catálogo global)
CREATE TABLE IF NOT EXISTS risk_event_types (
    event_type_id     SERIAL PRIMARY KEY,
    type_code         VARCHAR(120) NOT NULL UNIQUE,
    display_name      VARCHAR(200) NOT NULL,
    description       TEXT,
    category          VARCHAR(50) NOT NULL DEFAULT 'general',
    is_system         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO risk_event_types (type_code, display_name, description, category) VALUES
    ('LATE_ARRIVAL',        'Llegada tarde',              'Estudiante llega después de la hora de entrada + tolerancia', 'asistencia'),
    ('INASISTENCIA',        'Inasistencia',               'Estudiante no asiste a clases sin justificación', 'asistencia'),
    ('UNAUTHORIZED_ABSENCE','Ausencia no autorizada',      'Ausencia detectada sin permiso registrado', 'asistencia'),
    ('EVASION_INTERNA',     'Evasión interna',             'Estudiante no entra a clase estando en el colegio', 'evasion'),
    ('SALIDA_BAÑO',         'Salida al baño',              'Salida al baño durante clase', 'comportamiento'),
    ('SALIDA_NO_AUTORIZADA','Salida no autorizada',         'Estudiante sale del perímetro escolar sin autorización', 'evasion'),
    ('PERMISO',             'Permiso justificado',          'Ausencia o salida con permiso previo', 'administrativo'),
    ('RISK_ALERT_LEVE',     'Alerta de riesgo LEVE',        'Alerta generada por motor de riesgo', 'sistema'),
    ('RISK_ALERT_MODERADA', 'Alerta de riesgo MODERADA',    'Alerta generada por motor de riesgo', 'sistema'),
    ('RISK_ALERT_ALTA',     'Alerta de riesgo ALTA',        'Alerta generada por motor de riesgo', 'sistema'),
    ('RISK_ALERT_MUY_ALTA', 'Alerta de riesgo MUY_ALTA',    'Alerta generada por motor de riesgo', 'sistema')
ON CONFLICT (type_code) DO UPDATE SET
    display_name = EXCLUDED.display_name,
    description  = EXCLUDED.description,
    category     = EXCLUDED.category;

-- Calendario lectivo institucional (para cálculo de días lectivos en decaimiento)
CREATE TABLE IF NOT EXISTS school_calendar (
    calendar_id     UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id       UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    calendar_date   DATE NOT NULL,
    is_lecture_day  BOOLEAN NOT NULL DEFAULT TRUE,
    reason          VARCHAR(200),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(school_id, calendar_date)
);
CREATE INDEX IF NOT EXISTS idx_school_calendar_lookup
    ON school_calendar(school_id, calendar_date);

-- Políticas institucionales versionadas (cada cambio crea nueva versión)
CREATE TABLE IF NOT EXISTS risk_policies (
    policy_id       UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id       UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    version         INTEGER NOT NULL DEFAULT 1,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    activated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deactivated_at  TIMESTAMPTZ,
    snapshot_json   JSONB NOT NULL,
    created_by      UUID NOT NULL REFERENCES users(user_id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    change_reason   TEXT NOT NULL,
    UNIQUE(school_id, version)
);
CREATE INDEX IF NOT EXISTS idx_risk_policies_school_active
    ON risk_policies(school_id, is_active);

-- Mapeo evento → nivel (por política, con override individual por estudiante)
CREATE TABLE IF NOT EXISTS risk_event_level_mapping (
    mapping_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    policy_id       UUID NOT NULL REFERENCES risk_policies(policy_id) ON DELETE CASCADE,
    school_id       UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    event_type_id   INTEGER NOT NULL REFERENCES risk_event_types(event_type_id),
    risk_level      VARCHAR(20) NOT NULL CHECK(risk_level IN (
                        'SIN_IMPORTANCIA','LEVE','MODERADA','ALTA','MUY_ALTA'
                    )),
    student_id      UUID REFERENCES students(student_id) ON DELETE CASCADE,
    override_reason TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(policy_id, event_type_id, student_id)
);
CREATE INDEX IF NOT EXISTS idx_risk_mapping_policy_event
    ON risk_event_level_mapping(policy_id, event_type_id);
CREATE INDEX IF NOT EXISTS idx_risk_mapping_student
    ON risk_event_level_mapping(student_id)
    WHERE student_id IS NOT NULL;

-- Reglas por nivel (pesos, vidas medias, umbrales, cooldowns, rangos protegidos)
CREATE TABLE IF NOT EXISTS risk_rules (
    rule_id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    policy_id           UUID NOT NULL REFERENCES risk_policies(policy_id) ON DELETE CASCADE,
    school_id           UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    risk_level          VARCHAR(20) NOT NULL CHECK(risk_level IN (
                            'LEVE','MODERADA','ALTA','MUY_ALTA'
                        )),
    weight_base         NUMERIC(5,2) NOT NULL,
    half_life_days      INTEGER NOT NULL,
    activation_threshold NUMERIC(8,2) NOT NULL,
    cooldown_days       INTEGER NOT NULL DEFAULT 0,
    single_occurrence   BOOLEAN NOT NULL DEFAULT FALSE,
    requires_human_review BOOLEAN NOT NULL DEFAULT FALSE,
    min_weight          NUMERIC(5,2) NOT NULL,
    max_weight          NUMERIC(5,2) NOT NULL,
    min_half_life       INTEGER NOT NULL,
    max_half_life       INTEGER NOT NULL,
    min_threshold       NUMERIC(8,2) NOT NULL,
    max_threshold       NUMERIC(8,2) NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(policy_id, risk_level)
);

-- Reglas de combinación entre categorías (Capa 4)
CREATE TABLE IF NOT EXISTS risk_combination_rules (
    combo_rule_id    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    policy_id        UUID NOT NULL REFERENCES risk_policies(policy_id) ON DELETE CASCADE,
    school_id        UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    rule_name        VARCHAR(200) NOT NULL,
    condition_json   JSONB NOT NULL,
    result_level     VARCHAR(20) NOT NULL CHECK(result_level IN (
                         'MODERADA','ALTA','MUY_ALTA'
                     )),
    result_reason    TEXT NOT NULL,
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_risk_combo_policy
    ON risk_combination_rules(policy_id, is_active);

-- Snapshot de riesgo activo por estudiante + categoría (vista derivada)
CREATE TABLE IF NOT EXISTS risk_active_snapshot (
    snapshot_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id        UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    student_id       UUID NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    category         VARCHAR(50) NOT NULL,
    active_score     NUMERIC(8,2) NOT NULL DEFAULT 0.00,
    event_count      INTEGER NOT NULL DEFAULT 0,
    clustering_factor NUMERIC(5,2) NOT NULL DEFAULT 1.00,
    last_event_at    TIMESTAMPTZ,
    last_calculated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    policy_id        UUID REFERENCES risk_policies(policy_id),
    metadata_json    JSONB,
    UNIQUE(school_id, student_id, category)
);
CREATE INDEX IF NOT EXISTS idx_risk_snapshot_school_score
    ON risk_active_snapshot(school_id, active_score DESC);
CREATE INDEX IF NOT EXISTS idx_risk_snapshot_student
    ON risk_active_snapshot(student_id, category);

-- Alertas pedagógicas con máquina de estados
CREATE TABLE IF NOT EXISTS risk_alerts (
    alert_id         UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id        UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    student_id       UUID NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    alert_level      VARCHAR(20) NOT NULL CHECK(alert_level IN (
                         'LEVE','MODERADA','ALTA','MUY_ALTA'
                     )),
    escalation_state VARCHAR(50) NOT NULL DEFAULT 'ALERTA_PEDAGOGICA' CHECK(escalation_state IN (
                         'OBSERVACION','ALERTA_PEDAGOGICA','SEGUIMIENTO',
                         'INTERVENCION_PRIORITARIA','ATENCION_INMEDIATA'
                     )),
    trigger_category VARCHAR(50),
    trigger_rule     TEXT,
    trigger_score    NUMERIC(8,2),
    combo_rule_id    UUID REFERENCES risk_combination_rules(combo_rule_id),
    policy_id        UUID NOT NULL REFERENCES risk_policies(policy_id),
    involved_events  JSONB,
    status           VARCHAR(20) NOT NULL DEFAULT 'abierta' CHECK(status IN (
                         'abierta','en_seguimiento','resuelta','descartada'
                     )),
    cooldown_until   TIMESTAMPTZ,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at      TIMESTAMPTZ,
    resolved_by      UUID REFERENCES users(user_id),
    resolution_notes TEXT,
    metadata_json    JSONB
);
CREATE INDEX IF NOT EXISTS idx_risk_alerts_school_status
    ON risk_alerts(school_id, status, alert_level);
CREATE INDEX IF NOT EXISTS idx_risk_alerts_student
    ON risk_alerts(student_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_risk_alerts_cooldown
    ON risk_alerts(student_id, alert_level, cooldown_until)
    WHERE status = 'abierta';

-- Justificaciones de eventos (evento justificado no suma al riesgo activo)
CREATE TABLE IF NOT EXISTS risk_justifications (
    justification_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id        UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    student_id       UUID NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    incident_type    VARCHAR(120) NOT NULL,
    incident_date    TIMESTAMPTZ NOT NULL,
    justified_by     UUID NOT NULL REFERENCES users(user_id),
    justified_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    justification_type VARCHAR(50) NOT NULL CHECK(justification_type IN (
                         'permiso','error_sensor','horario','medico','otro'
                     )),
    reason           TEXT NOT NULL,
    recalculated     BOOLEAN NOT NULL DEFAULT FALSE,
    metadata_json    JSONB
);
CREATE INDEX IF NOT EXISTS idx_risk_justifications_student
    ON risk_justifications(student_id, incident_date DESC);

-- Audit log de cambios de configuración de políticas
CREATE TABLE IF NOT EXISTS risk_audit_log (
    audit_id         UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id        UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    actor_id         UUID NOT NULL REFERENCES users(user_id),
    actor_role       VARCHAR(50),
    entity_modified  VARCHAR(100) NOT NULL,
    entity_id        UUID,
    action           VARCHAR(50) NOT NULL,
    previous_config  JSONB,
    new_config       JSONB,
    change_reason    TEXT,
    policy_version   INTEGER,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_risk_audit_school_entity
    ON risk_audit_log(school_id, entity_modified, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_risk_audit_actor
    ON risk_audit_log(actor_id, created_at DESC);

CREATE TABLE IF NOT EXISTS student_tracking (
    tracking_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id   UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    student_id  UUID NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    status      VARCHAR(50) NOT NULL DEFAULT 'en proceso',
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT (NOW() AT TIME ZONE 'America/Bogota'),
    updated_at  TIMESTAMP WITH TIME ZONE DEFAULT (NOW() AT TIME ZONE 'America/Bogota')
);
CREATE INDEX IF NOT EXISTS idx_tracking_school_status ON student_tracking(school_id, status);

CREATE TABLE IF NOT EXISTS student_tracking_notes (
    note_id     UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tracking_id UUID NOT NULL REFERENCES student_tracking(tracking_id) ON DELETE CASCADE,
    user_id     UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    note_text   TEXT NOT NULL,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT (NOW() AT TIME ZONE 'America/Bogota')
);
CREATE INDEX IF NOT EXISTS idx_tracking_notes_tid ON student_tracking_notes(tracking_id);

CREATE TABLE IF NOT EXISTS school_panic_events (
    panic_event_id       UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id            UUID NOT NULL REFERENCES schools(school_id),
    triggered_by_user_id UUID NOT NULL REFERENCES users(user_id),
    triggered_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    devices_deactivated  INTEGER NOT NULL DEFAULT 0,
    metadata_json        JSONB,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_school_panic_events_school_triggered ON school_panic_events(school_id, triggered_at DESC);

-- =============================================================================
-- TABLAS DE SISTEMA
-- =============================================================================
CREATE TABLE IF NOT EXISTS system_telemetry (
    id          BIGSERIAL PRIMARY KEY,
    session_id  UUID NOT NULL,
    app_version TEXT NOT NULL DEFAULT 'unknown',
    platform    TEXT NOT NULL DEFAULT 'web' CHECK (platform IN ('web', 'desktop', 'android', 'ios')),
    event_type  TEXT NOT NULL CHECK (event_type IN ('JS_ERROR', 'API_LATENCY', 'BIOMETRIC_LATENCY', 'APP_PING', 'RENDER_SLOW')),
    severity    TEXT NOT NULL DEFAULT 'info' CHECK (severity IN ('debug', 'info', 'warn', 'error')),
    payload     JSONB NOT NULL DEFAULT '{}',
    user_agent  TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_telemetry_created_at ON system_telemetry (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_telemetry_event_type ON system_telemetry (event_type);
CREATE INDEX IF NOT EXISTS idx_telemetry_severity ON system_telemetry (severity) WHERE severity IN ('warn', 'error');
CREATE INDEX IF NOT EXISTS idx_telemetry_session ON system_telemetry (session_id);
CREATE INDEX IF NOT EXISTS idx_telemetry_payload_gin ON system_telemetry USING GIN (payload);

CREATE TABLE IF NOT EXISTS contact_leads (
    lead_id     UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nombre      VARCHAR(200) NOT NULL,
    cargo       VARCHAR(100) NOT NULL,
    institucion VARCHAR(300) NOT NULL,
    municipio   VARCHAR(200) NOT NULL,
    email       VARCHAR(254) NOT NULL,
    whatsapp    VARCHAR(30)  NOT NULL,
    mensaje     TEXT,
    ip_address  VARCHAR(45),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_contact_leads_email ON contact_leads(email);
CREATE INDEX IF NOT EXISTS idx_contact_leads_created_at ON contact_leads(created_at);

CREATE TABLE IF NOT EXISTS rate_limits (
    rl_key      TEXT PRIMARY KEY,
    window_start TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    hits        INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_rate_limits_window_start ON rate_limits(window_start);

CREATE TABLE IF NOT EXISTS jwt_blocklist (
    jti        TEXT PRIMARY KEY,
    revoked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_jwt_blocklist_expires_at ON jwt_blocklist(expires_at);

CREATE TABLE IF NOT EXISTS verification_codes (
    code_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id      UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    purpose      VARCHAR(50) NOT NULL,
    target_value TEXT NOT NULL,
    code         VARCHAR(10) NOT NULL,
    attempts     INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    used         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at   TIMESTAMPTZ NOT NULL,
    verified_at  TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_verification_codes_user_purpose ON verification_codes(user_id, purpose, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_verification_codes_active ON verification_codes(code, expires_at) WHERE used = FALSE;

-- =============================================================================
-- ONBOARDING DE HORARIOS INSTITUCIONALES
-- =============================================================================
CREATE TABLE IF NOT EXISTS school_schedule_config (
    school_id               UUID NOT NULL REFERENCES schools(school_id),
    rotates_classrooms      BOOLEAN NOT NULL DEFAULT FALSE,
    work_shift              VARCHAR(50) NOT NULL DEFAULT 'mañana',
    entry_time              TIME,
    exit_time               TIME,
    recess_start_time       TIME,
    recess_end_time         TIME,
    onboarding_completed    BOOLEAN NOT NULL DEFAULT FALSE,
    onboarding_completed_by UUID REFERENCES users(user_id),
    onboarding_completed_at TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (school_id, work_shift)
);

COMMENT ON TABLE school_schedule_config IS 'Configuración de horarios por jornada. Una fila por (school_id, work_shift). Creada durante el onboarding obligatorio.';
COMMENT ON COLUMN school_schedule_config.rotates_classrooms IS 'TRUE = colegio rota de salones. FALSE = colegio no rota.';
COMMENT ON COLUMN school_schedule_config.work_shift IS 'Jornada: mañana, tarde, noche, completa. Una fila por jornada.';

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

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'school_time_blocks_school_workshift_block_key') THEN
        ALTER TABLE school_time_blocks ADD CONSTRAINT school_time_blocks_school_workshift_block_key UNIQUE(school_id, work_shift, block_number);
    END IF;
END $$;
CREATE INDEX IF NOT EXISTS idx_school_time_blocks_shift ON school_time_blocks(school_id, work_shift, block_number);

-- =============================================================================
-- FUNCIONES HELPER (RLS)
-- =============================================================================
-- VF-011: Función IMMUTABLE para extraer fecha en zona horaria Bogotá
-- Necesaria para índices que usen conversión de TIMESTAMPTZ a DATE
CREATE OR REPLACE FUNCTION fn_bogota_date(t TIMESTAMPTZ) RETURNS DATE AS $$
    SELECT (t AT TIME ZONE 'America/Bogota')::date;
$$ LANGUAGE SQL IMMUTABLE;

-- Índice para dedup de LATE_ARRIVAL (no unique por limitación de tablas particionadas)
CREATE INDEX IF NOT EXISTS idx_late_arrival_per_day
    ON attendance_incidents (student_id, school_id, fn_bogota_date(detected_at))
    WHERE incident_type = 'LATE_ARRIVAL';

CREATE OR REPLACE FUNCTION get_current_school_id()
RETURNS UUID AS $$
DECLARE v_school_id TEXT;
BEGIN
    v_school_id := current_setting('app.current_school_id', true);
    IF v_school_id IS NULL OR v_school_id = '' THEN RETURN NULL; END IF;
    RETURN v_school_id::UUID;
EXCEPTION WHEN OTHERS THEN RETURN NULL;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

CREATE OR REPLACE FUNCTION get_current_role()
RETURNS TEXT AS $$
DECLARE v_role TEXT;
BEGIN
    v_role := current_setting('app.current_role', true);
    IF v_role IS NULL OR v_role = '' THEN RETURN NULL; END IF;
    RETURN v_role;
EXCEPTION WHEN OTHERS THEN RETURN NULL;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

-- =============================================================================
-- FUNCIONES DE NEGOCIO
-- =============================================================================

-- Phone normalization trigger
CREATE OR REPLACE FUNCTION fn_guardians_normalize_phone()
RETURNS TRIGGER AS $$
BEGIN
    NEW.whatsapp_phone_normalized := regexp_replace(COALESCE(NEW.whatsapp_phone,''),'[^0-9+]','','g');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_guardians_normalize_phone ON guardians;
CREATE TRIGGER trg_guardians_normalize_phone
    BEFORE INSERT OR UPDATE OF whatsapp_phone ON guardians
    FOR EACH ROW EXECUTE FUNCTION fn_guardians_normalize_phone();

-- Audit chain: HMAC-SHA256 hash function (VF-003: sin fallback a default)
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

-- Audit chain trigger
CREATE OR REPLACE FUNCTION fn_audit_chain_trigger()
RETURNS TRIGGER AS $$
DECLARE
    v_prev_hash TEXT;
    v_prev_id   UUID;
BEGIN
    SELECT log_id, chain_hash INTO v_prev_id, v_prev_hash
    FROM global_audit_logs
    WHERE (school_id IS NOT DISTINCT FROM NEW.school_id)
    ORDER BY created_at DESC, log_id DESC LIMIT 1 FOR UPDATE;
    NEW.prev_audit_id := v_prev_id;
    NEW.chain_hash := fn_calculate_audit_hash(
        v_prev_hash, NEW.school_id, NEW.performed_by_user_id,
        NEW.action_type, NEW.action_details::TEXT,
        NEW.ip_address::TEXT, NEW.created_at
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_audit_chain ON global_audit_logs;
CREATE TRIGGER trg_audit_chain BEFORE INSERT ON global_audit_logs
    FOR EACH ROW EXECUTE FUNCTION fn_audit_chain_trigger();

-- Audit chain validation
CREATE OR REPLACE FUNCTION fn_validate_audit_chain(p_school_id UUID DEFAULT NULL)
RETURNS JSONB AS $$
DECLARE
    v_expected_hash TEXT;
    v_prev_hash     TEXT;
    v_record        RECORD;
    v_broken_at     UUID := NULL;
    v_count         INTEGER := 0;
    v_valid_count   INTEGER := 0;
BEGIN
    v_prev_hash := NULL;
    FOR v_record IN
        SELECT log_id, school_id, performed_by_user_id, action_type,
               action_details, ip_address, created_at, chain_hash, prev_audit_id
        FROM global_audit_logs
        WHERE (p_school_id IS NULL OR school_id = p_school_id)
        ORDER BY created_at ASC, log_id ASC
    LOOP
        v_count := v_count + 1;
        v_expected_hash := fn_calculate_audit_hash(
            v_prev_hash, v_record.school_id, v_record.performed_by_user_id,
            v_record.action_type, v_record.action_details::TEXT,
            v_record.ip_address::TEXT, v_record.created_at
        );
        IF v_record.chain_hash = v_expected_hash THEN
            v_valid_count := v_valid_count + 1;
        ELSE
            v_broken_at := v_record.log_id;
            EXIT;
        END IF;
        v_prev_hash := v_record.chain_hash;
    END LOOP;
    IF v_broken_at IS NOT NULL THEN
        RETURN jsonb_build_object('status','compromised','broken_at_audit_id',v_broken_at,
            'total_checked',v_count,'valid_up_to',v_valid_count - 1);
    ELSE
        RETURN jsonb_build_object('status','ok','total_records',v_count,'school_id',p_school_id);
    END IF;
END;
$$ LANGUAGE plpgsql;

-- Risk score calculation (VF-030: incluye evasiones)
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
        jsonb_build_object('threshold', v_threshold, 'window_days', p_window_days, 'evasion_count', v_evasion_count)
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
-- fn_recalculate_school_metrics — versión con evasiones (VF-030)
-- =============================================================================
CREATE OR REPLACE FUNCTION fn_recalculate_school_metrics(p_school_id UUID)
RETURNS INTEGER AS $$
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
        COALESCE(bev_stat.total_events, 0),
        LEAST(100.00,
            COALESCE(be.late_count, 0) * 5.0
            + COALESCE(be.absence_count, 0) * 15.0
            + COALESCE(ev_stat.evasion_count, 0) * 10.0
            + GREATEST(0, (COALESCE(bev_stat.total_events, 0) - 20) * 0.5)
        ),
        CASE
            WHEN LEAST(100.00,
                COALESCE(be.late_count, 0) * 5.0
                + COALESCE(be.absence_count, 0) * 15.0
                + COALESCE(ev_stat.evasion_count, 0) * 10.0
                + GREATEST(0, (COALESCE(bev_stat.total_events, 0) - 20) * 0.5)
            ) >= 80 THEN 'CRITICAL'
            WHEN LEAST(100.00,
                COALESCE(be.late_count, 0) * 5.0
                + COALESCE(be.absence_count, 0) * 15.0
                + COALESCE(ev_stat.evasion_count, 0) * 10.0
                + GREATEST(0, (COALESCE(bev_stat.total_events, 0) - 20) * 0.5)
            ) >= 60 THEN 'HIGH'
            WHEN LEAST(100.00,
                COALESCE(be.late_count, 0) * 5.0
                + COALESCE(be.absence_count, 0) * 15.0
                + COALESCE(ev_stat.evasion_count, 0) * 10.0
                + GREATEST(0, (COALESCE(bev_stat.total_events, 0) - 20) * 0.5)
            ) >= 30 THEN 'MEDIUM'
            ELSE 'LOW'
        END,
        30,
        jsonb_build_object('recalculated_at', NOW(), 'evasion_count', COALESCE(ev_stat.evasion_count, 0))
    FROM students s
    LEFT JOIN(
        SELECT s2.student_id,
               COALESCE(ai.late_count, 0) AS late_count,
               COALESCE(ai2.absence_count, 0) AS absence_count
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
    ) be ON be.student_id = s.student_id
    LEFT JOIN (
        SELECT student_id, COUNT(*) AS total_events
        FROM biometric_events
        WHERE event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days'
        GROUP BY student_id
    ) bev_stat ON bev_stat.student_id = s.student_id
    LEFT JOIN (
        SELECT student_id, COUNT(*) AS evasion_count
        FROM attendance_incidents
        WHERE incident_type = 'EVASION_INTERNA'
          AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days'
        GROUP BY student_id
    ) ev_stat ON ev_stat.student_id = s.student_id
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
-- FUNCIONES DE ONBOARDING / EVASIÓN
-- =============================================================================
CREATE OR REPLACE FUNCTION is_student_present_today(
    p_school_id  UUID,
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
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

CREATE OR REPLACE FUNCTION has_active_permiso(
    p_school_id  UUID,
    p_student_id UUID
) RETURNS BOOLEAN AS $$
DECLARE
    v_count INTEGER;
    v_now   TIMESTAMPTZ;
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
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

CREATE OR REPLACE FUNCTION get_active_permiso_info(
    p_school_id  UUID,
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
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

-- =============================================================================
-- GUARDIAN LIMIT: máximo 3 acudientes por estudiante
-- =============================================================================
CREATE OR REPLACE FUNCTION fn_check_guardian_limit()
RETURNS TRIGGER AS $$
DECLARE
    current_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO current_count
    FROM guardian_student_relationships
    WHERE student_id = NEW.student_id;
    IF TG_OP = 'UPDATE' THEN
        current_count := current_count - 1;
    END IF;
    IF current_count >= 3 THEN
        RAISE EXCEPTION 'Un estudiante no puede tener más de 3 acudientes asignados.';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SET search_path = public, pg_temp;

DROP TRIGGER IF EXISTS trg_guardian_limit ON guardian_student_relationships;
CREATE TRIGGER trg_guardian_limit
    BEFORE INSERT OR UPDATE ON guardian_student_relationships
    FOR EACH ROW
    EXECUTE FUNCTION fn_check_guardian_limit();

-- =============================================================================
-- RISK ENGINE v3.0 — Funciones de cálculo
-- =============================================================================

-- Cuenta días lectivos entre dos fechas (fallback: Lun-Vie si no hay calendario)
CREATE OR REPLACE FUNCTION fn_count_lecture_days(
    p_school_id UUID,
    p_from_date DATE,
    p_to_date   DATE
) RETURNS INTEGER AS $$
DECLARE
    v_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO v_count
    FROM school_calendar
    WHERE school_id = p_school_id
      AND calendar_date BETWEEN p_from_date AND p_to_date
      AND is_lecture_day = TRUE;
    IF v_count = 0 THEN
        SELECT COUNT(*) INTO v_count
        FROM generate_series(p_from_date, p_to_date, '1 day'::interval) AS d
        WHERE EXTRACT(ISODOW FROM d) BETWEEN 1 AND 5;
    END IF;
    RETURN v_count;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Seed de política default para una escuela
CREATE OR REPLACE FUNCTION fn_seed_default_risk_policy(p_school_id UUID, p_created_by UUID)
RETURNS UUID AS $$
DECLARE
    v_policy_id UUID;
    v_snapshot JSONB;
BEGIN
    INSERT INTO risk_policies (school_id, version, is_active, snapshot_json, created_by, change_reason)
    VALUES (p_school_id, 1, TRUE,
            jsonb_build_object('engine_version', '3.0', 'seed', true),
            p_created_by, 'Política inicial generada automáticamente por el sistema')
    RETURNING policy_id INTO v_policy_id;

    INSERT INTO risk_rules (policy_id, school_id, risk_level,
        weight_base, half_life_days, activation_threshold, cooldown_days,
        single_occurrence, requires_human_review,
        min_weight, max_weight, min_half_life, max_half_life,
        min_threshold, max_threshold)
    VALUES (v_policy_id, p_school_id, 'LEVE',
        1.0, 7, 4.0, 5, FALSE, FALSE,
        0.5, 2.0, 3, 14, 2.0, 8.0);
    INSERT INTO risk_rules (policy_id, school_id, risk_level,
        weight_base, half_life_days, activation_threshold, cooldown_days,
        single_occurrence, requires_human_review,
        min_weight, max_weight, min_half_life, max_half_life,
        min_threshold, max_threshold)
    VALUES (v_policy_id, p_school_id, 'MODERADA',
        3.0, 10, 9.0, 7, FALSE, FALSE,
        2.0, 5.0, 5, 21, 5.0, 15.0);
    INSERT INTO risk_rules (policy_id, school_id, risk_level,
        weight_base, half_life_days, activation_threshold, cooldown_days,
        single_occurrence, requires_human_review,
        min_weight, max_weight, min_half_life, max_half_life,
        min_threshold, max_threshold)
    VALUES (v_policy_id, p_school_id, 'ALTA',
        6.0, 15, 12.0, 3, FALSE, TRUE,
        4.0, 8.0, 7, 30, 8.0, 20.0);
    INSERT INTO risk_rules (policy_id, school_id, risk_level,
        weight_base, half_life_days, activation_threshold, cooldown_days,
        single_occurrence, requires_human_review,
        min_weight, max_weight, min_half_life, max_half_life,
        min_threshold, max_threshold)
    VALUES (v_policy_id, p_school_id, 'MUY_ALTA',
        10.0, 9999, 10.0, 0, TRUE, FALSE,
        8.0, 15.0, 9999, 9999, 10.0, 10.0);

    INSERT INTO risk_event_level_mapping (policy_id, school_id, event_type_id, risk_level)
    SELECT v_policy_id, p_school_id, event_type_id,
        CASE type_code
            WHEN 'LATE_ARRIVAL'         THEN 'LEVE'
            WHEN 'INASISTENCIA'          THEN 'MODERADA'
            WHEN 'UNAUTHORIZED_ABSENCE'  THEN 'MODERADA'
            WHEN 'EVASION_INTERNA'       THEN 'MODERADA'
            WHEN 'SALIDA_BAÑO'           THEN 'SIN_IMPORTANCIA'
            WHEN 'SALIDA_NO_AUTORIZADA'  THEN 'MUY_ALTA'
            WHEN 'PERMISO'               THEN 'SIN_IMPORTANCIA'
            ELSE 'SIN_IMPORTANCIA'
        END
    FROM risk_event_types
    WHERE is_system = TRUE;

    INSERT INTO risk_combination_rules (policy_id, school_id, rule_name,
        condition_json, result_level, result_reason, is_active)
    VALUES (v_policy_id, p_school_id,
        'Evasión + Inasistencia simultánea',
        jsonb_build_object(
            'categories', jsonb_build_array(
                jsonb_build_object('category', 'evasion', 'min_level', 'MODERADA'),
                jsonb_build_object('category', 'asistencia', 'min_level', 'MODERADA')
            ),
            'window_lecture_days', 10
        ),
        'ALTA',
        'Patrón combinado: evasión interna + inasistencia detectadas simultáneamente',
        TRUE);

    v_snapshot := jsonb_build_object(
        'engine_version', '3.0',
        'levels', jsonb_build_object(
            'LEVE',     jsonb_build_object('weight', 1.0, 'half_life', 7, 'threshold', 4.0, 'cooldown', 5),
            'MODERADA', jsonb_build_object('weight', 3.0, 'half_life', 10, 'threshold', 9.0, 'cooldown', 7),
            'ALTA',     jsonb_build_object('weight', 6.0, 'half_life', 15, 'threshold', 12.0, 'cooldown', 3),
            'MUY_ALTA', jsonb_build_object('weight', 10.0, 'half_life', 9999, 'threshold', 10.0, 'cooldown', 0)
        ),
        'event_mapping', 'default_nexo',
        'combination_rules', 1
    );
    UPDATE risk_policies SET snapshot_json = v_snapshot WHERE policy_id = v_policy_id;
    RETURN v_policy_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Calcula riesgo activo por categoría con decaimiento exponencial + clustering
CREATE OR REPLACE FUNCTION fn_calculate_category_risk(
    p_student_id   UUID,
    p_school_id    UUID,
    p_category     VARCHAR,
    p_policy_id    UUID,
    p_lookback_days INTEGER DEFAULT 90
) RETURNS TABLE (
    active_score      NUMERIC(8,2),
    event_count       INTEGER,
    clustering_factor NUMERIC(5,2),
    event_details     JSONB,
    triggered_level   VARCHAR
) AS $$
DECLARE
    v_rule_record RECORD;
    v_lambda DOUBLE PRECISION;
    v_now TIMESTAMPTZ := NOW();
    v_total_score NUMERIC(8,2) := 0.0;
    v_event_count INTEGER := 0;
    v_events_json JSONB := '[]'::jsonb;
    v_intervals INTEGER[] := '{}';
    v_prev_date DATE;
    v_clustering NUMERIC(5,2) := 1.00;
    v_stddev DOUBLE PRECISION;
    v_avg_interval DOUBLE PRECISION;
    v_triggered_level VARCHAR := NULL;
    v_best_level VARCHAR := NULL;
    v_best_score NUMERIC(8,2) := 0.0;
BEGIN
    FOR v_rule_record IN
        SELECT rr.*, elm.risk_level AS mapped_level, ret.type_code, ret.category
        FROM risk_rules rr
        JOIN risk_event_level_mapping elm
            ON elm.policy_id = rr.policy_id
            AND elm.risk_level = rr.risk_level
            AND elm.student_id IS NULL
        JOIN risk_event_types ret
            ON ret.event_type_id = elm.event_type_id
        WHERE rr.policy_id = p_policy_id
          AND ret.category = p_category
          AND rr.risk_level != 'SIN_IMPORTANCIA'
        ORDER BY rr.weight_base DESC
    LOOP
        v_lambda := ln(2.0) / GREATEST(1, v_rule_record.half_life_days);
        SELECT
            COALESCE(SUM(
                v_rule_record.weight_base *
                EXP(-v_lambda *
                    fn_count_lecture_days(p_school_id,
                        (ai.detected_at AT TIME ZONE 'America/Bogota')::date,
                        (v_now AT TIME ZONE 'America/Bogota')::date)
                    )
            ), 0.0),
            COUNT(*)
        INTO v_total_score, v_event_count
        FROM attendance_incidents ai
        WHERE ai.student_id = p_student_id
          AND ai.school_id = p_school_id
          AND ai.incident_type = v_rule_record.type_code
          AND ai.detected_at >= v_now - (p_lookback_days || ' days')::INTERVAL
          AND ai.incident_type NOT LIKE 'RISK_ALERT%'
          AND NOT EXISTS (
              SELECT 1 FROM risk_justifications rj
              WHERE rj.student_id = ai.student_id
                AND rj.school_id = ai.school_id
                AND rj.incident_type = ai.incident_type
                AND rj.incident_date::date = ai.detected_at::date
          );
        IF v_event_count > 0 AND v_total_score > 0 THEN
            v_intervals := '{}';
            v_prev_date := NULL;
            SELECT array_agg(d ORDER BY d) INTO v_intervals
            FROM (
                SELECT DISTINCT (detected_at AT TIME ZONE 'America/Bogota')::date AS d
                FROM attendance_incidents
                WHERE student_id = p_student_id
                  AND school_id = p_school_id
                  AND incident_type = v_rule_record.type_code
                  AND detected_at >= v_now - (p_lookback_days || ' days')::INTERVAL
                  AND incident_type NOT LIKE 'RISK_ALERT%'
            ) sub;
            IF array_length(v_intervals, 1) >= 2 THEN
                SELECT COALESCE(stddev(interval_days), 0),
                       COALESCE(avg(interval_days), 0)
                INTO v_stddev, v_avg_interval
                FROM (
                    SELECT fn_count_lecture_days(p_school_id,
                        v_intervals[i], v_intervals[i+1]) AS interval_days
                    FROM generate_subscripts(v_intervals, 1) AS i
                    WHERE i < array_length(v_intervals, 1)
                ) intervals;
                IF v_stddev > 0 AND v_avg_interval > 0 THEN
                    v_clustering := LEAST(2.0, 1.0 + (v_avg_interval / (v_stddev + 0.5)) * 0.3);
                ELSE
                    v_clustering := 1.5;
                END IF;
            ELSE
                v_clustering := 1.0;
            END IF;
            v_total_score := v_total_score * v_clustering;
            IF v_total_score >= v_rule_record.activation_threshold THEN
                IF v_rule_record.single_occurrence AND v_event_count >= 1 THEN
                    v_triggered_level := v_rule_record.risk_level;
                    v_best_score := v_total_score;
                    EXIT;
                ELSIF v_event_count >= 1 THEN
                    IF v_best_level IS NULL OR v_total_score > v_best_score THEN
                        v_triggered_level := v_rule_record.risk_level;
                        v_best_score := v_total_score;
                    END IF;
                END IF;
            END IF;
        END IF;
    END LOOP;
    IF v_triggered_level IS NULL THEN
        v_triggered_level := 'NONE';
    END IF;
    RETURN QUERY
    SELECT
        COALESCE(v_best_score, 0.0)::NUMERIC(8,2),
        v_event_count,
        v_clustering::NUMERIC(5,2),
        v_events_json,
        v_triggered_level;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Evalúa todas las categorías + combinaciones + genera alertas
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
    FOR v_combo_record IN
        SELECT * FROM risk_combination_rules
        WHERE policy_id = v_policy_id AND is_active = TRUE
    LOOP
        NULL;
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
            SELECT cooldown_days INTO v_cooldown_days
            FROM risk_rules
            WHERE policy_id = v_policy_id AND risk_level = v_alert_level;
            INSERT INTO risk_alerts (
                school_id, student_id, alert_level, escalation_state,
                trigger_category, trigger_rule, trigger_score,
                policy_id, involved_events, status, cooldown_until, metadata_json
            ) VALUES (
                p_school_id, p_student_id, v_alert_level, v_escalation,
                v_cat, 'Riesgo activo superó umbral de ' || v_alert_level,
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
        END IF;
    END IF;
    RETURN jsonb_build_object(
        'student_id', p_student_id,
        'policy_id', v_policy_id,
        'categories', v_results,
        'max_level', v_max_level,
        'max_score', v_max_score,
        'alert_generated', v_should_alert AND v_max_level != 'NONE',
        'alert_id', v_alert_id
    );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Trigger: tras cada incidente, evalúa riesgo del estudiante con motor v3
CREATE OR REPLACE FUNCTION fn_trigger_evaluate_risk_v3()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.incident_type NOT LIKE 'RISK_ALERT%'
       AND NEW.incident_type IN (
           'LATE_ARRIVAL', 'INASISTENCIA', 'UNAUTHORIZED_ABSENCE',
           'EVASION_INTERNA', 'SALIDA_BAÑO', 'SALIDA_NO_AUTORIZADA'
       ) THEN
        PERFORM fn_evaluate_student_risk(NEW.student_id, NEW.school_id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SET search_path = public, pg_temp;

DROP TRIGGER IF EXISTS trg_recalc_risk_on_incident ON attendance_incidents;
DROP TRIGGER IF EXISTS trg_evaluate_risk_v3 ON attendance_incidents;
CREATE TRIGGER trg_evaluate_risk_v3
    AFTER INSERT ON attendance_incidents
    FOR EACH ROW
    EXECUTE FUNCTION fn_trigger_evaluate_risk_v3();

-- Recalcula el riesgo de toda una escuela
CREATE OR REPLACE FUNCTION fn_recalculate_school_risk_v3(p_school_id UUID)
RETURNS INTEGER AS $$
DECLARE
    v_count INTEGER := 0;
    v_student RECORD;
BEGIN
    FOR v_student IN
        SELECT student_id FROM students WHERE school_id = p_school_id AND active = TRUE
    LOOP
        BEGIN
            PERFORM fn_evaluate_student_risk(v_student.student_id, p_school_id);
            v_count := v_count + 1;
        EXCEPTION WHEN OTHERS THEN
            RAISE NOTICE 'Error evaluando student %: %', v_student.student_id, SQLERRM;
        END;
    END LOOP;
    RETURN v_count;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Justifica un evento y recalcula el riesgo
CREATE OR REPLACE FUNCTION fn_justify_risk_event(
    p_school_id    UUID,
    p_student_id   UUID,
    p_incident_type VARCHAR,
    p_incident_date TIMESTAMPTZ,
    p_justified_by UUID,
    p_justification_type VARCHAR,
    p_reason       TEXT
) RETURNS UUID AS $$
DECLARE
    v_just_id UUID;
BEGIN
    INSERT INTO risk_justifications (
        school_id, student_id, incident_type, incident_date,
        justified_by, justification_type, reason, recalculated, metadata_json
    ) VALUES (
        p_school_id, p_student_id, p_incident_type, p_incident_date,
        p_justified_by, p_justification_type, p_reason,
        FALSE, jsonb_build_object('justified_at', NOW())
    ) RETURNING justification_id INTO v_just_id;
    PERFORM fn_evaluate_student_risk(p_student_id, p_school_id);
    UPDATE risk_justifications SET recalculated = TRUE WHERE justification_id = v_just_id;
    RETURN v_just_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- =============================================================================
-- ROW LEVEL SECURITY — POLICIES
-- =============================================================================

-- students
ALTER TABLE students ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS students_select ON students;
DROP POLICY IF EXISTS students_insert ON students;
DROP POLICY IF EXISTS students_update ON students;
DROP POLICY IF EXISTS students_delete ON students;
CREATE POLICY students_select ON students FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY students_insert ON students FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY students_update ON students FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY students_delete ON students FOR DELETE USING(school_id = get_current_school_id());

-- biometric_events
ALTER TABLE biometric_events ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS be_select ON biometric_events;
DROP POLICY IF EXISTS be_insert ON biometric_events;
DROP POLICY IF EXISTS be_update ON biometric_events;
DROP POLICY IF EXISTS be_delete ON biometric_events;
CREATE POLICY be_select ON biometric_events FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY be_insert ON biometric_events FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY be_update ON biometric_events FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY be_delete ON biometric_events FOR DELETE USING(school_id = get_current_school_id());

-- attendance_incidents
ALTER TABLE attendance_incidents ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ai_select ON attendance_incidents;
DROP POLICY IF EXISTS ai_insert ON attendance_incidents;
DROP POLICY IF EXISTS ai_update ON attendance_incidents;
DROP POLICY IF EXISTS ai_delete ON attendance_incidents;
CREATE POLICY ai_select ON attendance_incidents FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY ai_insert ON attendance_incidents FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY ai_update ON attendance_incidents FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY ai_delete ON attendance_incidents FOR DELETE USING(school_id = get_current_school_id());

-- sos_alerts
ALTER TABLE sos_alerts ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sos_select ON sos_alerts;
DROP POLICY IF EXISTS sos_insert ON sos_alerts;
DROP POLICY IF EXISTS sos_update ON sos_alerts;
DROP POLICY IF EXISTS sos_delete ON sos_alerts;
CREATE POLICY sos_select ON sos_alerts FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY sos_insert ON sos_alerts FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY sos_update ON sos_alerts FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY sos_delete ON sos_alerts FOR DELETE USING(school_id = get_current_school_id());

-- global_audit_logs
ALTER TABLE global_audit_logs ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS gal_select ON global_audit_logs;
DROP POLICY IF EXISTS gal_insert ON global_audit_logs;
CREATE POLICY gal_select ON global_audit_logs FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY gal_insert ON global_audit_logs FOR INSERT WITH CHECK(school_id = get_current_school_id());

-- twilio_messages
ALTER TABLE twilio_messages ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tm_select ON twilio_messages;
DROP POLICY IF EXISTS tm_insert ON twilio_messages;
DROP POLICY IF EXISTS tm_update ON twilio_messages;
DROP POLICY IF EXISTS tm_delete ON twilio_messages;
CREATE POLICY tm_select ON twilio_messages FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY tm_insert ON twilio_messages FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY tm_update ON twilio_messages FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY tm_delete ON twilio_messages FOR DELETE USING(school_id = get_current_school_id());

-- edge_devices (EDGE_NODE puede hacer lookup por device_id sin school_id)
ALTER TABLE edge_devices ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ed_select ON edge_devices;
DROP POLICY IF EXISTS ed_insert ON edge_devices;
DROP POLICY IF EXISTS ed_update ON edge_devices;
DROP POLICY IF EXISTS ed_delete ON edge_devices;
CREATE POLICY ed_select ON edge_devices FOR SELECT USING(
    school_id = get_current_school_id()
    OR get_current_role() = 'EDGE_NODE'
);
CREATE POLICY ed_insert ON edge_devices FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY ed_update ON edge_devices FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY ed_delete ON edge_devices FOR DELETE USING(school_id = get_current_school_id());

-- student_behavior_metrics
ALTER TABLE student_behavior_metrics ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sbm_select ON student_behavior_metrics;
DROP POLICY IF EXISTS sbm_insert ON student_behavior_metrics;
DROP POLICY IF EXISTS sbm_update ON student_behavior_metrics;
DROP POLICY IF EXISTS sbm_delete ON student_behavior_metrics;
CREATE POLICY sbm_select ON student_behavior_metrics FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY sbm_insert ON student_behavior_metrics FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY sbm_update ON student_behavior_metrics FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY sbm_delete ON student_behavior_metrics FOR DELETE USING(school_id = get_current_school_id());

-- user_commands
ALTER TABLE user_commands ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS uc_select ON user_commands;
DROP POLICY IF EXISTS uc_insert ON user_commands;
DROP POLICY IF EXISTS uc_update ON user_commands;
DROP POLICY IF EXISTS uc_delete ON user_commands;
CREATE POLICY uc_select ON user_commands FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY uc_insert ON user_commands FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY uc_update ON user_commands FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY uc_delete ON user_commands FOR DELETE USING(school_id = get_current_school_id());

-- guardian_student_relationships
ALTER TABLE guardian_student_relationships ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS gsr_select ON guardian_student_relationships;
DROP POLICY IF EXISTS gsr_insert ON guardian_student_relationships;
DROP POLICY IF EXISTS gsr_delete ON guardian_student_relationships;
CREATE POLICY gsr_select ON guardian_student_relationships FOR SELECT
    USING(EXISTS(SELECT 1 FROM students s WHERE s.student_id = guardian_student_relationships.student_id AND s.school_id = get_current_school_id()) OR get_current_role() = 'SYSTEM_WORKER');
CREATE POLICY gsr_insert ON guardian_student_relationships FOR INSERT
    WITH CHECK(EXISTS(SELECT 1 FROM students s WHERE s.student_id = guardian_student_relationships.student_id AND s.school_id = get_current_school_id()));
CREATE POLICY gsr_delete ON guardian_student_relationships FOR DELETE
    USING(EXISTS(SELECT 1 FROM students s WHERE s.student_id = guardian_student_relationships.student_id AND s.school_id = get_current_school_id()));

-- student_group_assignments
ALTER TABLE student_group_assignments ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sga_select ON student_group_assignments;
DROP POLICY IF EXISTS sga_insert ON student_group_assignments;
DROP POLICY IF EXISTS sga_update ON student_group_assignments;
DROP POLICY IF EXISTS sga_delete ON student_group_assignments;
CREATE POLICY sga_select ON student_group_assignments FOR SELECT
    USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sga_insert ON student_group_assignments FOR INSERT
    WITH CHECK(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sga_update ON student_group_assignments FOR UPDATE
    USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sga_delete ON student_group_assignments FOR DELETE
    USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()));

-- schedules
ALTER TABLE schedules ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sch_select ON schedules;
DROP POLICY IF EXISTS sch_insert ON schedules;
DROP POLICY IF EXISTS sch_update ON schedules;
DROP POLICY IF EXISTS sch_delete ON schedules;
CREATE POLICY sch_select ON schedules FOR SELECT
    USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = schedules.group_id AND ag.school_id = get_current_school_id()) OR get_current_role() = 'SYSTEM_WORKER');
CREATE POLICY sch_insert ON schedules FOR INSERT
    WITH CHECK(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = schedules.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sch_update ON schedules FOR UPDATE
    USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = schedules.group_id AND ag.school_id = get_current_school_id()));
CREATE POLICY sch_delete ON schedules FOR DELETE
    USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = schedules.group_id AND ag.school_id = get_current_school_id()));

-- users
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS users_select ON users;
DROP POLICY IF EXISTS users_insert ON users;
DROP POLICY IF EXISTS users_update ON users;
DROP POLICY IF EXISTS users_delete ON users;
CREATE POLICY users_select ON users FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY users_insert ON users FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY users_update ON users FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY users_delete ON users FOR DELETE USING(school_id = get_current_school_id());

-- guardians
ALTER TABLE guardians ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS guardians_select ON guardians;
DROP POLICY IF EXISTS guardians_insert ON guardians;
DROP POLICY IF EXISTS guardians_update ON guardians;
DROP POLICY IF EXISTS guardians_delete ON guardians;
CREATE POLICY guardians_select ON guardians FOR SELECT
    USING(EXISTS(SELECT 1 FROM users u WHERE u.user_id = guardians.user_id AND u.school_id = get_current_school_id()));
CREATE POLICY guardians_insert ON guardians FOR INSERT
    WITH CHECK(EXISTS(SELECT 1 FROM users u WHERE u.user_id = guardians.user_id AND u.school_id = get_current_school_id()));
CREATE POLICY guardians_update ON guardians FOR UPDATE
    USING(EXISTS(SELECT 1 FROM users u WHERE u.user_id = guardians.user_id AND u.school_id = get_current_school_id()));
CREATE POLICY guardians_delete ON guardians FOR DELETE
    USING(EXISTS(SELECT 1 FROM users u WHERE u.user_id = guardians.user_id AND u.school_id = get_current_school_id()));

-- school_panic_events
ALTER TABLE school_panic_events ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS spe_select ON school_panic_events;
DROP POLICY IF EXISTS spe_insert ON school_panic_events;
DROP POLICY IF EXISTS spe_update ON school_panic_events;
DROP POLICY IF EXISTS spe_delete ON school_panic_events;
CREATE POLICY spe_select ON school_panic_events FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY spe_insert ON school_panic_events FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY spe_update ON school_panic_events FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY spe_delete ON school_panic_events FOR DELETE USING(school_id = get_current_school_id());

-- student_tracking
ALTER TABLE student_tracking ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS st_select ON student_tracking;
DROP POLICY IF EXISTS st_insert ON student_tracking;
DROP POLICY IF EXISTS st_update ON student_tracking;
DROP POLICY IF EXISTS st_delete ON student_tracking;
CREATE POLICY st_select ON student_tracking FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY st_insert ON student_tracking FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY st_update ON student_tracking FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY st_delete ON student_tracking FOR DELETE USING(school_id = get_current_school_id());

-- student_tracking_notes
ALTER TABLE student_tracking_notes ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS stn_select ON student_tracking_notes;
DROP POLICY IF EXISTS stn_insert ON student_tracking_notes;
DROP POLICY IF EXISTS stn_delete ON student_tracking_notes;
CREATE POLICY stn_select ON student_tracking_notes FOR SELECT
    USING(EXISTS(SELECT 1 FROM student_tracking st WHERE st.tracking_id = student_tracking_notes.tracking_id AND st.school_id = get_current_school_id()));
CREATE POLICY stn_insert ON student_tracking_notes FOR INSERT
    WITH CHECK(EXISTS(SELECT 1 FROM student_tracking st WHERE st.tracking_id = student_tracking_notes.tracking_id AND st.school_id = get_current_school_id()));
CREATE POLICY stn_delete ON student_tracking_notes FOR DELETE
    USING(EXISTS(SELECT 1 FROM student_tracking st WHERE st.tracking_id = student_tracking_notes.tracking_id AND st.school_id = get_current_school_id()));

-- notifications
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS notif_select ON notifications;
DROP POLICY IF EXISTS notif_insert ON notifications;
DROP POLICY IF EXISTS notif_delete ON notifications;
CREATE POLICY notif_select ON notifications FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY notif_insert ON notifications FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY notif_delete ON notifications FOR DELETE USING(school_id = get_current_school_id());

-- jwt_blocklist (tabla de sistema — acceso global)
ALTER TABLE jwt_blocklist ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS jbl_select ON jwt_blocklist;
DROP POLICY IF EXISTS jbl_insert ON jwt_blocklist;
CREATE POLICY jbl_select ON jwt_blocklist FOR SELECT USING(true);
CREATE POLICY jbl_insert ON jwt_blocklist FOR INSERT WITH CHECK(true);

-- subjects (catálogo global — lectura global)
ALTER TABLE subjects ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS subjects_select ON subjects;
CREATE POLICY subjects_select ON subjects FOR SELECT USING(true);

-- system_telemetry (solo SYSTEM_WORKER y SUPER_ADMIN)
ALTER TABLE system_telemetry ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS telemetry_select_admin ON system_telemetry;
DROP POLICY IF EXISTS telemetry_select_super_rector ON system_telemetry;
CREATE POLICY telemetry_select_admin ON system_telemetry FOR SELECT
    USING (get_current_role() IN ('SYSTEM_WORKER', 'SUPER_ADMIN'));
DROP POLICY IF EXISTS telemetry_insert_authenticated ON system_telemetry;
CREATE POLICY telemetry_insert_authenticated ON system_telemetry FOR INSERT
    WITH CHECK (current_setting('app.current_role', true) IS NOT NULL AND current_setting('app.current_role', true) != '');

-- daily_schedule_config
ALTER TABLE daily_schedule_config ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS dsc_select ON daily_schedule_config;
DROP POLICY IF EXISTS dsc_insert ON daily_schedule_config;
DROP POLICY IF EXISTS dsc_update ON daily_schedule_config;
DROP POLICY IF EXISTS dsc_delete ON daily_schedule_config;
CREATE POLICY dsc_select ON daily_schedule_config FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY dsc_insert ON daily_schedule_config FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY dsc_update ON daily_schedule_config FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY dsc_delete ON daily_schedule_config FOR DELETE USING(school_id = get_current_school_id());

-- VF-001: RLS en 10 tablas que no la tenían
-- staff_records
ALTER TABLE staff_records ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS str_select ON staff_records;
DROP POLICY IF EXISTS str_insert ON staff_records;
DROP POLICY IF EXISTS str_update ON staff_records;
DROP POLICY IF EXISTS str_delete ON staff_records;
CREATE POLICY str_select ON staff_records FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY str_insert ON staff_records FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY str_update ON staff_records FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY str_delete ON staff_records FOR DELETE USING(school_id = get_current_school_id());

-- academic_groups
ALTER TABLE academic_groups ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ag_select ON academic_groups;
DROP POLICY IF EXISTS ag_insert ON academic_groups;
DROP POLICY IF EXISTS ag_update ON academic_groups;
DROP POLICY IF EXISTS ag_delete ON academic_groups;
CREATE POLICY ag_select ON academic_groups FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY ag_insert ON academic_groups FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY ag_update ON academic_groups FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY ag_delete ON academic_groups FOR DELETE USING(school_id = get_current_school_id());

-- classrooms
ALTER TABLE classrooms ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS cr_select ON classrooms;
DROP POLICY IF EXISTS cr_insert ON classrooms;
DROP POLICY IF EXISTS cr_update ON classrooms;
DROP POLICY IF EXISTS cr_delete ON classrooms;
CREATE POLICY cr_select ON classrooms FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY cr_insert ON classrooms FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY cr_update ON classrooms FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY cr_delete ON classrooms FOR DELETE USING(school_id = get_current_school_id());

-- security_incidents
ALTER TABLE security_incidents ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS si_select ON security_incidents;
DROP POLICY IF EXISTS si_insert ON security_incidents;
DROP POLICY IF EXISTS si_update ON security_incidents;
DROP POLICY IF EXISTS si_delete ON security_incidents;
CREATE POLICY si_select ON security_incidents FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY si_insert ON security_incidents FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY si_update ON security_incidents FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY si_delete ON security_incidents FOR DELETE USING(school_id = get_current_school_id());

-- school_exit_authorizations
ALTER TABLE school_exit_authorizations ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sea_select ON school_exit_authorizations;
DROP POLICY IF EXISTS sea_insert ON school_exit_authorizations;
DROP POLICY IF EXISTS sea_update ON school_exit_authorizations;
DROP POLICY IF EXISTS sea_delete ON school_exit_authorizations;
CREATE POLICY sea_select ON school_exit_authorizations FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY sea_insert ON school_exit_authorizations FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY sea_update ON school_exit_authorizations FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY sea_delete ON school_exit_authorizations FOR DELETE USING(school_id = get_current_school_id());

-- class_exit_authorizations
ALTER TABLE class_exit_authorizations ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS cea_select ON class_exit_authorizations;
DROP POLICY IF EXISTS cea_insert ON class_exit_authorizations;
DROP POLICY IF EXISTS cea_update ON class_exit_authorizations;
DROP POLICY IF EXISTS cea_delete ON class_exit_authorizations;
CREATE POLICY cea_select ON class_exit_authorizations FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY cea_insert ON class_exit_authorizations FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY cea_update ON class_exit_authorizations FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY cea_delete ON class_exit_authorizations FOR DELETE USING(school_id = get_current_school_id());

-- pedagogical_trip_authorizations
ALTER TABLE pedagogical_trip_authorizations ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS pta_select ON pedagogical_trip_authorizations;
DROP POLICY IF EXISTS pta_insert ON pedagogical_trip_authorizations;
DROP POLICY IF EXISTS pta_update ON pedagogical_trip_authorizations;
DROP POLICY IF EXISTS pta_delete ON pedagogical_trip_authorizations;
CREATE POLICY pta_select ON pedagogical_trip_authorizations FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY pta_insert ON pedagogical_trip_authorizations FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY pta_update ON pedagogical_trip_authorizations FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY pta_delete ON pedagogical_trip_authorizations FOR DELETE USING(school_id = get_current_school_id());

-- student_record_audit (particionada)
ALTER TABLE student_record_audit ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sra_select ON student_record_audit;
DROP POLICY IF EXISTS sra_insert ON student_record_audit;
CREATE POLICY sra_select ON student_record_audit FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY sra_insert ON student_record_audit FOR INSERT WITH CHECK(school_id = get_current_school_id());

-- report_exports
ALTER TABLE report_exports ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS re_select ON report_exports;
DROP POLICY IF EXISTS re_insert ON report_exports;
DROP POLICY IF EXISTS re_delete ON report_exports;
CREATE POLICY re_select ON report_exports FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY re_insert ON report_exports FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY re_delete ON report_exports FOR DELETE USING(school_id = get_current_school_id());

-- internal_messages (particionada)
ALTER TABLE internal_messages ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS im_select ON internal_messages;
DROP POLICY IF EXISTS im_insert ON internal_messages;
DROP POLICY IF EXISTS im_update ON internal_messages;
DROP POLICY IF EXISTS im_delete ON internal_messages;
CREATE POLICY im_select ON internal_messages FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY im_insert ON internal_messages FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY im_update ON internal_messages FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY im_delete ON internal_messages FOR DELETE USING(school_id = get_current_school_id());

-- school_schedule_config
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

-- school_time_blocks
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

-- user_sessions (multi-tenant via JOIN users, SYSTEM_WORKER puede leer todo)
ALTER TABLE user_sessions ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS user_sessions_select ON user_sessions;
DROP POLICY IF EXISTS user_sessions_insert ON user_sessions;
DROP POLICY IF EXISTS user_sessions_update ON user_sessions;
DROP POLICY IF EXISTS user_sessions_delete ON user_sessions;
CREATE POLICY user_sessions_select ON user_sessions FOR SELECT
    USING (
        get_current_role() = 'SYSTEM_WORKER'
        OR EXISTS (SELECT 1 FROM users u WHERE u.user_id = user_sessions.user_id AND u.school_id = get_current_school_id())
    );
CREATE POLICY user_sessions_insert ON user_sessions FOR INSERT
    WITH CHECK (
        EXISTS (SELECT 1 FROM users u WHERE u.user_id = user_sessions.user_id AND u.school_id = get_current_school_id())
    );
CREATE POLICY user_sessions_update ON user_sessions FOR UPDATE
    USING (
        get_current_role() = 'SYSTEM_WORKER'
        OR EXISTS (SELECT 1 FROM users u WHERE u.user_id = user_sessions.user_id AND u.school_id = get_current_school_id())
    );
CREATE POLICY user_sessions_delete ON user_sessions FOR DELETE
    USING (
        get_current_role() = 'SYSTEM_WORKER'
        OR EXISTS (SELECT 1 FROM users u WHERE u.user_id = user_sessions.user_id AND u.school_id = get_current_school_id())
    );

-- teacher_group_access
ALTER TABLE teacher_group_access ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tga_select ON teacher_group_access;
DROP POLICY IF EXISTS tga_insert ON teacher_group_access;
DROP POLICY IF EXISTS tga_update ON teacher_group_access;
DROP POLICY IF EXISTS tga_delete ON teacher_group_access;
CREATE POLICY tga_select ON teacher_group_access FOR SELECT
    USING (school_id = get_current_school_id() OR get_current_role() = 'SYSTEM_WORKER');
CREATE POLICY tga_insert ON teacher_group_access FOR INSERT
    WITH CHECK (school_id = get_current_school_id());
CREATE POLICY tga_update ON teacher_group_access FOR UPDATE
    USING (school_id = get_current_school_id());
CREATE POLICY tga_delete ON teacher_group_access FOR DELETE
    USING (school_id = get_current_school_id());

-- sensor_revocation_requests
ALTER TABLE sensor_revocation_requests ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS srr_select ON sensor_revocation_requests;
DROP POLICY IF EXISTS srr_insert ON sensor_revocation_requests;
DROP POLICY IF EXISTS srr_update ON sensor_revocation_requests;
DROP POLICY IF EXISTS srr_delete ON sensor_revocation_requests;
CREATE POLICY srr_select ON sensor_revocation_requests FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY srr_insert ON sensor_revocation_requests FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY srr_update ON sensor_revocation_requests FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY srr_delete ON sensor_revocation_requests FOR DELETE USING(school_id = get_current_school_id());

-- risk_policies
ALTER TABLE risk_policies ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS risk_policies_select ON risk_policies;
DROP POLICY IF EXISTS risk_policies_insert ON risk_policies;
DROP POLICY IF EXISTS risk_policies_update ON risk_policies;
DROP POLICY IF EXISTS risk_policies_delete ON risk_policies;
CREATE POLICY risk_policies_select ON risk_policies FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY risk_policies_insert ON risk_policies FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY risk_policies_update ON risk_policies FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY risk_policies_delete ON risk_policies FOR DELETE USING(school_id = get_current_school_id());

-- risk_event_level_mapping
ALTER TABLE risk_event_level_mapping ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS risk_elm_select ON risk_event_level_mapping;
DROP POLICY IF EXISTS risk_elm_insert ON risk_event_level_mapping;
DROP POLICY IF EXISTS risk_elm_update ON risk_event_level_mapping;
DROP POLICY IF EXISTS risk_elm_delete ON risk_event_level_mapping;
CREATE POLICY risk_elm_select ON risk_event_level_mapping FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY risk_elm_insert ON risk_event_level_mapping FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY risk_elm_update ON risk_event_level_mapping FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY risk_elm_delete ON risk_event_level_mapping FOR DELETE USING(school_id = get_current_school_id());

-- risk_rules
ALTER TABLE risk_rules ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS risk_rules_select ON risk_rules;
DROP POLICY IF EXISTS risk_rules_insert ON risk_rules;
DROP POLICY IF EXISTS risk_rules_update ON risk_rules;
DROP POLICY IF EXISTS risk_rules_delete ON risk_rules;
CREATE POLICY risk_rules_select ON risk_rules FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY risk_rules_insert ON risk_rules FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY risk_rules_update ON risk_rules FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY risk_rules_delete ON risk_rules FOR DELETE USING(school_id = get_current_school_id());

-- risk_combination_rules
ALTER TABLE risk_combination_rules ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS risk_combo_select ON risk_combination_rules;
DROP POLICY IF EXISTS risk_combo_insert ON risk_combination_rules;
DROP POLICY IF EXISTS risk_combo_update ON risk_combination_rules;
DROP POLICY IF EXISTS risk_combo_delete ON risk_combination_rules;
CREATE POLICY risk_combo_select ON risk_combination_rules FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY risk_combo_insert ON risk_combination_rules FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY risk_combo_update ON risk_combination_rules FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY risk_combo_delete ON risk_combination_rules FOR DELETE USING(school_id = get_current_school_id());

-- risk_active_snapshot
ALTER TABLE risk_active_snapshot ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS risk_snapshot_select ON risk_active_snapshot;
DROP POLICY IF EXISTS risk_snapshot_insert ON risk_active_snapshot;
DROP POLICY IF EXISTS risk_snapshot_update ON risk_active_snapshot;
DROP POLICY IF EXISTS risk_snapshot_delete ON risk_active_snapshot;
CREATE POLICY risk_snapshot_select ON risk_active_snapshot FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY risk_snapshot_insert ON risk_active_snapshot FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY risk_snapshot_update ON risk_active_snapshot FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY risk_snapshot_delete ON risk_active_snapshot FOR DELETE USING(school_id = get_current_school_id());

-- risk_alerts
ALTER TABLE risk_alerts ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS risk_alerts_select ON risk_alerts;
DROP POLICY IF EXISTS risk_alerts_insert ON risk_alerts;
DROP POLICY IF EXISTS risk_alerts_update ON risk_alerts;
DROP POLICY IF EXISTS risk_alerts_delete ON risk_alerts;
CREATE POLICY risk_alerts_select ON risk_alerts FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY risk_alerts_insert ON risk_alerts FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY risk_alerts_update ON risk_alerts FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY risk_alerts_delete ON risk_alerts FOR DELETE USING(school_id = get_current_school_id());

-- risk_justifications
ALTER TABLE risk_justifications ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS risk_just_select ON risk_justifications;
DROP POLICY IF EXISTS risk_just_insert ON risk_justifications;
DROP POLICY IF EXISTS risk_just_update ON risk_justifications;
DROP POLICY IF EXISTS risk_just_delete ON risk_justifications;
CREATE POLICY risk_just_select ON risk_justifications FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY risk_just_insert ON risk_justifications FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY risk_just_update ON risk_justifications FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY risk_just_delete ON risk_justifications FOR DELETE USING(school_id = get_current_school_id());

-- risk_audit_log
ALTER TABLE risk_audit_log ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS risk_audit_select ON risk_audit_log;
DROP POLICY IF EXISTS risk_audit_insert ON risk_audit_log;
CREATE POLICY risk_audit_select ON risk_audit_log FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY risk_audit_insert ON risk_audit_log FOR INSERT WITH CHECK(school_id = get_current_school_id());

-- school_calendar
ALTER TABLE school_calendar ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS school_calendar_select ON school_calendar;
DROP POLICY IF EXISTS school_calendar_insert ON school_calendar;
DROP POLICY IF EXISTS school_calendar_update ON school_calendar;
DROP POLICY IF EXISTS school_calendar_delete ON school_calendar;
CREATE POLICY school_calendar_select ON school_calendar FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY school_calendar_insert ON school_calendar FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY school_calendar_update ON school_calendar FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY school_calendar_delete ON school_calendar FOR DELETE USING(school_id = get_current_school_id());

-- device_commands (multi-tenant via device_id → edge_devices.school_id)
ALTER TABLE device_commands ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS dc_select ON device_commands;
DROP POLICY IF EXISTS dc_insert ON device_commands;
DROP POLICY IF EXISTS dc_update ON device_commands;
DROP POLICY IF EXISTS dc_delete ON device_commands;
CREATE POLICY dc_select ON device_commands FOR SELECT
    USING (EXISTS (SELECT 1 FROM edge_devices ed WHERE ed.device_id = device_commands.device_id AND ed.school_id = get_current_school_id()));
CREATE POLICY dc_insert ON device_commands FOR INSERT
    WITH CHECK (EXISTS (SELECT 1 FROM edge_devices ed WHERE ed.device_id = device_commands.device_id AND ed.school_id = get_current_school_id()));
CREATE POLICY dc_update ON device_commands FOR UPDATE
    USING (EXISTS (SELECT 1 FROM edge_devices ed WHERE ed.device_id = device_commands.device_id AND ed.school_id = get_current_school_id()));
CREATE POLICY dc_delete ON device_commands FOR DELETE
    USING (EXISTS (SELECT 1 FROM edge_devices ed WHERE ed.device_id = device_commands.device_id AND ed.school_id = get_current_school_id()));

-- =============================================================================
-- PARTICIONES — DEFAULT + función de creación automática (VF-005 definitivo)
-- =============================================================================
-- DEFAULT partitions (catch-all para datos fuera de rango)
CREATE TABLE IF NOT EXISTS biometric_events_default PARTITION OF biometric_events DEFAULT;
CREATE TABLE IF NOT EXISTS attendance_incidents_default PARTITION OF attendance_incidents DEFAULT;
CREATE TABLE IF NOT EXISTS internal_messages_default PARTITION OF internal_messages DEFAULT;
CREATE TABLE IF NOT EXISTS twilio_messages_default PARTITION OF twilio_messages DEFAULT;
CREATE TABLE IF NOT EXISTS user_commands_default PARTITION OF user_commands DEFAULT;
CREATE TABLE IF NOT EXISTS sos_alerts_default PARTITION OF sos_alerts DEFAULT;
CREATE TABLE IF NOT EXISTS student_record_audit_default PARTITION OF student_record_audit DEFAULT;
CREATE TABLE IF NOT EXISTS global_audit_logs_default PARTITION OF global_audit_logs DEFAULT;

-- =============================================================================
-- Función: fn_ensure_partitions
-- =============================================================================
-- Crea particiones mensuales para las 8 tablas particionadas para el mes
-- actual + los próximos N meses (default 3). Es idempotente: si la partición
-- ya existe, la omite. Diseñada para ejecutarse vía cron mensual o semanal.
--
-- Parámetros:
--   p_months_ahead INTEGER DEFAULT 3 — cuántos meses futuros crear
--
-- Uso:
--   SELECT fn_ensure_partitions(3);  -- crea mes actual + 3 futuros
--   SELECT fn_ensure_partitions(6);  -- crea mes actual + 6 futuros
-- =============================================================================
CREATE OR REPLACE FUNCTION fn_ensure_partitions(p_months_ahead INTEGER DEFAULT 3)
RETURNS INTEGER AS $$
DECLARE
    v_count      INTEGER := 0;
    v_month_date DATE;
    v_end_date   DATE;
    v_part_name  TEXT;
    v_start_str  TEXT;
    v_end_str    TEXT;
    v_table_defs JSONB;
    v_def        JSONB;
    v_i          INTEGER;
BEGIN
    -- Definición de las 8 tablas particionadas: (tabla, columna)
    v_table_defs := jsonb_build_array(
        jsonb_build_object('table', 'biometric_events',     'col', 'event_timestamp'),
        jsonb_build_object('table', 'attendance_incidents', 'col', 'detected_at'),
        jsonb_build_object('table', 'user_commands',        'col', 'executed_at'),
        jsonb_build_object('table', 'sos_alerts',           'col', 'emitted_at'),
        jsonb_build_object('table', 'internal_messages',    'col', 'sent_at'),
        jsonb_build_object('table', 'twilio_messages',      'col', 'sent_at'),
        jsonb_build_object('table', 'student_record_audit', 'col', 'performed_at'),
        jsonb_build_object('table', 'global_audit_logs',    'col', 'created_at')
    );

    -- Crear particiones desde el mes actual hasta p_months_ahead
    FOR v_i IN 0..p_months_ahead LOOP
        v_month_date := date_trunc('month', NOW()::date + (v_i || ' months')::INTERVAL)::date;
        v_end_date   := (v_month_date + INTERVAL '1 month')::date;
        v_start_str  := to_char(v_month_date, 'YYYY-MM-DD');
        v_end_str    := to_char(v_end_date, 'YYYY-MM-DD');

        FOR v_def IN SELECT * FROM jsonb_array_elements(v_table_defs) LOOP
            v_part_name := v_def->>'table' || '_' || to_char(v_month_date, 'YYYY_MM');

            -- Verificar si la partición ya existe
            IF NOT EXISTS (
                SELECT 1 FROM pg_tables
                WHERE schemaname = 'public' AND tablename = v_part_name
            ) THEN
                EXECUTE format(
                    'CREATE TABLE IF NOT EXISTS %I PARTITION OF %I FOR VALUES FROM (%L) TO (%L)',
                    v_part_name, v_def->>'table', v_start_str, v_end_str
                );
                v_count := v_count + 1;
            END IF;
        END LOOP;
    END LOOP;

    RETURN v_count;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

-- Crear particiones iniciales: mes actual + 6 meses futuros
SELECT fn_ensure_partitions(6);

-- =============================================================================
-- Función: fn_drop_old_partitions
-- =============================================================================
-- Política de retención: elimina (DETACH + DROP) particiones más antiguas
-- que el número de meses especificado. Solo afecta particiones mensuales
-- con nombre _YYYY_MM; nunca toca la partición DEFAULT.
--
-- Parámetros:
--   p_retention_months INTEGER DEFAULT 24 — meses a conservar
--
-- Uso:
--   SELECT fn_drop_old_partitions(24);  -- elimina particiones > 24 meses
--   SELECT fn_drop_old_partitions(36);  -- conserva 3 años
--
-- Configurable vía NEXO_PARTITION_RETENTION_MONTHS (ver crontab).
-- =============================================================================
CREATE OR REPLACE FUNCTION fn_drop_old_partitions(p_retention_months INTEGER DEFAULT 24)
RETURNS INTEGER AS $$
DECLARE
    v_count     INTEGER := 0;
    v_cutoff    DATE;
    v_rec       RECORD;
    v_part_date DATE;
BEGIN
    v_cutoff := date_trunc('month', NOW()::date - (p_retention_months || ' months')::INTERVAL)::date;

    FOR v_rec IN
        SELECT c.relname AS part_name, p.relname AS parent_name
        FROM pg_inherits i
        JOIN pg_class c ON i.inhrelid = c.oid
        JOIN pg_class p ON i.inhparent = p.oid
        JOIN pg_namespace n ON c.relnamespace = n.oid
        WHERE n.nspname = 'public'
          AND c.relname != p.relname || '_default'
          AND c.relname ~ '_[0-9]{4}_[0-9]{2}$'
    LOOP
        -- Extraer fecha del nombre _YYYY_MM
        BEGIN
            v_part_date := to_date(
                split_part(v_rec.part_name, '_', array_length(string_to_array(v_rec.part_name, '_'), 1) - 1) ||
                '-' ||
                split_part(v_rec.part_name, '_', array_length(string_to_array(v_rec.part_name, '_'), 1)),
                'YYYY-MM'
            );
        EXCEPTION WHEN OTHERS THEN
            CONTINUE;
        END;

        IF v_part_date < v_cutoff THEN
            -- DETACH primero (seguro), luego DROP
            EXECUTE format('ALTER TABLE %I DETACH PARTITION %I', v_rec.parent_name, v_rec.part_name);
            EXECUTE format('DROP TABLE IF EXISTS %I', v_rec.part_name);
            v_count := v_count + 1;
        END IF;
    END LOOP;

    RETURN v_count;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog;

-- =============================================================================
-- SEED DATA — Mínimo (geografía, roles, permisos, admin)
-- =============================================================================
INSERT INTO departments(department_id, department_name)
VALUES(uuid_generate_v4(), 'Bogotá D.C.') ON CONFLICT DO NOTHING;

INSERT INTO municipalities(municipality_id, department_id, municipality_name)
SELECT uuid_generate_v4(), d.department_id, 'Bogotá D.C.'
FROM departments d WHERE d.department_name = 'Bogotá D.C.'
ON CONFLICT DO NOTHING;

INSERT INTO schools(school_id, municipality_id, dane_code, school_name, address, phone, email, active)
SELECT uuid_generate_v4(), m.municipality_id, '000000000', 'Institución Educativa NEXO',
       'Calle 1 # 1-1', '6010000000', 'contacto@nexo.edu', TRUE
FROM municipalities m JOIN departments d ON d.department_id = m.department_id
WHERE d.department_name = 'Bogotá D.C.'
ON CONFLICT DO NOTHING;

-- Roles
INSERT INTO roles(role_id, role_name, description) VALUES
    (uuid_generate_v4(), 'RECTOR', 'School principal'),
    (uuid_generate_v4(), 'COORDINATOR', 'Academic / disciplinary coordinator'),
    (uuid_generate_v4(), 'TEACHER', 'Classroom teacher'),
    (uuid_generate_v4(), 'SECRETARY', 'Administrative secretary'),
    (uuid_generate_v4(), 'SECURITY', 'Security guard / gatekeeper'),
    (uuid_generate_v4(), 'AUXILIARY', 'Administrative auxiliary'),
    (uuid_generate_v4(), 'COUNSELOR', 'School counselor / psychologist'),
    (uuid_generate_v4(), 'GUARDIAN', 'Student guardian / parent'),
    (uuid_generate_v4(), 'SUPER_ADMIN', 'Global system administrator'),
    (uuid_generate_v4(), 'SYSTEM_WORKER', 'Internal system worker / background process')
ON CONFLICT(role_name) DO NOTHING;

-- Admin user (password: admin123)
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, password_hash, password_salt, active)
SELECT uuid_generate_v4(), s.school_id, r.role_id, '111111111', 'Admin', 'NEXO', 'admin@nexo.edu',
       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'salt', TRUE
FROM schools s, roles r WHERE r.role_name = 'RECTOR'
ON CONFLICT(email) DO NOTHING;

-- Permisos
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

-- Asignación de permisos a roles (helper temporal)
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

-- RECTOR: all permissions
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

-- COORDINATOR
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

-- TEACHER
SELECT assign_permission_to_role('TEACHER', 'dashboard.teacher_view');
SELECT assign_permission_to_role('TEACHER', 'operations.inasistencia');
SELECT assign_permission_to_role('TEACHER', 'operations.citacion');
SELECT assign_permission_to_role('TEACHER', 'operations.permiso');
SELECT assign_permission_to_role('TEACHER', 'operations.pedagogica');
SELECT assign_permission_to_role('TEACHER', 'operations.horario');
SELECT assign_permission_to_role('TEACHER', 'operations.incidente');
SELECT assign_permission_to_role('TEACHER', 'operations.seguimiento');
SELECT assign_permission_to_role('TEACHER', 'operations.solicitud');
SELECT assign_permission_to_role('TEACHER', 'operations.fusionar_bloque');
SELECT assign_permission_to_role('TEACHER', 'operations.situacion_critica');
SELECT assign_permission_to_role('TEACHER', 'consultations.teacher_view');
SELECT assign_permission_to_role('TEACHER', 'reports.preview');
SELECT assign_permission_to_role('TEACHER', 'students.view');
SELECT assign_permission_to_role('TEACHER', 'behavior.view_risk');

-- SECRETARY
SELECT assign_permission_to_role('SECRETARY', 'dashboard.global_view');
SELECT assign_permission_to_role('SECRETARY', 'students.create');
SELECT assign_permission_to_role('SECRETARY', 'students.view');
SELECT assign_permission_to_role('SECRETARY', 'consultations.global_view');
SELECT assign_permission_to_role('SECRETARY', 'reports.preview');
SELECT assign_permission_to_role('SECRETARY', 'reports.export');
SELECT assign_permission_to_role('SECRETARY', 'operations.solicitud');
SELECT assign_permission_to_role('SECRETARY', 'operations.situacion_critica');

-- COUNSELOR
SELECT assign_permission_to_role('COUNSELOR', 'dashboard.teacher_view');
SELECT assign_permission_to_role('COUNSELOR', 'tracking.manage');
SELECT assign_permission_to_role('COUNSELOR', 'behavior.view_risk');
SELECT assign_permission_to_role('COUNSELOR', 'consultations.teacher_view');
SELECT assign_permission_to_role('COUNSELOR', 'consultations.global_view');
SELECT assign_permission_to_role('COUNSELOR', 'operations.seguimiento');
SELECT assign_permission_to_role('COUNSELOR', 'operations.situacion_critica');
SELECT assign_permission_to_role('COUNSELOR', 'operations.solicitud');
SELECT assign_permission_to_role('COUNSELOR', 'operations.citacion');
SELECT assign_permission_to_role('COUNSELOR', 'students.view');

-- SECURITY
SELECT assign_permission_to_role('SECURITY', 'students.view');
SELECT assign_permission_to_role('SECURITY', 'consultations.global_view');
SELECT assign_permission_to_role('SECURITY', 'reports.preview');
SELECT assign_permission_to_role('SECURITY', 'operations.solicitud');
SELECT assign_permission_to_role('SECURITY', 'operations.daño');
SELECT assign_permission_to_role('SECURITY', 'operations.situacion_critica');

-- AUXILIARY
SELECT assign_permission_to_role('AUXILIARY', 'dashboard.global_view');
SELECT assign_permission_to_role('AUXILIARY', 'students.view');
SELECT assign_permission_to_role('AUXILIARY', 'consultations.global_view');
SELECT assign_permission_to_role('AUXILIARY', 'reports.preview');
SELECT assign_permission_to_role('AUXILIARY', 'operations.solicitud');
SELECT assign_permission_to_role('AUXILIARY', 'operations.daño');
SELECT assign_permission_to_role('AUXILIARY', 'operations.situacion_critica');

DROP FUNCTION IF EXISTS assign_permission_to_role(VARCHAR, VARCHAR);

-- =============================================================================
-- COMMENTS (al final, después de crear todas las tablas)
-- =============================================================================
COMMENT ON TABLE users IS 'Core user accounts with authentication credentials. Multi-tenant by school_id.';
COMMENT ON TABLE students IS 'Student enrollment records. Soft-deletable. Multi-tenant by school_id.';
COMMENT ON TABLE guardians IS 'Guardian/parent profiles linked to users. WhatsApp phone is primary contact method.';
COMMENT ON TABLE biometric_events IS 'Biometric scan events from edge devices. Partitioned by event_timestamp for performance.';
COMMENT ON TABLE school_panic_events IS 'Emergency panic button events. Triggers device deactivation cascade.';

-- =============================================================================
-- FIN DEL ESQUEMA
-- =============================================================================
