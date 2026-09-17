-- =============================================================================
-- pruebas/seed.sql — Datos semilla del entorno de pruebas NEXO.
-- =============================================================================
-- Corre DESPUÉS de schema.sql (initdb ordenado). Idempotente: usa IDs fijos
-- deterministas (uuid v5-like fijos) para poder resembrar sin duplicar.
--
-- Escuela de prueba: "IE Test NEXO" con 1 grupo, 3 estudiantes, 1 coordinador,
-- 1 docente, 1 acudiente, 1 dispositivo edge (token: nexo-test-device-token).
-- =============================================================================

DO $$
DECLARE
    v_muni   UUID := '11111111-1111-4111-8111-111111111111';
    v_school UUID := '22222222-2222-4222-8222-222222222222';
    v_dept   UUID;
    v_group  UUID := '33333333-3333-4333-8333-333333333333';
    v_dev    UUID := '44444444-4444-4444-8444-444444444444';
    v_role_coord UUID; v_role_teach UUID; v_role_guard UUID; v_role_rector UUID;
    v_coord  UUID := '55555555-5555-4555-8555-555555555551';
    v_teach  UUID := '55555555-5555-4555-8555-555555555552';
    v_guardu UUID := '55555555-5555-4555-8555-555555555553';
    v_stu1   UUID := '66666666-6666-4666-8666-666666666661';
    v_stu2   UUID := '66666666-6666-4666-8666-666666666662';
    v_stu3   UUID := '66666666-6666-4666-8666-666666666663';
    v_guard  UUID := '77777777-7777-4777-8777-777777777777';
BEGIN
    -- Departamento + municipio + escuela de prueba
    INSERT INTO departments(department_id, department_name)
    VALUES ('00000000-0000-4000-8000-000000000099','TEST-DEPT') ON CONFLICT DO NOTHING;
    SELECT department_id INTO v_dept FROM departments WHERE department_name='TEST-DEPT' LIMIT 1;

    INSERT INTO municipalities(municipality_id, department_id, municipality_name)
    VALUES (v_muni, v_dept, 'TEST-MUNI') ON CONFLICT DO NOTHING;

    INSERT INTO schools(school_id, municipality_id, dane_code, school_name, active, onboarding_completed, groups_onboarding_completed, risk_config_completed)
    VALUES (v_school, v_muni, 'TEST-001', 'IE Test NEXO', TRUE, TRUE, TRUE, TRUE)
    ON CONFLICT (dane_code) DO NOTHING;

    -- Roles
    SELECT role_id INTO v_role_coord FROM roles WHERE role_name='COORDINATOR';
    SELECT role_id INTO v_role_teach FROM roles WHERE role_name='TEACHER';
    SELECT role_id INTO v_role_guard FROM roles WHERE role_name='GUARDIAN';
    SELECT role_id INTO v_role_rector FROM roles WHERE role_name='RECTOR';

    -- Usuarios de prueba (password: test1234 → bcrypt fijo)
    INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, password_hash, password_salt, active, email_verified)
    VALUES
      (v_coord,  v_school, v_role_coord,  '9001','Coord','Prueba','coord@test.nexo','$2y$12$fxce32IvIlbhpE/.taxnWeGLXxY2fm6UhX5kvgBGS0Ftc6y8mTNsu','',TRUE,TRUE),
      (v_teach,  v_school, v_role_teach,  '9002','Docente','Prueba','teach@test.nexo','$2y$12$fxce32IvIlbhpE/.taxnWeGLXxY2fm6UhX5kvgBGS0Ftc6y8mTNsu','',TRUE,TRUE),
      (v_guardu, v_school, v_role_guard,  '9003','Acudiente','Prueba','guard@test.nexo','$2y$12$fxce32IvIlbhpE/.taxnWeGLXxY2fm6UhX5kvgBGS0Ftc6y8mTNsu','',TRUE,TRUE)
    ON CONFLICT (email) DO NOTHING;

    -- Acudiente
    INSERT INTO guardians(guardian_id, user_id, whatsapp_phone, whatsapp_phone_normalized)
    VALUES (v_guard, v_guardu, '+573000000001', '573000000001') ON CONFLICT (user_id) DO NOTHING;

    -- Grupo académico
    INSERT INTO academic_groups(group_id, school_id, group_name, grade_level, work_shift, academic_year)
    VALUES (v_group, v_school, '6-A', '6', 'mañana', EXTRACT(YEAR FROM NOW())::int)
    ON CONFLICT DO NOTHING;

    -- Estudiantes
    INSERT INTO students(student_id, school_id, document_number, first_name, last_name, active, work_shift, grade_level, biometric_exempt)
    VALUES
      (v_stu1, v_school, '8001','Ana','Estudiante', TRUE, 'mañana','6', FALSE),
      (v_stu2, v_school, '8002','Luis','Estudiante', TRUE, 'mañana','6', FALSE),
      (v_stu3, v_school, '8003','Eva','Exenta',     TRUE, 'mañana','6', TRUE)
    ON CONFLICT (school_id, document_number) DO NOTHING;

    -- Vinculación acudiente↔estudiantes (guardian_student_relationships)
    INSERT INTO guardian_student_relationships(guardian_id, student_id, relationship_type, primary_guardian)
    SELECT v_guard, s, 'TUTOR', TRUE FROM (VALUES (v_stu1),(v_stu2),(v_stu3)) AS t(s)
    WHERE NOT EXISTS (SELECT 1 FROM guardian_student_relationships WHERE guardian_id=v_guard);

    -- Asignaciones al grupo
    INSERT INTO student_group_assignments(student_id, group_id, active)
    SELECT v_stu1, v_group, TRUE WHERE NOT EXISTS (SELECT 1 FROM student_group_assignments WHERE student_id=v_stu1 AND active);
    INSERT INTO student_group_assignments(student_id, group_id, active)
    SELECT v_stu2, v_group, TRUE WHERE NOT EXISTS (SELECT 1 FROM student_group_assignments WHERE student_id=v_stu2 AND active);
    INSERT INTO student_group_assignments(student_id, group_id, active)
    SELECT v_stu3, v_group, TRUE WHERE NOT EXISTS (SELECT 1 FROM student_group_assignments WHERE student_id=v_stu3 AND active);

    -- Config de jornada (para que los detectores tengan qué evaluar)
    INSERT INTO school_schedule_config(school_id, work_shift, rotates_classrooms, entry_time, exit_time, recess_start_time, recess_end_time, onboarding_completed)
    VALUES (v_school, 'mañana', FALSE, '07:00', '13:00', '10:00', '10:30', TRUE)
    ON CONFLICT (school_id, work_shift) DO NOTHING;

    -- school_time_blocks no tiene UNIQUE — insertar solo si la jornada está vacía
    IF NOT EXISTS (SELECT 1 FROM school_time_blocks WHERE school_id = v_school AND work_shift = 'mañana') THEN
        INSERT INTO school_time_blocks(school_id, work_shift, block_number, block_name, start_time, end_time)
        VALUES (v_school, 'mañana', 1, 'Bloque 1', '07:00', '08:00'),
               (v_school, 'mañana', 2, 'Bloque 2', '08:00', '09:00'),
               (v_school, 'mañana', 3, 'Bloque 3', '09:00', '10:00'),
               (v_school, 'mañana', 4, 'Bloque 4', '10:30', '11:30'),
               (v_school, 'mañana', 5, 'Bloque 5', '11:30', '12:30');
    END IF;

    -- Dispositivo edge (token conocido: nexo-test-device-token → bcrypt)
    -- Dispositivo edge (token conocido: nexo-test-device-token → bcrypt)
    INSERT INTO edge_devices(device_id, school_id, group_id, device_name, public_key, active, configured, token_hash, ota_key, app_version, last_ping)
    VALUES (v_dev, v_school, v_group, 'Nodo Test Principal', 'test-key', TRUE, TRUE,
            '$2y$12$.RETGVgmlOvtgnNnnMTIeu6fxudueOZwco4mZsh3zqqo.jcl9GIja',
            'aabbccdd11223344aabbccdd11223344aabbccdd11223344aabbccdd11223344', '1.0.0', NOW())
    ON CONFLICT DO NOTHING;

    -- Acceso docente→grupo (para F-18 teacher alert rules)
    INSERT INTO teacher_group_access(school_id, group_id, teacher_user_id, work_shift, academic_year)
    VALUES (v_school, v_group, v_teach, 'mañana', EXTRACT(YEAR FROM NOW())::int)
    ON CONFLICT DO NOTHING;

    -- Usuarios que esperan los tests de integración (password: admin123)
    INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, password_hash, password_salt, active, email_verified)
    SELECT uuid_generate_v4(), (SELECT school_id FROM schools WHERE dane_code='000000000'), r.role_id,
           'IT-' || r.role_name, 'Test', r.role_name,
           CASE r.role_name WHEN 'RECTOR' THEN 'rector@nexo.edu' WHEN 'COORDINATOR' THEN 'coordinador@nexo.edu'
                WHEN 'TEACHER' THEN 'docente@nexo.edu' WHEN 'COUNSELOR' THEN 'psicorientador@nexo.edu'
                WHEN 'SECRETARY' THEN 'secretaria@nexo.edu' WHEN 'SECURITY' THEN 'portero@nexo.edu'
                ELSE 'auxiliar@nexo.edu' END,
           '$2y$10$ixBEl1HY/wGL5DBEHQ/q/u1fdohSvdAQnji83oWGyzSK0IFR3Yxly', '', TRUE, TRUE
    FROM roles r WHERE r.role_name IN ('RECTOR','COORDINATOR','TEACHER','COUNSELOR','SECRETARY','SECURITY','AUXILIARY')
    ON CONFLICT (email) DO NOTHING;

    -- Escuela 2: SIN onboarding (para probar el bloqueo de operación)
    INSERT INTO schools(school_id, municipality_id, dane_code, school_name, active, onboarding_completed, groups_onboarding_completed, risk_config_completed)
    VALUES ('88888888-8888-4888-8888-888888888888', v_muni, 'TEST-002', 'IE Sin Onboarding', TRUE, FALSE, FALSE, FALSE)
    ON CONFLICT (dane_code) DO NOTHING;

    -- Secretaria de la escuela sin onboarding (rol NO configurador → mensaje genérico)
    INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, password_hash, password_salt, active, email_verified)
    SELECT '55555555-5555-4555-8555-555555555554', '88888888-8888-4888-8888-888888888888', r.role_id,
           '9004','Secretaria','SinOnboard','sec@test.nexo',
           '$2y$12$fxce32IvIlbhpE/.taxnWeGLXxY2fm6UhX5kvgBGS0Ftc6y8mTNsu','',TRUE,TRUE
    FROM roles r WHERE r.role_name='SECRETARY'
    ON CONFLICT (email) DO NOTHING;

    -- Coordinador de la escuela sin onboarding (rol configurador → payload detallado)
    INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, password_hash, password_salt, active, email_verified)
    SELECT '55555555-5555-4555-8555-555555555555', '88888888-8888-4888-8888-888888888888', r.role_id,
           '9005','Coord','SinOnboard','coord2@test.nexo',
           '$2y$12$fxce32IvIlbhpE/.taxnWeGLXxY2fm6UhX5kvgBGS0Ftc6y8mTNsu','',TRUE,TRUE
    FROM roles r WHERE r.role_name='COORDINATOR'
    ON CONFLICT (email) DO NOTHING;

    -- daily_schedule_config para HOY (detectores la necesitan para correr)
    INSERT INTO daily_schedule_config(school_id, group_id, config_date, has_classes, expected_entry_time, expected_exit_time)
    VALUES (v_school, v_group, CURRENT_DATE, TRUE, '07:00', '13:00')
    ON CONFLICT (school_id, group_id, config_date) DO NOTHING;
END $$;
