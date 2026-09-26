# README_schema.md — Modelo de datos PostgreSQL de NEXO

Documentación del modelo de datos de NEXO. La fuente canónica del esquema es `sql/schema.sql`; este documento lo explica y referencia, no lo duplica.

## Fuente canónica

El esquema PostgreSQL de NEXO se mantiene en un único archivo:

```
sql/schema.sql
```

- Es autocontenido e idempotente (`IF NOT EXISTS`, `ON CONFLICT`): puede ejecutarse contra una base vacía o sobre una existente sin error.
- Contiene extensiones, tabla de control de migraciones, todas las tablas con columnas/tipos/constraints/FKs, índices (únicos, parciales, GIN), funciones, triggers, políticas RLS, particiones, funciones de creación y retención de particiones, y un seed mínimo.
- Las migraciones SQL que existieron durante el desarrollo ya no forman parte del flujo de instalación. La tabla `schema_migrations` conserva el historial de versiones aplicadas con fines de trazabilidad, pero no hay archivos de migración que ejecutar para una instalación nueva.
- Cualquier cambio futuro del esquema debe reflejarse en `sql/schema.sql` y validarse con las pruebas de `test/sql/` antes de integrarse.

## Instalación limpia

```bash
DATABASE_URL="postgresql://usuario:password@host:5432/nexo" ./sql/deploy_db.sh
```

Requisitos:

- PostgreSQL 15 o superior.
- Extensiones `uuid-ossp` y `pgcrypto` (las crea el propio `schema.sql` con `CREATE EXTENSION IF NOT EXISTS`).
- Cliente `psql`.
- Rol con permisos para crear tablas, funciones, triggers, políticas RLS y extensiones.
- Variable de entorno `DATABASE_URL` en formato `postgresql://usuario:password@host:5432/nexo`.

`sql/deploy_db.sh` ejecuta `psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f sql/schema.sql`. El flag `ON_ERROR_STOP` aborta al primer error.

El seed inicial crea:

- Una institución de ejemplo en Bogotá D.C. (`schools` con `dane_code='000000000'`).
- Los 10 roles del sistema (`RECTOR`, `COORDINATOR`, `TEACHER`, `SECRETARY`, `SECURITY`, `AUXILIARY`, `COUNSELOR`, `GUARDIAN`, `SUPER_ADMIN`, `SYSTEM_WORKER`).
- El catálogo de 31 permisos en `permissions`.
- La asignación rol→permiso en `role_permissions` (RECTOR tiene todos; el resto un subconjunto).
- Un usuario administrador con rol `RECTOR` (`admin@nexo.edu`). Las credenciales están en el bloque `SEED DATA` de `schema.sql` y deben rotarse inmediatamente en cualquier entorno que no sea de desarrollo.
- Las particiones mensuales iniciales (mes actual + 6 meses) vía `fn_ensure_partitions(6)`.

## Dominios del modelo

60 tablas organizadas en dominios funcionales. Todas las operacionales llevan `school_id` (multi-tenant) salvo los catálogos globales.

| Dominio | Tablas | Responsabilidad |
|---|---|---|
| Identidad y acceso | `users`, `roles`, `role_permissions`, `permissions`, `user_sessions`, `jwt_blocklist`, `verification_codes` | Cuentas, roles, permisos, sesiones JWT, códigos OTP |
| Geografía e institución | `departments`, `municipalities`, `schools` | Ubicación geográfica y datos de la institución + flags de onboarding |
| Estudiantes y acudientes | `students`, `guardians`, `guardian_student_relationships`, `staff_records` | Matrícula, acudientes (máx 3 por estudiante), personal |
| Grupos y horarios | `academic_groups`, `student_group_assignments`, `teacher_group_access`, `classrooms`, `subjects`, `schedules`, `daily_schedule_config`, `school_schedule_config`, `school_time_blocks`, `technical_modality_config`, `school_calendar` | Grupos académicos, asignaciones, horarios, bloques, calendario lectivo |
| Dispositivos edge | `edge_devices`, `sensor_revocation_requests`, `device_commands` | Sensores, revocaciones, cola de comandos (fallback PG) |
| Eventos biométricos y asistencia | `biometric_events`, `attendance_incidents` | Eventos del sensor (particionados) e incidentes derivados |
| Operaciones y notificaciones | `notifications`, `internal_messages`, `twilio_messages`, `twilio_message_types`, `user_commands`, `report_exports` | Notificaciones in-app, mensajes internos, envíos WhatsApp, auditoría de comandos, reportes |
| Seguridad y emergencias | `sos_alerts`, `security_incidents`, `school_panic_events` | SOS, incidentes de seguridad, modo pánico |
| Permisos y salidas | `school_exit_authorizations`, `class_exit_authorizations`, `pedagogical_trip_authorizations` | Salida de institución, salida al baño, salida pedagógica grupal |
| Riesgo pedagógico v3.0 | `risk_event_types`, `risk_policies`, `risk_event_level_mapping`, `risk_rules`, `risk_combination_rules`, `risk_active_snapshot`, `risk_alerts`, `risk_justifications`, `risk_audit_log`, `student_behavior_metrics` | Motor de análisis de riesgo con decaimiento exponencial y clustering |
| Seguimiento | `student_tracking`, `student_tracking_notes` | Casos de seguimiento por orientación |
| Auditoría | `student_record_audit`, `global_audit_logs` | Cadena de hashes HMAC de logs y auditoría de expedientes |
| Sistema | `system_telemetry`, `rate_limits`, `contact_leads`, `schema_migrations` | Telemetría, rate limiting, contactos de landing, control de migraciones |

## Relaciones importantes

- `schools` es el centro del multitenant. Casi todas las tablas operacionales tienen `school_id REFERENCES schools(school_id)`.
- `users` → `roles` (N:1) → `role_permissions` (N:M) → `permissions`. Un usuario pertenece a una `schools`.
- `students` → `student_group_assignments` (N:M) → `academic_groups`. Un estudiante tiene un grupo activo por año.
- `guardians` → `guardian_student_relationships` (N:M) → `students`. Máximo 3 acudientes por estudiante (trigger `trg_guardian_limit`).
- `academic_groups` → `teacher_group_access` (N:M) → `users` (docentes). Un docente ve los grupos asignados.
- `biometric_events` → `students` + `edge_devices`. Genera `attendance_incidents` (LATE_ARRIVAL, EVASION_INTERNA, etc.).
- `attendance_incidents` dispara `risk_alerts` vía trigger `trg_evaluate_risk_v3`.
- `class_exit_authorizations` → `students`. `worker_permission_status` las cierra/expira según `biometric_events`.
- `risk_policies` (versionada) → `risk_rules` + `risk_event_level_mapping` → `risk_event_types`. Genera `risk_active_snapshot` y `risk_alerts`.
- `global_audit_logs` encadena con `prev_audit_id` + `chain_hash` (cadena HMAC por escuela).

## Row Level Security

RLS está habilitada en todas las tablas multi-tenant. El mecanismo:

1. `get_current_school_id()` lee el setting de sesión `app.current_school_id` (devuelve NULL si no está seteado).
2. `get_current_role()` lee `app.current_role`.
3. Cada policy compara `school_id = get_current_school_id()` en `USING`/`WITH CHECK`.

El backend fija estos settings en `requireAuth()` (`backend/api/routes/_auth_middleware.php`) con `set_config('app.current_school_id', ..., true)` a nivel de transacción (necesario por PgBouncer transaction-pool). Los dispositivos edge los fijan con rol `EDGE_NODE` tras validar su token. Los workers con `SYSTEM_WORKER`.

Tablas con RLS (extracto; ver `sql/schema.sql` sección `ROW LEVEL SECURITY — POLICIES` para el listado completo de ~40 tablas): `students`, `users`, `guardians`, `biometric_events`, `attendance_incidents`, `sos_alerts`, `global_audit_logs`, `edge_devices`, `device_commands`, `academic_groups`, `schedules`, `notifications`, `school_exit_authorizations`, `class_exit_authorizations`, `school_panic_events`, `user_sessions`, `teacher_group_access`, `school_schedule_config`, `school_time_blocks`, `risk_alerts`, `student_tracking`, etc.

Casos especiales:

- `user_sessions`: multi-tenant vía JOIN a `users`. `SYSTEM_WORKER` puede leer todas (para validación de refresh tokens).
- `teacher_group_access`: `SYSTEM_WORKER` puede leer todas (para notificaciones de workers).
- `schema_migrations`, `risk_event_types`, `departments`, `municipalities`: catálogos globales sin RLS.

## Funciones

### Helpers RLS

- `get_current_school_id()` → UUID. Lee `app.current_school_id`.
- `get_current_role()` → TEXT. Lee `app.current_role`.
- `fn_bogota_date(t TIMESTAMPTZ)` → DATE. Conversión a zona America/Bogota (IMMUTABLE, para índices).

### Migraciones

- `migration_was_executed(filename)` → BOOLEAN.
- `register_migration(...)` → UUID. Inserta/actualiza `schema_migrations`.

### Auditoría

- `fn_calculate_audit_hash(prev_hash, school_id, actor_id, event_type, description, ip, created_at)` → TEXT. HMAC-SHA256 con `app.nexo_hmac_secret`. Aborta si el secreto es el default.
- `fn_audit_chain_trigger()` → TRIGGER. Calcula `prev_audit_id` y `chain_hash` antes de insertar en `global_audit_logs`.
- `fn_validate_audit_chain(school_id)` → JSONB. Verifica la cadena completa y reporta dónde se rompió.

### Riesgo (motor v3.0)

- `fn_count_lecture_days(school_id, from, to)` → INTEGER. Días lectivos desde `school_calendar` (fallback Lun-Vie).
- `fn_seed_default_risk_policy(school_id, created_by)` → UUID. Siembra política v3.0 con 4 niveles (LEVE/MODERADA/ALTA/MUY_ALTA) y mapeo de eventos.
- `fn_calculate_category_risk(student_id, school_id, category, policy_id, lookback_days)` → TABLE. Calcula score activo por categoría con decaimiento exponencial (`weight_base * exp(-λ * días_lectivos)`, `λ = ln(2)/half_life`) y factor de clustering (hasta 2x si los eventos son regulares).
- `fn_evaluate_student_risk(student_id, school_id)` → JSONB. Evalúa las 3 categorías (asistencia, evasion, comportamiento), agrega, toma el nivel máximo, inserta `risk_active_snapshot` y, si corresponde, `risk_alerts` con cooldown.
- `fn_trigger_evaluate_risk_v3()` → TRIGGER. Dispara `fn_evaluate_student_risk` tras cada `LATE_ARRIVAL`/`EVASION_INTERNA`/`SALIDA_BAÑO` nuevo.
- `fn_recalculate_school_risk_v3(school_id)` → INTEGER. Recalcula todos los estudiantes de una escuela.
- `fn_justify_risk_event(...)` → UUID. Inserta justificación y recalcula.
- `fn_calculate_student_risk(student_id, school_id, window_days)` → JSONB. Cálculo legacy (score 0-100 con pesos fijos: late×5, ausencia×15, evasion×10). Sigue usándose para `student_behavior_metrics`.
- `fn_recalculate_school_metrics(school_id)` → INTEGER. Versión legacy del recálculo masivo.

### Onboarding / evasión

- `is_student_present_today(school_id, student_id)` → BOOLEAN. Hubo `INGRESO_*` hoy.
- `has_active_permiso(school_id, student_id)` → BOOLEAN. Tiene `class_exit_authorizations` activa.
- `get_active_permiso_info(school_id, student_id)` → TABLE. Info del permiso activo.

### Particiones

- `fn_ensure_partitions(months_ahead)` → INTEGER. Crea particiones mensuales para mes actual + N futuros en las 8 tablas particionadas. Idempotente.
- `fn_drop_old_partitions(retention_months)` → INTEGER. Detacha y dropea particiones más antiguas que N meses (nunca toca la DEFAULT).

### Otras

- `fn_guardians_normalize_phone()` → TRIGGER. Normaliza `whatsapp_phone_normalized`.
- `fn_check_guardian_limit()` → TRIGGER. Enforcea máximo 3 acudientes por estudiante.
- `assign_permission_to_role(role_name, permission_code)` → VOID. Helper temporal del seed.

Todas las funciones de negocio son `SECURITY DEFINER` con `search_path = public, pg_catalog` para evitar ataques de search_path.

## Triggers

| Trigger | Tabla | Evento | Función |
|---|---|---|---|
| `trg_guardians_normalize_phone` | `guardians` | BEFORE INSERT/UPDATE | Normaliza teléfono WhatsApp |
| `trg_audit_chain` | `global_audit_logs` | BEFORE INSERT | Calcula cadena HMAC |
| `trg_guardian_limit` | `guardian_student_relationships` | BEFORE INSERT/UPDATE | Máximo 3 acudientes |
| `trg_evaluate_risk_v3` | `attendance_incidents` | AFTER INSERT | Evalúa riesgo del estudiante |

## Índices

El schema define índices en todas las rutas de acceso frecuentes. Tipos notables:

- **Únicos**: `permissions.permission_code`, `departments.department_name`, `schools.dane_code`, `academic_groups(school_id, academic_year, grade_level, group_name)`, `schema_migrations.filename`, `student_behavior_metrics(student_id, calculation_window_days)`.
- **Parciales**: `idx_users_email_lower ON users(LOWER(email)) WHERE email IS NOT NULL`, `idx_students_school_grade WHERE deleted_at IS NULL`, `idx_telemetry_severity WHERE severity IN ('warn','error')`, `idx_late_arrival_per_day WHERE incident_type='LATE_ARRIVAL'`.
- **Compuestos**: `idx_students_school_last_first`, `idx_schedule_group_day_block`, `idx_risk_alerts_cooldown`, etc.
- **GIN**: `idx_telemetry_payload_gin ON system_telemetry USING GIN (payload)`.
- **Funcionales**: `idx_late_arrival_per_day` usa `fn_bogota_date(detected_at)`.

## Particiones y retención

8 tablas de alto volumen están particionadas mensualmente por marca de tiempo:

| Tabla | Columna de partición |
|---|---|
| `biometric_events` | `event_timestamp` |
| `attendance_incidents` | `detected_at` |
| `internal_messages` | `sent_at` |
| `twilio_messages` | `sent_at` |
| `user_commands` | `executed_at` |
| `sos_alerts` | `emitted_at` |
| `student_record_audit` | `performed_at` |
| `global_audit_logs` | `created_at` |

Cada una tiene una partición `DEFAULT` (catch-all) y particiones mensuales con nombre `<tabla>_YYYY_MM`. `fn_ensure_partitions(6)` crea las del mes actual + 6 futuros al aplicar el schema. En producción, `backend/api/infra/scripts/create_monthly_partition.sh` corre el primer día de cada mes a las 3 AM UTC desde el crontab del contenedor para crear la del mes siguiente.

`fn_drop_old_partitions(24)` implementa la retención: detach + drop de particiones > 24 meses. Nunca toca la `DEFAULT`. Configurable vía `NEXO_PARTITION_RETENTION_MONTHS`.

## Restricciones notables

- `guardian_student_relationships`: máximo 3 acudientes por estudiante (trigger).
- `risk_event_level_mapping.risk_level`: CHECK en `('SIN_IMPORTANCIA','LEVE','MODERADA','ALTA','MUY_ALTA')`.
- `class_exit_authorizations.status`: estados `ACTIVE`/`COMPLETED`/`EXPIRED`.
- `risk_alerts.status`: `abierta`/`resuelta`/`escalada`.
- Soft-delete en `students` (`deleted_at`) y `users` (`deleted_at`).
- `schools`: flags de onboarding (`onboarding_completed`, `groups_onboarding_completed`, `risk_config_completed`).

## Seguridad a nivel de base de datos

- **RLS** en todas las tablas multi-tenant (ver arriba).
- **UUIDs** como PK en todas las tablas (vía `uuid-ossp`), no enumerables.
- **Hashing bcrypt** de contraseñas (`users.password_hash`).
- **Cadena HMAC** de auditoría con secreto obligatorio (aborta si es default).
- **Funciones SECURITY DEFINER** con `search_path` fijado.
- **Soft-delete** para preservar integridad referencial histórica.

El detalle de seguridad a nivel de aplicación (JWT, CORS, cifrado edge, etc.) está en [docs/SECURITY.md](docs/SECURITY.md).

## Pruebas del schema

`test/sql/` (PHPUnit, suite `SQL Schema Tests`) parsea `sql/schema.sql` estáticamente sin necesidad de PostgreSQL corriendo:

- `SchemaIntegrityTest.php` — tablas, columnas, tipos, PKs, FKs, constraints, índices, triggers, funciones, roles, policies RLS, particiones, ALTER TABLE.
- `ConstraintTest.php` — constraints CHECK/UNIQUE.
- `ForeignKeyTest.php` — integridad referencial.
- `IndexTest.php` — índices esperados.
- `PartitionTest.php` — particiones y función de creación.
- `RlsSecurityTest.php` — policies RLS por tabla.
- `SeedDataTest.php` — seed mínimo presente.
- `TriggerTest.php` — triggers definidos.

Ejecución:

```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "SQL Schema Tests"
```

`test/runners/SchemaPhpAlignmentTest.php` verifica que el schema SQL y el código PHP estén alineados (columnas que usa la API existen en el schema). Más detalle en [docs/TESTING.md](docs/TESTING.md).

## Desarrollo del esquema

Para modificar el esquema:

1. Edita `sql/schema.sql`. Es la única fuente oficial.
2. Mantén la idempotencia (`IF NOT EXISTS`, `ON CONFLICT`) para que el archivo siga siendo reejecutable.
3. Si añades o modificas tablas multi-tenant, refleja el cambio en las policies RLS correspondientes.
4. Si tocas tablas particionadas, verifica que `fn_ensure_partitions` y `create_monthly_partition.sh` sigan siendo coherentes.
5. Valida con `test/sql/` y `test/runners/SchemaPhpAlignmentTest.php`.
6. Para instalar una base limpia desde cero, crea una base vacía y ejecuta `sql/deploy_db.sh`.

La alineación entre el esquema SQL y el código PHP la verifica `SchemaPhpAlignmentTest.php`; úsalo tras cualquier cambio de modelo.
