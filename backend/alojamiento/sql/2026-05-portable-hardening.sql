-- Portable hardening (PostgreSQL 16+)
-- Safe, incremental, non-destructive.

CREATE INDEX IF NOT EXISTS idx_users_email_lower
ON users (LOWER(email))
WHERE email IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_users_active_school_role
ON users (active, school_id, role_id);

CREATE INDEX IF NOT EXISTS idx_sessions_refresh_hash
ON user_sessions (refresh_token_hash);

CREATE INDEX IF NOT EXISTS idx_sessions_expires_revoked
ON user_sessions (expires_at, revoked);

CREATE INDEX IF NOT EXISTS idx_guardian_rel_student_primary
ON guardian_student_relationships (student_id, primary_guardian);

CREATE INDEX IF NOT EXISTS idx_guardian_rel_guardian
ON guardian_student_relationships (guardian_id);

CREATE INDEX IF NOT EXISTS idx_groups_school_year_level
ON academic_groups (school_id, academic_year, grade_level, group_name);

CREATE INDEX IF NOT EXISTS idx_schedule_group_day_block
ON schedules (group_id, day_of_week, block_number);

CREATE INDEX IF NOT EXISTS idx_schedule_teacher_day_block
ON schedules (teacher_user_id, day_of_week, block_number);

CREATE INDEX IF NOT EXISTS idx_edge_devices_school_active
ON edge_devices (school_id, active);

CREATE INDEX IF NOT EXISTS idx_twilio_messages_school_sent
ON twilio_messages (school_id, sent_at DESC);

CREATE INDEX IF NOT EXISTS idx_user_commands_school_exec
ON user_commands (school_id, executed_at DESC);

CREATE INDEX IF NOT EXISTS idx_global_audit_school_created
ON global_audit_logs (school_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_student_audit_school_performed
ON student_record_audit (school_id, performed_at DESC);

CREATE INDEX IF NOT EXISTS idx_security_incidents_school_detected
ON security_incidents (school_id, detected_at DESC, resolved);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'uq_role_permissions_role_permission'
    ) THEN
        ALTER TABLE role_permissions
        ADD CONSTRAINT uq_role_permissions_role_permission UNIQUE (role_id, permission_id);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'uq_guardian_student_relationship'
    ) THEN
        ALTER TABLE guardian_student_relationships
        ADD CONSTRAINT uq_guardian_student_relationship UNIQUE (guardian_id, student_id);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'uq_academic_group_school_year_name'
    ) THEN
        ALTER TABLE academic_groups
        ADD CONSTRAINT uq_academic_group_school_year_name UNIQUE (school_id, academic_year, group_name);
    END IF;
END $$;
