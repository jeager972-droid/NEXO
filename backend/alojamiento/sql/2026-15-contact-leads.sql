-- Migration: Contact Leads Table
-- Purpose: Store contact form submissions from landing page
-- Created: 2026-06-14

CREATE TABLE IF NOT EXISTS contact_leads (
    lead_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nombre       VARCHAR(200)  NOT NULL,
    cargo        VARCHAR(100)  NOT NULL,
    institucion  VARCHAR(300)  NOT NULL,
    municipio    VARCHAR(200)  NOT NULL,
    email        VARCHAR(254)  NOT NULL,
    whatsapp     VARCHAR(30)   NOT NULL,
    mensaje      TEXT,
    ip_address   VARCHAR(45),
    created_at   TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

-- Index for querying by email (useful for deduplication)
CREATE INDEX IF NOT EXISTS idx_contact_leads_email ON contact_leads(email);

-- Index for querying by date range (useful for analytics)
CREATE INDEX IF NOT EXISTS idx_contact_leads_created_at ON contact_leads(created_at);
