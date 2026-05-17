-- ============================================================
-- NEXO MAINTENANCE CLEANUP
-- Ejecutar diariamente (CRON o Railway Scheduler)
-- psql $DATABASE_URL -f cleanup_maintenance.sql
-- ============================================================

-- 1. Purga de JWT blocklist expirados
DELETE FROM jwt_blocklist WHERE expires_at < NOW();

-- 2. Purga de rate_limits de ventanas pasadas (más de 24h)
DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL '24 hours';

-- 3. (Opcional) Compactar tabla si creció mucho
-- VACUUM FULL jwt_blocklist; -- Solo si sabes que no hay tráfico
