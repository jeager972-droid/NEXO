-- ============================================================
-- FIX (BUG-1): student_group_assignments missing UNIQUE constraint
-- ============================================================
-- Problem: The INSERT in routes/students.php uses ON CONFLICT (student_id, group_id)
-- but there's no UNIQUE constraint on (student_id, group_id). PostgreSQL throws
-- error 42P10: no unique or exclusion constraint matching ON CONFLICT.
--
-- Impact: Every student registration fails with HTTP 500 because the transaction
-- rolls back when the INSERT into student_group_assignments fails.
--
-- Solution: Add UNIQUE constraint on (student_id, group_id)
-- ============================================================

-- Add the missing UNIQUE constraint
ALTER TABLE student_group_assignments 
ADD CONSTRAINT uq_sga_student_group 
UNIQUE (student_id, group_id);
