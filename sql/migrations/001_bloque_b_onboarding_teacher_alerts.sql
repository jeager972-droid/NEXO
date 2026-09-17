-- =============================================================================
-- Migración 001 — Bloque B: onboarding enforceable + criterios docente (F-18)
-- Aplica sobre bases NEXO existentes (schema.sql ya la incorpora para despliegues
-- nuevos). Idempotente: segura de re-ejecutar.
-- Orden: después de cualquier schema base previo; antes de reiniciar API/workers.
-- =============================================================================

BEGIN;

-- 1. Criterios de aviso por docente (F-18)
CREATE TABLE IF NOT EXISTS teacher_alert_rules (
    rule_id         UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id       UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    teacher_user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    group_id        UUID REFERENCES academic_groups(group_id) ON DELETE CASCADE,
    student_id      UUID REFERENCES students(student_id) ON DELETE CASCADE,
    event_kind      VARCHAR(30) NOT NULL CHECK(event_kind IN
        ('LATE','ABSENCE','EVASION','EXIT','PERMISSION_EXPIRY')),
    threshold_count INTEGER NOT NULL CHECK(threshold_count > 0 AND threshold_count <= 60),
    window_days     INTEGER NOT NULL DEFAULT 7 CHECK(window_days BETWEEN 1 AND 90),
    active          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_teacher_alert_rules_teacher ON teacher_alert_rules(teacher_user_id, active);
CREATE INDEX IF NOT EXISTS idx_teacher_alert_rules_school  ON teacher_alert_rules(school_id, active);

-- 2. Onboarding por usuario (docente completa/omite criterios de aviso)
ALTER TABLE users ADD COLUMN IF NOT EXISTS onboarding_completed BOOLEAN NOT NULL DEFAULT FALSE;

-- 2b. Enrutamiento configurable de respuestas/escalaciones (Bloque C — Twilio)
CREATE TABLE IF NOT EXISTS school_notification_routes (
    route_id    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id   UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    event_kind  VARCHAR(50) NOT NULL,
    target_role VARCHAR(50) NOT NULL,
    enabled     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_school_route UNIQUE (school_id, event_kind, target_role)
);
CREATE INDEX IF NOT EXISTS idx_snr_school ON school_notification_routes(school_id, event_kind, enabled);

-- 3. student_fingerprints — si la BD previa la tenía antes de edge_devices y
--    la creó sin FK, esto la añade de forma idempotente.
DO $$ BEGIN
  IF to_regclass('student_fingerprints') IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'student_fingerprints_device_id_fkey') THEN
    ALTER TABLE student_fingerprints
      ADD CONSTRAINT student_fingerprints_device_id_fkey
      FOREIGN KEY (device_id) REFERENCES edge_devices(device_id);
  END IF;
END $$;

-- 4. Registro manual pendiente temporal (doc §9.6)
ALTER TABLE students ADD COLUMN IF NOT EXISTS manual_pending_until TIMESTAMPTZ;

-- 5. OTA M2M (Bloque D)
ALTER TABLE edge_devices ADD COLUMN IF NOT EXISTS app_version VARCHAR(40) DEFAULT '1.0.0';
ALTER TABLE edge_devices ADD COLUMN IF NOT EXISTS ota_key VARCHAR(64);

CREATE TABLE IF NOT EXISTS ota_updates (
    update_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id      UUID REFERENCES schools(school_id) ON DELETE CASCADE,
    version        VARCHAR(40) NOT NULL,
    payload_url    TEXT NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    min_version    VARCHAR(40),
    notes          TEXT,
    active         BOOLEAN NOT NULL DEFAULT TRUE,
    created_by     UUID,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_ota_school_version UNIQUE (school_id, version)
);
CREATE INDEX IF NOT EXISTS idx_ota_updates_active ON ota_updates(active, created_at DESC);

CREATE TABLE IF NOT EXISTS ota_deployments (
    deployment_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    update_id     UUID NOT NULL REFERENCES ota_updates(update_id) ON DELETE CASCADE,
    device_id     UUID NOT NULL REFERENCES edge_devices(device_id) ON DELETE CASCADE,
    school_id     UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    status        VARCHAR(20) NOT NULL,
    detail        TEXT,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_ota_deploy UNIQUE (update_id, device_id)
);

-- RLS: lectura por escuela del dueño; EDGE_NODE puede leer/escribir despliegues
ALTER TABLE ota_deployments ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ota_d_select ON ota_deployments;
DROP POLICY IF EXISTS ota_d_insert ON ota_deployments;
DROP POLICY IF EXISTS ota_d_update ON ota_deployments;
CREATE POLICY ota_d_select ON ota_deployments FOR SELECT USING(school_id = get_current_school_id() OR get_current_role() IN ('SYSTEM_WORKER','EDGE_NODE','SUPER_ADMIN'));
CREATE POLICY ota_d_insert ON ota_deployments FOR INSERT WITH CHECK(school_id = get_current_school_id() OR get_current_role() IN ('SYSTEM_WORKER','EDGE_NODE'));
CREATE POLICY ota_d_update ON ota_deployments FOR UPDATE USING(school_id = get_current_school_id() OR get_current_role() IN ('SYSTEM_WORKER','EDGE_NODE','SUPER_ADMIN'));

-- 6. Catálogo de tipos de mensaje Twilio (documentación/clasificación)
INSERT INTO twilio_message_types (type_code, description) VALUES
    ('INASISTENCIA',       'Notificación/respuesta de inasistencia con menú 1-2'),
    ('CITACION',           'Citación al acudiente'),
    ('AUTORIZAR_SALIDA',   'Autorización de salida escolar'),
    ('CRITICAL_SITUATION', 'Situación crítica (emergencia/pánico)'),
    ('INCIDENTE',          'Incidente disciplinario/de seguridad'),
    ('NOTIFY_ROLE',        'Notificación dirigida a un rol'),
    ('OUTBOUND',           'Mensaje saliente genérico'),
    ('PEDAGOGICA',         'Salida pedagógica grupal'),
    ('SOLICITUD',          'Solicitud del acudiente'),
    ('SOS_ALERT',          'Alerta SOS de botón de pánico'),
    ('HORARIO',            'Modificación de jornada/horario del día'),
    ('SEGUIMIENTO',        'Derivación/seguimiento de caso'),
    ('ABSENCE_FOLLOWUP',   'Recordatorio/escalación por falta de respuesta')
ON CONFLICT (type_code) DO NOTHING;

COMMIT;
