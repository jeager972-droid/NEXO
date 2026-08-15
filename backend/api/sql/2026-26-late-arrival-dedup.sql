-- =============================================================================
-- 2026-26-late-arrival-dedup.sql
-- =============================================================================
-- PROPÓSITO: Crear unique index parcial en attendance_incidents para prevenir
--            duplicados de LATE_ARRIVAL por estudiante/día/escuela.
--
-- HALLAZGO: VF-011 — LATE_ARRIVAL race condition (NOT EXISTS no atómico)
-- SEVERIDAD: P1 (duplicados de incidentes si 2 workers procesan mismo estudiante)
--
-- CAMBIO:
--   1. Limpiar duplicados existentes (mantener el más antiguo por detected_at)
--   2. Crear unique index parcial: (student_id, school_id, (detected_at)::date)
--      WHERE incident_type = 'LATE_ARRIVAL'
--   3. El worker_biometric.php usará ON CONFLICT DO NOTHING en lugar de NOT EXISTS
--
-- SEGURIDAD: El index es ADITIVO (CREATE UNIQUE INDEX IF NOT EXISTS).
--            La limpieza de duplicados solo afecta LATE_ARRIVAL duplicados
--            (mismo estudiante, misma escuela, misma fecha), manteniendo el
--            registro más antiguo (primer detección de tardanza).
--
-- NOTA: attendance_incidents es particionada. PostgreSQL propaga el index
--       del parent a todas las particiones existentes y futuras.
-- =============================================================================

-- Paso 1: Limpiar duplicados existentes de LATE_ARRIVAL
-- Mantener el registro más antiguo (primer detección) por estudiante/escuela/fecha
DELETE FROM attendance_incidents a
USING attendance_incidents b
WHERE a.incident_type = 'LATE_ARRIVAL'
  AND b.incident_type = 'LATE_ARRIVAL'
  AND a.student_id = b.student_id
  AND a.school_id = b.school_id
  AND (a.detected_at)::date = (b.detected_at)::date
  AND a.incident_id > b.incident_id;  -- Mantener el de menor incident_id (más antiguo)

-- Paso 2: Crear unique index parcial
-- El expression index usa (detected_at)::date para agrupar por día
CREATE UNIQUE INDEX IF NOT EXISTS uq_late_arrival_per_day
ON attendance_incidents (student_id, school_id, (detected_at)::date)
WHERE incident_type = 'LATE_ARRIVAL';

-- =============================================================================
-- VERIFICACIÓN POST-MIGRACIÓN:
--
-- 1. Verificar que el index existe:
--    SELECT indexname FROM pg_indexes WHERE tablename LIKE 'attendance_incidents%'
--      AND indexname = 'uq_late_arrival_per_day';
--    Expected: al menos 1 row (parent + una por partición)
--
-- 2. Verificar que no hay duplicados:
--    SELECT student_id, school_id, (detected_at)::date, COUNT(*)
--    FROM attendance_incidents WHERE incident_type = 'LATE_ARRIVAL'
--    GROUP BY 1, 2, 3 HAVING COUNT(*) > 1;
--    Expected: 0 rows
-- =============================================================================
