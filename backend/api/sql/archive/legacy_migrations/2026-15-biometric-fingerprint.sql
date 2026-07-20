-- Migration: Add event_fingerprint for idempotency in biometric_events
-- This column enables ON CONFLICT DO NOTHING to prevent duplicate event processing
-- when the biometric worker retries jobs.

ALTER TABLE biometric_events ADD COLUMN IF NOT EXISTS event_fingerprint VARCHAR(64);

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS idx_be_fingerprint 
ON biometric_events(event_fingerprint, event_timestamp) 
WHERE event_fingerprint IS NOT NULL;
