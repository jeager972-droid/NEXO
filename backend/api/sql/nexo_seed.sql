-- =============================================================================
-- NEXO SEED DATA — Datos comprehensivos para demo/desarrollo
-- =============================================================================
-- Puebla TODAS las tablas. Garantiza métricas del dashboard siempre muestren:
--   - 4 INGRESO + 2 INGRESO_TARDE por grupo hoy
--   - 2 INASISTENCIA + 1 PERMISO + 1 AUTORIZAR_SALIDA por grupo hoy
--   - SOS alerts (activas y resueltas), eventos históricos 30 días
-- Idempotente: TRUNCATE todas las tablas antes de inserts (seed completamente nuevo).
-- Password para todos: admin123
-- =============================================================================

-- =============================================================================
-- CLEANUP TOTAL — TRUNCATE todas las tablas para un seed completamente nuevo
-- No usar CASCADE en tablas particionadas (PostgreSQL maneja sus particiones)
-- =============================================================================
TRUNCATE TABLE student_tracking_notes RESTART IDENTITY CASCADE;
TRUNCATE TABLE student_tracking RESTART IDENTITY CASCADE;
TRUNCATE TABLE student_behavior_metrics RESTART IDENTITY CASCADE;
TRUNCATE TABLE report_exports RESTART IDENTITY CASCADE;
TRUNCATE TABLE internal_messages RESTART IDENTITY CASCADE;
TRUNCATE TABLE pedagogical_trip_authorizations RESTART IDENTITY CASCADE;
TRUNCATE TABLE school_exit_authorizations RESTART IDENTITY CASCADE;
TRUNCATE TABLE class_exit_authorizations RESTART IDENTITY CASCADE;
TRUNCATE TABLE student_record_audit RESTART IDENTITY CASCADE;
TRUNCATE TABLE global_audit_logs RESTART IDENTITY CASCADE;
TRUNCATE TABLE security_incidents RESTART IDENTITY CASCADE;
TRUNCATE TABLE user_commands RESTART IDENTITY CASCADE;
TRUNCATE TABLE twilio_messages RESTART IDENTITY CASCADE;
TRUNCATE TABLE twilio_message_types RESTART IDENTITY CASCADE;
TRUNCATE TABLE sos_alerts RESTART IDENTITY CASCADE;
TRUNCATE TABLE attendance_incidents RESTART IDENTITY CASCADE;
TRUNCATE TABLE biometric_events RESTART IDENTITY CASCADE;
TRUNCATE TABLE notifications RESTART IDENTITY CASCADE;
TRUNCATE TABLE schedules RESTART IDENTITY CASCADE;
TRUNCATE TABLE staff_records RESTART IDENTITY CASCADE;
TRUNCATE TABLE guardian_student_relationships RESTART IDENTITY CASCADE;
TRUNCATE TABLE guardians RESTART IDENTITY CASCADE;
TRUNCATE TABLE student_group_assignments RESTART IDENTITY CASCADE;
TRUNCATE TABLE students RESTART IDENTITY CASCADE;
TRUNCATE TABLE edge_devices RESTART IDENTITY CASCADE;
TRUNCATE TABLE academic_groups RESTART IDENTITY CASCADE;
TRUNCATE TABLE classrooms RESTART IDENTITY CASCADE;
TRUNCATE TABLE subjects RESTART IDENTITY CASCADE;
TRUNCATE TABLE user_sessions RESTART IDENTITY CASCADE;
TRUNCATE TABLE verification_codes RESTART IDENTITY CASCADE;
TRUNCATE TABLE school_panic_events RESTART IDENTITY CASCADE;
TRUNCATE TABLE system_telemetry RESTART IDENTITY CASCADE;
TRUNCATE TABLE contact_leads RESTART IDENTITY CASCADE;
TRUNCATE TABLE rate_limits RESTART IDENTITY CASCADE;
TRUNCATE TABLE jwt_blocklist RESTART IDENTITY CASCADE;
TRUNCATE TABLE role_permissions RESTART IDENTITY CASCADE;
TRUNCATE TABLE permissions RESTART IDENTITY CASCADE;
TRUNCATE TABLE users RESTART IDENTITY CASCADE;
TRUNCATE TABLE roles RESTART IDENTITY CASCADE;
TRUNCATE TABLE schools RESTART IDENTITY CASCADE;
TRUNCATE TABLE municipalities RESTART IDENTITY CASCADE;
TRUNCATE TABLE departments RESTART IDENTITY CASCADE;

-- 1. GEOGRAFÍA Y ESCUELA
INSERT INTO departments(department_id, department_name) VALUES('d1111111-1111-1111-1111-111111111111'::UUID,'Cundinamarca');
INSERT INTO municipalities(municipality_id, department_id, municipality_name) VALUES('a2222222-2222-2222-2222-222222222222'::UUID,'d1111111-1111-1111-1111-111111111111'::UUID,'Bogotá D.C.');
INSERT INTO schools(school_id, municipality_id, dane_code, school_name, address, phone, email, active)
VALUES('a3333333-3333-3333-3333-333333333333'::UUID,'a2222222-2222-2222-2222-222222222222'::UUID,'111001000001','Colegio Nacional','Carrera 7 # 32-12','6012345678','contacto@colegionacional.edu.co',TRUE);

-- 2. ROLES Y PERMISOS
INSERT INTO roles(role_id, role_name, description) VALUES
    (gen_random_uuid(),'RECTOR','Director'),
    (gen_random_uuid(),'COORDINATOR','Coordinador'),
    (gen_random_uuid(),'TEACHER','Docente'),
    (gen_random_uuid(),'SECRETARY','Secretaria'),
    (gen_random_uuid(),'SECURITY','Portero'),
    (gen_random_uuid(),'AUXILIARY','Auxiliar'),
    (gen_random_uuid(),'COUNSELOR','Psicorientador'),
    (gen_random_uuid(),'GUARDIAN','Acudiente')
ON CONFLICT(role_name) DO NOTHING;

INSERT INTO permissions(permission_id, permission_code, description) VALUES
    (gen_random_uuid(),'dashboard.teacher_view','View dashboard filtered by assigned groups'),
    (gen_random_uuid(),'dashboard.global_view','View full institution dashboard'),
    (gen_random_uuid(),'operations.sos','Generate SOS alert'),
    (gen_random_uuid(),'operations.inasistencia','Report absence and notify guardian'),
    (gen_random_uuid(),'operations.citacion','Send guardian citation via WhatsApp'),
    (gen_random_uuid(),'operations.autorizar_salida','Authorize student exit'),
    (gen_random_uuid(),'operations.permiso','Generate class exit permission'),
    (gen_random_uuid(),'operations.solicitud','Send internal request to another user'),
    (gen_random_uuid(),'operations.daño','Report institutional damage'),
    (gen_random_uuid(),'operations.pedagogica','Register group pedagogical exit'),
    (gen_random_uuid(),'operations.horario','Notify group schedule change'),
    (gen_random_uuid(),'operations.incidente','Report disciplinary incident'),
    (gen_random_uuid(),'operations.seguimiento','Request counselor tracking'),
    (gen_random_uuid(),'consultations.teacher_view','View queries filtered by assigned groups'),
    (gen_random_uuid(),'consultations.global_view','View all institution queries'),
    (gen_random_uuid(),'reports.preview','View biometric report preview'),
    (gen_random_uuid(),'reports.export','Export institutional reports'),
    (gen_random_uuid(),'devices.manage','Register, revoke and command EDGE devices'),
    (gen_random_uuid(),'devices.admin_health','View global device health check'),
    (gen_random_uuid(),'audit.view','View full audit modules'),
    (gen_random_uuid(),'audit.integrity','Validate audit hash chain integrity'),
    (gen_random_uuid(),'security.panic','Activate emergency panic mode'),
    (gen_random_uuid(),'admin.recalc_risk','Recalculate student risk metrics'),
    (gen_random_uuid(),'admin.users_manage','Manage system users'),
    (gen_random_uuid(),'students.create','Create and edit students'),
    (gen_random_uuid(),'students.view','View student list'),
    (gen_random_uuid(),'tracking.manage','Manage student tracking cases'),
    (gen_random_uuid(),'behavior.view_risk','View behavioral risk metrics')
ON CONFLICT(permission_code) DO NOTHING;

-- Role-permission assignments
DO $$
DECLARE v_r UUID; v_p UUID;
BEGIN
  SELECT role_id INTO v_r FROM roles WHERE role_name='RECTOR' LIMIT 1;
  FOR v_p IN SELECT permission_id FROM permissions LOOP
    INSERT INTO role_permissions(role_permission_id,role_id,permission_id) VALUES(gen_random_uuid(),v_r,v_p) ON CONFLICT DO NOTHING;
  END LOOP;
  SELECT role_id INTO v_r FROM roles WHERE role_name='COORDINATOR' LIMIT 1;
  FOR v_p IN SELECT permission_id FROM permissions p WHERE p.permission_code NOT IN ('admin.recalc_risk','admin.users_manage') LOOP
    INSERT INTO role_permissions(role_permission_id,role_id,permission_id) VALUES(gen_random_uuid(),v_r,v_p) ON CONFLICT DO NOTHING;
  END LOOP;
  SELECT role_id INTO v_r FROM roles WHERE role_name='TEACHER' LIMIT 1;
  FOR v_p IN SELECT permission_id FROM permissions p WHERE p.permission_code IN ('dashboard.teacher_view','operations.inasistencia','operations.citacion','operations.permiso','operations.pedagogica','operations.horario','operations.incidente','operations.seguimiento','consultations.teacher_view','reports.preview','students.view','behavior.view_risk') LOOP
    INSERT INTO role_permissions(role_permission_id,role_id,permission_id) VALUES(gen_random_uuid(),v_r,v_p) ON CONFLICT DO NOTHING;
  END LOOP;
  SELECT role_id INTO v_r FROM roles WHERE role_name='SECRETARY' LIMIT 1;
  FOR v_p IN SELECT permission_id FROM permissions p WHERE p.permission_code IN ('dashboard.global_view','students.create','students.view','consultations.global_view','reports.preview','reports.export','operations.solicitud') LOOP
    INSERT INTO role_permissions(role_permission_id,role_id,permission_id) VALUES(gen_random_uuid(),v_r,v_p) ON CONFLICT DO NOTHING;
  END LOOP;
  SELECT role_id INTO v_r FROM roles WHERE role_name='COUNSELOR' LIMIT 1;
  FOR v_p IN SELECT permission_id FROM permissions p WHERE p.permission_code IN ('dashboard.teacher_view','tracking.manage','behavior.view_risk','consultations.teacher_view','consultations.global_view','operations.seguimiento','students.view') LOOP
    INSERT INTO role_permissions(role_permission_id,role_id,permission_id) VALUES(gen_random_uuid(),v_r,v_p) ON CONFLICT DO NOTHING;
  END LOOP;
  SELECT role_id INTO v_r FROM roles WHERE role_name='SECURITY' LIMIT 1;
  FOR v_p IN SELECT permission_id FROM permissions p WHERE p.permission_code IN ('students.view','consultations.global_view','reports.preview') LOOP
    INSERT INTO role_permissions(role_permission_id,role_id,permission_id) VALUES(gen_random_uuid(),v_r,v_p) ON CONFLICT DO NOTHING;
  END LOOP;
  SELECT role_id INTO v_r FROM roles WHERE role_name='AUXILIARY' LIMIT 1;
  FOR v_p IN SELECT permission_id FROM permissions p WHERE p.permission_code IN ('dashboard.global_view','students.view','consultations.global_view','reports.preview','operations.solicitud') LOOP
    INSERT INTO role_permissions(role_permission_id,role_id,permission_id) VALUES(gen_random_uuid(),v_r,v_p) ON CONFLICT DO NOTHING;
  END LOOP;
END $$;

-- 3. USUARIOS INSTITUCIONALES (password: admin123 para todos)
--    Todos con work_shift (jornada: mañana, tarde, noche, completa)
--    Hash bcrypt: $2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My
DO $$
DECLARE v_role UUID;
BEGIN
  -- ===== ADMIN (super-user, RECTOR role) =====
  SELECT role_id INTO v_role FROM roles WHERE role_name='RECTOR' LIMIT 1;
  INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,work_shift,active,email_verified,phone_verified,created_at)
  VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'111111111','Administrador','Sistema','admin@nexo.edu','3000000000','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','completa',TRUE,TRUE,TRUE,NOW());

  -- ===== RECTOR (1 con nombre) =====
  INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,work_shift,active,email_verified,phone_verified,created_at)
  VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000001','Eduardo','Castro','rector@nexo.edu','3000000001','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','completa',TRUE,TRUE,TRUE,NOW());

  -- ===== COORDINADORES (3 total) =====
  SELECT role_id INTO v_role FROM roles WHERE role_name='COORDINATOR' LIMIT 1;
  INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,work_shift,active,email_verified,phone_verified,created_at)
  VALUES
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000002','Martha','Gomez','coordinador@nexo.edu','3000000002','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','mañana',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000003','Javier','Mendoza','coordinador2@nexo.edu','3000000003','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','tarde',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000004','Lucia','Vasquez','coordinador3@nexo.edu','3000000004','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','completa',TRUE,TRUE,TRUE,NOW());

  -- ===== DOCENTE PRINCIPAL (para testing de dashboard de docente) =====
  SELECT role_id INTO v_role FROM roles WHERE role_name='TEACHER' LIMIT 1;
  INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,work_shift,active,email_verified,phone_verified,created_at)
  VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000005','Roberto','Fernandez','docente@nexo.edu','3000000005','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','tarde',TRUE,TRUE,TRUE,NOW());

  -- ===== PSICORIENTADORES (3 total) =====
  SELECT role_id INTO v_role FROM roles WHERE role_name='COUNSELOR' LIMIT 1;
  INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,work_shift,active,email_verified,phone_verified,created_at)
  VALUES
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000006','Patricia','Ruiz','psicorientador@nexo.edu','3000000006','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','mañana',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000007','Andres','Soto','psicorientador2@nexo.edu','3000000007','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','tarde',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000008','Claudia','Perez','psicorientador3@nexo.edu','3000000008','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','completa',TRUE,TRUE,TRUE,NOW());

  -- ===== SECRETARIAS (5 total) =====
  SELECT role_id INTO v_role FROM roles WHERE role_name='SECRETARY' LIMIT 1;
  INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,work_shift,active,email_verified,phone_verified,created_at)
  VALUES
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000009','Sandra','Lopez','secretaria@nexo.edu','3000000009','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','mañana',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000010','Diana','Torres','secretaria2@nexo.edu','3000000010','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','tarde',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000011','Paola','Castro','secretaria3@nexo.edu','3000000011','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','mañana',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000012','Carolina','Morales','secretaria4@nexo.edu','3000000012','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','tarde',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000013','Elena','Ramirez','secretaria5@nexo.edu','3000000013','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','completa',TRUE,TRUE,TRUE,NOW());

  -- ===== PORTERO / SEGURIDAD (2 total, 1 con nombre) =====
  SELECT role_id INTO v_role FROM roles WHERE role_name='SECURITY' LIMIT 1;
  INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,work_shift,active,email_verified,phone_verified,created_at)
  VALUES
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000014','Jorge','Hernandez','portero@nexo.edu','3000000014','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','mañana',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000015','Felix','Gutierrez','portero2@nexo.edu','3000000015','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','tarde',TRUE,TRUE,TRUE,NOW());

  -- ===== AUXILIARES (4 total) =====
  SELECT role_id INTO v_role FROM roles WHERE role_name='AUXILIARY' LIMIT 1;
  INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,work_shift,active,email_verified,phone_verified,created_at)
  VALUES
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000016','Oscar','Diaz','auxiliar@nexo.edu','3000000016','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','mañana',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000017','Natalia','Cruz','auxiliar2@nexo.edu','3000000017','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','tarde',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000018','Raul','Mendoza','auxiliar3@nexo.edu','3000000018','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','noche',TRUE,TRUE,TRUE,NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'100000019','Sofia','Aguilar','auxiliar4@nexo.edu','3000000019','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt','completa',TRUE,TRUE,TRUE,NOW());
END $$;

-- Docentes adicionales (8, todos con jornada y asignaciones)
INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,work_shift,active,email_verified,phone_verified,created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,r.role_id,doc,fname,lname,email,phone,'$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt',shift,TRUE,TRUE,TRUE,NOW()
FROM (VALUES
    ('200000001','Carlos','Martinez','cmartinez@colegionacional.edu.co','3001000001','tarde'),
    ('200000002','Ana','Rodriguez','arodriguez@colegionacional.edu.co','3001000002','mañana'),
    ('200000003','Luis','Gonzalez','lgonzalez@colegionacional.edu.co','3001000003','tarde'),
    ('200000004','Maria','Lopez','mlopez@colegionacional.edu.co','3001000004','mañana'),
    ('200000005','Pedro','Sanchez','psanchez@colegionacional.edu.co','3001000005','tarde'),
    ('200000006','Jose','Ramirez','jramirez@colegionacional.edu.co','3001000006','mañana'),
    ('200000007','Diana','Torres','dtorres@colegionacional.edu.co','3001000007','tarde'),
    ('200000008','Camila','Castro','ccastro@colegionacional.edu.co','3001000008','mañana')
) AS t(doc,fname,lname,email,phone,shift)
JOIN roles r ON r.role_name='TEACHER';

-- 4. AULAS, GRUPOS, ASIGNATURAS
INSERT INTO classrooms(classroom_id, school_id, classroom_name, building, created_at) VALUES
    ('c1aaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Salon 101','Bloque A',NOW()),
    ('c2bbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Salon 102','Bloque A',NOW()),
    ('c3cccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Salon 201','Bloque B',NOW()),
    ('c4dddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Salon 202','Bloque B',NOW()),
    ('c5eeeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Laboratorio','Bloque C',NOW());

INSERT INTO academic_groups(group_id, school_id, group_name, grade_level, academic_year, created_at) VALUES
    ('a10aaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'6A','Sexto',2026,NOW()),
    ('a20bbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'6B','Sexto',2026,NOW()),
    ('a30ccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'7A','Septimo',2026,NOW()),
    ('a40ddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'7B','Septimo',2026,NOW()),
    ('a50eeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'8A','Octavo',2026,NOW());

INSERT INTO subjects(subject_id, subject_name, created_at) VALUES
    (gen_random_uuid(),'Matematicas',NOW()),
    (gen_random_uuid(),'Ciencias',NOW()),
    (gen_random_uuid(),'Lengua',NOW()),
    (gen_random_uuid(),'Historia',NOW()),
    (gen_random_uuid(),'Educacion Fisica',NOW()),
    (gen_random_uuid(),'Ingles',NOW()),
    (gen_random_uuid(),'Tecnologia',NOW());

-- 5. ESTUDIANTES (50, 10 por grupo)
DO $$
DECLARE i INT;
    fnames TEXT[]:=ARRAY['Jhon','Carlos','Ana','Luis','Maria','Pedro','Sofia','Diego','Laura','Juan','Valentina','Andres','Camila','Daniel','Juliana','Miguel','Natalia','Alejandro','Isabella','Mateo'];
    lnames TEXT[]:=ARRAY['Edison','Garcia','Martinez','Rodriguez','Gonzalez','Lopez','Sanchez','Perez','Ramirez','Torres','Flores','Rivera','Castro','Ortiz','Reyes','Ruiz','Jimenez','Vargas','Moreno','Aguilar'];
BEGIN
    FOR i IN 1..50 LOOP
        INSERT INTO students(student_id,school_id,document_number,first_name,last_name,birth_date,active,created_at)
        VALUES(
            CASE WHEN i=1 THEN 'a1111111-1111-1111-1111-111111111111'::UUID ELSE gen_random_uuid() END,
            'a3333333-3333-3333-3333-333333333333'::UUID,
            (100000000+i)::TEXT,
            CASE WHEN i=1 THEN 'Jhon' ELSE fnames[1+(i%20)] END,
            CASE WHEN i=1 THEN 'Edison' ELSE lnames[1+((i*3)%20)] END,
            CURRENT_DATE-INTERVAL'12 years'-((i%36)||' months')::INTERVAL,
            TRUE, NOW()
        );
    END LOOP;
END $$;

INSERT INTO student_group_assignments(assignment_id,student_id,group_id,active,start_date,created_at)
SELECT gen_random_uuid(),s.student_id,
    CASE
        WHEN s.document_number BETWEEN '100000001' AND '100000010' THEN 'a10aaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID
        WHEN s.document_number BETWEEN '100000011' AND '100000020' THEN 'a20bbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID
        WHEN s.document_number BETWEEN '100000021' AND '100000030' THEN 'a30ccccc-cccc-cccc-cccc-cccccccccccc'::UUID
        WHEN s.document_number BETWEEN '100000031' AND '100000040' THEN 'a40ddddd-dddd-dddd-dddd-dddddddddddd'::UUID
        ELSE 'a50eeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID
    END,
    TRUE,'2026-01-20',NOW()
FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID;

-- 6. ACUDIENTES (50, uno por estudiante)
DO $$
DECLARE i INT;
    gnames TEXT[]:=ARRAY['Carlos Sr.','Ana Maria','Luis Alberto','Maria Elena','Pedro Jose','Sofia Isabel','Diego Fernando','Laura Cristina','Juan Pablo','Valentina'];
    v_user UUID; v_guard UUID; v_role UUID;
BEGIN
    v_role:=(SELECT role_id FROM roles WHERE role_name='GUARDIAN' LIMIT 1);
    FOR i IN 1..50 LOOP
        v_user:=gen_random_uuid(); v_guard:=gen_random_uuid();
        INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,active,phone_verified,created_at)
        VALUES(v_user,'a3333333-3333-3333-3333-333333333333'::UUID,v_role,'g'||(100000000+i)::TEXT,gnames[1+(i%10)],'Acudiente '||i,'guardian'||i||'@test.com',CASE WHEN i=1 THEN '+573243607948' ELSE '+57'||(3000000000+i)::TEXT END,'$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt',TRUE,TRUE,NOW());
        INSERT INTO guardians(guardian_id,user_id,whatsapp_phone,whatsapp_phone_normalized,emergency_contact,created_at)
        VALUES(v_guard,v_user,CASE WHEN i=1 THEN '+573243607948' ELSE '+57'||(3000000000+i)::TEXT END,regexp_replace(CASE WHEN i=1 THEN '+573243607948' ELSE '+57'||(3000000000+i)::TEXT END, '[^0-9+]', '', 'g'),CASE WHEN i%5=0 THEN TRUE ELSE FALSE END,NOW());
    END LOOP;
END $$;

INSERT INTO guardian_student_relationships(relationship_id,guardian_id,student_id,relationship_type,primary_guardian,created_at)
SELECT gen_random_uuid(),g.guardian_id,s.student_id,'GUARDIAN',TRUE,NOW()
FROM (SELECT ROW_NUMBER() OVER() rn,guardian_id FROM guardians ORDER BY guardian_id) g
JOIN (SELECT ROW_NUMBER() OVER() rn,student_id FROM students WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY student_id) s ON g.rn=s.rn;

-- 7. DISPOSITIVOS EDGE
INSERT INTO edge_devices(device_id,school_id,classroom_id,device_name,active,last_sync_at,last_ping,status,last_seen_timestamp,location,created_at) VALUES
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,'c1aaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'Lector Entrada Principal',TRUE,NOW(),NOW(),'ONLINE',NOW(),'Entrada Principal Bloque A',NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,'c2bbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'Lector Comedor',TRUE,NOW(),NOW()-INTERVAL'5 minutes','ONLINE',NOW()-INTERVAL'5 minutes','Comedor',NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,'c3cccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'Lector Bloque B',TRUE,NOW(),NOW()-INTERVAL'1 hour','ONLINE',NOW()-INTERVAL'1 hour','Bloque B Pasillo',NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,'c4dddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'Lector Bloque B 2',TRUE,NOW(),NOW()-INTERVAL'2 hours','STALE',NOW()-INTERVAL'2 hours','Bloque B Segundo Piso',NOW()),
    (gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,'c5eeeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID,'Lector Laboratorio',FALSE,NOW()-INTERVAL'1 day',NULL,'OFFLINE',NULL,'Laboratorio Bloque C',NOW());

-- 8. HORARIOS — Cada docente tiene grupo + materia + aula asignados
DO $$
DECLARE
    v_teacher UUID; v_subj UUID; i INT; j INT;
    groups UUID[]:=ARRAY['a10aaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'a20bbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'a30ccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'a40ddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'a50eeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID];
    classrooms UUID[]:=ARRAY['c1aaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'c2bbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'c3cccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'c4dddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'c5eeeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID];
    teacher_emails TEXT[]:=ARRAY['docente@nexo.edu','cmartinez@colegionacional.edu.co','arodriguez@colegionacional.edu.co','lgonzalez@colegionacional.edu.co','mlopez@colegionacional.edu.co','psanchez@colegionacional.edu.co','jramirez@colegionacional.edu.co','dtorres@colegionacional.edu.co','ccastro@colegionacional.edu.co'];
    time_slots TEXT[]:=ARRAY['08:00','09:35','11:05','12:35','14:00','15:30'];
    time_ends TEXT[]:=ARRAY['09:30','11:00','12:30','14:00','15:30','17:00'];
BEGIN
    -- Docente principal: 2 bloques por grupo (10 schedules total)
    SELECT user_id INTO v_teacher FROM users WHERE email='docente@nexo.edu' LIMIT 1;
    FOR i IN 1..5 LOOP
        SELECT subject_id INTO v_subj FROM subjects ORDER BY subject_name LIMIT 1;
        INSERT INTO schedules(schedule_id,group_id,subject_id,classroom_id,teacher_user_id,day_of_week,block_number,start_time,end_time)
        VALUES(gen_random_uuid(),groups[i],v_subj,classrooms[i],v_teacher,1,1,time_slots[1]::TIME,time_ends[1]::TIME);
        INSERT INTO schedules(schedule_id,group_id,subject_id,classroom_id,teacher_user_id,day_of_week,block_number,start_time,end_time)
        VALUES(gen_random_uuid(),groups[i],v_subj,classrooms[i],v_teacher,2,2,time_slots[2]::TIME,time_ends[2]::TIME);
    END LOOP;

    -- 8 docentes adicionales: cada uno con 1-2 grupos, materias rotadas
    FOR i IN 2..9 LOOP
        SELECT user_id INTO v_teacher FROM users WHERE email=teacher_emails[i] LIMIT 1;
        -- Asignar 2 grupos por docente (rotando)
        FOR j IN 0..1 LOOP
            SELECT subject_id INTO v_subj FROM subjects ORDER BY subject_name OFFSET ((i+j)%7) LIMIT 1;
            INSERT INTO schedules(schedule_id,group_id,subject_id,classroom_id,teacher_user_id,day_of_week,block_number,start_time,end_time)
            VALUES(gen_random_uuid(),
                groups[1+((i+j-1)%5)],
                v_subj,
                classrooms[1+((i+j-1)%5)],
                v_teacher,
                1+((i+j)%5),  -- day_of_week 1-5
                1+((i+j)%3),  -- block 1-3
                time_slots[1+((i+j)%3)]::TIME,
                time_ends[1+((i+j)%3)]::TIME
            );
        END LOOP;
    END LOOP;
END $$;

-- 9. STAFF RECORDS — Un registro por cada usuario no-guardian
INSERT INTO staff_records(staff_record_id,school_id,user_id,hired_at,position_name,employee_code,active)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,u.user_id,
    '2020-01-01'::DATE,
    CASE r.role_name
        WHEN 'RECTOR' THEN 'Rector'
        WHEN 'COORDINATOR' THEN 'Coordinador Académico'
        WHEN 'TEACHER' THEN 'Docente de Aula'
        WHEN 'COUNSELOR' THEN 'Psicorientador'
        WHEN 'SECRETARY' THEN 'Secretaria'
        WHEN 'SECURITY' THEN 'Portero'
        WHEN 'AUXILIARY' THEN 'Auxiliar Administrativo'
        ELSE 'Personal'
    END,
    'EMP-'||LPAD(ROW_NUMBER() OVER()::TEXT,4,'0'),
    TRUE
FROM users u JOIN roles r ON r.role_id=u.role_id
WHERE u.school_id='a3333333-3333-3333-3333-333333333333'::UUID
  AND r.role_name != 'GUARDIAN'
ORDER BY r.role_name, u.user_id;

-- 10. EVENTOS BIOMÉTRICOS — Hoy (garantizado 4 INGRESO + 2 INGRESO_TARDE por grupo)
DO $$
DECLARE
    d UUID[]; s UUID[];
    grp_idx INT; i INT;
    groups UUID[]:=ARRAY['a10aaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'a20bbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'a30ccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'a40ddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'a50eeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID];
BEGIN
    SELECT array_agg(device_id::UUID) INTO d FROM edge_devices WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID AND active=TRUE;
    FOR grp_idx IN 1..5 LOOP
        SELECT array_agg(sga.student_id::UUID ORDER BY sga.student_id) INTO s
        FROM student_group_assignments sga WHERE sga.group_id=groups[grp_idx] AND sga.active=TRUE;
        -- 4 INGRESO
        FOR i IN 1..LEAST(4, array_length(s,1)) LOOP
            INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,confidence_score,event_timestamp)
            VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s[i],d[1+((i)%array_length(d,1))],'INGRESO','SUCCESS',92.0+random()*7.0,CURRENT_DATE+((7+(i%2))::TEXT||':'||(0+(i*15)%60)::TEXT||':00')::TIME);
        END LOOP;
        -- 2 INGRESO_TARDE
        FOR i IN 5..LEAST(6, array_length(s,1)) LOOP
            INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,confidence_score,event_timestamp)
            VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s[i],d[1+((i)%array_length(d,1))],'INGRESO_TARDE','LATE',88.0+random()*10.0,CURRENT_DATE+((8+(i%2))::TEXT||':'||(15+(i*10)%45)::TEXT||':00')::TIME);
        END LOOP;
        -- 2 SALIDA
        FOR i IN 7..LEAST(8, array_length(s,1)) LOOP
            INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,confidence_score,event_timestamp)
            VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s[i],d[1+((i)%array_length(d,1))],'SALIDA','SUCCESS',90.0+random()*9.0,CURRENT_DATE+((15+(i%2))::TEXT||':'||(0+(i*10)%60)::TEXT||':00')::TIME);
        END LOOP;
    END LOOP;
    -- Históricos: 30 días, ~50 por día
    FOR i IN 1..1500 LOOP
        INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,confidence_score,event_timestamp)
        SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,student_id,d[1+(i%array_length(d,1))],
            CASE WHEN i%4=0 THEN 'INGRESO_TARDE' WHEN i%4=1 THEN 'SALIDA' WHEN i%4=2 THEN 'INGRESO' ELSE 'SALIDA_ALMUERZO' END,
            CASE WHEN i%5=0 THEN 'LATE' ELSE 'SUCCESS' END,
            85.0+random()*14.9,
            (CURRENT_DATE-((i%30)||' days')::INTERVAL)+((7+(i%10))::TEXT||':'||(15+(i%45))::TEXT||':00')::TIME
        FROM students WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 1;
    END LOOP;
END $$;

-- 11. INCIDENTES DE ASISTENCIA — Hoy + históricos
DO $$
DECLARE
    grp_idx INT; i INT; s UUID[];
    groups UUID[]:=ARRAY['a10aaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'a20bbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'a30ccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'a40ddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'a50eeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID];
BEGIN
    FOR grp_idx IN 1..5 LOOP
        SELECT array_agg(sga.student_id::UUID ORDER BY sga.student_id) INTO s
        FROM student_group_assignments sga WHERE sga.group_id=groups[grp_idx] AND sga.active=TRUE;
        -- 2 INASISTENCIA hoy
        FOR i IN 9..LEAST(10, array_length(s,1)) LOOP
            INSERT INTO attendance_incidents(incident_id,school_id,student_id,incident_type,detected_at,resolved,created_at)
            VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s[i],'INASISTENCIA',CURRENT_DATE+'08:00:00'::TIME,FALSE,NOW());
        END LOOP;
        -- 1 PERMISO hoy
        INSERT INTO attendance_incidents(incident_id,school_id,student_id,incident_type,detected_at,resolved,created_at)
        VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s[1],'PERMISO',CURRENT_DATE+'10:00:00'::TIME,TRUE,NOW());
        -- 1 AUTORIZAR_SALIDA hoy
        INSERT INTO attendance_incidents(incident_id,school_id,student_id,incident_type,detected_at,resolved,created_at)
        VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s[2],'AUTORIZAR_SALIDA',CURRENT_DATE+'14:00:00'::TIME,TRUE,NOW());
        -- 1 LATE_ARRIVAL hoy (alerta)
        INSERT INTO attendance_incidents(incident_id,school_id,student_id,incident_type,detected_at,resolved,created_at)
        VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s[3],'LATE_ARRIVAL',CURRENT_DATE+'07:45:00'::TIME,FALSE,NOW());
    END LOOP;
    -- Históricos: 30 días
    FOR i IN 1..200 LOOP
        INSERT INTO attendance_incidents(incident_id,school_id,student_id,incident_type,detected_at,resolved,created_at)
        SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,student_id,
            CASE WHEN i%5=0 THEN 'INASISTENCIA' WHEN i%5=1 THEN 'LATE_ARRIVAL' WHEN i%5=2 THEN 'EARLY_DEPARTURE' WHEN i%5=3 THEN 'PERMISO' ELSE 'AUTORIZAR_SALIDA' END,
            (CURRENT_DATE-((i%30)||' days')::INTERVAL)+'08:00:00'::TIME,
            CASE WHEN i%2=0 THEN TRUE ELSE FALSE END,
            NOW()-(i||' hours')::INTERVAL
        FROM students WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 1;
    END LOOP;
END $$;

-- 12. ALERTAS SOS (2 activas + 3 resueltas)
INSERT INTO sos_alerts(alert_id,school_id,emitted_by_user_id,classroom_id,alert_type,alert_description,resolved,resolved_by_user_id,resolved_at,emitted_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    classroom_id,
    CASE WHEN random()<0.33 THEN 'EMERGENCY_MEDICAL' WHEN random()<0.66 THEN 'SECURITY_THREAT' ELSE 'FIRE_ALARM' END,
    'Alerta SOS simulada activa',
    FALSE, NULL, NULL,
    CURRENT_DATE+((10+(random()*4)::INT)::TEXT||':00:00')::TIME
FROM classrooms WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 2;

INSERT INTO sos_alerts(alert_id,school_id,emitted_by_user_id,classroom_id,alert_type,alert_description,resolved,resolved_by_user_id,resolved_at,emitted_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    classroom_id,
    CASE WHEN random()<0.33 THEN 'EMERGENCY_MEDICAL' WHEN random()<0.66 THEN 'SECURITY_THREAT' ELSE 'FIRE_ALARM' END,
    'Alerta SOS simulada resuelta',
    TRUE,
    (SELECT user_id FROM users WHERE email='coordinador@nexo.edu' LIMIT 1),
    CURRENT_DATE+((12+(random()*2)::INT)::TEXT||':00:00')::TIME,
    CURRENT_DATE+((10+(random()*2)::INT)::TEXT||':00:00')::TIME
FROM classrooms WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 3;

-- Históricos SOS (10 días)
INSERT INTO sos_alerts(alert_id,school_id,emitted_by_user_id,classroom_id,alert_type,alert_description,resolved,resolved_by_user_id,resolved_at,emitted_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='docente@nexo.edu' LIMIT 1),
    (SELECT classroom_id FROM classrooms WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 1),
    CASE WHEN i%3=0 THEN 'EMERGENCY_MEDICAL' WHEN i%3=1 THEN 'SECURITY_THREAT' ELSE 'FIRE_ALARM' END,
    'Alerta histórica '||i,
    TRUE,
    (SELECT user_id FROM users WHERE email='coordinador@nexo.edu' LIMIT 1),
    (CURRENT_DATE-((i%10)||' days')::INTERVAL)+'12:00:00'::TIME,
    (CURRENT_DATE-((i%10)||' days')::INTERVAL)+'10:00:00'::TIME
FROM generate_series(1,10) i;

-- 13. MENSAJES TWILIO
INSERT INTO twilio_message_types(type_code,description) VALUES
    ('CITATION','Citación a acudiente'),
    ('NOTIFICATION','Notificación general'),
    ('ABSENCE_ALERT','Alerta de inasistencia'),
    ('LATE_ALERT','Alerta de llegada tardía'),
    ('EMERGENCY','Emergencia')
ON CONFLICT DO NOTHING;

INSERT INTO twilio_messages(twilio_message_id,school_id,student_id,guardian_id,sender_user_id,type_code,direction,phone_number,message_content,delivery_status,sent_at)
VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT student_id FROM students WHERE first_name='Jhon' AND last_name='Edison' LIMIT 1),
    (SELECT guardian_id FROM guardians WHERE whatsapp_phone='+573243607948' LIMIT 1),
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    'CITATION','OUTBOUND','+573243607948',
    'Estimado acudiente, su hijo Jhon Edison ha sido citado. Por favor comuniquese con la institucion.',
    'SENT',NOW())
ON CONFLICT DO NOTHING;

INSERT INTO twilio_messages(twilio_message_id,school_id,student_id,guardian_id,sender_user_id,type_code,direction,phone_number,message_content,delivery_status,sent_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,gsr.guardian_id,
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    CASE WHEN i%4=0 THEN 'CITATION' WHEN i%4=1 THEN 'NOTIFICATION' WHEN i%4=2 THEN 'ABSENCE_ALERT' ELSE 'LATE_ALERT' END,
    'OUTBOUND',g.whatsapp_phone,
    'Mensaje de prueba para '||s.first_name||' '||s.last_name,
    CASE WHEN i%8=0 THEN 'FAILED' ELSE 'SENT' END,
    NOW()-(random()*INTERVAL'7 days')
FROM students s
JOIN guardian_student_relationships gsr ON gsr.student_id=s.student_id
JOIN guardians g ON g.guardian_id=gsr.guardian_id
CROSS JOIN generate_series(1,30) i
WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID
ORDER BY random() LIMIT 30;

-- Mensajes inbound (respuestas de acudientes)
INSERT INTO twilio_messages(twilio_message_id,school_id,student_id,guardian_id,type_code,direction,phone_number,message_content,delivery_status,sent_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,gsr.guardian_id,
    'NOTIFICATION','INBOUND',g.whatsapp_phone,
    'Gracias por la información',
    'RECEIVED',NOW()-(random()*INTERVAL'3 days')
FROM students s
JOIN guardian_student_relationships gsr ON gsr.student_id=s.student_id
JOIN guardians g ON g.guardian_id=gsr.guardian_id
WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID
ORDER BY random() LIMIT 10;

-- 14. COMANDOS DE USUARIO
INSERT INTO user_commands(command_id,school_id,executed_by_user_id,command_type,target_entity_type,target_entity_id,command_payload,executed_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    CASE WHEN i%7=0 THEN 'SOS' WHEN i%7=1 THEN 'INASISTENCIA' WHEN i%7=2 THEN 'CITACION' WHEN i%7=3 THEN 'AUTORIZAR_SALIDA' WHEN i%7=4 THEN 'PERMISO' WHEN i%7=5 THEN 'INCIDENTE' ELSE 'SEGUIMIENTO' END,
    'student', gen_random_uuid(),
    jsonb_build_object('reason','Comando simulado '||i,'student_name','Estudiante '||i),
    NOW()-(random()*INTERVAL'7 days')
FROM generate_series(1,20) i;

-- Comandos de docente
INSERT INTO user_commands(command_id,school_id,executed_by_user_id,command_type,target_entity_type,target_entity_id,command_payload,executed_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='docente@nexo.edu' LIMIT 1),
    CASE WHEN i%3=0 THEN 'CITACION' WHEN i%3=1 THEN 'INASISTENCIA' ELSE 'PERMISO' END,
    'student', gen_random_uuid(),
    jsonb_build_object('reason','Comando docente '||i),
    NOW()-(random()*INTERVAL'3 days')
FROM generate_series(1,10) i;

-- 15. INCIDENTES DE SEGURIDAD
INSERT INTO security_incidents(incident_id,school_id,related_student_id,related_user_id,incident_type,severity_level,description,detected_at,resolved)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT student_id FROM students WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 1),
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    CASE WHEN i%4=0 THEN 'UNAUTHORIZED_ACCESS' WHEN i%4=1 THEN 'DATA_LEAK' WHEN i%4=2 THEN 'PHYSICAL_THREAT' ELSE 'SUSPICIOUS_BEHAVIOR' END,
    CASE WHEN i%3=0 THEN 'LOW' WHEN i%3=1 THEN 'MEDIUM' ELSE 'HIGH' END,
    'Incidente de seguridad simulado '||i,
    NOW()-(random()*INTERVAL'7 days'),
    CASE WHEN i%2=0 THEN TRUE ELSE FALSE END
FROM generate_series(1,10) i;

-- 16. AUDIT LOGS GLOBALES (incluye FAILED_LOGIN para /audit/security/failed-attempts)
INSERT INTO global_audit_logs(log_id,school_id,performed_by_user_id,action_type,entity_type,entity_id,action_details,ip_address,created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    CASE WHEN i%5=0 THEN 'LOGIN' WHEN i%5=1 THEN 'LOGOUT' WHEN i%5=2 THEN 'DATA_ACCESS' WHEN i%5=3 THEN 'CONFIG_CHANGE' ELSE 'FAILED_LOGIN' END,
    CASE WHEN i%3=0 THEN 'user' WHEN i%3=1 THEN 'student' ELSE 'system' END,
    gen_random_uuid(),
    jsonb_build_object('event','audit_test','index',i),
    ('192.168.1.'||(10+(i%245))::TEXT)::INET,
    NOW()-(random()*INTERVAL'7 days')
FROM generate_series(1,30) i;

-- 17. STUDENT RECORD AUDIT
INSERT INTO student_record_audit(audit_id,school_id,student_id,performed_by_user_id,action_type,previous_data,new_data,performed_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,
    (SELECT user_id FROM users WHERE email='secretaria@nexo.edu' LIMIT 1),
    CASE WHEN i%2=0 THEN 'UPDATE' ELSE 'CREATE' END,
    jsonb_build_object('old','data'),
    jsonb_build_object('new','data'),
    NOW()-(random()*INTERVAL'30 days')
FROM students s CROSS JOIN generate_series(1,2) i
WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 20;

-- 18. AUTORIZACIONES DE SALIDA
INSERT INTO class_exit_authorizations(authorization_id,school_id,student_id,authorized_by_user_id,schedule_id,authorization_reason,exit_time,return_time)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,
    (SELECT user_id FROM users WHERE email='coordinador@nexo.edu' LIMIT 1),
    (SELECT schedule_id FROM schedules ORDER BY random() LIMIT 1),
    'Salida anticipada por cita médica',
    CURRENT_DATE+'14:00:00'::TIME, CURRENT_DATE+'15:30:00'::TIME
FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 10;

INSERT INTO school_exit_authorizations(authorization_id,school_id,student_id,authorized_by_user_id,authorization_reason,exit_time,expected_return_time,status)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,
    (SELECT user_id FROM users WHERE email='coordinador@nexo.edu' LIMIT 1),
    'Salida pedagógica autorizada',
    CURRENT_DATE+'08:00:00'::TIME, CURRENT_DATE+'16:00:00'::TIME, 'APPROVED'
FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 5;

INSERT INTO pedagogical_trip_authorizations(authorization_id,school_id,student_id,authorized_by_user_id,destination,departure_time,return_time,purpose)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,
    (SELECT user_id FROM users WHERE email='rector@nexo.edu' LIMIT 1),
    'Museo de Bogotá',
    CURRENT_DATE+'08:00:00'::TIME, CURRENT_DATE+'16:00:00'::TIME, 'Visita educativa al museo'
FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 5;

-- 19. MENSAJES INTERNOS
INSERT INTO internal_messages(message_id,school_id,sender_user_id,receiver_user_id,subject,message_content,sent_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    u.user_id, 'Asunto mensaje '||i, 'Contenido del mensaje interno número '||i,
    NOW()-(random()*INTERVAL'3 days')
FROM users u CROSS JOIN generate_series(1,3) i
WHERE u.school_id='a3333333-3333-3333-3333-333333333333'::UUID AND u.email NOT LIKE 'guardian%@test.com'
LIMIT 15;

-- 20. REPORTES EXPORTADOS
INSERT INTO report_exports(report_export_id,school_id,generated_by_user_id,report_type,file_format,generated_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='rector@nexo.edu' LIMIT 1),
    CASE WHEN i%4=0 THEN 'ATTENDANCE' WHEN i%4=1 THEN 'BEHAVIOR' WHEN i%4=2 THEN 'BIOMETRIC' ELSE 'DISCIPLINE' END,
    CASE WHEN i%2=0 THEN 'PDF' ELSE 'XLSX' END,
    NOW()-(random()*INTERVAL'7 days')
FROM generate_series(1,10) i;

-- 21. SESIONES DE USUARIO
INSERT INTO user_sessions(session_id,user_id,refresh_token_hash,ip_address,user_agent,expires_at,revoked)
SELECT gen_random_uuid(),u.user_id,md5(u.user_id::TEXT||NOW()::TEXT),
    ('192.168.1.'||(10+(i%245))::TEXT)::INET,'Chrome/Windows',
    NOW()+INTERVAL'24 hours',FALSE
FROM users u CROSS JOIN generate_series(1,2) i
WHERE u.school_id='a3333333-3333-3333-3333-333333333333'::UUID AND u.email NOT LIKE 'guardian%@test.com'
LIMIT 20;

-- 22. MÉTRICAS DE COMPORTAMIENTO ESTUDIANTIL
INSERT INTO student_behavior_metrics(school_id,student_id,calculated_at,late_count,absence_count,total_events,risk_score,risk_level,calculation_window_days)
SELECT 'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,NOW()-(random()*INTERVAL'7 days'),
    (random()*5)::INT,(random()*3)::INT,20+(random()*80)::INT,
    LEAST(100.00,(random()*100)),
    CASE WHEN random()<0.15 THEN 'CRITICAL' WHEN random()<0.35 THEN 'HIGH' WHEN random()<0.65 THEN 'MEDIUM' ELSE 'LOW' END,
    30
FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID;

-- 23. STUDENT TRACKING (activos y completados)
INSERT INTO student_tracking(tracking_id,school_id,student_id,status,created_at,updated_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,
    'en proceso',
    CURRENT_DATE-INTERVAL'5 days', CURRENT_DATE-INTERVAL'1 day'
FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 5;

INSERT INTO student_tracking(tracking_id,school_id,student_id,status,created_at,updated_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,
    'completado',
    CURRENT_DATE-INTERVAL'20 days', CURRENT_DATE-INTERVAL'5 days'
FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 5;

-- 24. STUDENT TRACKING NOTES
INSERT INTO student_tracking_notes(note_id,tracking_id,user_id,note_text,created_at)
SELECT gen_random_uuid(),t.tracking_id,
    (SELECT user_id FROM users WHERE email='psicorientador@nexo.edu' LIMIT 1),
    'Nota de seguimiento: estudiante muestra mejora en comportamiento.',
    NOW()-(random()*INTERVAL'3 days')
FROM student_tracking t LIMIT 5;

INSERT INTO student_tracking_notes(note_id,tracking_id,user_id,note_text,created_at)
SELECT gen_random_uuid(),t.tracking_id,
    (SELECT user_id FROM users WHERE email='psicorientador@nexo.edu' LIMIT 1),
    'Segunda nota: se recomienda continuar monitoreo.',
    NOW()-(random()*INTERVAL'1 day')
FROM student_tracking t WHERE t.status='en proceso' LIMIT 3;

-- 25. NOTIFICATIONS (para cada usuario admin)
INSERT INTO notifications(notification_id,school_id,user_id,title,message,type,created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,u.user_id,
    'Bienvenido a NEXO','Su cuenta ha sido configurada correctamente.',
    'INFO', NOW()-(random()*INTERVAL'1 day')
FROM users u WHERE u.school_id='a3333333-3333-3333-3333-333333333333'::UUID AND u.email NOT LIKE 'guardian%@test.com';

INSERT INTO notifications(notification_id,school_id,user_id,title,message,type,created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,u.user_id,
    'Nueva alerta SOS','Se ha generado una alerta SOS en el sistema.',
    'ALERT', NOW()-(random()*INTERVAL'2 hours')
FROM users u WHERE u.school_id='a3333333-3333-3333-3333-333333333333'::UUID AND u.email IN ('admin@nexo.edu','rector@nexo.edu','coordinador@nexo.edu');

-- 26. SCHOOL PANIC EVENTS (1 activo, 1 resuelto)
INSERT INTO school_panic_events(panic_event_id,school_id,triggered_by_user_id,devices_deactivated,metadata_json,created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    3, jsonb_build_object('type','LOCKDOWN','description','Simulacro de bloqueo activo'),
    NOW()-INTERVAL'10 minutes';

INSERT INTO school_panic_events(panic_event_id,school_id,triggered_by_user_id,devices_deactivated,metadata_json,created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,
    (SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),
    5, jsonb_build_object('type','EVACUATION','description','Evacuación completada','resolved_by',(SELECT user_id FROM users WHERE email='rector@nexo.edu' LIMIT 1)::TEXT),
    NOW()-INTERVAL'2 days';

-- 27. CONTACT LEADS (landing page)
INSERT INTO contact_leads(lead_id,nombre,cargo,institucion,municipio,email,whatsapp,mensaje,created_at)
SELECT gen_random_uuid(),'Interesado '||i,'Director','Institución Educativa '||i,'Bogotá','interesado'||i||'@nexo.seed','300'||(1000000+i)::TEXT,
    'Me gustaría conocer más sobre NEXO para mi institución.',
    NOW()-(random()*INTERVAL'15 days')
FROM generate_series(1,5) i;

-- 28. VERIFICATION CODES (para testing del flujo OTP)
INSERT INTO verification_codes(code_id,user_id,purpose,target_value,code,expires_at,used,verified_at,created_at)
SELECT gen_random_uuid(),u.user_id,'password_reset','3000000000','123456',
    NOW()+INTERVAL'10 minutes',FALSE,NULL,NOW()
FROM users u WHERE u.email='admin@nexo.edu' LIMIT 1;

INSERT INTO verification_codes(code_id,user_id,purpose,target_value,code,expires_at,used,verified_at,created_at)
SELECT gen_random_uuid(),u.user_id,'email_change','docente@nexo.edu','654321',
    NOW()+INTERVAL'10 minutes',FALSE,NULL,NOW()
FROM users u WHERE u.email='docente@nexo.edu' LIMIT 1;

-- =============================================================================
-- FIN DEL SEED
-- =============================================================================
