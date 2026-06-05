-- NEXO Full Migration | Idempotent | PostgreSQL 15+
-- Run: psql $DATABASE_URL -f nexo_full_migration.sql

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- BASE TABLES
CREATE TABLE IF NOT EXISTS permissions (permission_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), permission_code VARCHAR(120) UNIQUE NOT NULL, description TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS departments (department_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), department_name VARCHAR(120) UNIQUE NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS municipalities (municipality_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), department_id UUID NOT NULL REFERENCES departments(department_id), municipality_name VARCHAR(120) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_municipality_department ON municipalities(department_id);
CREATE TABLE IF NOT EXISTS schools (school_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), municipality_id UUID NOT NULL REFERENCES municipalities(municipality_id), dane_code VARCHAR(50) UNIQUE, school_name VARCHAR(255) NOT NULL, address TEXT, phone VARCHAR(30), email VARCHAR(255), active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_school_municipality ON schools(municipality_id);
CREATE TABLE IF NOT EXISTS roles (role_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), role_name VARCHAR(100) UNIQUE NOT NULL, description TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS role_permissions (role_permission_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), role_id UUID NOT NULL REFERENCES roles(role_id), permission_id UUID NOT NULL REFERENCES permissions(permission_id), created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_role_permission_role ON role_permissions(role_id);
CREATE TABLE IF NOT EXISTS users (user_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), role_id UUID NOT NULL REFERENCES roles(role_id), document_number VARCHAR(30) UNIQUE NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, email VARCHAR(255), phone VARCHAR(30), password_hash TEXT NOT NULL, password_salt TEXT NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE, last_login_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ, deleted_at TIMESTAMPTZ);
CREATE INDEX IF NOT EXISTS idx_users_school ON users(school_id); CREATE INDEX IF NOT EXISTS idx_users_role ON users(role_id);
CREATE TABLE IF NOT EXISTS user_sessions (session_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), user_id UUID NOT NULL REFERENCES users(user_id), refresh_token_hash TEXT NOT NULL, ip_address INET, user_agent TEXT, expires_at TIMESTAMPTZ NOT NULL, revoked BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), revoked_at TIMESTAMPTZ);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON user_sessions(user_id);
CREATE TABLE IF NOT EXISTS staff_records (staff_record_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), user_id UUID NOT NULL REFERENCES users(user_id), hired_at DATE, position_name VARCHAR(120), employee_code VARCHAR(120), active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_staff_school ON staff_records(school_id);
CREATE TABLE IF NOT EXISTS guardians (guardian_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), user_id UUID UNIQUE NOT NULL REFERENCES users(user_id), whatsapp_phone VARCHAR(30) NOT NULL, emergency_contact BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS students (student_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), document_number VARCHAR(30) UNIQUE NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, birth_date DATE, biometric_hash TEXT, active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ, deleted_at TIMESTAMPTZ);
CREATE INDEX IF NOT EXISTS idx_students_school ON students(school_id);
CREATE TABLE IF NOT EXISTS guardian_student_relationships (relationship_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), guardian_id UUID NOT NULL REFERENCES guardians(guardian_id), student_id UUID NOT NULL REFERENCES students(student_id), relationship_type VARCHAR(80), primary_guardian BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS academic_groups (group_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), group_name VARCHAR(120) NOT NULL, grade_level VARCHAR(50), academic_year INTEGER NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS student_group_assignments (assignment_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), student_id UUID NOT NULL REFERENCES students(student_id), group_id UUID NOT NULL REFERENCES academic_groups(group_id), active BOOLEAN NOT NULL DEFAULT TRUE, start_date DATE, end_date DATE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS classrooms (classroom_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), classroom_name VARCHAR(120) NOT NULL, building VARCHAR(120), created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS subjects (subject_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), subject_name VARCHAR(120) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS schedules (schedule_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), group_id UUID NOT NULL REFERENCES academic_groups(group_id), classroom_id UUID NOT NULL REFERENCES classrooms(classroom_id), teacher_user_id UUID NOT NULL REFERENCES users(user_id), subject_id UUID NOT NULL REFERENCES subjects(subject_id), day_of_week INTEGER NOT NULL, block_number INTEGER NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS edge_devices (device_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), classroom_id UUID REFERENCES classrooms(classroom_id), device_name VARCHAR(120) NOT NULL, public_key TEXT, active BOOLEAN NOT NULL DEFAULT TRUE, last_sync_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS biometric_events (event_id UUID NOT NULL, school_id UUID NOT NULL, student_id UUID, device_id UUID NOT NULL, classroom_id UUID, schedule_id UUID, event_type VARCHAR(120) NOT NULL, event_result VARCHAR(120) NOT NULL, confidence_score NUMERIC(5,2), sync_hash TEXT, event_signature TEXT, event_timestamp TIMESTAMPTZ NOT NULL, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), PRIMARY KEY(event_id, event_timestamp)) PARTITION BY RANGE(event_timestamp);
CREATE TABLE IF NOT EXISTS notifications (notification_id UUID DEFAULT uuid_generate_v4() PRIMARY KEY, school_id UUID NOT NULL, user_id UUID NOT NULL, title VARCHAR(200) NOT NULL, message TEXT NOT NULL, type VARCHAR(50) NOT NULL DEFAULT 'INFO', created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, created_at DESC);
CREATE TABLE IF NOT EXISTS attendance_incidents (incident_id UUID NOT NULL, school_id UUID NOT NULL, student_id UUID NOT NULL, related_event_id UUID, incident_type VARCHAR(120) NOT NULL, detected_at TIMESTAMPTZ NOT NULL, resolved BOOLEAN NOT NULL DEFAULT FALSE, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), PRIMARY KEY(incident_id, detected_at)) PARTITION BY RANGE(detected_at);
CREATE TABLE IF NOT EXISTS internal_messages (message_id UUID NOT NULL, school_id UUID NOT NULL, sender_user_id UUID NOT NULL, receiver_user_id UUID NOT NULL, subject VARCHAR(255), message_content TEXT NOT NULL, sent_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), read_at TIMESTAMPTZ, metadata_json JSONB, PRIMARY KEY(message_id, sent_at)) PARTITION BY RANGE(sent_at);
CREATE TABLE IF NOT EXISTS twilio_message_types (type_code VARCHAR(100) PRIMARY KEY, description TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS twilio_messages (twilio_message_id UUID NOT NULL DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, student_id UUID, guardian_id UUID, sender_user_id UUID, type_code VARCHAR(100) NOT NULL, direction VARCHAR(20) NOT NULL, phone_number VARCHAR(30) NOT NULL, message_content TEXT NOT NULL, provider_message_sid VARCHAR(255), delivery_status VARCHAR(100), sent_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), received_at TIMESTAMPTZ, metadata_json JSONB, PRIMARY KEY(twilio_message_id, sent_at)) PARTITION BY RANGE(sent_at);
CREATE TABLE IF NOT EXISTS user_commands (command_id UUID NOT NULL DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, executed_by_user_id UUID NOT NULL, command_type VARCHAR(120) NOT NULL, target_entity_type VARCHAR(120), target_entity_id UUID, command_payload JSONB, executed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), metadata_json JSONB, PRIMARY KEY(command_id, executed_at)) PARTITION BY RANGE(executed_at);
CREATE TABLE IF NOT EXISTS sos_alerts (alert_id UUID NOT NULL, school_id UUID NOT NULL, emitted_by_user_id UUID NOT NULL, classroom_id UUID, alert_type VARCHAR(120) NOT NULL, alert_description TEXT, resolved BOOLEAN NOT NULL DEFAULT FALSE, resolved_by_user_id UUID, emitted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), resolved_at TIMESTAMPTZ, metadata_json JSONB, PRIMARY KEY(alert_id, emitted_at)) PARTITION BY RANGE(emitted_at);
CREATE TABLE IF NOT EXISTS security_incidents (incident_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, related_student_id UUID, related_user_id UUID, incident_type VARCHAR(120) NOT NULL, severity_level VARCHAR(50), description TEXT, detected_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), resolved BOOLEAN NOT NULL DEFAULT FALSE, resolved_at TIMESTAMPTZ, metadata_json JSONB);
CREATE TABLE IF NOT EXISTS school_exit_authorizations (authorization_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, student_id UUID NOT NULL, authorized_by_user_id UUID NOT NULL, authorization_reason TEXT, exit_time TIMESTAMPTZ NOT NULL, expected_return_time TIMESTAMPTZ, actual_return_time TIMESTAMPTZ, status VARCHAR(100) NOT NULL, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS class_exit_authorizations (authorization_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, student_id UUID NOT NULL, authorized_by_user_id UUID NOT NULL, schedule_id UUID, authorization_reason TEXT, exit_time TIMESTAMPTZ NOT NULL, return_time TIMESTAMPTZ, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS pedagogical_trip_authorizations (authorization_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, student_id UUID NOT NULL, authorized_by_user_id UUID NOT NULL, destination TEXT NOT NULL, departure_time TIMESTAMPTZ NOT NULL, return_time TIMESTAMPTZ, purpose TEXT, metadata_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());
CREATE TABLE IF NOT EXISTS student_record_audit (audit_id UUID NOT NULL, school_id UUID NOT NULL, student_id UUID, performed_by_user_id UUID NOT NULL, action_type VARCHAR(120) NOT NULL, previous_data JSONB, new_data JSONB, performed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), metadata_json JSONB, PRIMARY KEY(audit_id, performed_at)) PARTITION BY RANGE(performed_at);
CREATE TABLE IF NOT EXISTS global_audit_logs (log_id UUID NOT NULL, school_id UUID, performed_by_user_id UUID, action_type VARCHAR(120) NOT NULL, entity_type VARCHAR(120), entity_id UUID, action_details JSONB, ip_address INET, user_agent TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), PRIMARY KEY(log_id, created_at)) PARTITION BY RANGE(created_at);
CREATE TABLE IF NOT EXISTS report_exports (report_export_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL, generated_by_user_id UUID NOT NULL, report_type VARCHAR(120) NOT NULL, file_format VARCHAR(50) NOT NULL, storage_path TEXT, generated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), metadata_json JSONB);
CREATE TABLE IF NOT EXISTS student_behavior_metrics (metric_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), school_id UUID NOT NULL REFERENCES schools(school_id), student_id UUID NOT NULL REFERENCES students(student_id), calculated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), late_count INTEGER NOT NULL DEFAULT 0, absence_count INTEGER NOT NULL DEFAULT 0, total_events INTEGER NOT NULL DEFAULT 0, risk_score NUMERIC(5,2) NOT NULL DEFAULT 0.00, risk_level VARCHAR(20) CHECK(risk_level IN('LOW','MEDIUM','HIGH','CRITICAL')), calculation_window_days INTEGER NOT NULL DEFAULT 30, metadata_json JSONB, CONSTRAINT uq_behavior_student_window UNIQUE(student_id,calculation_window_days));

-- MIGRATION TABLES
CREATE TABLE IF NOT EXISTS rate_limits (rl_key TEXT PRIMARY KEY, window_start TIMESTAMPTZ NOT NULL DEFAULT NOW(), hits INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_rate_limits_window_start ON rate_limits(window_start);
CREATE TABLE IF NOT EXISTS jwt_blocklist (jti TEXT PRIMARY KEY, revoked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), expires_at TIMESTAMPTZ NOT NULL);
CREATE INDEX IF NOT EXISTS idx_jwt_blocklist_expires_at ON jwt_blocklist(expires_at);

-- ADDITIONAL COLUMNS
ALTER TABLE guardians ADD COLUMN IF NOT EXISTS whatsapp_phone_normalized TEXT;
UPDATE guardians SET whatsapp_phone_normalized = regexp_replace(COALESCE(whatsapp_phone,''),'[^0-9+]','','g') WHERE whatsapp_phone_normalized IS NULL OR whatsapp_phone_normalized <> regexp_replace(COALESCE(whatsapp_phone,''),'[^0-9+]','','g');
ALTER TABLE edge_devices ADD COLUMN IF NOT EXISTS token_hash VARCHAR(255), ADD COLUMN IF NOT EXISTS last_ping TIMESTAMPTZ;
ALTER TABLE global_audit_logs ADD COLUMN IF NOT EXISTS chain_hash TEXT, ADD COLUMN IF NOT EXISTS prev_audit_id UUID, ADD COLUMN IF NOT EXISTS description TEXT;

-- INDEXES
CREATE INDEX IF NOT EXISTS idx_guardians_whatsapp_normalized ON guardians(whatsapp_phone_normalized);
CREATE INDEX IF NOT EXISTS idx_edge_devices_school_active ON edge_devices(school_id, active);
CREATE INDEX IF NOT EXISTS idx_users_email_lower ON users(LOWER(email)) WHERE email IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_users_active_school_role ON users(active, school_id, role_id);
CREATE INDEX IF NOT EXISTS idx_sessions_refresh_hash ON user_sessions(refresh_token_hash);
CREATE INDEX IF NOT EXISTS idx_sessions_expires_revoked ON user_sessions(expires_at, revoked);
CREATE INDEX IF NOT EXISTS idx_guardian_rel_student_primary ON guardian_student_relationships(student_id, primary_guardian);
CREATE INDEX IF NOT EXISTS idx_guardian_rel_guardian ON guardian_student_relationships(guardian_id);
CREATE INDEX IF NOT EXISTS idx_groups_school_year_level ON academic_groups(school_id, academic_year, grade_level, group_name);
CREATE INDEX IF NOT EXISTS idx_schedule_group_day_block ON schedules(group_id, day_of_week, block_number);
CREATE INDEX IF NOT EXISTS idx_schedule_teacher_day_block ON schedules(teacher_user_id, day_of_week, block_number);
CREATE INDEX IF NOT EXISTS idx_biometric_events_school_type_ts ON biometric_events(school_id, event_type, event_timestamp DESC);
CREATE INDEX IF NOT EXISTS idx_attendance_incidents_school_type_detected ON attendance_incidents(school_id, incident_type, detected_at DESC);
CREATE INDEX IF NOT EXISTS idx_sos_alerts_school_resolved_emitted ON sos_alerts(school_id, resolved, emitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_students_school_last_first ON students(school_id, last_name, first_name);
CREATE INDEX IF NOT EXISTS idx_student_group_assignments_student_active ON student_group_assignments(student_id, active);
CREATE INDEX IF NOT EXISTS idx_student_group_assignments_group_active ON student_group_assignments(group_id, active);
CREATE INDEX IF NOT EXISTS idx_report_exports_school_generated ON report_exports(school_id, generated_at DESC);
CREATE INDEX IF NOT EXISTS idx_security_incidents_school_detected ON security_incidents(school_id, detected_at DESC, resolved);
CREATE INDEX IF NOT EXISTS idx_biometric_events_school_student_time ON biometric_events(school_id, student_id, event_timestamp DESC);
CREATE INDEX IF NOT EXISTS idx_students_document_number ON students(document_number);
CREATE INDEX IF NOT EXISTS idx_behavior_risk_score ON student_behavior_metrics(school_id, risk_level, calculated_at DESC);
CREATE INDEX IF NOT EXISTS idx_behavior_student ON student_behavior_metrics(student_id, calculated_at DESC);
CREATE INDEX IF NOT EXISTS idx_twilio_messages_school_sent ON twilio_messages(school_id, sent_at DESC);
CREATE INDEX IF NOT EXISTS idx_user_commands_school_exec ON user_commands(school_id, executed_at DESC);
CREATE INDEX IF NOT EXISTS idx_global_audit_school_created ON global_audit_logs(school_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_student_audit_school_performed ON student_record_audit(school_id, performed_at DESC);

-- UNIQUE CONSTRAINTS
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_role_permissions_role_permission') THEN ALTER TABLE role_permissions ADD CONSTRAINT uq_role_permissions_role_permission UNIQUE(role_id,permission_id); END IF; END $$;
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_guardian_student_relationship') THEN ALTER TABLE guardian_student_relationships ADD CONSTRAINT uq_guardian_student_relationship UNIQUE(guardian_id,student_id); END IF; END $$;
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_academic_group_school_year_name') THEN ALTER TABLE academic_groups ADD CONSTRAINT uq_academic_group_school_year_name UNIQUE(school_id,academic_year,group_name); END IF; END $$;

-- PARTITIONS
CREATE TABLE IF NOT EXISTS biometric_events_2026_05 PARTITION OF biometric_events FOR VALUES FROM('2026-05-01') TO('2026-06-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_06 PARTITION OF biometric_events FOR VALUES FROM('2026-06-01') TO('2026-07-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_07 PARTITION OF biometric_events FOR VALUES FROM('2026-07-01') TO('2026-08-01');
CREATE TABLE IF NOT EXISTS biometric_events_2026_08 PARTITION OF biometric_events FOR VALUES FROM('2026-08-01') TO('2026-09-01');
CREATE TABLE IF NOT EXISTS biometric_events_default PARTITION OF biometric_events DEFAULT;
CREATE TABLE IF NOT EXISTS attendance_incidents_default PARTITION OF attendance_incidents DEFAULT;
CREATE TABLE IF NOT EXISTS internal_messages_default PARTITION OF internal_messages DEFAULT;
CREATE TABLE IF NOT EXISTS twilio_messages_default PARTITION OF twilio_messages DEFAULT;
CREATE TABLE IF NOT EXISTS user_commands_default PARTITION OF user_commands DEFAULT;
CREATE TABLE IF NOT EXISTS sos_alerts_default PARTITION OF sos_alerts DEFAULT;
CREATE TABLE IF NOT EXISTS student_record_audit_default PARTITION OF student_record_audit DEFAULT;
CREATE TABLE IF NOT EXISTS global_audit_logs_default PARTITION OF global_audit_logs DEFAULT;

-- PHONE NORMALIZATION TRIGGER
CREATE OR REPLACE FUNCTION fn_guardians_normalize_phone() RETURNS TRIGGER AS $$ BEGIN NEW.whatsapp_phone_normalized := regexp_replace(COALESCE(NEW.whatsapp_phone,''),'[^0-9+]','','g'); RETURN NEW; END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_guardians_normalize_phone ON guardians;
CREATE TRIGGER trg_guardians_normalize_phone BEFORE INSERT OR UPDATE OF whatsapp_phone ON guardians FOR EACH ROW EXECUTE FUNCTION fn_guardians_normalize_phone();

-- AUDIT CHAIN
CREATE OR REPLACE FUNCTION fn_calculate_audit_hash(p_prev_hash TEXT, p_school_id UUID, p_actor_id UUID, p_event_type TEXT, p_description TEXT, p_ip_address TEXT, p_created_at TIMESTAMPTZ) RETURNS TEXT AS $$ DECLARE v_secret TEXT; v_payload TEXT; BEGIN v_secret := COALESCE(current_setting('app.nexo_hmac_secret',true),'default-secret-change-me'); v_payload := COALESCE(p_prev_hash,'GENESIS')||'|'||COALESCE(p_school_id::TEXT,'NULL')||'|'||COALESCE(p_actor_id::TEXT,'NULL')||'|'||COALESCE(p_event_type,'')||'|'||COALESCE(p_description,'')||'|'||COALESCE(p_ip_address,'')||'|'||COALESCE(p_created_at::TEXT,''); RETURN encode(hmac(v_payload,v_secret,'sha256'),'hex'); END; $$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION fn_audit_chain_trigger() RETURNS TRIGGER AS $$ DECLARE v_prev_hash TEXT; v_prev_id UUID; BEGIN SELECT log_id, chain_hash INTO v_prev_id, v_prev_hash FROM global_audit_logs WHERE(school_id IS NOT DISTINCT FROM NEW.school_id) ORDER BY created_at DESC, log_id DESC LIMIT 1 FOR UPDATE; NEW.prev_audit_id := v_prev_id; NEW.chain_hash := fn_calculate_audit_hash(v_prev_hash, NEW.school_id, NEW.performed_by_user_id, NEW.action_type, NEW.action_details::TEXT, NEW.ip_address::TEXT, NEW.created_at); RETURN NEW; END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_audit_chain ON global_audit_logs; CREATE TRIGGER trg_audit_chain BEFORE INSERT ON global_audit_logs FOR EACH ROW EXECUTE FUNCTION fn_audit_chain_trigger();

CREATE OR REPLACE FUNCTION fn_validate_audit_chain(p_school_id UUID DEFAULT NULL) RETURNS JSONB AS $$ DECLARE v_expected_hash TEXT; v_prev_hash TEXT; v_record RECORD; v_broken_at UUID := NULL; v_count INTEGER := 0; v_valid_count INTEGER := 0; BEGIN v_prev_hash := NULL; FOR v_record IN SELECT log_id, school_id, performed_by_user_id, action_type, action_details, ip_address, created_at, chain_hash, prev_audit_id FROM global_audit_logs WHERE(p_school_id IS NULL OR school_id = p_school_id) ORDER BY created_at ASC, log_id ASC LOOP v_count := v_count + 1; v_expected_hash := fn_calculate_audit_hash(v_prev_hash, v_record.school_id, v_record.performed_by_user_id, v_record.action_type, v_record.action_details::TEXT, v_record.ip_address::TEXT, v_record.created_at); IF v_record.chain_hash = v_expected_hash THEN v_valid_count := v_valid_count + 1; ELSE v_broken_at := v_record.log_id; EXIT; END IF; v_prev_hash := v_record.chain_hash; END LOOP; IF v_broken_at IS NOT NULL THEN RETURN jsonb_build_object('status','compromised','broken_at_audit_id',v_broken_at,'total_checked',v_count,'valid_up_to',v_valid_count - 1); ELSE RETURN jsonb_build_object('status','ok','total_records',v_count,'school_id',p_school_id); END IF; END; $$ LANGUAGE plpgsql;
UPDATE global_audit_logs SET chain_hash = 'LEGACY_'||md5(log_id::TEXT) WHERE chain_hash IS NULL;

-- BEHAVIOR METRICS (UUID adapted)
CREATE OR REPLACE FUNCTION fn_calculate_student_risk(p_student_id UUID, p_school_id UUID, p_window_days INTEGER DEFAULT 30) RETURNS JSONB AS $$ DECLARE v_late_count INTEGER; v_absence_count INTEGER; v_total_events INTEGER; v_risk_score NUMERIC(5,2); v_risk_level VARCHAR(20); v_threshold CONSTANT NUMERIC(5,2) := 70.00; v_metric_id UUID; BEGIN SELECT COUNT(*) FILTER(WHERE event_type LIKE 'INGRESO_TARDE%'), COUNT(*) FILTER(WHERE event_type LIKE 'INASISTENCIA%'), COUNT(*) INTO v_late_count, v_absence_count, v_total_events FROM biometric_events WHERE student_id = p_student_id AND school_id = p_school_id AND event_timestamp >= NOW() - (p_window_days || ' days')::INTERVAL; v_risk_score := LEAST(100.00, (v_late_count * 5.0) + (v_absence_count * 15.0) + GREATEST(0, (v_total_events - 20) * 0.5)); v_risk_level := CASE WHEN v_risk_score >= 80 THEN 'CRITICAL' WHEN v_risk_score >= 60 THEN 'HIGH' WHEN v_risk_score >= 30 THEN 'MEDIUM' ELSE 'LOW' END; INSERT INTO student_behavior_metrics(school_id, student_id, calculated_at, late_count, absence_count, total_events, risk_score, risk_level, calculation_window_days, metadata_json) VALUES(p_school_id, p_student_id, NOW(), v_late_count, v_absence_count, v_total_events, v_risk_score, v_risk_level, p_window_days, jsonb_build_object('threshold', v_threshold, 'window_days', p_window_days)) ON CONFLICT(student_id, calculation_window_days) DO UPDATE SET calculated_at = EXCLUDED.calculated_at, late_count = EXCLUDED.late_count, absence_count = EXCLUDED.absence_count, total_events = EXCLUDED.total_events, risk_score = EXCLUDED.risk_score, risk_level = EXCLUDED.risk_level, metadata_json = EXCLUDED.metadata_json RETURNING metric_id INTO v_metric_id; IF v_risk_score >= v_threshold THEN INSERT INTO attendance_incidents(incident_id, school_id, student_id, incident_type, detected_at, metadata_json) SELECT uuid_generate_v4(), p_school_id, p_student_id, 'RISK_ALERT_' || v_risk_level, NOW(), jsonb_build_object('risk_score', v_risk_score, 'metric_id', v_metric_id, 'late_count', v_late_count, 'absence_count', v_absence_count, 'trigger_threshold', v_threshold) WHERE NOT EXISTS(SELECT 1 FROM attendance_incidents WHERE student_id = p_student_id AND school_id = p_school_id AND incident_type LIKE 'RISK_ALERT%' AND detected_at >= NOW() - INTERVAL '7 days'); END IF; RETURN jsonb_build_object('metric_id', v_metric_id, 'risk_score', v_risk_score, 'risk_level', v_risk_level, 'late_count', v_late_count, 'absence_count', v_absence_count, 'threshold_exceeded', v_risk_score >= v_threshold); END; $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION fn_recalculate_school_metrics(p_school_id UUID) RETURNS INTEGER AS $$ DECLARE v_count INTEGER; BEGIN INSERT INTO student_behavior_metrics(school_id, student_id, calculated_at, late_count, absence_count, total_events, risk_score, risk_level, calculation_window_days, metadata_json) SELECT p_school_id, s.student_id, NOW(), COALESCE(be.late_count, 0), COALESCE(be.absence_count, 0), COALESCE(be.total_events, 0), LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)), CASE WHEN LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)) >= 80 THEN 'CRITICAL' WHEN LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)) >= 60 THEN 'HIGH' WHEN LEAST(100.00, COALESCE(be.late_count, 0) * 5.0 + COALESCE(be.absence_count, 0) * 15.0 + GREATEST(0, (COALESCE(be.total_events, 0) - 20) * 0.5)) >= 30 THEN 'MEDIUM' ELSE 'LOW' END, 30, jsonb_build_object('recalculated_at', NOW()) FROM students s LEFT JOIN(SELECT student_id, COUNT(*) FILTER(WHERE event_type LIKE 'INGRESO_TARDE%') AS late_count, COUNT(*) FILTER(WHERE event_type LIKE 'INASISTENCIA%') AS absence_count, COUNT(*) AS total_events FROM biometric_events WHERE event_timestamp >= NOW() - INTERVAL '30 days' GROUP BY student_id) be ON be.student_id = s.student_id WHERE s.school_id = p_school_id AND s.active = TRUE ON CONFLICT(student_id, calculation_window_days) DO UPDATE SET calculated_at = EXCLUDED.calculated_at, late_count = EXCLUDED.late_count, absence_count = EXCLUDED.absence_count, total_events = EXCLUDED.total_events, risk_score = EXCLUDED.risk_score, risk_level = EXCLUDED.risk_level, metadata_json = EXCLUDED.metadata_json; GET DIAGNOSTICS v_count = ROW_COUNT; RETURN v_count; END; $$ LANGUAGE plpgsql;

-- RLS HELPERS
CREATE OR REPLACE FUNCTION get_current_school_id() RETURNS UUID AS $$ DECLARE v_school_id TEXT; BEGIN v_school_id := current_setting('app.current_school_id', true); IF v_school_id IS NULL OR v_school_id = '' THEN RETURN NULL; END IF; RETURN v_school_id::UUID; EXCEPTION WHEN OTHERS THEN RETURN NULL; END; $$ LANGUAGE plpgsql SECURITY DEFINER;
CREATE OR REPLACE FUNCTION is_super_rector() RETURNS BOOLEAN AS $$ DECLARE v_role TEXT; BEGIN v_role := current_setting('app.current_role', true); RETURN(v_role = 'SUPER_RECTOR'); EXCEPTION WHEN OTHERS THEN RETURN FALSE; END; $$ LANGUAGE plpgsql SECURITY DEFINER;

-- RLS POLICIES
ALTER TABLE students ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS students_select ON students; DROP POLICY IF EXISTS students_insert ON students; DROP POLICY IF EXISTS students_update ON students; DROP POLICY IF EXISTS students_delete ON students;
CREATE POLICY IF NOT EXISTS students_select ON students FOR SELECT USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS students_insert ON students FOR INSERT WITH CHECK(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS students_update ON students FOR UPDATE USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS students_delete ON students FOR DELETE USING(school_id = get_current_school_id() OR is_super_rector());

ALTER TABLE biometric_events ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS be_select ON biometric_events; DROP POLICY IF EXISTS be_insert ON biometric_events; DROP POLICY IF EXISTS be_update ON biometric_events; DROP POLICY IF EXISTS be_delete ON biometric_events;
CREATE POLICY IF NOT EXISTS be_select ON biometric_events FOR SELECT USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS be_insert ON biometric_events FOR INSERT WITH CHECK(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS be_update ON biometric_events FOR UPDATE USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS be_delete ON biometric_events FOR DELETE USING(school_id = get_current_school_id() OR is_super_rector());

ALTER TABLE attendance_incidents ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ai_select ON attendance_incidents; DROP POLICY IF EXISTS ai_insert ON attendance_incidents; DROP POLICY IF EXISTS ai_update ON attendance_incidents; DROP POLICY IF EXISTS ai_delete ON attendance_incidents;
CREATE POLICY IF NOT EXISTS ai_select ON attendance_incidents FOR SELECT USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS ai_insert ON attendance_incidents FOR INSERT WITH CHECK(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS ai_update ON attendance_incidents FOR UPDATE USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS ai_delete ON attendance_incidents FOR DELETE USING(school_id = get_current_school_id() OR is_super_rector());

ALTER TABLE sos_alerts ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sos_select ON sos_alerts; DROP POLICY IF EXISTS sos_insert ON sos_alerts; DROP POLICY IF EXISTS sos_update ON sos_alerts; DROP POLICY IF EXISTS sos_delete ON sos_alerts;
CREATE POLICY IF NOT EXISTS sos_select ON sos_alerts FOR SELECT USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS sos_insert ON sos_alerts FOR INSERT WITH CHECK(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS sos_update ON sos_alerts FOR UPDATE USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS sos_delete ON sos_alerts FOR DELETE USING(school_id = get_current_school_id() OR is_super_rector());

ALTER TABLE global_audit_logs ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS gal_select ON global_audit_logs; DROP POLICY IF EXISTS gal_insert ON global_audit_logs;
CREATE POLICY IF NOT EXISTS gal_select ON global_audit_logs FOR SELECT USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS gal_insert ON global_audit_logs FOR INSERT WITH CHECK(school_id = get_current_school_id() OR is_super_rector());

ALTER TABLE twilio_messages ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tm_select ON twilio_messages; DROP POLICY IF EXISTS tm_insert ON twilio_messages; DROP POLICY IF EXISTS tm_update ON twilio_messages; DROP POLICY IF EXISTS tm_delete ON twilio_messages;
CREATE POLICY IF NOT EXISTS tm_select ON twilio_messages FOR SELECT USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS tm_insert ON twilio_messages FOR INSERT WITH CHECK(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS tm_update ON twilio_messages FOR UPDATE USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS tm_delete ON twilio_messages FOR DELETE USING(school_id = get_current_school_id() OR is_super_rector());

ALTER TABLE edge_devices ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ed_select ON edge_devices; DROP POLICY IF EXISTS ed_insert ON edge_devices; DROP POLICY IF EXISTS ed_update ON edge_devices; DROP POLICY IF EXISTS ed_delete ON edge_devices;
CREATE POLICY IF NOT EXISTS ed_select ON edge_devices FOR SELECT USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS ed_insert ON edge_devices FOR INSERT WITH CHECK(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS ed_update ON edge_devices FOR UPDATE USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS ed_delete ON edge_devices FOR DELETE USING(school_id = get_current_school_id() OR is_super_rector());

ALTER TABLE student_behavior_metrics ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sbm_select ON student_behavior_metrics; DROP POLICY IF EXISTS sbm_insert ON student_behavior_metrics; DROP POLICY IF EXISTS sbm_update ON student_behavior_metrics; DROP POLICY IF EXISTS sbm_delete ON student_behavior_metrics;
CREATE POLICY IF NOT EXISTS sbm_select ON student_behavior_metrics FOR SELECT USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS sbm_insert ON student_behavior_metrics FOR INSERT WITH CHECK(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS sbm_update ON student_behavior_metrics FOR UPDATE USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS sbm_delete ON student_behavior_metrics FOR DELETE USING(school_id = get_current_school_id() OR is_super_rector());

ALTER TABLE user_commands ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS uc_select ON user_commands; DROP POLICY IF EXISTS uc_insert ON user_commands; DROP POLICY IF EXISTS uc_update ON user_commands; DROP POLICY IF EXISTS uc_delete ON user_commands;
CREATE POLICY IF NOT EXISTS uc_select ON user_commands FOR SELECT USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS uc_insert ON user_commands FOR INSERT WITH CHECK(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS uc_update ON user_commands FOR UPDATE USING(school_id = get_current_school_id() OR is_super_rector());
CREATE POLICY IF NOT EXISTS uc_delete ON user_commands FOR DELETE USING(school_id = get_current_school_id() OR is_super_rector());

ALTER TABLE guardian_student_relationships ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS gsr_select ON guardian_student_relationships; DROP POLICY IF EXISTS gsr_insert ON guardian_student_relationships; DROP POLICY IF EXISTS gsr_delete ON guardian_student_relationships;
CREATE POLICY IF NOT EXISTS gsr_select ON guardian_student_relationships FOR SELECT USING(EXISTS(SELECT 1 FROM students s WHERE s.student_id = guardian_student_relationships.student_id AND s.school_id = get_current_school_id()) OR is_super_rector());
CREATE POLICY IF NOT EXISTS gsr_insert ON guardian_student_relationships FOR INSERT WITH CHECK(EXISTS(SELECT 1 FROM students s WHERE s.student_id = guardian_student_relationships.student_id AND s.school_id = get_current_school_id()) OR is_super_rector());
CREATE POLICY IF NOT EXISTS gsr_delete ON guardian_student_relationships FOR DELETE USING(EXISTS(SELECT 1 FROM students s WHERE s.student_id = guardian_student_relationships.student_id AND s.school_id = get_current_school_id()) OR is_super_rector());

ALTER TABLE student_group_assignments ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sga_select ON student_group_assignments; DROP POLICY IF EXISTS sga_insert ON student_group_assignments; DROP POLICY IF EXISTS sga_update ON student_group_assignments; DROP POLICY IF EXISTS sga_delete ON student_group_assignments;
CREATE POLICY IF NOT EXISTS sga_select ON student_group_assignments FOR SELECT USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()) OR is_super_rector());
CREATE POLICY IF NOT EXISTS sga_insert ON student_group_assignments FOR INSERT WITH CHECK(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()) OR is_super_rector());
CREATE POLICY IF NOT EXISTS sga_update ON student_group_assignments FOR UPDATE USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()) OR is_super_rector());
CREATE POLICY IF NOT EXISTS sga_delete ON student_group_assignments FOR DELETE USING(EXISTS(SELECT 1 FROM academic_groups ag WHERE ag.group_id = student_group_assignments.group_id AND ag.school_id = get_current_school_id()) OR is_super_rector());

ALTER TABLE jwt_blocklist ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS jbl_select ON jwt_blocklist; DROP POLICY IF EXISTS jbl_insert ON jwt_blocklist;
CREATE POLICY IF NOT EXISTS jbl_select ON jwt_blocklist FOR SELECT USING(is_super_rector());
CREATE POLICY IF NOT EXISTS jbl_insert ON jwt_blocklist FOR INSERT WITH CHECK(is_super_rector());

-- SEED DATA
INSERT INTO departments(department_id, department_name) VALUES(uuid_generate_v4(), 'Bogotá D.C.') ON CONFLICT DO NOTHING;
INSERT INTO municipalities(municipality_id, department_id, municipality_name) SELECT uuid_generate_v4(), d.department_id, 'Bogotá D.C.' FROM departments d WHERE d.department_name = 'Bogotá D.C.' ON CONFLICT DO NOTHING;
INSERT INTO schools(school_id, municipality_id, dane_code, school_name, address, phone, email, active) SELECT uuid_generate_v4(), m.municipality_id, '000000000', 'Institución Educativa NEXO', 'Calle 1 # 1-1', '6010000000', 'contacto@nexo.edu', TRUE FROM municipalities m JOIN departments d ON d.department_id = m.department_id WHERE d.department_name = 'Bogotá D.C.' ON CONFLICT DO NOTHING;

INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'SUPER_RECTOR', 'Super administrador') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'RECTOR', 'Director de institución') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'TEACHER', 'Docente') ON CONFLICT(role_name) DO NOTHING;
INSERT INTO roles(role_id, role_name, description) VALUES(uuid_generate_v4(), 'GUARDIAN', 'Acudiente') ON CONFLICT(role_name) DO NOTHING;

-- ADMIN USER (password: admin123 | generate hash with: php -r "echo password_hash('admin123', PASSWORD_BCRYPT);")
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, password_hash, password_salt, active)
SELECT uuid_generate_v4(), s.school_id, r.role_id, '111111111', 'Admin', 'NEXO', 'admin@nexo.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'salt', TRUE
FROM schools s, roles r WHERE r.role_name = 'SUPER_RECTOR' ON CONFLICT(email) DO NOTHING;
