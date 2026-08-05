-- =============================================================================
-- nexo_multi_jornada.sql
-- =============================================================================
-- Migración: Soporte para múltiples jornadas por institución.
--
-- Permite que una escuela tenga configuraciones independientes para cada jornada
-- (mañana, tarde, noche, etc.) con sus propios horarios, recesos y bloques.
--
-- CAMBIOS:
--   school_schedule_config  — PK pasa de (school_id) a (school_id, work_shift)
--   school_time_blocks      — ADD work_shift + UNIQUE(school_id, work_shift, block_number)
-- =============================================================================

-- 1. school_schedule_config: cambiar PK para permitir múltiples jornadas
ALTER TABLE school_schedule_config DROP CONSTRAINT IF EXISTS school_schedule_config_pkey;
ALTER TABLE school_schedule_config ADD PRIMARY KEY (school_id, work_shift);

COMMENT ON COLUMN school_schedule_config.work_shift IS 'Jornada: mañana, tarde, noche, completa. Una fila por jornada.';

-- 2. school_time_blocks: añadir work_shift
ALTER TABLE school_time_blocks ADD COLUMN IF NOT EXISTS work_shift VARCHAR(50) NOT NULL DEFAULT 'mañana';

COMMENT ON COLUMN school_time_blocks.work_shift IS 'Jornada a la que pertenece este bloque horario.';

-- 3. Actualizar constraint único para incluir work_shift
ALTER TABLE school_time_blocks DROP CONSTRAINT IF EXISTS school_time_blocks_school_id_block_number_key;
ALTER TABLE school_time_blocks ADD CONSTRAINT school_time_blocks_school_workshift_block_key
    UNIQUE(school_id, work_shift, block_number);

-- 4. Índice optimizado para queries por jornada
CREATE INDEX IF NOT EXISTS idx_school_time_blocks_shift ON school_time_blocks(school_id, work_shift, block_number);
