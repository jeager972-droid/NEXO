-- =============================================================================
-- NEXO — SEED MASIVO DE DATOS (población 100% del sistema)
-- =============================================================================
-- Puebla TODAS las escuelas activas con datos realistas tipo colegio colombiano:
--
--   · Onboarding institucional completo: jornada mañana 07:00–13:00, descanso
--     09:45–10:15, AULAS ROTATIVAS (rotates_classrooms=TRUE) con 6 bloques.
--   · Grupos 6° a 11° × A/B/C/D (24 grupos/escuela), 20–24 estudiantes c/u.
--   · 1 acudiente por estudiante (usuario GUARDIAN con teléfono CO aleatorio).
--   · Docentes: conserva los existentes y crea los que falten hasta 24.
--   · Horarios rotativos L–V (schedules), aulas, nodos edge por aula + entrada
--     + coordinación, accesos docente→grupo y criterios de aviso (F-18).
--   · ~30 días lectivos de eventos biométricos pseudoaleatorios por estudiante:
--     ingresos puntuales/tarde/muy tarde, entradas a aula, baño, evasiones,
--     inasistencias, permisos de clase y de salida, salidas pedagógicas.
--   · Incidentes coherentes (LATE_ARRIVAL, INASISTENCIA, EVASION_INTERNA,
--     PERMISSION_EXPIRED), WhatsApp Twilio in/out, notificaciones internas,
--     calendario lectivo (festivos CO), métricas, seguimientos, y motor de
--     riesgo v3 evaluado sobre los estudiantes con más eventos.
--
-- Password de TODOS los usuarios generados por el seed: test1234
--   (docentes nuevos: profNN@<dane>.nexo.local — acudientes: acu-…@nexo.local)
--
-- PRE-REQUISITO: correr sql/factory_reset.sql primero (aborta si hay datos).
-- USO:  psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f sql/seed.sql
-- Idempotencia: NO re-ejecutable sobre la misma data (guard clause inicial).
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 0. Guard: no sembrar sobre una DB con estudiantes
-- ---------------------------------------------------------------------------
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM students LIMIT 1) THEN
        RAISE EXCEPTION 'seed.sql: ya existen estudiantes. Ejecuta sql/factory_reset.sql primero.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM schools WHERE active) THEN
        RAISE EXCEPTION 'seed.sql: no hay escuelas activas que poblar.';
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- 1. Tablas temporales base
-- ---------------------------------------------------------------------------
CREATE TEMP TABLE _schools ON COMMIT DROP AS
SELECT s.school_id,
       COALESCE(NULLIF(regexp_replace(lower(s.dane_code), '[^a-z0-9]', '', 'g'), ''),
                substr(replace(s.school_id::text, '-', ''), 1, 8)) AS skey
FROM schools s WHERE s.active;

-- Festivos Colombia 2026 (fijos + trasladados a lunes)
CREATE TEMP TABLE _festivos (f DATE PRIMARY KEY) ON COMMIT DROP;
INSERT INTO _festivos VALUES
    ('2026-01-01'),('2026-01-12'),('2026-03-23'),('2026-04-02'),('2026-04-03'),
    ('2026-05-01'),('2026-05-18'),('2026-06-08'),('2026-06-15'),('2026-06-29'),
    ('2026-07-20'),('2026-08-07'),('2026-08-17'),('2026-10-12'),('2026-11-02'),
    ('2026-11-16'),('2026-12-08'),('2026-12-25');

-- Últimos 30 días lectivos (L–V, sin festivos, hasta ayer)
CREATE TEMP TABLE _sdays ON COMMIT DROP AS
SELECT d::date AS day
FROM generate_series(CURRENT_DATE - 80, CURRENT_DATE - 1, INTERVAL '1 day') d
WHERE extract(isodow FROM d) BETWEEN 1 AND 5
  AND d::date NOT IN (SELECT f FROM _festivos)
ORDER BY d DESC LIMIT 30;

-- Bloques de la jornada mañana (rotación de aulas): 6 bloques + descanso 09:45–10:15
CREATE TEMP TABLE _blocks (blk INT PRIMARY KEY, st TIME, et TIME) ON COMMIT DROP;
INSERT INTO _blocks VALUES
    (1,'07:00','07:55'),(2,'07:55','08:50'),(3,'08:50','09:45'),
    (4,'10:15','11:10'),(5,'11:10','12:05'),(6,'12:05','13:00');

-- 24 grupos por escuela: grados 6–11 × A/B/C/D
CREATE TEMP TABLE _groups ON COMMIT DROP AS
SELECT uuid_generate_v4() AS group_id, s.school_id,
       g.grade::text AS grade_level,
       g.grade || '-' || l.letter AS group_name,
       ((g.grade - 6) * 4 + l.idx) AS gidx
FROM _schools s
CROSS JOIN generate_series(6, 11) g(grade)
CROSS JOIN (VALUES ('A',1),('B',2),('C',3),('D',4)) l(letter, idx);

-- Estudiantes: 20–24 por grupo (count determinista por grupo), con perfil
-- de comportamiento persistente. OJO: random() se evalúa por fila solo en la
-- proyección — los laterales/argumentos de SRF sin correlación se evalúan una
-- sola vez por statement (verificado). Por eso el count usa hashtext() y el
-- profile un random() dentro de subquery de proyección.
CREATE TEMP TABLE _students ON COMMIT DROP AS
SELECT uuid_generate_v4() AS student_id,
       g.school_id, g.group_id, g.grade_level, g.gidx,
       gs.seq,
       'EST-' || s.skey || '-' || lpad((g.gidx * 100 + gs.seq)::text, 5, '0') AS doc,
       (ARRAY['Valentina','Sofía','Salomé','Isabella','Mariana','Gabriela','Antonia','Emmanuel','Santiago','Mateo','Sebastián','Alejandro','Samuel','Nicolás','Daniel','Tomás','Emiliano','Jerónimo','Martín','Simón','Juan José','Juan Pablo','Juan Diego','Juan Esteban','Juan Camilo','Andrés Felipe','Luisa Fernanda','Ana Sofía','María José','María Fernanda','Camila','Danna','Antonella','Julieta','Victoria','Paula','Renata','Luciana','Aitana','Mía'])[1 + floor(random()*40)::int] AS fname,
       (ARRAY['García','Rodríguez','Martínez','López','González','Hernández','Pérez','Sánchez','Ramírez','Torres','Gómez','Díaz','Vargas','Castro','Ruiz','Álvarez','Romero','Suárez','Rojas','Moreno','Muñoz','Valencia','Ortiz','Gutiérrez','Chávez','Cárdenas','Guerrero','Medina','Castaño','Duarte','Ospina','Zapata','Cortés','Mejía','Pineda','Quintero','Salazar','Arias','Montoya','Herrera'])[1 + floor(random()*40)::int]
       || ' ' ||
       (ARRAY['García','Rodríguez','Martínez','López','González','Hernández','Pérez','Sánchez','Ramírez','Torres','Gómez','Díaz','Vargas','Castro','Ruiz','Álvarez','Romero','Suárez','Rojas','Moreno','Muñoz','Valencia','Ortiz','Gutiérrez','Chávez','Cárdenas','Guerrero','Medina','Castaño','Duarte','Ospina','Zapata','Cortés','Mejía','Pineda','Quintero','Salazar','Arias','Montoya','Herrera'])[1 + floor(random()*40)::int] AS lname,
       CASE WHEN b.r < 0.70 THEN 'ok'
            WHEN b.r < 0.85 THEN 'late'
            WHEN b.r < 0.93 THEN 'absent'
            WHEN b.r < 0.98 THEN 'evader'
            ELSE 'severe' END AS profile
FROM _groups g
JOIN _schools s ON s.school_id = g.school_id
CROSS JOIN LATERAL generate_series(1, 20 + mod(abs(hashtext(g.group_id::text)::bigint), 5)) gs(seq)
CROSS JOIN LATERAL (SELECT g.group_id IS NOT NULL AS _c, random() AS r) b;

CREATE INDEX ON _students (student_id);
CREATE INDEX ON _students (school_id);
CREATE INDEX ON _students (group_id);

-- Acudiente por estudiante (usuario GUARDIAN nuevo)
CREATE TEMP TABLE _guard ON COMMIT DROP AS
SELECT st.student_id, st.school_id, st.seq, st.gidx, st.lname,
       uuid_generate_v4() AS user_id,
       uuid_generate_v4() AS guardian_id,
       'ACU-' || st.doc AS doc,
       'acu.' || lower(replace(st.doc, '-', '.')) || '@nexo.local' AS email,
       '+57 3' || lpad(((100000000 + floor(random()*899999999))::bigint)::text, 9, '0') AS phone,
       (ARRAY['Carlos','Andrés','Jorge','Mario','Luis','Fernando','Diego','Ricardo','Óscar','Iván','María','Ana','Luz','Carmen','Rosa','Patricia','Sandra','Claudia','Diana','Ángela'])[1 + floor(random()*20)::int] AS gname,
       (ARRAY['MADRE','PADRE','TUTOR','ABUELA','ABUELO','TÍA','TÍO','HERMANA','HERMANO','ACUDIENTE'])[1 + floor(random()*10)::int] AS rel
FROM _students st;

-- Docentes: pool existente + nuevos hasta llegar a 24 por escuela
CREATE TEMP TABLE _new_teachers ON COMMIT DROP AS
SELECT uuid_generate_v4() AS user_id, n.school_id,
       'PROF-' || lpad(g.k::text, 2, '0') AS doc,
       'prof' || lpad(g.k::text, 2, '0') || '@' || n.skey || '.nexo.local' AS email,
       (ARRAY['Adriana','Beatriz','Carolina','Diana','Elena','Fabiola','Gloria','Helena','Ingrid','Jimena','Álvaro','Bernardo','Camilo','Duván','Edgar','Fabián','Germán','Hernando','Ignacio','Javier'])[1 + floor(random()*20)::int] AS fname,
       (ARRAY['García','Rodríguez','Martínez','López','González','Hernández','Pérez','Sánchez','Ramírez','Torres','Gómez','Díaz','Vargas','Castro','Ruiz','Álvarez','Romero','Suárez','Rojas','Moreno'])[1 + floor(random()*20)::int] AS lname
FROM _schools n
CROSS JOIN LATERAL generate_series(
    1,
    GREATEST(0, 24 - (
        SELECT count(*) FROM users u JOIN roles r ON r.role_id = u.role_id
        WHERE u.school_id = n.school_id AND r.role_name = 'TEACHER'
          AND u.active AND u.deleted_at IS NULL
    ))
) g(k);

-- Staff administrativo existente por escuela (para autorizar/notificar)
CREATE TEMP TABLE _admins ON COMMIT DROP AS
SELECT u.user_id, u.school_id, r.role_name,
       row_number() OVER (PARTITION BY u.school_id ORDER BY
           CASE r.role_name WHEN 'RECTOR' THEN 0 WHEN 'COORDINATOR' THEN 1 ELSE 2 END,
           u.created_at) AS ridx
FROM users u JOIN roles r ON r.role_id = u.role_id
WHERE u.school_id IN (SELECT school_id FROM _schools)
  AND r.role_name IN ('RECTOR','COORDINATOR','SECRETARY','COUNSELOR')
  AND u.active AND u.deleted_at IS NULL;

-- 24 aulas por escuela
CREATE TEMP TABLE _classrooms ON COMMIT DROP AS
SELECT uuid_generate_v4() AS classroom_id, s.school_id,
       'Aula ' || lpad(k.k::text, 2, '0') AS cname, k.k AS cidx
FROM _schools s CROSS JOIN generate_series(1, 24) k(k);

-- Nodos edge: 1 por aula + entrada principal + coordinación
CREATE TEMP TABLE _devs ON COMMIT DROP AS
SELECT uuid_generate_v4() AS device_id, c.school_id, c.classroom_id,
       'Nodo ' || c.cname AS dname, 'aula' AS kind
FROM _classrooms c
UNION ALL
SELECT uuid_generate_v4(), s.school_id, NULL, 'Nodo Entrada Principal', 'entrada' FROM _schools s
UNION ALL
SELECT uuid_generate_v4(), s.school_id, NULL, 'Nodo Coordinación', 'coordinacion' FROM _schools s;

-- Pool de docentes por escuela (existentes primero, luego nuevos)
CREATE TEMP TABLE _tpool ON COMMIT DROP AS
SELECT u.user_id, u.school_id,
       row_number() OVER (PARTITION BY u.school_id
                          ORDER BY (u.email LIKE 'prof%@%.nexo.local'), u.created_at, u.user_id) AS tidx,
       count(*) OVER (PARTITION BY u.school_id) AS tcnt
FROM users u JOIN roles r ON r.role_id = u.role_id
WHERE r.role_name = 'TEACHER' AND u.active AND u.deleted_at IS NULL
  AND u.school_id IN (SELECT school_id FROM _schools);
-- (los nuevos aún no existen en users en este punto — se insertan abajo y
--  _tpool se rehace después de insertarlos)

-- ---------------------------------------------------------------------------
-- 2. Estructura académica + personas
-- ---------------------------------------------------------------------------
INSERT INTO academic_groups (group_id, school_id, group_name, grade_level, work_shift, academic_year)
SELECT group_id, school_id, group_name, grade_level, 'mañana',
       EXTRACT(YEAR FROM NOW())::int
FROM _groups
ON CONFLICT (school_id, academic_year, group_name) DO NOTHING;

-- Sincronizar _groups.group_id con el ID real (por si ya existía el grupo)
UPDATE _groups g SET group_id = ag.group_id
FROM academic_groups ag
WHERE ag.school_id = g.school_id
  AND ag.academic_year = EXTRACT(YEAR FROM NOW())::int
  AND ag.group_name = g.group_name;

-- Materias (catálogo global — no tiene school_id)
INSERT INTO subjects (subject_name)
SELECT v.n FROM (VALUES
    ('Matemáticas'),('Lengua Castellana'),('Ciencias Naturales'),('Ciencias Sociales'),
    ('Inglés'),('Educación Física'),('Educación Artística'),('Ética y Valores'),
    ('Religión'),('Informática'),('Física'),('Química'),('Filosofía'),('Tecnología e Innovación')
) v(n)
WHERE NOT EXISTS (SELECT 1 FROM subjects s WHERE s.subject_name = v.n);

CREATE TEMP TABLE _subjects ON COMMIT DROP AS
SELECT subject_id, row_number() OVER (ORDER BY subject_name) AS sidx,
       count(*) OVER () AS scnt
FROM subjects;

INSERT INTO classrooms (classroom_id, school_id, classroom_name, building)
SELECT classroom_id, school_id, cname, 'Bloque Principal' FROM _classrooms;

-- Docentes nuevos (password: test1234)
INSERT INTO users (user_id, school_id, role_id, document_number, first_name, last_name,
                   email, phone, password_hash, password_salt, active, email_verified,
                   onboarding_completed, work_shift)
SELECT t.user_id, t.school_id, (SELECT role_id FROM roles WHERE role_name = 'TEACHER'),
       t.doc, t.fname, t.lname, t.email,
       '+57 3' || lpad(((100000000 + floor(random()*899999999))::bigint)::text, 9, '0'),
       '$2y$12$fxce32IvIlbhpE/.taxnWeGLXxY2fm6UhX5kvgBGS0Ftc6y8mTNsu', '', TRUE, TRUE,
       TRUE, 'mañana'
FROM _new_teachers t
ON CONFLICT (email) DO NOTHING;

-- Rehacer pool de docentes incluyendo los nuevos
DROP TABLE _tpool;
CREATE TEMP TABLE _tpool ON COMMIT DROP AS
SELECT u.user_id, u.school_id,
       row_number() OVER (PARTITION BY u.school_id
                          ORDER BY (u.email LIKE 'prof%@%.nexo.local'), u.created_at, u.user_id) AS tidx,
       count(*) OVER (PARTITION BY u.school_id) AS tcnt
FROM users u JOIN roles r ON r.role_id = u.role_id
WHERE r.role_name = 'TEACHER' AND u.active AND u.deleted_at IS NULL
  AND u.school_id IN (SELECT school_id FROM _schools);

-- Estudiantes (consentimiento biométrico OTORGADO — habeas data)
INSERT INTO students (student_id, school_id, document_number, first_name, last_name,
                      birth_date, biometric_hash, active, work_shift, grade_level,
                      consent_status, consent_channel, consent_recorded_at)
SELECT st.student_id, st.school_id, st.doc, st.fname, st.lname,
       (CURRENT_DATE - ((st.grade_level::int + 5) * INTERVAL '1 year')
                       - ((floor(random()*547))::int * INTERVAL '1 day'))::date,
       'SEEDED-' || substr(replace(st.student_id::text, '-', ''), 1, 12),
       TRUE, 'mañana', st.grade_level,
       'OTORGADO', 'PAPEL', NOW() - ((30 + floor(random()*300))::int || ' days')::interval
FROM _students st
ON CONFLICT (school_id, document_number) DO NOTHING;

-- Sincronizar _students.student_id con el ID real
UPDATE _students st SET student_id = s.student_id
FROM students s
WHERE s.school_id = st.school_id AND s.document_number = st.doc;

INSERT INTO student_group_assignments (student_id, group_id, active, start_date)
SELECT student_id, group_id, TRUE, CURRENT_DATE - 60 FROM _students
ON CONFLICT (student_id, group_id) DO NOTHING;

-- Usuarios acudiente (password: test1234) + guardians + relación
INSERT INTO users (user_id, school_id, role_id, document_number, first_name, last_name,
                   email, phone, password_hash, password_salt, active, email_verified,
                   onboarding_completed)
SELECT g.user_id, g.school_id, (SELECT role_id FROM roles WHERE role_name = 'GUARDIAN'),
       g.doc, g.gname, g.lname, g.email, g.phone,
       '$2y$12$fxce32IvIlbhpE/.taxnWeGLXxY2fm6UhX5kvgBGS0Ftc6y8mTNsu', '', TRUE, TRUE, TRUE
FROM _guard g
ON CONFLICT (email) DO NOTHING;

INSERT INTO guardians (guardian_id, user_id, whatsapp_phone, emergency_contact)
SELECT g.guardian_id, g.user_id, g.phone, (random() < 0.3)
FROM _guard g
JOIN users u ON u.user_id = g.user_id
ON CONFLICT (user_id) DO NOTHING;

INSERT INTO guardian_student_relationships (guardian_id, student_id, relationship_type, primary_guardian)
SELECT g.guardian_id, g.student_id, g.rel, TRUE
FROM _guard g
JOIN guardians gd ON gd.guardian_id = g.guardian_id
ON CONFLICT (guardian_id, student_id) DO NOTHING;

-- Nodos edge configurados y online
INSERT INTO edge_devices (device_id, school_id, classroom_id, device_name, public_key,
                          active, configured, token_hash, app_version, status, last_ping, location)
SELECT d.device_id, d.school_id, d.classroom_id, d.dname,
       'pk-' || substr(replace(d.device_id::text, '-', ''), 1, 16),
       TRUE, TRUE,
       '$2y$10$' || substr(replace(uuid_generate_v4()::text, '-', ''), 1, 53),
       '1.0.0', 'online', NOW() - (floor(random()*300)::int || ' seconds')::interval,
       d.dname
FROM _devs d
ON CONFLICT DO NOTHING;

-- Huellas: slot 1 (~95%) y slot 2 (~45%) enroladas en el nodo de entrada
INSERT INTO student_fingerprints (student_id, school_id, finger_slot, edge_huella_id, device_id)
SELECT st.student_id, st.school_id, x.slot, st.seq * 10 + x.slot, d.device_id
FROM _students st
JOIN _devs d ON d.school_id = st.school_id AND d.kind = 'entrada'
CROSS JOIN (VALUES (1),(2)) x(slot)
WHERE ((x.slot = 1 AND random() < 0.95) OR (x.slot = 2 AND random() < 0.45))
  AND EXISTS (SELECT 1 FROM students s2 WHERE s2.student_id = st.student_id)
ON CONFLICT (student_id, finger_slot) DO NOTHING;

-- Registros de personal para staff que no tenga
INSERT INTO staff_records (school_id, user_id, hired_at, position_name)
SELECT u.school_id, u.user_id,
       CURRENT_DATE - (30 + floor(random()*1500))::int,
       r.role_name
FROM users u JOIN roles r ON r.role_id = u.role_id
WHERE u.school_id IN (SELECT school_id FROM _schools)
  AND r.role_name <> 'GUARDIAN' AND u.active AND u.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM staff_records sr WHERE sr.user_id = u.user_id);

-- ---------------------------------------------------------------------------
-- 3. Onboarding institucional: jornada, bloques, grupos, accesos, horarios
-- ---------------------------------------------------------------------------
INSERT INTO school_schedule_config
    (school_id, work_shift, rotates_classrooms, entry_time, exit_time,
     recess_start_time, recess_end_time, onboarding_completed,
     onboarding_completed_by, onboarding_completed_at)
SELECT s.school_id, 'mañana', TRUE, '07:00', '13:00', '09:45', '10:15', TRUE,
       (SELECT user_id FROM _admins a WHERE a.school_id = s.school_id AND a.ridx = 1),
       NOW() - (floor(random()*40)::int || ' days')::interval
FROM _schools s
ON CONFLICT (school_id, work_shift) DO UPDATE SET
    rotates_classrooms      = TRUE,
    entry_time              = '07:00',
    exit_time               = '13:00',
    recess_start_time       = '09:45',
    recess_end_time         = '10:15',
    onboarding_completed    = TRUE,
    onboarding_completed_at = EXCLUDED.onboarding_completed_at;

INSERT INTO school_time_blocks (school_id, work_shift, block_number, block_name, start_time, end_time)
SELECT s.school_id, 'mañana', b.blk, 'Bloque ' || b.blk, b.st, b.et
FROM _schools s CROSS JOIN _blocks b
ON CONFLICT (school_id, work_shift, block_number) DO NOTHING;

-- Horario semanal rotativo: aula y docente rotan por (grupo, día, bloque).
-- La fórmula (gidx + blk + dow) mod N garantiza que en un mismo día/bloque
-- cada aula y cada docente atiende un único grupo (sin colisiones).
INSERT INTO schedules (group_id, classroom_id, teacher_user_id, subject_id,
                       day_of_week, block_number, start_time, end_time)
SELECT g.group_id,
       c.classroom_id,
       t.user_id,
       sub.subject_id,
       d.dow, b.blk, b.st, b.et
FROM _groups g
CROSS JOIN (VALUES (1),(2),(3),(4),(5)) d(dow)
CROSS JOIN _blocks b
JOIN _classrooms c ON c.school_id = g.school_id
                  AND c.cidx = mod(g.gidx + b.blk + d.dow, 24) + 1
JOIN _tpool t     ON t.school_id = g.school_id
                  AND t.tidx = mod(g.gidx + b.blk + d.dow, LEAST(t.tcnt, 24)) + 1
JOIN _subjects sub ON sub.sidx = mod(g.gidx * 3 + d.dow * 5 + b.blk * 7, sub.scnt) + 1;

-- Acceso docente→grupo (director + 2 docentes rotativos por grupo)
INSERT INTO teacher_group_access (school_id, group_id, teacher_user_id, work_shift, academic_year)
SELECT DISTINCT g.school_id, g.group_id, t.user_id, 'mañana', EXTRACT(YEAR FROM NOW())::int
FROM _groups g
JOIN _tpool t ON t.school_id = g.school_id
             AND t.tidx IN (g.gidx, mod(g.gidx + 7, LEAST(t.tcnt,24)) + 1, mod(g.gidx + 13, LEAST(t.tcnt,24)) + 1)
ON CONFLICT (teacher_user_id, group_id, academic_year) DO NOTHING;

-- Criterios de aviso F-18 (cada docente: llegadas tarde; la mitad también inasistencias)
INSERT INTO teacher_alert_rules (school_id, teacher_user_id, event_kind, threshold_count, window_days, active)
SELECT t.school_id, t.user_id, x.kind,
       CASE x.kind WHEN 'LATE' THEN 3 ELSE 2 END,
       7, TRUE
FROM _tpool t
CROSS JOIN (VALUES ('LATE'),('ABSENCE')) x(kind)
WHERE x.kind = 'LATE' OR mod(t.tidx, 2) = 0;

-- Rutas de notificación por defecto explícitas
INSERT INTO school_notification_routes (school_id, event_kind, target_role, enabled)
SELECT s.school_id, v.kind, v.role, TRUE
FROM _schools s
CROSS JOIN (VALUES
    ('ABSENCE_RESPONSE','COORDINATOR'),('ABSENCE_RESPONSE','RECTOR'),
    ('ABSENCE_NO_REPLY','COORDINATOR'),('EVASION_INTERNA','COORDINATOR'),
    ('EVASION_INTERNA','RECTOR'),('SOS_ALERT','RECTOR'),('SOS_ALERT','SECURITY')
) v(kind, role)
ON CONFLICT (school_id, event_kind, target_role) DO NOTHING;

-- Onboarding de usuarios marcado completo
UPDATE users SET onboarding_completed = TRUE
WHERE school_id IN (SELECT school_id FROM _schools);

-- Escuela: onboarding de horarios + grupos completos (riesgo se marca al final)
UPDATE schools SET
    onboarding_completed        = TRUE,
    groups_onboarding_completed = TRUE,
    groups_onboarding_year      = EXTRACT(YEAR FROM NOW())::int
WHERE school_id IN (SELECT school_id FROM _schools);

-- ---------------------------------------------------------------------------
-- 4. Calendario lectivo + configuración diaria por grupo
-- ---------------------------------------------------------------------------
INSERT INTO school_calendar (school_id, calendar_date, is_lecture_day, reason)
SELECT s.school_id, d::date,
       (extract(isodow FROM d) BETWEEN 1 AND 5 AND d::date NOT IN (SELECT f FROM _festivos)),
       CASE WHEN extract(isodow FROM d) > 5 THEN 'Fin de semana'
            WHEN d::date IN (SELECT f FROM _festivos) THEN 'Festivo' END
FROM _schools s
CROSS JOIN generate_series(CURRENT_DATE - 90, CURRENT_DATE + 30, INTERVAL '1 day') d
ON CONFLICT (school_id, calendar_date) DO NOTHING;

INSERT INTO daily_schedule_config
    (school_id, group_id, config_date, has_classes, expected_entry_time, expected_exit_time, created_by_user_id)
SELECT g.school_id, g.group_id, d.day, TRUE, '07:00', '13:00',
       (SELECT user_id FROM _admins a WHERE a.school_id = g.school_id AND a.ridx = 1)
FROM _groups g
CROSS JOIN (SELECT day FROM _sdays ORDER BY day DESC LIMIT 15) d
ON CONFLICT (school_id, group_id, config_date) DO NOTHING;

-- ---------------------------------------------------------------------------
-- 5. Matriz estudiante × día lectivo con comportamiento pseudoaleatorio
-- ---------------------------------------------------------------------------
CREATE TEMP TABLE _sday ON COMMIT DROP AS
SELECT st.student_id, st.school_id, st.group_id, st.gidx, st.profile,
       d.day, extract(isodow FROM d.day)::int AS dow,
       random() AS r1, random() AS r2, random() AS r3, random() AS r4,
       random() AS r5, random() AS r6, random() AS r7, random() AS r8
FROM _students st CROSS JOIN _sdays d;

CREATE TEMP TABLE _sday_f ON COMMIT DROP AS
SELECT *,
    (r1 >= CASE profile WHEN 'severe' THEN 0.18 WHEN 'absent' THEN 0.14
                        WHEN 'evader' THEN 0.05 WHEN 'late' THEN 0.04 ELSE 0.015 END) AS present,
    (r2 < CASE profile WHEN 'severe' THEN 0.30 WHEN 'late' THEN 0.35 ELSE 0.06 END) AS late,
    (profile IN ('late','severe') AND r7 < 0.15) AS very_late,
    (r3 < CASE profile WHEN 'severe' THEN 0.10 WHEN 'evader' THEN 0.08 ELSE 0.008 END) AS evasion,
    (r4 < 0.22)  AS bath,
    (r5 < 0.035) AS perm,
    (r6 < 0.015) AS pexit
FROM _sday;

CREATE INDEX ON _sday_f (student_id);

-- ---------------------------------------------------------------------------
-- 6. Eventos biométricos (~30 días × ~500 estudiantes × ~5 eventos)
-- ---------------------------------------------------------------------------
-- Ingreso a la institución (puntual 06:35-07:00 / tarde 07:05-08:30 / muy tarde 11:30-12:50)
INSERT INTO biometric_events
    (event_id, school_id, student_id, device_id, event_type, event_result, event_timestamp, metadata_json)
SELECT uuid_generate_v4(), sd.school_id, sd.student_id, de.device_id,
       CASE WHEN sd.very_late THEN 'INGRESO_TARDE'
            WHEN sd.late      THEN 'INGRESO_MANANA'
            ELSE 'INGRESO_PUNTUAL' END,
       'PROCESSED',
       (sd.day + CASE WHEN sd.very_late THEN TIME '11:30'
                      WHEN sd.late      THEN TIME '07:05'
                      ELSE TIME '06:35' END
            + (floor(random() * CASE WHEN sd.very_late THEN 80
                                     WHEN sd.late THEN 85 ELSE 25 END))::int * INTERVAL '1 minute'
       ) AT TIME ZONE 'America/Bogota',
       jsonb_build_object('seed', TRUE, 'finger_slot', 1)
FROM _sday_f sd
JOIN _devs de ON de.school_id = sd.school_id AND de.kind = 'entrada'
WHERE sd.present;

-- Entradas a aula (bloques 1, 3 y 5 — aula real del schedule del grupo).
-- Sin aula-events si evade (está en el colegio pero no entra) o si salió
-- temprano del colegio (solo marca bloques previos a las ~11:30).
INSERT INTO biometric_events
    (event_id, school_id, student_id, device_id, classroom_id, schedule_id,
     event_type, event_result, event_timestamp, metadata_json)
SELECT uuid_generate_v4(), sd.school_id, sd.student_id, da.device_id,
       sch.classroom_id, sch.schedule_id,
       'INGRESO_AULA', 'PROCESSED',
       (sd.day + sch.start_time + (floor(random()*4)::int * INTERVAL '1 minute'))
           AT TIME ZONE 'America/Bogota',
       jsonb_build_object('seed', TRUE)
FROM _sday_f sd
JOIN schedules sch ON sch.group_id = sd.group_id
                  AND sch.day_of_week = sd.dow
                  AND sch.block_number IN (1, 3, 5)
JOIN _devs da ON da.classroom_id = sch.classroom_id AND da.kind = 'aula'
WHERE sd.present AND NOT sd.evasion
  AND (NOT sd.pexit OR sch.block_number < 5);

-- Salida/ingreso de baño (~10:30) — salidas del aula informativas
INSERT INTO biometric_events
    (event_id, school_id, student_id, device_id, event_type, event_result, event_timestamp, metadata_json)
SELECT uuid_generate_v4(), sd.school_id, sd.student_id, de.device_id,
       x.et, 'PROCESSED',
       (sd.day + TIME '10:20' + (floor(random()*20)::int * INTERVAL '1 minute')
              + CASE WHEN x.et = 'INGRESO_BAÑO' THEN (8 + floor(random()*8)::int) * INTERVAL '1 minute' ELSE INTERVAL '0' END
       ) AT TIME ZONE 'America/Bogota',
       jsonb_build_object('seed', TRUE)
FROM _sday_f sd
JOIN _devs de ON de.school_id = sd.school_id AND de.kind = 'entrada'
CROSS JOIN (VALUES ('SALIDA_BAÑO'),('INGRESO_BAÑO')) x(et)
WHERE sd.present AND sd.bath AND NOT sd.evasion;

-- Salida autorizada del colegio (sensor de coordinación, ~11:15–12:15)
INSERT INTO biometric_events
    (event_id, school_id, student_id, device_id, event_type, event_result, event_timestamp, metadata_json)
SELECT uuid_generate_v4(), sd.school_id, sd.student_id, dc.device_id,
       'SALIDA_AUTORIZADA', 'PROCESSED',
       (sd.day + TIME '11:15' + (floor(random()*60)::int * INTERVAL '1 minute'))
           AT TIME ZONE 'America/Bogota',
       jsonb_build_object('seed', TRUE)
FROM _sday_f sd
JOIN _devs dc ON dc.school_id = sd.school_id AND dc.kind = 'coordinacion'
WHERE sd.present AND sd.pexit;

-- Salida de la institución al final de la jornada (13:02–13:20)
INSERT INTO biometric_events
    (event_id, school_id, student_id, device_id, event_type, event_result, event_timestamp, metadata_json)
SELECT uuid_generate_v4(), sd.school_id, sd.student_id, de.device_id,
       'SALIDA_INSTITUCION', 'PROCESSED',
       (sd.day + TIME '13:02' + (floor(random()*18)::int * INTERVAL '1 minute'))
           AT TIME ZONE 'America/Bogota',
       jsonb_build_object('seed', TRUE)
FROM _sday_f sd
JOIN _devs de ON de.school_id = sd.school_id AND de.kind = 'entrada'
WHERE sd.present AND NOT sd.pexit;

-- ---------------------------------------------------------------------------
-- 7. Incidentes de asistencia (trigger de riesgo deshabilitado durante carga;
--    se evalúa explícitamente al final sobre los estudiantes con más eventos)
-- ---------------------------------------------------------------------------
ALTER TABLE attendance_incidents DISABLE TRIGGER trg_evaluate_risk_v3;

INSERT INTO attendance_incidents
    (incident_id, school_id, student_id, group_id, incident_type, detected_at, metadata_json)
SELECT uuid_generate_v4(), sd.school_id, sd.student_id, sd.group_id, 'LATE_ARRIVAL',
       (sd.day + CASE WHEN sd.very_late THEN TIME '11:30' ELSE TIME '07:05' END
              + (floor(random()*80)::int * INTERVAL '1 minute')
       ) AT TIME ZONE 'America/Bogota',
       jsonb_build_object('seed', TRUE,
           'event_type', CASE WHEN sd.very_late THEN 'INGRESO_TARDE' ELSE 'INGRESO_MANANA' END)
FROM _sday_f sd WHERE sd.present AND (sd.late OR sd.very_late);

INSERT INTO attendance_incidents
    (incident_id, school_id, student_id, group_id, incident_type, detected_at, metadata_json)
SELECT uuid_generate_v4(), sd.school_id, sd.student_id, sd.group_id, 'INASISTENCIA',
       (sd.day + TIME '09:15' + (floor(random()*45)::int * INTERVAL '1 minute'))
           AT TIME ZONE 'America/Bogota',
       jsonb_build_object('seed', TRUE, 'source', 'absence_detector')
FROM _sday_f sd WHERE NOT sd.present;

INSERT INTO attendance_incidents
    (incident_id, school_id, student_id, group_id, incident_type, detected_at, metadata_json)
SELECT uuid_generate_v4(), sd.school_id, sd.student_id, sd.group_id, 'EVASION_INTERNA',
       (sd.day + TIME '10:30' + (floor(random()*40)::int * INTERVAL '1 minute'))
           AT TIME ZONE 'America/Bogota',
       jsonb_build_object('seed', TRUE, 'reason', 'NO_REGISTRO_AULA',
                          'returned_to_class', (random() < 0.6))
FROM _sday_f sd WHERE sd.present AND sd.evasion;

-- Salidas del aula informativas (alimentan la categoría 'comportamiento' del riesgo)
INSERT INTO attendance_incidents
    (incident_id, school_id, student_id, group_id, incident_type, detected_at, metadata_json)
SELECT uuid_generate_v4(), sd.school_id, sd.student_id, sd.group_id, 'SALIDA_BAÑO',
       (sd.day + TIME '10:25' + (floor(random()*20)::int * INTERVAL '1 minute'))
           AT TIME ZONE 'America/Bogota',
       jsonb_build_object('seed', TRUE)
FROM _sday_f sd WHERE sd.present AND sd.bath AND NOT sd.evasion AND random() < 0.5;

-- Permisos de salida de clase (~3.5% de estudiante-días)
CREATE TEMP TABLE _permisos ON COMMIT DROP AS
SELECT sd.school_id, sd.student_id, sd.day,
       t.user_id AS auth_by,
       (ARRAY['Ir al baño','Cita médica en enfermería','Llamado a coordinación',
              'Trámite en secretaría','Apoyo en otra aula','Entrega de material en biblioteca'])[1 + floor(random()*6)::int] AS reason,
       (sd.day + TIME '10:10' + (floor(random()*80)::int * INTERVAL '1 minute'))
           AT TIME ZONE 'America/Bogota' AS exit_t,
       CASE WHEN sd.r8 < 0.70 THEN 'COMPLETED'
            WHEN sd.r8 < 0.85 THEN 'EXPIRED'
            ELSE 'CANCELLED' END AS st,
       sd.r8
FROM _sday_f sd
JOIN _tpool t ON t.school_id = sd.school_id AND t.tidx = sd.gidx
WHERE sd.present AND sd.perm;

INSERT INTO class_exit_authorizations
    (school_id, student_id, authorized_by_user_id, authorization_reason,
     exit_time, return_time, actual_return_time, status, metadata_json)
SELECT p.school_id, p.student_id, p.auth_by, p.reason,
       p.exit_t, p.exit_t + INTERVAL '15 minutes',
       CASE WHEN p.st = 'COMPLETED'
            THEN p.exit_t + (10 + floor(random()*12)::int) * INTERVAL '1 minute' END,
       p.st, jsonb_build_object('seed', TRUE)
FROM _permisos p;

-- Permiso vencido sin retorno → incidente PERMISSION_EXPIRED
INSERT INTO attendance_incidents
    (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
SELECT uuid_generate_v4(), p.school_id, p.student_id, 'PERMISSION_EXPIRED',
       p.exit_t + INTERVAL '20 minutes',
       jsonb_build_object('seed', TRUE, 'reason', p.reason)
FROM _permisos p WHERE p.st = 'EXPIRED';

-- Autorizaciones de salida del colegio (~1.5%)
INSERT INTO school_exit_authorizations
    (school_id, student_id, authorized_by_user_id, authorization_reason,
     exit_time, expected_return_time, actual_return_time, status, metadata_json)
SELECT sd.school_id, sd.student_id,
       (SELECT a.user_id FROM _admins a WHERE a.school_id = sd.school_id
         ORDER BY a.ridx LIMIT 1),
       (ARRAY['Cita médica','Diligencia familiar autorizada','Cita odontológica',
              'Retiro temprano por acudiente','Trámite personal urgente'])[1 + floor(random()*5)::int],
       (sd.day + TIME '11:15' + (floor(random()*60)::int * INTERVAL '1 minute'))
           AT TIME ZONE 'America/Bogota',
       CASE WHEN sd.r2 < 0.40
            THEN (sd.day + TIME '14:30') AT TIME ZONE 'America/Bogota' END,
       CASE WHEN sd.r2 < 0.25
            THEN (sd.day + TIME '14:30' + (floor(random()*30)::int * INTERVAL '1 minute'))
                 AT TIME ZONE 'America/Bogota' END,
       CASE WHEN sd.r2 < 0.25 THEN 'COMPLETED'      -- salió y regresó
            WHEN sd.r2 < 0.40 THEN 'EXPIRED'        -- salió con retorno esperado, no volvió
            ELSE 'COMPLETED' END,                  -- salida definitiva (sin retorno)
       jsonb_build_object('seed', TRUE)
FROM _sday_f sd
WHERE sd.present AND sd.pexit
  AND EXISTS (SELECT 1 FROM _admins a WHERE a.school_id = sd.school_id);

-- Salidas pedagógicas: 2 grupos por escuela, día lectivo reciente
INSERT INTO pedagogical_trip_authorizations
    (school_id, student_id, authorized_by_user_id, destination,
     departure_time, return_time, purpose, metadata_json)
SELECT st.school_id, st.student_id,
       (SELECT a.user_id FROM _admins a WHERE a.school_id = st.school_id AND a.ridx = 1),
       v.dest,
       (v.d + TIME '08:00') AT TIME ZONE 'America/Bogota',
       (v.d + TIME '12:30') AT TIME ZONE 'America/Bogota',
       v.purp,
       jsonb_build_object('seed', TRUE)
FROM _students st
JOIN _groups g ON g.group_id = st.group_id
CROSS JOIN LATERAL (
    SELECT CASE WHEN g.gidx <= 12
                THEN 'Museo de Historia Natural'
                ELSE 'Parque Explora — jornada de ciencias' END AS dest,
           (SELECT day FROM _sdays ORDER BY day DESC OFFSET 8 LIMIT 1) AS d,
           'Salida pedagógica programada — refuerzo curricular' AS purp
) v
WHERE g.gidx IN (9, 21) AND v.d IS NOT NULL
  AND EXISTS (SELECT 1 FROM _admins a WHERE a.school_id = st.school_id);

-- Justificaciones de riesgo (algunas llegadas tarde justificadas)
INSERT INTO risk_justifications
    (school_id, student_id, incident_type, incident_date, justified_by,
     justification_type, reason, recalculated, metadata_json)
SELECT i.school_id, i.student_id, 'LATE_ARRIVAL', i.detected_at,
       (SELECT a.user_id FROM _admins a WHERE a.school_id = i.school_id AND a.ridx = 1),
       (ARRAY['permiso','medico','horario','otro'])[1 + floor(random()*4)::int],
       (ARRAY['Acudiente reportó calamidad familiar','Cita médica en la mañana',
              'Falla del transporte escolar','Permiso verbal de coordinación'])[1 + floor(random()*4)::int],
       FALSE, jsonb_build_object('seed', TRUE)
FROM attendance_incidents i
WHERE i.incident_type = 'LATE_ARRIVAL' AND random() < 0.08
  AND EXISTS (SELECT 1 FROM _admins a WHERE a.school_id = i.school_id);

ALTER TABLE attendance_incidents ENABLE TRIGGER trg_evaluate_risk_v3;

-- ---------------------------------------------------------------------------
-- 8. Mensajería WhatsApp (Twilio) y notificaciones internas
-- ---------------------------------------------------------------------------
-- OUTBOUND de inasistencia al acudiente + respuesta INBOUND del menú (60%)
INSERT INTO twilio_messages
    (twilio_message_id, school_id, student_id, guardian_id, type_code, direction,
     phone_number, message_content, provider_message_sid, delivery_status,
     sent_at, metadata_json)
SELECT uuid_generate_v4(), i.school_id, i.student_id, gsr.guardian_id,
       'INASISTENCIA', 'OUTBOUND', g.whatsapp_phone,
       'NEXO: Su acudido no registró ingreso hoy. Responda: 1=Justificada, 2=Sin autorizar',
       'SM' || replace(uuid_generate_v4()::text, '-', ''),
       'delivered',
       i.detected_at + INTERVAL '3 minutes',
       jsonb_build_object('seed', TRUE)
FROM attendance_incidents i
JOIN guardian_student_relationships gsr
  ON gsr.student_id = i.student_id AND gsr.primary_guardian
JOIN guardians g ON g.guardian_id = gsr.guardian_id
WHERE i.incident_type = 'INASISTENCIA';

INSERT INTO twilio_messages
    (twilio_message_id, school_id, student_id, guardian_id, type_code, direction,
     phone_number, message_content, delivery_status, sent_at, received_at, metadata_json)
SELECT uuid_generate_v4(), i.school_id, i.student_id, gsr.guardian_id,
       'INASISTENCIA', 'INBOUND', g.whatsapp_phone,
       CASE WHEN random() < 0.7 THEN '1' ELSE '2' END,
       'received', m.ts, m.ts,
       jsonb_build_object('seed', TRUE)
FROM attendance_incidents i
JOIN guardian_student_relationships gsr
  ON gsr.student_id = i.student_id AND gsr.primary_guardian
JOIN guardians g ON g.guardian_id = gsr.guardian_id
CROSS JOIN LATERAL (
    SELECT i.incident_id AS _c,
           i.detected_at + (8 + floor(random()*120)::int) * INTERVAL '1 minute' AS ts
) m
WHERE i.incident_type = 'INASISTENCIA' AND random() < 0.6;

-- Notificaciones a coordinación/rectoría/docente-director por incidentes recientes
INSERT INTO notifications (school_id, user_id, title, message, type,
                           metadata_json, dedup_key, read_at, created_at)
SELECT i.school_id, u.user_id,
       CASE i.incident_type
           WHEN 'LATE_ARRIVAL'      THEN 'Llegada tarde registrada'
           WHEN 'INASISTENCIA'      THEN 'Inasistencia detectada'
           WHEN 'EVASION_INTERNA'   THEN 'Evasión interna detectada'
           WHEN 'PERMISSION_EXPIRED' THEN 'Permiso de clase vencido'
           ELSE 'Incidente de asistencia' END,
       st.fname || ' ' || st.lname || ' (' || g.group_name || ') — ' ||
       CASE i.incident_type
           WHEN 'LATE_ARRIVAL'      THEN 'ingresó después de la hora de entrada.'
           WHEN 'INASISTENCIA'      THEN 'no registró ingreso en la jornada.'
           WHEN 'EVASION_INTERNA'   THEN 'está en el colegio sin registrar ingreso al aula.'
           WHEN 'PERMISSION_EXPIRED' THEN 'no regresó dentro del permiso de clase.'
           ELSE 'evento de asistencia.' END,
       'ALERT',
       jsonb_build_object('incident_id', i.incident_id, 'student_id', i.student_id, 'seed', TRUE),
       'seed-' || substr(i.incident_id::text, 1, 18) || '-' || substr(u.user_id::text, 1, 18),
       CASE WHEN random() < 0.45
            THEN i.detected_at + (floor(random()*180)::int * INTERVAL '1 minute') END,
       i.detected_at
FROM attendance_incidents i
JOIN _students st ON st.student_id = i.student_id
JOIN _groups g ON g.group_id = st.group_id
CROSS JOIN LATERAL (
    SELECT a.user_id FROM _admins a
    WHERE a.school_id = i.school_id AND a.role_name IN ('COORDINATOR','RECTOR')
    UNION
    SELECT t.user_id FROM _tpool t
    WHERE t.school_id = i.school_id AND t.tidx = g.gidx
) u
WHERE i.detected_at > NOW() - INTERVAL '10 days'
  AND i.incident_type IN ('LATE_ARRIVAL','INASISTENCIA','EVASION_INTERNA','PERMISSION_EXPIRED')
ON CONFLICT (dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING;

-- Mensajes internos entre staff
INSERT INTO internal_messages (message_id, school_id, sender_user_id, receiver_user_id,
                               subject, message_content, sent_at, read_at, metadata_json)
SELECT uuid_generate_v4(), a.school_id, a.user_id, t.user_id,
       v.subj, v.body,
       NOW() - (floor(random()*720)::int * INTERVAL '1 hour'),
       CASE WHEN random() < 0.6 THEN NOW() - (floor(random()*300)::int * INTERVAL '1 hour') END,
       jsonb_build_object('seed', TRUE)
FROM _admins a
JOIN _tpool t ON t.school_id = a.school_id AND t.tidx <= 8 AND mod(t.tidx + a.ridx, 3) = 0
CROSS JOIN (VALUES
    ('Reporte semanal de asistencia','Coordino seguimiento de los casos de tardanza de esta semana.'),
    ('Estudiantes con evasión recurrente','Favor revisar los casos de evasión interna de su grupo.'),
    ('Reunión de consejo académico','Recordatorio: consejo académico el viernes 2:00 p.m.'),
    ('Seguimiento a inasistencias','Hay acudientes sin respuesta de WhatsApp, escalar a llamada.')
) v(subj, body)
WHERE a.ridx <= 2;

-- ---------------------------------------------------------------------------
-- 9. Operación miscelánea: SOS, incidentes de seguridad, reportes, comandos,
--    seguimiento de estudiantes, métricas de comportamiento, OTA
-- ---------------------------------------------------------------------------
INSERT INTO sos_alerts (alert_id, school_id, emitted_by_user_id, classroom_id,
                        alert_type, alert_description, resolved,
                        resolved_by_user_id, emitted_at, resolved_at, metadata_json)
SELECT uuid_generate_v4(), a.school_id, a.user_id,
       (SELECT c.classroom_id FROM _classrooms c WHERE c.school_id = a.school_id
         ORDER BY random() LIMIT 1),
       'PANIC_BUTTON',
       (ARRAY['Altercado entre estudiantes en el patio','Emergencia médica en aula',
              'Intruso detectado en perímetro'])[x.k],
       TRUE, a.user_id,
       e.ts, e.ts + (1 + floor(random()*8)::int) * INTERVAL '1 hour',
       jsonb_build_object('seed', TRUE)
FROM _admins a
CROSS JOIN (VALUES (1),(2)) x(k)
CROSS JOIN LATERAL (
    SELECT a.user_id AS _c,
           NOW() - (5 + floor(random()*50))::int * INTERVAL '1 day' AS ts
) e
WHERE a.ridx = 1 AND random() < 0.6;

INSERT INTO security_incidents (school_id, related_student_id, related_user_id,
                                incident_type, severity_level, description,
                                detected_at, resolved, resolved_at, metadata_json)
SELECT st.school_id, st.student_id,
       (SELECT a.user_id FROM _admins a WHERE a.school_id = st.school_id AND a.ridx = 1),
       (ARRAY['ACOSO','RIÑA','DAÑO_MATERIAL','SUSTANCIAS','PORTE_OBJETO'])[1 + floor(random()*5)::int],
       (ARRAY['LEVE','MODERADA','GRAVE'])[1 + floor(random()*3)::int],
       'Incidente disciplinario reportado por docente durante la jornada.',
       z.det, z.res,
       CASE WHEN z.res THEN z.det + (1 + floor(random()*5)::int) * INTERVAL '1 day' END,
       jsonb_build_object('seed', TRUE)
FROM _students st
CROSS JOIN LATERAL (
    SELECT st.student_id AS _c,
           NOW() - (floor(random()*45)::int * INTERVAL '1 day') AS det,
           random() < 0.7 AS res
) z
WHERE random() < 0.012;

INSERT INTO report_exports (school_id, generated_by_user_id, report_type, file_format,
                            storage_path, generated_at, metadata_json)
SELECT a.school_id, a.user_id, v.rt, v.ff,
       '/exports/' || lower(v.rt) || '_' || to_char(NOW(), 'YYYYMMDD') || '.' || lower(v.ff),
       NOW() - (floor(random()*30)::int * INTERVAL '1 day'),
       jsonb_build_object('seed', TRUE)
FROM _admins a
CROSS JOIN (VALUES ('ASISTENCIA_MENSUAL','PDF'),('RIESGO_ESTUDIANTIL','XLSX'),('INCIDENTES','PDF')) v(rt, ff)
WHERE a.ridx = 1;

INSERT INTO user_commands (command_id, school_id, executed_by_user_id, command_type,
                           target_entity_type, executed_at, metadata_json)
SELECT uuid_generate_v4(), a.school_id, a.user_id, v.ct, v.et,
       NOW() - (floor(random()*60)::int * INTERVAL '1 day'),
       jsonb_build_object('seed', TRUE)
FROM _admins a
CROSS JOIN (VALUES ('ONBOARDING_SCHEDULE','school'),('GROUPS_ONBOARDING','school'),
                   ('RISK_CONFIG','school'),('EXPORT_REPORT','report'),
                   ('CITACION_ACUDIENTE','guardian')) v(ct, et)
WHERE a.ridx = 1;

-- Casos de seguimiento (estudiantes de perfil problemático) + notas
CREATE TEMP TABLE _tracking ON COMMIT DROP AS
SELECT x.school_id, x.student_id, x.st_status,
       COALESCE(
         (SELECT a.user_id FROM _admins a WHERE a.school_id = x.school_id
            AND a.role_name IN ('COUNSELOR','COORDINATOR')
            ORDER BY CASE WHEN a.role_name='COUNSELOR' THEN 0 ELSE 1 END LIMIT 1),
         (SELECT a2.user_id FROM _admins a2 WHERE a2.school_id = x.school_id LIMIT 1)
       ) AS assigned_to
FROM (
    SELECT st.school_id, st.student_id,
           (ARRAY['en proceso','en proceso','resuelto','escalado'])[1 + floor(random()*4)::int] AS st_status,
           row_number() OVER (PARTITION BY st.school_id ORDER BY random()) AS rn
    FROM _students st WHERE st.profile IN ('severe','evader','absent')
) x WHERE x.rn <= 10;

INSERT INTO student_tracking (school_id, student_id, status, dependency,
                              assigned_to_user_id, origin_type, created_at)
SELECT t.school_id, t.student_id, t.st_status,
       (ARRAY['coordinacion','psicoorientacion','rectoria','convivencia'])[1 + floor(random()*4)::int],
       t.assigned_to, 'manual',
       NOW() - (floor(random()*30)::int * INTERVAL '1 day')
FROM _tracking t;

INSERT INTO student_tracking_notes (tracking_id, user_id, note_text)
SELECT tk.tracking_id, tk.assigned_to_user_id,
       (ARRAY['Se contacta al acudiente — cita pendiente.',
              'Estudiante compromete mejora en puntualidad.',
              'Se agenda visita domiciliaria con trabajadora social.',
              'Caso remitido a psicoorientación para valoración.'])[1 + floor(random()*4)::int]
FROM student_tracking tk
JOIN _tracking t ON t.student_id = tk.student_id AND t.school_id = tk.school_id
                AND t.assigned_to IS NOT NULL
CROSS JOIN generate_series(1, 1 + mod(abs(hashtext(tk.tracking_id::text)::bigint), 3)) n;

-- Métricas de comportamiento agregadas (ventana 30 días)
INSERT INTO student_behavior_metrics
    (school_id, student_id, late_count, absence_count, total_events,
     risk_score, risk_level, calculation_window_days, metadata_json)
SELECT i.school_id, i.student_id,
       count(*) FILTER (WHERE i.incident_type = 'LATE_ARRIVAL'),
       count(*) FILTER (WHERE i.incident_type IN ('INASISTENCIA','UNAUTHORIZED_ABSENCE')),
       count(*),
       LEAST(10.0,
             count(*) FILTER (WHERE i.incident_type = 'LATE_ARRIVAL') * 0.5 +
             count(*) FILTER (WHERE i.incident_type IN ('INASISTENCIA','UNAUTHORIZED_ABSENCE')) * 2.0 +
             count(*) FILTER (WHERE i.incident_type = 'EVASION_INTERNA') * 4.0)::numeric(5,2),
       CASE
           WHEN LEAST(10.0,
             count(*) FILTER (WHERE i.incident_type = 'LATE_ARRIVAL') * 0.5 +
             count(*) FILTER (WHERE i.incident_type IN ('INASISTENCIA','UNAUTHORIZED_ABSENCE')) * 2.0 +
             count(*) FILTER (WHERE i.incident_type = 'EVASION_INTERNA') * 4.0) >= 8 THEN 'CRITICAL'
           WHEN LEAST(10.0,
             count(*) FILTER (WHERE i.incident_type = 'LATE_ARRIVAL') * 0.5 +
             count(*) FILTER (WHERE i.incident_type IN ('INASISTENCIA','UNAUTHORIZED_ABSENCE')) * 2.0 +
             count(*) FILTER (WHERE i.incident_type = 'EVASION_INTERNA') * 4.0) >= 5 THEN 'HIGH'
           WHEN LEAST(10.0,
             count(*) FILTER (WHERE i.incident_type = 'LATE_ARRIVAL') * 0.5 +
             count(*) FILTER (WHERE i.incident_type IN ('INASISTENCIA','UNAUTHORIZED_ABSENCE')) * 2.0 +
             count(*) FILTER (WHERE i.incident_type = 'EVASION_INTERNA') * 4.0) >= 2 THEN 'MEDIUM'
           ELSE 'LOW' END,
       30, jsonb_build_object('seed', TRUE)
FROM attendance_incidents i
WHERE i.detected_at > NOW() - INTERVAL '30 days'
GROUP BY i.school_id, i.student_id
ON CONFLICT (student_id, calculation_window_days) DO NOTHING;

-- Actualización OTA disponible (una por escuela)
INSERT INTO ota_updates (school_id, version, payload_url, payload_sha256, min_version, notes, active)
SELECT s.school_id, '1.0.1',
       'https://ota.nexo.local/firmware/1.0.1.bin',
       md5(random()::text || s.school_id::text), '1.0.0',
       'Mejoras de sincronización biométrica (seed)', FALSE
FROM _schools s
ON CONFLICT (school_id, version) DO NOTHING;

-- ---------------------------------------------------------------------------
-- 10. Motor de riesgo v3: política por defecto + evaluación de los estudiantes
--     con más incidentes (genera risk_active_snapshot, risk_alerts y
--     student_tracking derivado automáticamente — vía fn_evaluate_student_risk)
-- ---------------------------------------------------------------------------
SELECT fn_seed_default_risk_policy(s.school_id, a.user_id)
FROM _schools s
JOIN _admins a ON a.school_id = s.school_id AND a.ridx = 1
WHERE NOT EXISTS (
    SELECT 1 FROM risk_policies rp WHERE rp.school_id = s.school_id AND rp.is_active
);

CREATE TEMP TABLE _top_risk ON COMMIT DROP AS
SELECT i.student_id, i.school_id
FROM (
    SELECT i.student_id, i.school_id, count(*) AS c,
           row_number() OVER (PARTITION BY i.school_id ORDER BY count(*) DESC) AS rn
    FROM attendance_incidents i
    WHERE i.incident_type IN ('LATE_ARRIVAL','EVASION_INTERNA','INASISTENCIA')
      AND i.detected_at > NOW() - INTERVAL '21 days'
    GROUP BY i.student_id, i.school_id
) i WHERE i.rn <= 150;

SELECT count(*) AS students_evaluated
FROM (SELECT fn_evaluate_student_risk(student_id, school_id) FROM _top_risk) ev;

UPDATE schools SET risk_config_completed = TRUE
WHERE school_id IN (
    SELECT school_id FROM risk_policies WHERE is_active
);

COMMIT;

-- =============================================================================
-- RESUMEN ESPERADO (por escuela): 24 grupos · ~480-576 estudiantes · ~500
-- acudientes · 24 aulas+26 nodos edge · 720 schedules · ~75k eventos · ~2.5k
-- incidentes · WhatsApp + notificaciones · riesgo evaluado en top-150.
-- =============================================================================
