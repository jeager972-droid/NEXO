CREATE TABLE IF NOT EXISTS twilio_messages (
    twilio_message_id UUID DEFAULT uuid_generate_v4() PRIMARY KEY,
    school_id INTEGER,
    student_id INTEGER,
    guardian_id INTEGER,
    sender_user_id INTEGER,
    type_code VARCHAR(50),
    direction VARCHAR(10) CHECK (direction IN ('INBOUND', 'OUTBOUND')),
    phone_number VARCHAR(50) NOT NULL,
    message_content TEXT,
    provider_message_sid VARCHAR(64) UNIQUE,
    delivery_status VARCHAR(20),
    metadata_json JSONB,
    sent_at TIMESTAMPTZ,
    received_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_twilio_sid ON twilio_messages(provider_message_sid);
CREATE INDEX IF NOT EXISTS idx_twilio_school_date ON twilio_messages(school_id, sent_at DESC);
