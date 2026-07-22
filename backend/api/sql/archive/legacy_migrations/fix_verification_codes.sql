-- FIX: Create missing verification_codes table and user columns
-- Run this in PostgreSQL immediately
-- Safe to run multiple times (idempotent)

-- 1. Add missing columns to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo_url TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS work_shift VARCHAR(50);
ALTER TABLE users ADD COLUMN IF NOT EXISTS backup_email VARCHAR(255);
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_verified BOOLEAN NOT NULL DEFAULT FALSE;

-- 1b. metadata_json in notifications
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS metadata_json JSONB;

-- 2. Create verification_codes table
CREATE TABLE IF NOT EXISTS verification_codes (
    code_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    purpose VARCHAR(50) NOT NULL,
    target_value TEXT NOT NULL,
    code VARCHAR(10) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    verified_at TIMESTAMPTZ
);

-- 3. Indexes for performance
CREATE INDEX IF NOT EXISTS idx_verification_codes_user_purpose
ON verification_codes(user_id, purpose, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_verification_codes_user_used
ON verification_codes(user_id, used, expires_at);

-- 4. Cleanup function for old codes
CREATE OR REPLACE FUNCTION cleanup_expired_verification_codes()
RETURNS void AS $$
BEGIN
    DELETE FROM verification_codes WHERE expires_at < NOW() - INTERVAL '24 hours';
END;
$$ LANGUAGE plpgsql;
