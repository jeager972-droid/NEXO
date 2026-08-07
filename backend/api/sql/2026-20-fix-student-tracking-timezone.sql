-- =============================================================================
-- Migration: Fix student_tracking timezone defaults
-- Date: 2026-20
-- Description: Replace CURRENT_TIMESTAMP with NOW() AT TIME ZONE 'America/Bogota'
--              for student_tracking and student_tracking_notes tables.
--              Ensures timestamps are stored in Bogotá local time consistently.
-- =============================================================================

ALTER TABLE student_tracking
    ALTER COLUMN created_at SET DEFAULT (NOW() AT TIME ZONE 'America/Bogota'),
    ALTER COLUMN updated_at SET DEFAULT (NOW() AT TIME ZONE 'America/Bogota');

ALTER TABLE student_tracking_notes
    ALTER COLUMN created_at SET DEFAULT (NOW() AT TIME ZONE 'America/Bogota');
