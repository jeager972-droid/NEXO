-- =============================================================================
-- 2026-35: Restaurar asignaciones tras onboarding (fix bugs #1, #2, #3, #4)
--
-- PROBLEMA:
--   POST /school/groups-onboarding borra student_group_assignments, schedules y
--   daily_schedule_config al recrear grupos, pero NUNCA los repuebla. Resultado:
--   - Docentes pierden acceso a todos sus grupos (schedules vacío).
--   - Estudiantes quedan sin grupo (student_group_assignments vacío).
--   - Dashboard del docente, citaciones, operaciones, detección de evasiones y
--     match biométrico se rompen (todos leen schedules + sga).
--   - Secretaria/coordinador filtran estudiantes por grupo y ven listas vacías.
--   Adicionalmente, academic_groups no tenía work_shift, así que no había forma
--   de validar que un coordinador solo vea grupos de su jornada.
--
-- SOLUCIÓN:
--   1. academic_groups: + work_shift VARCHAR(50) (mañana/tarde/noche/completa).
--      Permite que el onboarding asigne jornada por grado y que coordinadores
--      filtren grupos por su propia work_shift (users.work_shift).
--   2. Nueva tabla teacher_group_access: relación ligera (teacher_user_id,
--      group_id, work_shift) que representa "este docente tiene acceso a este
--      grupo este año electivo". Es INDEPENDIENTE de schedules (que modela
--      horarios reales con aula, materia, día, bloque). Las queries de acceso
--      (dashboard, consultations, operations, workers) se repuntan aquí.
--      Razón: schedules exige classroom_id + subject_id NOT NULL; usarlo para
--      "acceso" obligaría a crear filas dummy o a debilitar constraints.
--      teacher_group_access es la abstracción correcta y escalable.
--   3. students: + grade_level VARCHAR(50). Permite reconstruir
--      student_group_assignments por grade_level tras un onboarding sin
--      depender de un snapshot previo (robustez frente a futuros re-onboardings).
--   4. RLS + índices + constraint único para teacher_group_access.
--
-- NOTA: Esta migración solo cambia el schema. La lógica de repoblación va en
--       school_config.php (POST /school/groups-onboarding) y la recuperación
--       de datos ya perdidos en 2026-36-restore-assignments.sql.
-- =============================================================================

-- 1. academic_groups: añadir work_shift
ALTER TABLE academic_groups ADD COLUMN IF NOT EXISTS work_shift VARCHAR(50) DEFAULT 'mañana';
COMMENT ON COLUMN academic_groups.work_shift IS 'Jornada del grupo: mañana, tarde, noche, completa. Asignada en el onboarding por grado.';
CREATE INDEX IF NOT EXISTS idx_groups_school_year_shift ON academic_groups(school_id, academic_year, work_shift);

-- 2. students: añadir grade_level (para reconstruir asignaciones tras onboarding)
ALTER TABLE students ADD COLUMN IF NOT EXISTS grade_level VARCHAR(50);
COMMENT ON COLUMN students.grade_level IS 'Grado actual del estudiante. Sincronizado al asignar grupo. Permite reconstruir student_group_assignments tras onboarding.';
CREATE INDEX IF NOT EXISTS idx_students_school_grade ON students(school_id, grade_level) WHERE deleted_at IS NULL;

-- 3. Nueva tabla: teacher_group_access
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
COMMENT ON COLUMN teacher_group_access.academic_year IS 'Año electivo. Permite conservar asignaciones históricas al re-hacer onboarding.';

-- 4. RLS para teacher_group_access (solo si las funciones helper existen)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'get_current_school_id') THEN
        ALTER TABLE teacher_group_access ENABLE ROW LEVEL SECURITY;
        DROP POLICY IF EXISTS tga_select ON teacher_group_access;
        CREATE POLICY tga_select ON teacher_group_access
            FOR SELECT USING (school_id = get_current_school_id() OR get_current_role() = 'SYSTEM_WORKER');
        DROP POLICY IF EXISTS tga_insert ON teacher_group_access;
        CREATE POLICY tga_insert ON teacher_group_access
            FOR INSERT WITH CHECK (school_id = get_current_school_id());
        DROP POLICY IF EXISTS tga_update ON teacher_group_access;
        CREATE POLICY tga_update ON teacher_group_access
            FOR UPDATE USING (school_id = get_current_school_id());
        DROP POLICY IF EXISTS tga_delete ON teacher_group_access;
        CREATE POLICY tga_delete ON teacher_group_access
            FOR DELETE USING (school_id = get_current_school_id());
    ELSE
        RAISE NOTICE 'Funciones get_current_* no existen. Saltando RLS para teacher_group_access.';
    END IF;
END $$;

-- 5. Registrar migración
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-35-onboarding-restore-assignments.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'Restaurar asignaciones tras onboarding: work_shift en academic_groups, grade_level en students, tabla teacher_group_access'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Fix bugs #1/#2/#3/#4: onboarding destruye asignaciones y no las recrea. Separa acceso docente (teacher_group_access) de horarios reales (schedules).'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro de migración.';
    END IF;
END $$;
