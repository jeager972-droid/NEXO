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

    -- ── Rector del colegio fixture — las baterías de rol global necesitan
    -- un RECTOR dentro de la escuela de datos (rector@nexo.edu vive en otro
    -- tenant). Misma credencial de prueba que teach/coord.
    INSERT INTO users(user_id, school_id, role_id, first_name, last_name, document_number, email, phone, active, password_hash, password_salt, created_at)
    SELECT '55555555-5555-4555-8555-555555555550', v_school,
           (SELECT role_id FROM roles WHERE role_name='RECTOR' LIMIT 1),
           'Rector','Prueba Test','9000','rector@test.nexo','+573000000000',TRUE,
           u.password_hash, u.password_salt, NOW()
    FROM users u WHERE u.email='teach@test.nexo'
    ON CONFLICT (user_id) DO NOTHING;
END $$;

-- =============================================================================
-- Bloque 2 — casos SCP (transcript real): María Fernanda, Juan Camilo,
-- 8-C para comparación, ranking diferenciado de faltas, umbral de riesgo.
-- =============================================================================
DO $$
DECLARE
    v_school UUID := '22222222-2222-4222-8222-222222222222';
    v_teach  UUID := '55555555-5555-4555-8555-555555555552';
    v_dev    UUID := '44444444-4444-4444-8444-444444444444';
    v_g10a   UUID := '33333333-3333-4333-8333-3333333300a0';
    v_g8c    UUID := '33333333-3333-4333-8333-3333333308c0';
    v_role_g UUID;
    v_mf     UUID := '66666666-6666-4666-8666-6666666600a7';
    v_jc     UUID := '66666666-6666-4666-8666-6666666600a8';
    v_gmf    UUID := '77777777-7777-4777-8777-7777777777a7';
    v_gjc    UUID := '77777777-7777-4777-8777-7777777777a8';
    v_ugmf   UUID := '99999999-9999-4999-8999-9999999999a7';
    v_ugjc   UUID := '99999999-9999-4999-8999-9999999999a8';
    s        RECORD;
    d        RECORD;
BEGIN
    -- ── Grupo 8-C + acceso docente (caso N: comparación 10A vs 8C) ──────
    INSERT INTO academic_groups(group_id, school_id, group_name, grade_level, work_shift, academic_year)
    VALUES (v_g8c, v_school, '8-C', '8', 'mañana', EXTRACT(YEAR FROM NOW())::int)
    ON CONFLICT DO NOTHING;
    INSERT INTO teacher_group_access(school_id, group_id, teacher_user_id, work_shift, academic_year)
    VALUES (v_school, v_g8c, v_teach, 'mañana', EXTRACT(YEAR FROM NOW())::int)
    ON CONFLICT DO NOTHING;

    -- ── Estudiantes de 8-C (sin incidentes — para «ninguna» del caso N) ──
    FOR s IN SELECT * FROM (VALUES
        ('66666666-6666-4666-8666-6666666608c1','8301','Camila','Octava C Uno'),
        ('66666666-6666-4666-8666-6666666608c2','8302','Andrés','Octava C Dos')
    ) AS t(id,doc,nom,ape) LOOP
        INSERT INTO students(student_id, school_id, document_number, first_name, last_name, active, work_shift, grade_level, biometric_exempt)
        VALUES (s.id::uuid, v_school, s.doc, s.nom, s.ape, TRUE, 'mañana', '8', FALSE)
        ON CONFLICT (school_id, document_number) DO NOTHING;
        INSERT INTO student_group_assignments(student_id, group_id, active)
        SELECT s.id::uuid, v_g8c, TRUE
        WHERE NOT EXISTS (SELECT 1 FROM student_group_assignments WHERE student_id=s.id::uuid AND active);
    END LOOP;

    -- ── María Fernanda Castaño Muñoz (10-A) + Juan Camilo Ospina ────────
    FOR s IN SELECT * FROM (VALUES
        (v_mf,'8107','María Fernanda','Castaño Muñoz'),
        (v_jc,'8108','Juan Camilo','Ospina García')
    ) AS t(id,doc,nom,ape) LOOP
        INSERT INTO students(student_id, school_id, document_number, first_name, last_name, active, work_shift, grade_level, biometric_exempt)
        VALUES (s.id, v_school, s.doc, s.nom, s.ape, TRUE, 'mañana', '10', FALSE)
        ON CONFLICT (school_id, document_number) DO NOTHING;
        INSERT INTO student_group_assignments(student_id, group_id, active)
        SELECT s.id, v_g10a, TRUE
        WHERE NOT EXISTS (SELECT 1 FROM student_group_assignments WHERE student_id=s.id AND active);
    END LOOP;

    -- ── Acudientes propios (usuarios + guardians + relación) ────────────
    SELECT role_id INTO v_role_g FROM roles WHERE role_name='GUARDIAN' LIMIT 1;
    FOR s IN SELECT * FROM (VALUES
        (v_ugmf, v_gmf, '9004', 'María Custodia', 'Muñoz López', '+573000000002'),
        (v_ugjc, v_gjc, '9005', 'Alfonso', 'Ospina Ruiz', '+573000000003')
    ) AS t(uid,gid,doc,nom,ape,tel) LOOP
        INSERT INTO users(user_id, school_id, role_id, first_name, last_name, document_number, phone, active, password_hash, password_salt, created_at)
        -- hash/salt placeholder NO funcionales: este usuario nunca inicia sesión
        VALUES (s.uid, v_school, v_role_g, s.nom, s.ape, s.doc, s.tel, TRUE, '$2y$12$fixtureplaceholderhashneverlogin.', 'fixture', NOW())
        ON CONFLICT (user_id) DO NOTHING;
        INSERT INTO guardians(guardian_id, user_id, whatsapp_phone, whatsapp_phone_normalized)
        VALUES (s.gid, s.uid, s.tel, s.tel)
        ON CONFLICT (guardian_id) DO NOTHING;
    END LOOP;
    INSERT INTO guardian_student_relationships(guardian_id, student_id, relationship_type, primary_guardian)
    SELECT v_gmf, v_mf, 'MADRE', TRUE
    WHERE NOT EXISTS (SELECT 1 FROM guardian_student_relationships WHERE guardian_id=v_gmf AND student_id=v_mf);
    INSERT INTO guardian_student_relationships(guardian_id, student_id, relationship_type, primary_guardian)
    SELECT v_gjc, v_jc, 'PADRE', TRUE
    WHERE NOT EXISTS (SELECT 1 FROM guardian_student_relationships WHERE guardian_id=v_gjc AND student_id=v_jc);

    -- ── Incidentes: ranking de faltas diferenciado + casos C/D/G ────────
    -- Idempotencia entre corridas/días: los incidentes de JC y MF son
    -- datos del fixture — se reemplazan, nunca se acumulan (un re-seed al
    -- otro día NO debe duplicar las faltas esperadas por las suites).
    DELETE FROM attendance_incidents
    WHERE student_id IN (v_jc, v_mf)
      AND incident_type IN ('INASISTENCIA','EVASION_INTERNA','LATE_ARRIVAL');
    -- Juan Camilo: 4 faltas (15d) + 1 tardanza → caso G = «cuánto ha faltado… 15 días» = 4
    FOR d IN SELECT * FROM (VALUES (2),(5),(9),(13)) AS t(days) LOOP
        INSERT INTO attendance_incidents(incident_id, school_id, student_id, group_id, incident_type, detected_at)
        SELECT uuid_generate_v4(), v_school, v_jc, v_g10a, 'INASISTENCIA', NOW() - (d.days || ' days')::interval
        WHERE NOT EXISTS (SELECT 1 FROM attendance_incidents WHERE student_id=v_jc AND incident_type='INASISTENCIA'
                          AND detected_at::date = (NOW() - (d.days || ' days')::interval)::date);
    END LOOP;
    -- María Fernanda: 2 faltas + 2 evasiones (30d) + 1 tardanza → caso D
    FOR d IN SELECT * FROM (VALUES (7),(11)) AS t(days) LOOP
        INSERT INTO attendance_incidents(incident_id, school_id, student_id, group_id, incident_type, detected_at)
        SELECT uuid_generate_v4(), v_school, v_mf, v_g10a, 'INASISTENCIA', NOW() - (d.days || ' days')::interval
        WHERE NOT EXISTS (SELECT 1 FROM attendance_incidents WHERE student_id=v_mf AND incident_type='INASISTENCIA'
                          AND detected_at::date = (NOW() - (d.days || ' days')::interval)::date);
    END LOOP;
    FOR d IN SELECT * FROM (VALUES (5),(12)) AS t(days) LOOP
        INSERT INTO attendance_incidents(incident_id, school_id, student_id, group_id, incident_type, detected_at)
        SELECT uuid_generate_v4(), v_school, v_mf, v_g10a, 'EVASION_INTERNA', NOW() - (d.days || ' days')::interval
        WHERE NOT EXISTS (SELECT 1 FROM attendance_incidents WHERE student_id=v_mf AND incident_type='EVASION_INTERNA'
                          AND detected_at::date = (NOW() - (d.days || ' days')::interval)::date);
    END LOOP;
    INSERT INTO attendance_incidents(incident_id, school_id, student_id, group_id, incident_type, detected_at)
    SELECT uuid_generate_v4(), v_school, v_mf, v_g10a, 'LATE_ARRIVAL', NOW() - INTERVAL '6 days'
    WHERE NOT EXISTS (SELECT 1 FROM attendance_incidents WHERE student_id=v_mf AND incident_type='LATE_ARRIVAL');
    INSERT INTO attendance_incidents(incident_id, school_id, student_id, group_id, incident_type, detected_at)
    SELECT uuid_generate_v4(), v_school, v_jc, v_g10a, 'LATE_ARRIVAL', NOW() - INTERVAL '3 days'
    WHERE NOT EXISTS (SELECT 1 FROM attendance_incidents WHERE student_id=v_jc AND incident_type='LATE_ARRIVAL');

    -- ── Umbral de riesgo (caso I: «pasado mi umbral de alerta») ─────────
    INSERT INTO student_behavior_metrics(school_id, student_id, late_count, absence_count, total_events, risk_score, risk_level, calculation_window_days)
    VALUES (v_school, v_mf, 1, 2, 5, 85.0, 'CRITICAL', 30)
    ON CONFLICT (student_id, calculation_window_days) DO UPDATE
        SET late_count=EXCLUDED.late_count, absence_count=EXCLUDED.absence_count,
            total_events=EXCLUDED.total_events, risk_score=EXCLUDED.risk_score,
            risk_level=EXCLUDED.risk_level, calculated_at=NOW();
    INSERT INTO student_behavior_metrics(school_id, student_id, late_count, absence_count, total_events, risk_score, risk_level, calculation_window_days)
    VALUES (v_school, v_jc, 1, 4, 6, 78.0, 'HIGH', 30)
    ON CONFLICT (student_id, calculation_window_days) DO UPDATE
        SET late_count=EXCLUDED.late_count, absence_count=EXCLUDED.absence_count,
            total_events=EXCLUDED.total_events, risk_score=EXCLUDED.risk_score,
            risk_level=EXCLUDED.risk_level, calculated_at=NOW();

    -- ── Sofía Herrera Ruiz (10-A) + acudiente propia — golden §15 t9 ───
    INSERT INTO students(student_id, school_id, document_number, first_name, last_name, active, work_shift, grade_level, biometric_exempt)
    VALUES ('66666666-6666-4666-8666-6666666600a9', v_school, '8109', 'Sofía', 'Herrera Ruiz', TRUE, 'mañana', '10', FALSE)
    ON CONFLICT (school_id, document_number) DO NOTHING;
    INSERT INTO student_group_assignments(student_id, group_id, active)
    SELECT '66666666-6666-4666-8666-6666666600a9', v_g10a, TRUE
    WHERE NOT EXISTS (SELECT 1 FROM student_group_assignments WHERE student_id='66666666-6666-4666-8666-6666666600a9' AND active);
    INSERT INTO users(user_id, school_id, role_id, first_name, last_name, document_number, phone, active, password_hash, password_salt, created_at)
    VALUES ('99999999-9999-4999-8999-9999999999a9', v_school, v_role_g, 'Paola', 'Ruiz Ríos', '9006', '+573000000004', TRUE, '$2y$12$fixtureplaceholderhashneverlogin.', 'fixture', NOW())
    ON CONFLICT (user_id) DO NOTHING;
    INSERT INTO guardians(guardian_id, user_id, whatsapp_phone, whatsapp_phone_normalized)
    VALUES ('77777777-7777-4777-8777-7777777777a9', '99999999-9999-4999-8999-9999999999a9', '+573000000004', '+573000000004')
    ON CONFLICT (guardian_id) DO NOTHING;
    INSERT INTO guardian_student_relationships(guardian_id, student_id, relationship_type, primary_guardian)
    SELECT '77777777-7777-4777-8777-7777777777a9', '66666666-6666-4666-8666-6666666600a9', 'MADRE', TRUE
    WHERE NOT EXISTS (SELECT 1 FROM guardian_student_relationships WHERE guardian_id='77777777-7777-4777-8777-7777777777a9' AND student_id='66666666-6666-4666-8666-6666666600a9');

    -- ── Ingresos de hoy (presentes hoy — coherente con el resto) ────────
    FOR s IN SELECT student_id FROM students WHERE school_id=v_school AND document_number IN ('8107','8108') LOOP
        INSERT INTO biometric_events(event_id, school_id, student_id, device_id, event_type, event_result, confidence_score, event_timestamp)
        SELECT uuid_generate_v4(), v_school, s.student_id, v_dev, 'INGRESO', 'MATCH', 98.5, NOW() - INTERVAL '3 hours'
        WHERE NOT EXISTS (SELECT 1 FROM biometric_events WHERE student_id=s.student_id AND event_type='INGRESO' AND event_timestamp::date = CURRENT_DATE);
    END LOOP;
END $$;
