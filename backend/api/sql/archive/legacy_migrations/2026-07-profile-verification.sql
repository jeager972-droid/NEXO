-- Migration: profile verification fields + backup email + OTP table
-- Safe, incremental, non-destructive.

-- 1. Add profile fields to users
ALTER TABLE users
ADD COLUMN IF NOT EXISTS backup_email VARCHAR(255);

ALTER TABLE users
ADD COLUMN IF NOT EXISTS email_verified BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE users
ADD COLUMN IF NOT EXISTS phone_verified BOOLEAN NOT NULL DEFAULT FALSE;

-- 2. OTP / verification codes table
CREATE TABLE IF NOT EXISTS verification_codes (
    code_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    purpose VARCHAR(50) NOT NULL, -- 'email_change', 'phone_change', 'password_reset', 'backup_email'
    target_value TEXT NOT NULL,    -- the new email/phone being verified
    code VARCHAR(10) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    verified_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_verification_codes_user_purpose
ON verification_codes(user_id, purpose, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_verification_codes_active
ON verification_codes(code, expires_at)
WHERE used = FALSE AND expires_at > NOW();

-- 3. Auto-expire old codes function
CREATE OR REPLACE FUNCTION cleanup_expired_verification_codes()
RETURNS void AS $$
BEGIN
    DELETE FROM verification_codes WHERE expires_at < NOW() - INTERVAL '24 hours';
END;
$$ LANGUAGE plpgsql;
