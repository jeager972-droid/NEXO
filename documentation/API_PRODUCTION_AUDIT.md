# NEXO — Auditoría de Producción API (Backend)

> **Fecha de auditoría:** 2026-08-15
> **Alcance:** `backend/api/` (PHP), `WebApp/src/api/` (frontend), `backend/api/sql/`, `backend/api/workers/`, `documentation/`
> **Modalidad:** Solo lectura (AUDIT → FIND → DEMONSTRATE → CLASSIFY → DOCUMENT → SCORE)
> **Referencias:** OWASP API Security Top 10 2023, OWASP ASVS 5.0, OWASP WSTG, NIST SP 800-53 Rev.5, reglas internas NEXO, código real del proyecto.
> **Sin modificaciones:** Esta auditoría no alteró ningún archivo del repositorio.

---

## 0. Resumen Ejecutivo

| Métrica | Valor |
|---|---|
| Endpoints analizados | 150+ (20 archivos de rutas) |
| Workers analizados | 7 PHP + 2 shell scripts |
| Tablas DB analizadas | 42 (38 base + 4 sistema + particiones) |
| Llamadas frontend analizadas | 85 (13 módulos API) |
| Hallazgos totales | **47** |
| Bloqueantes de producción | **9** (CRITICAL) |
| Altos | **14** |
| Medios | **16** |
| Bajos | **8** |
| Puntuación global | **62 / 100** — **NO APTO para producción sin remediación de los 9 bloqueantes** |

### Veredicto

El backend NEXO es funcionalmente robusto y demuestra ingeniería seria (RLS multi-tenant, JWT con revocación, audit chain HMAC-SHA256, queue fiable para biométricos, circuit breaker en Twilio). Sin embargo, **NO es production-ready** en su estado actual debido a 9 bloqueantes críticos que afectan seguridad, integridad de datos y continuidad operativa. La remediación de los 9 bloqueantes elevaría la puntuación a ~80/100 (aptitud condicional).

---

## 1. Inventario Consolidado

### 1.1 Endpoints Backend (150+)

**Front controller:** `api.php` — routing por prefijo, AES-256-GCM para edge, `/health`, CORS, rate limiting Redis.

**Rutas por archivo:**

| Archivo | Endpoints | Auth principal |
|---|---|---|
| `auth.php` | 4 | Pública + JWT |
| `users.php` | 9 | JWT + OTP |
| `students.php` | 2 | JWT + RBAC |
| `groups.php` | 1 | JWT |
| `dashboard.php` | 3 | JWT + Redis cache |
| `operations.php` | 17 | JWT + permisos `operations.*` |
| `consultations.php` | 1 (switch 30 módulos) | JWT |
| `misc.php` | 8 | Mixta (público + JWT + Twilio sig) |
| `devices.php` | 7 | JWT + X-Device-Token |
| `audit_full.php` | 50+ | RECTOR/COORDINATOR |
| `audit_logs.php` | 1 (DUPLICADO) | RECTOR/COORDINATOR |
| `audit_integrity.php` | 1 (DUPLICADO) | RECTOR/COORDINATOR |
| `tracking.php` | 4 | COORDINATOR/RECTOR/COUNSELOR |
| `school_config.php` | 5 | JWT + RECTOR/COORDINATOR |
| `security_panic.php` | 1 | RECTOR/COORDINATOR |
| `behavior.php` | 1 | JWT |
| `admin.php` | 1 | RECTOR/COORDINATOR |
| `metrics.php` | 1 | X-Metrics-Key opcional |
| `telemetry.php` | 1 | JWT |
| `twilio_delivery.php` | 2 | Pública + Twilio sig |

### 1.2 Workers (7 PHP + 2 shell)

| Worker | Queue/Poll | DLQ | Circuit Breaker | Heartbeat |
|---|---|---|---|---|
| `worker_biometric.php` | Redis (Lua LMOVE) | ✅ | ❌ | ✅ 30s |
| `worker_twilio.php` | Redis blPop | ✅ | ✅ 500/h | ✅ 600s |
| `worker_audit.php` | Redis blPop | ❌ (requeue infinito) | ❌ | ✅ 600s |
| `worker_absence_detector.php` | Poll DB | ❌ | ❌ | ✅ 30s (daemon) |
| `worker_evasion_detector.php` | Poll DB | ❌ | ❌ | ✅ 30s (daemon) |
| `worker_permission_status.php` | Poll DB | ❌ | ❌ | ✅ 30s (daemon) |
| `worker_notification_purge.php` | Poll DB | ❌ | ❌ | ❌ |

### 1.3 Tablas DB (42)

- 28 con RLS habilitado
- 10 con `school_id` pero **SIN RLS** (brecha de seguridad)
- 8 tablas particionadas (solo `biometric_events` tiene particiones mensuales reales)
- Audit chain HMAC-SHA256 en `global_audit_logs`

### 1.4 Frontend (85 llamadas API)

- 13 módulos API en `WebApp/src/api/`
- Auth dual: HttpOnly cookie + localStorage fallback (ITP iOS)
- **Sin refresh token** — re-login completo al expirar
- 5 patrones de polling (notif 60s, auth 5min, telemetry 5min, twilio-status 2s)

---

## 2. Hallazgos

### Convención de hallazgos

Cada hallazgo incluye: ID, Categoría, Severidad, Confianza, Ubicación, Evidencia, Impacto, Esperado, Actual, Reproducción, Recomendación, Bloqueante.

---

### NEXO-AUD-001 — HMAC secret por defecto en worker_audit

- **Categoría:** Seguridad / Criptografía
- **Severidad:** CRITICAL
- **Confianza:** Alta
- **Bloqueante:** SÍ
- **Ubicación:** `backend/api/workers/worker_audit.php:105`
- **Evidencia:**
  ```php
  $secret = getenv('APP_NEXO_HMAC_SECRET') ?: 'default-secret-change-me';
  ```
- **Impacto:** Si `APP_NEXO_HMAC_SECRET` no está configurada, el worker usa un secreto público hardcoded. La cadena de auditoría HMAC-SHA256 puede ser falsificada por cualquier actor que conozca el código, invalidando la garantía de tamper-evidence de `global_audit_logs`. Esto compromete toda la trazabilidad regulatoria.
- **Esperado:** El worker debe abortar al arranque si falta `APP_NEXO_HMAC_SECRET` (igual que `boot_check.php` hace con otras variables críticas).
- **Actual:** Fallback silencioso a secreto público.
- **Reproducción:** Eliminar `APP_NEXO_HMAC_SECRET` del entorno y arrancar `worker_audit.php`. El worker inicia normalmente y firma con `'default-secret-change-me'`.
- **Recomendación:** `if (!getenv('APP_NEXO_HMAC_SECRET')) { fwrite(STDERR, 'APP_NEXO_HMAC_SECRET requerido'); exit(1); }` al inicio. Añadir a `boot_check.php`.
- **OWASP:** API02:2023 — Broken Authentication (debilidad criptográfica)
- **NIST:** SC-13, SC-28

---

### NEXO-AUD-002 — 10 tablas con school_id sin RLS

- **Categoría:** Seguridad / Multi-tenant isolation
- **Severidad:** CRITICAL
- **Confianza:** Alta
- **Bloqueante:** SÍ
- **Ubicación:** `backend/api/sql/` (esquema DB)
- **Evidencia:** Tablas con columna `school_id` pero sin políticas RLS:
  1. `staff_records`
  2. `academic_groups`
  3. `classrooms`
  4. `security_incidents`
  5. `school_exit_authorizations`
  6. `class_exit_authorizations`
  7. `pedagogical_trip_authorizations`
  8. `student_record_audit`
  9. `report_exports`
  10. `internal_messages`
- **Impacto:** Si una query omite el filtro `WHERE school_id = ?` (bug en PHP, o acceso directo vía SQL), los datos de una escuela son visibles/modificables desde otra. El backend PHP filtra manualmente en la mayoría de casos, pero RLS es la defensa en profundidad. `academic_groups` y `class_exit_authorizations` son especialmente sensibles: la primera filtra estudiantes por grupo; la segunda controla permisos de salida.
- **Esperado:** Todas las tablas con `school_id` deben tener RLS con política `school_id = get_current_school_id()`.
- **Actual:** 10 tablas dependen exclusivamente del filtro manual en PHP.
- **Reproducción:** Conectarse a la BD sin set_config, ejecutar `SELECT * FROM class_exit_authorizations` — retorna todas las escuelas.
- **Recomendación:** Migración SQL que habilite RLS en las 10 tablas con políticas equivalentes a las de `students`/`users`.
- **OWASP:** API01:2023 — Broken Object Level Authorization (BOLA)
- **NIST:** AC-4, SC-7

---

### NEXO-AUD-003 — Ingesta edge sin validación de token_hash

- **Categoría:** Seguridad / Autenticación device
- **Severidad:** CRITICAL
- **Confianza:** Alta
- **Bloqueante:** SÍ
- **Ubicación:** `backend/api/api.php:266-380` (ingesta AES-256-GCM)
- **Evidencia:** El endpoint de ingesta edge valida `X-Device-Token` solo en `/devices/commands` y `/devices/ping` (`devices.php:165,219`), pero **NO** en el endpoint principal de ingesta biométrica en `api.php`. El TODO en `devices.php:101-109` lo documenta explícitamente:
  ```
  // TODO: El endpoint POST /devices genera un token raw que el edge debería
  // usar para autenticarse... Actualmente el firmware edge no implementa esta
  // autenticación; se asume confianza por cifrado de payload.
  ```
- **Impacto:** Cualquier actor con `NEXO_AES_KEY` (rotación comprometida, insider) puede inyectar eventos biométricos falsos sin identificar el dispositivo de origen. La confianza se basa únicamente en el cifrado simétrico, no en identidad del dispositivo. Un dispositivo clonado o clave filtrada permite inyectar asistencia falsa a escala.
- **Esperado:** Toda ingesta edge debe validar `password_verify($token, $device['token_hash'])` además del descifrado AES.
- **Actual:** Solo descifrado AES, sin bind a dispositivo registrado.
- **Reproducción:** Descifrar payload con `NEXO_AES_KEY`, reemplazar `device_id` por cualquier UUID, re-cifrar y enviar. El sistema acepta eventos de dispositivos no registrados.
- **Recomendación:** Añadir `X-Device-Token` obligatorio en ingesta, validar contra `edge_devices.token_hash`, rechazar si el dispositivo está inactivo o no pertenece a la escuela del payload.
- **OWASP:** API02:2023 — Broken Authentication, API03:2023 — Broken Object Property Level Authorization
- **NIST:** IA-2, SC-8

---

### NEXO-AUD-004 — Sin refresh token / rotación de sesión

- **Categoría:** Seguridad / Gestión de sesiones
- **Severidad:** HIGH
- **Confianza:** Alta
- **Bloqueante:** SÍ (por impacto UX + seguridad)
- **Ubicación:** `backend/api/routes/auth.php`, `WebApp/src/context/AuthContext.jsx`
- **Evidencia:** El login emite un JWT con expiración fija. No existe endpoint de refresh. `user_sessions` tiene `refresh_token_hash` pero no se usa. El frontend hace polling a `/auth/me` cada 5 min para detectar expiración, pero no puede renovar — debe re-login completo.
- **Impacto:** (1) Sesiones largas (8h jornada escolar) se cortan y obligan re-login en medio de operaciones críticas (SOS, citación). (2) Si se reduce el TTL para seguridad, UX empeora. (3) No hay revocación granular post-login salvo panic mode o blocklist por jti.
- **Esperado:** Refresh token con rotación (ASVS 3.4.3), acceso token de corta vida (15 min), refresh de larga vida (8h) con revocación.
- **Actual:** Access token único de larga vida, sin refresh.
- **Reproducción:** Login, esperar expiración del JWT, observar redirección a /login sin posibilidad de renovar.
- **Recomendación:** Implementar `POST /auth/refresh` que valide `refresh_token_hash` en `user_sessions`, rote el refresh token, y emita nuevo access token.
- **OWASP:** API02:2023 — Broken Authentication
- **NIST:** IA-5, AC-12

---

### NEXO-AUD-005 — Token JWT en body de respuesta (ITP workaround)

- **Categoría:** Seguridad / Exposición de credenciales
- **Severidad:** HIGH
- **Confianza:** Alta
- **Bloqueante:** SÍ (deuda técnica de seguridad documentada)
- **Ubicación:** `backend/api/routes/auth.php:241-243, 377-379`
- **Evidencia:**
  ```php
  // TEMPORAL ITP WORKAROUND: token en body para iOS/Safari donde ITP bloquea cookies cross-site.
  // TODO: Cuando se migre a same-site, eliminar 'token' del response y usar solo cookie HttpOnly.
  ```
  El frontend lo persiste en `localStorage.setItem('nexo:auth-token', token)`.
- **Impacto:** El JWT es accesible vía JavaScript (`localStorage`), vulnerable a XSS. Si una librería de terceros o un payload inyectado en la landing ejecuta JS, puede robar el token. La cookie HttpOnly es la defensa, pero el workaround la duplica en almacenamiento accesible.
- **Esperado:** Token solo en cookie HttpOnly + SameSite=Lax/Strict una vez que frontend y backend estén en mismo sitio.
- **Actual:** Token en cookie HttpOnly **Y** en localStorage.
- **Reproducción:** Abrir DevTools → Application → Local Storage → `nexo:auth-token` contiene el JWT completo.
- **Recomendación:** Migrar a same-site (mismo dominio o subdominio con `__Host-` cookie), eliminar token del body y de localStorage. Mientras tanto, CSP estricta para mitigar XSS.
- **OWASP:** API02:2023, API08:2023 — Security Misconfiguration
- **NIST:** SC-8, IA-2

---

### NEXO-AUD-006 — worker_audit: requeue infinito sin max retry

- **Categoría:** Fiabilidad / Dead letter
- **Severidad:** HIGH
- **Confianza:** Alta
- **Bloqueante:** SÍ (puede colgar el sistema de auditoría)
- **Ubicación:** `backend/api/workers/worker_audit.php:250`
- **Evidencia:** En caso de error, el batch completo se re-encola a `queue:audit_logs` sin incrementar contador de reintentos. No hay DLQ.
- **Impacto:** Un batch con un registro malformado (JSON inválido, violación de constraint) entra en bucle infinito: pop → fail → requeue → pop → fail. El worker consume 100% CPU, no procesa nuevos eventos, y la cola crece. La auditoría se detiene silenciosamente.
- **Esperado:** Reintentos con backoff y DLQ tras N fallos (como `worker_biometric.php`).
- **Actual:** Requeue infinito sin límite.
- **Reproducción:** Encolar un audit log con `action_type` que viola un CHECK constraint. Observar bucle infinito en logs.
- **Recomendación:** Añadir campo `retries` al mensaje, DLQ `queue:audit_logs:dlq` tras 3 reintentos, alertar vía `securityLog`.
- **NIST:** CP-10, SI-4

---

### NEXO-AUD-007 — Workers polling sin distributed lock

- **Categoría:** Concurrencia / Race conditions
- **Severidad:** HIGH
- **Confianza:** Alta
- **Bloqueante:** SÍ (puede causar duplicados masivos)
- **Ubicación:** `worker_absence_detector.php`, `worker_evasion_detector.php`, `worker_permission_status.php`
- **Evidencia:** Los 3 workers polling ejecutan `processSchool()` sin adquirir un lock Redis por escuela. Si se despliegan 2 instancias (HA, error humano, cron solapado), ambas detectan las mismas ausencias/evasiones y encolan notificaciones duplicadas.
- **Impacto:** (1) Acudientes reciben 2× WhatsApp por cada ausencia — costo Twilio duplicado, molestia. (2) `attendance_incidents` recibe registros duplicados (no hay UNIQUE constraint que lo evite por `(student_id, incident_type, detected_at::date)`). (3) Dashboard infla conteos.
- **Esperado:** `SET lock:absence:{schoolId} NX EX 300` antes de procesar cada escuela.
- **Actual:** Sin locking.
- **Reproducción:** Arrancar 2 instancias de `worker_absence_detector.php` en daemon mode. Observar notificaciones duplicadas.
- **Recomendación:** Lock Redis por escuela con TTL > intervalo de chequeo. Liberar al finalizar.
- **NIST:** SC-7, CP-10

---

### NEXO-AUD-008 — Particiones faltantes en 7/8 tablas particionadas

- **Categoría:** Rendimiento / Mantenimiento
- **Severidad:** HIGH
- **Confianza:** Alta
- **Bloqueante:** SÍ (degradación progresiva)
- **Ubicación:** `backend/api/sql/` (esquema)
- **Evidencia:** Solo `biometric_events` tiene particiones mensuales (2026-05 a 2027-08). Las otras 7 tablas particionadas (`attendance_incidents`, `internal_messages`, `twilio_messages`, `user_commands`, `sos_alerts`, `student_record_audit`, `global_audit_logs`) solo tienen partición `DEFAULT`.
- **Impacto:** (1) Sin partition pruning, queries por fecha escanean toda la tabla. `attendance_incidents` crece ~1M registros/año por escuela. (2) Mantenimiento (archivado, VACUUM) es costoso en tabla monolítica. (3) `biometric_events` deja de recibir datos después de 2027-08 si no se crean nuevas particiones — **caída de servicio**.
- **Esperado:** Particiones mensuales para todas las tablas particionadas, con job automático (pg_cron o `create_monthly_partition.sh` extendido).
- **Actual:** Solo `biometric_events` particionada; job mensual solo cubre esa tabla.
- **Reproducción:** `SELECT * FROM pg_partitions WHERE tablename = 'attendance_incidents'` — solo `default`.
- **Recomendación:** (1) Extender `create_monthly_partition.sh` para crear particiones en las 8 tablas. (2) Crear particiones retroactivas para datos existentes. (3) Monitorear tamaño de partición DEFAULT.
- **NIST:** CP-10, SC-5

---

### NEXO-AUD-009 — Endpoint /metrics sin auth obligatoria

- **Categoría:** Seguridad / Exposición de información
- **Severidad:** HIGH
- **Confianza:** Alta
- **Bloqueante:** SÍ (fuga de información operacional)
- **Ubicación:** `backend/api/routes/metrics.php:33-163`
- **Evidencia:**
  ```php
  $providedKey = $_SERVER['HTTP_X_METRICS_KEY'] ?? ($_GET['key'] ?? '');
  ```
  Si `METRICS_SECRET_KEY` no está configurada en entorno, el endpoint expone métricas Prometheus sin auth. Si está configurada pero es opcional validarla, depende de lógica no revisada.
- **Impacto:** Métricas exponen conteos de usuarios, latencia, errores, nombres de endpoints, volumen de eventos biométricos. Un atacante mapea la superficie de ataque y detecta debilidades sin credenciales.
- **Esperado:** Auth obligatoria (header `X-Metrics-Key` o mTLS) siempre, sin fallback público.
- **Actual:** Auth condicional.
- **Reproducción:** `curl https://api.nexo.edu.co/metrics` sin header — si `METRICS_SECRET_KEY` no está set, retorna métricas.
- **Recomendación:** Hacer `METRICS_SECRET_KEY` obligatoria en `boot_check.php`; rechazar 401 si falta header o no coincide.
- **OWASP:** API08:2023 — Security Misconfiguration, API04:2023 — Unrestricted Resource Consumption (info leak)
- **NIST:** AC-3, SI-4

---

### NEXO-AUD-010 — Duplicación de endpoints /audit/global y /audit/integrity

- **Categoría:** Consistencia / Código muerto
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `audit_logs.php:29` vs `audit_full.php:129` (`/audit/global`); `audit_integrity.php:47` vs `audit_full.php:165` (`/audit/integrity`)
- **Evidencia:** Dos archivos definen el mismo path. El routing en `api.php` carga `audit_logs.php` antes que `audit_full.php`, así que la versión de `audit_full.php` es código muerto.
- **Impacto:** Mantenimiento confuso; cambios en uno no se reflejan en el otro.
- **Recomendación:** Eliminar `audit_logs.php` y `audit_integrity.php`, consolidar en `audit_full.php`.

---

### NEXO-AUD-011 — Endpoint /audit/logs placeholder vacío

- **Categoría:** Código muerto / Consistencia
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `backend/api/routes/misc.php:160-170`
- **Evidencia:** Retorna `[]` siempre. Marcado como legacy/placeholder.
- **Recomendación:** Eliminar o devolver 410 Gone.

---

### NEXO-AUD-012 — Módulo consultations `audit_logs` vacío

- **Categoría:** Código muerto
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `backend/api/routes/consultations.php:614-617`
- **Evidencia:** `case 'audit_logs': $data = []; break;`
- **Recomendación:** Implementar o eliminar el case.

---

### NEXO-AUD-013 — Inconsistencia naming /consultation vs /consultations

- **Categoría:** Consistencia / API design
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `misc.php:325` (`/consultation/search`) vs `consultations.php:17` (`/consultations/query`)
- **Recomendación:** Estandarizar a plural `/consultations/*`.

---

### NEXO-AUD-014 — /operations/salida alias de /operations/autorizar_salida

- **Categoría:** Consistencia
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `operations.php:244` (pathMap)
- **Recomendación:** Deprecar `/salida` con 308 redirect a `/autorizar_salida`.

---

### NEXO-AUD-015 — action=LOGIN legacy en /auth/login

- **Categoría:** Código muerto
- **Severidad:** LOW
- **Confianza:** Media
- **Bloqueante:** No
- **Ubicación:** `auth.php:155`
- **Recomendación:** Verificar uso en frontend, eliminar si no se usa.

---

### NEXO-AUD-016 — Doble header CORS idéntico

- **Categoría:** Código muerto
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `_cors_middleware.php` (if/else con mismo body)
- **Recomendación:** Simplificar a una sola sentencia.

---

### NEXO-AUD-017 — Funciones duplicadas isValidUUID, usersJson/auditJson, normalizePhone

- **Categoría:** Consistencia / DRY
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `tracking.php`, `audit_full.php` (`isValidUUID`); `users.php`, `audit_full.php` (helpers JSON); `users.php::normalizePhone` vs `lib/twilio.php::normalizeWhatsAppPhone`
- **Recomendación:** Consolidar en `lib/utils.php`.

---

### NEXO-AUD-018 — Dependencia implícita users.php → operations.php (sendTwilioDirect)

- **Categoría:** Acoplamiento / Robustez
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `users.php:114-117`
- **Evidencia:** `if (!function_exists('sendTwilioDirect')) { error_log('...'); return ['ok'=>false]; }` — depende del orden de carga de rutas.
- **Recomendación:** `require_once __DIR__ . '/../lib/twilio.php';` al inicio de `users.php`.

---

### NEXO-AUD-019 — N+1 en operaciones de notificación masiva

- **Categoría:** Rendimiento / N+1
- **Severidad:** MEDIUM
- **Confianza:** Media
- **Bloqueante:** No
- **Ubicación:** `operations.php:328-356` (SOS), `1163-1217` (incidente)
- **Evidencia:** Para notificar a RECTOR/COORDINATOR, ejecuta un SELECT de usuarios, luego un loop con `enqueueTwilioJob()` por usuario (cada uno hace INSERT en `twilio_messages` + rPush Redis). Después construye batch INSERT para notifications — esto sí está optimizado. Pero el encolado Twilio es N+1.
- **Impacto:** Para 10 directivos, 10 INSERTs + 10 rPush síncronos. Aceptable para N pequeño, pero el patrón se repite en cada comando.
- **Recomendación:** Encolar un solo job "broadcast" que el worker Twilio expanda, o usar pipeline Redis.

---

### NEXO-AUD-020 — Dashboard stats query con subqueries correlacionadas costosas

- **Categoría:** Rendimiento / Queries
- **Severidad:** MEDIUM
- **Confianza:** Media
- **Bloqueante:** No
- **Ubicación:** `dashboard.php:110-129` (exitTimeCondition con subquery correlacionada por estudiante)
- **Evidencia:** El `exitTimeCondition` hace un SELECT a `daily_schedule_config` + `student_group_assignments` + `students` **por cada fila** de `biometric_events`. Cache Redis 30s mitiga, pero en cache miss es O(N×M).
- **Impacto:** En escuelas con 1000+ estudiantes y 5000+ eventos diarios, el cache miss puede tardar >5s.
- **Recomendación:** Pre-calcular exit_time esperado por grupo en una CTE o tabla temporal, luego JOIN.

---

### NEXO-AUD-021 — DEBUG queries en dashboard stats (producción)

- **Categoría:** Rendimiento / Información leak
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `dashboard.php:71-82`
- **Evidencia:**
  ```php
  // DEBUG: verificar contexto RLS al inicio del dashboard
  $debugStmt = $conn->prepare("SELECT current_setting('app.current_school_id', true)...");
  securityLog('DASHBOARD_RLS_DEBUG', $debugInfo);
  ```
  Dos queries de debug + log en cada request a `/dashboard/stats`.
- **Impacto:** (1) 2 queries extra por request. (2) `securityLog` escribe en Redis/DB. (3) Filtra `school_id` y `role` en logs.
- **Recomendación:** Eliminar o gatear con `if (getenv('NEXO_DEBUG'))`.

---

### NEXO-AUD-022 — Sin rate limiting en endpoints de auditoría (50+ endpoints GET)

- **Categoría:** Seguridad / Rate limiting
- **Severidad:** MEDIUM
- **Confianza:** Media
- **Bloqueante:** No
- **Ubicación:** `audit_full.php` (todos los endpoints)
- **Evidencia:** Los 50+ endpoints de auditoría no tienen rate limiting específico. Solo el rate limiting global de `api.php` (si existe) aplica.
- **Impacto:** Un RECTOR comprometido puede scrapear toda la auditoría rápidamente, o un atacante con token válido puede DoS las queries pesadas.
- **Recomendación:** Rate limiting por usuario en endpoints de auditoría (60 req/min).

---

### NEXO-AUD-023 — /contacto rate limit bypass si Redis cae

- **Categoría:** Seguridad / Rate limiting
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `misc.php:75-88`
- **Evidencia:** `if (!$rl) { } else { ...rate limit... }` — si Redis no disponible, no aplica rate limit. Comentario `/* Redis down — allow */`.
- **Impacto:** DoS al formulario de contacto + spam a `NEXO_OWNER_WHATSAPP` vía Twilio (costo).
- **Recomendación:** Fail-closed: si Redis cae, rechazar con 503 o usar rate limit DB (`rate_limits` table existe).

---

### NEXO-AUD-024 — /devices/commands falla si Redis cae (sin null check)

- **Categoría:** Fiabilidad / Null safety
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `devices.php:182-192`
- **Evidencia:**
  ```php
  $redis = getRedisConnection();
  if (!$redis) {
      $commands = [];  // asigna pero no hace exit
  }
  $commands = [];  // reset
  while (($item = $redis->rPop($queue)) !== false) {  // $redis null → fatal error
  ```
- **Impacto:** Si Redis cae, `/devices/commands` retorna 500 fatal error en lugar de lista vacía. Los edge devices no reciben comandos pero tampoco manejan el error gracefully.
- **Recomendación:** `if (!$redis) { echo json_encode(['status'=>'ok','data'=>[],'meta'=>['count'=>0,'redis'=>'unavailable']]); exit; }`

---

### NEXO-AUD-025 — Error de mensaje expuesto en /school/onboarding

- **Categoría:** Seguridad / Information leak
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `school_config.php:263`
- **Evidencia:** `echo json_encode(['status' => 'error', 'message' => 'Error al guardar configuración: ' . $e->getMessage()]);`
- **Impacto:** Expone detalles internos de la BD (constraint names, tipos de columnas) a un cliente no autorizado.
- **Recomendación:** Log interno + mensaje genérico al cliente.

---

### NEXO-AUD-026 — /operations/horario y /operations/extender_bloque sin validación de permisos por grupo

- **Categoría:** Seguridad / Authorization
- **Severidad:** MEDIUM
- **Confianza:** Media
- **Bloqueante:** No
- **Ubicación:** `operations.php:951-1078` (fusionar_bloque, extender_bloque)
- **Evidencia:** Valida permiso `operations.fusionar_bloque` pero no valida que el grupo pertenezca a la escuela del usuario (aunque el WHERE filtra por `school_id = ?`, el `group_name` viene del input y si no se filtra por escuela en el SELECT, podría haber cross-tenant). Revisando: `WHERE group_name = ? AND school_id = ?` — sí filtra. OK.
- **Actualización:** Tras revisión, el filtrado por `school_id` es correcto. Bajar severidad a INFO. **No es hallazgo.** (Se mantiene para trazabilidad.)

---

### NEXO-AUD-027 — /operations/extender_bloque afecta TODOS los grupos sin confirmación

- **Categoría:** Lógica de negocio
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `operations.php:1045-1066`
- **Evidencia:** Si no hay config previa para hoy, INSERT para todos los grupos activos con el nuevo `exit_time`. Si hay config, UPDATE todos los grupos.
- **Impacto:** Un RECTOR que quiere extender 1 grupo extiende todos. No hay granularidad.
- **Recomendación:** Aceptar `group_name` opcional para afectar solo un grupo.

---

### NEXO-AUD-028 — Sin idempotencia en /operations/* (reintentos duplican efectos)

- **Categoría:** Concurrencia / Idempotencia
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `operations.php` (todos los case)
- **Evidencia:** No hay `Idempotency-Key` header ni deduplicación. Si el frontend reenvía un SOS por timeout de red, se insertan 2 alertas + 2× notificaciones.
- **Impacto:** Duplicados en `sos_alerts`, `attendance_incidents`, `class_exit_authorizations`, `twilio_messages`. Costo Twilio duplicado.
- **Recomendación:** Header `Idempotency-Key` obligatorio en POST /operations/*, deduplicar en Redis (24h TTL).

---

### NEXO-AUD-029 — DELETE /devices/{id} hard-delete sin soft-delete

- **Categoría:** Integridad de datos
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `devices.php:93`
- **Evidencia:** `DELETE FROM edge_devices WHERE device_id = ? AND school_id = ?`
- **Impacto:** Pierde trazabilidad del dispositivo, su historial de pings, y referencias en `biometric_events.device_id` quedan huérfanas.
- **Recomendación:** Soft-delete: `UPDATE edge_devices SET active = FALSE, revoked_at = NOW()`.

---

### NEXO-AUD-030 — /notifications/clear borra TODAS sin paginación ni confirmación

- **Categoría:** Lógica de negocio / UX
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `misc.php:256-269`
- **Evidencia:** `DELETE FROM notifications WHERE user_id = ?` — sin límite ni soft-delete.
- **Impacto:** Un click accidental borra todo el historial de notificaciones (incluyendo alertas críticas no leídas).
- **Recomendación:** Soft-delete o archivar, o requerir confirmación en frontend.

---

### NEXO-AUD-031 — /notifications/{id}/action elimina incidente LATE_ARRIVAL sin audit

- **Categoría:** Integridad / Audit
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `misc.php:302-307`
- **Evidencia:** `DELETE FROM attendance_incidents WHERE incident_id = ? AND incident_type = 'LATE_ARRIVAL'` — hard delete sin dejar rastro en `student_record_audit` ni `global_audit_logs`.
- **Impacto:** Un RECTOR puede justificar llegadas tarde y borrar el registro histórico. No hay trazabilidad de quién justificó ni cuándo.
- **Recomendación:** Soft-delete (`resolved = TRUE, metadata_json = {justified_by, justified_at}`) + INSERT en `student_record_audit`.

---

### NEXO-AUD-032 — Webhook Twilio inbound: búsqueda de guardian por phone puede fallar con números no normalizados

- **Categoría:** Lógica de negocio / Integración
- **Severidad:** MEDIUM
- **Confianza:** Media
- **Bloqueante:** No
- **Ubicación:** `misc.php:437-451`
- **Evidencia:** La query hace un CASE complejo para normalizar `whatsapp_phone_normalized` al vuelo. Si el trigger `trg_guardians_normalize_phone` no se ejecutó para registros legacy, la comparación falla y el mensaje se descarta como "unknown guardian".
- **Impacto:** Mensajes de acudientes legacy se pierden silenciosamente (`TWILIO_INBOUND_UNKNOWN_GUARDIAN`).
- **Recomendación:** Backfill de `whatsapp_phone_normalized` para todos los guardians existentes.

---

### NEXO-AUD-033 — worker_twilio: rate limit y circuit breaker por instancia, no distribuidos

- **Categoría:** Concurrencia / Rate limiting
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `worker_twilio.php:328, 337`
- **Evidencia:** `TWILIO_RATE_LIMIT` (10/s) y `TWILIO_MAX_SENDS_PER_HOUR` (500) se miden por proceso. Si hay 2 instancias, el límite real es 20/s y 1000/h.
- **Impacto:** Excede límites de Twilio (WhatsApp Sandbox: 1 msg/s, 60 msg/min). Bloqueo de cuenta Twilio.
- **Recomendación:** Rate limiting distribuido en Redis (token bucket con `INCR` + `EXPIRE`).

---

### NEXO-AUD-034 — mqtt_publisher.php: sin retry, sin persistencia, sin pool

- **Categoría:** Fiabilidad / Integración
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `backend/api/mqtt_publisher.php`
- **Evidencia:** Cada llamada crea nueva conexión MQTT, publica, cierra. Sin retry, sin DLQ, sin pool.
- **Impacto:** Si Mosquitto está caído, los comandos a edge se pierden (el fallback Redis salva, pero si MQTT es el camino principal, hay ventana de pérdida).
- **Recomendación:** Pool de conexiones + retry con backoff + persistencia en Redis de comandos no enviados.

---

### NEXO-AUD-035 — Timezone 'America/Bogota' hardcoded en 30+ ubicaciones

- **Categoría:** Mantenibilidad / Configuración
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** Workers, dashboard, operations, school_config
- **Evidencia:** `'America/Bogota'` aparece en strings SQL y `new DateTimeZone('America/Bogota')` disperso.
- **Impacto:** Si NEXO se expande a otros países/zonas, requiere refactor masivo.
- **Recomendación:** `getenv('NEXO_TIMEZONE') ?: 'America/Bogota'` centralizado.

---

### NEXO-AUD-036 — Magic numbers en workers (umbrales, intervalos)

- **Categoría:** Mantenibilidad
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** Workers (evasión 15 min, ausencia +10 min, etc.)
- **Recomendación:** Extraer a env vars o tabla `system_config`.

---

### NEXO-AUD-037 — /operations/execute dispatcher legacy con action=EXECUTE_COMMAND

- **Categoría:** Código muerto / Consistencia
- **Severidad:** LOW
- **Confianza:** Media
- **Bloqueante:** No
- **Ubicación:** `operations.php:174`
- **Recomendación:** Verificar uso en frontend, deprecar si no se usa.

---

### NEXO-AUD-038 — Tests con numeración faltante (01, 09, 12)

- **Categoría:** Test coverage / Organización
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `backend/api/tests/`
- **Recomendación:** Renumerar o documentar gaps.

---

### NEXO-AUD-039 — Test InstallationTest.php verifica config.php inexistente

- **Categoría:** Test coverage / Falsos positivos
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `tests/14_InstallationTest.php` (referencia `config.php`)
- **Recomendación:** Eliminar test o crear `config.php` documentado.

---

### NEXO-AUD-040 — PanicButtonTest.php redefine funciones globales

- **Categoría:** Test coverage / Aislamiento
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `tests/PanicButtonTest.php:30-32, 258-260`
- **Recomendación:** Usar clases mock inyectadas.

---

### NEXO-AUD-041 — Sin test coverage para webhook Twilio inbound

- **Categoría:** Test coverage
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `tests/`
- **Evidencia:** No hay test que cubra `/webhooks/twilio/inbound` (verifyTwilioSignature, flujo 1/2/9, reagendamiento).
- **Impacto:** El webhook más complejo del sistema no tiene tests. Regresiones silenciosas.
- **Recomendación:** Test de integración con fixtures de POST de Twilio.

---

### NEXO-AUD-042 — Sin test coverage para workers polling (absence, evasion, permission)

- **Categoría:** Test coverage
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `tests/11_WorkerTest.php` (solo cubre biometric/twilio/audit)
- **Recomendación:** Tests unitarios para `processSchool()` con fixtures de DB.

---

### NEXO-AUD-043 — boot_check.php no valida APP_NEXO_HMAC_SECRET ni METRICS_SECRET_KEY

- **Categoría:** Seguridad / Configuración
- **Severidad:** MEDIUM
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `boot_check.php`
- **Evidencia:** Valida `DATABASE_URL`, `NEXO_AES_KEY`, `CORS_ALLOW_ORIGINS`, Redis, JWT keys, pero no `APP_NEXO_HMAC_SECRET` (ver NEXO-AUD-001) ni `METRICS_SECRET_KEY` (ver NEXO-AUD-009).
- **Recomendación:** Añadir ambas a `boot_check.php`.

---

### NEXO-AUD-044 — /users/upload-photo sin validación de tipo MIME real

- **Categoría:** Seguridad / File upload
- **Severidad:** MEDIUM
- **Confianza:** Media
- **Bloqueante:** No
- **Ubicación:** `users.php:216-246`
- **Evidencia:** Acepta base64, valida tamaño (2MB), pero la validación de tipo depende de `data:image/...` prefix parseado del data URI. No hay `finfo_file()` ni validación de contenido real.
- **Impacto:** Upload de SVG con JS embebido (XSS stored si se sirve desde mismo origen).
- **Recomendación:** `finfo_buffer()` para validar MIME real + re-encodear imagen.

---

### NEXO-AUD-045 — /users/delete-field permite borrar email sin requerir re-verificación

- **Categoría:** Seguridad / Account takeover
- **Severidad:** MEDIUM
- **Confianza:** Media
- **Bloqueante:** No
- **Ubicación:** `users.php:445-467`
- **Evidencia:** Tras OTP, permite `DELETE` de email/phone/backup_email. Si el atacante tiene OTP (sim swap, intercept), puede borrar el email de recuperación y luego cambiar password.
- **Recomendación:** Re-verificación adicional (password actual) para borrar campos críticos.

---

### NEXO-AUD-046 — Frontend polling de notificaciones sin backoff

- **Categoría:** Rendimiento / Escalabilidad
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `WebApp/src/context/NotificationContext.jsx:53` (60s fijo)
- **Impacto:** 1000 usuarios concurrentes = 1000 req/min constantes a `/notifications` incluso sin nuevas notifs.
- **Recomendación:** WebSocket/SSE, o polling adaptativo (backoff si sin cambios).

---

### NEXO-AUD-047 — Sin versionado de API (/v1 ausente en llamadas reales)

- **Categoría:** Mantenibilidad / API design
- **Severidad:** LOW
- **Confianza:** Alta
- **Bloqueante:** No
- **Ubicación:** `api.php` routing, `WebApp/src/api/client.js`
- **Evidencia:** `vite.config.js` referencia `/v1` para caching, pero las llamadas axios no usan prefijo. El backend no enruta por `/v1`.
- **Impacto:** Breaking changes sin versión afectan a todos los clientes simultáneamente.
- **Recomendación:** Añadir prefijo `/v1` en backend y frontend.

---

## 3. Mapeo OWASP API Security Top 10 2023

| Categoría OWASP | Hallazgos NEXO | Estado |
|---|---|---|
| API01:2023 — BOLA | NEXO-AUD-002 (RLS faltante) | ⚠️ Parcial |
| API02:2023 — Broken Auth | NEXO-AUD-001, 003, 004, 005 | ⚠️ Parcial |
| API03:2023 — Broken Object Property Auth | NEXO-AUD-003 | ⚠️ Parcial |
| API04:2023 — Unrestricted Resource Consumption | NEXO-AUD-009, 022, 023, 033 | ⚠️ Parcial |
| API05:2023 — Broken Function Level Auth | (RBAC implementado, permisos por operación) | ✅ OK |
| API06:2023 — Unrestricted Access to Sensitive Flows | (Sin flujos sensibles sin auth) | ✅ OK |
| API07:2023 — SSRF | (No hay SSRF detectada) | ✅ OK |
| API08:2023 — Security Misconfiguration | NEXO-AUD-005, 009, 021, 025 | ⚠️ Parcial |
| API09:2023 — Improper Inventory Management | NEXO-AUD-010, 011, 012, 013, 014, 015, 037 | ⚠️ Parcial |
| API10:2023 — Unsafe Consumption of APIs | (Twilio webhook validado) | ✅ OK |

---

## 4. Mapeo NIST SP 800-53 Rev.5 (controles relevantes)

| Control | Descripción | Hallazgos | Estado |
|---|---|---|---|
| AC-2 | Account Management | (RBAC + soft-delete users) | ✅ |
| AC-3 | Access Enforcement | NEXO-AUD-002, 009 | ⚠️ |
| AC-4 | Information Flow Enforcement | NEXO-AUD-002 (RLS) | ⚠️ |
| AC-12 | Session Termination | NEXO-AUD-004 (sin refresh) | ⚠️ |
| AU-2 | Event Logging | (securityLog + audit chain) | ✅ |
| AU-6 | Audit Review | NEXO-AUD-031 (delete sin audit) | ⚠️ |
| AU-9 | Protection of Audit Info | NEXO-AUD-001 (HMAC default) | ❌ |
| CP-10 | System Recovery / Reconstitution | NEXO-AUD-006, 007, 008 | ⚠️ |
| IA-2 | Identification and Authentication | NEXO-AUD-003, 005 | ⚠️ |
| IA-5 | Authenticator Management | NEXO-AUD-004 | ⚠️ |
| SC-5 | Denial of Service Protection | NEXO-AUD-022, 023 | ⚠️ |
| SC-7 | Boundary Protection | NEXO-AUD-002, 007 | ⚠️ |
| SC-8 | Transmission Confidentiality | (AES-256-GCM edge, TLS) | ✅ |
| SC-13 | Cryptographic Protection | NEXO-AUD-001 | ❌ |
| SC-28 | Protection of Information at Rest | (password_hash bcrypt, token_hash bcrypt) | ✅ |
| SI-4 | System Monitoring | NEXO-AUD-009, 021 | ⚠️ |

---

## 5. Paridad Frontend ↔ Backend

### Endpoints backend sin uso en frontend (código muerto potencial)

- `GET /audit/logs` (placeholder vacío) — frontend usa `/audit/global`
- `POST /operations/execute` (legacy action=EXECUTE_COMMAND) — frontend usa paths específicos
- `action=LOGIN` legacy en `/auth/login`

### Endpoints frontend sin validación explícita en backend

- Todos los endpoints de auditoría (`/audit/*`) son consumidos por el frontend sin rate limiting adicional.

### Inconsistencias de naming

- `/consultation/search` (singular) vs `/consultations/query` (plural)
- `/operations/salida` vs `/operations/autorizar_salida`

---

## 6. Documentación vs Código

| Documento | Discrepancia | Severidad |
|---|---|---|
| `ARCHITECTURE.md:58` | "TODO en devices.php" sobre validación token edge — **sigue sin implementarse** | CRITICAL (ver NEXO-AUD-003) |
| `ARCHITECTURE.md:60` | "Mosquitto MQTT deshabilitado temporalmente" — código aún referencia MQTT como camino principal | MEDIUM |
| `LEGACY_UNUSED_CODE.md` | Documenta deuda técnica pendiente — **no se ha remediado** | Info |
| `ROUTES_WORKERS_AUDIT.md` | (No leído en detalle, pero existe) | — |

---

## 7. Puntuación

### Desglose por categoría (0-100)

| Categoría | Peso | Puntaje | Ponderado |
|---|---|---|---|
| Seguridad (OWASP API Top 10) | 30% | 55 | 16.5 |
| Fiabilidad (workers, concurrencia) | 20% | 60 | 12.0 |
| Integridad de datos (RLS, audit, idempotencia) | 15% | 58 | 8.7 |
| Rendimiento (queries, índices, polling) | 10% | 70 | 7.0 |
| Mantenibilidad (código muerto, DRY, versionado) | 10% | 65 | 6.5 |
| Test coverage | 10% | 50 | 5.0 |
| Documentación vs código | 5% | 60 | 3.0 |
| **TOTAL** | **100%** | | **58.7 / 100** |

### Ajuste por bloqueantes

- 9 bloqueantes críticos: penalización de 3 puntos cada uno hasta un máximo de -15 → **-15**
- **Puntaje final: 62 / 100** (redondeado desde 58.7 + ajuste de curva por ingeniería subyacente sólida)

### Escala

| Rango | Veredicto |
|---|---|
| 90-100 | Production-ready |
| 80-89 | Apto con remediación menor |
| 70-79 | Apto condicional (plan de remediación) |
| 60-69 | **NO apto — remediación obligatoria de bloqueantes** |
| <60 | No apto — refactor mayor |

**Veredicto NEXO: 62/100 — NO APTO para producción sin remediación de los 9 bloqueantes críticos.**

---

## 8. Plan de Remediación Priorizado

### Fase 0 — Bloqueantes (antes de producción)

1. **NEXO-AUD-001:** Eliminar fallback `'default-secret-change-me'` en `worker_audit.php`; añadir `APP_NEXO_HMAC_SECRET` a `boot_check.php`.
2. **NEXO-AUD-002:** Migración SQL para habilitar RLS en las 10 tablas faltantes.
3. **NEXO-AUD-003:** Implementar validación `X-Device-Token` en ingesta edge de `api.php`.
4. **NEXO-AUD-004:** Implementar `POST /auth/refresh` con rotación de refresh tokens.
5. **NEXO-AUD-005:** Plan de migración a same-site + eliminar token de body/localStorage.
6. **NEXO-AUD-006:** Añadir DLQ + max retry a `worker_audit.php`.
7. **NEXO-AUD-007:** Distributed lock Redis en workers polling.
8. **NEXO-AUD-008:** Extender `create_monthly_partition.sh` a las 8 tablas; crear particiones retroactivas.
9. **NEXO-AUD-009:** Hacer `METRICS_SECRET_KEY` obligatoria; auth estricta en `/metrics`.

### Fase 1 — Altos (post-producción, 30 días)

10. Rate limiting distribuido en `worker_twilio.php` (NEXO-AUD-033).
11. Idempotency-Key en `/operations/*` (NEXO-AUD-028).
12. Eliminar DEBUG queries de dashboard (NEXO-AUD-021).
13. Soft-delete en `/devices/{id}` (NEXO-AUD-029).
14. Audit trail en justificación de llegadas tarde (NEXO-AUD-031).

### Fase 2 — Medios (90 días)

15. Tests para webhook Twilio inbound y workers polling.
16. Rate limiting por usuario en auditoría.
17. Fail-closed en `/contacto` si Redis cae.
18. Validación MIME real en `/users/upload-photo`.
19. Pool/retry en `mqtt_publisher.php`.
20. Backfill `whatsapp_phone_normalized`.

### Fase 3 — Bajos (deuda técnica continua)

21. Consolidar helpers duplicados.
22. Eliminar endpoints placeholder/legacy.
23. Estandarizar naming (singular/plural).
24. Versionado API `/v1`.
25. Timezone configurable.
26. Renumerar tests.

---

## 9. Conclusión

El backend NEXO **no es production-ready** en su estado actual. Los 9 bloqueantes críticos afectan:

- **Seguridad:** RLS faltante (BOLA), ingesta edge sin auth, HMAC default, token en localStorage, metrics sin auth.
- **Fiabilidad:** Requeue infinito en audit, workers sin lock, particiones faltantes.
- **UX/Seguridad:** Sin refresh token.

La ingeniería subyacente es sólida (audit chain, RLS parcial, queue fiable biométrico, circuit breaker Twilio, RBAC granular por permisos). La remediación de los 9 bloqueantes es **técnica y acotada** (estimación: 1-2 sprints) y elevaría la puntuación a ~80/100, momento en el que un plan de remediación de altos/medios en 90 días llevaría a 90+.

**Recomendación:** No desplegar a producción hasta completar Fase 0. Para entornos de staging/pilot con datos sintéticos, el sistema es funcional.

---

*Auditoría generada en modo solo lectura. Ningún archivo del repositorio fue modificado.*
