-- ============================================================
-- FIX (BUG-9): students.document_number UNIQUE breaks multi-tenancy
-- ============================================================
-- Problem: The students table has a global UNIQUE constraint on
-- document_number without school_id. When School A has student with
-- document 1234567 and School B tries to register the same document,
-- the ON CONFLICT tries to UPDATE the existing row from School A.
-- The RLS UPDATE policy blocks this because the row belongs to School A,
-- causing HTTP 500 for School B.
--
-- Impact: Two schools in production cannot share any document number.
-- Common in Colombia: transferred students, siblings with migrant parents.
--
-- Solution: Change UNIQUE to composite (school_id, document_number)
-- ============================================================

-- Drop the old global UNIQUE constraint
ALTER TABLE students DROP CONSTRAINT IF EXISTS students_document_number_key;

-- Add the new composite UNIQUE constraint
ALTER TABLE students ADD CONSTRAINT uq_students_school_document
    UNIQUE (school_id, document_number);
