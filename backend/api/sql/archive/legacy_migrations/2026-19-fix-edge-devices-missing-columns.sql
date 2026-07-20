-- ============================================================
-- FIX (BUG-2): edge_devices missing columns for heartbeat/monitoring
-- ============================================================
-- Problem: routes/devices.php references columns that don't exist:
-- - status (device health status)
-- - last_seen_timestamp (timestamp from device)
-- - location (device physical location)
--
-- Impact: edge_monitor.py heartbeat fails every 60s with HTTP 500 because
-- UPDATE tries to set non-existent columns. Device listing endpoints also fail.
--
-- Solution: Add missing columns to edge_devices table
-- ============================================================

-- Add status column for device health tracking
ALTER TABLE edge_devices 
ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'unknown';

-- Add last_seen_timestamp for device-reported timestamp
ALTER TABLE edge_devices 
ADD COLUMN IF NOT EXISTS last_seen_timestamp TIMESTAMPTZ;

-- Add location column for device physical location
ALTER TABLE edge_devices 
ADD COLUMN IF NOT EXISTS location TEXT;

-- Add index for health check queries
CREATE INDEX IF NOT EXISTS idx_edge_devices_status_ping 
ON edge_devices(status, last_ping DESC NULLS LAST);
