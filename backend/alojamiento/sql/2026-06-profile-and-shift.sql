-- Migration: profile photos & work shift for user filtering in operations
-- Safe, incremental, non-destructive.

-- 1. Add profile photo URL to users
ALTER TABLE users
ADD COLUMN IF NOT EXISTS profile_photo_url TEXT;

-- 2. Add work shift (jornada) to users for same-shift filtering
ALTER TABLE users
ADD COLUMN IF NOT EXISTS work_shift VARCHAR(50);

-- Common shifts in Colombian schools
COMMENT ON COLUMN users.work_shift IS 'mañana, tarde, completa, etc. Used for filtering recipients in operations.';

-- 3. Add index for efficient role+shift lookups
CREATE INDEX IF NOT EXISTS idx_users_school_role_shift
ON users (school_id, role_id, work_shift)
WHERE active = TRUE;

-- 4. Add index for photo lookups
CREATE INDEX IF NOT EXISTS idx_users_photo
ON users (user_id, profile_photo_url)
WHERE profile_photo_url IS NOT NULL;
