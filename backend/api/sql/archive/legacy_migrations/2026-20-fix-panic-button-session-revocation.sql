-- ============================================================
-- FIX (BUG-3): Panic button doesn't actually revoke sessions
-- ============================================================
-- Problem: security_panic.php claims to invalidate all sessions but only
-- deletes old jwt_blocklist entries (>90 days). No actual JWTs are revoked.
--
-- Impact: Panic button returns 'sessions_revoked': true falsely. Active
-- sessions remain valid after panic is triggered.
--
-- Solution: Create school_panic_events table to track panic timestamps,
-- and modify JWT verification to reject tokens issued before panic.
-- ============================================================

-- Create table to track panic events per school
CREATE TABLE IF NOT EXISTS school_panic_events (
    panic_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    school_id UUID NOT NULL REFERENCES schools(school_id),
    triggered_by_user_id UUID NOT NULL REFERENCES users(user_id),
    triggered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    devices_deactivated INTEGER NOT NULL DEFAULT 0,
    metadata_json JSONB
);

-- Index for fast panic check during JWT verification
CREATE INDEX IF NOT EXISTS idx_school_panic_events_school_time 
ON school_panic_events(school_id, triggered_at DESC);

-- Index to find latest panic for a school
CREATE INDEX IF NOT EXISTS idx_school_panic_events_latest 
ON school_panic_events(school_id, triggered_at DESC);
