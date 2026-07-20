-- ============================================================
-- T6: Row-Level Security (RLS) - Aislamiento por Colegio
-- ============================================================
-- Este script habilita RLS en todas las tablas con school_id
-- para garantizar que ningún usuario (ni siquiera con bugs
-- en PHP) pueda leer datos de otro colegio.
--
-- El backend PHP debe ejecutar antes de cada request:
--   SET app.current_school_id = '<school_id>';
--   SET app.current_role = '<role_name>';
-- ============================================================

-- 1. Función auxiliar: obtener school_id del contexto de sesión
CREATE OR REPLACE FUNCTION get_current_school_id()
RETURNS INTEGER STABLE AS $$
DECLARE
    v_school_id TEXT;
BEGIN
    v_school_id := current_setting('app.current_school_id', true);
    IF v_school_id IS NULL OR v_school_id = '' THEN
        RETURN NULL;
    END IF;
    RETURN v_school_id::INTEGER;
EXCEPTION WHEN OTHERS THEN
    RETURN NULL;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- 2. Función auxiliar: verificar si el rol actual es SUPER_RECTOR
CREATE OR REPLACE FUNCTION is_super_rector()
RETURNS BOOLEAN STABLE AS $$
DECLARE
    v_role TEXT;
BEGIN
    v_role := current_setting('app.current_role', true);
    RETURN (v_role = 'SUPER_RECTOR');
EXCEPTION WHEN OTHERS THEN
    RETURN FALSE;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ============================================================
-- TABLAS CON school_id DIRECTO
-- ============================================================

-- students
ALTER TABLE students ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS students_select ON students;
DROP POLICY IF EXISTS students_insert ON students;
DROP POLICY IF EXISTS students_update ON students;
DROP POLICY IF EXISTS students_delete ON students;
CREATE POLICY students_select ON students FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY students_insert ON students FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY students_update ON students FOR UPDATE
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY students_delete ON students FOR DELETE
    USING (school_id = get_current_school_id() OR is_super_rector());

-- biometric_events
ALTER TABLE biometric_events ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS be_select ON biometric_events;
DROP POLICY IF EXISTS be_insert ON biometric_events;
DROP POLICY IF EXISTS be_update ON biometric_events;
DROP POLICY IF EXISTS be_delete ON biometric_events;
CREATE POLICY be_select ON biometric_events FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY be_insert ON biometric_events FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY be_update ON biometric_events FOR UPDATE
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY be_delete ON biometric_events FOR DELETE
    USING (school_id = get_current_school_id() OR is_super_rector());

-- attendance_incidents
ALTER TABLE attendance_incidents ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ai_select ON attendance_incidents;
DROP POLICY IF EXISTS ai_insert ON attendance_incidents;
DROP POLICY IF EXISTS ai_update ON attendance_incidents;
DROP POLICY IF EXISTS ai_delete ON attendance_incidents;
CREATE POLICY ai_select ON attendance_incidents FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY ai_insert ON attendance_incidents FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY ai_update ON attendance_incidents FOR UPDATE
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY ai_delete ON attendance_incidents FOR DELETE
    USING (school_id = get_current_school_id() OR is_super_rector());

-- sos_alerts
ALTER TABLE sos_alerts ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sos_select ON sos_alerts;
DROP POLICY IF EXISTS sos_insert ON sos_alerts;
DROP POLICY IF EXISTS sos_update ON sos_alerts;
DROP POLICY IF EXISTS sos_delete ON sos_alerts;
CREATE POLICY sos_select ON sos_alerts FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY sos_insert ON sos_alerts FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY sos_update ON sos_alerts FOR UPDATE
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY sos_delete ON sos_alerts FOR DELETE
    USING (school_id = get_current_school_id() OR is_super_rector());

-- global_audit_logs
ALTER TABLE global_audit_logs ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS gal_select ON global_audit_logs;
DROP POLICY IF EXISTS gal_insert ON global_audit_logs;
CREATE POLICY gal_select ON global_audit_logs FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY gal_insert ON global_audit_logs FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());

-- twilio_messages
ALTER TABLE twilio_messages ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tm_select ON twilio_messages;
DROP POLICY IF EXISTS tm_insert ON twilio_messages;
DROP POLICY IF EXISTS tm_update ON twilio_messages;
DROP POLICY IF EXISTS tm_delete ON twilio_messages;
CREATE POLICY tm_select ON twilio_messages FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY tm_insert ON twilio_messages FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY tm_update ON twilio_messages FOR UPDATE
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY tm_delete ON twilio_messages FOR DELETE
    USING (school_id = get_current_school_id() OR is_super_rector());

-- edge_devices
ALTER TABLE edge_devices ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ed_select ON edge_devices;
DROP POLICY IF EXISTS ed_insert ON edge_devices;
DROP POLICY IF EXISTS ed_update ON edge_devices;
DROP POLICY IF EXISTS ed_delete ON edge_devices;
CREATE POLICY ed_select ON edge_devices FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY ed_insert ON edge_devices FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY ed_update ON edge_devices FOR UPDATE
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY ed_delete ON edge_devices FOR DELETE
    USING (school_id = get_current_school_id() OR is_super_rector());

-- student_behavior_metrics
ALTER TABLE student_behavior_metrics ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sbm_select ON student_behavior_metrics;
DROP POLICY IF EXISTS sbm_insert ON student_behavior_metrics;
DROP POLICY IF EXISTS sbm_update ON student_behavior_metrics;
DROP POLICY IF EXISTS sbm_delete ON student_behavior_metrics;
CREATE POLICY sbm_select ON student_behavior_metrics FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY sbm_insert ON student_behavior_metrics FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY sbm_update ON student_behavior_metrics FOR UPDATE
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY sbm_delete ON student_behavior_metrics FOR DELETE
    USING (school_id = get_current_school_id() OR is_super_rector());

-- user_commands
ALTER TABLE user_commands ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS uc_select ON user_commands;
DROP POLICY IF EXISTS uc_insert ON user_commands;
DROP POLICY IF EXISTS uc_update ON user_commands;
DROP POLICY IF EXISTS uc_delete ON user_commands;
CREATE POLICY uc_select ON user_commands FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY uc_insert ON user_commands FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY uc_update ON user_commands FOR UPDATE
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY uc_delete ON user_commands FOR DELETE
    USING (school_id = get_current_school_id() OR is_super_rector());

-- ============================================================
-- TABLAS RELACIONADAS (sin school_id directo, filtrado indirecto)
-- ============================================================

-- guardian_student_relationships: filtrar via student_id -> students.school_id
ALTER TABLE guardian_student_relationships ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS gsr_select ON guardian_student_relationships;
DROP POLICY IF EXISTS gsr_insert ON guardian_student_relationships;
DROP POLICY IF EXISTS gsr_delete ON guardian_student_relationships;
CREATE POLICY gsr_select ON guardian_student_relationships FOR SELECT
    USING (EXISTS (
        SELECT 1 FROM students s
        WHERE s.student_id = guardian_student_relationships.student_id
          AND s.school_id = get_current_school_id()
    ) OR is_super_rector());
CREATE POLICY gsr_insert ON guardian_student_relationships FOR INSERT
    WITH CHECK (EXISTS (
        SELECT 1 FROM students s
        WHERE s.student_id = guardian_student_relationships.student_id
          AND s.school_id = get_current_school_id()
    ) OR is_super_rector());
CREATE POLICY gsr_delete ON guardian_student_relationships FOR DELETE
    USING (EXISTS (
        SELECT 1 FROM students s
        WHERE s.student_id = guardian_student_relationships.student_id
          AND s.school_id = get_current_school_id()
    ) OR is_super_rector());

-- student_group_assignments: filtrar via group_id -> academic_groups.school_id
ALTER TABLE student_group_assignments ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sga_select ON student_group_assignments;
DROP POLICY IF EXISTS sga_insert ON student_group_assignments;
DROP POLICY IF EXISTS sga_update ON student_group_assignments;
DROP POLICY IF EXISTS sga_delete ON student_group_assignments;
CREATE POLICY sga_select ON student_group_assignments FOR SELECT
    USING (EXISTS (
        SELECT 1 FROM academic_groups ag
        WHERE ag.group_id = student_group_assignments.group_id
          AND ag.school_id = get_current_school_id()
    ) OR is_super_rector());
CREATE POLICY sga_insert ON student_group_assignments FOR INSERT
    WITH CHECK (EXISTS (
        SELECT 1 FROM academic_groups ag
        WHERE ag.group_id = student_group_assignments.group_id
          AND ag.school_id = get_current_school_id()
    ) OR is_super_rector());
CREATE POLICY sga_update ON student_group_assignments FOR UPDATE
    USING (EXISTS (
        SELECT 1 FROM academic_groups ag
        WHERE ag.group_id = student_group_assignments.group_id
          AND ag.school_id = get_current_school_id()
    ) OR is_super_rector());
CREATE POLICY sga_delete ON student_group_assignments FOR DELETE
    USING (EXISTS (
        SELECT 1 FROM academic_groups ag
        WHERE ag.group_id = student_group_assignments.group_id
          AND ag.school_id = get_current_school_id()
    ) OR is_super_rector());

-- jwt_blocklist: tabla global (sin school_id), permite INSERT/SELECT para operaciones del sistema
-- FIX (BUG-8): jwt_blocklist es una tabla de sistema para revocación de JWT, no datos de usuario.
-- Las operaciones revokeJwt() e isJwtRevoked() se ejecutan antes de requireAuth() configure el rol,
-- por lo que la política no puede depender de is_super_rector(). Se permite acceso a cualquier conexión.
ALTER TABLE jwt_blocklist ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS jbl_select ON jwt_blocklist;
DROP POLICY IF EXISTS jbl_insert ON jwt_blocklist;
CREATE POLICY jbl_select ON jwt_blocklist FOR SELECT
    USING (true);
CREATE POLICY jbl_insert ON jwt_blocklist FOR INSERT
    WITH CHECK (true);

-- ============================================================
-- NOTA PARA EL BACKEND PHP
-- ============================================================
-- Antes de ejecutar cualquier consulta, el backend debe hacer:
--
--   $conn->exec("SET app.current_school_id = '{$authUser['school_id']}'");
--   $conn->exec("SET app.current_role = '{$authUser['role']}'");
--
-- Esto activa el contexto de sesión que PostgreSQL usa para evaluar
-- las políticas RLS. Sin esta línea, get_current_school_id() retorna
-- NULL y las políticas bloquean TODAS las filas (fail-closed).
-- ============================================================

-- ============================================================
-- 9. TABLAS MULTI-TENANT RESTANTES (Users y Notifications)
-- ============================================================

-- Users
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
CREATE POLICY users_select ON users FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());

-- Notifications
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
CREATE POLICY notifications_select ON notifications FOR SELECT
    USING (school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY notifications_insert ON notifications FOR INSERT
    WITH CHECK (school_id = get_current_school_id() OR is_super_rector());
