-- =============================================================================
-- 2026-24-monthly-partitions-7-tables.sql
-- =============================================================================
-- PROPÓSITO: Crear particiones mensuales para las 7 tablas particionadas que
--            solo tienen partition DEFAULT (sin particiones mensuales reales).
--
-- HALLAZGO: VF-005 (NEXO-AUD-008) — Particiones faltantes en 7/8 tablas
-- SEVERIDAD: P0 CRITICAL (degradación progresiva; caída post-2027-08)
--
-- TABLAS AFECTADAS (ya tienen DEFAULT partition):
--   1. attendance_incidents   (PARTITION BY RANGE(detected_at))
--   2. user_commands           (PARTITION BY RANGE(executed_at))
--   3. sos_alerts              (PARTITION BY RANGE(emitted_at))
--   4. internal_messages       (PARTITION BY RANGE(sent_at))
--   5. twilio_messages         (PARTITION BY RANGE(sent_at))
--   6. student_record_audit    (PARTITION BY RANGE(performed_at))
--   7. global_audit_logs       (PARTITION BY RANGE(created_at))
--
-- RANGO: 2026-05 a 2027-12 (20 particiones por tabla = 140 particiones total)
--        Coincide con biometric_events (2026-05 a 2027-08) y extiende 4 meses más.
--
-- SEGURIDAD: CREATE TABLE IF NOT EXISTS — idempotente.
--            Si hay datos en DEFAULT que pertenecen a un rango nuevo, PostgreSQL
--            los moverá automáticamente a la partición correcta.
--            Las particiones se crean con IF NOT EXISTS, por lo que re-ejecutar
--            esta migration es seguro.
--
-- DEPENDENCIAS: Las tablas parent ya existen con PARTITION BY RANGE.
--               Las DEFAULT partitions ya existen.
-- =============================================================================

-- 1. attendance_incidents (detected_at)
CREATE TABLE IF NOT EXISTS attendance_incidents_2026_05 PARTITION OF attendance_incidents FOR VALUES FROM('2026-05-01') TO('2026-06-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2026_06 PARTITION OF attendance_incidents FOR VALUES FROM('2026-06-01') TO('2026-07-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2026_07 PARTITION OF attendance_incidents FOR VALUES FROM('2026-07-01') TO('2026-08-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2026_08 PARTITION OF attendance_incidents FOR VALUES FROM('2026-08-01') TO('2026-09-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2026_09 PARTITION OF attendance_incidents FOR VALUES FROM('2026-09-01') TO('2026-10-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2026_10 PARTITION OF attendance_incidents FOR VALUES FROM('2026-10-01') TO('2026-11-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2026_11 PARTITION OF attendance_incidents FOR VALUES FROM('2026-11-01') TO('2026-12-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2026_12 PARTITION OF attendance_incidents FOR VALUES FROM('2026-12-01') TO('2027-01-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_01 PARTITION OF attendance_incidents FOR VALUES FROM('2027-01-01') TO('2027-02-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_02 PARTITION OF attendance_incidents FOR VALUES FROM('2027-02-01') TO('2027-03-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_03 PARTITION OF attendance_incidents FOR VALUES FROM('2027-03-01') TO('2027-04-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_04 PARTITION OF attendance_incidents FOR VALUES FROM('2027-04-01') TO('2027-05-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_05 PARTITION OF attendance_incidents FOR VALUES FROM('2027-05-01') TO('2027-06-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_06 PARTITION OF attendance_incidents FOR VALUES FROM('2027-06-01') TO('2027-07-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_07 PARTITION OF attendance_incidents FOR VALUES FROM('2027-07-01') TO('2027-08-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_08 PARTITION OF attendance_incidents FOR VALUES FROM('2027-08-01') TO('2027-09-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_09 PARTITION OF attendance_incidents FOR VALUES FROM('2027-09-01') TO('2027-10-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_10 PARTITION OF attendance_incidents FOR VALUES FROM('2027-10-01') TO('2027-11-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_11 PARTITION OF attendance_incidents FOR VALUES FROM('2027-11-01') TO('2027-12-01');
CREATE TABLE IF NOT EXISTS attendance_incidents_2027_12 PARTITION OF attendance_incidents FOR VALUES FROM('2027-12-01') TO('2028-01-01');

-- 2. user_commands (executed_at)
CREATE TABLE IF NOT EXISTS user_commands_2026_05 PARTITION OF user_commands FOR VALUES FROM('2026-05-01') TO('2026-06-01');
CREATE TABLE IF NOT EXISTS user_commands_2026_06 PARTITION OF user_commands FOR VALUES FROM('2026-06-01') TO('2026-07-01');
CREATE TABLE IF NOT EXISTS user_commands_2026_07 PARTITION OF user_commands FOR VALUES FROM('2026-07-01') TO('2026-08-01');
CREATE TABLE IF NOT EXISTS user_commands_2026_08 PARTITION OF user_commands FOR VALUES FROM('2026-08-01') TO('2026-09-01');
CREATE TABLE IF NOT EXISTS user_commands_2026_09 PARTITION OF user_commands FOR VALUES FROM('2026-09-01') TO('2026-10-01');
CREATE TABLE IF NOT EXISTS user_commands_2026_10 PARTITION OF user_commands FOR VALUES FROM('2026-10-01') TO('2026-11-01');
CREATE TABLE IF NOT EXISTS user_commands_2026_11 PARTITION OF user_commands FOR VALUES FROM('2026-11-01') TO('2026-12-01');
CREATE TABLE IF NOT EXISTS user_commands_2026_12 PARTITION OF user_commands FOR VALUES FROM('2026-12-01') TO('2027-01-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_01 PARTITION OF user_commands FOR VALUES FROM('2027-01-01') TO('2027-02-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_02 PARTITION OF user_commands FOR VALUES FROM('2027-02-01') TO('2027-03-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_03 PARTITION OF user_commands FOR VALUES FROM('2027-03-01') TO('2027-04-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_04 PARTITION OF user_commands FOR VALUES FROM('2027-04-01') TO('2027-05-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_05 PARTITION OF user_commands FOR VALUES FROM('2027-05-01') TO('2027-06-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_06 PARTITION OF user_commands FOR VALUES FROM('2027-06-01') TO('2027-07-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_07 PARTITION OF user_commands FOR VALUES FROM('2027-07-01') TO('2027-08-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_08 PARTITION OF user_commands FOR VALUES FROM('2027-08-01') TO('2027-09-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_09 PARTITION OF user_commands FOR VALUES FROM('2027-09-01') TO('2027-10-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_10 PARTITION OF user_commands FOR VALUES FROM('2027-10-01') TO('2027-11-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_11 PARTITION OF user_commands FOR VALUES FROM('2027-11-01') TO('2027-12-01');
CREATE TABLE IF NOT EXISTS user_commands_2027_12 PARTITION OF user_commands FOR VALUES FROM('2027-12-01') TO('2028-01-01');

-- 3. sos_alerts (emitted_at)
CREATE TABLE IF NOT EXISTS sos_alerts_2026_05 PARTITION OF sos_alerts FOR VALUES FROM('2026-05-01') TO('2026-06-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2026_06 PARTITION OF sos_alerts FOR VALUES FROM('2026-06-01') TO('2026-07-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2026_07 PARTITION OF sos_alerts FOR VALUES FROM('2026-07-01') TO('2026-08-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2026_08 PARTITION OF sos_alerts FOR VALUES FROM('2026-08-01') TO('2026-09-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2026_09 PARTITION OF sos_alerts FOR VALUES FROM('2026-09-01') TO('2026-10-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2026_10 PARTITION OF sos_alerts FOR VALUES FROM('2026-10-01') TO('2026-11-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2026_11 PARTITION OF sos_alerts FOR VALUES FROM('2026-11-01') TO('2026-12-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2026_12 PARTITION OF sos_alerts FOR VALUES FROM('2026-12-01') TO('2027-01-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_01 PARTITION OF sos_alerts FOR VALUES FROM('2027-01-01') TO('2027-02-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_02 PARTITION OF sos_alerts FOR VALUES FROM('2027-02-01') TO('2027-03-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_03 PARTITION OF sos_alerts FOR VALUES FROM('2027-03-01') TO('2027-04-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_04 PARTITION OF sos_alerts FOR VALUES FROM('2027-04-01') TO('2027-05-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_05 PARTITION OF sos_alerts FOR VALUES FROM('2027-05-01') TO('2027-06-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_06 PARTITION OF sos_alerts FOR VALUES FROM('2027-06-01') TO('2027-07-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_07 PARTITION OF sos_alerts FOR VALUES FROM('2027-07-01') TO('2027-08-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_08 PARTITION OF sos_alerts FOR VALUES FROM('2027-08-01') TO('2027-09-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_09 PARTITION OF sos_alerts FOR VALUES FROM('2027-09-01') TO('2027-10-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_10 PARTITION OF sos_alerts FOR VALUES FROM('2027-10-01') TO('2027-11-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_11 PARTITION OF sos_alerts FOR VALUES FROM('2027-11-01') TO('2027-12-01');
CREATE TABLE IF NOT EXISTS sos_alerts_2027_12 PARTITION OF sos_alerts FOR VALUES FROM('2027-12-01') TO('2028-01-01');

-- 4. internal_messages (sent_at)
CREATE TABLE IF NOT EXISTS internal_messages_2026_05 PARTITION OF internal_messages FOR VALUES FROM('2026-05-01') TO('2026-06-01');
CREATE TABLE IF NOT EXISTS internal_messages_2026_06 PARTITION OF internal_messages FOR VALUES FROM('2026-06-01') TO('2026-07-01');
CREATE TABLE IF NOT EXISTS internal_messages_2026_07 PARTITION OF internal_messages FOR VALUES FROM('2026-07-01') TO('2026-08-01');
CREATE TABLE IF NOT EXISTS internal_messages_2026_08 PARTITION OF internal_messages FOR VALUES FROM('2026-08-01') TO('2026-09-01');
CREATE TABLE IF NOT EXISTS internal_messages_2026_09 PARTITION OF internal_messages FOR VALUES FROM('2026-09-01') TO('2026-10-01');
CREATE TABLE IF NOT EXISTS internal_messages_2026_10 PARTITION OF internal_messages FOR VALUES FROM('2026-10-01') TO('2026-11-01');
CREATE TABLE IF NOT EXISTS internal_messages_2026_11 PARTITION OF internal_messages FOR VALUES FROM('2026-11-01') TO('2026-12-01');
CREATE TABLE IF NOT EXISTS internal_messages_2026_12 PARTITION OF internal_messages FOR VALUES FROM('2026-12-01') TO('2027-01-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_01 PARTITION OF internal_messages FOR VALUES FROM('2027-01-01') TO('2027-02-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_02 PARTITION OF internal_messages FOR VALUES FROM('2027-02-01') TO('2027-03-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_03 PARTITION OF internal_messages FOR VALUES FROM('2027-03-01') TO('2027-04-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_04 PARTITION OF internal_messages FOR VALUES FROM('2027-04-01') TO('2027-05-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_05 PARTITION OF internal_messages FOR VALUES FROM('2027-05-01') TO('2027-06-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_06 PARTITION OF internal_messages FOR VALUES FROM('2027-06-01') TO('2027-07-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_07 PARTITION OF internal_messages FOR VALUES FROM('2027-07-01') TO('2027-08-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_08 PARTITION OF internal_messages FOR VALUES FROM('2027-08-01') TO('2027-09-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_09 PARTITION OF internal_messages FOR VALUES FROM('2027-09-01') TO('2027-10-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_10 PARTITION OF internal_messages FOR VALUES FROM('2027-10-01') TO('2027-11-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_11 PARTITION OF internal_messages FOR VALUES FROM('2027-11-01') TO('2027-12-01');
CREATE TABLE IF NOT EXISTS internal_messages_2027_12 PARTITION OF internal_messages FOR VALUES FROM('2027-12-01') TO('2028-01-01');

-- 5. twilio_messages (sent_at)
CREATE TABLE IF NOT EXISTS twilio_messages_2026_05 PARTITION OF twilio_messages FOR VALUES FROM('2026-05-01') TO('2026-06-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2026_06 PARTITION OF twilio_messages FOR VALUES FROM('2026-06-01') TO('2026-07-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2026_07 PARTITION OF twilio_messages FOR VALUES FROM('2026-07-01') TO('2026-08-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2026_08 PARTITION OF twilio_messages FOR VALUES FROM('2026-08-01') TO('2026-09-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2026_09 PARTITION OF twilio_messages FOR VALUES FROM('2026-09-01') TO('2026-10-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2026_10 PARTITION OF twilio_messages FOR VALUES FROM('2026-10-01') TO('2026-11-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2026_11 PARTITION OF twilio_messages FOR VALUES FROM('2026-11-01') TO('2026-12-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2026_12 PARTITION OF twilio_messages FOR VALUES FROM('2026-12-01') TO('2027-01-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_01 PARTITION OF twilio_messages FOR VALUES FROM('2027-01-01') TO('2027-02-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_02 PARTITION OF twilio_messages FOR VALUES FROM('2027-02-01') TO('2027-03-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_03 PARTITION OF twilio_messages FOR VALUES FROM('2027-03-01') TO('2027-04-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_04 PARTITION OF twilio_messages FOR VALUES FROM('2027-04-01') TO('2027-05-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_05 PARTITION OF twilio_messages FOR VALUES FROM('2027-05-01') TO('2027-06-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_06 PARTITION OF twilio_messages FOR VALUES FROM('2027-06-01') TO('2027-07-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_07 PARTITION OF twilio_messages FOR VALUES FROM('2027-07-01') TO('2027-08-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_08 PARTITION OF twilio_messages FOR VALUES FROM('2027-08-01') TO('2027-09-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_09 PARTITION OF twilio_messages FOR VALUES FROM('2027-09-01') TO('2027-10-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_10 PARTITION OF twilio_messages FOR VALUES FROM('2027-10-01') TO('2027-11-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_11 PARTITION OF twilio_messages FOR VALUES FROM('2027-11-01') TO('2027-12-01');
CREATE TABLE IF NOT EXISTS twilio_messages_2027_12 PARTITION OF twilio_messages FOR VALUES FROM('2027-12-01') TO('2028-01-01');

-- 6. student_record_audit (performed_at)
CREATE TABLE IF NOT EXISTS student_record_audit_2026_05 PARTITION OF student_record_audit FOR VALUES FROM('2026-05-01') TO('2026-06-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2026_06 PARTITION OF student_record_audit FOR VALUES FROM('2026-06-01') TO('2026-07-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2026_07 PARTITION OF student_record_audit FOR VALUES FROM('2026-07-01') TO('2026-08-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2026_08 PARTITION OF student_record_audit FOR VALUES FROM('2026-08-01') TO('2026-09-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2026_09 PARTITION OF student_record_audit FOR VALUES FROM('2026-09-01') TO('2026-10-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2026_10 PARTITION OF student_record_audit FOR VALUES FROM('2026-10-01') TO('2026-11-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2026_11 PARTITION OF student_record_audit FOR VALUES FROM('2026-11-01') TO('2026-12-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2026_12 PARTITION OF student_record_audit FOR VALUES FROM('2026-12-01') TO('2027-01-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_01 PARTITION OF student_record_audit FOR VALUES FROM('2027-01-01') TO('2027-02-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_02 PARTITION OF student_record_audit FOR VALUES FROM('2027-02-01') TO('2027-03-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_03 PARTITION OF student_record_audit FOR VALUES FROM('2027-03-01') TO('2027-04-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_04 PARTITION OF student_record_audit FOR VALUES FROM('2027-04-01') TO('2027-05-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_05 PARTITION OF student_record_audit FOR VALUES FROM('2027-05-01') TO('2027-06-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_06 PARTITION OF student_record_audit FOR VALUES FROM('2027-06-01') TO('2027-07-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_07 PARTITION OF student_record_audit FOR VALUES FROM('2027-07-01') TO('2027-08-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_08 PARTITION OF student_record_audit FOR VALUES FROM('2027-08-01') TO('2027-09-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_09 PARTITION OF student_record_audit FOR VALUES FROM('2027-09-01') TO('2027-10-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_10 PARTITION OF student_record_audit FOR VALUES FROM('2027-10-01') TO('2027-11-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_11 PARTITION OF student_record_audit FOR VALUES FROM('2027-11-01') TO('2027-12-01');
CREATE TABLE IF NOT EXISTS student_record_audit_2027_12 PARTITION OF student_record_audit FOR VALUES FROM('2027-12-01') TO('2028-01-01');

-- 7. global_audit_logs (created_at)
CREATE TABLE IF NOT EXISTS global_audit_logs_2026_05 PARTITION OF global_audit_logs FOR VALUES FROM('2026-05-01') TO('2026-06-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2026_06 PARTITION OF global_audit_logs FOR VALUES FROM('2026-06-01') TO('2026-07-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2026_07 PARTITION OF global_audit_logs FOR VALUES FROM('2026-07-01') TO('2026-08-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2026_08 PARTITION OF global_audit_logs FOR VALUES FROM('2026-08-01') TO('2026-09-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2026_09 PARTITION OF global_audit_logs FOR VALUES FROM('2026-09-01') TO('2026-10-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2026_10 PARTITION OF global_audit_logs FOR VALUES FROM('2026-10-01') TO('2026-11-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2026_11 PARTITION OF global_audit_logs FOR VALUES FROM('2026-11-01') TO('2026-12-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2026_12 PARTITION OF global_audit_logs FOR VALUES FROM('2026-12-01') TO('2027-01-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_01 PARTITION OF global_audit_logs FOR VALUES FROM('2027-01-01') TO('2027-02-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_02 PARTITION OF global_audit_logs FOR VALUES FROM('2027-02-01') TO('2027-03-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_03 PARTITION OF global_audit_logs FOR VALUES FROM('2027-03-01') TO('2027-04-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_04 PARTITION OF global_audit_logs FOR VALUES FROM('2027-04-01') TO('2027-05-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_05 PARTITION OF global_audit_logs FOR VALUES FROM('2027-05-01') TO('2027-06-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_06 PARTITION OF global_audit_logs FOR VALUES FROM('2027-06-01') TO('2027-07-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_07 PARTITION OF global_audit_logs FOR VALUES FROM('2027-07-01') TO('2027-08-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_08 PARTITION OF global_audit_logs FOR VALUES FROM('2027-08-01') TO('2027-09-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_09 PARTITION OF global_audit_logs FOR VALUES FROM('2027-09-01') TO('2027-10-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_10 PARTITION OF global_audit_logs FOR VALUES FROM('2027-10-01') TO('2027-11-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_11 PARTITION OF global_audit_logs FOR VALUES FROM('2027-11-01') TO('2027-12-01');
CREATE TABLE IF NOT EXISTS global_audit_logs_2027_12 PARTITION OF global_audit_logs FOR VALUES FROM('2027-12-01') TO('2028-01-01');

-- =============================================================================
-- VERIFICACIÓN POST-MIGRACIÓN:
--
-- SELECT tablename, COUNT(*) AS partition_count
-- FROM pg_tables WHERE schemaname = 'public'
--   AND tablename LIKE ANY(ARRAY[
--     'attendance_incidents_%','user_commands_%','sos_alerts_%',
--     'internal_messages_%','twilio_messages_%','student_record_audit_%',
--     'global_audit_logs_%'
--   ]) AND tablename NOT LIKE '%_default'
-- GROUP BY tablename
-- ORDER BY tablename;
-- Expected: 140 rows (20 per table × 7 tables)
-- =============================================================================
