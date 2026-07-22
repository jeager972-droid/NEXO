# NEXO — Auditoría técnica del backend PHP, SQL, tests y edge

> Documento generado tras revisión completa de los archivos que componen la capa de enrutamiento, workers, infraestructura de entrada, migraciones SQL, suite de tests y el backend edge (C++/Python) de NEXO.

---

## 1. Arquitectura general

El backend sigue un diseño monolítico en PHP con un **único punto de entrada** (`backend/api/api.php`) que, tras configurar seguridad, CORS, rate-limiting y conexiones, incluye el archivo de `routes` correspondiente al path solicitado. Los workers son procesos PHP independientes que consumen colas Redis y escriben en PostgreSQL. El esquema de base de datos es idempotente, particionado por rango y protegido con RLS.

### 1.1 Capas principales

```
Cliente React/Tauri/PWA
        │
        ▼ axios/fetch + Bearer token / cookie token
backend/api/api.php
        │
        ├── _cors_middleware.php   # CORS + OPTIONS preflight
        ├── _auth_middleware.php   # JWT, RBAC, Redis, panic mode
        └── routes/*.php           # lógica de endpoints
        │
        ▼
   PostgreSQL (datos + RLS por app.current_role)
        ▲
   Redis (cache, colas, rate limit, JWT blocklist, panic cache)
        │
   workers/*.php (audit, biometric, twilio)

backend/edge/main.cpp (Raspberry Pi 4 / Linux)
        │
        ├── SyncWorker -> CloudManager -> backend/api.php (POST /edge/ingest)
        ├── MqttCommandWorker -> broker MQTT -> comandos cloud
        ├── HealthMonitor -> reinicio si worker muerto
        ├── HardwareWatchdog -> /dev/watchdog
        └── HAL (sensor, display, notificaciones) vía interfaces
```

### 1.2 Workers

| Worker | Cola(s) | Responsabilidad |
|--------|---------|-------------------|
| `worker_audit.php` | `queue:audit_logs` | Persiste `global_audit_logs` con cadena de hashes HMAC por escuela. |
| `worker_biometric.php` | `queue:biometric_ingest`, `queue:biometric_processing`, `queue:biometric_dlq` | Procesa eventos biométricos con patrón reliable queue (LMOVE + Lua + GC). |
| `worker_twilio.php` | `queue:twilio`, `queue:twilio:delayed` | Envía WhatsApp vía Twilio, deduplica, rate limit, backoff exponencial. |

### 1.3 Infraestructura PHP suelta (no routes ni workers)

| Archivo | Responsabilidad |
|---------|-----------------|
| `api.php` | Front controller, headers de seguridad, rate limit global, routing por prefijo, ingesta cifrada edge, endpoint `/health`. |
| `db.php` | Crear PDO a PostgreSQL desde env vars, setear `app.nexo_hmac_secret`. |
| `boot_check.php` | Validar variables críticas antes de arrancar; falla con 503 si falta algo. |
| `mqtt_publisher.php` | Publicar comandos a edge devices vía MQTT (fallback a Redis). |

### 1.4 SQL y migraciones

| Archivo | Uso |
|---------|-----|
| `sql/nexo_full_migration.sql` | Esquema base idempotente: tablas, índices, constraints, funciones, triggers, RLS, particiones. |
| `sql/nexo_seed.sql` | Datos de prueba/demo: escuela, roles, usuarios, aulas, grupos, estudiantes, acudientes, eventos. |
| `sql/schema_migrations_backfill.sql` | Registra migraciones previas en `schema_migrations` para evitar reejecución. |
| `sql/fix_migration.sql` | Parche manual por error de orden en script original (legacy). |
| `sql/cleanup_maintenance.sql` | Limpieza programada de jwt_blocklist y rate_limits. |
| `sql/purge_notification_garbage.sql` | Eliminación manual de datos de prueba/notificaciones basura. |

### 1.5 Suite de tests

| Test | Enfoque |
|------|---------|
| `02_ForeignKeyTest` — `15_ProductionReadinessTest` | Tests PHPUnit estáticos del esquema SQL (FK, constraints, índices, triggers, RLS, particiones, seed, seguridad, regresión, instalación, producción). |
| `FullSystemAlignmentTest` / `PlanComplianceTest` / `SchemaPhpAlignmentTest` / `integration_test.php` | Scripts autónomos que emiten veredicto de deploy SQL↔PHP. |
| `PanicButtonTest.php` | Test funcional del botón de pánico con MockRedis. |
| `SchemaIntegrityTest.php` | Parseo exhaustivo del SQL sin PostgreSQL corriendo. |

### 1.6 Backend Edge (Raspberry Pi 4 / Linux)

| Componente | Responsabilidad |
|------------|-----------------|
| `main.cpp` | Punto de entrada, menú, bucle principal, HealthMonitor, watchdog. |
| `base_de_datos/SqliteManager` | BD local SQLite con estudiantes, patrones, audit_trail. |
| `base_de_datos/CloudManager` | POST cifrado AES-256-GCM a `api.php` (endpoint edge ingest / acciones). |
| `base_de_datos/Encryption` | AES-256-GCM, base64, mlock, almacenamiento seguro de clave/token. |
| `mqtt/MqttCommandWorker` | Suscripción persistente a comandos M2M por MQTT. |
| `hal/*` | Interfaces de sensor, display, HTTP y notificación. |
| `hardware/dev_stub/*` | Implementaciones stub para desarrollo sin hardware. |
| `hardware/real/*` | Implementaciones reales: ZK9500, OLED SSD1306 I2C, GPIO RPi4. |
| `hardware/HardwareWatchdog` | Wrapper de /dev/watchdog. |
| `interoperabilidad/AuditTrail` | Facade de auditoría local; bloquea si falla SQLite. |
| `utils/ConfigManager` | Configuración JSON. |
| `utils/Logger` | Inicialización spdlog. |
| `utils/NexoResult` | Monada de resultado y enumeración de errores. |
| `utils/edge_monitor.py` | Heartbeat periódico hacia `/devices/ping`. |

### 1.7 Flujo típico del edge (ingreso biométrico)

```
Sensor biométrico -> searchUser()
        │
        ▼
main.cpp handleBiometricMatch(huellaId)
        │
        ├── SqliteManager::getEstudianteByHuellaID
        ├── checkLateStatus() -> PUNTUAL / MANANA / TARDE
        ├── AuditTrail::logEvent() -> SQLite
        │       (si falla: bloquea acceso)
        ├── INotification::notifySuccess()
        ├── IDisplay::showMessage(nombre, "Ingreso <status>")
        ├── updatePattern() -> resumen de asistencia
        └── SyncWorker::nudge() -> sync próximo lote
```

---

## 2. Flujo completo de una petición típica

### 2.1 Autenticación

```
HTTP Request
    │
    ▼
_cors_middleware.php → Access-Control headers / 204 OPTIONS
    │
    ▼
routes/*.php require_once _auth_middleware.php
    │
    ▼
extractBearerToken()  ← header Authorization, redirect header, getallheaders(), apache_request_headers() o cookie 'token'
    │
    ▼
verifyJwtToken()
    ├── RS256 (JWT_PRIVATE_KEY/PUBLIC_KEY) o HS256 (JWT_SECRET)
    ├── exp / nbf / iat / iss / aud / sub / jti
    ├── isJwtRevoked(jti)   → Redis jwt:blocklist:<jti> fallback jwt_blocklist
    └── isSchoolInPanicMode(school_id, iat) → Redis panic:school:<id> fallback school_panic_events
    │
    ▼
requireAuth(['RECTOR', 'COORDINATOR'])  ← carga usuario + permisos; set_config app.current_school_id / app.current_role
```

### 2.2 Ejecución de un comando operativo

```
POST /operations/execute {action:'citacion', ...}
    │
    ▼
requireAuth() + logUserCommand()
    │
    ▼
switch($action):
  'citacion' → INSERT twilio_messages / INSERT user_commands / INSERT attendance_incidents / enqueueTwilioJob()
    │
    ▼
enqueueTwilioJob() → rPush queue:twilio + registro QUEUED
    │
    ▼
worker_twilio.php consume → sendTwilioWhatsAppSmart() → Twilio API
    │
    ▼
Twilio POST /webhooks/twilio/status → routes/twilio_delivery.php → UPDATE delivery_status
```

### 2.3 Ingesta biométrica

```
Edge device → POST /edge/ingest (api.php)
    │
    ▼
decryptPayload(AES-256-GCM) → validar device token
    │
    ▼
rPush queue:biometric_ingest
    │
    ▼
worker_biometric.php scriptReliablePop(LMOVE + timestamp)
    │
    ▼
processJob()
    ├── SYNC_ATTENDANCE → INSERT biometric_events (ON CONFLICT fingerprint, timestamp)
    ├── REGISTER_STUDENT → upsert students + crear/relacionar guardian
    └── DELETE_STUDENT → UPDATE active=FALSE
    │
    ▼
lRem processing tras commit; GC cada 60s reinserta zombies >5min
```

---

## 3. Dependencias

### 3.1 Qué usan los archivos de `routes`

- `_auth_middleware.php` (todos los archivos excepto twilio_delivery.php en GET y endpoints no autenticados).
- `_cors_middleware.php` (incluido una vez en `api.php` antes de routing).
- `lib/twilio.php` (`operations.php`, `users.php`, `misc.php`, `worker_twilio.php` lo requiere).
- `lib/RiskScoreEngine.php` (`admin.php`, `behavior.php`).
- `$conn` / `$pdo` (PDO) definido en `db.php` e incluido por `api.php`.
- `$cleanPath`, `$method`, `$input` (parseados en `api.php`).

### 3.2 Qué archivos son consumidos por otros

| Archivo | Consumidores |
|---------|--------------|
| `_auth_middleware.php` | Todos los `routes/*.php` y los workers (indirectamente a través de funciones como `getRedisConnection`). |
| `lib/twilio.php` | `routes/operations.php`, `routes/users.php`, `routes/misc.php`, `workers/worker_twilio.php`. |
| `lib/RiskScoreEngine.php` | `routes/admin.php`, `routes/behavior.php`. |
| `workers/*.php` | Invocados por supervisor/Docker; reciben trabajos encolados por `api.php` y `routes/*.php`. |

### 3.3 Dependencias externas

- **PostgreSQL**: datos, funciones `fn_validate_audit_chain`, RLS mediante `app.current_role` y `app.current_school_id`.
- **Redis**: colas, caché, rate limiting, JWT blocklist, panic cache, heartbeats.
- **Twilio**: WhatsApp outbound/inbound, templates, status callbacks.
- **openssl**: firma/verificación JWT RS256.
- **MQTT** (opcional): `mqtt_publisher.php` para comandos a edge devices; fallback a Redis.

---

## 4. Acoplamiento (coupling)

### 4.1 Acoplamiento alto

- **Variables globales**: `$conn`, `$pdo`, `$cleanPath`, `$input`, `$method` son esperados por casi todos los archivos de `routes`. Esto dificulta testing unitario y reutilización.
- **Autenticación centralizada**: `_auth_middleware.php` es requerido por casi todas las rutas. Cualquier cambio en `requireAuth` o `verifyJwtToken` afecta a todo el sistema.
- **Twilio**: varias rutas (`operations`, `users`, `misc`) dependen directamente de `lib/twilio.php`; además `users.php` verifica con `function_exists('sendTwilioDirect')` porque asume que `operations.php` se cargó antes (acoplamiento de orden de inclusión).
- **Base de datos y RLS**: múltiples workers y rutas usan `set_config('app.current_role', 'SYSTEM_WORKER'/'EDGE_NODE', ...)` para bypassar RLS. Esto acopla la lógica a la implementación de seguridad en PostgreSQL.

### 4.2 Acoplamiento moderado/bajo

- **RiskScoreEngine**: bien encapsulado; solo `admin.php` y `behavior.php` lo usan.
- **Audit helpers** (`auditJson`, `auditError`, `auditFilters` en `audit_full.php`): locales al archivo.
- **Workers**: desacoplados del request HTTP, comunican por Redis.

---

## 5. Código muerto o legacy

Ver archivo separado `LEGACY_UNUSED_CODE.md` para el listado detallado. Resumen:

- `routes/_cors_middleware.php` y `boot_check.php` son pasivos; no son "muertos" pero no tienen lógica dinámica.
- `routes/misc.php` contiene endpoint `GET /audit/logs` vacío (solo devuelve `[]` realmente no implementado).
- `routes/users.php`: `/users/me/photo` es legacy; `/users/me/extended` ya incluye `profile_photo_url`.
- `routes/operations.php` y `misc.php` repiten lógica de Twilio (envío directo + encolado) con funciones similares; hay oportunidad de consolidar.
- `routes/devices.php` TODO indica que el firmware edge aún no valida `token_hash` en ingest aunque el endpoint de registro ya lo genera.
- `worker_twilio.php` y `lib/twilio.php` duplican funciones `sendTwilioWhatsAppSmart`/`_twilioHttpPost` (worker tiene su propia versión para no depender de `lib/twilio.php` en detalles de timeout).
- `routes/consultations.php` módulo `audit_logs` está vacío (`data = []`, `columns` informativo).

---

## 6. Mejoras potenciales

1. **Front controller y routing explícito**: actualmente `api.php` incluye archivos con `require_once` según `$cleanPath`. Reemplazar por un router con controladores/clases para reducir variables globales.
2. **Inyección de dependencias**: pasar `$conn`, `$redis`, `$input` explícitamente en lugar de depender de globales.
3. **Consolidar helpers de Twilio**: unificar `sendTwilioDirect`, `enqueueTwilioJob`, `buildTwilioPayload`, `_twilioHttpPost` y `sendTwilioWhatsAppRequest` en `lib/twilio.php`.
4. **Workers como clases**: convertir workers de scripts procedurales a clases con métodos de test para mejor mantenibilidad.
5. **Rate limiting**: actualmente depende de `rate_limits` en DB para login y Redis para `/contacto`. Unificar estrategia.
6. **Validación de device tokens en ingesta**: implementar validación de `token_hash` en `/edge/ingest` y `/devices/commands`/`/devices/ping` de forma consistente.
7. **Paginación y límites**: algunos endpoints (`audit_full.php`, `consultations.php`, `dashboard.php`) usan `LIMIT` fijo sin paginación de offset/cursor consistente.
8. **Manejo de errores global**: muchos endpoints repiten `http_response_code(500); echo json_encode(['status'=>'error',...])`. Un middleware de errores reduciría duplicación.
9. **Caché**: `dashboard.php` usa Redis con TTL fijo de 30s. Considerar invalidación selectiva por eventos para evitar datos obsoletos.
10. **Tests automatizados**: no se observan tests unitarios para `RiskScoreEngine` ni workers. Agregar PHPUnit para funciones puras y workers con mocks de Redis/PDO.

---

## 7. Consideraciones de seguridad documentadas

- JWT puede firmar con RS256 o HS256; HS256 es legacy y menos seguro.
- `requireAuth` exige `X-Requested-With: XMLHttpRequest` para métodos mutantes (mitigación CSRF).
- RLS en PostgreSQL depende de `set_config('app.current_role', ...)`. Los workers y webhooks establecen `SYSTEM_WORKER`; los edges `EDGE_NODE`.
- Tokens de dispositivo se hashean con `password_hash` (bcrypt) en `edge_devices.token_hash`.
- Payload edge se cifra con AES-256-GCM en `api.php`.
- Panic mode invalida sesiones activas mediante `school_panic_events` y caché Redis.

---

## 8. Archivos revisados

### 8.1 Routes (21 archivos)

- `_auth_middleware.php`
- `_cors_middleware.php`
- `admin.php`
- `audit_full.php`
- `audit_integrity.php`
- `audit_logs.php`
- `auth.php`
- `behavior.php`
- `consultations.php`
- `dashboard.php`
- `devices.php`
- `groups.php`
- `metrics.php`
- `misc.php`
- `operations.php`
- `security_panic.php`
- `students.php`
- `telemetry.php`
- `tracking.php`
- `twilio_delivery.php`
- `users.php`

### 8.2 Workers (3 archivos)

- `worker_audit.php`
- `worker_biometric.php`
- `worker_twilio.php`

### 8.3 Librerías relacionadas

- `lib/twilio.php`
- `lib/RiskScoreEngine.php`

### 8.4 Infraestructura PHP suelta (4 archivos)

- `api.php`
- `boot_check.php`
- `db.php`
- `mqtt_publisher.php`

### 8.5 SQL / migraciones (6 archivos)

- `sql/cleanup_maintenance.sql`
- `sql/fix_migration.sql`
- `sql/nexo_full_migration.sql`
- `sql/nexo_seed.sql`
- `sql/purge_notification_garbage.sql`
- `sql/schema_migrations_backfill.sql`

### 8.6 Tests (18 archivos)

- `tests/02_ForeignKeyTest.php`
- `tests/03_ConstraintTest.php`
- `tests/04_IndexTest.php`
- `tests/05_TriggerTest.php`
- `tests/06_RlsSecurityTest.php`
- `tests/07_PartitionTest.php`
- `tests/08_SeedDataTest.php`
- `tests/10_EndpointSecurityTest.php`
- `tests/11_WorkerTest.php`
- `tests/13_RegressionTest.php`
- `tests/14_InstallationTest.php`
- `tests/15_ProductionReadinessTest.php`
- `tests/FullSystemAlignmentTest.php`
- `tests/PanicButtonTest.php`
- `tests/PlanComplianceTest.php`
- `tests/SchemaIntegrityTest.php`
- `tests/SchemaPhpAlignmentTest.php`
- `tests/integration_test.php`

### 8.7 Backend edge (17 headers + 16 src + scripts)

**Headers (`include/`):**
- `base_de_datos/cloud_manager.h`
- `base_de_datos/encryption.h`
- `base_de_datos/sqlite_manager.h`
- `hal/IBiometricSensor.h`
- `hal/IDisplay.h`
- `hal/IHttpClient.h`
- `hal/INotification.h`
- `hardware/dev_stub/DevStubBiometricSensor.h`
- `hardware/dev_stub/DevStubDisplay.h`
- `hardware/dev_stub/DevStubHttpClient.h`
- `hardware/dev_stub/DevStubNotification.h`
- `hardware/watchdog.h`
- `interoperabilidad/audit_trail.h`
- `mqtt/mqtt_command_worker.h`
- `utils/ConfigManager.h`
- `utils/Logger.h`
- `utils/NexoResult.h`

**Source (`src/`):**
- `base_de_datos/cloud_manager.cpp`
- `base_de_datos/encryption.cpp`
- `base_de_datos/sqlite_manager.cpp`
- `hardware/dev_stub/DevStubBiometricSensor.cpp`
- `hardware/dev_stub/DevStubDisplay.cpp`
- `hardware/dev_stub/DevStubHttpClient.cpp`
- `hardware/dev_stub/DevStubNotification.cpp`
- `hardware/real/RealGpioManager.cpp`
- `hardware/real/RealOledDisplay.cpp`
- `hardware/real/Zk9500BiometricSensor.cpp`
- `hardware/watchdog.cpp`
- `interoperabilidad/audit_trail.cpp`
- `mqtt/mqtt_command_worker.cpp`
- `utils/ConfigManager.cpp`
- `utils/edge_monitor.py`
- `main.cpp`

### 8.8 Build, scripts y configuración del edge

- `CMakeLists.txt`
- `CMakePresets.json`
- `Dockerfile.edge`
- `README.md`
- `config.example.json`
- `setup_nexo.sh`
- `scripts/install_deps_debian.sh`
- `scripts/install_deps_fedora.sh`
- `.clang-format`
- `.clang-tidy`
- `tests/test_crypto.cpp`
- `tests/test_sqlite.cpp`

---

## 9. Hallazgos específicos de SQL, tests y edge

### 9.1 SQL

- `nexo_full_migration.sql` es la fuente de verdad del esquema. Es idempotente y usa `CREATE ... IF NOT EXISTS`.
- La numeración de particiones requiere jobs periódicos para crear particiones mensuales; las particiones DEFAULT evitan errores si no se crea una partición puntual.
- `fix_migration.sql` es un parche legacy para un error de orden en `nexo_full_migration.sql`. Si se usa la versión corregida, no es necesario.
- `schema_migrations_backfill.sql` clasifica migraciones en pre-sistema, post-sistema y legacy archivadas.
- `cleanup_maintenance.sql` y `purge_notification_garbage.sql` deben ejecutarse con cuidado y backup previo.

### 9.2 Tests

- La suite tiene tests PHPUnit (02–15) y scripts autónomos. Faltan los números 01, 09 y 12, lo que indica historial de refactor/renumeración.
- La mayoría de tests son estáticos: parsean SQL/PHP sin necesidad de PostgreSQL/Redis. Solo `PanicButtonTest.php` requiere funciones del middleware y simula Redis.
- `SchemaIntegrityTest.php` es el más exhaustivo: parsea tablas, columnas, constraints, FK, índices, triggers, funciones, RLS y particiones.
- `PlanComplianceTest.php` y `FullSystemAlignmentTest.php` duplican parcialmente la lógica de `SchemaPhpAlignmentTest.php` e `integration_test.php`. Hay oportunidad de consolidar.

### 9.3 Edge

- `main.cpp` arranca todos los subsistemas y usa `DevStub*` por defecto, lo que implica que la build por defecto no carga implementaciones reales de ZK9500/GPIO/OLED. Las reales se seleccionarían compilando/linkeando los archivos correspondientes o cambiando `main.cpp`.
- `CommandWorker` (HTTP polling cada 30s) y `MqttCommandWorker` (MQTT persistente) coexisten; si `mqtt_host` está configurado se usa MQTT, sino solo HTTP.
- `SqliteManager` tiene funciones legacy/config como stubs (`checkInasistencia`, `savePAE`, `setConfig`, `getConfig`) que no implementan persistencia real; esto puede provocar pérdida de configuración y funcionalidades incompletas.
- `RealGpioManager` define una clase anónima sin registro en fábrica: `main.cpp` no la usa actualmente.
- `RealOledDisplay` incluye fuente 5x8 embebida y se asume segmento re-mapeado; el comentario sugiere invertir bits si la pantalla se ve invertida.
- `Encryption` almacena la clave AES como `std::vector<char>` y hace `mlock`; `initialize()` lee `nexo_aes_key_b64` directamente sin decodificar base64 (la clave se guarda en texto plano como string de 32 chars, no como 32 bytes aleatorios), lo que debilita el propósito de la clave AES.
- `ConfigManager` tiene URL de producción hardcodeada (`nexo-production-dbe3.up.railway.app`) en el default.
- `CMakeLists.txt` no enlaza `libmosquitto` de forma robusta cuando `pkg_check_modules` falla; la variable `MOSQUITTO_LIB` puede quedar vacía y aún así se enlaza.
- `setup_nexo.sh` asume estructura legacy `Logica de negocio/edge`, que ya no existe en el repo actual (`backend/edge`).
- `Dockerfile.edge` no instala `libspdlog`, `libmosquitto` ni `catch2` (descargados por FetchContent en build, pero runtime de spdlog/mosquitto podría faltar).
- `CMakePresets.json` referencia `cmake/arm64-pi4-toolchain.cmake` que no existe en el repositorio.
- `config.example.json` define pines GPIO `32`, `33`, `34`, pero `RealGpioManager.cpp` usa líneas `17`, `27`, `22` hardcodeadas.

---

### 8.9 Landing page (Vite + React + Three.js)

**Configuración y entry points:**
- `landing/index.html`
- `landing/vite.config.js`
- `landing/build.sh`
- `landing/package.json`

**Fuente:**
- `landing/src/main.jsx`
- `landing/src/App.jsx`
- `landing/src/index.css`
- `landing/src/landing/LandingPage.jsx`
- `landing/src/landing/components/ContactModal.jsx`
- `landing/src/landing/components/CookieBanner.jsx`
- `landing/src/landing/components/CookieFloatingButton.jsx`
- `landing/src/landing/components/CookieManager.jsx`
- `landing/src/landing/components/CustomCursor.jsx`
- `landing/src/landing/components/DownloadButton.jsx`
- `landing/src/landing/components/ErrorBoundary.jsx`
- `landing/src/landing/components/LegalModal.jsx`
- `landing/src/landing/components/Navbar.jsx`
- `landing/src/landing/components/NexoCanvas.jsx`
- `landing/src/landing/components/useReveal.js`
- `landing/src/landing/components/useStickyScroll.js`
- `landing/src/landing/core/NexoModel.jsx`
- `landing/src/landing/hooks/useCookieConsent.js`
- `landing/src/landing/sections/CredibilityBar.jsx`
- `landing/src/landing/sections/DownloadSection.jsx`
- `landing/src/landing/sections/FinalCTASection.jsx`
- `landing/src/landing/sections/Footer.jsx`
- `landing/src/landing/sections/HeroSection.jsx`
- `landing/src/landing/sections/HowItWorksSection.jsx`
- `landing/src/landing/sections/NodeSection.jsx`
- `landing/src/landing/sections/Preloader.jsx`
- `landing/src/landing/sections/ProblemSection.jsx`
- `landing/src/landing/sections/RolesSection.jsx`
- `landing/src/landing/sections/SecuritySection.jsx`
- `landing/src/landing/sections/ValuePropSection.jsx`
- `landing/src/dashboard/DashboardPage.jsx`

---

### 8.10 WebApp institucional (React + Vite + PWA + Tauri)

**Configuración y entry points:**
- `WebApp/index.html`
- `WebApp/package.json`
- `WebApp/vite.config.js`
- `WebApp/tailwind.config.js`
- `WebApp/postcss.config.js`
- `WebApp/vercel.json`
- `WebApp/style.css`
- `WebApp/BUILD_INSTRUCTIONS.md`

**Fuente:**
- `WebApp/src/main.jsx`
- `WebApp/src/App.jsx`
- `WebApp/src/index.css`
- `WebApp/src/api/client.js`
- `WebApp/src/api/auth.js`
- `WebApp/src/api/audit.js`
- `WebApp/src/api/dashboard.js`
- `WebApp/src/api/notifications.js`
- `WebApp/src/api/behavior.js`
- `WebApp/src/api/consultations.js`
- `WebApp/src/api/operations.js`
- `WebApp/src/api/reports.js`
- `WebApp/src/api/students.js`
- `WebApp/src/api/telemetry.js`
- `WebApp/src/api/tracking.js`
- `WebApp/src/api/users.js`
- `WebApp/src/components/ErrorBoundary.jsx`
- `WebApp/src/components/LogoNexo.jsx`
- `WebApp/src/components/PwaInstallPrompt.jsx`
- `WebApp/src/config/roles.js`
- `WebApp/src/context/AuthContext.jsx`
- `WebApp/src/context/ThemeContext.jsx`
- `WebApp/src/hooks/useAuth.js`
- `WebApp/src/layout/Layout.jsx`
- `WebApp/src/layout/Sidebar.jsx`
- `WebApp/src/pages/Audit.jsx`
- `WebApp/src/pages/Consultation.jsx`
- `WebApp/src/pages/ConsultationDrawer.jsx`
- `WebApp/src/pages/Dashboard.jsx`
- `WebApp/src/pages/Downloads.jsx`
- `WebApp/src/pages/Enrollment.jsx`
- `WebApp/src/pages/InstallPage.jsx`
- `WebApp/src/pages/Login.jsx`
- `WebApp/src/pages/Notifications.jsx`
- `WebApp/src/pages/Operation.jsx`
- `WebApp/src/pages/Profile.jsx`
- `WebApp/src/pages/Reports.jsx`
- `WebApp/src/pages/Seguimiento.jsx`
- `WebApp/src/pages/TrackingModal.jsx`
- `WebApp/src/pages/Unauthorized.jsx`
- `WebApp/src/routes/ProtectedRoute.jsx`
- `WebApp/src/store/userStore.js`
- `WebApp/src/utils/cn.js`
- `WebApp/src/utils/formatters.jsx`
- `WebApp/src/utils/mobilePermissions.js`
- `WebApp/src/utils/nativeAuth.js`

---

## 9. Hallazgos específicos de SQL, tests y edge

### 9.4 Landing page

- **Preloader timer-based**: `Preloader.jsx` oculta el overlay por tiempo (~1.6s) en lugar de esperar a la carga real de assets 3D; no hay handler de fallo de carga del modelo.
- **Contacto hardcodeado**: `ContactModal.jsx` postea a `nexo-production-dbe3.up.railway.app/v1/contacto` con fallback `import.meta?.env?.VITE_API_BASE_URL`; la URL de producción está embebida.
- **Dashboard placeholder**: `DashboardPage.jsx` es un contenedor vacío vinculado desde el hero ("¿Quién construyó NEXO?"); requiere implementación futura.
- **Cookie consent**: flujo funcional con localStorage, categorías analytics/marketing/preferences no se integran aún con scripts reales (ningún tag de tracking está presente).
- **CustomCursor en móvil**: se desactiva por `matchMedia('(pointer: coarse)')`, pero el hook de resize y listeners de mouse se ejecutan aunque retornen temprano; podría limpiarse más.
- **ScrollTrigger refresh global**: `LandingPage.jsx` hace `ScrollTrigger.refresh()` con `setTimeout(1200)` y debounced resize; esto es un workaround para layout shifts del canvas 3D.
- **R3F canvas pointer-events**: `NexoCanvas.jsx` usa `pointer-events: none` y un `DragOverlay` personalizado para permitir scroll de página mientras se rota el modelo.

### 9.5 WebApp institucional

- **Auth centralizada en cookie HttpOnly**: `WebApp/src/api/client.js` configura `withCredentials: true` y un interceptor global 401; no se almacena JWT en localStorage.
- **RBAC duplicado parcial**: `WebApp/src/config/roles.js` es la fuente de verdad del sidebar, pero `WebApp/src/App.jsx` y `WebApp/src/routes/ProtectedRoute.jsx` también repiten lógica de roles. Unificar en un helper `hasAnyRole()` reduciría duplicación.
- **URL de producción hardcodeada**: `WebApp/src/api/client.js` usa `nexo-production-dbe3.up.railway.app/v1` como fallback, igual que landing.
- **PWA install handling**: `WebApp/src/components/PwaInstallPrompt.jsx` y `WebApp/src/pages/InstallPage.jsx` capturan `beforeinstallprompt`, pero iOS Safari requiere instrucciones manuales mantenidas en `InstallPage.jsx`.
- **Timeout adaptativo**: `WebApp/src/api/client.js` extiende timeout a 45s para rutas `/operations/` y `/reports/`; sin embargo no hay lógica de cancelación ni abort controller explícito.
- **Telemetría en cola**: `WebApp/src/api/telemetry.js` envía un flush cada 5 minutos o 100 eventos, sin mecanismo de reintentos en envío fallido.
- **Estado global híbrido**: `WebApp/src/context/AuthContext.jsx` usa `userStore` para acceso no-React; mezcla contexto y singleton sin listeners de cambio.
- **Multiplataforma Tauri**: `WebApp/src/utils/nativeAuth.js` depende de `window.__TAURI__`; el bundle web no rompe, pero los imports de Tauri se resuelven condicionalmente en runtime.

---

## 10. Cómo usar este documento

Este resumen debe leerse junto con los comentarios añadidos directamente en los archivos PHP, C++, scripts/config, landing y WebApp. Juntos forman la documentación técnica necesaria para que un desarrollador senior comprenda el flujo, las dependencias y las decisiones arquitectónicas del backend y frontend de NEXO sin modificar la lógica de negocio existente.
