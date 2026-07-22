-- ============================================================================
-- PURGE: Limpieza de datos de notificaciones "basura"
-- Ejecutar en PostgreSQL (con precaución, hacer backup primero)
-- ============================================================================

-- 1. ELIMINAR incidentes de asistencia viejos (test/duplicados) que NO sean permisos
--    Estos eran los que inundaban la sección de notificaciones.
--    IMPORTANTE: Solo borrar los de más de 30 días para preservar auditoría reciente.
DELETE FROM attendance_incidents
WHERE incident_type IN ('INASISTENCIA','LATE_ARRIVAL','LATE:ARRIVAL','EARLY_EXIT',
                        'EARLY:DEPARTURE','EARLY_DEPARTURE','UNAUTHORIZED_ABSENCE',
                        'UNAUTHORIZED:ABSENCE','EVASION_INTERNA',
                        'BIOMETRIC_FAILURE','SPAM_BIOMETRIC',
                        'CITACION','INCIDENTE','DAÑO','PEDAGOGICA','HORARIO')
  AND detected_at < NOW() - INTERVAL '30 days';

-- 2. Borrar SOS de prueba (alert_description vacío o contiene 'test')
DELETE FROM sos_alerts
WHERE alert_description ILIKE '%test%'
   OR alert_description ILIKE '%prueba%'
   OR alert_description = ''
   OR alert_description IS NULL;

-- 3. Borrar mensajes internos duplicados o de prueba
DELETE FROM internal_messages
WHERE message_content ILIKE '%test%'
   OR message_content ILIKE '%prueba%'
   OR message_content = '';

-- 4. Vaciar report_exports viejos (pendientes de notificaciones)
DELETE FROM report_exports
WHERE generated_at < NOW() - INTERVAL '7 days';
