-- =============================================================================
-- SCHEMA MIGRATIONS BACKFILL
-- =============================================================================
-- RESPONSABILIDAD:
--   Registra en schema_migrations los archivos de migración que ya fueron
--   aplicados en bases de datos existentes antes de la consolidación. Evita
--   que el sistema de migraciones intente reejecutar scripts cuyo contenido
--   ya está en nexo_full_migration.sql o que son independientes/legacy.
--
-- EJECUTAR:
--   psql $DATABASE_URL -f schema_migrations_backfill.sql
--
-- CATEGORÍAS:
--   1. Pre-sistema: migraciones absorbidas por nexo_full_migration.sql.
--   2. Post-sistema: archivos independientes que aún se ejecutan por separado.
--   3. Legacy archivadas: marcadas como fallidas porque usan INTEGER en lugar
--      de UUID; NO deben ejecutarse.
--
-- Idempotente: ON CONFLICT (filename) actualiza success y notes sin duplicar.
-- =============================================================================

-- Asegurar que la tabla de tracking existe (por si se ejecuta antes que nexo_full_migration.sql)
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    filename          VARCHAR(255) NOT NULL UNIQUE,
    version_label     VARCHAR(50),
    description       TEXT,
    checksum          VARCHAR(64),
    executed_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    executed_by       VARCHAR(100),
    execution_time_ms INTEGER,
    success           BOOLEAN NOT NULL DEFAULT TRUE,
    rollback_script   TEXT,
    notes             TEXT
);

-- =============================================================================
-- MIGRACIONES PRE-SISTEMA (contenido ya consolidado en nexo_full_migration.sql)
-- Estas migraciones fueron absorbidas por el esquema base consolidado.
-- Se marcan como ejecutadas para evitar reejecución accidental.
-- =============================================================================

INSERT INTO schema_migrations (filename, version_label, description, notes)
VALUES
    ('nexo_full_migration.sql', '2026-05', 'Esquema base completo consolidado', 'Incluye tablas base, índices, constraints, triggers, funciones RLS, particiones, seed mínimo. Consolidación de múltiples migraciones antiguas.'),
    ('2026-05-hardening-indexes.sql', '2026-05', 'Índices de performance', 'Consolidado en nexo_full_migration.sql'),
    ('2026-05-portable-hardening.sql', '2026-05', 'Índices adicionales + constraints UNIQUE', 'Consolidado en nexo_full_migration.sql'),
    ('2026-05-stateful-hardening.sql', '2026-05', 'Rate limits, JWT blocklist, trigger teléfono', 'Consolidado en nexo_full_migration.sql'),
    ('2026-06-profile-and-shift.sql', '2026-06', 'Columnas profile_photo_url y work_shift', 'Consolidado en nexo_full_migration.sql'),
    ('2026-07-profile-verification.sql', '2026-07', 'Tabla verification_codes + columnas verificación', 'Consolidado en nexo_full_migration.sql'),
    ('2026-11-additional-indexes.sql', '2026-11', 'Índices adicionales de performance', 'Consolidado en nexo_full_migration.sql. Nota: índice sobre audit_trail descartado (tabla no existe).'),
    ('2026-14-system-telemetry.sql', '2026-14', 'Tabla system_telemetry con RLS', 'Consolidado en nexo_full_migration.sql'),
    ('2026-15-biometric-fingerprint.sql', '2026-15', 'Columna event_fingerprint + índice único', 'Consolidado en nexo_full_migration.sql'),
    ('2026-15-contact-leads.sql', '2026-15', 'Tabla contact_leads para landing page', 'Consolidado en nexo_full_migration.sql'),
    ('2026-16-fix-jwt-blocklist-rls.sql', '2026-16', 'Fix políticas RLS jwt_blocklist', 'Consolidado en nexo_full_migration.sql'),
    ('2026-17-fix-students-multi-tenant-unique.sql', '2026-17', 'Fix constraint multi-tenant students', 'Consolidado en nexo_full_migration.sql'),
    ('2026-19-fix-edge-devices-missing-columns.sql', '2026-19', 'Fix columnas faltantes edge_devices', 'Consolidado en nexo_full_migration.sql'),
    ('fix_verification_codes.sql', '2026-07', 'Fix tabla verification_codes y columnas users', 'Consolidado en nexo_full_migration.sql'),
    ('student_tracking_schema.sql', '2026-07', 'Tablas student_tracking y student_tracking_notes', 'Consolidado en nexo_full_migration.sql')
ON CONFLICT (filename) DO UPDATE SET
    success = TRUE,
    notes = EXCLUDED.notes;

-- =============================================================================
-- MIGRACIONES POST-SISTEMA (archivos individuales que aún se ejecutan por separado)
-- Estas deben registrarse si ya fueron aplicadas en la base de datos.
-- =============================================================================

INSERT INTO schema_migrations (filename, version_label, description, notes)
VALUES
    ('2026-07-fix-missing-tables.sql', '2026-07', 'Tablas school_panic_events y system_telemetry', 'Archivo independiente. Crea tablas faltantes con RLS.'),
    ('2026-18-fix-student-group-assignments-unique.sql', '2026-18', 'Fix constraint UNIQUE student_group_assignments', 'Archivo independiente. Agrega uq_sga_student_group.'),
    ('2026-20-fix-panic-button-session-revocation.sql', '2026-20', 'Tabla school_panic_events para revocación', 'Archivo independiente. Duplicado parcial de 2026-07.')
ON CONFLICT (filename) DO UPDATE SET
    success = TRUE,
    notes = EXCLUDED.notes;

-- =============================================================================
-- MIGRACIONES LEGACY ARCHIVADAS (NO EJECUTAR - marcadas como fallidas/intencionalmente ignoradas)
-- Estas usan tipos INTEGER en lugar de UUID y son incompatibles.
-- =============================================================================

INSERT INTO schema_migrations (filename, version_label, description, success, notes)
VALUES
    ('2026-06-scaling-partitioning.sql', '2026-06', 'Particionamiento biometric_events (INTEGER)', FALSE, 'ARCHIVADO: Usa tipos INTEGER. Incompatible con esquema UUID. NO ejecutar.'),
    ('2026-08-twilio-tracking.sql', '2026-08', 'Tabla twilio_messages (INTEGER)', FALSE, 'ARCHIVADO: Usa tipos INTEGER. Incompatible con esquema UUID. NO ejecutar.'),
    ('2026-09-edge-devices.sql', '2026-09', 'Tabla edge_devices (INTEGER)', FALSE, 'ARCHIVADO: Usa tipos INTEGER. Incompatible con esquema UUID. NO ejecutar.'),
    ('2026-10-audit-chain.sql', '2026-10', 'Audit chain con funciones INTEGER', FALSE, 'ARCHIVADO: Funciones usan parámetros INTEGER. Incompatible con esquema UUID. NO ejecutar.'),
    ('2026-11-behavior-metrics.sql', '2026-11', 'Tabla student_behavior_metrics (INTEGER)', FALSE, 'ARCHIVADO: Usa tipos INTEGER. Incompatible con esquema UUID. NO ejecutar.'),
    ('2026-12-user-commands.sql', '2026-12', 'Tabla user_commands (INTEGER)', FALSE, 'ARCHIVADO: Usa tipos INTEGER. Incompatible con esquema UUID. NO ejecutar.'),
    ('2026-13-rls-policies.sql', '2026-13', 'RLS policies con get_current_school_id() INTEGER', FALSE, 'ARCHIVADO: Función retorna INTEGER. Incompatible con esquema UUID. NO ejecutar.')
ON CONFLICT (filename) DO UPDATE SET
    success = FALSE,
    notes = EXCLUDED.notes;

-- =============================================================================
-- REGISTRO DEL PROPIO BACKFILL
-- =============================================================================

SELECT register_migration(
    'schema_migrations_backfill.sql',
    '2026-07-19',
    'Backfill de migraciones pre-sistema y legacy archivadas',
    NULL,
    'migration_system',
    NULL,
    'Registra migraciones ya ejecutadas para evitar reejecución. Incluye marcado de archivos legacy como fallidos.'
);
