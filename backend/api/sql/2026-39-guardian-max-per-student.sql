-- =============================================================================
-- 2026-39: Constraint máximo de acudientes por estudiante (A7)
--
-- PROBLEMA:
--   No hay constraint de máximo de acudientes por estudiante en
--   guardian_student_relationships. Si se modifica el código para notificar
--   a todos los acudientes, se multiplican los SMS.
--
-- SOLUCIÓN:
--   Trigger BEFORE INSERT/UPDATE que rechaza si el estudiante ya tiene 3
--   acudientes activos. 3 es un límite razonable (padre, madre, otro).
-- =============================================================================

CREATE OR REPLACE FUNCTION fn_check_guardian_limit()
RETURNS TRIGGER AS $$
DECLARE
    current_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO current_count
    FROM guardian_student_relationships
    WHERE student_id = NEW.student_id;
    -- En UPDATE, excluir la fila actual del conteo
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

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'register_migration') THEN
        PERFORM register_migration(
            '2026-39-guardian-max-per-student.sql'::VARCHAR,
            '2026-08'::VARCHAR,
            'Constraint máximo 3 acudientes por estudiante'::TEXT,
            NULL::VARCHAR,
            CURRENT_USER::VARCHAR,
            NULL::INTEGER,
            'Trigger BEFORE INSERT/UPDATE en guardian_student_relationships que rechaza >3 acudientes por estudiante.'::TEXT
        );
    ELSE
        RAISE NOTICE 'Función register_migration no existe. Saltando registro.';
    END IF;
END $$;
