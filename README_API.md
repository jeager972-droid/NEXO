# README_API.md — Referencia de la API REST de NEXO

Documentación técnica de la API REST de NEXO, extraída del código en `backend/api/`. Todas las rutas se sirven desde el front controller único `backend/api/api.php` bajo el mismo host (no hay prefijo `/v1` en el dispatch real; el backend normaliza vía `cleanPath`).

## Convenciones

- **Base URL**: la que configure la PWA en `VITE_API_BASE_URL` (ej. `https://nexo-80go.onrender.com`). El edge usa `api_url` de su `config.json`.
- **Content-Type**: `application/json` en todas las peticiones y respuestas con body.
- **Autenticación**: cookie HttpOnly `token` (preferida) o cabecera `Authorization: Bearer <JWT>`. El refresh token va en cookie HttpOnly `refresh_token` o en el body (`refresh_token`) como workaround para iOS ITP.
- **CSRF**: los métodos mutantes (`POST`/`PUT`/`DELETE`/`PATCH`) requieren cabecera `X-Requested-With: XMLHttpRequest`. Sin ella, `403 Request forbidden`.
- **Respuesta**: siempre JSON con `{"status": "ok"|"error"|"accepted"|"2fa_required", ...}`.
- **Errores**: `400` validación, `401` no autenticado, `403` sin permiso, `404` no encontrado, `409` conflicto, `422` semántica, `429` rate limit, `500` error interno, `503` servicio externo no disponible.
- **Rate limiting**: en endpoints sensibles (`/auth/login`, `/contacto`, etc.) vía Redis con ventana configurable. Si Redis está caído, fail-open excepto `/contacto` (fail-closed, `503`).
- **Auditoría**: `securityLog()` escribe a stderr y, si `AUDIT_WORKER_ENABLED=1`, encola en `queue:audit_logs`. Las operaciones de usuario se registran en `user_commands`.
- **Multi-tenant**: toda consulta a tablas con RLS se filtra por `app.current_school_id` fijado en `requireAuth()`. Un endpoint nunca recibe `school_id` del cliente; se toma del JWT.

## Routing

`api.php` toma el primer segmento de la ruta y lo mapea a uno o varios archivos de `routes/`:

| Prefijo | Archivo(s) |
|---|---|
| `auth` | `auth.php` |
| `dashboard` | `dashboard.php` |
| `students` | `students.php` |
| `groups` | `groups.php` |
| `contacto` | `misc.php` |
| `notifications` | `misc.php` |
| `reports` | `misc.php` |
| `webhooks` | `twilio_delivery.php`, `misc.php` |
| `operations` | `operations.php` (cargado siempre para helpers Twilio) |
| `devices` | `devices.php` |
| `audit` | `audit_logs.php`, `audit_integrity.php`, `audit_full.php` |
| `security` | `security_panic.php` |
| `behavior` | `behavior.php` |
| `risk` | `risk.php` |
| `admin` | `admin.php` |
| `metrics` | `metrics.php` |
| `telemetry` | `telemetry.php` |
| `users` | `users.php` |
| `consultation` / `consultations` | `consultations.php` (+ `misc.php` para `consultation`) |
| `tracking` | `tracking.php` |
| `school` | `school_config.php` |

Dentro de cada archivo, los endpoints se dispatchan por `$cleanPath === '/prefijo/subruta'` y `$method`. Las rutas con parámetros de path usan `preg_match`.

## Autenticación

### POST /auth/login

Inicia sesión. No requiere auth.

- **Body**: `{ "email": "string", "password": "string" }`
- **200**: `{ "status": "ok", "user": {...}, "token": "<JWT>", "refresh_token": "<refresh>" }` + cookies HttpOnly `token` y `refresh_token`.
- **202**: `{ "status": "2fa_required", "requires_2fa": true }` si `LOGIN_2FA_ENABLED=true` y el usuario tiene teléfono verificado (se envía OTP por WhatsApp).
- **400**: email/password vacíos.
- **401**: credenciales incorrectas o cuenta inactiva.
- **429**: login throttled (`isLoginThrottled`).
- **503**: 2FA habilitado pero no se pudo entregar el OTP por WhatsApp.
- **Efectos**: `users.last_login_at = NOW()`, rehash bcrypt si cost < 12, inserta `user_sessions` con `refresh_token_hash`. Si 2FA, inserta `verification_codes`.

### POST /auth/verify-2fa

Completa el login tras 2FA.

- **Body**: `{ "email": "string", "code": "string" }`
- **200**: mismo que login exitoso.
- **400**: datos inválidos.
- **401**: código incorrecto o expirado.

### POST /auth/refresh

Rota el refresh token y emite un nuevo access token. No requiere auth (usa el refresh token).

- **Body** (opcional): `{ "refresh_token": "string" }` o cookie `refresh_token`.
- **200**: `{ "status": "ok", "token": "<JWT>", "refresh_token": "<nuevo>" }` + cookies.
- **401**: refresh token inválido, expirado o revocado.
- **Efectos**: revoca la sesión anterior (`user_sessions.revoked = TRUE`) y crea una nueva (rotación real).

### POST /auth/logout

Cierra sesión. Requiere `X-Requested-With`.

- **Auth**: opcional (usa el token si está presente).
- **200**: `{ "status": "ok", "message": "Sesión cerrada" }` + cookies expiradas.
- **Efectos**: revoca `user_sessions` por `refresh_token_hash`, añade `jti` a blocklist de Redis si está disponible, borra cookies.

### GET /auth/me

Valida la sesión y retorna el usuario actual.

- **Auth**: requerida.
- **200**: `{ "status": "ok", "user": { id, email, nombre, role, school_id, ... } }`.
- **401**: token inválido.

## Usuarios

Todos requieren auth salvo donde se indique.

### GET /users/by-role
- **Auth**: cualquier rol.
- **Query**: `role` (nombre de rol).
- **200**: lista de usuarios de la institución con ese rol.

### GET /users/me/extended
- **Auth**: cualquier rol.
- **200**: perfil extendido del usuario (incluye staff_records/guardians según rol).

### POST /users/upload-photo
- **Auth**: cualquier rol.
- **Body**: multipart/form-data con la imagen.
- **200**: URL de la foto subida. Efecto: actualiza `users.profile_photo_url`.

### POST /users/send-verification
- **Auth**: cualquier rol.
- **Body**: `{ "target": "phone|email" }`.
- **Efecto**: inserta `verification_codes`, encola OTP por WhatsApp.

### POST /users/verify-code
- **Auth**: cualquier rol.
- **Body**: `{ "code": "string", "purpose": "string" }`.
- **200/400**: verifica el código en `verification_codes`.

### POST /users/update-profile
- **Auth**: cualquier rol.
- **Body**: campos a actualizar (first_name, last_name, phone, etc.).

### POST /users/delete-field
- **Auth**: cualquier rol.
- **Body**: `{ "field": "string" }`.
- **Efecto**: elimina un campo del perfil (ej. foto).

### POST /users/change-password
- **Auth**: cualquier rol.
- **Body**: `{ "current_password": "string", "new_password": "string" }`.
- **Efecto**: re-verifica la contraseña actual, actualiza `password_hash`.

### POST /users/reset-password
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Body**: `{ "user_id": "uuid", "new_password": "string" }`.
- **Efecto**: resetea la contraseña de otro usuario.

### GET /users/me/photo
- **Auth**: cualquier rol.
- **200**: imagen de perfil.

## Estudiantes

### GET /students
- **Auth**: cualquier rol.
- **Query**: `limit` (máx 100, default 50), `search`, `group`, `grade`.
- **200**: lista paginada de estudiantes de la institución (filtrado por RLS).

### POST /students
- **Auth**: `SECRETARY`, `RECTOR`, `COORDINATOR`.
- **Body**: `{ "first_name", "last_name", "document", "group" (nombre grupo), "work_shift" }`.
- **201**: estudiante creado. Efecto: inserta `students` + `student_group_assignments` + crea `users`/`guardians`/`guardian_student_relationships` para el acudiente.
- **400**: campos vacíos. **500**: error.

### POST /students/bulk-assign
- **Auth**: `RECTOR`, `SECRETARY`.
- **Body**: asignación masiva de estudiantes a grupos.
- **Efecto**: actualiza `student_group_assignments`.

### DELETE /students/{student_id}
- **Auth**: `SECRETARY`, `RECTOR`, `COORDINATOR`.
- **Efecto**: soft-delete (`students.deleted_at = NOW()`, `active = FALSE`).
- **404**: no encontrado.

## Grupos

### GET /groups
- **Auth**: cualquier rol.
- **Query**: `academic_year` (default año actual).
- **200**: lista de `academic_groups` de la institución con conteo de estudiantes.

## Dashboard

### GET /dashboard/stats
- **Auth**: cualquier rol.
- **Query**: `group_id`, `range`, `metric` (present/absent/alert/permiso/late).
- **200**: estadísticas filtradas por rol (docente ve solo sus grupos, rector/coordinador ven toda la institución). Usa cache Redis `dashboard:stats:*` si está disponible.

### GET /dashboard/teacher-group-detail
- **Auth**: cualquier rol.
- **Query**: `group_id`, `date`.
- **200**: detalle de un grupo del docente (asistencia, incidentes).

### GET /dashboard/events
- **Auth**: cualquier rol.
- **Query**: `type` (situacion_critica/permiso/autorizar_salida/pedagogica/iniciar_seguimiento/daño/...).
- **200**: feed de eventos operacionales recientes.

## Operaciones

Todas requieren auth + permiso `operations.<accion>`. Los métodos mutantes requieren `X-Requested-With`. Las acciones que requieren presencia del estudiante (`permiso`, `autorizar_salida`, `horario`) validan que no tenga `INASISTENCIA` hoy.

### POST /operations/sos
- **Auth**: permiso `operations.sos`.
- **Body**: `{ "params": { "location", "message", "student_id" } }`.
- **Efecto**: inserta `sos_alerts`, notifica por WhatsApp al rol superior (RECTOR↔COORDINATOR).
- **Tablas**: `sos_alerts`, `user_commands`, `twilio_messages`.

### POST /operations/situacion_critica
- **Auth**: permiso `operations.situacion_critica`.
- **Efecto**: notifica a rector y coordinador por WhatsApp.

### POST /operations/inasistencia
- **Auth**: permiso `operations.inasistencia`.
- **Body**: `{ "params": { "student_id", "reason" } }`.
- **Efecto**: inserta `attendance_incidents` (INASISTENCIA), encola WhatsApp al acudiente.

### POST /operations/citacion
- **Auth**: permiso `operations.citacion`.
- **Efecto**: encola WhatsApp de citación al acudiente, inserta `twilio_messages`.

### POST /operations/salida (autorizar_salida)
- **Auth**: permiso `operations.autorizar_salida`.
- **Efecto**: inserta `school_exit_authorizations`.

### POST /operations/permiso
- **Auth**: permiso `operations.permiso`.
- **Efecto**: inserta `class_exit_authorizations` (salida al baño).

### POST /operations/solicitud
- **Auth**: permiso `operations.solicitud`.
- **Efecto**: inserta `internal_messages` (solicitud a otro usuario).

### POST /operations/daño
- **Auth**: permiso `operations.daño`.
- **Efecto**: registra daño institucional.

### POST /operations/pedagogica
- **Auth**: permiso `operations.pedagogica`.
- **Efecto**: inserta `pedagogical_trip_authorizations`.

### POST /operations/horario
- **Auth**: permiso `operations.horario`.
- **Efecto**: notifica cambio de horario de un grupo.

### POST /operations/incidente
- **Auth**: permiso `operations.incidente`.
- **Efecto**: registra incidente disciplinario.

### POST /operations/seguimiento
- **Auth**: permiso `operations.seguimiento`.
- **Efecto**: inserta `student_tracking` (solicita seguimiento al orientador).

### POST /operations/fusionar_bloque
- **Auth**: permiso `operations.fusionar_bloque`.

### POST /operations/extender_bloque
- **Auth**: permiso `operations.extender_bloque`.

### POST /operations/twilio-status
- **Auth**: cualquier rol.
- **Body**: `{ "message_ids": [...] }`.
- **Efecto**: consulta el estado real en Twilio API para esos mensajes y actualiza `twilio_messages.delivery_status`.

## Dispositivos edge

### GET /devices
- **Auth**: `RECTOR`, `COORDINATOR`.
- **200**: lista de `edge_devices` activos de la institución con grupo y usuario asignado.

### POST /devices
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Body**: `{ "name", "location", "group_id", "assigned_user_id" }`.
- **Efecto**: registra un nuevo dispositivo, genera `device_token` (hash bcrypt).

### POST /devices/{device_id}/configure
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Efecto**: configura el dispositivo (asigna grupo/usuario).

### DELETE /devices/{device_id}
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Efecto**: desactiva el dispositivo (`active = FALSE`).

### POST /devices/{device_id}/revocation
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Body**: `{ "master_key" }`.
- **Efecto**: inicia revocación de sensor (`sensor_revocation_requests`), requiere la master key de la escuela (`schools.sensor_master_key_hash`).
- **401**: master key incorrecta. **409**: revocación ya en curso.

### POST /devices/{device_id}/revocation/cancel
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Efecto**: cancela una revocación pendiente.

### GET /devices/revocations/pending
- **Auth**: `RECTOR`, `COORDINATOR`.
- **200**: revocaciones pendientes.

### GET /devices/by-role
- **Auth**: cualquier rol.
- **200**: dispositivos visibles según el rol del usuario.

### GET /devices/commands
- **Auth**: dispositivo edge (valida `X-NEXO-TOKEN` o `device_token` en query).
- **Query**: `device_id`.
- **200**: comandos pendientes para el dispositivo. Efecto: marca `device_commands.delivered_at = NOW()` (fallback PG) o `rPop` de Redis `device:{id}:commands`.

### POST /devices/command/{device_id}
- **Auth**: `RECTOR`, `COORDINATOR`, `SECRETARY`.
- **Body**: `{ "command": "ENROLL_REQUEST|AUTHORIZE_EXIT|DELETE_STUDENT|...", "payload": {...} }`.
- **Efecto**: encola el comando en Redis `device:{id}:commands` o, como fallback, en `device_commands`. Publica por MQTT si está disponible.

### POST /devices/ping
- **Auth**: dispositivo edge.
- **Efecto**: actualiza `edge_devices.last_ping = NOW()`.

### POST /devices/enroll-confirm
- **Auth**: dispositivo edge.
- **Body**: `{ "device_id", "doc", "nombre", "huella_id", "has_fingerprint" }`.
- **Efecto**: confirmación de enrolamiento (fallback cuando el fast path de `/ingest` no aplica).

### POST /devices/{old_device_id}/reconfigure
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Efecto**: reconfigura un dispositivo con un nuevo device_id (reemplazo físico).
- **409**: conflicto.

## Ingesta edge (cualquier path con `payload`)

El endpoint de ingesta no tiene ruta fija: `api.php` detecta la clave `payload` en el body de cualquier petición y la trata como ingesta edge cifrada.

- **Auth**: `device_token` dentro del payload cifrado + `device_id`.
- **Body**: `{ "inst_id": "<school_id>", "payload": "<base64 AES-256-GCM>", "token": "<device_token>" }`. El payload descifrado contiene `{ "action", "device_token", "device_id", "nonce", "request_id", "captured_at", ... }`.
- **Validaciones**: `device_token` contra `edge_devices.token_hash` (bcrypt), `device_id` formato UUID v4, timestamp ±7 días, nonce único (Redis, fail-open si Redis caído).
- **Fast path**: `REGISTER_STUDENT` con `has_fingerprint` se procesa directo en PostgreSQL (upsert `students`).
- **SYNC_ATTENDANCE**: se inserta directo en `biometric_events` (con fingerprint + ON CONFLICT) **y** se encola en `queue:biometric_ingest` para que el worker procese LATE_ARRIVAL/notificaciones.
- **200** (SYNC_ATTENDANCE / REGISTER_STUDENT): `{ "status": "ok", "action", "request_id" }`.
- **202** (otros actions): `{ "status": "accepted", "action", "request_id" }`.
- **Fallback sin Redis**: SYNC_ATTENDANCE se inserta directo en PG; otros actions responden 202 sin encolar.
- **401**: device token inválido. **400**: device_id malformado. **403**: timestamp inválido o nonce duplicado.

## Riesgo

### GET /risk/policy
- **Auth**: `RECTOR`, `COORDINATOR`.
- **200**: política activa de la institución (`risk_policies` + `risk_rules`).

### GET /risk/policy/history
- **Auth**: `RECTOR`, `COORDINATOR`.
- **200**: historial versionado de políticas.

### POST /risk/policy
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Body**: nueva política (crea versión nueva, desactiva la anterior).

### GET /risk/event-types
- **Auth**: `RECTOR`, `COORDINATOR`, `TEACHER`.
- **200**: catálogo `risk_event_types`.

### GET /risk/alerts
- **Auth**: cualquier rol.
- **Query**: `status`, `level`.
- **200**: alertas de riesgo (`risk_alerts`).

### POST /risk/alerts/{alert_id}/resolve
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Efecto**: marca alerta como resuelta.

### POST /risk/alerts/{alert_id}/escalate
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Efecto**: escala el `escalation_state` de la alerta.

### POST /risk/incidents/{incident_id}/resolve
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Efecto**: resuelve un `attendance_incidents` (marca `resolved = TRUE`).

### GET /risk/student/{student_id}
- **Auth**: cualquier rol.
- **200**: evaluación de riesgo del estudiante (`risk_active_snapshot` + métricas).

### GET /risk/anomaly/{student_id}
- **Auth**: `RECTOR`, `COORDINATOR`.
- **200**: detección de anomalías para el estudiante.

### POST /risk/justify
- **Auth**: `RECTOR`, `COORDINATOR`, `TEACHER`.
- **Body**: `{ "student_id", "incident_type", "incident_date", "justification_type", "reason" }`.
- **Efecto**: inserta `risk_justifications` y recalcula riesgo vía `fn_justify_risk_event`.

### POST /risk/recalculate
- **Auth**: `RECTOR`, `COORDINATOR`, `TEACHER`.
- **Efecto**: recalcula riesgo de la escuela o estudiante (`fn_recalculate_school_risk_v3` o `fn_evaluate_student_risk`).

## Configuración de institución (onboarding)

### GET /school/config
- **Auth**: cualquier rol.
- **200**: configuración de la institución (`school_schedule_config`, flags de onboarding).

### POST /school/onboarding
- **Auth**: cualquier rol (con permiso).
- **Efecto**: completa onboarding de horarios (`schools.onboarding_completed = TRUE`).

### PUT /school/config
- **Auth**: cualquier rol (con permiso).
- **Efecto**: actualiza `school_schedule_config` (turnos, horas, receso).

### GET /school/time-blocks
- **Auth**: cualquier rol.
- **200**: bloques horarios (`school_time_blocks`).

### POST /school/time-blocks
- **Auth**: cualquier rol (con permiso).
- **Efecto**: crea/actualiza bloques horarios.

### GET /school/groups-onboarding
- **Auth**: cualquier rol.
- **200**: estado del onboarding de grupos.

### POST /school/groups-onboarding
- **Auth**: cualquier rol (con permiso).
- **Efecto**: completa onboarding de grupos (`schools.groups_onboarding_completed = TRUE`), crea `academic_groups`.

### POST /school/assign-teacher | DELETE /school/assign-teacher
- **Auth**: cualquier rol (con permiso).
- **Efecto**: asigna/remueve docente a grupo en `teacher_group_access`.

### GET /school/teachers
- **Auth**: cualquier rol.
- **200**: docentes con sus grupos asignados.

### POST /school/sensor-master-key
- **Auth**: cualquier rol (con permiso).
- **Efecto**: setea `schools.sensor_master_key_hash` (bcrypt) para revocación de sensores.

### GET /school/risk-config
- **Auth**: cualquier rol.
- **200**: estado de configuración de riesgo.

### POST /school/risk-config
- **Auth**: cualquier rol (con permiso).
- **Efecto**: completa onboarding de riesgo (`schools.risk_config_completed = TRUE`), siembra política vía `fn_seed_default_risk_policy`.

### GET /school/technical-modality
- **Auth**: cualquier rol.
- **200**: configuración de modalidad técnica (`technical_modality_config`).

### POST /school/technical-modality
- **Auth**: cualquier rol (con permiso).

### POST /school/seed
- **Auth**: `RECTOR`.
- **Efecto**: ejecuta `backend/api/scripts/seed_school.php` para poblar datos de demo.

### POST /school/simulate
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Efecto**: ejecuta `backend/scripts/simulate.php` para simular eventos.

### GET /school/simulate (acción)
- **Auth**: `RECTOR`, `COORDINATOR`.
- **Query**: `action` (list_students/finger/change_class/mark_absent/evasion/late/bathroom/risk_high/tracking/resolve_evasion/stats/clear_today).
- **200**: resultado de la acción de simulación.

## Consultas

### GET /consultations/query
- **Auth**: cualquier rol (con permiso `consultations.teacher_view` o `consultations.global_view`).
- **Query**: `type` (group_students/late_arrivals/absences/active_permissions/attendance_history/incidents/student_tracking_active/student_tracking_completed/sent_messages/internal_messages/biometric_spam/issued_permissions/school_exits/pedagogical_trips/all_groups/all_teachers/all_students/all_guardians/institutional_metrics/staff/staff_auxiliary/staff_security/reports/justified_absences/unjustified_absences/evasions/sos_emitted/damages_reported/critical_situations).
- **200**: datos de la consulta filtrados por rol (docente ve sus grupos, rector/coordinador ven todo).

### GET /consultation/search
- **Auth**: cualquier rol.
- **Query**: `q`, `type`.
- **200**: resultados de búsqueda.

## Notificaciones

### GET /notifications
- **Auth**: cualquier rol.
- **Query**: `unreadOnly`, `limit`.
- **200**: notificaciones in-app del usuario (`notifications`).

### POST /notifications/clear
- **Auth**: cualquier rol.
- **Efecto**: marca todas como leídas.

### POST /notifications/{notification_id}/action
- **Auth**: cualquier rol.
- **Body**: `{ "action": "string" }`.
- **Efecto**: ejecuta la acción asociada a la notificación.

## Reportes

### GET /reports/preview
- **Auth**: cualquier rol (con permiso `reports.preview`).
- **Query**: `type`, `group_id`, `from`, `to`.
- **200**: preview del reporte.

## Tracking (seguimiento)

### POST /tracking/start
- **Auth**: cualquier rol (con permiso `tracking.manage`).
- **Efecto**: inserta `student_tracking` (abre caso de seguimiento).

### POST /tracking/notes
- **Auth**: cualquier rol (con permiso `tracking.manage`).
- **Efecto**: inserta `student_tracking_notes`.

### GET /tracking/details
- **Auth**: cualquier rol.
- **Query**: `tracking_id`.
- **200**: detalle del caso.

### GET /tracking/active
- **Auth**: cualquier rol.
- **200**: casos de seguimiento activos.

## Auditoría

Todos requieren `RECTOR` o `COORDINATOR` (permiso `audit.view`). Son `GET` con query params de filtro (fechas, grupo, estudiante, etc.).

### GET /audit/global
Logs globales (`global_audit_logs`).

### GET /audit/integrity
Valida la cadena de hashes (`fn_validate_audit_chain`).

### GET /audit/attendance/{general|absences|lates|evasion|by-group|by-student}
Auditoría de asistencia.

### GET /audit/discipline/{incidents|violations|wrong-classroom|biometric-spam|reports|student-history}
Auditoría disciplinaria.

### GET /audit/permissions/{class-exits|school-exits|pedagogical|pending-returns|history}
Auditoría de permisos y salidas.

### GET /audit/messaging/{whatsapp-sent|guardian-replies|failed|citations|internal|conversations}
Auditoría de mensajería.

### GET /audit/teacher/{activity|classes|permissions|incidents|system-activity}
Auditoría de actividad docente.

### GET /audit/security/{global|accesses|sessions|commands|admin-activity|failed-attempts}
Auditoría de seguridad.

### GET /audit/sos/{alerts|resolved|resolution-time|history}
Auditoría de SOS.

### GET /audit/historical/{student|teacher|attendance|discipline|permissions|messaging|search|download|download-consolidated}
Reportes históricos y descargas.

### GET /audit/consolidated/{attendance|discipline|permissions|messaging|teacher|security|institutional}
Reportes consolidados.

### GET /audit/groups
- **Query**: `academic_year`.

### GET /audit/groups/{group_id}/students
Estudiantes de un grupo.

### GET /audit/staff
Personal de la institución.

## Seguridad

### POST /security/panic
- **Auth**: cualquier rol (con permiso `security.panic`).
- **Efecto**: activa modo pánico (`school_panic_events`), desactiva dispositivos en cascada. Verifica `isSchoolInPanicMode()` (Redis, fail-open si caído).

## Comportamiento

### GET /behavior/risk
- **Auth**: cualquier rol.
- **Query**: `student_id`, `group_id`.
- **200**: métricas de riesgo conductual (`student_behavior_metrics`).

## Admin

### POST /admin/recalc-risk | GET /admin/recalc-risk
- **Auth**: `RECTOR`, `COORDINATOR` (permiso `admin.recalc_risk`).
- **Efecto**: recalcula riesgo de toda la escuela (`fn_recalculate_school_metrics` / `fn_recalculate_school_risk_v3`).

## Métricas y telemetría

### GET /metrics
- **200**: métricas del sistema (requiere auth, valida token).

### POST /telemetry
- **Auth**: cualquier rol.
- **Body**: `{ "session_id", "event_type", "payload", "severity" }`.
- **Efecto**: inserta `system_telemetry`.

## Contacto (landing)

### POST /contacto
- **No requiere auth.**
- **Body**: `{ "name", "whatsapp", "email", "institution", "message" }`.
- **Efecto**: inserta `contact_leads`, encola WhatsApp al owner si `NEXO_OWNER_WHATSAPP` está seteado.
- **429**: rate limit (Redis, fail-closed → 503 si Redis caído).

## Webhooks Twilio

### POST /webhooks/twilio/status
- **No requiere auth** (valida firma Twilio).
- **Efecto**: actualiza `twilio_messages.delivery_status` con el delivery receipt.

### POST /webhooks/twilio/inbound
- **No requiere auth** (valida firma Twilio).
- **Efecto**: procesa mensajes entrantes del acudiente (respuestas a citaciones, inasistencias, etc.) usando el contexto de conversación en Redis.

### GET /webhooks/twilio/status
- Variante GET del webhook de status.

## Health

### GET /health | GET /health/workers
- **No requiere auth.**
- **200**: `{ "database": {...}, "redis": {...}, "workers": {...}, "disk": {...} }` con estado de cada componente. Los workers se verifican via heartbeat en Redis (`worker:*:last_heartbeat`).

## CORS

Gestionado por `routes/_cors_middleware.php`. `CORS_ALLOW_ORIGINS` lista los orígenes permitidos. Permite credentials (cookies). Métodos y headers estándar. Preflight cacheado.

## Endpoints legacy / redirecciones

- `/auditoria` y `/riesgo` en la PWA redirigen a `/consulta` y `/perfil` respectivamente (ver `PWA/src/App.jsx`). No son endpoints de API.
- No hay endpoints de API marcados como deprecated en el código actual.

## Variables de entorno relevantes para la API

Ver `backend/api/.env.example`. Las que afectan al comportamiento de la API:

- `DATABASE_URL` o `PGHOST`/`PGPORT`/`PGDATABASE`/`PGUSER`/`PGPASSWORD` — conexión PostgreSQL.
- `REDISHOST`/`REDISPORT`/`REDIS_PASSWORD`/`REDIS_TLS`/`REDIS_URL` — Redis (opcional, fallback PG).
- `NEXO_AES_KEY` — clave AES-256 para cifrado de payloads edge.
- `JWT_SECRET` (HS256) o `JWT_PRIVATE_KEY`/`JWT_PUBLIC_KEY` (RS256) — firma JWT.
- `JWT_ISSUER`, `JWT_AUDIENCE`, `JWT_KEY_ID`, `JWT_ACCESS_TTL_SECONDS` (default 900), `JWT_REFRESH_TTL_SECONDS` (default 604800).
- `CORS_ALLOW_ORIGINS` — orígenes permitidos.
- `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_WHATSAPP_TEMPLATE_SID`, `TWILIO_WEBHOOK_URL_BASE`, `NEXO_OWNER_WHATSAPP`.
- `MQTT_USER`, `MQTT_PASS` — autenticación del broker Mosquitto del contenedor.
- `APP_NEXO_HMAC_SECRET` / `NEXO_HMAC_SECRET` — secreto de la cadena de auditoría.
- `APP_ENV`, `LOGIN_2FA_ENABLED`, `AUDIT_WORKER_ENABLED`.
- Workers: `ABSENCE_DETECTOR_MODE`, `ABSENCE_CHECK_INTERVAL`, `EVASION_DETECTOR_MODE`, `EVASION_CHECK_INTERVAL`, `PERMISSION_STATUS_MODE`, `PERMISSION_CHECK_INTERVAL`, `BIOMETRIC_DEDUP_WINDOW_SECONDS`, `BIOMETRIC_GC_MAX_AGE`, `TWILIO_RATE_LIMIT`, `TWILIO_MAX_SENDS_PER_HOUR`, `TWILIO_MAX_DAILY_PER_PHONE`.
