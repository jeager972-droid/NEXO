# MIGRACIONES — esquema NEXO

## Estrategia

- `sql/schema.sql` = **schema final completo** — aplica de cero (verificado en el
  entorno `pruebas/`: initdb ejecuta el archivo entero sobre PostgreSQL 15 limpio).
- `sql/migrations/NNN_*.sql` = **migraciones incrementales** para bases existentes.
  Cada cambio de schema que se hace en `schema.sql` lleva su migración espejo.
- `sql/factory_reset.sql` = limpieza/recreación (existente).
- `deploy_db.sh` aplica `schema.sql` (idempotente) — las migraciones son para
  instalaciones ya desplegadas que no puedan recrearse.

## Inventario de discrepancias encontradas (schema vs código) — Bloque A/B

| # | Discrepancia | Estado |
|---|---|---|
| 1 | `student_fingerprints.device_id` FK a `edge_devices` creada ANTES que `edge_devices` → schema no aplicaba en BD limpia | **Corregido** (tabla movida tras `edge_devices` en schema.sql; migración 001 añade la FK si falta) |
| 2 | `DELETE/UPDATE` sobre `risk_event_level_mapping`/`risk_event_types` antes de su `CREATE TABLE` → error en initdb | **Corregido** (guardados con `to_regclass`) |
| 3 | `consultations.php` → `reports` usaba `format`/`status` inexistentes; real: `file_format` + `metadata_json->>'status'` | **Corregido** |
| 4 | Flags `onboarding_completed`, `groups_onboarding_completed`, `risk_config_completed` existían en `schools` pero **ningún endpoint los enforceaba** | **Corregido** (gate `requireSchoolOnboarding`) |
| 5 | No existía tabla para criterios de aviso docente (doc §4.5: "cantidad de llegadas tardías/inasistencias/salidas en período definido") | **Creado** `teacher_alert_rules` + `users.onboarding_completed` |
| 6 | `notifications.dedup_key` = VARCHAR(64) — keys de workers deben ser ≤64B (uso sha256) | Respetado en código |

## Migración 001 — Bloque B (`sql/migrations/001_bloque_b_onboarding_teacher_alerts.sql`)

| Cambio | Tipo | Rollback |
|---|---|---|
| `CREATE TABLE teacher_alert_rules` | aditiva | `DROP TABLE teacher_alert_rules` |
| `users.onboarding_completed` | aditiva | `ALTER TABLE users DROP COLUMN onboarding_completed` |
| FK `student_fingerprints.device_id` si ausente | aditiva | `ALTER TABLE … DROP CONSTRAINT` |

Todo idempotente (`IF NOT EXISTS` / `to_regclass`). Sin cambios destructivos ni
de tipos — rollback seguro en cualquier punto.

## Orden de aplicación en BD existente

```bash
psql "$DATABASE_URL" -f sql/migrations/001_bloque_b_onboarding_teacher_alerts.sql
# luego redesplegar api (entrypoint arranca worker_teacher_alerts)
```

## Notas de compatibilidad

- El gate de onboarding responde **HTTP 428** con `{status:'onboarding_required',
  missing:[schedule|groups|risk_config]}` a RECTOR/COORDINATOR/TEACHER y mensaje
  genérico al resto. El PWA debe manejar 428 → redirigir al wizard (ver
  FRONTEND_PENDIENTE.md).
- `teacher_alert_rules` es leída por `worker_teacher_alerts` cada
  `TEACHER_ALERTS_INTERVAL`s; notificaciones dedup por sha256(regla|estudiante|día).

## Adiciones Bloque C/D (misma migración `001_bloque_b_onboarding_teacher_alerts.sql`)

| Cambio | Tipo | Motivo |
|---|---|---|
| `school_notification_routes` | tabla nueva | Enrutamiento configurable de respuestas/escalaciones de acudientes (default COORDINATOR+RECTOR) |
| `students.manual_pending_until` | columna | Doc §9.6 — suspender detectores mientras registro manual pendiente |
| `edge_devices.app_version` | columna | Versión actual del nodo (OTA) |
| `edge_devices.ota_key` | columna | Clave HMAC OTA por-dispositivo |
| `ota_updates` | tabla nueva | Versiones publicadas (school-scoped o global NULL) |
| `ota_deployments` | tabla nueva | Auditoría por nodo del despliegue OTA + RLS |

Idempotente — re-ejecutable (`IF NOT EXISTS`). Después: redesplegar api
(entrypoint arranca `worker_absence_followup`).

## Migración 002 — Cierre de verificaciones (`sql/migrations/002_cierre_verificaciones.sql`)

Idempotente, cubre el cierre v3+v4 sobre bases ya provisionadas:

| Cambio | Tipo | Motivo |
|---|---|---|
| `class_exit_authorizations.schedule_id` + `actual_return_time` | columnas | Validación de retorno por espacio (V-031/063/009) |
| `student_tracking.dependency`/`assigned_to_user_id`/`origin_type`/`origin_id` | columnas | Derivación SEGUIMIENTO (V-151/069) |
| `risk_rules.detect_only` | columna | Detección sin alerta (V-377) |
| `twilio_message_types` += `HORARIO` | dato | Notificación de cambio de jornada (V-030/045) |
| `operations.citacion` → SECRETARY | permiso | V-127 |
| RLS `teacher_alert_rules` + `school_notification_routes` | políticas | aislamiento multi-tenant |
| índice `idx_tracking_origin(origin_type, origin_id)` | índice | derivación + dedup idempotente |
| `students.consent_status/channel/recorded_at/recorded_by/document_ref` + CHECK | columnas | Habeas data/ARCO (V-342/344) |
| `chk_tracking_status` (enum workflow) | constraint | V-150 (NOT VALID: protege filas legadas) |
| `notifications.origin_type/origin_id` + `idx_notifications_origin` | columnas+índice | V-028 |
| `fn_notifications_origin` + `trg_notifications_origin` | función+trigger | poblar origen desde metadata_json (V-028) |
| `assign_permission_to_role` (re-definición) | función | la BD destino puede ser anterior a su creación |
| `operations.extender_bloque` → TEACHER | permiso | V-046/077 |

```bash
psql "$DATABASE_URL" -f sql/migrations/002_cierre_verificaciones.sql
# redesplegar api (entrypoint arranca todos los workers)
```
