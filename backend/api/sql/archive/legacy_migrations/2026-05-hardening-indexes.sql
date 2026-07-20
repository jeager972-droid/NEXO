-- NEXO hardening/performance indexes (safe + incremental)
-- Ejecutar en ventana controlada de mantenimiento.

CREATE INDEX IF NOT EXISTS idx_biometric_events_school_type_ts
ON biometric_events (school_id, event_type, event_timestamp DESC);

CREATE INDEX IF NOT EXISTS idx_attendance_incidents_school_type_detected
ON attendance_incidents (school_id, incident_type, detected_at DESC);

CREATE INDEX IF NOT EXISTS idx_sos_alerts_school_resolved_emitted
ON sos_alerts (school_id, resolved, emitted_at DESC);

CREATE INDEX IF NOT EXISTS idx_students_school_last_first
ON students (school_id, last_name, first_name);

CREATE INDEX IF NOT EXISTS idx_student_group_assignments_student_active
ON student_group_assignments (student_id, active);

CREATE INDEX IF NOT EXISTS idx_student_group_assignments_group_active
ON student_group_assignments (group_id, active);

CREATE INDEX IF NOT EXISTS idx_report_exports_school_generated
ON report_exports (school_id, generated_at DESC);
