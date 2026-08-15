-- =============================================================================
-- 2026-21-rls-missing-tables.sql
-- =============================================================================
-- PROPÓSITO: Habilitar RLS en 10 tablas multi-tenant que tienen school_id
--            pero NO tienen Row Level Security habilitado.
--
-- HALLAZGO: VF-001 (NEXO-AUD-002) — 10 tablas con school_id sin RLS
-- SEVERIDAD: P0 CRITICAL
--
-- TABLAS AFECTADAS:
--   1. staff_records
--   2. academic_groups
--   3. classrooms
--   4. security_incidents
--   5. school_exit_authorizations
--   6. class_exit_authorizations
--   7. pedagogical_trip_authorizations
--   8. student_record_audit (particionada)
--   9. report_exports
--  10. internal_messages (particionada)
--
-- PATRÓN: Todas tienen columna school_id directa, por lo que se usa el mismo
--         patrón que las 25 tablas existentes con RLS:
--         school_id = get_current_school_id()
--
-- SEGURIDAD: Esta migration es ADITIVA — solo añade ENABLE RLS y CREATE POLICY.
--            No altera schema, no cambia datos existentes.
--            Las queries PHP existentes ya filtran por school_id = ?, por lo que
--            RLS es defense-in-depth y no cambia los resultados de queries válidas.
--            Si una sesión no tiene app.current_school_id seteado,
--            get_current_school_id() retorna NULL y school_id = NULL es FALSE
--            (fail-closed: no retorna filas).
--
-- DEPENDENCIAS: get_current_school_id() debe existir (creada en nexo_full_migration.sql:348)
-- =============================================================================

-- 1. staff_records
ALTER TABLE staff_records ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS str_select ON staff_records;
DROP POLICY IF EXISTS str_insert ON staff_records;
DROP POLICY IF EXISTS str_update ON staff_records;
DROP POLICY IF EXISTS str_delete ON staff_records;
CREATE POLICY str_select ON staff_records FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY str_insert ON staff_records FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY str_update ON staff_records FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY str_delete ON staff_records FOR DELETE USING(school_id = get_current_school_id());

-- 2. academic_groups
ALTER TABLE academic_groups ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ag_select ON academic_groups;
DROP POLICY IF EXISTS ag_insert ON academic_groups;
DROP POLICY IF EXISTS ag_update ON academic_groups;
DROP POLICY IF EXISTS ag_delete ON academic_groups;
CREATE POLICY ag_select ON academic_groups FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY ag_insert ON academic_groups FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY ag_update ON academic_groups FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY ag_delete ON academic_groups FOR DELETE USING(school_id = get_current_school_id());

-- 3. classrooms
ALTER TABLE classrooms ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS cr_select ON classrooms;
DROP POLICY IF EXISTS cr_insert ON classrooms;
DROP POLICY IF EXISTS cr_update ON classrooms;
DROP POLICY IF EXISTS cr_delete ON classrooms;
CREATE POLICY cr_select ON classrooms FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY cr_insert ON classrooms FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY cr_update ON classrooms FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY cr_delete ON classrooms FOR DELETE USING(school_id = get_current_school_id());

-- 4. security_incidents
ALTER TABLE security_incidents ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS si_select ON security_incidents;
DROP POLICY IF EXISTS si_insert ON security_incidents;
DROP POLICY IF EXISTS si_update ON security_incidents;
DROP POLICY IF EXISTS si_delete ON security_incidents;
CREATE POLICY si_select ON security_incidents FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY si_insert ON security_incidents FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY si_update ON security_incidents FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY si_delete ON security_incidents FOR DELETE USING(school_id = get_current_school_id());

-- 5. school_exit_authorizations
ALTER TABLE school_exit_authorizations ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sea_select ON school_exit_authorizations;
DROP POLICY IF EXISTS sea_insert ON school_exit_authorizations;
DROP POLICY IF EXISTS sea_update ON school_exit_authorizations;
DROP POLICY IF EXISTS sea_delete ON school_exit_authorizations;
CREATE POLICY sea_select ON school_exit_authorizations FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY sea_insert ON school_exit_authorizations FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY sea_update ON school_exit_authorizations FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY sea_delete ON school_exit_authorizations FOR DELETE USING(school_id = get_current_school_id());

-- 6. class_exit_authorizations
ALTER TABLE class_exit_authorizations ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS cea_select ON class_exit_authorizations;
DROP POLICY IF EXISTS cea_insert ON class_exit_authorizations;
DROP POLICY IF EXISTS cea_update ON class_exit_authorizations;
DROP POLICY IF EXISTS cea_delete ON class_exit_authorizations;
CREATE POLICY cea_select ON class_exit_authorizations FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY cea_insert ON class_exit_authorizations FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY cea_update ON class_exit_authorizations FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY cea_delete ON class_exit_authorizations FOR DELETE USING(school_id = get_current_school_id());

-- 7. pedagogical_trip_authorizations
ALTER TABLE pedagogical_trip_authorizations ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS pta_select ON pedagogical_trip_authorizations;
DROP POLICY IF EXISTS pta_insert ON pedagogical_trip_authorizations;
DROP POLICY IF EXISTS pta_update ON pedagogical_trip_authorizations;
DROP POLICY IF EXISTS pta_delete ON pedagogical_trip_authorizations;
CREATE POLICY pta_select ON pedagogical_trip_authorizations FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY pta_insert ON pedagogical_trip_authorizations FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY pta_update ON pedagogical_trip_authorizations FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY pta_delete ON pedagogical_trip_authorizations FOR DELETE USING(school_id = get_current_school_id());

-- 8. student_record_audit (particionada — RLS en parent, heredada por partitions)
ALTER TABLE student_record_audit ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sra_select ON student_record_audit;
DROP POLICY IF EXISTS sra_insert ON student_record_audit;
CREATE POLICY sra_select ON student_record_audit FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY sra_insert ON student_record_audit FOR INSERT WITH CHECK(school_id = get_current_school_id());

-- 9. report_exports
ALTER TABLE report_exports ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS re_select ON report_exports;
DROP POLICY IF EXISTS re_insert ON report_exports;
DROP POLICY IF EXISTS re_delete ON report_exports;
CREATE POLICY re_select ON report_exports FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY re_insert ON report_exports FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY re_delete ON report_exports FOR DELETE USING(school_id = get_current_school_id());

-- 10. internal_messages (particionada — RLS en parent, heredada por partitions)
ALTER TABLE internal_messages ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS im_select ON internal_messages;
DROP POLICY IF EXISTS im_insert ON internal_messages;
DROP POLICY IF EXISTS im_update ON internal_messages;
DROP POLICY IF EXISTS im_delete ON internal_messages;
CREATE POLICY im_select ON internal_messages FOR SELECT USING(school_id = get_current_school_id());
CREATE POLICY im_insert ON internal_messages FOR INSERT WITH CHECK(school_id = get_current_school_id());
CREATE POLICY im_update ON internal_messages FOR UPDATE USING(school_id = get_current_school_id());
CREATE POLICY im_delete ON internal_messages FOR DELETE USING(school_id = get_current_school_id());

-- =============================================================================
-- VERIFICACIÓN POST-MIGRACIÓN (ejecutar manualmente para validar):
--
-- SELECT tablename FROM pg_tables WHERE schemaname = 'public'
--   AND tablename IN ('staff_records','academic_groups','classrooms',
--     'security_incidents','school_exit_authorizations','class_exit_authorizations',
--     'pedagogical_trip_authorizations','student_record_audit','report_exports',
--     'internal_messages')
--   AND rowsecurity = true;
-- Expected: 10 rows
--
-- SELECT tablename, policyname FROM pg_policies WHERE schemaname = 'public'
--   AND tablename IN ('staff_records','academic_groups','classrooms',
--     'security_incidents','school_exit_authorizations','class_exit_authorizations',
--     'pedagogical_trip_authorizations','student_record_audit','report_exports',
--     'internal_messages')
-- ORDER BY tablename, policyname;
-- Expected: 38 policies total
-- =============================================================================
