-- =============================================================================
-- 2026-36: Restaurar asignaciones para escuelas que ya corrieron el onboarding
-- con el código roto (antes del fix 2026-35).
--
-- PROBLEMA:
--   Las escuelas que ejecutaron POST /school/groups-onboarding con la versión
--   anterior quedaron con:
--     - student_group_assignments VACÍO (borrado y nunca repoblado)
--     - schedules VACÍO (borrado y nunca repoblado)
--     - daily_schedule_config VACÍO
--     - academic_groups SIN work_shift (la columna no existía)
--     - students SIN grade_level (la columna no existía)
--   Como groups_onboarding_completed=TRUE para el año actual, el wizard no se
--   re-dispara, así que el fix del onboarding no las ayuda.
--
-- SOLUCIÓN:
--   Esta migración hace lo que puede de forma automática:
--     1. Backfill students.grade_level desde student_group_assignments que
--        hayan sobrevivido (de años anteriores o re-creadas manualmente).
--     2. Reconstruir student_group_assignments para los grupos del año actual
--        emparejando students.grade_level con academic_groups.grade_level.
--        Solo asigna al primer grupo de cada grado (el rector puede reasignar
--        manualmente después via POST /students/bulk-assign).
--     3. Setear academic_groups.work_shift='mañana' donde sea NULL (default
--        razonable; el rector puede cambiarlo después).
--
--   NO reconstruye schedules ni teacher_group_access porque no hay forma de
--   inferir qué docente tenía qué grupo. El rector debe re-asignar docentes
--   via POST /school/assign-teacher o re-correr el onboarding.
--
--   NOTA: Esta migración es idempotente y segura de re-ejecutar.
-- =============================================================================

-- 1. Backfill students.grade_level desde student_group_assignments sobrevivientes
UPDATE students s
SET grade_level = sub.grade_level
FROM (
    SELECT DISTINCT ON (sga.student_id) sga.student_id, ag.grade_level
    FROM student_group_assignments sga
    JOIN academic_groups ag ON ag.group_id = sga.group_id
    WHERE sga.active = TRUE AND ag.grade_level IS NOT NULL
    ORDER BY sga.student_id, ag.academic_year DESC
) sub
WHERE s.student_id = sub.student_id
  AND s.grade_level IS NULL
  AND s.deleted_at IS NULL;

-- 2. Setear work_shift='mañana' donde sea NULL en academic_groups
UPDATE academic_groups
SET work_shift = 'mañana'
WHERE work_shift IS NULL;

-- 3. Reconstruir student_group_assignments para grupos del año actual
--    Empareja students.grade_level con academic_groups.grade_level.
--    Asigna al primer grupo de cada grado (ordenado por group_name).
INSERT INTO student_group_assignments (student_id, group_id, active, start_date)
SELECT s.student_id, g.group_id, TRUE, CURRENT_DATE
FROM students s
JOIN LATERAL (
    SELECT ag.group_id
    FROM academic_groups ag
    WHERE ag.school_id = s.school_id
      AND ag.academic_year = EXTRACT(YEAR FROM NOW())::INT
      AND ag.grade_level = s.grade_level
    ORDER BY ag.group_name
    LIMIT 1
) g ON TRUE
WHERE s.grade_level IS NOT NULL
  AND s.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM student_group_assignments sga
      WHERE sga.student_id = s.student_id AND sga.group_id = g.group_id AND sga.active = TRUE
  )
ON CONFLICT (student_id, group_id) DO UPDATE SET active = TRUE, start_date = CURRENT_DATE;

-- 4. Registrar migración
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-36-restore-assignments.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'Restaurar student_group_assignments para escuelas que corrieron el onboarding roto'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Backfill students.grade_level, setea academic_groups.work_shift default, reconstruye student_group_assignments por grade_level. No reconstruye schedules/teacher_group_access (requiere re-asignación manual).'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro de migración.';
    END IF;
END $$;
