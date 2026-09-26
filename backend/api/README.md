# API Backend de NEXO — Documentación técnica

Documentación exhaustiva del backend PHP 8 de NEXO (`backend/api/`). Toda la
información aquí se deriva del código fuente real; documentos complementarios:
[docs/SECURITY.md](../../docs/SECURITY.md),
[docs/DEPLOYMENT.md](../../docs/DEPLOYMENT.md) y
[docs/nexus/NEXUS.md](../../docs/nexus/NEXUS.md) (subsistema conversacional,
fuera del alcance de este documento salvo su entry point HTTP).

---

## 1. Visión general y topología

NEXO es una plataforma de control de asistencia y riesgo escolar multi-tenant.
El backend sirve dos canales sobre el mismo proceso:

- **REST + JWT** para la PWA (personal de la institución: rector, coordinación,
  docentes, secretaría, etc.).
- **Canal cifrado AES-256-GCM** para los nodos edge (Raspberry Pi con sensor
  biométrico) que reportan eventos de huella.

### Topología del contenedor y sus dependencias

```
                 ┌──────────────┐   HTTPS/JSON     ┌───────────────┐
   PWA (Vercel) ─┤              │─────────────────►│               │
                 │    nginx     │                  │   PHP-FPM 8   │
   Landing    ──►│  :8080       │─── catch-all ───►│  api.php      │
   (/contacto)   │              │                  │  health.php   │
                 └──────────────┘                  └──────┬────────┘
   Edge (RPi4) ──► cualquier path con body {payload:      │
        AES-256-GCM cifrado}                              │
                 ┌────────────────────────────────────────┼─────────┐
                 │  mismo contenedor:                     ▼         │
                 │   workers PHP (daemon/periodic)   PostgreSQL     │
                 │   mosquitto (MQTT 127.0.0.1:1883)  via PgBouncer │
                 │   supercronic (crontab)           Redis/Upstash  │
                 └─────────────────────────────────────────────────┘
```

| Componente | Rol | Dónde vive |
|---|---|---|
| nginx | Terminación HTTP, estáticos PWA (`public/`), proxy a PHP-FPM por socket Unix | Dentro del contenedor API |
| PHP-FPM | Ejecuta `api.php`/`health.php` (`pm.dynamic`, máx. 10 children) | Contenedor API |
| PgBouncer | Pool de conexiones en modo **transaction** hacia PostgreSQL | `infra/pgbouncer/` (contenedor aparte en dev) |
| PostgreSQL | Datos persistentes + RLS multi-tenant + funciones de negocio (`fn_*`) | Externo (`DATABASE_URL`/`PG*`) |
| Redis | Broker de colas, dedup, blocklist JWT, rate limiting, caché | Externo (`REDIS_URL`/`REDIS*`), opcional |
| Mosquitto | Broker MQTT local para comandos a nodos edge (`nexo/devices/{id}/commands`) | Contenedor API |
| Workers PHP | 10 procesos de cola/periódicos + `contingency_lib.php` | `workers/`, lanzados por `docker-entrypoint.sh` |
| supercronic | Cron dentro del contenedor (particiones, recálculo de riesgo, purga, keep-alive) | `infra/scripts/crontab` |

El contenedor se construye con `Dockerfile` (PHP 8.2-fpm + nginx + mosquitto +
supercronic + extensiones `pdo_pgsql redis sockets zip pcntl mysqli`) y arranca
con `docker-entrypoint.sh`.

---

## 2. Entry point y envelope

### `api.php` — front controller único

Flujo de cada petición (`api.php:51-270`):

1. Headers de seguridad (`nosniff`, `X-Frame-Options: DENY`, HSTS, CSP
   `frame-ancestors 'none'`, `Referrer-Policy`).
2. `core/boot_check.php` — fail-closed si falta configuración crítica.
3. `core/db.php` — conexión PDO a PostgreSQL (`$pdo`, alias `$conn`).
4. Rate limiting global sobre métodos mutantes (POST/PUT/DELETE/PATCH),
   Redis `rl:ip:{md5}`/`rl:u:{user}:{md5}`, defaults `RATE_LIMIT_MAX=100`,
   `RATE_LIMIT_WINDOW=60`.
5. Rate limiting específico por endpoint sensible (`api.php:189-225`):

   | Endpoint | Máx | Ventana | Scope |
   |---|---|---|---|
   | `/auth/verify-2fa` | 10 | 300 s | IP |
   | `/users/send-verification` | 5 | 600 s | usuario |
   | `/contacto` | 3 | 3600 s | IP |

6. Normalización de path: se acepta `/v1/*` y `/api.php/*` como prefijos
   (`api.php:180`).
7. Si el body trae clave `payload` → **ingesta edge cifrada** (ver §2.2).
8. Si `/health` o `/health/workers` → chequeo detallado (ver §11).
9. Enrutado por primer segmento vía `$routeMap` a `routes/*.php`. Si ningún
   archivo consume la ruta → `404`.

> `routes/operations.php` se incluye **siempre** (`api.php:228`) porque exporta
> helpers compartidos (`enqueueTwilioJob`, etc.) usados por otras rutas.

### Envelope REST

- Request: `application/json`; auth por `Authorization: Bearer <JWT>` o cookie
  HttpOnly `token`.
- CSRF: los métodos mutantes exigen `X-Requested-With: XMLHttpRequest`
  (`requireAuth()`, `_auth_middleware.php:602-607`) → `403` si falta.
- Respuesta: `{"status": "ok"|"error"|"accepted"|"2fa_required", ...}`.
- Errores: `400` validación · `401` auth · `403` permiso/CSRF · `404` ·
  `409` conflicto · `428` onboarding incompleto · `429` rate limit ·
  `500` interno · `503` dependencia caída.

### Canal cifrado edge (`api.php:276-555`)

El edge no tiene ruta fija: cualquier body JSON con `payload` se interpreta
como ingesta cifrada.

- **Formato**: `{ "payload": "<base64>", "inst_id"?, "token"? }` donde
  `payload = base64( iv(12B) ‖ ciphertext ‖ tag(16B) )`, AES-256-GCM con
  `NEXO_AES_KEY`. El JSON descifrado trae `action`, `device_id` (UUID v4),
  `device_token`, `nonce`, `captured_at`, `request_id` y datos del evento.
- **Validaciones**: `device_id` UUID v4 (400), `password_verify(device_token,
  edge_devices.token_hash)` (401), `|now - captured_at| ≤ 7 días` (403),
  nonce único en Redis `SET NX EX 604800` (403; fail-open si Redis cae).
- Tras validar, fija contexto RLS: `app.current_school_id` +
  `app.current_role='EDGE_NODE'`.
- **Fast paths**:
  - `REGISTER_STUDENT` con `has_fingerprint` → upsert directo en `students`
    con `biometric_hash = 'fp_<huella_id>'`, responde 200 inmediato.
  - `SYNC_ATTENDANCE` → insert directo en `biometric_events` con
    `event_fingerprint` (SHA-256 de school+doc+event+ts) y
    `ON CONFLICT ... DO NOTHING` (dedup), reconciliación de inasistencia vía
    `nexoReconcileAbsence()`, **además** encola en `queue:biometric_ingest`.
- **Degradación sin Redis**: `SYNC_ATTENDANCE` se procesa igual en PG; otros
  actions responden `202 {"warning":"redis_down"}` sin encolar.
- Respuestas: `200 ok` (SYNC_ATTENDANCE, REGISTER_STUDENT), `202 accepted`
  (resto), `401` token inválido, `403` replay/timestamp, `400` formato.

### `health.php` — health check autónomo

Endpoint sin autenticación (ver §11).

---

## 3. Autenticación y autorización

Centralizado en `routes/_auth_middleware.php`.

### JWT

- **Emisión** (`issueJwtToken`): RS256 si `JWT_PRIVATE_KEY` es PEM RSA válido;
  fallback HS256 con `JWT_SECRET`. Claims: `iss` (`JWT_ISSUER`, default
  `nexo-api`), `aud` (`JWT_AUDIENCE`, default `nexo-webapp`), `iat`, `nbf`
  (iat − 2 s por clock skew), `exp`, `jti` (16 bytes aleatorios), `sub`,
  `role`, `school_id`. Header incluye `kid` (`JWT_KEY_ID`).
- **Verificación** (`verifyJwtToken`): firma (RS256/HS256, otros algoritmos
  rechazados), `iss`/`aud`, `exp`/`nbf`/`iat` con tolerancia 10 s, `sub` y
  `jti` obligatorios, y en una sola ronda `MGET` a Redis
  (`checkJwtAndPanicState`):
  - `jwt:blocklist:{jti}` → token revocado (fallback a tabla `jwt_blocklist`).
  - `panic:school:{school_id}` → si el modo pánico se activó después del
    `iat`, la sesión queda invalidada (fallback a `school_panic_events`).
- **Extracción** (`extractBearerToken`): `Authorization`, cabeceras
  redirect/Apache y, como último recurso, cookie `token`.
- **Refresh**: tokens opacos hasheados en `user_sessions.refresh_token_hash`
  con rotación real en `/auth/refresh`.

### Roles (RBAC)

Catálogo canónico (`_auth_middleware.php:81-91`): `RECTOR`, `COORDINATOR`,
`TEACHER`, `SECRETARY`, `SECURITY`, `AUXILIARY`, `COUNSELOR`, `GUARDIAN`.
`normalizeRole()` mapea legados (`PRINCIPAL`→`RECTOR`,
`PSYCHOLOGIST`→`COUNSELOR`). Roles internos de sistema (no emitidos a usuarios
PWA): `SYSTEM_WORKER` (workers/webhooks) y `EDGE_NODE` (ingesta/comandos edge).

`requireAuth($allowedRoles)`:
1. Exige `X-Requested-With` en métodos mutantes.
2. Verifica JWT y abre transacción (PgBouncer transaction-pooling exige
   `set_config(..., is_local=true)`).
3. Carga usuario + `role_permissions` → devuelve
   `{id, email, nombre, role, role_id, school_id, school_name,
   profile_photo_url, work_shift, claims, permissions}`.
4. Fija `app.current_school_id` y `app.current_role` para RLS.
5. `403` si el rol no está en la lista permitida; `401` si el token falla.

La autorización fina se hace con `permissions` (ej.
`in_array('operations.'.$action, $authUser['permissions'])` en
`operations.php:273`). `requireSchoolOnboarding()` responde `428` si la escuela
no completó la configuración obligatoria.

## 4. Mapa completo de endpoints

Dispatch por primer segmento (`$routeMap`, `api.php:231-257`). Dentro de cada
archivo el dispatch es por `$cleanPath`/`$method` (`===` o `preg_match`).
«Auth» = `requireAuth()` sin restricción de rol; «Edge» = token de dispositivo
(`X-Device-Token` o `device_token` dentro del payload cifrado).

### `routes/auth.php` — sesión y cuenta

| Método | Endpoint | Auth | Propósito |
|---|---|---|---|
| POST | `/auth/login` | público | Login email+password; throttle `isLoginThrottled`; 202 `2fa_required` si `LOGIN_2FA_ENABLED` y teléfono verificado (OTP por WhatsApp); crea `user_sessions`, rehash bcrypt si cost<12 |
| POST | `/auth/verify-2fa` | público | Completa login con código OTP (`verification_codes`) |
| POST | `/auth/logout` | opcional | Revoca sesión (`refresh_token_hash`), añade `jti` a blocklist, expira cookies |
| GET | `/auth/me` | auth | Devuelve el usuario actual |
| POST | `/auth/refresh` | público | Rota refresh token, emite nuevo access JWT |

### `routes/users.php` — perfil y directorio

Todo el archivo exige `requireAuth()` (línea 38).

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/users/by-role` | directorio (RECTOR, COORDINATOR, SECRETARY, TEACHER, COUNSELOR) | Lista usuarios de la escuela por rol (`?role=`, `?same_shift=1`) |
| GET | `/users/me/extended` | auth | Perfil extendido (staff/guardian según rol) |
| POST | `/users/upload-photo` | auth | Sube foto de perfil (multipart) |
| POST | `/users/send-verification` | auth | Envía OTP por WhatsApp (`verification_codes`); rate limit 5/600 s |
| POST | `/users/verify-code` | auth | Verifica código OTP |
| POST | `/users/update-profile` | auth | Actualiza campos del perfil |
| POST | `/users/delete-field` | auth | Elimina un campo del perfil |
| POST | `/users/change-password` | auth | Cambio de contraseña (re-verifica la actual) |
| POST | `/users/reset-password` | auth | Reset con código `password_reset` de `verification_codes` |
| GET | `/users/me/photo` | auth | Devuelve la foto de perfil |

### `routes/students.php`

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/students` | auth | Lista paginada (`limit`≤100, `search`, `group_name`, …) filtrada por RLS |
| POST | `/students` | SECRETARY, RECTOR, COORDINATOR | Crea estudiante + asignación de grupo + acudiente; soporta `biometric_exempt` |
| POST | `/students/bulk-assign` | RECTOR, SECRETARY | Asignación masiva a grupos |
| DELETE | `/students/{id}` | SECRETARY, RECTOR, COORDINATOR | Soft-delete (`deleted_at`, `active=FALSE`) |
| POST | `/students/{id}/consent` | SECRETARY, RECTOR, COORDINATOR | Registra consentimiento biométrico |

### `routes/groups.php`

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/groups` | auth | Grupos académicos con conteo de estudiantes; `?teacher_only=1` filtra a grupos del docente |

### `routes/dashboard.php`

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/dashboard/stats` | auth | Estadísticas de asistencia por rol/grupo; caché Redis `dashboard:stats:*` |
| GET | `/dashboard/teacher-group-detail` | auth | Detalle de grupo para docente |
| GET | `/dashboard/events` | auth | Feed de eventos operacionales (`?type=`) |
| GET | `/dashboard/insights` | auth | Tarjetas Nexus Insights (lib/insights.php); caché 60 s |

### `routes/operations.php` — comandos operativos

Cargado siempre por `api.php`. Dispatcher en `operations.php:179-260`: acepta
`POST /operations/<comando>` y body `{action:'EXECUTE_COMMAND'}`. Cada comando
requiere el permiso `operations.<accion>` (`operations.php:273`).

| Método | Endpoint | Permiso | Propósito |
|---|---|---|---|
| POST | `/operations/twilio-status` | auth | Consulta masiva de estado real en Twilio y actualiza `twilio_messages.delivery_status` |
| POST | `/operations/sos` | `operations.sos` | Alerta SOS (`sos_alerts`) + WhatsApp al rol superior |
| POST | `/operations/situacion_critica` | `operations.situacion_critica` | Notifica a rector+coordinación |
| POST | `/operations/inasistencia` | `operations.inasistencia` | `attendance_incidents` INASISTENCIA + WhatsApp al acudiente |
| POST | `/operations/citacion` | `operations.citacion` | Citación al acudiente por WhatsApp |
| POST | `/operations/salida` | `operations.autorizar_salida` | `school_exit_authorizations` |
| POST | `/operations/permiso` | `operations.permiso` | `class_exit_authorizations` (salida de aula) |
| POST | `/operations/solicitud` | `operations.solicitud` | `internal_messages` a otro usuario |
| POST | `/operations/daño` | `operations.daño` | Registro de daño institucional |
| POST | `/operations/pedagogica` | `operations.pedagogica` | `pedagogical_trip_authorizations` |
| POST | `/operations/horario` | `operations.horario` | Notifica cambio de horario de un grupo |
| POST | `/operations/incidente` | `operations.incidente` | Incidente disciplinario |
| POST | `/operations/seguimiento` | `operations.seguimiento` | `student_tracking` (caso al orientador) |
| POST | `/operations/fusionar_bloque` | `operations.fusionar_bloque` | Fusión de bloques horarios |
| POST | `/operations/extender_bloque` | `operations.extender_bloque` | Extensión de bloque horario |
| POST | `/operations/registro_manual` | `operations.registro_manual` | Registro manual de asistencia |
| POST | `/operations/registro_manual_pendiente` | `operations.registro_manual_pendiente` | Registro manual pendiente de validación |

Las acciones que requieren presencia (`permiso`, `autorizar_salida`, `horario`)
validan que el estudiante no tenga INASISTENCIA abierta hoy.

### `routes/devices.php` — gestión de nodos edge

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/devices` | RECTOR, COORDINATOR | Lista dispositivos activos de la escuela |
| POST | `/devices` | RECTOR, COORDINATOR | Registra dispositivo; genera `device_token` (bcrypt) |
| POST | `/devices/{id}/configure` | RECTOR, COORDINATOR | Asigna grupo/usuario/aula al nodo |
| DELETE | `/devices/{id}` | RECTOR, COORDINATOR | Desactiva (`active=FALSE`) |
| POST | `/devices/{id}/revocation` | RECTOR, COORDINATOR | Inicia revocación de sensor; exige `master_key` (`schools.sensor_master_key_hash`) |
| POST | `/devices/{id}/revocation/cancel` | RECTOR, COORDINATOR | Cancela revocación pendiente |
| GET | `/devices/revocations/pending` | RECTOR, COORDINATOR | Revocaciones pendientes |
| GET | `/devices/by-role` | auth | Dispositivos visibles según rol |
| POST | `/devices/command/{id}` | RECTOR, COORDINATOR, SECRETARY | Encola comando (`ENROLL_REQUEST`, `AUTHORIZE_EXIT`, `DELETE_STUDENT`, …): Redis `device:{id}:commands` → fallback tabla `device_commands`; publica MQTT si disponible |
| GET | `/devices/commands` | edge (`X-Device-Token`) | Polling de comandos pendientes; marca `delivered_at`; reintenta ×3 ante fallos del pool |
| POST | `/devices/ping` | edge | Heartbeat: `edge_devices.last_ping=NOW()` |
| POST | `/devices/enroll-confirm` | edge | Confirmación de enrolamiento (fallback del fast path) |
| GET | `/admin/devices` | RECTOR | Vista administrativa de dispositivos |
| POST | `/devices/{id}/reconfigure` | RECTOR, COORDINATOR | Reconfigura con nuevo `device_id` (reemplazo físico) |
| GET | `/devices/ota/check` | edge | Consulta manifiesto OTA firmado (HMAC por dispositivo) |
| POST | `/devices/ota/report` | edge | Reporta etapa/resultado → `ota_deployments` |
| POST | `/devices/ota/publish` | RECTOR, COORDINATOR | Publica nueva versión OTA |
| POST | `/devices/ota/revoke` | RECTOR, COORDINATOR | Revoca despliegue OTA |
| POST | `/devices/reassign` | RECTOR, COORDINATOR | Reasigna dispositivo a otro grupo/aula |
| POST | `/devices/reprovision` | RECTOR, COORDINATOR | Re-aprovisiona credenciales del nodo |

### `routes/audit_full.php` — auditoría

Guardia al inicio (`audit_full.php:27-31`): solo `/audit/*` y
`requireAuth(['RECTOR','COORDINATOR'])` — **todos** los endpoints son GET y
exigen RECTOR o COORDINATOR.

| Endpoint | Propósito |
|---|---|
| `/audit/global` | Logs globales (`global_audit_logs`) |
| `/audit/integrity` | Valida la cadena HMAC (`fn_validate_audit_chain`) |
| `/audit/attendance/{general,absences,lates,evasion,by-group,by-student}` | Auditoría de asistencia |
| `/audit/discipline/{incidents,violations,wrong-classroom,biometric-spam,reports,student-history}` | Disciplina |
| `/audit/permissions/{class-exits,school-exits,pedagogical,pending-returns,history}` | Permisos y salidas |
| `/audit/messaging/{whatsapp-sent,guardian-replies,failed,citations,internal,conversations}` | Mensajería |
| `/audit/teacher/{activity,classes,permissions,incidents,system-activity}` | Actividad docente |
| `/audit/security/{global,accesses,sessions,commands,admin-activity,failed-attempts}` | Seguridad |
| `/audit/sos/{alerts,resolved,resolution-time,history}` | Alertas SOS |
| `/audit/historical/{student,teacher,attendance,discipline,permissions,messaging,search,download,download-consolidated}` | Históricos y descargas |
| `/audit/consolidated/{attendance,discipline,permissions,messaging,teacher,security,institutional}` | Consolidados |
| `/audit/groups`, `/audit/groups/{id}/students`, `/audit/staff` | Catálogos de soporte |

### `routes/risk.php` — motor de riesgo

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/risk/policy` | RECTOR, COORDINATOR | Política activa (`risk_policies`+`risk_rules`) |
| GET | `/risk/policy/history` | RECTOR, COORDINATOR | Historial versionado de políticas |
| POST | `/risk/policy` | RECTOR, COORDINATOR | Nueva versión de política |
| GET | `/risk/event-types` | RECTOR, COORDINATOR, TEACHER | Catálogo `risk_event_types` |
| GET | `/risk/alerts` | auth | Alertas (`?status`, `?level`) |
| POST | `/risk/alerts/{id}/resolve` | RECTOR, COORDINATOR | Resuelve alerta |
| POST | `/risk/alerts/{id}/escalate` | RECTOR, COORDINATOR | Escala `escalation_state` |
| POST | `/risk/incidents/{id}/resolve` | RECTOR, COORDINATOR | Resuelve `attendance_incidents` |
| GET | `/risk/student/{id}` | auth | Snapshot de riesgo del estudiante |
| GET | `/risk/anomaly/{id}` | RECTOR, COORDINATOR | Anomalía estadística (z-score) |
| POST | `/risk/justify` | RECTOR, COORDINATOR, TEACHER | `risk_justifications` + `fn_justify_risk_event` |
| POST | `/risk/recalculate` | RECTOR, COORDINATOR | `fn_recalculate_school_risk_v3` / `fn_evaluate_student_risk` |

### `routes/behavior.php`

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/behavior/risk` | auth | Estudiantes con riesgo HIGH/CRITICAL (`student_behavior_metrics`, RiskScoreEngine) |

### `routes/school_config.php` — configuración institucional

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/school/config` | auth | `school_schedule_config` + flags de onboarding |
| POST | `/school/onboarding` | RECTOR, COORDINATOR | Completa onboarding de horarios/jornadas |
| PUT | `/school/config` | auth* | Actualiza configuración de horarios (*el handler aplica el rol) |
| GET | `/school/time-blocks` | auth | Bloques horarios |
| POST | `/school/time-blocks` | auth* | Crea/actualiza bloques |
| GET | `/school/groups-onboarding` | auth | Estado del onboarding de grupos |
| POST | `/school/groups-onboarding` | RECTOR | Crea grupos por grado/nomenclatura, asigna docentes |
| POST/DELETE | `/school/assign-teacher` | RECTOR | Asigna/remueve docente en `teacher_group_access` |
| GET | `/school/teachers` | RECTOR, COORDINATOR | Docentes con grupos asignados |
| POST | `/school/sensor-master-key` | RECTOR | Fija `schools.sensor_master_key_hash` |
| GET | `/school/risk-config` | auth | Estado de configuración de riesgo |
| POST | `/school/risk-config` | RECTOR | Completa onboarding de riesgo (`fn_seed_default_risk_policy`) |
| GET | `/school/technical-modality` | auth | `technical_modality_config` por grado/jornada |
| POST | `/school/technical-modality` | RECTOR, COORDINATOR | Actualiza modalidad técnica |
| GET | `/school/classrooms` | auth | Aulas (`classrooms`) |
| POST/DELETE | `/school/classrooms` | RECTOR, COORDINATOR | Alta/baja de aulas |
| GET | `/school/subjects` | auth | Materias |
| POST | `/school/subjects` | RECTOR, COORDINATOR, SECRETARY | Crea materia |
| GET | `/school/schedules` | auth | Horarios por grupo |
| POST/DELETE | `/school/schedules` | RECTOR, COORDINATOR, SECRETARY | Alta/baja de bloques de horario |
| GET | `/school/spatial-enforcement` | auth | Config de control espacial |
| POST | `/school/spatial-enforcement` | RECTOR, COORDINATOR | Actualiza control espacial |

### `routes/security_panic.php`

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| POST | `/security/panic` | RECTOR, COORDINATOR | Modo emergencia: desactiva edge_devices en cascada, inserta `school_panic_events` (invalida JWT emitidos antes), marca `panic:school:{id}` en Redis |

### `routes/consultations.php` — motor de consultas

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| POST | `/consultations/query` | auth + `consultations.teacher_view`/`consultations.global_view` | Consulta por `type`; docentes ven sus grupos, `global_view` ve toda la escuela; exige onboarding completo (428) |

Tipos soportados (`case` en `consultations.php:119-799`): `group_students`,
`late_arrivals`, `absences`, `active_permissions`, `attendance_history`,
`incidents`, `student_tracking_active`, `student_tracking_completed`,
`sent_messages`, `internal_messages`, `biometric_spam`, `issued_permissions`,
`school_exits`, `pedagogical_trips`, `all_groups`, `all_teachers`,
`all_students`, `all_guardians`, `institutional_metrics`, `staff`,
`staff_auxiliary`, `staff_security`, `reports`, `justified_absences`,
`unjustified_absences`, `evasions`, `sos_emitted`, `damages_reported`,
`critical_situations`.

### `routes/misc.php` — misceláneos

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| POST | `/contacto` | público | Lead de la landing (`contact_leads`); rate limit 5/h **fail-closed** (503 si Redis cae); WhatsApp al owner si `NEXO_OWNER_WHATSAPP` |
| GET | `/notifications` | auth | Notificaciones in-app (`?unreadOnly`, `?limit`) |
| POST | `/notifications/{id}/read` | auth | Marca una como leída |
| POST | `/notifications/read-all` | auth | Marca todas como leídas |
| POST | `/notifications/clear` | auth | Limpia notificaciones |
| POST | `/notifications/{id}/action` | auth | Ejecuta acción asociada a la notificación |
| GET | `/consultation/search` | auth | Búsqueda de estudiantes (`?q=`) |
| GET | `/reports/preview` | RECTOR, COORDINATOR | Vista previa de reportes (`?from`,`?to`) |
| POST | `/webhooks/twilio/inbound` | firma Twilio | Mensajes entrantes del acudiente (respuestas a citaciones/inasistencias); contexto de conversación en Redis |

### `routes/twilio_delivery.php`

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/webhooks/twilio/status` | público | Sondeo del webhook |
| POST | `/webhooks/twilio/status` | firma Twilio (`verifyTwilioSignature`, misc.php:42) | Delivery receipts → `twilio_messages.delivery_status`; RLS bypass con `app.current_role='SYSTEM_WORKER'` |

### `routes/tracking.php` — seguimiento de casos

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| POST | `/tracking/start` | COORDINATOR, RECTOR, COUNSELOR | Abre caso (`student_tracking`) |
| POST | `/tracking/derive` | auth | Deriva caso a otro profesional |
| POST | `/tracking/notes` | auth | Nota en `student_tracking_notes` (`status`: en proceso/resuelto/descartado/escalado) |
| POST | `/tracking/close` | auth | Cierra caso (`outcome`: resuelto/descartado/escalado) |
| GET | `/tracking/details` | auth | Detalle del caso con notas |
| GET | `/tracking/active` | auth | Casos activos |

### `routes/teacher_alerts.php` — reglas de aviso docente

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/teacher/alert-rules` | TEACHER, COORDINATOR, RECTOR | Lista reglas «N eventos en M días» |
| POST | `/teacher/alert-rules` | TEACHER | Crea regla |
| PUT/DELETE | `/teacher/alert-rules/{id}` | TEACHER | Edita/elimina regla |
| GET/POST | `/teacher/onboarding` | TEACHER | Estado/completado del onboarding docente |

### `routes/telemetry.php`

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| POST | `/telemetry` | auth | Ingesta `events[]` + `session_id` (UUID v4) + `platform` (web/desktop/android/ios) → `system_telemetry`; sanitiza PII y trunca strings |

### `routes/events.php` — SSE

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/events/stream` | auth | Server-Sent Events de notificaciones nuevas (poll BD 2 s, keep-alive 15 s, máx. 300 s por conexión; `X-Accel-Buffering: no`) |

### `routes/metrics.php`

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET | `/metrics` | `METRICS_SECRET_KEY` (header `X-Metrics-Key` o `?key=`; 401 si no configurada) | Exposición Prometheus: `nexo_db_*`, `nexo_worker_*`, `nexo_queue_length`, `nexo_auth_logins_total`, `nexo_biometric_events_today`, `nexo_twilio_*`, `nexo_risk_alerts_today`, `nexo_panic_events_30d`, `nexo_disk_used_percent` |

### `routes/chat.php` — entry point del asistente Nexus

Solo la capa HTTP; la lógica conversacional (LLM parser, NLU, semántica, SCP)
está documentada en [docs/nexus/NEXUS.md](../../docs/nexus/NEXUS.md) y vive en
`lib/nexus_*.php`.

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| POST | `/chat/message` | auth | Mensaje al asistente (máx. 500 chars; rate limit 60 msg/10 min por usuario); crea/reutiliza `session_id` |
| GET | `/chat/sessions` | auth | Lista las conversaciones del usuario |
| GET | `/chat/history` | auth | Historial de una sesión (`?session_id=`) |
| POST | `/chat/action` | auth | Resuelve acción operativa permitida por rol → redirect `/operacion?cmd=` |
| GET/POST | `/chat/policies` | RECTOR, COORDINATOR | Interruptores por escuela (`school_chat_policies`) |

### `routes/admin.php`

| Método | Endpoint | Rol | Propósito |
|---|---|---|---|
| GET/POST | `/admin/recalc-risk` | RECTOR, COORDINATOR | Recálculo masivo de riesgo (`fn_recalculate_school_risk_v3` + legacy `fn_recalculate_school_metrics`) |
| GET | `/admin/config-check` | RECTOR, COORDINATOR | Auditoría de configuración: grupos sin horario, schedules con aula inexistente, nodos sin contexto espacial, etc. (`findings[]` con severity HIGH/MEDIUM) |

---
## 5. Librerías de dominio (`lib/`)

| Archivo | Propósito |
|---|---|
| `RiskEngineV3.php` | Motor de riesgo pedagógico v3.0 (arquitectura de 7-8 capas; esta clase implementa capas 2, 4, 6 y 8): políticas versionadas configurables, combinación/correlación entre categorías, auditoría/versionado y anomalía estadística (z-score). Ontología fija: SIN_IMPORTANCIA, LEVE (4 en 7 d), MODERADA (3 en 10 d), ALTA (2 en 15 d), MUY_ALTA (1 ocurrencia = alerta inmediata). La institución configura mapeo evento→nivel, vidas medias, umbrales y cooldowns dentro de rangos protegidos; el scoring con decaimiento exponencial y la escalación (máquina de estados) viven en funciones SQL (`fn_evaluate_student_risk`, `fn_calculate_category_risk`, …) |
| `RiskScoreEngine.php` | Motor numérico de puntaje estudiantil: pesos LATE=5, ABSENCE=15, BATHROOM=2 (baseline 3 salidas gratis), PATTERN=10 (recurrencia), OVERFLOW=0.5 (>20 eventos); techo 100. Niveles LOW/MEDIUM/HIGH/CRITICAL (30/60/80) y alertas con `ALERT_THRESHOLD=70`, cooldown 7 días. `calculateAndStore()`, `recalculateSchool()` |
| `calculator.php` | Calculadora del chatbot: evalúa operaciones estructuradas por el parser; expresiones literales pasan por shunting-yard→RPN propio, **nunca `eval()`** |
| `insights.php` | Nexus Insights del dashboard: media/stddev poblacional, z-score vs línea base móvil 20 días hábiles, moda por histograma deslizante 10 min, pendiente por mínimos cuadrados + r², score de prioridad `w·z + w·magnitud + w·recencia`. Emite tarjetas `{kind, severity, score, title, body, action, data}` |
| `attendance_reconcile.php` | `nexoReconcileAbsence()`: si llega `INGRESO_*` con una INASISTENCIA abierta hoy, la resuelve (`resolved=TRUE` + metadata del evento/espacio) y genera incidente `REAPARICION_TARDIA`. Llamada desde el ingest síncrono de `api.php` y `worker_biometric.php` |
| `notify_routing.php` | Enrutamiento de notificaciones por escuela: `nexoRouteUserIds()` (tabla `school_notification_routes`, event_kind→roles destino, default COORDINATOR+RECTOR) y `nexoPolicyEnabled()`/`nexoAction()` (on/off y acción por evento vía `school_action_policies`) |
| `ota.php` | OTA de nodos edge: comparación semver con anti-rollback (`min_version`), manifiesto firmado `HMAC-SHA256("nexo-ota|ver|sha256|url")` con clave OTA por dispositivo (hex 32 B), auditoría en `ota_deployments` |
| `twilio.php` | Helpers Twilio/WhatsApp: `normalizeWhatsAppPhone`, `getTwilioStatusCallbackUrl`, `sendTwilioDirect` (texto libre con fallback a template fuera de ventana 24 h), `_twilioHttpPost`, `logTwilioMessage` |
| `kb_colombia.php` | Base de conocimiento estática de Colombia (departamentos/capitales/regiones, presidentes) para el chatbot |
| `nexus_*.php` | Subsistema conversacional (LLM parser, NLU, semántica, SCP) — **documentado aparte** en [docs/nexus/NEXUS.md](../../docs/nexus/NEXUS.md) |

---

## 6. Workers (`workers/`)

Todos los procesos se lanzan desde `docker-entrypoint.sh`: los de cola corren
como daemon-loop con reinicio por backoff exponencial (2→60 s) y los periódicos
con `*_MODE=daemon` en loop interno con sleep (si murieran, se reinician con
sleep ≥5 s para no inundar la BD). Heartbeats en `worker:<name>:last_heartbeat`
(Redis) — `/health` los considera caídos tras 300 s sin señal.

| Worker | Modo / intervalo | Qué procesa |
|---|---|---|
| `worker_biometric.php` | daemon-loop sobre `queue:biometric_ingest` | Reliable queue: LMOVE atómico (Lua) a `queue:biometric_processing`, commit PG, borrado tras éxito; DLQ `queue:biometric_dlq` tras 3 reintentos; GC de zombies >300 s. Actions: `SYNC_ATTENDANCE` (biometric_events + cruce con `class_exit_authorizations` ACTIVE: EXIT_WITH_PERMISSION/RETURN, dedup por fingerprint y ventana `BIOMETRIC_DEDUP_WINDOW_SECONDS`=300 s), `REGISTER_STUDENT`, `DELETE_STUDENT`. Sleep 1 s en cola vacía (`BIOMETRIC_EMPTY_QUEUE_SLEEP_US`) |
| `worker_twilio.php` | daemon-loop sobre `queue:twilio` + zset `queue:twilio:delayed` | Outbox WhatsApp: dedup 30 s (`twilio:dedup:*`), leaky bucket `TWILIO_RATE_LIMIT`, límites `TWILIO_MAX_SENDS_PER_HOUR`/`TWILIO_MAX_DAILY_PER_PHONE`, fallback texto→template (err. 63016/63015), actualiza `delivery_status`, reintentos con backoff exponencial |
| `worker_audit.php` | daemon-loop sobre `queue:audit_logs` (solo si `AUDIT_WORKER_ENABLED=1`) | Inserta en `global_audit_logs` manteniendo la cadena HMAC por escuela (`prev_audit_id`+`chain_hash` con `app.nexo_hmac_secret`); heartbeat + GC |
| `worker_absence_detector.php` | `ABSENCE_DETECTOR_MODE` (cron|daemon), `ABSENCE_CHECK_INTERVAL`=60 s | Marca `INASISTENCIA` a estudiantes sin `INGRESO_*` tras la hora límite de su jornada (07:10 mañana / 12:10 tarde, o `daily_schedule_config.expected_entry_time`+10 min; `has_classes=FALSE` desactiva); WhatsApp al acudiente; trigger de riesgo vía `attendance_incidents` |
| `worker_evasion_detector.php` | `EVASION_DETECTOR_MODE`, `EVASION_CHECK_INTERVAL`=120 s | `EVASION_INTERNA`: salida de aula sin permiso >15 min (o permiso expirado +5 min); colegios con rotación de salones: >10 min sin registro en la clase siguiente; receso: +10 min tras `recess_end_time`; notifica a docente del bloque y coordinación (no rector) |
| `worker_permission_status.php` | `PERMISSION_STATUS_MODE`, `PERMISSION_CHECK_INTERVAL`=60 s | `class_exit_authorizations` ACTIVE → COMPLETED si hubo `INGRESO_*` posterior a `exit_time`, EXPIRED si `now > return_time + 5 min` sin regreso |
| `worker_device_health.php` | `DEVICE_HEALTH_MODE`, `DEVICE_HEALTH_INTERVAL`=60 s | F-04: nodo sin ping > `NODE_OFFLINE_MINUTES` (10) → `security_incidents` NODO_OFFLINE + `edge_devices.status='offline'` + aviso a coordinación; bidireccional (resuelve al recuperar ping) |
| `worker_teacher_alerts.php` | `TEACHER_ALERTS_MODE`, `TEACHER_ALERTS_INTERVAL`=60 s | F-18: evalúa reglas «N eventos en M días» del docente (LATE, ABSENCE, EVASION, EXIT, PERMISSION_EXPIRY) y notifica; dedup por regla+estudiante+día |
| `worker_absence_followup.php` | `ABSENCE_FOLLOWUP_MODE`, `ABSENCE_FOLLOWUP_INTERVAL`=300 s | Reenvía menú WhatsApp a acudientes sin respuesta (`ABSENCE_FOLLOWUP_MINUTES`=60, máx `ABSENCE_FOLLOWUP_MAX`=2); escalación interna a `ABSENCE_NO_REPLY` (COORDINATOR+RECTOR) tras `ABSENCE_ESCALATE_MINUTES`=240 |
| `worker_notification_purge.php` | cron diario (crontab) o `--daemon` (1 h) | `DELETE` de `notifications` >30 días |
| `contingency_lib.php` | librería compartida | F-04/F-05: gate de salud de nodo (`NODE_HEALTH_GATE`) — grupo «sin datos de nodo» solo si tiene ≥1 dispositivo y todos offline; anomalía grupal (`ANOMALY_MIN_ABSENCES`=5, `ANOMALY_GROUP_FRACTION`=0.5); helpers BD para detectores |

### Colas Redis utilizadas

| Cola/clave | Productor → Consumidor |
|---|---|
| `queue:biometric_ingest` → `queue:biometric_processing` → `queue:biometric_dlq` | api.php → worker_biometric |
| `queue:twilio`, `queue:twilio:delayed` (zset) | rutas/workers → worker_twilio |
| `queue:audit_logs` | `securityLog()` → worker_audit (solo si `AUDIT_WORKER_ENABLED=1`) |
| `device:{id}:commands` | POST /devices/command → polling edge (`GET /devices/commands`) |
| `worker:*:last_heartbeat` | workers → health.php /api.php `/health` |
| `jwt:blocklist:{jti}`, `panic:school:{id}` | auth/logout/pánico → `verifyJwtToken` |
| `rl:*`, `chat_rl:*`, `twilio:dedup:*`, `dashboard:*`, nonces edge | rate limiting, dedup, caché, anti-replay |

### Tareas cron (`infra/scripts/crontab` → supercronic)

| Schedule | Tarea |
|---|---|
| `0 2 * * *` | `recalc_risk.sh` — `fn_recalculate_school_risk_v3` por escuela + legacy `fn_recalculate_school_metrics` |
| `0 3 1 * *` | `create_monthly_partition.sh` — crea partición del mes siguiente de `biometric_events` (idempotente) |
| `0 4 * * *` | `worker_notification_purge.php` — purga notificaciones >30 días |
| `*/4 * * * *` | keep-alive: `GET ${RENDER_EXTERNAL_URL}/health` (evita suspensión del servicio free en Render) |

---

## 7. Infraestructura

### Dockerfile

`php:8.2-fpm` + nginx + mosquitto + supercronic; extensiones vía
`install-php-extensions`: `pdo_pgsql mysqli redis sockets zip pcntl`; Composer
`--no-dev` (única dependencia runtime: `php-mqtt/client`; dev: PHPUnit 11).
Entrypoint: `docker-entrypoint.sh`. Puerto expuesto: 8080.

### docker-entrypoint.sh

1. Genera `nginx.conf` completo (sin includes frágiles): catch-all →
   `api.php`, `location = /health{,/workers}` → `health.php`, estáticos PWA
   (`/sw.js`, `/manifest.webmanifest`, `/workbox-*` desde `public/`),
   **deniega cualquier otro `.php`** y pasa `HTTP_AUTHORIZATION` a PHP-FPM
   (nginx no lo reenvía por defecto).
2. Genera pool PHP-FPM (socket Unix `/run/php/php-fpm.sock`, `pm.dynamic`,
   max_children=10) y OPcache (`validate_timestamps=0`).
3. Lanza workers: daemon-loop con backoff (twilio, audit, biometric) y
   periódicos con `*_MODE=daemon` (absence, evasion, permission, device_health,
   teacher_alerts, absence_followup).
4. Configura e inicia Mosquitto en `127.0.0.1:1883` (auth si `MQTT_USER`/
   `MQTT_PASS`; WARN + `allow_anonymous` si faltan).
5. Inicia nginx, registra `infra/scripts/crontab` en supercronic y mantiene el
   contenedor vivo (`tail -f /dev/null`).

### PgBouncer (`infra/pgbouncer/`)

Contenedor separado (dev) / sidecar (prod). `pgbouncer.ini`:
`pool_mode=transaction`, `auth_type=scram-sha-256`,
`server_reset_query=DISCARD ALL`,
`ignore_startup_parameters=extra_float_digits,options`, `max_client_conn=1000`,
`default_pool_size=50`, `query_wait_timeout=5`. `entrypoint.sh` genera
`userlist.txt` desde `DB_USER`/`DB_PASSWORD` (en claro: necesario para que
PgBouncer responda SCRAM hacia el servidor Postgres 15) y expande la plantilla
con `envsubst`.

> **Por qué transaction pooling**: `app.current_school_id`/`app.current_role`
> se fijan con `set_config(..., is_local=true)` dentro de la transacción que
> abre `requireAuth()`; en session pooling los settings fugan entre clientes
> (riesgo cross-tenant).

### dev_tools/

`dev_tools/docker-compose.yml`: stack local de contingencia (api + postgres 15
+ pgbouncer + redis + audit-worker) para pruebas de integración.

### public/

Estáticos servidos por nginx (`sw.js`, `sw.js.map`, `manifest.webmanifest`,
`workbox-*.js`) — service worker/manifest de la PWA.

---

## 8. Persistencia: PostgreSQL vs Redis

| Capa | PostgreSQL (única fuente de verdad) | Redis (efímero, opcional) |
|---|---|---|
| Datos | students, users, schools, academic_groups, schedules, biometric_events (particionada mensual), attendance_incidents, *_authorizations, notifications, twilio_messages, sos_alerts, student_tracking*, risk_*, edge_devices, device_commands (fallback), global_audit_logs (cadena HMAC), jwt_blocklist, user_sessions, system_telemetry, chat_messages, school_*_config/policies | — |
| Colas | `device_commands` (fallback de `device:{id}:commands`) | `queue:biometric_ingest`, `queue:twilio`, `queue:audit_logs`, `device:{id}:commands` |
| Estado volátil | `school_panic_events`, `jwt_blocklist` (fallback persistente) | `panic:school:*`, `jwt:blocklist:*`, nonces edge (7 d), `biometric_dedup:*`, `twilio:dedup:*`, `rl:*`, `chat_rl:*`, `dashboard:stats/insights:*`, `worker:*:last_heartbeat`, contadores `school:{id}:present:{fecha}` |

**Degradación sin Redis** (el stack sobrevivió una caída prolongada de
Upstash — estos caminos existen por ese incidente):

- `getRedisConnection()` retorna `null` (fail-open).
- Comandos edge: fallback a tabla `device_commands` (enqueue y polling).
- `SYNC_ATTENDANCE` y `REGISTER_STUDENT`: fast path directo a PG en `api.php`.
- Rate limiting global y de login: fail-open; `/contacto` es **fail-closed**
  (503).
- Blocklist JWT: verifica `jwt_blocklist` en PG (los tokens revocados expiran
  solos en 15 min si todo falla).
- Modo pánico: fallback a `school_panic_events`.
- Nonce edge: se acepta sin validar (el timestamp ±7 días sigue protegiendo).
- Colas de Twilio/auditoría/caché dashboard: degradan o no funcionan hasta que
  Redis vuelva.

---

## 9. Seguridad

- **Multi-tenant (RLS)**: `requireAuth()` fija `app.current_school_id` +
  `app.current_role` por transacción; las policies comparan con
  `get_current_school_id()`. Roles internos: `SYSTEM_WORKER` (workers,
  webhooks Twilio) lee todas las escuelas; `EDGE_NODE` queda confinado a la
  escuela de su dispositivo.
- **Cifrado edge**: AES-256-GCM (`NEXO_AES_KEY`), IV 12 B / tag 16 B;
  `device_token` bcrypt en `edge_devices.token_hash`; anti-replay por nonce
  Redis (TTL 7 días) + ventana de timestamp ±7 días. Los templates biométricos
  **nunca salen del edge**; solo viaja `huella_id`.
- **JWT**: RS256 preferido / HS256 fallback; TTL access 900 s; revocación por
  `jti`; invalidación masiva por `panic:school:{id}` (token emitido antes del
  pánico queda inválido); rotación real de refresh tokens.
- **CSRF**: `X-Requested-With: XMLHttpRequest` obligatorio en mutantes;
  cookies `SameSite=Lax`, HttpOnly.
- **Dedup**: `event_fingerprint` SHA-256 + `ON CONFLICT` en
  `biometric_events`; dedup adicional en worker (Redis NX);
  `twilio:dedup` 30 s.
- **Rate limiting**: global mutantes (100/60 s), sensibles
  (`/auth/verify-2fa` 10/300 s, `/users/send-verification` 5/600 s,
  `/contacto` 3/3600 s + límite propio 5/h fail-closed), login por IP+email,
  Twilio por teléfono, chat 60/10 min.
- **Auditoría**: `securityLog()` → stderr siempre; a `queue:audit_logs` y
  cadena HMAC-SHA256 en `global_audit_logs` (trigger `trg_audit_chain`,
  secreto `app.nexo_hmac_secret`) solo con `AUDIT_WORKER_ENABLED=1`.
  `/audit/integrity` verifica la cadena.
- **Webhooks Twilio**: firma `X-Twilio-Signature` verificada
  (`verifyTwilioSignature`) antes de tocar la BD.
- **Headers HTTP**: HSTS, `X-Frame-Options: DENY`, CSP frame-ancestors,
  nosniff, Referrer-Policy; nginx deniega `*.php` salvo `api.php`/`health.php`
  y dotfiles.
- **OTA**: manifiestos firmados HMAC-SHA256 con clave por dispositivo;
  anti-rollback por `min_version`.
- **Revocación de sensores**: requiere `sensor_master_key` de la escuela
  (bcrypt). **Pánico**: desactiva todos los edge_devices en cascada.
- No se encontraron credenciales hardcodeadas en `backend/api/`; las claves se
  leen siempre de variables de entorno.

---

## 10. Configuración (variables de entorno)

Sin valores — los secretos viven fuera del repo.

### Obligatorias (verificadas por `core/boot_check.php` → 503 si faltan)

| Variable | Propósito |
|---|---|
| `DATABASE_URL` (o `PGHOST`/`PGPORT`/`PGDATABASE`/`PGUSER`/`PGPASSWORD`) | PostgreSQL / PgBouncer |
| `NEXO_AES_KEY` | AES-256-GCM del canal edge |
| `CORS_ALLOW_ORIGINS` | Orígenes exactos, sin wildcard |

Además se exige un mecanismo de firma JWT (`JWT_PRIVATE_KEY`+`JWT_PUBLIC_KEY`
o `JWT_SECRET`).

### Autenticación / seguridad

`JWT_SECRET`, `JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEY`, `JWT_KEY_ID`, `JWT_ISSUER`
(`nexo-api`), `JWT_AUDIENCE` (`nexo-webapp`), `JWT_ACCESS_TTL_SECONDS` (900),
`JWT_REFRESH_TTL_SECONDS` (604800), `LOGIN_2FA_ENABLED`,
`APP_NEXO_HMAC_SECRET`/`NEXO_HMAC_SECRET` (cadena de auditoría),
`METRICS_SECRET_KEY`, `RATE_LIMIT_MAX`, `RATE_LIMIT_WINDOW`.

### Infraestructura

`REDIS_URL` (prioritaria, soporta `rediss://` TLS) o
`REDISHOST`/`REDISPORT`/`REDIS_USER`/`REDIS_PASSWORD`/`REDIS_DB`; `MQTT_HOST`,
`MQTT_PORT`, `MQTT_USER`, `MQTT_PASS`; `PORT` (8080); `APP_ENV`;
`RENDER_EXTERNAL_URL` (keep-alive cron).

### Twilio / notificaciones

`TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_WHATSAPP_FROM`,
`TWILIO_FROM_NUMBER`, `TWILIO_SMS_FROM`, `TWILIO_WHATSAPP_TEMPLATE_SID`,
`TWILIO_WEBHOOK_URL_BASE`, `APP_URL`, `NEXO_OWNER_WHATSAPP`,
`TWILIO_RATE_LIMIT`, `TWILIO_MAX_SENDS_PER_HOUR`, `TWILIO_MAX_DAILY_PER_PHONE`.

### Workers y contingencia

`AUDIT_WORKER_ENABLED`, `ABSENCE_DETECTOR_MODE`, `ABSENCE_CHECK_INTERVAL`,
`EVASION_DETECTOR_MODE`, `EVASION_CHECK_INTERVAL`, `PERMISSION_STATUS_MODE`,
`PERMISSION_CHECK_INTERVAL`, `DEVICE_HEALTH_MODE`, `DEVICE_HEALTH_INTERVAL`,
`TEACHER_ALERTS_MODE`, `TEACHER_ALERTS_INTERVAL`, `ABSENCE_FOLLOWUP_MODE`,
`ABSENCE_FOLLOWUP_INTERVAL`, `ABSENCE_FOLLOWUP_MINUTES`,
`ABSENCE_FOLLOWUP_MAX`, `ABSENCE_ESCALATE_MINUTES`,
`BIOMETRIC_DEDUP_WINDOW_SECONDS`, `BIOMETRIC_EMPTY_QUEUE_SLEEP_US`,
`BIOMETRIC_GC_MAX_AGE`, `NODE_HEALTH_GATE`, `NODE_OFFLINE_MINUTES`
(`NODE_OFFLINE_SECONDS` para pruebas), `ANOMALY_MIN_ABSENCES`,
`ANOMALY_GROUP_FRACTION`, `TELEM_*` (umbrales de telemetría de nodos).

### Chat (Nexus)

`NLU_LLM_KEY`, `NLU_LLM_MODE` — el parser del chat es un LLM; sin clave el
health reporta `llm:false` y cae a 503. Detalles en
[docs/nexus/NEXUS.md](../../docs/nexus/NEXUS.md).

---

## 11. Salud y observabilidad

### `GET /health` y `/health/workers`

Existen **dos** implementaciones sin autenticación:

- **`health.php`** (standalone, servido por nginx `location = /health*`):
  verifica DB (`SELECT 1`, timeout 2 s), Redis (`PING`, TLS incluido),
  heartbeats de workers vía `MGET` (edad ≤300 s; `audit` solo si
  `AUDIT_WORKER_ENABLED=1`) y configuración del parser LLM
  (`NLU_LLM_KEY`+`NLU_LLM_MODE!='off'`). 200 si todo OK, 503 si algo falla.
- **`api.php` `/health`** (cuando la petición llega por el catch-all):
  además añade latencia de BD, detalle por worker (`last_heartbeat`,
  `seconds_ago`) y disco (`used_percent < 90`). Responde
  `{"status":"ok"|"degraded","checks":{...}}`.

### Observabilidad

- `GET /metrics` — exposición Prometheus (ver §4, requiere `METRICS_SECRET_KEY`).
- `GET /events/stream` — SSE de notificaciones para la PWA.
- Logs: todo a stderr/stdout del contenedor (`error_log`, `securityLog`,
  `logXX()` por worker); supercronic escribe logs de cron en `infra/logs/`.
- Trazabilidad: `request_id` del edge se propaga a cabecera `X-Request-ID` y a
  los `securityLog` (`[REQ:...]`).
- `core/boot_check.php` — fail-closed al arranque (ver §10).
- `worker_audit` + `fn_validate_audit_chain` — integridad de la cadena de
  auditoría.

---

## 12. Mapa de archivos

```
backend/api/
├── api.php                    Front controller: headers, boot, rate limit,
│                              routing, ingesta edge AES-256-GCM, /health
├── health.php                 Health check autónomo (db/redis/workers/llm)
├── composer.json              php-mqtt/client (runtime), phpunit (dev)
├── Dockerfile                 php:8.2-fpm + nginx + mosquitto + supercronic
├── docker-entrypoint.sh       nginx/php-fpm conf, workers, MQTT, supercronic
├── core/
│   ├── boot_check.php         Env obligatorias (fail-closed 503)
│   ├── db.php                 PDO PostgreSQL + app.nexo_hmac_secret
│   ├── redis.php              getRedisConnection() (REDIS_URL, TLS, fail-open)
│   └── mqtt_publisher.php     publishDeviceCommand() (php-mqtt, QoS 1)
├── routes/
│   ├── _cors_middleware.php   CORS estricto por CORS_ALLOW_ORIGINS
│   ├── _auth_middleware.php   JWT, roles, requireAuth, RLS ctx, blocklist, pánico
│   ├── auth.php               login/2fa/logout/me/refresh
│   ├── users.php              perfil, directorio, verificación, passwords
│   ├── students.php           CRUD estudiantes + bulk-assign + consent
│   ├── groups.php             grupos académicos
│   ├── dashboard.php          stats, teacher-group-detail, events, insights
│   ├── operations.php         comandos operativos + helpers Twilio (siempre cargado)
│   ├── devices.php            edge devices, comandos, revocación, OTA
│   ├── audit_full.php         auditoría completa (RECTOR/COORDINATOR)
│   ├── risk.php               políticas, alertas, justificación, recálculo
│   ├── behavior.php           métricas de riesgo conductual
│   ├── school_config.php      onboarding, horarios, aulas, materias, modalidad
│   ├── security_panic.php     modo pánico
│   ├── consultations.php      motor de consultas por type
│   ├── misc.php               contacto, notificaciones, búsqueda, reportes, webhook inbound
│   ├── twilio_delivery.php    webhook de delivery receipts Twilio
│   ├── tracking.php           seguimiento de casos (orientación)
│   ├── teacher_alerts.php     reglas de aviso docente
│   ├── telemetry.php          telemetría del cliente
│   ├── events.php             SSE /events/stream
│   ├── metrics.php            /metrics Prometheus
│   └── chat.php               entry point HTTP del asistente Nexus
├── lib/
│   ├── RiskEngineV3.php       Motor de riesgo v3 (capas 2/4/6/8)
│   ├── RiskScoreEngine.php    Puntaje numérico de riesgo
│   ├── calculator.php         Calculadora segura (sin eval)
│   ├── insights.php           Nexus Insights (estadística sobre datos reales)
│   ├── attendance_reconcile.php  Reconciliación INASISTENCIA↔INGRESO
│   ├── notify_routing.php     Rutas de notificación y políticas por escuela
│   ├── ota.php                OTA edge (manifiesto HMAC, semver, anti-rollback)
│   ├── twilio.php             Helpers Twilio/WhatsApp
│   ├── kb_colombia.php        KB estática de Colombia (chatbot)
│   └── nexus_*.php            Subsistema Nexus → docs/nexus/NEXUS.md
├── workers/                   10 workers + contingency_lib.php (ver §6)
├── infra/
│   ├── pgbouncer/             Dockerfile, entrypoint.sh, pgbouncer.ini
│   └── scripts/               crontab, create_monthly_partition.sh, recalc_risk.sh
├── dev_tools/
│   └── docker-compose.yml     Stack local (api+db+pgbouncer+redis)
└── public/                    sw.js, manifest.webmanifest, workbox (PWA)
```


