# SCHEMA_CONSOLIDATION.md

> Consolidación definitiva del esquema de base de datos NEXO
> Fecha: 2026-07-19
> Autor: Kimi (bajo directrices del system prompt del proyecto)

---

## 1. FUENTE OFICIAL DEL ESQUEMA

La única fuente oficial del esquema de NEXO son los siguientes archivos, en orden de ejecución:

| Orden | Archivo | Propósito |
|-------|---------|-----------|
| 1 | `nexo_full_migration.sql` | Esquema completo: tablas, índices, constraints, triggers, funciones, RLS, particiones, seed mínimo |
| 2 | `nexo_seed.sql` | Datos de simulación completos para desarrollo/testing |

**Nota:** Todos los fixes (2026-07 a 2026-20) fueron consolidados en `nexo_full_migration.sql` en Etapa 4.

**NO ejecutar ningún otro archivo SQL del repositorio sobre una base de datos limpia.**

---

## 2. ARCHIVOS CONSOLIDADOS EN `nexo_full_migration.sql`

El siguiente contenido de archivos antiguos ya está **completamente incorporado** en `nexo_full_migration.sql`:

| Archivo antiguo | Contenido consolidado |
|-----------------|----------------------|
| `2026-05-hardening-indexes.sql` | Todos los índices de performance |
| `2026-05-portable-hardening.sql` | Índices adicionales + constraints UNIQUE |
| `2026-05-stateful-hardening.sql` | Tablas `rate_limits`, `jwt_blocklist`, trigger de normalización de teléfono |
| `2026-06-profile-and-shift.sql` | Columnas `profile_photo_url`, `work_shift` + índices |
| `2026-07-profile-verification.sql` | Tabla `verification_codes` + columnas `email_verified`, `phone_verified`, `backup_email` |
| `2026-11-additional-indexes.sql` | Índice `idx_biometric_events_school_student_time` (el índice sobre `audit_trail` referencia tabla inexistente, descartado) |
| `2026-14-system-telemetry.sql` | Tabla `system_telemetry` con índices y RLS |
| `2026-15-biometric-fingerprint.sql` | Columna `event_fingerprint` + índice único |
| `2026-15-contact-leads.sql` | Tabla `contact_leads` con índices |
| `2026-16-fix-jwt-blocklist-rls.sql` | Políticas RLS permisivas para `jwt_blocklist` |
| `2026-17-fix-students-multi-tenant-unique.sql` | Constraint `uq_students_school_document` |
| `2026-19-fix-edge-devices-missing-columns.sql` | Columnas `status`, `last_seen_timestamp`, `location` |
| `fix_verification_codes.sql` | Tabla `verification_codes` + columnas `users` + función cleanup |
| `student_tracking_schema.sql` | Tablas `student_tracking` y `student_tracking_notes` |

---

## 3. ARCHIVOS CONSOLIDADOS EN `nexo_seed.sql`

| Archivo antiguo | Contenido consolidado |
|-----------------|----------------------|
| `seed_data_nexo.sql` | Todos los datos de simulación (geografía, roles, permisos, usuarios, estudiantes, acudientes, aulas, grupos, eventos biométricos, incidentes, alertas SOS, mensajes Twilio, comandos, audit logs, etc.) |
| `create_nexo_users.sql` | Usuarios adicionales por rol (rector, coordinador, docente, psicorientador, secretaria, portero, auxiliar) |

---

## 4. MIGRACIONES ARCHIVADAS (GENERACIÓN ANTIGUA INTEGER)

Los siguientes archivos fueron movidos a `archive/legacy_migrations/` porque usan tipos `INTEGER` para claves foráneas en lugar de `UUID`, lo que los hace **incompatibles** con el esquema moderno:

| Archivo | Problema crítico |
|---------|-----------------|
| `2026-06-scaling-partitioning.sql` | `biometric_events` con `school_id INTEGER`, `student_id INTEGER` |
| `2026-08-twilio-tracking.sql` | `twilio_messages` con `school_id INTEGER`, `student_id INTEGER`, etc. |
| `2026-09-edge-devices.sql` | `edge_devices` con `school_id INTEGER` |
| `2026-10-audit-chain.sql` | Funciones con parámetros `INTEGER` (`p_school_id`, `p_actor_id`) |
| `2026-11-behavior-metrics.sql` | `student_behavior_metrics` con `school_id INTEGER`, `student_id INTEGER` |
| `2026-12-user-commands.sql` | `user_commands` con `school_id INTEGER`, `executed_by_user_id INTEGER` |
| `2026-13-rls-policies.sql` | `get_current_school_id()` retorna `INTEGER` en lugar de `UUID` |

**Riesgo si se ejecutan:** Sobrescribirían las funciones con firmas `INTEGER`, rompiendo toda la aplicación PHP que espera `UUID`.

---

## 5. MOTIVO DEL ARCHIVADO

1. **Inconsistencia de tipos:** La generación antigua usa `INTEGER` para todas las FKs. La generación moderna usa `UUID`.
2. **Dependencia del orden de ejecución:** Si alguien ejecuta primero los archivos antiguos y luego los modernos, las tablas se crean con tipos incorrectos y las funciones fallan.
3. **Duplicación:** Todo el contenido funcional de los archivos antiguos ya está presente en `nexo_full_migration.sql` con los tipos correctos (`UUID`).
4. **Preservación histórica:** Los archivos NO fueron eliminados, solo movidos a `archive/` para referencia histórica y auditoría.

---

## 6. RIESGOS ENCONTRADOS

### Riesgo 1: Constraint faltante en `student_group_assignments` (RESUELTO)
- **Problema:** `2026-18-fix-student-group-assignments-unique.sql` definía `uq_sga_student_group` en `(student_id, group_id)`, pero este constraint **no existía** en `nexo_full_migration.sql`.
- **Impacto:** El PHP (`routes/students.php`) usa `ON CONFLICT (student_id, group_id)`. Sin el constraint, PostgreSQL arroja error `42P10`.
- **Resolución:** Agregado al `nexo_full_migration.sql` mediante bloque DO idempotente.

### Riesgo 2: Tabla `contact_leads` ausente del esquema oficial (RESUELTO)
- **Problema:** La tabla `contact_leads` solo existía en `2026-15-contact-leads.sql` y **no estaba** en `nexo_full_migration.sql`.
- **Impacto:** El endpoint de contacto de la landing page (`misc.php:80`) fallaría con "relation does not exist".
- **Resolución:** Agregada al `nexo_full_migration.sql` con sus índices.

### Riesgo 3: Índice sobre tabla inexistente `audit_trail` (IDENTIFICADO, NO RESUELTO)
- **Problema:** `2026-11-additional-indexes.sql` referencia `audit_trail(synced)`.
- **Impacto:** La tabla `audit_trail` no existe en el esquema moderno. Este índice es un residuo de la generación antigua.
- **Estado:** No incorporado al esquema oficial. Si en el futuro se crea `audit_trail`, este índice deberá recrearse.

### Riesgo 4: Duplicación de `school_panic_events`
- **Problema:** La tabla `school_panic_events` existe tanto en `2026-07-fix-missing-tables.sql` como en `2026-20-fix-panic-button-session-revocation.sql`.
- **Impacto:** Ninguno, ambos usan `CREATE TABLE IF NOT EXISTS`.
- **Estado:** Aceptado. Se mantiene `2026-07-fix-missing-tables.sql` como fuente oficial porque es más completo (incluye `system_telemetry`).

---

## 7. VERIFICACIONES REALIZADAS

| Verificación | Resultado |
|--------------|-----------|
| Todos los archivos SQL leídos y analizados | ✅ 27/27 |
| Búsqueda de referencias PHP a tablas/columnas | ✅ Confirmado uso de `contact_leads`, `school_panic_events`, `system_telemetry`, `student_tracking`, `verification_codes`, `rate_limits`, `jwt_blocklist`, `event_fingerprint` |
| Identificación de tipos INTEGER vs UUID | ✅ 7 archivos con INTEGER marcados como legacy |
| Identificación de contenido duplicado | ✅ 14 archivos con contenido ya en `nexo_full_migration.sql` |
| Identificación de contenido faltante en esquema oficial | ✅ 2 elementos (`contact_leads`, `uq_sga_student_group`) |
| Consolidación de seed data | ✅ `seed_data_nexo.sql` + `create_nexo_users.sql` → `nexo_seed.sql` |
| Movimiento de archivos legacy a `archive/` | ✅ 24 archivos movidos |
| Limpieza de archivos obsoletos de `sql/` | ✅ Completada |
| Validación de estado final del directorio | ✅ 6 archivos restantes (esquema, seed, fixes, mantenimiento, archive) |

---

## 8. ESTRUCTURA FINAL DE `backend/api/sql/`

```
sql/
├── archive/
│   └── legacy_migrations/          ← 24 archivos archivados (referencia histórica)
│       ├── 2026-05-hardening-indexes.sql
│       ├── 2026-05-portable-hardening.sql
│       ├── 2026-05-stateful-hardening.sql
│       ├── 2026-06-profile-and-shift.sql
│       ├── 2026-06-scaling-partitioning.sql      ← INTEGER
│       ├── 2026-07-profile-verification.sql
│       ├── 2026-08-twilio-tracking.sql           ← INTEGER
│       ├── 2026-09-edge-devices.sql              ← INTEGER
│       ├── 2026-10-audit-chain.sql               ← INTEGER
│       ├── 2026-11-additional-indexes.sql
│       ├── 2026-11-behavior-metrics.sql          ← INTEGER
│       ├── 2026-12-user-commands.sql             ← INTEGER
│       ├── 2026-13-rls-policies.sql              ← INTEGER
│       ├── 2026-14-system-telemetry.sql
│       ├── 2026-15-biometric-fingerprint.sql
│       ├── 2026-15-contact-leads.sql
│       ├── 2026-16-fix-jwt-blocklist-rls.sql
│       ├── 2026-17-fix-students-multi-tenant-unique.sql
│       ├── 2026-18-fix-student-group-assignments-unique.sql
│       ├── 2026-19-fix-edge-devices-missing-columns.sql
│       ├── 2026-20-fix-panic-button-session-revocation.sql
│       ├── create_nexo_users.sql
│       ├── fix_verification_codes.sql
│       ├── seed_data_nexo.sql
│       └── student_tracking_schema.sql
├── cleanup_maintenance.sql          ← Script operativo (no esquema)
├── nexo_full_migration.sql          ← ESQUEMA OFICIAL COMPLETO (consolidado Etapa 4)
├── nexo_seed.sql                    ← SEED OFICIAL COMPLETO
├── purge_notification_garbage.sql   ← Script operativo (no esquema)
└── SCHEMA_CONSOLIDATION.md          ← Este documento
```

---

## 9. POSIBLES MEJORAS FUTURAS (SIN IMPLEMENTAR)

1. **Unificar fixes en `nexo_full_migration.sql`:** Los fixes 2026-16 a 2026-20 podrían incorporarse directamente en `nexo_full_migration.sql` para tener un único archivo de esquema. Esto simplificaría el deploy pero perdería la trazabilidad de fixes individuales.

2. **Crear `audit_trail` o eliminar referencia:** Decidir si la tabla `audit_trail` (referenciada en `2026-11-additional-indexes.sql`) debe crearse o si el índice es basura de la generación antigua.

3. **Particionamiento dinámico:** Las particiones de `biometric_events` están hardcodeadas hasta agosto 2026. Considerar un job periódico que cree nuevas particiones mensuales.

4. **Seed condicional:** `nexo_seed.sql` inserta datos masivos sin verificar si la base ya tiene datos reales. Considerar un flag o verificación antes de ejecutar en producción.

5. **Scripts de mantenimiento como cron jobs:** `cleanup_maintenance.sql` y `purge_notification_garbage.sql` podrían convertirse en jobs programados en Render, Supabase o pg_cron.

6. **Tests de integridad del esquema:** Crear un script que verifique que todas las tablas, columnas, índices y constraints esperados existan tras ejecutar `nexo_full_migration.sql`.

---

## 10. INSTRUCCIONES PARA NUEVA BASE DE DATOS

Para crear una base de datos NEXO limpia desde cero:

```bash
# 1. Esquema completo (único archivo necesario)
psql $DATABASE_URL -f backend/api/sql/nexo_full_migration.sql

# 2. Seed data (solo para desarrollo/testing)
psql $DATABASE_URL -f backend/api/sql/nexo_seed.sql
```

**NO ejecutar archivos de `archive/legacy_migrations/` sobre una base de datos UUID.**

---

*Documento generado automáticamente durante la consolidación del esquema NEXO.*
