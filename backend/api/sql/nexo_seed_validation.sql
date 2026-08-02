-- =============================================================================
-- NEXO SEED VALIDATION — Tests de integridad del seed contra DB viva
-- =============================================================================
-- Ejecutar DESPUÉS de nexo_seed.sql para validar que todos los datos
-- son consistentes y cumplen las dependencias del sistema.
--
-- Uso:
--   psql "postgresql://..." -f backend/api/sql/nexo_seed_validation.sql
--
-- Resultado esperado: todas las queries devuelven 0 filas (sin errores)
-- Si alguna devuelve filas, hay un problema de integridad.
-- =============================================================================

\set ON_ERROR_STOP off

-- =============================================================================
-- 1. USUARIOS — Todos deben tener work_shift, email, phone, role_id válido
-- =============================================================================
\echo '=== 1. USUARIOS sin work_shift ==='
SELECT user_id, email, role_name
FROM users u JOIN roles r ON u.role_id = r.role_id
WHERE u.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND u.work_shift IS NULL
  AND r.role_name != 'GUARDIAN';

\echo '=== 2. USUARIOS sin email o phone ==='
SELECT user_id, document_number, first_name, last_name
FROM users
WHERE school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND (email IS NULL OR phone IS NULL OR email = '' OR phone = '');

\echo '=== 3. USUARIOS con role_id inexistente ==='
SELECT u.user_id, u.email, u.role_id
FROM users u
LEFT JOIN roles r ON u.role_id = r.role_id
WHERE u.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND r.role_id IS NULL;

\echo '=== 4. USUARIOS con school_id inexistente ==='
SELECT u.user_id, u.email, u.school_id
FROM users u
LEFT JOIN schools s ON u.school_id = s.school_id
WHERE u.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND s.school_id IS NULL;

-- =============================================================================
-- 2. CONTeos por ROL — Verificar cantidades esperadas
-- =============================================================================
\echo '=== 5. Conteo de usuarios por rol (esperado: RECTOR=2, COORDINATOR=3, TEACHER=9, COUNSELOR=3, SECRETARY=5, SECURITY=2, AUXILIARY=4, GUARDIAN=50) ==='
SELECT r.role_name, COUNT(*) as total
FROM users u JOIN roles r ON u.role_id = r.role_id
WHERE u.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
GROUP BY r.role_name
ORDER BY r.role_name;

-- =============================================================================
-- 3. DOCENTES — Todos deben tener horario (schedule) asignado
-- =============================================================================
\echo '=== 6. DOCENTES sin horario asignado (debe ser 0) ==='
SELECT u.user_id, u.email, u.first_name, u.last_name
FROM users u
JOIN roles r ON u.role_id = r.role_id
WHERE r.role_name = 'TEACHER'
  AND u.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND u.user_id NOT IN (SELECT DISTINCT teacher_user_id FROM schedules);

\echo '=== 7. DOCENTES sin work_shift (debe ser 0) ==='
SELECT u.user_id, u.email
FROM users u JOIN roles r ON u.role_id = r.role_id
WHERE r.role_name = 'TEACHER'
  AND u.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND (u.work_shift IS NULL OR u.work_shift = '');

-- =============================================================================
-- 4. HORARIOS — Cada schedule debe tener group, subject, classroom, teacher válidos
-- =============================================================================
\echo '=== 8. SCHEDULES con group_id inexistente (debe ser 0) ==='
SELECT s.schedule_id, s.group_id
FROM schedules s
LEFT JOIN academic_groups g ON s.group_id = g.group_id
WHERE g.group_id IS NULL;

\echo '=== 9. SCHEDULES con subject_id inexistente (debe ser 0) ==='
SELECT s.schedule_id, s.subject_id
FROM schedules s
LEFT JOIN subjects sub ON s.subject_id = sub.subject_id
WHERE sub.subject_id IS NULL;

\echo '=== 10. SCHEDULES con classroom_id inexistente (debe ser 0) ==='
SELECT s.schedule_id, s.classroom_id
FROM schedules s
LEFT JOIN classrooms c ON s.classroom_id = c.classroom_id
WHERE c.classroom_id IS NULL;

\echo '=== 11. SCHEDULES con teacher_user_id inexistente (debe ser 0) ==='
SELECT s.schedule_id, s.teacher_user_id
FROM schedules s
LEFT JOIN users u ON s.teacher_user_id = u.user_id
WHERE u.user_id IS NULL;

-- =============================================================================
-- 5. ESTUDIANTES — Todos deben tener grupo asignado
-- =============================================================================
\echo '=== 12. ESTUDIANTES sin grupo asignado (debe ser 0) ==='
SELECT s.student_id, s.document_number, s.first_name, s.last_name
FROM students s
WHERE s.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND s.student_id NOT IN (
    SELECT student_id FROM student_group_assignments WHERE active = TRUE
  );

\echo '=== 13. Conteo de estudiantes por grupo (esperado: 10 por grupo) ==='
SELECT g.group_name, g.grade_level, COUNT(sga.student_id) as total
FROM academic_groups g
LEFT JOIN student_group_assignments sga ON g.group_id = sga.group_id AND sga.active = TRUE
WHERE g.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
GROUP BY g.group_name, g.grade_level
ORDER BY g.group_name;

-- =============================================================================
-- 6. ACUDIENTES — Todos deben tener relationship con estudiante
-- =============================================================================
\echo '=== 14. ACUDIENTES sin relación a estudiante (debe ser 0) ==='
SELECT g.guardian_id, g.whatsapp_phone
FROM guardians g
JOIN users u ON g.user_id = u.user_id
WHERE u.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND g.guardian_id NOT IN (
    SELECT guardian_id FROM guardian_student_relationships
  );

-- =============================================================================
-- 7. STAFF RECORDS — Todos los usuarios no-guardian deben tener staff_record
-- =============================================================================
\echo '=== 15. USUARIOS sin staff_record (excepto GUARDIAN, debe ser 0) ==='
SELECT u.user_id, u.email, r.role_name
FROM users u
JOIN roles r ON u.role_id = r.role_id
WHERE u.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND r.role_name != 'GUARDIAN'
  AND u.user_id NOT IN (SELECT user_id FROM staff_records);

-- =============================================================================
-- 8. PERMISOS — Cada rol debe tener permisos asignados
-- =============================================================================
\echo '=== 16. ROLES sin permisos asignados (debe ser 0) ==='
SELECT r.role_name
FROM roles r
WHERE r.role_name != 'GUARDIAN'
  AND r.role_id NOT IN (SELECT role_id FROM role_permissions);

-- =============================================================================
-- 9. EVENTOS BIOMÉTRICOS — Hoy debe haber 4 INGRESO + 2 INGRESO_TARDE por grupo
-- =============================================================================
\echo '=== 17. Eventos biométricos de hoy por grupo (esperado: 4 INGRESO + 2 INGRESO_TARDE) ==='
SELECT g.group_name,
       be.event_type,
       COUNT(*) as total
FROM biometric_events be
JOIN student_group_assignments sga ON be.student_id = sga.student_id AND sga.active = TRUE
JOIN academic_groups g ON sga.group_id = g.group_id
WHERE be.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND be.event_timestamp::date = CURRENT_DATE
GROUP BY g.group_name, be.event_type
ORDER BY g.group_name, be.event_type;

-- =============================================================================
-- 10. INCIDENTES DE ASISTENCIA — Hoy debe haber 2 INASISTENCIA + 1 PERMISO + 1 AUTORIZAR_SALIDA por grupo
-- =============================================================================
\echo '=== 18. Incidentes de asistencia de hoy por grupo ==='
SELECT g.group_name,
       ai.incident_type,
       COUNT(*) as total
FROM attendance_incidents ai
JOIN student_group_assignments sga ON ai.student_id = sga.student_id AND sga.active = TRUE
JOIN academic_groups g ON sga.group_id = g.group_id
WHERE ai.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND ai.detected_at::date = CURRENT_DATE
GROUP BY g.group_name, ai.incident_type
ORDER BY g.group_name, ai.incident_type;

-- =============================================================================
-- 11. SOS ALERTS — Debe haber 2 activas + 3 resueltas + 10 históricas
-- =============================================================================
\echo '=== 19. SOS alerts por estado ==='
SELECT
  CASE WHEN resolved THEN 'resueltas' ELSE 'activas' END as estado,
  COUNT(*) as total
FROM sos_alerts
WHERE school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
GROUP BY resolved;

-- =============================================================================
-- 12. DUPLICADOS — Emails y document_numbers deben ser únicos
-- =============================================================================
\echo '=== 20. Emails duplicados (debe ser 0) ==='
SELECT email, COUNT(*) as cnt
FROM users
WHERE school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND email IS NOT NULL
GROUP BY email
HAVING COUNT(*) > 1;

\echo '=== 21. Document numbers duplicados (debe ser 0) ==='
SELECT document_number, COUNT(*) as cnt
FROM users
WHERE school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
GROUP BY document_number
HAVING COUNT(*) > 1;

-- =============================================================================
-- 13. VERIFICACIÓN DE JORNADAS — work_shift debe ser válido
-- =============================================================================
\echo '=== 22. Usuarios con work_shift inválido (debe ser 0) ==='
SELECT user_id, email, work_shift
FROM users
WHERE school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND work_shift IS NOT NULL
  AND work_shift NOT IN ('mañana', 'tarde', 'noche', 'completa');

-- =============================================================================
-- 14. NOTIFICATIONS — Todos los usuarios no-guardian deben tener al menos 1
-- =============================================================================
\echo '=== 23. Usuarios sin notificación (excepto GUARDIAN, debe ser 0) ==='
SELECT u.user_id, u.email, r.role_name
FROM users u
JOIN roles r ON u.role_id = r.role_id
WHERE u.school_id = 'a3333333-3333-3333-3333-333333333333'::UUID
  AND r.role_name != 'GUARDIAN'
  AND u.user_id NOT IN (SELECT user_id FROM notifications WHERE school_id = 'a3333333-3333-3333-3333-333333333333'::UUID);

-- =============================================================================
-- 15. RESUMEN FINAL
-- =============================================================================
\echo '=== 24. RESUMEN: Conteos totales por tabla ==='
SELECT 'users (no guardian)' as tabla, COUNT(*) as total FROM users u JOIN roles r ON u.role_id=r.role_id WHERE u.school_id='a3333333-3333-3333-3333-333333333333'::UUID AND r.role_name!='GUARDIAN'
UNION ALL
SELECT 'guardians', COUNT(*) FROM guardians g JOIN users u ON g.user_id=u.user_id WHERE u.school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'students', COUNT(*) FROM students WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'academic_groups', COUNT(*) FROM academic_groups WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'subjects', COUNT(*) FROM subjects
UNION ALL
SELECT 'classrooms', COUNT(*) FROM classrooms WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'schedules', COUNT(*) FROM schedules
UNION ALL
SELECT 'staff_records', COUNT(*) FROM staff_records WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'biometric_events', COUNT(*) FROM biometric_events WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'attendance_incidents', COUNT(*) FROM attendance_incidents WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'sos_alerts', COUNT(*) FROM sos_alerts WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'notifications', COUNT(*) FROM notifications WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'student_behavior_metrics', COUNT(*) FROM student_behavior_metrics WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'student_tracking', COUNT(*) FROM student_tracking WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'student_tracking_notes', COUNT(*) FROM student_tracking_notes
UNION ALL
SELECT 'internal_messages', COUNT(*) FROM internal_messages WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'twilio_messages', COUNT(*) FROM twilio_messages WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'user_commands', COUNT(*) FROM user_commands WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'security_incidents', COUNT(*) FROM security_incidents WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'global_audit_logs', COUNT(*) FROM global_audit_logs WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'report_exports', COUNT(*) FROM report_exports WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'edge_devices', COUNT(*) FROM edge_devices WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'school_panic_events', COUNT(*) FROM school_panic_events WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'contact_leads', COUNT(*) FROM contact_leads
UNION ALL
SELECT 'verification_codes', COUNT(*) FROM verification_codes
UNION ALL
SELECT 'exit_authorizations (class)', COUNT(*) FROM class_exit_authorizations WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'exit_authorizations (school)', COUNT(*) FROM school_exit_authorizations WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
UNION ALL
SELECT 'trip_authorizations', COUNT(*) FROM pedagogical_trip_authorizations WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID
ORDER BY tabla;

\echo '=== FIN DE VALIDACIÓN ==='
