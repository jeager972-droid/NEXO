-- =============================================================================
-- pruebas/seed_chat_fixture.sql — fixture aditivo para pruebas conversacionales
-- live (continuity_50, live_probe, conversaciones §31-37 del spec).
--
-- Idempotente (UUIDs fijos + ON CONFLICT / NOT EXISTS). Añade:
--   - grupos 10-A y 10-B (la conversación de aceptación usa 10A/10B)
--   - 6 estudiantes en 10-A (incl. Tomás Castaño Gutiérrez) + 2 en 10-B
--   - acudiente compartido, acceso docente a los 3 grupos (mis grupos ≠ 1)
--   - incidentes y eventos biométricos de hoy y del mes pasado
--
-- Aplicar: docker exec -i nexo-test-db-1 psql -U nexo_test -d nexo_test -f - < pruebas/seed_chat_fixture.sql
-- =============================================================================

DO $$
DECLARE
    v_school UUID := '22222222-2222-4222-8222-222222222222';
    v_teach  UUID := '55555555-5555-4555-8555-555555555552';  -- teach@test.nexo
    v_guard  UUID := '77777777-7777-4777-8777-777777777777';  -- acudiente común
    v_dev    UUID := '44444444-4444-4444-8444-444444444444';  -- nodo edge test
    v_g10a   UUID := '33333333-3333-4333-8333-3333333300a0';
    v_g10b   UUID := '33333333-3333-4333-8333-3333333300b0';
    v_stu    UUID;
    s        RECORD;
BEGIN
    -- ── Grupos 10-A / 10-B ──────────────────────────────────────────────
    INSERT INTO academic_groups(group_id, school_id, group_name, grade_level, work_shift, academic_year)
    VALUES (v_g10a, v_school, '10-A', '10', 'mañana', EXTRACT(YEAR FROM NOW())::int),
           (v_g10b, v_school, '10-B', '10', 'mañana', EXTRACT(YEAR FROM NOW())::int)
    ON CONFLICT DO NOTHING;

    -- ── Estudiantes de 10-A (6 — soporta slice primeros-5 + nav) ────────
    FOR s IN SELECT * FROM (VALUES
        ('66666666-6666-4666-8666-6666666600a1','8101','Tomás','Castaño Gutiérrez'),
        ('66666666-6666-4666-8666-6666666600a2','8102','Sara','Décima Prueba'),
        ('66666666-6666-4666-8666-6666666600a3','8103','Pedro','Diez Tercero'),
        ('66666666-6666-4666-8666-6666666600a4','8104','Lucía','Décima Cuarta'),
        ('66666666-6666-4666-8666-6666666600a5','8105','Marco','Décimo Quinto'),
        ('66666666-6666-4666-8666-6666666600a6','8106','Valeria','Décima Sexta'),
        -- 10-B
        ('66666666-6666-4666-8666-6666666600b1','8201','Óscar','Décimo B Uno'),
        ('66666666-6666-4666-8666-6666666600b2','8202','Nadia','Décima B Dos')
    ) AS t(id,doc,nom,ape) LOOP
        INSERT INTO students(student_id, school_id, document_number, first_name, last_name, active, work_shift, grade_level, biometric_exempt)
        VALUES (s.id::uuid, v_school, s.doc, s.nom, s.ape, TRUE, 'mañana', '10', FALSE)
        ON CONFLICT (school_id, document_number) DO NOTHING;

        INSERT INTO student_group_assignments(student_id, group_id, active)
        SELECT s.id::uuid, CASE WHEN s.doc LIKE '82%' THEN v_g10b ELSE v_g10a END, TRUE
        WHERE NOT EXISTS (SELECT 1 FROM student_group_assignments WHERE student_id=s.id::uuid AND active);

        INSERT INTO guardian_student_relationships(guardian_id, student_id, relationship_type, primary_guardian)
        SELECT v_guard, s.id::uuid, 'TUTOR', TRUE
        WHERE NOT EXISTS (SELECT 1 FROM guardian_student_relationships WHERE guardian_id=v_guard AND student_id=s.id::uuid);
    END LOOP;

    -- ── Acceso docente a los nuevos grupos («mis grupos» = 3) ───────────
    INSERT INTO teacher_group_access(school_id, group_id, teacher_user_id, work_shift, academic_year)
    VALUES (v_school, v_g10a, v_teach, 'mañana', EXTRACT(YEAR FROM NOW())::int),
           (v_school, v_g10b, v_teach, 'mañana', EXTRACT(YEAR FROM NOW())::int)
    ON CONFLICT DO NOTHING;

    -- ── Incidentes de hoy: 1 inasistencia (10-A) + 1 tardanza (10-B) ────
    INSERT INTO attendance_incidents(incident_id, school_id, student_id, group_id, incident_type, detected_at)
    SELECT '88888888-8888-4888-8888-888888880001', v_school, '66666666-6666-4666-8666-6666666600a2',
           v_g10a, 'INASISTENCIA', NOW()
    WHERE NOT EXISTS (SELECT 1 FROM attendance_incidents WHERE incident_id='88888888-8888-4888-8888-888888880001');
    INSERT INTO attendance_incidents(incident_id, school_id, student_id, group_id, incident_type, detected_at)
    SELECT '88888888-8888-4888-8888-888888880002', v_school, '66666666-6666-4666-8666-6666666600b1',
           v_g10b, 'LATE_ARRIVAL', NOW()
    WHERE NOT EXISTS (SELECT 1 FROM attendance_incidents WHERE incident_id='88888888-8888-4888-8888-888888880002');

    -- ── Evasiones del mes pasado (2) — para «y del último mes» ──────────
    INSERT INTO attendance_incidents(incident_id, school_id, student_id, group_id, incident_type, detected_at)
    SELECT '88888888-8888-4888-8888-888888880003', v_school, '66666666-6666-4666-8666-6666666600a1',
           v_g10a, 'EVASION_INTERNA', NOW() - INTERVAL '20 days'
    WHERE NOT EXISTS (SELECT 1 FROM attendance_incidents WHERE incident_id='88888888-8888-4888-8888-888888880003');
    INSERT INTO attendance_incidents(incident_id, school_id, student_id, group_id, incident_type, detected_at)
    SELECT '88888888-8888-4888-8888-888888880004', v_school, '66666666-6666-4666-8666-6666666600a3',
           v_g10a, 'EVASION_INTERNA', NOW() - INTERVAL '22 days'
    WHERE NOT EXISTS (SELECT 1 FROM attendance_incidents WHERE incident_id='88888888-8888-4888-8888-888888880004');

    -- ── Eventos biométricos de ingreso hoy (asistencias presentes) ──────
    FOR s IN SELECT student_id FROM students WHERE school_id=v_school AND document_number IN ('8001','8002','8101','8102','8103','8201') LOOP
        INSERT INTO biometric_events(event_id, school_id, student_id, device_id, event_type, event_result, confidence_score, event_timestamp)
        SELECT uuid_generate_v4(), v_school, s.student_id, v_dev, 'INGRESO', 'MATCH', 98.5, NOW() - INTERVAL '3 hours'
        WHERE NOT EXISTS (SELECT 1 FROM biometric_events WHERE student_id=s.student_id AND event_type='INGRESO' AND event_timestamp::date = CURRENT_DATE);
    END LOOP;
END $$;
