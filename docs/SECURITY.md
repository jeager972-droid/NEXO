# SECURITY.md — Seguridad de NEXO

Documentación del modelo de seguridad de NEXO: autenticación, autorización, cifrado, RLS, auditoría, y amenazas mitigadas.

## Modelo de amenazas

NEXO maneja datos de menores de edad (estudiantes de institución educativa), incluyendo biométricos y de asistencia. Las amenazas principales son:

- **Fuga de datos de estudiantes** (PII, biométricos, asistencia).
- **Acceso cross-tenant** (una institución ve datos de otra).
- **Suplantación de dispositivo edge** (sensor falso envía eventos).
- **Replay de eventos** (reenvío de un evento legítimo capturado).
- **Manipulación de auditoría** (borrado/edición de logs).
- **Acceso no autorizado a la API** (endpoints sin auth).
- **XSS/CSRF en la PWA**.

## Autenticación

### JWT

- **Access token**: HS256 (default) o RS256 (si `JWT_PRIVATE_KEY`/`JWT_PUBLIC_KEY` están seteados). TTL 15 min (`JWT_ACCESS_TTL_SECONDS=900`).
- **Refresh token**: opaque string aleatorio de 64 bytes, hasheado con SHA-256 en `user_sessions.refresh_token_hash`. TTL 7 días (`JWT_REFRESH_TTL_SECONDS=604800`). Rotación real: cada refresh revoca la sesión anterior y crea una nueva.
- **Claims**: `sub` (user_id), `email`, `role`, `school_id`, `jti` (UUID único por token), `iss`, `aud`, `iat`, `exp`.
- **Cookies**: `token` y `refresh_token` en cookies HttpOnly, Secure (en HTTPS), SameSite=Lax. El refresh token también se acepta en el body como workaround para iOS ITP que bloquea cookies cross-site.
- **Revocación**: `jti` se añade a blocklist de Redis (`jwt:blocklist:{jti}`, TTL = tiempo restante del token) en logout. Si Redis cae, la revocación es best-effort (el token expira en 15 min).

### Login

- **Throttling**: `isLoginThrottled()` limita intentos por IP y por email (Redis, fail-open si Redis cae).
- **2FA opcional**: si `LOGIN_2FA_ENABLED=true` y el usuario tiene teléfono verificado, se envía un OTP por WhatsApp tras validar la contraseña. El OTP se guarda en `verification_codes` con expiración.
- **Rehash**: si el cost bcrypt del hash almacenado es < 12, se re-hashea en el login exitoso.

### Sesiones

- `user_sessions` registra cada sesión activa con `user_id`, `refresh_token_hash`, `ip_address`, `user_agent`, `created_at`, `last_used_at`, `revoked_at`.
- RLS permite que un usuario solo vea sus propias sesiones (vía JOIN a `users.school_id`); `SYSTEM_WORKER` puede leer todas para validar refresh tokens.

## Autorización

### Roles

10 roles definidos en `roles`:

| Rol | Descripción |
|---|---|
| `RECTOR` | Rector/director. Acceso total a su institución. |
| `COORDINATOR` | Coordinador. Gestión operativa, auditoría, riesgo. |
| `TEACHER` | Docente. Ve sus grupos asignados. |
| `SECRETARY` | Secretaría. Gestión de estudiantes, matrícula. |
| `SECURITY` | Seguridad de la institución. |
| `AUXILIARY` | Auxiliar. |
| `COUNSELOR` | Orientador. Ve casos de seguimiento y riesgo. |
| `GUARDIAN` | Acudiente. Ve solo sus hijos. |
| `SUPER_ADMIN` | Super administrador (multi-tenant). |
| `SYSTEM_WORKER` | Rol interno de los workers. Solo acceso programático. |

### Permisos

31 permisos en `permissions`, asignados a roles vía `role_permissions` (N:M). RECTOR tiene todos los permisos. El middleware `requirePermission($code)` valida en cada endpoint mutante.

### Multi-tenant (RLS)

- Toda tabla operacional tiene `school_id` y RLS habilitada.
- `requireAuth()` fija `app.current_school_id` y `app.current_role` a nivel de transacción (compatible con PgBouncer transaction-pooling).
- Las policies comparan `school_id = get_current_school_id()`. Un usuario nunca puede acceder a datos de otra institución.
- Los workers fijan `app.current_role = 'SYSTEM_WORKER'` y pueden leer todas las escuelas (necesario para procesamiento batch).
- Los dispositivos edge fijan `app.current_school_id` tras validar su token.

### Validación de permisos en la API

```php
requireAuth();                    // valida JWT, fija school_id y role
requirePermission('operations.sos');  // valida permiso específico
```

Si el usuario no tiene el permiso, responde `403 Forbidden`.

## Cifrado

### Comunicación edge → API

- **AES-256-GCM** para el payload de ingesta. IV de 12 bytes, tag de 16 bytes.
- La clave AES se provisiona al edge y se guarda en `edge_devices` (referenciada por `device_id`).
- El `device_token` (bcrypt en `edge_devices.token_hash`) autentica el dispositivo.
- **Anti-replay**: nonce único por evento (validado en Redis con TTL 7 días, fail-open si Redis cae) + timestamp ±7 días.

### Templates biométricos

- Los templates **nunca salen del edge**. Solo viaja el `huella_id` (entero local asignado por el edge).
- En el edge, los templates se cifran con AES-256-GCM en SQLite (`template_huella`).
- La clave AES del edge es **hardware-bound**: derivada de `/proc/cpuinfo` (Serial + Revision) vía PBKDF2-SHA256, cifrada en disco con magic `NXE1`. `mlock()` para evitar swap.

### Datos en tránsito

- HTTPS obligatorio en producción (Nginx con TLS).
- CORS estricto: `CORS_ALLOW_ORIGINS` lista los orígenes permitidos (PWA y landing).
- HSTS en Nginx.

### Datos en reposo

- Contraseñas: bcrypt (cost ≥ 12).
- Refresh tokens: SHA-256 del token en `user_sessions.refresh_token_hash`.
- Device tokens: bcrypt en `edge_devices.token_hash`.
- Sensor master key: bcrypt en `schools.sensor_master_key_hash`.
- HMAC secret: `app.nexo_hmac_secret` seteado en la sesión PG, no persistido en la base.

## Auditoría

### Cadena HMAC

`global_audit_logs` implementa una cadena de hashes HMAC-SHA256:

- Cada registro tiene `prev_audit_id` (FK al registro anterior de la escuela) y `chain_hash`.
- `chain_hash = HMAC-SHA256(secret, prev_hash || school_id || actor_id || event_type || description || ip || created_at)`.
- El trigger `trg_audit_chain` calcula ambos antes de insertar.
- `fn_validate_audit_chain(school_id)` recorre la cadena y reporta dónde se rompió.
- El secreto (`app.nexo_hmac_secret`) se setea en la sesión PG desde `NEXO_HMAC_SECRET`. Si es el valor default, las funciones abortan.

### Logs de seguridad

`securityLog()` en la API registra eventos de seguridad (login, logout, 2FA, permisos denegados, etc.) a stderr del contenedor y, si `AUDIT_WORKER_ENABLED=1`, encola en `queue:audit_logs` para persistencia en la cadena HMAC.

### Auditoría de expedientes

`student_record_audit` registra cambios en expedientes de estudiantes (particionada mensualmente).

## CSRF y XSS

### CSRF

- Cookies HttpOnly + `SameSite=Lax`.
- Métodos mutantes requieren cabecera `X-Requested-With: XMLHttpRequest`. Sin ella, `403 Request forbidden`. Esta cabecera no la envían los formularios HTML nativos, pero sí las peticiones fetch/axios.
- El interceptor de Axios añade `X-Requested-With` automáticamente en métodos mutantes.

### XSS

- React escapa por defecto (JSX).
- No se usa `dangerouslySetInnerHTML` sin sanitización.
- Tokens en cookies HttpOnly (no accesibles por JS).
- CSP configurable en Nginx (recomendado en producción).

## Rate limiting

- **Login**: por IP y por email (Redis, fail-open si Redis cae).
- **Contacto** (`/contacto`): por IP (Redis, **fail-closed** → 503 si Redis cae, para evitar spam).
- **Twilio**: por teléfono (`TWILIO_MAX_SENDS_PER_HOUR`, `TWILIO_MAX_DAILY_PER_PHONE`) y global.
- **API general**: configurable.

## Revocación de sensores

- `POST /devices/{id}/revocation` requiere la `sensor_master_key` de la escuela (bcrypt en `schools.sensor_master_key_hash`).
- Crea una `sensor_revocation_requests` que el edge procesa vía MQTT/polling.
- El edge borra los templates del sensor revocado de su SQLite local.

## Modo pánico

- `POST /security/panic` activa `school_panic_events`.
- Desactiva todos los dispositivos edge de la institución en cascada (vía comandos MQTT).
- Verifica `isSchoolInPanicMode()` (Redis, fail-open si Redis cae) para evitar re-activación.

## PgBouncer

- Transaction-pooling mode (necesario para que los settings `app.current_school_id`/`app.current_role` funcionen por transacción).
- `backend/api/infra/pgbouncer/entrypoint.sh` configura el pool.
- Los settings se fijan con `set_config(..., true)` (local a la transacción).

## Variables de entorno de seguridad

| Variable | Propósito |
|---|---|
| `JWT_SECRET` | Firma JWT HS256 |
| `JWT_PRIVATE_KEY` / `JWT_PUBLIC_KEY` | Firma JWT RS256 (alternativa) |
| `JWT_ISSUER`, `JWT_AUDIENCE`, `JWT_KEY_ID` | Claims JWT |
| `JWT_ACCESS_TTL_SECONDS` | TTL access token (default 900) |
| `JWT_REFRESH_TTL_SECONDS` | TTL refresh token (default 604800) |
| `NEXO_AES_KEY` | Clave AES-256 para payloads edge |
| `NEXO_HMAC_SECRET` / `APP_NEXO_HMAC_SECRET` | Secreto cadena auditoría |
| `CORS_ALLOW_ORIGINS` | Orígenes permitidos |
| `LOGIN_2FA_ENABLED` | Habilita 2FA por WhatsApp |
| `AUDIT_WORKER_ENABLED` | Persistencia de logs en cadena HMAC |
| `TWILIO_ACCOUNT_SID` / `TWILIO_AUTH_TOKEN` | Credenciales Twilio |
| `MQTT_USER` / `MQTT_PASS` | Auth broker Mosquitto |

## Pruebas de seguridad

- `test/sql/RlsSecurityTest.php` — verifica policies RLS por tabla.
- `test/api/` — tests de endpoints que validan auth, permisos y aislamiento multi-tenant.
- `test/runners/SchemaPhpAlignmentTest.php` — verifica que el código PHP no use columnas inexistentes.

Más detalle en [TESTING.md](TESTING.md).
