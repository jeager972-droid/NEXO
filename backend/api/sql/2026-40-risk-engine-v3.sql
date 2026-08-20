-- =============================================================================
-- 2026-40: Motor de Análisis de Riesgo Pedagógico v3.0
--
-- Arquitectura de 7 capas:
--   Capa 1: Ingesta, deduplicación y filtros de contexto
--   Capa 2: Políticas institucionales configurables (versionadas)
--   Capa 3: Scoring con decaimiento exponencial + clustering
--   Capa 4: Combinación/correlación entre categorías
--   Capa 5: Escalamiento y alertas (máquina de estados)
--   Capa 6: Auditoría y versionado de políticas
--   Capa 7: Decisión humana (Coordinación)
--   Capa 8 (opcional): Anomalía estadística (z-score individual)
--
-- Niveles de gravedad (ontología NEXO, no editable por institución):
--   SIN_IMPORTANCIA: peso 0, no alimenta scoring
--   LEVE:           peso 1.0, vida media 7 días lectivos
--   MODERADA:        peso 3.0, vida media 10 días lectivos
--   ALTA:            peso 6.0, vida media 15 días lectivos
--   MUY_ALTA:        peso 10.0, no decae, 1 ocurrencia dispara
--
-- La institución configura:
--   - Qué evento mapped a qué nivel
--   - Vida media (dentro de rangos permitidos)
--   - Umbrales (dentro de rangos permitidos)
--   - Cooldowns
--   - Textos de notificación
-- =============================================================================

-- =============================================================================
-- BLOQUE 1: Tablas catálogo — tipos de evento y niveles
-- =============================================================================

-- Catálogo universal de tipos de evento que pueden alimentar el riesgo.
-- NEXO define qué eventos existen; la institución decide su nivel.
CREATE TABLE IF NOT EXISTS risk_event_types (
    event_type_id     SERIAL PRIMARY KEY,
    type_code         VARCHAR(120) NOT NULL UNIQUE,
    display_name      VARCHAR(200) NOT NULL,
    description       TEXT,
    category          VARCHAR(50) NOT NULL DEFAULT 'general',
    -- category agrupa eventos para correlación (asistencia, evasion, disciplina, etc.)
    is_system         BOOLEAN NOT NULL DEFAULT TRUE,  -- TRUE = definido por NEXO
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Insertar tipos de evento del sistema (idempotente)
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
    ('RISK_ALERT_MUY_ALTA', 'Alerta de riesgo MUY ALTA',    'Alerta generada por motor de riesgo', 'sistema')
ON CONFLICT (type_code) DO UPDATE SET
    display_name = EXCLUDED.display_name,
    description  = EXCLUDED.description,
    category     = EXCLUDED.category;

-- =============================================================================
-- BLOQUE 1 (cont): Calendario lectivo institucional
-- =============================================================================
-- Permite calcular "días lectivos" para el decaimiento temporal.
-- Si no hay calendario cargado, se asume todos los días son lectivos (fallback).

CREATE TABLE IF NOT EXISTS school_calendar (
    calendar_id     UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id       UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    calendar_date   DATE NOT NULL,
    is_lecture_day  BOOLEAN NOT NULL DEFAULT TRUE,
    reason          VARCHAR(200),  -- 'feriado', 'vacaciones', 'puente', etc.
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(school_id, calendar_date)
);
CREATE INDEX IF NOT EXISTS idx_school_calendar_lookup
    ON school_calendar(school_id, calendar_date);

-- Función helper: cuenta días lectivos entre dos fechas para una escuela
CREATE OR REPLACE FUNCTION fn_count_lecture_days(
    p_school_id UUID,
    p_from_date DATE,
    p_to_date   DATE
) RETURNS INTEGER AS $$
DECLARE
    v_count INTEGER;
BEGIN
    -- Si existe calendario, contar días marcados como lectivos
    SELECT COUNT(*) INTO v_count
    FROM school_calendar
    WHERE school_id = p_school_id
      AND calendar_date BETWEEN p_from_date AND p_to_date
      AND is_lecture_day = TRUE;

    -- Si no hay calendario cargado (count es 0 pero hay días en el rango),
    -- fallback: contar días laborables (Lun-Vie) excluyendo sábado/domingo
    IF v_count = 0 THEN
        SELECT COUNT(*) INTO v_count
        FROM generate_series(p_from_date, p_to_date, '1 day'::interval) AS d
        WHERE EXTRACT(ISODOW FROM d) BETWEEN 1 AND 5;
    END IF;

    RETURN v_count;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- =============================================================================
-- BLOQUE 2: Políticas institucionales versionadas
-- =============================================================================
-- Cada cambio de política crea una NUEVA versión, nunca edita in-place.
-- Las alertas ya emitidas quedan ligadas a la versión vigente en su momento.

CREATE TABLE IF NOT EXISTS risk_policies (
    policy_id       UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id       UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    version         INTEGER NOT NULL DEFAULT 1,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    activated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deactivated_at  TIMESTAMPTZ,
    snapshot_json   JSONB NOT NULL,  -- snapshot completo de la configuración
    created_by      UUID NOT NULL REFERENCES users(user_id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    change_reason   TEXT NOT NULL,   -- obligatorio: motivo del cambio
    UNIQUE(school_id, version)
);
CREATE INDEX IF NOT EXISTS idx_risk_policies_school_active
    ON risk_policies(school_id, is_active);

-- Mapeo evento → nivel (por política)
-- La institución decide qué nivel asignar a cada tipo de evento.
CREATE TABLE IF NOT EXISTS risk_event_level_mapping (
    mapping_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    policy_id       UUID NOT NULL REFERENCES risk_policies(policy_id) ON DELETE CASCADE,
    school_id       UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    event_type_id   INTEGER NOT NULL REFERENCES risk_event_types(event_type_id),
    risk_level      VARCHAR(20) NOT NULL CHECK(risk_level IN (
                        'SIN_IMPORTANCIA','LEVE','MODERADA','ALTA','MUY_ALTA'
                    )),
    -- Override individual: si un estudiante tiene adaptación curricular
    -- se puede sobreescribir el nivel para ese estudiante específico
    student_id      UUID REFERENCES students(student_id) ON DELETE CASCADE,
    override_reason TEXT,  -- obligatorio si student_id no es NULL
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(policy_id, event_type_id, student_id)
);
CREATE INDEX IF NOT EXISTS idx_risk_mapping_policy_event
    ON risk_event_level_mapping(policy_id, event_type_id);
CREATE INDEX IF NOT EXISTS idx_risk_mapping_student
    ON risk_event_level_mapping(student_id)
    WHERE student_id IS NOT NULL;

-- Reglas por nivel (pesos, vidas medias, umbrales, cooldowns)
-- La institución puede ajustar dentro de rangos protegidos.
CREATE TABLE IF NOT EXISTS risk_rules (
    rule_id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    policy_id           UUID NOT NULL REFERENCES risk_policies(policy_id) ON DELETE CASCADE,
    school_id           UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    risk_level          VARCHAR(20) NOT NULL CHECK(risk_level IN (
                            'LEVE','MODERADA','ALTA','MUY_ALTA'
                        )),
    weight_base         NUMERIC(5,2) NOT NULL,
    half_life_days      INTEGER NOT NULL,    -- vida media en días lectivos
    activation_threshold NUMERIC(8,2) NOT NULL,  -- riesgo activo mínimo para disparar
    cooldown_days       INTEGER NOT NULL DEFAULT 0,  -- días lectivos entre alertas del mismo nivel
    single_occurrence   BOOLEAN NOT NULL DEFAULT FALSE,  -- TRUE = 1 ocurrencia dispara
    requires_human_review BOOLEAN NOT NULL DEFAULT FALSE,  -- ALTA requiere revisión humana
    -- Rangos protegidos (pisos y techos) — definidos por NEXO, no editables
    min_weight          NUMERIC(5,2) NOT NULL,
    max_weight          NUMERIC(5,2) NOT NULL,
    min_half_life       INTEGER NOT NULL,
    max_half_life       INTEGER NOT NULL,
    min_threshold       NUMERIC(8,2) NOT NULL,
    max_threshold       NUMERIC(8,2) NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(policy_id, risk_level)
);

-- =============================================================================
-- BLOQUE 3: Reglas de combinación, snapshot de riesgo, alertas, justificaciones
-- =============================================================================

-- Reglas de combinación entre categorías (Capa 4)
-- Ej: SI evasion_interna >= MODERADA Y llegadas_tarde >= MODERADA
--     EN 10 días lectivos → escalar a ALTA
CREATE TABLE IF NOT EXISTS risk_combination_rules (
    combo_rule_id    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    policy_id        UUID NOT NULL REFERENCES risk_policies(policy_id) ON DELETE CASCADE,
    school_id        UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    rule_name        VARCHAR(200) NOT NULL,
    -- Condición en JSON: categorías y niveles mínimos requeridos
    -- Ej: {"categories": [{"category": "evasion", "min_level": "MODERADA"},
    --                     {"category": "asistencia", "min_level": "MODERADA"}],
    --      "window_lecture_days": 10}
    condition_json   JSONB NOT NULL,
    -- Resultado: nivel al que escalar
    result_level     VARCHAR(20) NOT NULL CHECK(result_level IN (
                         'MODERADA','ALTA','MUY_ALTA'
                     )),
    result_reason    TEXT NOT NULL,  -- texto que aparece en la alerta
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_risk_combo_policy
    ON risk_combination_rules(policy_id, is_active);

-- Snapshot de riesgo activo por estudiante + categoría (vista derivada)
-- No es fuente de verdad — se recalcula a partir del historial.
CREATE TABLE IF NOT EXISTS risk_active_snapshot (
    snapshot_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id        UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    student_id       UUID NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    category         VARCHAR(50) NOT NULL,  -- asistencia, evasion, comportamiento, etc.
    active_score     NUMERIC(8,2) NOT NULL DEFAULT 0.00,
    event_count      INTEGER NOT NULL DEFAULT 0,
    clustering_factor NUMERIC(5,2) NOT NULL DEFAULT 1.00,  -- multiplicador por concentración
    last_event_at    TIMESTAMPTZ,
    last_calculated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    policy_id        UUID REFERENCES risk_policies(policy_id),
    metadata_json    JSONB,  -- detalle del cálculo (eventos, pesos efectivos, etc.)
    UNIQUE(school_id, student_id, category)
);
CREATE INDEX IF NOT EXISTS idx_risk_snapshot_school_score
    ON risk_active_snapshot(school_id, active_score DESC);
CREATE INDEX IF NOT EXISTS idx_risk_snapshot_student
    ON risk_active_snapshot(student_id, category);

-- Alertas pedagógicas con máquina de estados (Capa 5)
-- OBSERVACION → ALERTA_PEDAGOGICA → SEGUIMIENTO → INTERVENCION_PRIORITARIA → ATENCION_INMEDIATA
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
    -- Qué disparó la alerta
    trigger_category VARCHAR(50),  -- categoría que cruzó el umbral
    trigger_rule     TEXT,         -- descripción humana de la regla activada
    trigger_score    NUMERIC(8,2), -- score que tenía cuando se disparó
    combo_rule_id    UUID REFERENCES risk_combination_rules(combo_rule_id),
    -- Versión de política vigente al momento de generar la alerta
    policy_id        UUID NOT NULL REFERENCES risk_policies(policy_id),
    -- Eventos involucrados (referencias a attendance_incidents)
    involved_events  JSONB,  -- [{incident_id, incident_type, detected_at, weight_effective}, ...]
    -- Estado de la alerta
    status           VARCHAR(20) NOT NULL DEFAULT 'abierta' CHECK(status IN (
                         'abierta','en_seguimiento','resuelta','descartada'
                     )),
    cooldown_until   TIMESTAMPTZ,  -- hasta cuándo no se re-dispara el mismo nivel
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at      TIMESTAMPTZ,
    resolved_by      UUID REFERENCES users(user_id),
    resolution_notes TEXT,
    -- Metadatos para explicabilidad
    metadata_json    JSONB
);
CREATE INDEX IF NOT EXISTS idx_risk_alerts_school_status
    ON risk_alerts(school_id, status, alert_level);
CREATE INDEX IF NOT EXISTS idx_risk_alerts_student
    ON risk_alerts(student_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_risk_alerts_cooldown
    ON risk_alerts(student_id, alert_level, cooldown_until)
    WHERE status = 'abierta';

-- Justificaciones de eventos (Capa 1 — filtro de contexto)
-- Un evento justificado no suma al riesgo activo pero permanece en el historial.
CREATE TABLE IF NOT EXISTS risk_justifications (
    justification_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id        UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    student_id       UUID NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    -- Referencia al evento justificado (attendance_incidents es particionada,
    -- no podemos FK directo, guardamos referencia lógica)
    incident_type    VARCHAR(120) NOT NULL,
    incident_date    TIMESTAMPTZ NOT NULL,
    justified_by     UUID NOT NULL REFERENCES users(user_id),
    justified_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    justification_type VARCHAR(50) NOT NULL CHECK(justification_type IN (
                         'permiso','error_sensor','horario','medico','otro'
                     )),
    reason           TEXT NOT NULL,
    -- Tras justificar, el riesgo activo se recalcula
    recalculated     BOOLEAN NOT NULL DEFAULT FALSE,
    metadata_json    JSONB
);
CREATE INDEX IF NOT EXISTS idx_risk_justifications_student
    ON risk_justifications(student_id, incident_date DESC);

-- =============================================================================
-- BLOQUE 4: Audit log de políticas + índices adicionales
-- =============================================================================

-- Audit log de cambios de configuración (Capa 6)
-- Todo cambio de política registra snapshot anterior y nuevo.
CREATE TABLE IF NOT EXISTS risk_audit_log (
    audit_id         UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id        UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
    actor_id         UUID NOT NULL REFERENCES users(user_id),
    actor_role       VARCHAR(50),
    entity_modified  VARCHAR(100) NOT NULL,  -- ej: 'risk_policy', 'risk_event_level_mapping'
    entity_id        UUID,                    -- ID de la entidad modificada
    action           VARCHAR(50) NOT NULL,    -- 'CREATE','UPDATE','ACTIVATE','DEACTIVATE'
    previous_config  JSONB,                   -- snapshot completo anterior
    new_config       JSONB,                   -- snapshot completo nuevo
    change_reason    TEXT,
    policy_version   INTEGER,                 -- versión resultante
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_risk_audit_school_entity
    ON risk_audit_log(school_id, entity_modified, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_risk_audit_actor
    ON risk_audit_log(actor_id, created_at DESC);

-- =============================================================================
-- BLOQUE 4 (cont): RLS — Row Level Security multi-tenant
-- =============================================================================
-- Todas las tablas de riesgo están aisladas por school_id.

ALTER TABLE risk_policies ENABLE ROW LEVEL SECURITY;
ALTER TABLE risk_event_level_mapping ENABLE ROW LEVEL SECURITY;
ALTER TABLE risk_rules ENABLE ROW LEVEL SECURITY;
ALTER TABLE risk_combination_rules ENABLE ROW LEVEL SECURITY;
ALTER TABLE risk_active_snapshot ENABLE ROW LEVEL SECURITY;
ALTER TABLE risk_alerts ENABLE ROW LEVEL SECURITY;
ALTER TABLE risk_justifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE risk_audit_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE school_calendar ENABLE ROW LEVEL SECURITY;

-- risk_policies
DROP POLICY IF EXISTS risk_policies_select ON risk_policies;
CREATE POLICY risk_policies_select ON risk_policies FOR SELECT
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_policies_insert ON risk_policies;
CREATE POLICY risk_policies_insert ON risk_policies FOR INSERT
    WITH CHECK(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_policies_update ON risk_policies;
CREATE POLICY risk_policies_update ON risk_policies FOR UPDATE
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_policies_delete ON risk_policies;
CREATE POLICY risk_policies_delete ON risk_policies FOR DELETE
    USING(school_id = get_current_school_id());

-- risk_event_level_mapping
DROP POLICY IF EXISTS risk_elm_select ON risk_event_level_mapping;
CREATE POLICY risk_elm_select ON risk_event_level_mapping FOR SELECT
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_elm_insert ON risk_event_level_mapping;
CREATE POLICY risk_elm_insert ON risk_event_level_mapping FOR INSERT
    WITH CHECK(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_elm_update ON risk_event_level_mapping;
CREATE POLICY risk_elm_update ON risk_event_level_mapping FOR UPDATE
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_elm_delete ON risk_event_level_mapping;
CREATE POLICY risk_elm_delete ON risk_event_level_mapping FOR DELETE
    USING(school_id = get_current_school_id());

-- risk_rules
DROP POLICY IF EXISTS risk_rules_select ON risk_rules;
CREATE POLICY risk_rules_select ON risk_rules FOR SELECT
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_rules_insert ON risk_rules;
CREATE POLICY risk_rules_insert ON risk_rules FOR INSERT
    WITH CHECK(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_rules_update ON risk_rules;
CREATE POLICY risk_rules_update ON risk_rules FOR UPDATE
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_rules_delete ON risk_rules;
CREATE POLICY risk_rules_delete ON risk_rules FOR DELETE
    USING(school_id = get_current_school_id());

-- risk_combination_rules
DROP POLICY IF EXISTS risk_combo_select ON risk_combination_rules;
CREATE POLICY risk_combo_select ON risk_combination_rules FOR SELECT
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_combo_insert ON risk_combination_rules;
CREATE POLICY risk_combo_insert ON risk_combination_rules FOR INSERT
    WITH CHECK(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_combo_update ON risk_combination_rules;
CREATE POLICY risk_combo_update ON risk_combination_rules FOR UPDATE
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_combo_delete ON risk_combination_rules;
CREATE POLICY risk_combo_delete ON risk_combination_rules FOR DELETE
    USING(school_id = get_current_school_id());

-- risk_active_snapshot
DROP POLICY IF EXISTS risk_snapshot_select ON risk_active_snapshot;
CREATE POLICY risk_snapshot_select ON risk_active_snapshot FOR SELECT
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_snapshot_insert ON risk_active_snapshot;
CREATE POLICY risk_snapshot_insert ON risk_active_snapshot FOR INSERT
    WITH CHECK(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_snapshot_update ON risk_active_snapshot;
CREATE POLICY risk_snapshot_update ON risk_active_snapshot FOR UPDATE
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_snapshot_delete ON risk_active_snapshot;
CREATE POLICY risk_snapshot_delete ON risk_active_snapshot FOR DELETE
    USING(school_id = get_current_school_id());

-- risk_alerts
DROP POLICY IF EXISTS risk_alerts_select ON risk_alerts;
CREATE POLICY risk_alerts_select ON risk_alerts FOR SELECT
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_alerts_insert ON risk_alerts;
CREATE POLICY risk_alerts_insert ON risk_alerts FOR INSERT
    WITH CHECK(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_alerts_update ON risk_alerts;
CREATE POLICY risk_alerts_update ON risk_alerts FOR UPDATE
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_alerts_delete ON risk_alerts;
CREATE POLICY risk_alerts_delete ON risk_alerts FOR DELETE
    USING(school_id = get_current_school_id());

-- risk_justifications
DROP POLICY IF EXISTS risk_just_select ON risk_justifications;
CREATE POLICY risk_just_select ON risk_justifications FOR SELECT
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_just_insert ON risk_justifications;
CREATE POLICY risk_just_insert ON risk_justifications FOR INSERT
    WITH CHECK(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_just_update ON risk_justifications;
CREATE POLICY risk_just_update ON risk_justifications FOR UPDATE
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_just_delete ON risk_justifications;
CREATE POLICY risk_just_delete ON risk_justifications FOR DELETE
    USING(school_id = get_current_school_id());

-- risk_audit_log
DROP POLICY IF EXISTS risk_audit_select ON risk_audit_log;
CREATE POLICY risk_audit_select ON risk_audit_log FOR SELECT
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS risk_audit_insert ON risk_audit_log;
CREATE POLICY risk_audit_insert ON risk_audit_log FOR INSERT
    WITH CHECK(school_id = get_current_school_id());

-- school_calendar
DROP POLICY IF EXISTS school_calendar_select ON school_calendar;
CREATE POLICY school_calendar_select ON school_calendar FOR SELECT
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS school_calendar_insert ON school_calendar;
CREATE POLICY school_calendar_insert ON school_calendar FOR INSERT
    WITH CHECK(school_id = get_current_school_id());
DROP POLICY IF EXISTS school_calendar_update ON school_calendar;
CREATE POLICY school_calendar_update ON school_calendar FOR UPDATE
    USING(school_id = get_current_school_id());
DROP POLICY IF EXISTS school_calendar_delete ON school_calendar;
CREATE POLICY school_calendar_delete ON school_calendar FOR DELETE
    USING(school_id = get_current_school_id());

-- risk_event_types es un catálogo global (sin school_id), lectura pública
-- No necesita RLS — es información de referencia compartida.
-- Pero forzamos que solo superadmin pueda modificarla (vía app logic, no RLS).

-- =============================================================================
-- BLOQUE 6: Seed de política default + rangos protegidos por nivel
-- =============================================================================
-- Crea una política v1 default para cada escuela existente.
-- Los rangos protegidos (pisos/techos) son definidos por NEXO y no editables.

CREATE OR REPLACE FUNCTION fn_seed_default_risk_policy(p_school_id UUID, p_created_by UUID)
RETURNS UUID AS $$
DECLARE
    v_policy_id UUID;
    v_snapshot JSONB;
BEGIN
    -- Crear política v1
    INSERT INTO risk_policies (school_id, version, is_active, snapshot_json, created_by, change_reason)
    VALUES (p_school_id, 1, TRUE,
            jsonb_build_object('engine_version', '3.0', 'seed', true),
            p_created_by, 'Política inicial generada automáticamente por el sistema')
    RETURNING policy_id INTO v_policy_id;

    -- Reglas por nivel con valores default de NEXO y rangos protegidos
    -- LEVE: peso 1.0, vida media 7 días, umbral ~4.0, cooldown 5 días
    INSERT INTO risk_rules (policy_id, school_id, risk_level,
        weight_base, half_life_days, activation_threshold, cooldown_days,
        single_occurrence, requires_human_review,
        min_weight, max_weight, min_half_life, max_half_life,
        min_threshold, max_threshold)
    VALUES (v_policy_id, p_school_id, 'LEVE',
        1.0, 7, 4.0, 5, FALSE, FALSE,
        0.5, 2.0, 3, 14, 2.0, 8.0);

    -- MODERADA: peso 3.0, vida media 10 días, umbral ~9.0, cooldown 7 días
    INSERT INTO risk_rules (policy_id, school_id, risk_level,
        weight_base, half_life_days, activation_threshold, cooldown_days,
        single_occurrence, requires_human_review,
        min_weight, max_weight, min_half_life, max_half_life,
        min_threshold, max_threshold)
    VALUES (v_policy_id, p_school_id, 'MODERADA',
        3.0, 10, 9.0, 7, FALSE, FALSE,
        2.0, 5.0, 5, 21, 5.0, 15.0);

    -- ALTA: peso 6.0, vida media 15 días, umbral ~12.0, cooldown 3 días, requiere revisión
    INSERT INTO risk_rules (policy_id, school_id, risk_level,
        weight_base, half_life_days, activation_threshold, cooldown_days,
        single_occurrence, requires_human_review,
        min_weight, max_weight, min_half_life, max_half_life,
        min_threshold, max_threshold)
    VALUES (v_policy_id, p_school_id, 'ALTA',
        6.0, 15, 12.0, 3, FALSE, TRUE,
        4.0, 8.0, 7, 30, 8.0, 20.0);

    -- MUY_ALTA: peso 10.0, no decae (half_life muy alta), 1 ocurrencia, sin cooldown
    INSERT INTO risk_rules (policy_id, school_id, risk_level,
        weight_base, half_life_days, activation_threshold, cooldown_days,
        single_occurrence, requires_human_review,
        min_weight, max_weight, min_half_life, max_half_life,
        min_threshold, max_threshold)
    VALUES (v_policy_id, p_school_id, 'MUY_ALTA',
        10.0, 9999, 10.0, 0, TRUE, FALSE,
        8.0, 15.0, 9999, 9999, 10.0, 10.0);

    -- Mapeo default de eventos a niveles (recomendado por NEXO)
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

    -- Regla de combinación default: evasion + asistencia en MODERADA → ALTA
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

    -- Actualizar snapshot
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

-- =============================================================================
-- BLOQUE 7: Función principal de cálculo de riesgo con decaimiento exponencial
-- =============================================================================
-- fn_calculate_category_risk: calcula el riesgo activo de un estudiante
-- para una categoría específica, usando decaimiento exponencial + clustering.
--
-- Fórmula:
--   peso_efectivo(evento) = peso_base × e^(-λ × Δt_lectivo) × factor_contexto
--   riesgo_activo = Σ peso_efectivo(eventos) × clustering_factor
--
-- λ = ln(2) / half_life_dias_lectivos

CREATE OR REPLACE FUNCTION fn_calculate_category_risk(
    p_student_id   UUID,
    p_school_id    UUID,
    p_category     VARCHAR,
    p_policy_id    UUID,
    p_lookback_days INTEGER DEFAULT 90  -- buscar eventos hasta 90 días atrás
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
    -- Iterar sobre cada nivel (de mayor a menor severidad) para encontrar
    -- cuál nivel se activa. Cada evento se evalúa con el peso del nivel
    -- al que está mapeado su tipo de evento.
    FOR v_rule_record IN
        SELECT rr.*, elm.risk_level AS mapped_level, ret.type_code, ret.category
        FROM risk_rules rr
        JOIN risk_event_level_mapping elm
            ON elm.policy_id = rr.policy_id
            AND elm.risk_level = rr.risk_level
            AND elm.student_id IS NULL  -- solo mapeo general, no overrides
        JOIN risk_event_types ret
            ON ret.event_type_id = elm.event_type_id
        WHERE rr.policy_id = p_policy_id
          AND ret.category = p_category
          AND rr.risk_level != 'SIN_IMPORTANCIA'
        ORDER BY rr.weight_base DESC
    LOOP
        -- λ = ln(2) / half_life
        v_lambda := ln(2.0) / GREATEST(1, v_rule_record.half_life_days);

        -- Calcular score para este nivel específico
        -- Sumar peso_efectivo de todos los eventos de tipos mapeados a este nivel
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
          -- Excluir eventos justificados
          AND NOT EXISTS (
              SELECT 1 FROM risk_justifications rj
              WHERE rj.student_id = ai.student_id
                AND rj.school_id = ai.school_id
                AND rj.incident_type = ai.incident_type
                AND rj.incident_date::date = ai.detected_at::date
          );

        -- Solo considerar si hay eventos y score > 0
        IF v_event_count > 0 AND v_total_score > 0 THEN
            -- Calcular clustering: desviación estándar de intervalos entre eventos
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
                -- Calcular intervalos en días lectivos
                SELECT COALESCE(stddev(interval_days), 0),
                       COALESCE(avg(interval_days), 0)
                INTO v_stddev, v_avg_interval
                FROM (
                    SELECT fn_count_lecture_days(p_school_id,
                        v_intervals[i], v_intervals[i+1]) AS interval_days
                    FROM generate_subscripts(v_intervals, 1) AS i
                    WHERE i < array_length(v_intervals, 1)
                ) intervals;

                -- clustering = 1 + (1 / (stddev + ε)) normalizado
                -- Mayor concentración (menor stddev) = mayor multiplicador
                IF v_stddev > 0 AND v_avg_interval > 0 THEN
                    v_clustering := LEAST(2.0, 1.0 + (v_avg_interval / (v_stddev + 0.5)) * 0.3);
                ELSE
                    v_clustering := 1.5;  -- eventos muy concentrados
                END IF;
            ELSE
                v_clustering := 1.0;  -- solo 1 evento, sin clustering
            END IF;

            v_total_score := v_total_score * v_clustering;

            -- Verificar si cruza el umbral de este nivel
            IF v_total_score >= v_rule_record.activation_threshold THEN
                -- Single occurrence dispara inmediatamente
                IF v_rule_record.single_occurrence AND v_event_count >= 1 THEN
                    v_triggered_level := v_rule_record.risk_level;
                    v_best_score := v_total_score;
                    EXIT;  -- MUY_ALTA tiene prioridad
                ELSIF v_event_count >= 1 THEN
                    -- Guardar el nivel más alto que se activa
                    IF v_best_level IS NULL OR v_total_score > v_best_score THEN
                        v_triggered_level := v_rule_record.risk_level;
                        v_best_score := v_total_score;
                    END IF;
                END IF;
            END IF;
        END IF;
    END LOOP;

    -- Si ningún nivel se activó, buscar el nivel con mayor score para reportar
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

-- =============================================================================
-- BLOQUE 8: fn_evaluate_student_risk — evalúa todas las categorías + combinaciones
-- =============================================================================
-- Esta es la función principal que se llama tras cada incidente.
-- Calcula el riesgo activo por categoría, evalúa reglas de combinación,
-- y genera alertas si corresponde (respetando cooldown).

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
    v_combo_triggered BOOLEAN;
    v_combo_level VARCHAR;
    v_alert_id UUID;
    v_cooldown_until TIMESTAMPTZ;
    v_should_alert BOOLEAN := FALSE;
    v_alert_level VARCHAR;
    v_escalation VARCHAR;
    v_cooldown_days INTEGER;
BEGIN
    -- Obtener política activa de la escuela
    SELECT policy_id INTO v_policy_id
    FROM risk_policies
    WHERE school_id = p_school_id AND is_active = TRUE
    ORDER BY version DESC LIMIT 1;

    IF v_policy_id IS NULL THEN
        RETURN jsonb_build_object('error', 'no_active_policy');
    END IF;

    -- Categorías a evaluar
    v_categories := ARRAY['asistencia', 'evasion', 'comportamiento'];

    -- 1. Calcular riesgo por cada categoría
    FOREACH v_cat IN ARRAY v_categories LOOP
        SELECT active_score, event_count, clustering_factor, event_details, triggered_level
        INTO v_score, v_count, v_clustering, v_details, v_level
        FROM fn_calculate_category_risk(p_student_id, p_school_id, v_cat, v_policy_id);

        -- Actualizar snapshot
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

        -- Track del nivel máximo individual
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

    -- 2. Evaluar reglas de combinación (Capa 4)
    -- La evaluación detallada de condition_json se hace en PHP para flexibilidad.
    -- Aquí verificamos combinaciones simples basadas en el snapshot.
    FOR v_combo_record IN
        SELECT * FROM risk_combination_rules
        WHERE policy_id = v_policy_id AND is_active = TRUE
    LOOP
        -- TODO: evaluar condition_json completamente
        -- Por ahora, la evaluación de combinaciones se hace en PHP (RiskEngine.php)
        NULL;
    END LOOP;

    -- 3. Generar alerta si corresponde (Capa 5 — máquina de estados)
    IF v_max_level != 'NONE' THEN
        v_alert_level := v_max_level;
        v_escalation := CASE v_max_level
            WHEN 'LEVE'      THEN 'ALERTA_PEDAGOGICA'
            WHEN 'MODERADA'  THEN 'SEGUIMIENTO'
            WHEN 'ALTA'      THEN 'INTERVENCION_PRIORITARIA'
            WHEN 'MUY_ALTA'  THEN 'ATENCION_INMEDIATA'
        END;

        -- Verificar cooldown: no re-disparar el mismo nivel si está en cooldown
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

            -- Insertar incidente RISK_ALERT_ para compatibilidad con sistema existente
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

-- =============================================================================
-- BLOQUE 9: Trigger que reemplaza trg_recalc_risk_on_incident
-- =============================================================================
-- Tras cada incidente, evalúa el riesgo del estudiante con el nuevo motor v3.

CREATE OR REPLACE FUNCTION fn_trigger_evaluate_risk_v3()
RETURNS TRIGGER AS $$
BEGIN
    -- Solo evaluar para tipos que afectan el risk score
    -- Excluir RISK_ALERT_ para evitar recursión
    IF NEW.incident_type NOT LIKE 'RISK_ALERT%'
       AND NEW.incident_type IN (
           'LATE_ARRIVAL', 'INASISTENCIA', 'UNAUTHORIZED_ABSENCE',
           'EVASION_INTERNA', 'SALIDA_BAÑO', 'SALIDA_NO_AUTORIZADA'
       ) THEN
        -- Evaluación síncrona (el cálculo es rápido, <50ms por estudiante)
        PERFORM fn_evaluate_student_risk(NEW.student_id, NEW.school_id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SET search_path = public, pg_temp;

-- Eliminar trigger anterior (2026-38) y crear el nuevo
DROP TRIGGER IF EXISTS trg_recalc_risk_on_incident ON attendance_incidents;
DROP TRIGGER IF EXISTS trg_evaluate_risk_v3 ON attendance_incidents;

CREATE TRIGGER trg_evaluate_risk_v3
    AFTER INSERT ON attendance_incidents
    FOR EACH ROW
    EXECUTE FUNCTION fn_trigger_evaluate_risk_v3();

-- =============================================================================
-- BLOQUE 9 (cont): Función para recalcular toda una escuela
-- =============================================================================
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
            -- Log pero continuar con el siguiente estudiante
            RAISE NOTICE 'Error evaluando student %: %', v_student.student_id, SQLERRM;
        END;
    END LOOP;
    RETURN v_count;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- =============================================================================
-- BLOQUE 9 (cont): Función para justificar un evento (recalcula riesgo)
-- =============================================================================
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

    -- Recalcular riesgo del estudiante (el evento justificado ya no sumará)
    PERFORM fn_evaluate_student_risk(p_student_id, p_school_id);

    -- Marcar como recalculado
    UPDATE risk_justifications SET recalculated = TRUE WHERE justification_id = v_just_id;

    RETURN v_just_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- =============================================================================
-- Registrar migración
-- =============================================================================
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-40-risk-engine-v3.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'Motor de Análisis de Riesgo Pedagógico v3.0 — decaimiento exponencial, clustering, políticas versionadas, alertas con máquina de estados'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Sistema de 7 capas: ingesta/filtros, políticas configurables, scoring con decay, combinación, escalamiento, auditoría, decisión humana. Reemplaza los 3 motores divergentes anteriores.'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro.';
    END IF;
END $$;

-- =============================================================================
-- BLOQUE 10: Seed de políticas default para escuelas existentes
-- =============================================================================
-- Crea una política v1 default para cada escuela que no tenga una.
-- Usa el usuario del sistema (primer RECTOR/COORDINATOR de la escuela, o un UUID fijo).

DO $$
DECLARE
    v_school RECORD;
    v_user_id UUID;
    v_system_user UUID;
BEGIN
    -- Crear o obtener un usuario "sistema" para seeds
    -- Usamos un UUID fijo para idempotencia
    v_system_user := '00000000-0000-0000-0000-000000000001'::UUID;

    -- Verificar si el usuario sistema existe, si no, crearlo
    IF NOT EXISTS (SELECT 1 FROM users WHERE user_id = v_system_user) THEN
        -- No podemos crear un usuario sin datos completos, usamos el primer admin disponible
        SELECT user_id INTO v_user_id FROM users LIMIT 1;
        IF v_user_id IS NULL THEN
            RAISE NOTICE 'No hay usuarios en el sistema. Seed de políticas saltado.';
            RETURN;
        END IF;
    ELSE
        v_user_id := v_system_user;
    END IF;

    -- Para cada escuela activa sin política, crear política default
    FOR v_school IN
        SELECT s.school_id FROM schools s
        WHERE s.active = TRUE
          AND NOT EXISTS (
              SELECT 1 FROM risk_policies rp WHERE rp.school_id = s.school_id
          )
    LOOP
        BEGIN
            PERFORM fn_seed_default_risk_policy(v_school.school_id, v_user_id);
            RAISE NOTICE 'Política default creada para escuela %', v_school.school_id;
        EXCEPTION WHEN OTHERS THEN
            RAISE NOTICE 'Error creando política para escuela %: %', v_school.school_id, SQLERRM;
        END;
    END LOOP;
END $$;

-- =============================================================================
-- BLOQUE 10 (cont): Migrar datos existentes de student_behavior_metrics
-- =============================================================================
-- Los snapshots existentes se migran al nuevo modelo risk_active_snapshot.
-- Los datos viejos se conservan (no se borran) para compatibilidad.

DO $$
DECLARE
    v_sbm RECORD;
    v_policy_id UUID;
    v_category VARCHAR;
BEGIN
    -- Para cada métrica existente, crear un snapshot en el nuevo modelo
    FOR v_sbm IN
        SELECT school_id, student_id, late_count, absence_count, total_events,
               risk_score, risk_level, calculation_window_days, metadata_json
        FROM student_behavior_metrics
        WHERE calculation_window_days = 30
    LOOP
        -- Obtener política activa de la escuela
        SELECT policy_id INTO v_policy_id
        FROM risk_policies
        WHERE school_id = v_sbm.school_id AND is_active = TRUE
        ORDER BY version DESC LIMIT 1;

        IF v_policy_id IS NULL THEN
            CONTINUE;
        END IF;

        -- Mapear risk_level viejo a categoría
        -- El viejo sistema usaba un score unificado; lo migramos a 'asistencia'
        v_category := 'asistencia';

        INSERT INTO risk_active_snapshot
            (school_id, student_id, category, active_score, event_count,
             clustering_factor, last_calculated_at, policy_id, metadata_json)
        VALUES (
            v_sbm.school_id, v_sbm.student_id, v_category,
            v_sbm.risk_score, v_sbm.total_events, 1.00,
            NOW(), v_policy_id,
            jsonb_build_object(
                'migrated_from', 'student_behavior_metrics',
                'legacy_risk_level', v_sbm.risk_level,
                'late_count', v_sbm.late_count,
                'absence_count', v_sbm.absence_count,
                'window_days', v_sbm.calculation_window_days
            )
        )
        ON CONFLICT (school_id, student_id, category) DO NOTHING;
    END LOOP;

    RAISE NOTICE 'Migración de datos legacy completada.';
END $$;
