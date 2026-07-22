-- ============================================================
-- Índices adicionales para PostgreSQL
-- ============================================================

-- 1. Índice parcial para audit_trail: solo registros no sincronizados
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_trail_synced ON audit_trail(synced) WHERE synced = 0;

-- 2. Índice compuesto para biometric_events: búsqueda por colegio, estudiante y timestamp descendente
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_biometric_events_school_student_time ON biometric_events (school_id, student_id, event_timestamp DESC);

-- 3. Índice para students.document_number (búsquedas por documento en EDGE ingestion)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_students_document_number ON students (document_number);
