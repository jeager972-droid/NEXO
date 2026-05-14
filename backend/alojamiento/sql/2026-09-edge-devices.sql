CREATE TABLE IF NOT EXISTS edge_devices (
    device_id UUID DEFAULT uuid_generate_v4() PRIMARY KEY,
    school_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(150),
    token_hash VARCHAR(255) NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    last_ping TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT fk_edge_school FOREIGN KEY (school_id) REFERENCES schools(school_id)
);

CREATE INDEX IF NOT EXISTS idx_edge_devices_school ON edge_devices(school_id, active);
