# MIGRATION_STRATEGY.md

> Sistema de control de versiones de migraciones de base de datos NEXO
> Fecha: 2026-07-19
> Versión: 1.0

---

## 1. RESUMEN EJECUTIVO

NEXO implementa un sistema de tracking de migraciones basado en la tabla `schema_migrations`. Cada migración ejecutada debe registrarse en esta tabla para evitar reejecuciones accidentales y mantener trazabilidad del estado del esquema.

---

## 2. TABLA DE CONTROL

### `schema_migrations`

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `migration_id` | UUID PK | Identificador único del registro |
| `filename` | VARCHAR(255) UNIQUE | Nombre del archivo SQL ejecutado |
| `version_label` | VARCHAR(50) | Etiqueta semántica (ej: `2026-07`, `v1.2.3`) |
| `description` | TEXT | Descripción legible de la migración |
| `checksum` | VARCHAR(64) | SHA-256 del archivo (para detectar modificaciones) |
| `executed_at` | TIMESTAMPTZ | Fecha/hora de ejecución |
| `executed_by` | VARCHAR(100) | Usuario o proceso que ejecutó |
| `execution_time_ms` | INTEGER | Duración en milisegundos |
| `success` | BOOLEAN | `TRUE` si se ejecutó correctamente |
| `rollback_script` | TEXT | SQL para revertir la migración (opcional) |
| `notes` | TEXT | Notas adicionales |

### Índices

- `idx_schema_migrations_executed_at` — para consultas recientes
- `idx_schema_migrations_version` — para filtrado por versión

### Funciones Helper

#### `migration_was_executed(p_filename VARCHAR(255))`
Retorna `TRUE` si la migración ya fue ejecutada exitosamente.

```sql
SELECT migration_was_executed('2026-21-nueva-tabla.sql');
```

#### `register_migration(...)`
Registra una migración como ejecutada. Idempotente: si el filename ya existe, actualiza el registro.

```sql
SELECT register_migration(
    '2026-21-nueva-tabla.sql',
    '2026-21',
    'Descripción de la migración',
    'abc123...',  -- checksum SHA-256
    'deploy-script',
    150,          -- ms de ejecución
    'Notas opcionales'
);
```

---

## 3. FLUJO DE TRABAJO

### Para bases de datos NUEVAS

```bash
# 1. Ejecutar esquema base (incluye tabla schema_migrations)
psql $DATABASE_URL -f backend/api/sql/nexo_full_migration.sql

# 2. Ejecutar fixes oficiales posteriores
psql $DATABASE_URL -f backend/api/sql/2026-07-fix-missing-tables.sql
# ... otros fixes según aplique

# 3. Ejecutar seed (solo desarrollo)
psql $DATABASE_URL -f backend/api/sql/nexo_seed.sql
```

El `nexo_full_migration.sql` ya incluye el registro automático de sí mismo mediante `register_migration()` al final del archivo.

### Para bases de datos EXISTENTES (backfill)

```bash
# Ejecutar UNA VEZ para registrar migraciones ya aplicadas
psql $DATABASE_URL -f backend/api/sql/schema_migrations_backfill.sql
```

Este script:
1. Marca como ejecutadas todas las migraciones consolidadas en `nexo_full_migration.sql`
2. Marca como ejecutadas las migraciones individuales post-sistema
3. Marca como **fallidas** las migraciones legacy INTEGER (para prevenir ejecución accidental)

### Para NUEVAS migraciones

Cada nuevo archivo SQL debe:

1. **Ser idempotente** (usar `IF NOT EXISTS`, `IF EXISTS`, etc.)
2. **Incluir al final** la llamada a `register_migration()`:

```sql
-- Contenido de la migración...
CREATE TABLE IF NOT EXISTS nueva_tabla (...);
CREATE INDEX IF NOT EXISTS idx_nueva_tabla ON nueva_tabla(...);

-- Registro obligatorio al final
SELECT register_migration(
    '2026-21-nueva-tabla.sql',
    '2026-21',
    'Creación de nueva_tabla para feature X',
    NULL,  -- calcular checksum en CI/CD
    CURRENT_USER,
    NULL,
    NULL
);
```

3. **Nombrarse con prefijo de fecha**: `YYYY-MM-descripcion.sql`

---

## 4. CONVENCIONES

### Nomenclatura de archivos

```
YYYY-MM-descripcion-breve.sql
```

Ejemplos:
- `2026-07-fix-missing-tables.sql`
- `2026-21-add-parent-notifications.sql`
- `2026-22-optimize-biometric-indexes.sql`

### Versionado

- Usar `YYYY-MM` como `version_label` para migraciones mensuales
- Usar semver (`v1.2.3`) solo para releases mayores del esquema completo

### Idempotencia

TODAS las migraciones deben ser idempotentes:

```sql
-- ✅ Correcto
CREATE TABLE IF NOT EXISTS ...
ALTER TABLE ... ADD COLUMN IF NOT EXISTS ...
CREATE INDEX IF NOT EXISTS ...
DROP POLICY IF EXISTS ...

-- ❌ Incorrecto
CREATE TABLE ...           -- falla si ya existe
ALTER TABLE ... ADD COLUMN ...  -- falla si ya existe
```

### Checksums

El campo `checksum` es opcional pero recomendado. En CI/CD:

```bash
CHECKSUM=$(sha256sum 2026-21-nueva-tabla.sql | cut -d' ' -f1)
psql $DATABASE_URL -c "SELECT register_migration('2026-21-nueva-tabla.sql', '2026-21', '...', '$CHECKSUM', 'ci-cd', ...);"
```

Si el checksum de un archivo cambia después de ser registrado, el sistema puede detectar la modificación.

---

## 5. COMPATIBILIDAD CON MIGRACIONES EXISTENTES

### Migraciones pre-sistema (consolidadas)

Todas las migraciones cuyo contenido fue absorbido por `nexo_full_migration.sql` están registradas en `schema_migrations_backfill.sql` con nota "Consolidado en nexo_full_migration.sql".

### Migraciones post-sistema (archivos individuales)

Las migraciones que aún se mantienen como archivos individuales:

| Archivo | Estado |
|---------|--------|
| `2026-07-fix-missing-tables.sql` | Activo — ejecutar si aplica |
| `2026-18-fix-student-group-assignments-unique.sql` | Activo — ejecutar si aplica |
| `2026-20-fix-panic-button-session-revocation.sql` | Activo — duplicado parcial de 2026-07 |

### Migraciones legacy (NO EJECUTAR)

Las migraciones con tipos INTEGER están marcadas en `schema_migrations` con `success = FALSE` y nota "ARCHIVADO". Esto previene ejecución accidental.

| Archivo | Motivo de archivado |
|---------|---------------------|
| `2026-06-scaling-partitioning.sql` | Usa `school_id INTEGER`, `student_id INTEGER` |
| `2026-08-twilio-tracking.sql` | Usa `school_id INTEGER`, `student_id INTEGER` |
| `2026-09-edge-devices.sql` | Usa `school_id INTEGER` |
| `2026-10-audit-chain.sql` | Funciones con parámetros `INTEGER` |
| `2026-11-behavior-metrics.sql` | Usa `school_id INTEGER`, `student_id INTEGER` |
| `2026-12-user-commands.sql` | Usa `school_id INTEGER`, `executed_by_user_id INTEGER` |
| `2026-13-rls-policies.sql` | `get_current_school_id()` retorna `INTEGER` |

---

## 6. ESTRUCTURA DEL DIRECTORIO

```
sql/
├── archive/
│   └── legacy_migrations/          ← Migraciones antiguas (referencia)
├── nexo_full_migration.sql         ← Esquema base completo + tracking
├── nexo_seed.sql                   ← Datos de simulación
├── schema_migrations_backfill.sql  ← Backfill para bases existentes
├── 2026-07-fix-missing-tables.sql  ← Fix activo
├── 2026-18-fix-...                 ← Fix activo
├── 2026-20-fix-...                 ← Fix activo
├── cleanup_maintenance.sql         ← Script operativo
├── purge_notification_garbage.sql  ← Script operativo
├── MIGRATION_STRATEGY.md           ← Este documento
└── SCHEMA_CONSOLIDATION.md         ← Documento de consolidación previa
```

---

## 7. ROLLBACK

### De una migración individual

Si una migración incluye `rollback_script`:

```sql
-- Ejecutar el rollback_script almacenado
DO $$
DECLARE v_script TEXT;
BEGIN
    SELECT rollback_script INTO v_script
    FROM schema_migrations WHERE filename = '2026-21-nueva-tabla.sql';
    IF v_script IS NOT NULL THEN
        EXECUTE v_script;
    END IF;
END $$;
```

### De todo el esquema

**⚠️ DESTRUCTIVO**: Elimina todas las tablas y datos.

```sql
-- Generar script de drop (ejecutar con EXTREMA precaución)
SELECT 'DROP TABLE IF EXISTS ' || tablename || ' CASCADE;'
FROM pg_tables WHERE schemaname = 'public';
```

---

## 8. MONITOREO Y ALERTAS

### Consultas útiles

```sql
-- Últimas migraciones ejecutadas
SELECT filename, version_label, executed_at, success
FROM schema_migrations
ORDER BY executed_at DESC
LIMIT 10;

-- Migraciones fallidas
SELECT filename, version_label, executed_at, notes
FROM schema_migrations
WHERE success = FALSE;

-- Verificar si una migración específica fue ejecutada
SELECT migration_was_executed('2026-21-nueva-tabla.sql');

-- Total de migraciones por estado
SELECT success, COUNT(*) FROM schema_migrations GROUP BY success;
```

---

## 9. RECOMENDACIONES

1. **Ejecutar siempre en transacción**: Las migraciones deben usar `BEGIN; ... COMMIT;` para garantizar atomicidad.

2. **Probar en staging primero**: Nunca ejecutar una migración nueva directamente en producción.

3. **Backup antes de migrar**: Siempre hacer backup de la base de datos antes de aplicar migraciones en producción.

4. **No modificar migraciones ya ejecutadas**: Si una migración tiene error, crear una nueva migración que lo corrija, no modificar el archivo original.

5. **Documentar breaking changes**: Si una migración rompe compatibilidad, documentarlo en `notes` y en el CHANGELOG del proyecto.

6. **Checksum en CI/CD**: Integrar el cálculo de checksum en el pipeline de deploy para detectar modificaciones no autorizadas.

---

*Documento generado durante la Etapa 2 del plan de trabajo NEXO.*
