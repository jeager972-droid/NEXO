# Base de datos NEXO — Documentación del esquema PostgreSQL

Documentación exhaustiva de la capa de datos de NEXO. La fuente canónica del
esquema es `sql/schema.sql` (autocontenido e idempotente); este documento lo
explica, lo resume y sirve como referencia operativa. No duplica DDL: para el
detalle exacto de columnas y constraints consultar el archivo fuente.

## Contenido

1. [Visión general](#1-visión-general)
2. [Archivos de este directorio](#2-archivos-de-este-directorio)
3. [Modelo de datos por dominio](#3-modelo-de-datos-por-dominio)
4. [Row Level Security (RLS)](#4-row-level-security-rls)
5. [Particiones y retención](#5-particiones-y-retención)
6. [Triggers](#6-triggers)
7. [Funciones](#7-funciones)
8. [Vistas](#8-vistas)
9. [Despliegue: deploy_db.sh](#9-despliegue-deploy_dbsh)
10. [Seeds](#10-seeds)
11. [Factory reset](#11-factory-reset)
12. [Convenciones del esquema](#12-convenciones-del-esquema)
13. [Tabla-resumen de todas las tablas](#13-tabla-resumen-de-todas-las-tablas)

---

## 1. Visión general

| Aspecto | Detalle |
|---|---|
| Motor | PostgreSQL 15 o superior |
| Extensiones | `uuid-ossp` (PKs UUID), `pgcrypto` (HMAC-SHA256 de la cadena de auditoría) |
| Acceso | El backend PHP (`backend/api`) y los workers se conectan a través de **PgBouncer** (puerto 6432) |
| Pooling | `pool_mode = transaction` en `backend/api/infra/pgbouncer/pgbouncer.ini` — cada transacción puede ir a una conexión física distinta |
| Multi-tenancy | **Row Level Security** por `school_id`: 53 tablas con `ENABLE ROW LEVEL SECURITY` |
| Tablas lógicas | **68** tablas base + 8 particiones `DEFAULT` + particiones mensuales dinámicas (`<tabla>_YYYY_MM`); con las particiones activas, el total de relaciones supera el centenar |
| Llaves foráneas | ~90 `REFERENCES` (incluye constraints añadidos por bloques `DO $$ ... ALTER TABLE`) |
| Funciones | 23 `CREATE OR REPLACE FUNCTION` (helpers RLS, migraciones, auditoría, motor de riesgo v3.0, onboarding, particiones) |
| Triggers | 5 triggers activos |
| Vistas | Ninguna (ver §8) |
| Zona horaria de negocio | `America/Bogota` — todas las reglas de "día lectivo / día de hoy" convierten con `AT TIME ZONE 'America/Bogota'` |

### Topología de acceso

```
Clientes ──► API PHP (nginx + php-fpm) ──► PgBouncer :6432 ──► PostgreSQL 15
Workers PHP ──────────────────────────────┘  (pool_mode = transaction)
Nodos edge (sincronización M2M vía API) ──────┘
```

Consecuencia práctica del pooling por transacción: los ajustes de contexto RLS
(`app.current_school_id`, `app.current_role`) se fijan con
`set_config(..., true)` **dentro de cada transacción**, no con `SET` de sesión,
porque la sesión física se comparte entre clientes. `server_reset_query =
DISCARD ALL` limpia el estado al devolver la conexión al pool.

### Multi-tenancy

`schools` es la raíz del tenant. Casi todas las tablas operacionales tienen
`school_id` (con FK directa a `schools` o multi-tenancy derivada por JOIN, p.ej.
`guardians` hereda el tenant vía `users`). Los catálogos globales
(`departments`, `municipalities`, `roles`, `permissions`, `subjects`,
`risk_event_types`, `twilio_message_types`, `schema_migrations`) no llevan
`school_id`. Detalle de políticas en §4.

---

## 2. Archivos de este directorio

| Archivo | Rol |
|---|---|
| `schema.sql` | Esquema canónico completo y autocontenido: extensiones, `schema_migrations`, 68 tablas, índices, funciones, triggers, RLS, particiones DEFAULT, funciones de particionado, seed mínimo. Idempotente. |
| `deploy_db.sh` | Wrapper que ejecuta `schema.sql` contra `$DATABASE_URL` con `ON_ERROR_STOP=1`. |
| `seed.sql` | Seed masivo de demostración: puebla todas las escuelas activas con un colegio colombiano realista (~500 estudiantes/escuela, 30 días lectivos de eventos). Requiere `factory_reset.sql` previo. |
| `factory_reset.sql` | Borra toda la data operativa preservando institución, roles/permisos y usuarios no-GUARDIAN. |

Fuentes relacionadas fuera de este directorio:

| Archivo | Rol |
|---|---|
| `pruebas/seed.sql` | Seed del stack de integración Docker (`pruebas/docker-compose.test.yml`): IDs fijos deterministas, escuela `IE Test NEXO` + `IE Sin Onboarding`. |
| `pruebas/seed_chat_fixture.sql` | Fixture aditivo para pruebas conversacionales live (grupos 10-A/10-B, estudiantes e incidentes concretos). |
| `backend/api/infra/scripts/create_monthly_partition.sh` | Cron mensual que crea la partición de `biometric_events` del mes siguiente. |
| `backend/api/infra/scripts/recalc_risk.sh` | Cron diario (02:00 UTC) que recalcula métricas de riesgo por escuela. |
| `backend/api/infra/scripts/crontab` | Crontab del contenedor API (particiones, recálculo de riesgo, purga de notificaciones, keep-alive). |

---

## 3. Modelo de datos por dominio

Las 68 tablas se agrupan en 15 dominios funcionales. Salvo indicación, todas
las tablas operacionales son multi-tenant (`school_id`).

### 3.1 Control de plataforma y geografía

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `schema_migrations` | Historial de migraciones aplicadas (trazabilidad; no hay archivos de migración que ejecutar). | PK `migration_id`; `filename` UNIQUE; `success`, `executed_at`, `execution_time_ms`, `checksum`. Sin RLS. |
| `departments` | Catálogo global de departamentos. | `department_name` UNIQUE. Sin RLS. |
| `municipalities` | Catálogo global de municipios. | FK `department_id → departments`. Sin RLS. |
| `schools` | Institución (raíz del multi-tenant). | `dane_code` UNIQUE; FK `municipality_id`; flags de onboarding (`onboarding_completed`, `groups_onboarding_completed`, `groups_onboarding_year`, `risk_config_completed`); `sensor_master_key_hash` (bcrypt de la llave maestra de sensores); `spatial_enforcement` (F-01c: verificación aula-del-dispositivo vs aula programada). Sin RLS. |

### 3.2 Identidad, roles y credenciales

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `roles` | Catálogo global de roles (10 en el seed: RECTOR, COORDINATOR, TEACHER, SECRETARY, SECURITY, AUXILIARY, COUNSELOR, GUARDIAN, SUPER_ADMIN, SYSTEM_WORKER). | `role_name` UNIQUE. Sin RLS. |
| `permissions` | Catálogo global de permisos (32 códigos `dominio.accion`). | `permission_code` UNIQUE. Sin RLS. |
| `role_permissions` | Asignación rol→permiso (N:M). | FK `role_id`, `permission_id`; UNIQUE `(role_id, permission_id)` añadido por `DO` block. Sin RLS. |
| `users` | Cuentas con credenciales. | FK `school_id`, `role_id`; UNIQUE `(school_id, document_number)` y `email`; `password_hash`/`password_salt` (bcrypt); soft-delete `deleted_at`; `onboarding_completed` (F-18); `work_shift`; flags `email_verified`, `phone_verified`. RLS por `school_id`. |
| `user_sessions` | Sesiones de refresh token JWT. | FK `user_id`; `refresh_token_hash`, `ip_address` INET, `expires_at`, `revoked`. RLS multi-tenant vía JOIN a `users` + lectura total para `SYSTEM_WORKER`. |
| `jwt_blocklist` | JTIs de JWT revocados. | PK `jti` TEXT; `expires_at` para purga. RLS con `USING(true)` (acceso global autenticado). |
| `verification_codes` | Códigos OTP de verificación. | FK `user_id`; `purpose`, `code`, `attempts`/`max_attempts`, `expires_at`. Sin RLS (acceso solo vía backend). |
| `staff_records` | Registro laboral del personal. | FK `school_id`, `user_id`; `hired_at`, `position_name`, `employee_code`. RLS por `school_id`. |

### 3.3 Estudiantes, acudientes y huellas

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `students` | Matrícula de estudiantes. | UNIQUE `(school_id, document_number)`; `grade_level` (sincronizado al asignar grupo), `work_shift`, `biometric_hash` (flag: NOT NULL = tiene ≥1 huella), `biometric_exempt` + `exemption_reason` (F-02: exención biométrica), `manual_pending_until` (suspende detectores mientras haya registro manual pendiente), consentimiento habeas data (`consent_status` CHECK `PENDIENTE/OTORGADO/REVOCADO/NO_APLICA`, canal, fecha, responsable, doc. ref); soft-delete `deleted_at`. RLS por `school_id`. |
| `guardians` | Perfil de acudiente (1:1 con `users`). | `user_id` UNIQUE FK; `whatsapp_phone` + `whatsapp_phone_normalized` (trigger `trg_guardians_normalize_phone`); `emergency_contact`. RLS vía JOIN a `users`. |
| `guardian_student_relationships` | Vínculo acudiente↔estudiante (N:M). | UNIQUE `(guardian_id, student_id)`; `relationship_type`, `primary_guardian`; **máx. 3 acudientes por estudiante** (trigger `trg_guardian_limit`). RLS vía JOIN a `students` + `SYSTEM_WORKER`. |
| `student_fingerprints` | Huellas dactilares por estudiante (F-03, máx. 2 dedos). | UNIQUE `(student_id, finger_slot)` CHECK `finger_slot IN (1,2)`; `edge_huella_id` = slot local del template en la SQLite del nodo (los templates nunca salen del edge); FK `device_id → edge_devices`, `enrolled_by → users`. Sin RLS explícita en el schema. |

### 3.4 Estructura académica: grupos, aulas, asignaciones

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `academic_groups` | Grupos por año electivo. | UNIQUE `(school_id, academic_year, group_name)`; `grade_level`, `work_shift`. RLS por `school_id`. |
| `student_group_assignments` | Asignación estudiante→grupo (N:M; un grupo activo por año). | UNIQUE `(student_id, group_id)`; `active`, `start_date`/`end_date`. RLS vía JOIN a `academic_groups`. |
| `teacher_group_access` | Acceso docente→grupo por año (independiente de `schedules`). | UNIQUE `(teacher_user_id, group_id, academic_year)`; `work_shift` desnormalizado. RLS por `school_id` + lectura total `SYSTEM_WORKER`. |
| `classrooms` | Aulas físicas. | `classroom_name`, `building`. RLS por `school_id`. |
| `subjects` | Catálogo global de asignaturas. | Sin `school_id`. RLS: solo política `SELECT USING(true)` (lectura global). |

### 3.5 Horarios, jornada y calendario

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `school_schedule_config` | Configuración de jornada por escuela (onboarding obligatorio). | PK compuesta `(school_id, work_shift)`; `rotates_classrooms` (TRUE = aulas rotativas), `entry_time`/`exit_time`, `recess_start_time`/`recess_end_time`, trazabilidad de onboarding (`onboarding_completed_by/at`). RLS por `school_id` (sin DELETE). |
| `school_time_blocks` | Bloques horarios por jornada (solo si `rotates_classrooms=TRUE`). | UNIQUE `(school_id, work_shift, block_number)` vía `DO` block; `start_time`/`end_time`. RLS por `school_id`. |
| `schedules` | Horario semanal: grupo×día×bloque → aula+docente+asignatura. | FK `group_id`, `classroom_id`, `teacher_user_id`, `subject_id`; `day_of_week` INT, `block_number`, `start_time`/`end_time` TIME. RLS multi-tenant vía JOIN a `academic_groups` + `SYSTEM_WORKER` en SELECT. |
| `daily_schedule_config` | Override diario por grupo (día sin clases, horas esperadas). | UNIQUE `(school_id, group_id, config_date)`; `has_classes`, `expected_entry_time`/`expected_exit_time`, `metadata_json`. RLS por `school_id`. Los detectores de ausencia la necesitan poblada. |
| `technical_modality_config` | Modalidad técnica por grado+jornada+año. | UNIQUE `(school_id, grade_level, work_shift, academic_year)`; `days_of_week` JSONB (ISO 1=Lunes…7=Domingo), `uses_blocks`, `entry_time`/`exit_time`. Sin RLS explícita. |
| `school_calendar` | Calendario lectivo institucional. | UNIQUE `(school_id, calendar_date)`; `is_lecture_day`, `reason`. Fuente de `fn_count_lecture_days` para el decaimiento del riesgo (fallback Lun–Vie). RLS por `school_id`. |

### 3.6 Dispositivos edge, biometría enrolada y OTA

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `edge_devices` | Nodos edge (sensores) de la escuela. | FK `school_id`, `classroom_id`, `group_id`, `assigned_user_id`; `token_hash` (bcrypt del token del nodo), `public_key`, `active`/`configured`, `status`, `last_ping`/`last_seen_timestamp`, `telemetry_json`+`telemetry_at` (F-06: clock_drift, disco, DLQ…), `app_version`, `ota_key` (Bloque D: clave OTA por dispositivo). RLS: SELECT por `school_id` **o rol `EDGE_NODE`** (lookup por `device_id` sin tenant). |
| `student_fingerprints` | Ver §3.3 — vive junto a `edge_devices` por la FK `device_id`. | — |
| `sensor_revocation_requests` | Revocación programada de sensores (countdown de 1 h). | FK `device_id`, `school_id`, `requested_by`; `executes_at`, `cancelled`/`completed` + quién/cuándo. RLS por `school_id`. |
| `device_commands` | Cola de comandos a nodos edge (fallback cuando Redis no está disponible). | PK `command_id` BIGSERIAL; FK `device_id`; `command` TEXT, `payload` JSONB, `issued_at` BIGINT (epoch), `delivered_at`; índice parcial de pendientes `WHERE delivered_at IS NULL`. RLS multi-tenant vía JOIN a `edge_devices`. |
| `ota_updates` | Versiones de firmware/app publicadas para los nodos (M2M). | UNIQUE `(school_id, version)`; `school_id NULL` = actualización global; `payload_url`, `payload_sha256` CHAR(64), `min_version` (anti-rollback), `active`. Manifiesto firmado HMAC con `ota_key` del dispositivo. RLS: escuela propia o global + `SYSTEM_WORKER`/`SUPER_ADMIN`. |
| `ota_deployments` | Auditoría de despliegue OTA por nodo. | UNIQUE `(update_id, device_id)`; FK a `ota_updates`, `edge_devices`, `schools` (todas `ON DELETE CASCADE`); máquina de estados `status` (`OFFERED`/`DOWNLOADING`/`STAGED`/`APPLYING`/`APPLIED`/`FAILED`/`ROLLED_BACK`). RLS con acceso ampliado para `EDGE_NODE`/`SYSTEM_WORKER`. |

### 3.7 Eventos biométricos e incidentes de asistencia

Ambas tablas son de **alto volumen y particionadas mensualmente** (§5).

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `biometric_events` | Eventos crudos del sensor (particionada por `event_timestamp`). | PK compuesta `(event_id, event_timestamp)`; **sin FKs declaradas** (tabla particionada: `student_id`, `device_id`, `classroom_id`, `schedule_id` son referencias lógicas); `event_type` (`INGRESO_PUNTUAL`, `INGRESO_MANANA`, `INGRESO_TARDE`, `INGRESO_AULA`, `INGRESO_MANUAL`, `SALIDA_BAÑO`, `INGRESO_BAÑO`, `SALIDA_AUTORIZADA`, `SALIDA_INSTITUCION`…), `event_result`, `confidence_score`, `sync_hash`, `event_signature`, `event_fingerprint` (dedup; índice único parcial `(event_fingerprint, event_timestamp)`), `metadata_json`. RLS por `school_id`. |
| `attendance_incidents` | Incidentes derivados de los eventos (particionada por `detected_at`). | PK compuesta `(incident_id, detected_at)`; `incident_type` (`LATE_ARRIVAL`, `INASISTENCIA`, `UNAUTHORIZED_ABSENCE`, `EVASION_INTERNA`, `SALIDA_BAÑO`, `PERMISSION_EXPIRED`, `RISK_ALERT_*`, `RISK_DETECTED_*`), `related_event_id`, `group_id` (añadido por `ALTER … IF NOT EXISTS`), `resolved`, `metadata_json` (incluye flag `pending_context` F-05: excluye del score de riesgo). Índice funcional `idx_late_arrival_per_day` sobre `fn_bogota_date(detected_at)` para dedup por día Bogotá. Trigger `trg_evaluate_risk_v3` dispara el motor de riesgo. RLS por `school_id`. |

### 3.8 Permisos y salidas

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `school_exit_authorizations` | Autorización de salida de la institución. | `authorized_by_user_id`, `authorization_reason`, `exit_time`, `expected_return_time`, `actual_return_time`, `status` VARCHAR(100). RLS por `school_id`. |
| `class_exit_authorizations` | Permiso de salida del aula (baño, enfermería…). | `status` CHECK informal: `ACTIVE`/`COMPLETED`/`EXPIRED`/`CANCELLED`; `schedule_id`; `exit_time`/`return_time`/`actual_return_time`. `worker_permission_status` las cierra/expira según `biometric_events`. Funciones `has_active_permiso`, `get_active_permiso_info`. RLS por `school_id`. |
| `pedagogical_trip_authorizations` | Salida pedagógica grupal. | `destination`, `departure_time`, `return_time`, `purpose`. RLS por `school_id`. |

### 3.9 Seguridad física y emergencias

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `sos_alerts` | Alertas SOS de botón de pánico (particionada por `emitted_at`). | PK compuesta `(alert_id, emitted_at)`; `emitted_by_user_id`, `classroom_id`, `alert_type`, `resolved`/`resolved_by_user_id`/`resolved_at`. RLS por `school_id`. |
| `security_incidents` | Incidentes disciplinarios/de seguridad. | `related_student_id`, `related_user_id`, `incident_type`, `severity_level`, `resolved`/`resolved_at`. RLS por `school_id`. |
| `school_panic_events` | Modo pánico institucional (desactiva dispositivos en cascada). | `triggered_by_user_id`, `triggered_at`, `devices_deactivated`. RLS por `school_id`. |

### 3.10 Notificaciones y mensajería

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `notifications` | Notificaciones in-app a usuarios. | `type` (`INFO`/`ALERT`…), `dedup_key` UNIQUE parcial (deduplicación), vínculo polimórfico `origin_type`/`origin_id` (sin FK: orígenes en tablas heterogéneas; el trigger `trg_notifications_origin` los rellena desde `metadata_json`), `read_at`. RLS por `school_id` (policies SELECT/INSERT/DELETE; no hay UPDATE). |
| `internal_messages` | Mensajería interna usuario↔usuario (particionada por `sent_at`). | PK compuesta `(message_id, sent_at)`; `sender_user_id`, `receiver_user_id`, `read_at`. RLS por `school_id`. |
| `twilio_message_types` | Catálogo de tipos de mensaje WhatsApp (13 tipos: `INASISTENCIA`, `CITACION`, `AUTORIZAR_SALIDA`, `CRITICAL_SITUATION`, `INCIDENTE`, `NOTIFY_ROLE`, `OUTBOUND`, `PEDAGOGICA`, `SOLICITUD`, `SOS_ALERT`, `HORARIO`, `SEGUIMIENTO`, `ABSENCE_FOLLOWUP`). | PK `type_code` VARCHAR (no UUID). Documentación/clasificación; sin FK desde `twilio_messages`. Sin RLS. |
| `twilio_messages` | Mensajes WhatsApp vía Twilio, in/out (particionada por `sent_at`). | PK compuesta `(twilio_message_id, sent_at)`; `type_code` (sin FK formal), `direction` (`OUTBOUND`/`INBOUND`), `phone_number`, `provider_message_sid`, `delivery_status`, `received_at`. RLS por `school_id`. |

### 3.11 Auditoría, comandos y reportes

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `global_audit_logs` | Auditoría global con **cadena HMAC-SHA256** por escuela (particionada por `created_at`). | PK compuesta `(log_id, created_at)`; `prev_audit_id` + `chain_hash` calculados por `trg_audit_chain` (requiere `app.nexo_hmac_secret` configurado — aborta si es el default); `action_type`, `entity_type`/`entity_id`, `action_details` JSONB, `ip_address` INET, `user_agent`. RLS: solo SELECT+INSERT (cadena inmutable, sin UPDATE/DELETE). Validación: `fn_validate_audit_chain`. |
| `student_record_audit` | Auditoría de expediente del estudiante (particionada por `performed_at`). | PK compuesta `(audit_id, performed_at)`; `action_type`, `previous_data`/`new_data` JSONB. RLS: SELECT+INSERT. |
| `user_commands` | Auditoría de comandos emitidos por usuarios (particionada por `executed_at`). | PK compuesta `(command_id, executed_at)`; `command_type`, `target_entity_type`/`target_entity_id`, `command_payload` JSONB. RLS por `school_id`. |
| `report_exports` | Trazabilidad de reportes exportados. | `report_type`, `file_format`, `storage_path`, `generated_at`. RLS por `school_id` (sin UPDATE). |

### 3.12 Motor de Análisis de Riesgo Pedagógico v3.0

Motor por escuela con políticas versionadas, decaimiento exponencial por días
lectivos y factor de clustering. Niveles: `SIN_IMPORTANCIA` < `LEVE` <
`MODERADA` < `ALTA` < `MUY_ALTA` (rank en `fn_risk_level_rank`).

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `risk_event_types` | Catálogo **global** de tipos de evento de riesgo. | PK `event_type_id` SERIAL; `type_code` UNIQUE; `category` (`asistencia`/`evasion`/`comportamiento`/`general`); `is_system`. El seed crea `LATE_ARRIVAL`, `EVASION_INTERNA`, `SALIDA_BAÑO` y limpia tipos retirados (`INASISTENCIA*`, `UNAUTHORIZED_*`, `SALIDA_BANO` sin Ñ). Sin RLS. |
| `risk_policies` | Políticas de riesgo versionadas por escuela. | UNIQUE `(school_id, version)`; `is_active`, `snapshot_json` (congelado de la config), `created_by`, `change_reason`. RLS por `school_id`. |
| `risk_event_level_mapping` | Mapeo evento→nivel dentro de una política, con override individual por estudiante. | UNIQUE `(policy_id, event_type_id, student_id)`; `risk_level` CHECK 5 niveles; `student_id NULL` = regla general; `override_reason`. RLS por `school_id`. |
| `risk_rules` | Parámetros por nivel: peso, vida media, umbral, cooldown y rangos permitidos (min/max de recurrencia, ventana, peso, vida media, umbral). | UNIQUE `(policy_id, risk_level)`; `weight_base`, `half_life_days`, `activation_threshold`, `cooldown_days`, `single_occurrence`, `requires_human_review`, `detect_only` (V-377: detecta sin alertar), `recurrence_count`, `window_days` + límites `min_*`/`max_*`. RLS por `school_id`. |
| `risk_combination_rules` | Capa 4: combinación entre categorías — si TODAS las categorías listadas alcanzan `min_level`, el nivel resultante puede elevarse. | `condition_json` (`{"categories":[{"category":…,"min_level":…}]}`), `result_level` CHECK `MODERADA/ALTA/MUY_ALTA`, `result_reason`, `is_active`. RLS por `school_id`. |
| `risk_active_snapshot` | Snapshot derivado: score activo por estudiante×categoría. | UNIQUE `(school_id, student_id, category)`; `active_score`, `event_count`, `clustering_factor`, `last_calculated_at`, `policy_id`. RLS por `school_id`. |
| `risk_alerts` | Alertas pedagógicas con máquina de estados. | `alert_level` CHECK 4 niveles; `escalation_state` CHECK (`OBSERVACION`/`ALERTA_PEDAGOGICA`/`SEGUIMIENTO`/`INTERVENCION_PRIORITARIA`/`ATENCION_INMEDIATA`); `status` CHECK (`abierta`/`en_seguimiento`/`resuelta`/`descartada`); `cooldown_until` (anti-spam), `combo_rule_id`, `involved_events` JSONB, `resolved_by`/`resolved_at`/`resolution_notes`. Índice parcial de cooldown `WHERE status='abierta'`. RLS por `school_id`. |
| `risk_justifications` | Justificación de incidentes: un evento justificado no suma al riesgo activo. | `incident_type`+`incident_date` (empate por día), `justification_type` CHECK (`permiso`/`error_sensor`/`horario`/`medico`/`otro`), `recalculated`. Creadas vía `fn_justify_risk_event` (recalcula el riesgo). RLS por `school_id`. |
| `risk_audit_log` | Bitácora de cambios de configuración del motor. | `actor_id`/`actor_role`, `entity_modified`/`entity_id`, `action`, `previous_config`/`new_config` JSONB, `policy_version`. RLS: SELECT+INSERT. |
| `student_behavior_metrics` | Métricas agregadas legacy (ventana de N días; score 0-100 con pesos fijos late×5, ausencia×15, evasión×10). | UNIQUE `(student_id, calculation_window_days)`; `risk_level` CHECK (`LOW`/`MEDIUM`/`HIGH`/`CRITICAL`); alimentada por `fn_calculate_student_risk`/`fn_recalculate_school_metrics`. RLS por `school_id`. |

Flujo del motor: `attendance_incidents` (INSERT de `LATE_ARRIVAL`/`EVASION_INTERNA`/`SALIDA_BAÑO`) → trigger `trg_evaluate_risk_v3` → `fn_evaluate_student_risk` → upsert en `risk_active_snapshot` + (si aplica) `risk_alerts` con cooldown; una alerta que nace en `SEGUIMIENTO` instancia automáticamente un caso en `student_tracking` si no hay uno abierto (V-069/V-151). Con `detect_only` se inserta `RISK_DETECTED_*` en `attendance_incidents` sin alerta.

### 3.13 Seguimiento y criterios de aviso docente

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `student_tracking` | Casos de seguimiento (orientación/coordinación). | `status` CHECK (`en proceso`/`resuelto`/`descartado`/`escalado`); `dependency`, `assigned_to_user_id`, origen polimórfico `origin_type`/`origin_id` (`manual`/`risk_alert`…). Defaults de fecha en `America/Bogota`. RLS por `school_id`. |
| `student_tracking_notes` | Notas del caso. | FK `tracking_id` (CASCADE), `user_id`; `note_text`. RLS vía JOIN a `student_tracking`. |
| `teacher_alert_rules` | F-18: criterios de aviso configurables por docente (N eventos en M días → notificación). | `event_kind` CHECK (`LATE`/`ABSENCE`/`EVASION`/`EXIT`/`PERMISSION_EXPIRY`); `threshold_count` 1-60, `window_days` 1-90; `group_id`/`student_id` NULL = todos los del alcance. `worker_teacher_alerts` las evalúa (dedup diario). RLS por `school_id` + lectura total `SYSTEM_WORKER`. |

### 3.14 Configuración por escuela y chat «Pregúntale a Nexus»

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `school_notification_routes` | Enrutamiento configurable de notificaciones/escalaciones por `event_kind`→`target_role`. | UNIQUE `(school_id, event_kind, target_role)`; `enabled`. Sin filas = default `COORDINATOR`+`RECTOR`. RLS por `school_id` + `SYSTEM_WORKER`. |
| `school_action_policies` | Políticas de acción por `event_type` (V-013/041/058/406): acción o `NONE`; sin fila = comportamiento por defecto habilitado. | UNIQUE `(school_id, event_type)`; `action`, `enabled`, `params` JSONB. Sin RLS explícita en el schema. |
| `chat_messages` | Historial del asistente NLU por usuario. | `role` CHECK (`user`/`assistant`); `session_id` agrupa conversaciones (NULL = legacy); `payload_json` `{intent, confidence, cards, actions}`. Retención sugerida 90 días. RLS: SELECT por `school_id`+`SYSTEM_WORKER`/`SUPER_ADMIN`; INSERT también por `EDGE_NODE`. |
| `school_chat_policies` | Interruptores del asistente por escuela (qué puede pedir cada rol: riesgo, campos de estudiante, agregados, acciones, smalltalk). | UNIQUE `(school_id, policy_key)`; `enabled`, `updated_by`. Sin fila = matriz segura por defecto en backend. RLS por `school_id` + escritura `SUPER_ADMIN`. |

### 3.15 Tablas de sistema

| Tabla | Propósito | Claves / notas |
|---|---|---|
| `system_telemetry` | Telemetría del cliente (web/desktop/android/ios). | PK `id` BIGSERIAL; `event_type` CHECK (`JS_ERROR`/`API_LATENCY`/`BIOMETRIC_LATENCY`/`APP_PING`/`RENDER_SLOW`); `severity` CHECK (`debug`/`info`/`warn`/`error`); `payload` JSONB con índice **GIN**; índice parcial `WHERE severity IN ('warn','error')`. RLS: SELECT solo `SYSTEM_WORKER`/`SUPER_ADMIN`; INSERT con cualquier rol autenticado. |
| `rate_limits` | Rate limiting por clave. | PK `rl_key` TEXT; `window_start`, `hits`. Sin RLS. |
| `contact_leads` | Contactos del landing público. | `nombre`, `cargo`, `institucion`, `municipio`, `email`, `whatsapp`, `ip_address`. Sin RLS (solo backend). |

---

## 4. Row Level Security (RLS)

### Mecanismo

1. `get_current_school_id()` → UUID: lee el setting `app.current_school_id` (NULL si no está fijado).
2. `get_current_role()` → TEXT: lee `app.current_role`.
3. Cada policy compara `school_id = get_current_school_id()` en `USING`/`WITH CHECK`, o valida el tenant por JOIN cuando la tabla no tiene `school_id` propio.

El backend fija ambos settings en `requireAuth()`
(`backend/api/routes/_auth_middleware.php`) con
`set_config('app.current_school_id', …, true)` **a nivel de transacción** —
obligatorio porque PgBouncer opera en `transaction` pooling. Los nodos edge se
autentican con rol `EDGE_NODE` tras validar su token; los workers con
`SYSTEM_WORKER`. Si el setting no está fijado, `get_current_school_id()`
devuelve NULL y las políticas por `school_id` no devuelven filas (fail-closed).

El schema crea las policies con `DROP POLICY IF EXISTS` + `CREATE POLICY`, lo
que las hace reejecutables (idempotencia).

### Patrón estándar: `school_id = get_current_school_id()`

SELECT/INSERT/UPDATE/DELETE completos (salvo indicación):

`students`, `users`, `biometric_events`, `attendance_incidents`, `sos_alerts`,
`twilio_messages`, `school_panic_events`, `staff_records`, `academic_groups`,
`classrooms`, `security_incidents`, `school_exit_authorizations`,
`class_exit_authorizations`, `pedagogical_trip_authorizations`,
`internal_messages`, `school_time_blocks`, `daily_schedule_config`,
`sensor_revocation_requests`, `student_tracking`, `user_commands`,
`student_behavior_metrics`, `school_calendar`, `risk_policies`,
`risk_event_level_mapping`, `risk_rules`, `risk_combination_rules`,
`risk_active_snapshot`, `risk_alerts`, `risk_justifications`.

Variantes del patrón:

| Tabla | Particularidad |
|---|---|
| `global_audit_logs` | Solo SELECT + INSERT (la cadena HMAC es append-only). |
| `student_record_audit` | Solo SELECT + INSERT. |
| `risk_audit_log` | Solo SELECT + INSERT. |
| `report_exports` | SELECT + INSERT + DELETE (sin UPDATE). |
| `notifications` | SELECT + INSERT + DELETE (sin UPDATE). |
| `school_schedule_config` | SELECT + INSERT + UPDATE (sin DELETE). |

### Multi-tenancy por JOIN (la tabla no tiene `school_id` directo)

| Tabla | Vía |
|---|---|
| `guardians` | `users.school_id` (JOIN por `user_id`). |
| `guardian_student_relationships` | `students.school_id`; SELECT también admite `SYSTEM_WORKER`. |
| `student_group_assignments` | `academic_groups.school_id` (JOIN por `group_id`). |
| `schedules` | `academic_groups.school_id`; SELECT también admite `SYSTEM_WORKER`. |
| `student_tracking_notes` | `student_tracking.school_id` (JOIN por `tracking_id`). |
| `user_sessions` | `users.school_id`; `SYSTEM_WORKER` puede leer/actualizar/borrar todas (validación de refresh tokens). |
| `device_commands` | `edge_devices.school_id` (JOIN por `device_id`). |

### Políticas con roles especiales

| Tabla | Regla |
|---|---|
| `edge_devices` | SELECT si `school_id` propio **o** `get_current_role()='EDGE_NODE'` (el nodo hace lookup por `device_id` antes de conocer su tenant). |
| `teacher_group_access` | SELECT por `school_id` **o** `SYSTEM_WORKER` (workers de notificación leen todos). |
| `teacher_alert_rules` | SELECT por `school_id` **o** `SYSTEM_WORKER`/`SUPER_ADMIN`. |
| `school_notification_routes` | SELECT por `school_id` **o** `SYSTEM_WORKER`/`SUPER_ADMIN`. |
| `ota_updates` | SELECT si `school_id` propio, `NULL` (global), `SYSTEM_WORKER` o `SUPER_ADMIN`; INSERT/UPDATE admiten fila global o propia. |
| `ota_deployments` | SELECT/UPDATE por `school_id` o `SYSTEM_WORKER`/`EDGE_NODE`/`SUPER_ADMIN`; INSERT propio o `SYSTEM_WORKER`/`EDGE_NODE`. |
| `chat_messages` | SELECT por `school_id` o `SYSTEM_WORKER`/`SUPER_ADMIN`; INSERT propio o `SYSTEM_WORKER`/`EDGE_NODE`. |
| `school_chat_policies` | SELECT por `school_id` o `SYSTEM_WORKER`/`SUPER_ADMIN`; escritura propia o `SUPER_ADMIN`. |
| `jwt_blocklist` | `USING(true)` en SELECT e INSERT — tabla de sistema, acceso global. |
| `subjects` | Solo `SELECT USING(true)` — catálogo global de lectura. |
| `system_telemetry` | SELECT solo `SYSTEM_WORKER`/`SUPER_ADMIN`; INSERT para cualquier rol autenticado (`app.current_role` no vacío). |

### Tablas sin RLS

15 tablas no habilitan RLS: catálogos globales (`departments`,
`municipalities`, `roles`, `permissions`, `role_permissions`, `subjects` tiene
RLS pero abierta, `risk_event_types`, `twilio_message_types`,
`schema_migrations`), la raíz del tenant (`schools`), infraestructura de
acceso (`verification_codes`, `rate_limits`, `contact_leads`) y configuración
(`technical_modality_config`, `school_action_policies`,
`student_fingerprints`). En estos casos el aislamiento lo impone la capa de
aplicación.

> Nota: `student_fingerprints` y `school_action_policies` contienen `school_id`
> pero no tienen policies definidas en `schema.sql` — acceso únicamente vía
> backend/funciones `SECURITY DEFINER`.

---

## 5. Particiones y retención

8 tablas de alto volumen están particionadas por rango mensual
(`PARTITION BY RANGE`):

| Tabla padre | Columna de partición | PK |
|---|---|---|
| `biometric_events` | `event_timestamp` | `(event_id, event_timestamp)` |
| `attendance_incidents` | `detected_at` | `(incident_id, detected_at)` |
| `internal_messages` | `sent_at` | `(message_id, sent_at)` |
| `twilio_messages` | `sent_at` | `(twilio_message_id, sent_at)` |
| `user_commands` | `executed_at` | `(command_id, executed_at)` |
| `sos_alerts` | `emitted_at` | `(alert_id, emitted_at)` |
| `student_record_audit` | `performed_at` | `(audit_id, performed_at)` |
| `global_audit_logs` | `created_at` | `(log_id, created_at)` |

Particularidades:

- Cada padre tiene una partición **`DEFAULT`** (`<tabla>_default`) como
  catch-all para datos fuera de rango.
- Las particiones mensuales se nombran `<tabla>_YYYY_MM`.
- La PK incluye siempre la columna de partición (requisito de PostgreSQL).
- Por la misma razón no hay `UNIQUE` por día en tablas particionadas: el dedup
  de `LATE_ARRIVAL` usa el índice funcional `idx_late_arrival_per_day`
  (`fn_bogota_date(detected_at)`) + lógica de aplicación
  (`ON CONFLICT DO NOTHING` / `SELECT FOR UPDATE` en `worker_biometric.php`).
- `ALTER TABLE` sobre el padre se propaga a todas las particiones
  (así se añadió `attendance_incidents.group_id`).
- `TRUNCATE`/operaciones sobre el padre alcanzan todas las particiones
  (lo aprovecha `factory_reset.sql`).

### Creación de particiones

- `fn_ensure_partitions(p_months_ahead INTEGER DEFAULT 3)` crea las
  particiones del mes actual + N futuros para las 8 tablas; idempotente
  (omite las existentes consultando `pg_tables`). El schema ejecuta
  `SELECT fn_ensure_partitions(6)` al instalarse.
- En producción, `backend/api/infra/scripts/create_monthly_partition.sh`
  corre el día 1 de cada mes a las 03:00 UTC (crontab del contenedor) para
  crear la de `biometric_events` del mes siguiente.

### Retención

`fn_drop_old_partitions(p_retention_months INTEGER DEFAULT 24)` hace
`DETACH PARTITION` + `DROP TABLE` de las particiones `_*_YYYY_MM` más antiguas
que el corte (inicio de mes actual − N meses). Nunca toca la `DEFAULT`.
Configurable vía `NEXO_PARTITION_RETENTION_MONTHS`.

---

## 6. Triggers

| Trigger | Tabla | Momento | Función | Efecto |
|---|---|---|---|---|
| `trg_guardians_normalize_phone` | `guardians` | BEFORE INSERT OR UPDATE OF `whatsapp_phone` | `fn_guardians_normalize_phone` | Normaliza `whatsapp_phone_normalized` (solo dígitos y `+`) para matching WhatsApp. |
| `trg_audit_chain` | `global_audit_logs` | BEFORE INSERT | `fn_audit_chain_trigger` | Lee el último `log_id`/`chain_hash` de la escuela (`FOR UPDATE`), fija `prev_audit_id` y calcula `chain_hash` HMAC-SHA256. |
| `trg_guardian_limit` | `guardian_student_relationships` | BEFORE INSERT OR UPDATE | `fn_check_guardian_limit` | Aborta si el estudiante ya tiene 3 acudientes. |
| `trg_notifications_origin` | `notifications` | BEFORE INSERT | `fn_notifications_origin` | Rellena `origin_type`/`origin_id` desde `metadata_json` (`security_incident_id`, `incident_id`, `alert_id`, `tracking_id`, `authorization_id`) cuando el llamador no los pasó (V-028). |
| `trg_evaluate_risk_v3` | `attendance_incidents` | AFTER INSERT | `fn_trigger_evaluate_risk_v3` | Llama `fn_evaluate_student_risk` para incidentes `LATE_ARRIVAL`/`EVASION_INTERNA`/`SALIDA_BAÑO` (excluye `RISK_ALERT%` para evitar recursión). `seed.sql` lo deshabilita durante la carga masiva. |

---

## 7. Funciones

Todas las funciones de negocio son `SECURITY DEFINER` con
`search_path` fijado (`public, pg_catalog` o `public, pg_temp`) para evitar
secuestro de `search_path`.

### 7.1 Helpers de contexto y utilidades

| Función | Firma → retorno | Descripción |
|---|---|---|
| `fn_bogota_date` | `(t TIMESTAMPTZ) → DATE` | `IMMUTABLE`: convierte a fecha en `America/Bogota`. Requerida por índices funcionales (`idx_late_arrival_per_day`). |
| `get_current_school_id` | `() → UUID` | Lee `app.current_school_id`; NULL/vacío/error → NULL. Base de las policies RLS. |
| `get_current_role` | `() → TEXT` | Lee `app.current_role`; NULL/vacío/error → NULL. |
| `migration_was_executed` | `(p_filename VARCHAR) → BOOLEAN` | TRUE si `schema_migrations` registra el archivo con `success=TRUE`. |
| `register_migration` | `(filename, version_label, description, checksum, executed_by, execution_time_ms, notes) → UUID` | Inserta/actualiza el registro de migración (`ON CONFLICT (filename)`). |
| `assign_permission_to_role` | `(role_name, permission_code) → VOID` | Helper temporal del seed; se **borra** al final del seed (`DROP FUNCTION`). |

### 7.2 Auditoría (cadena HMAC)

| Función | Firma → retorno | Descripción |
|---|---|---|
| `fn_calculate_audit_hash` | `(prev_hash, school_id, actor_id, event_type, description, ip_address, created_at) → TEXT` | HMAC-SHA256 del payload `prev_hash|school|actor|type|desc|ip|ts` con el secreto `app.nexo_hmac_secret`. **Aborta** si el secreto falta o es `default-secret-change-me` (VF-003). |
| `fn_audit_chain_trigger` | `() → TRIGGER` | Encadena `prev_audit_id`/`chain_hash` (ver §6). |
| `fn_validate_audit_chain` | `(p_school_id UUID DEFAULT NULL) → JSONB` | Recorre la cadena recalculando hashes; devuelve `{status:'ok',…}` o `{status:'compromised', broken_at_audit_id, valid_up_to}`. |

### 7.3 Onboarding / evasión / presencia

| Función | Firma → retorno | Descripción |
|---|---|---|
| `is_student_present_today` | `(school_id, student_id) → BOOLEAN` | TRUE si hay `biometric_events` `INGRESO_%` del estudiante hoy (fecha Bogotá). |
| `has_active_permiso` | `(school_id, student_id) → BOOLEAN` | TRUE si hay `class_exit_authorizations` `ACTIVE` cuyo rango cubre `NOW()`. |
| `get_active_permiso_info` | `(school_id, student_id) → TABLE` | Devuelve el permiso activo más reciente (`authorization_id`, `exit_time`, `return_time`, `authorization_reason`). |

### 7.4 Motor de Riesgo Pedagógico v3.0

| Función | Firma → retorno | Descripción |
|---|---|---|
| `fn_count_lecture_days` | `(school_id, from_date, to_date) → INTEGER` | Días lectivos desde `school_calendar` (`is_lecture_day`); si no hay filas, fallback Lun–Vie (`ISODOW 1-5`). `IMMUTABLE`. |
| `fn_seed_default_risk_policy` | `(school_id, created_by) → UUID` | Crea la política v1 de la escuela: 4 `risk_rules` (LEVE w=1.0/hl=5/thr=10/cd=5; MODERADA w=3/hl=5/thr=5/cd=7; ALTA w=6/hl=5/thr=3/cd=3 + `requires_human_review`; MUY_ALTA w=10/hl=9999/thr=10, `single_occurrence`) + mapeo default (`LATE_ARRIVAL`→LEVE, `EVASION_INTERNA`→MUY_ALTA, `SALIDA_BAÑO`→LEVE) y `snapshot_json`. |
| `fn_calculate_category_risk` | `(student_id, school_id, category, policy_id, lookback_days=90) → TABLE` | Score activo por categoría: `Σ weight_base · e^(−λ·días_lectivos)` con `λ=ln2/half_life`; excluye incidentes `RISK_ALERT%`, `pending_context` y justificados; factor de clustering (hasta 2× si los intervalos entre eventos son regulares). Devuelve `triggered_level`. |
| `fn_risk_level_rank` | `(p_level TEXT) → INT` | Ontología ordenada: `MUY_ALTA`=4, `ALTA`=3, `MODERADA`=2, `LEVE`=1, otro=0. |
| `fn_evaluate_student_risk` | `(student_id, school_id) → JSONB` | Evalúa `asistencia`/`evasion`/`comportamiento` con la política activa, upsert en `risk_active_snapshot`, aplica reglas de combinación, respeta `cooldown_until` y `detect_only`, inserta `risk_alerts` + incidente `RISK_ALERT_<nivel>` y auto-crea `student_tracking` cuando nace en `SEGUIMIENTO`. Devuelve JSONB con `max_level`, `max_score`, `alert_id`, etc. |
| `fn_trigger_evaluate_risk_v3` | `() → TRIGGER` | Dispara `fn_evaluate_student_risk` por incidente elegible. |
| `fn_recalculate_school_risk_v3` | `(school_id) → INTEGER` | Recalcula todos los estudiantes activos (errores por estudiante → NOTICE, no aborta). |
| `fn_justify_risk_event` | `(school_id, student_id, incident_type, incident_date, justified_by, justification_type, reason) → UUID` | Inserta `risk_justifications`, recalcula, marca `recalculated=TRUE`. |

### 7.5 Métricas legacy (score 0-100)

| Función | Firma → retorno | Descripción |
|---|---|---|
| `fn_calculate_student_risk` | `(student_id, school_id, window_days=30) → JSONB` | Pesos fijos: late×5 + ausencia×15 + evasión×10 + sobrecarga de eventos; niveles `LOW/MEDIUM/HIGH/CRITICAL`; upsert en `student_behavior_metrics`; si score≥70 inserta `RISK_ALERT_<level>` (dedup 7 días). |
| `fn_recalculate_school_metrics` | `(school_id) → INTEGER` | Recálculo masivo de `student_behavior_metrics` (ventana 30 días). |

### 7.6 Particiones

| Función | Firma → retorno | Descripción |
|---|---|---|
| `fn_ensure_partitions` | `(p_months_ahead INTEGER DEFAULT 3) → INTEGER` | Crea particiones `<tabla>_YYYY_MM` del mes actual + N futuros para las 8 tablas particionadas. Devuelve cuántas creó. |
| `fn_drop_old_partitions` | `(p_retention_months INTEGER DEFAULT 24) → INTEGER` | DETACH+DROP de particiones mensuales más viejas que el corte; ignora `DEFAULT`. |

### 7.7 Triggers de utilidad (funciones)

`fn_guardians_normalize_phone`, `fn_check_guardian_limit`,
`fn_notifications_origin` — descritas en §6.

---

## 8. Vistas

**El esquema no define vistas** (`CREATE VIEW`/`MATERIALIZED VIEW`). Las
lecturas derivadas se resuelven con:

- `risk_active_snapshot` — tabla-snapshot materializada por `fn_evaluate_student_risk`.
- Funciones `RETURNS TABLE`/`JSONB` (`get_active_permiso_info`, `fn_calculate_category_risk`, `fn_evaluate_student_risk`, `fn_validate_audit_chain`).
- Índices parciales (`idx_notifications_unread`, `idx_device_commands_pending`, `idx_risk_alerts_cooldown`, `idx_telemetry_severity`).

---

## 9. Despliegue: `deploy_db.sh`

```bash
DATABASE_URL="postgresql://usuario:password@host:5432/nexo" ./sql/deploy_db.sh
```

Comportamiento:

1. Falla si `DATABASE_URL` está vacía, si `psql` no está en PATH o si falta `sql/schema.sql` (`set -euo pipefail`).
2. Ejecuta `psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f sql/schema.sql` — aborta al primer error.
3. Imprime cabecera con target/schema/fecha UTC y resultado `[OK]`/`[FAIL]`.

### Idempotencia

`schema.sql` es reejecutable sobre una base existente sin error:

- `CREATE TABLE/INDEX/EXTENSION IF NOT EXISTS`.
- `DROP POLICY/TRIGGER/FUNCTION IF EXISTS` + recreación.
- `ALTER TABLE … ADD COLUMN IF NOT EXISTS` para columnas añadidas post-consolidación (`spatial_enforcement`, `biometric_exempt`, `exemption_reason`, `manual_pending_until`, `telemetry_json`/`telemetry_at`, `app_version`, `ota_key`, `group_id`, `users.onboarding_completed`).
- Constraints UNIQUE añadidos dentro de `DO $$ … IF NOT EXISTS (pg_constraint)`.
- Seeds con `ON CONFLICT DO NOTHING` / `DO UPDATE`.
- Limpiezas de catálogo protegidas con `to_regclass` (solo aplican a BD existentes).

El seed mínimo (bloque `SEED DATA` al final del archivo) crea:

- `departments`/`municipalities` "Bogotá D.C." y `schools` `dane_code='000000000'` ("Institución Educativa NEXO", onboardings en TRUE).
- Los 10 `roles` del sistema.
- Los 32 `permissions` y su asignación en `role_permissions` (RECTOR 28, COORDINATOR 26, TEACHER 17, SECRETARY 10, COUNSELOR 10, SECURITY 7, AUXILIARY 7; GUARDIAN/SUPER_ADMIN/SYSTEM_WORKER sin permisos).
- Usuario `admin@nexo.edu` con rol `RECTOR` (bcrypt de `admin123` — **rotar fuera de desarrollo**).
- Catálogo `twilio_message_types` (13 tipos) y `risk_event_types` (3 tipos sistema).
- Particiones iniciales: mes actual + 6 meses (`SELECT fn_ensure_partitions(6)`).

Requisitos: PostgreSQL 15+, cliente `psql`, rol con permisos para crear
extensiones, tablas, funciones, triggers y policies RLS.

---

## 10. Seeds

Hay tres niveles de datos semilla:

### 10.1 Seed mínimo (dentro de `schema.sql`)

Descrito en §9. Es todo lo necesario para arrancar: geografía Bogotá, escuela
de ejemplo, roles, catálogo de permisos, asignación rol→permiso, admin
`admin@nexo.edu`, catálogos de tipos de mensaje/evento y particiones
iniciales.

### 10.2 Seed masivo de demostración (`sql/seed.sql`)

```bash
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f sql/factory_reset.sql   # requerido
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f sql/seed.sql
```

Puebla **todas las escuelas activas** con un colegio colombiano realista:

- Guard clause: aborta si ya hay estudiantes (exige `factory_reset` previo) o
  si no hay escuelas activas. **No es reejecutable sobre la misma data.**
- Onboarding institucional: jornada mañana 07:00–13:00, descanso
  09:45–10:15, **aulas rotativas** (`rotates_classrooms=TRUE`) con 6 bloques.
- Estructura: 24 grupos (6°–11° × A/B/C/D), 20–24 estudiantes por grupo con
  perfil de comportamiento (`ok`/`late`/`absent`/`evader`/`severe`), 1
  acudiente por estudiante, docentes hasta 24/escuela, 24 aulas, 26 nodos
  edge (aula + entrada + coordinación), huellas slot 1 (~95%) y 2 (~45%),
  `staff_records`, accesos `teacher_group_access` (3 docentes/grupo),
  `teacher_alert_rules`, `school_notification_routes`, `school_calendar`
  (festivos CO 2026), `daily_schedule_config` y `schedules` rotativos L–V
  (fórmula `(gidx + blk + dow) mod N` sin colisiones).
- ~30 días lectivos de `biometric_events` coherentes (ingresos
  puntuales/tarde/muy tarde, entradas a aula por bloque real del schedule,
  baño, salida autorizada, salida de institución) e `attendance_incidents`
  derivados (`LATE_ARRIVAL`, `INASISTENCIA`, `EVASION_INTERNA`,
  `SALIDA_BAÑO`, `PERMISSION_EXPIRED`), permisos de clase, salidas de
  colegio y pedagógicas, `risk_justifications`.
- Mensajería: `twilio_messages` INASISTENCIA out/in (menú 1-2, ~60% de
  respuesta), `notifications` a coordinación/rectoría/docente-director,
  `internal_messages`, `sos_alerts`, `security_incidents`, `report_exports`,
  `user_commands`, `student_tracking`+notas, `student_behavior_metrics` y
  una `ota_updates` 1.0.1 inactiva.
- Deshabilita `trg_evaluate_risk_v3` durante la carga; al final siembra
  `fn_seed_default_risk_policy` por escuela y evalúa
  `fn_evaluate_student_risk` sobre el top-150 de estudiantes con más
  incidentes (genera snapshots, alertas y seguimientos automáticos) y marca
  `risk_config_completed`.
- Password de todos los usuarios generados: `test1234`. Docentes nuevos:
  `profNN@<dane>.nexo.local`; acudientes: `acu-…@nexo.local`.
- Resumen esperado por escuela: 24 grupos, ~480–576 estudiantes, ~500
  acudientes, 24 aulas + 26 nodos edge, 720 schedules, ~75k eventos, ~2.5k
  incidentes, WhatsApp + notificaciones, riesgo evaluado en top-150.

### 10.3 Seeds del stack de integración (`pruebas/`)

- `pruebas/seed.sql` — corre tras `schema.sql` en el initdb de
  `docker-compose.test.yml`. Idempotente con **UUIDs fijos deterministas**:
  municipio/depto `TEST-*`, escuela `IE Test NEXO` (`dane_code TEST-001`),
  1 grupo 6-A, 3 estudiantes (Eva es `biometric_exempt`), coordinador /
  docente / acudiente `*@test.nexo` (password `test1234`), nodo edge
  "Nodo Test Principal" con token conocido (`nexo-test-device-token`) y
  `ota_key` fija, bloques de jornada, `teacher_group_access`, usuarios por
  rol esperados por los tests de integración (`rector@nexo.edu` etc.,
  password `admin123`) sobre la escuela `000000000`, segunda escuela
  `IE Sin Onboarding` (`TEST-002`, todos los flags en FALSE) con secretaria
  y coordinador propios, y `daily_schedule_config` para hoy.
- `pruebas/seed_chat_fixture.sql` — fixture **aditivo** para pruebas
  conversacionales live (`continuity_50`, `live_probe`, conversaciones
  §31–37 del spec): grupos 10-A/10-B, 6 estudiantes en 10-A (incl. Tomás
  Castaño Gutiérrez) + 2 en 10-B con acudiente compartido, acceso docente a
  los 3 grupos, incidentes/eventos biométricos de hoy y del mes pasado,
  métricas, justificaciones y usuarios/estudiantes extra. Idempotente.

```bash
docker exec -i nexo-test-db-1 psql -U nexo_test -d nexo_test -f - < pruebas/seed_chat_fixture.sql
```

---

## 11. Factory reset

```bash
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f sql/factory_reset.sql
```

`factory_reset.sql` corre en una transacción (`BEGIN`/`COMMIT`) y devuelve la
base a estado "recién instalada" sin tocar la configuración estructural:

**TRUNCATE … CASCADE** (cubre tablas dependientes no listadas):

- Sesiones y efímera: `user_sessions`, `verification_codes`,
  `jwt_blocklist`, `rate_limits`.
- Personas y estructura académica: `guardians`, `students`,
  `guardian_student_relationships`, `student_fingerprints`,
  `academic_groups`, `student_group_assignments`, `teacher_group_access`,
  `teacher_alert_rules`, `classrooms`, `subjects`, `schedules`,
  `daily_schedule_config`, `school_schedule_config`, `school_time_blocks`,
  `technical_modality_config`.
- Edge/dispositivos: `edge_devices`, `device_commands`,
  `sensor_revocation_requests`, `ota_deployments`.
- Eventos e incidentes (padres particionados ⇒ caen todas las
  particiones): `biometric_events`, `attendance_incidents`, `sos_alerts`,
  `security_incidents`, `school_panic_events`.
- Autorizaciones: `school_exit_authorizations`,
  `class_exit_authorizations`, `pedagogical_trip_authorizations`.
- Mensajería: `notifications`, `internal_messages`, `twilio_messages`,
  `user_commands`.
- Auditoría y reportes: `student_record_audit`, `global_audit_logs`,
  `report_exports`.
- Riesgo y seguimiento: `student_behavior_metrics`, `risk_policies`,
  `risk_rules`, `risk_event_level_mapping`, `risk_combination_rules`,
  `risk_active_snapshot`, `risk_alerts`, `risk_justifications`,
  `risk_audit_log`, `student_tracking`, `student_tracking_notes`.
- Misceláneo: `school_calendar`, `system_telemetry`, `contact_leads`.

**Preserva**: `schools`/`departments`/`municipalities`, `roles`/`permissions`/
`role_permissions`, usuarios **no-GUARDIAN** y `staff_records`, catálogos
(`risk_event_types`, `twilio_message_types`), `schema_migrations`,
configuración institucional (`school_notification_routes`,
`school_action_policies`, `school_chat_policies`, `ota_updates`),
`chat_messages` (historial de los usuarios preservados) y
`schools.sensor_master_key_hash` (pertenece a la llave maestra del operador).

Luego `DELETE FROM users WHERE role = 'GUARDIAN'` y resetea los flags de
onboarding de `schools` (`onboarding_completed`,
`groups_onboarding_completed`, `groups_onboarding_year`,
`risk_config_completed`).

---

## 12. Convenciones del esquema

- **Nombres**: `snake_case` plural inglés para tablas; PK por tabla como
  `<entidad>_id` (`student_id`, `group_id`…); índices `idx_<tabla>_<cols>`,
  `uq_<tabla>_<cols>` para UNIQUE añadidos por `DO` blocks; triggers
  `trg_<tabla>_<efecto>`; policies `<iniciales>_<verbo>`
  (`students_select`, `be_insert`…); funciones `fn_*`.
- **PKs**: UUID v4 (`uuid_generate_v4()`) en casi todo; `BIGSERIAL` en
  `device_commands` y `system_telemetry`; `SERIAL` en `risk_event_types`;
  PK textual en `twilio_message_types.type_code`, `rate_limits.rl_key`,
  `jwt_blocklist.jti`; PK compuesta `(school_id, work_shift)` en
  `school_schedule_config`; PK compuesta `(id, <col_partición>)` en las 8
  tablas particionadas.
- **Timestamps**: `TIMESTAMPTZ` con `DEFAULT NOW()` en `created_at`;
  `updated_at`/`deleted_at` donde aplica. La lógica de negocio ("hoy",
  "día lectivo") convierte a `America/Bogota` — Colombia no tiene DST, la
  conversión es estable todo el año (`fn_bogota_date` es `IMMUTABLE`).
  Excepción: `student_tracking(_notes)` usa defaults
  `NOW() AT TIME ZONE 'America/Bogota'`.
- **JSONB**: `metadata_json` para payloads flexibles en eventos,
  incidentes, notificaciones, comandos y riesgo; `payload` en
  `system_telemetry` (índice GIN) y `device_commands`; `snapshot_json` en
  `risk_policies`; `condition_json` en `risk_combination_rules`;
  `days_of_week` en `technical_modality_config`; `payload_json` en
  `chat_messages`.
- **Enum-like**: `VARCHAR` + `CHECK` para dominios cerrados
  (`consent_status`, `risk_level`, `alert_level`, `escalation_state`,
  `status`, `event_kind`, `severity`, `event_type` de telemetría,
  `justification_type`, `role` de chat). Los `incident_type`/`event_type`
  operativos son `VARCHAR` libres (convención de código, no enum de BD).
- **Soft-delete**: `deleted_at` en `students` y `users` (los índices
  parciales `WHERE deleted_at IS NULL` aceleran la vista activa).
- **Secretos en BD**: solo hashes — `password_hash`/`password_salt`
  (bcrypt), `refresh_token_hash`, `token_hash` de dispositivos,
  `sensor_master_key_hash`, `ota_key`. El secreto HMAC de auditoría vive en
  el setting `app.nexo_hmac_secret` (variable de entorno del backend), no
  en tabla.
- **Idempotencia estructural**: toda evolución de columna posterior a la
  consolidación usa `ALTER TABLE … ADD COLUMN IF NOT EXISTS` o bloques
  `DO $$ … pg_constraint`; las rutinas se recrean con
  `CREATE OR REPLACE` / `DROP … IF EXISTS`.
- **Referencias polimórficas sin FK**: `notifications.origin_type`/
  `origin_id`, `student_tracking.origin_type`/`origin_id`,
  `user_commands.target_entity_*`, `twilio_messages.type_code` —
  documentadas por convención, no por constraint.
- **Tablas particionadas sin FKs**: `biometric_events` y demás padres
  particionados referencian entidades por columnas "lógicas"
  (`student_id`, `device_id`…) sin `REFERENCES`; la integridad la garantiza
  la aplicación.

---

## 13. Tabla-resumen de todas las tablas

68 tablas base (las 8 particiones `*_default` se listan con su padre). Columna
"P" = particionada mensual; "RLS" = con Row Level Security.

| # | Tabla | Dominio | P | RLS | Propósito en una línea |
|---|---|---|---|---|---|
| 1 | `academic_groups` | Académico | | ✔ | Grupos por escuela/año/grado/nomenclatura. |
| 2 | `attendance_incidents` | Asistencia | ✔ | ✔ | Incidentes derivados de eventos biométricos (tardanzas, inasistencias, evasiones…). |
| 3 | `biometric_events` | Asistencia | ✔ | ✔ | Eventos crudos del sensor (ingreso/aula/baño/salidas). |
| 4 | `chat_messages` | Chat Nexus | | ✔ | Historial del asistente «Pregúntale a Nexus» por usuario/sesión. |
| 5 | `class_exit_authorizations` | Permisos | | ✔ | Permisos de salida del aula (ACTIVE/COMPLETED/EXPIRED/CANCELLED). |
| 6 | `classrooms` | Académico | | ✔ | Aulas físicas de la escuela. |
| 7 | `contact_leads` | Sistema | | | Contactos captados en el landing público. |
| 8 | `daily_schedule_config` | Horarios | | ✔ | Override diario por grupo (clases sí/no, horas esperadas). |
| 9 | `departments` | Geografía | | | Catálogo global de departamentos. |
| 10 | `device_commands` | Edge | | ✔ | Cola de comandos a nodos edge (fallback sin Redis). |
| 11 | `edge_devices` | Edge | | ✔ | Nodos/sensores edge: tokens, estado, telemetría, versión, ota_key. |
| 12 | `global_audit_logs` | Auditoría | ✔ | ✔ | Auditoría global con cadena HMAC append-only por escuela. |
| 13 | `guardian_student_relationships` | Personas | | ✔ | Vínculo acudiente↔estudiante (máx. 3 por trigger). |
| 14 | `guardians` | Personas | | ✔ | Perfil de acudiente 1:1 con `users` (WhatsApp). |
| 15 | `internal_messages` | Mensajería | ✔ | ✔ | Mensajería interna entre usuarios de la escuela. |
| 16 | `jwt_blocklist` | Identidad | | ✔ | JTIs de JWT revocados (acceso global). |
| 17 | `municipalities` | Geografía | | | Catálogo global de municipios. |
| 18 | `notifications` | Mensajería | | ✔ | Notificaciones in-app con dedup y origen polimórfico. |
| 19 | `ota_deployments` | Edge/OTA | | ✔ | Auditoría de despliegue de cada update por nodo. |
| 20 | `ota_updates` | Edge/OTA | | ✔ | Versiones de firmware/app publicadas (global o por escuela). |
| 21 | `pedagogical_trip_authorizations` | Permisos | | ✔ | Salidas pedagógicas grupales. |
| 22 | `permissions` | Identidad | | | Catálogo global de 32 permisos `dominio.accion`. |
| 23 | `rate_limits` | Sistema | | | Contadores de rate limiting por clave/ventana. |
| 24 | `report_exports` | Auditoría | | ✔ | Trazabilidad de reportes exportados (tipo, formato, path). |
| 25 | `risk_active_snapshot` | Riesgo | | ✔ | Snapshot del score activo por estudiante×categoría. |
| 26 | `risk_alerts` | Riesgo | | ✔ | Alertas pedagógicas con nivel, escalación, cooldown y resolución. |
| 27 | `risk_audit_log` | Riesgo | | ✔ | Bitácora de cambios de configuración del motor de riesgo. |
| 28 | `risk_combination_rules` | Riesgo | | ✔ | Reglas de combinación entre categorías (Capa 4). |
| 29 | `risk_event_level_mapping` | Riesgo | | ✔ | Mapeo evento→nivel por política (+ override por estudiante). |
| 30 | `risk_event_types` | Riesgo | | | Catálogo global de tipos de evento de riesgo. |
| 31 | `risk_justifications` | Riesgo | | ✔ | Justificaciones que excluyen incidentes del score. |
| 32 | `risk_policies` | Riesgo | | ✔ | Políticas de riesgo versionadas por escuela. |
| 33 | `risk_rules` | Riesgo | | ✔ | Pesos, vidas medias, umbrales, cooldowns y rangos por nivel. |
| 34 | `role_permissions` | Identidad | | | Asignación rol→permiso (N:M). |
| 35 | `roles` | Identidad | | | Catálogo global de los 10 roles. |
| 36 | `schedules` | Horarios | | ✔ | Horario semanal grupo×día×bloque → aula/docente/asignatura. |
| 37 | `schema_migrations` | Plataforma | | | Historial de migraciones aplicadas (trazabilidad). |
| 38 | `school_action_policies` | Config | | | Políticas de acción por event_type (acción o NONE). |
| 39 | `school_calendar` | Horarios | | ✔ | Calendario lectivo (días hábiles/festivos por escuela). |
| 40 | `school_chat_policies` | Chat Nexus | | ✔ | Interruptores del asistente Nexus por escuela. |
| 41 | `school_exit_authorizations` | Permisos | | ✔ | Autorizaciones de salida de la institución. |
| 42 | `school_notification_routes` | Config | | ✔ | Destinos por rol para eventos/escalaciones. |
| 43 | `school_panic_events` | Seguridad | | ✔ | Modo pánico: evento que desactiva dispositivos en cascada. |
| 44 | `school_schedule_config` | Horarios | | ✔ | Config de jornada por escuela (PK school+work_shift). |
| 45 | `school_time_blocks` | Horarios | | ✔ | Bloques horarios por jornada (aulas rotativas). |
| 46 | `schools` | Institución | | | Raíz del multi-tenant; flags de onboarding y llave maestra. |
| 47 | `security_incidents` | Seguridad | | ✔ | Incidentes disciplinarios/de seguridad. |
| 48 | `sensor_revocation_requests` | Edge | | ✔ | Revocación programada de sensores con countdown. |
| 49 | `sos_alerts` | Seguridad | ✔ | ✔ | Alertas SOS de botón de pánico. |
| 50 | `staff_records` | Personas | | ✔ | Registro laboral del personal de la escuela. |
| 51 | `student_behavior_metrics` | Riesgo | | ✔ | Métricas agregadas legacy (score 0-100, ventana N días). |
| 52 | `student_fingerprints` | Biometría | | | Huellas por estudiante (máx. 2 slots; templates en el edge). |
| 53 | `student_group_assignments` | Académico | | ✔ | Asignación estudiante→grupo (N:M). |
| 54 | `student_record_audit` | Auditoría | ✔ | ✔ | Auditoría de expediente del estudiante (antes/después JSONB). |
| 55 | `student_tracking` | Seguimiento | | ✔ | Casos de seguimiento con workflow de estado. |
| 56 | `student_tracking_notes` | Seguimiento | | ✔ | Notas de los casos de seguimiento. |
| 57 | `students` | Personas | | ✔ | Matrícula; exenciones biométricas y consentimiento habeas data. |
| 58 | `subjects` | Académico | | ✔(open) | Catálogo global de asignaturas (lectura pública). |
| 59 | `system_telemetry` | Sistema | | ✔ | Telemetría de clientes (errores JS, latencias, ping). |
| 60 | `teacher_alert_rules` | Seguimiento | | ✔ | F-18: criterios de aviso por docente (N eventos en M días). |
| 61 | `teacher_group_access` | Académico | | ✔ | Acceso docente→grupo por año electivo. |
| 62 | `technical_modality_config` | Horarios | | | Modalidad técnica por grado/jornada/año. |
| 63 | `twilio_message_types` | Mensajería | | | Catálogo de los 13 tipos de mensaje WhatsApp. |
| 64 | `twilio_messages` | Mensajería | ✔ | ✔ | Mensajes WhatsApp Twilio entrantes/salientes. |
| 65 | `user_commands` | Auditoría | ✔ | ✔ | Auditoría de comandos ejecutados por usuarios. |
| 66 | `user_sessions` | Identidad | | ✔ | Sesiones de refresh token (multi-tenant vía `users`). |
| 67 | `users` | Identidad | | ✔ | Cuentas y credenciales (bcrypt); soft-delete. |
| 68 | `verification_codes` | Identidad | | | Códigos OTP de verificación con expiración e intentos. |

Particiones `DEFAULT` creadas por el schema (8): `biometric_events_default`,
`attendance_incidents_default`, `internal_messages_default`,
`twilio_messages_default`, `user_commands_default`, `sos_alerts_default`,
`student_record_audit_default`, `global_audit_logs_default`. Las particiones
mensuales `<padre>_YYYY_MM` las crean `fn_ensure_partitions` (al instalar:
mes actual + 6) y el cron `create_monthly_partition.sh`.

---

*Fuente canónica: `sql/schema.sql`. Cualquier cambio de esquema debe
reflejarse ahí, mantener la idempotencia y validarse con las suites de
`test/sql/` y `test/runners/SchemaPhpAlignmentTest.php`.*
