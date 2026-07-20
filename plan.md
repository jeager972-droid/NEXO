# PLAN DE TRABAJO NEXO — ETAPAS 2-8

> Documento maestro de planificación. Se eliminará al completar todas las etapas.
> Fecha: 2026-07-19

---

## ETAPA 2: Sistema de historial de migraciones

### Objetivo
Agregar control formal de versiones del esquema. Actualmente el proyecto no registra qué migraciones fueron ejecutadas.

### Tareas
1. Diseñar tabla `schema_migrations` para tracking de migraciones aplicadas
2. Crear mecanismo de registro automático (función/trigger o convención de ejecución)
3. Asegurar compatibilidad con migraciones existentes (2026-07, 2026-16 a 2026-20)
4. Generar `MIGRATION_STRATEGY.md`

### Archivos a crear/modificar
- `backend/api/sql/schema_migrations.sql` — tabla y mecanismo
- `backend/api/sql/MIGRATION_STRATEGY.md` — documentación

### Riesgos
- Migraciones existentes no registradas en historial → requiere backfill o marcarlas como "pre-sistema"
- Cambio en flujo de deploy → documentar claramente

### Restricciones
- No modificar lógica de negocio
- Solo infraestructura de migraciones

---

## ETAPA 3: Auditoría de compatibilidad PHP

### Objetivo
Verificar que todo el backend utilice exclusivamente el esquema consolidado (UUID, tablas modernas).

### Tareas
1. Revisar todas las rutas PHP (`backend/api/routes/`)
2. Revisar middleware (`backend/api/routes/_auth_middleware.php`)
3. Revisar workers (`backend/api/workers/`)
4. Revisar helpers/lib (`backend/api/lib/`)
5. Buscar: tablas legacy, columnas antiguas, INTEGER obsoletos, referencias incompatibles
6. Corregir únicamente incompatibilidades confirmadas
7. Generar `COMPATIBILITY_REPORT.md`

### Archivos a analizar
- `backend/api/routes/*.php`
- `backend/api/workers/*.php`
- `backend/api/lib/*.php`
- `backend/api/db.php`
- `backend/api/api.php`

### Riesgos
- Falso positivo en búsqueda de INTEGER (puede ser casting PostgreSQL legítimo)
- Cambio accidental de comportamiento al corregir incompatibilidades

### Restricciones
- No hacer optimizaciones
- No hacer refactors
- Solo corregir incompatibilidades

---

## ETAPA 4: Diseño para eliminar SUPER_RECTOR

### Objetivo
Analizar completamente el rol SUPER_RECTOR, identificar responsabilidades, separarlas, diseñar nueva arquitectura. **NO implementar nada.**

### Tareas
1. Identificar todas las referencias a `SUPER_RECTOR` en código PHP
2. Identificar todas las políticas RLS que usan `is_super_rector()`
3. Identificar permisos exclusivos de SUPER_RECTOR
4. Separar responsabilidades en roles independientes (ej: SYSTEM_ADMIN, SCHOOL_ADMIN, etc.)
5. Diseñar nueva arquitectura de roles y permisos
6. Plan de migración de usuarios existentes
7. Generar `SUPER_RECTOR_REMOVAL_PLAN.md`

### Archivos a analizar (solo lectura)
- Todo el backend PHP
- `nexo_full_migration.sql` (RLS policies)
- `2026-13-rls-policies.sql` (archivado, referencia)

### Riesgos
- SUPER_RECTOR puede estar hardcodeado en múltiples lugares
- Cambio de arquitectura de roles puede romper autenticación

### Restricciones
- No modificar código
- No modificar políticas RLS
- No modificar usuarios
- Solo diseñar la arquitectura

---

## ETAPA 5: Implementación del reemplazo de SUPER_RECTOR

### Objetivo
Implementar completamente la arquitectura definida en Etapa 4. Eliminar completamente SUPER_RECTOR.

### Tareas
1. Crear nuevos roles en tabla `roles`
2. Actualizar permisos y `role_permissions`
3. Modificar/eliminar función `is_super_rector()` en PostgreSQL
4. Actualizar todas las políticas RLS
5. Actualizar código PHP que verifica `SUPER_RECTOR`
6. Migrar usuarios existentes a nuevos roles
7. Generar `SUPER_RECTOR_IMPLEMENTATION.md`

### Archivos a modificar
- `backend/api/sql/nexo_full_migration.sql` — roles, RLS, funciones
- `backend/api/routes/*.php` — verificaciones de rol
- `backend/api/routes/_auth_middleware.php` — autenticación
- Posibles workers y lib

### Riesgos
- Alto: puede romper autenticación de todos los usuarios
- Requiere testing exhaustivo
- Rollback plan necesario

### Restricciones
- Garantizar que ningún proceso dependa de SUPER_RECTOR
- Documentar todos los cambios

---

## ETAPA 6: Auditoría de seguridad

### Objetivo
Auditoría completa de seguridad: RLS bypass, SQL Injection, privilegios excesivos, variables de sesión peligrosas, consultas inseguras.

### Tareas
1. Revisar todas las consultas SQL en PHP por SQL Injection
2. Revisar variables de sesión PostgreSQL (`app.current_school_id`, `app.current_role`)
3. Revisar políticas RLS por bypass potenciales
4. Revisar permisos de tablas y funciones
5. Revisar manejo de contraseñas y tokens
6. Corregir únicamente vulnerabilidades confirmadas
7. Generar `SECURITY_AUDIT.md`

### Archivos a analizar
- Todo el backend PHP
- Esquema SQL (tablas, funciones, RLS)

### Riesgos
- Falso positivo en análisis de seguridad
- Fix de seguridad puede romper funcionalidad existente

### Restricciones
- Solo corregir vulnerabilidades confirmadas
- Documentar cada hallazgo

---

## ETAPA 7: Eliminación de deuda técnica

### Objetivo
Eliminar deuda técnica relacionada con la base de datos: comentarios obsoletos, código muerto, funciones legacy, migraciones innecesarias, documentación desactualizada.

### Tareas
1. Revisar comentarios obsoletos en SQL y PHP
2. Identificar funciones/código no utilizado
3. Revisar documentación desactualizada
4. Limpiar sin modificar comportamiento
5. Actualizar o eliminar documentación obsoleta

### Archivos a revisar
- Todo el backend
- Documentación existente

### Riesgos
- Eliminar código que parece muerto pero tiene referencia indirecta
- Perder contexto histórico importante

### Restricciones
- No modificar comportamiento
- No optimizar
- Solo limpieza

---

## ETAPA 8: Documentación definitiva

### Objetivo
Generar documentación definitiva de la arquitectura de base de datos.

### Tareas
1. Documentar estructura completa del esquema
2. Documentar flujo de migraciones (post-Etapa 2)
3. Documentar modelo de seguridad y RLS
4. Documentar convenciones de nomenclatura
5. Escribir recomendaciones para futuros desarrolladores
6. Crear `DATABASE_ARCHITECTURE.md`

### Archivos a crear
- `backend/api/sql/DATABASE_ARCHITECTURE.md`

### Restricciones
- No modificar código
- Solo documentación

---

## ORDEN DE EJECUCIÓN

```
Etapa 2 → Etapa 3 → Etapa 4 → Etapa 5 → Etapa 6 → Etapa 7 → Etapa 8
```

Cada etapa requiere luz verde del usuario antes de comenzar.

---

## NOTAS

- Este plan.md será eliminado al completar la Etapa 8
- Cada etapa generará su propio informe/documento
- El system prompt del proyecto rige todas las etapas
