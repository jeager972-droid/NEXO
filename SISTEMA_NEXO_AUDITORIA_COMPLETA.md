# NEXO — Auditoría Completa del Sistema

> Documento único generado tras auditoría exhaustiva de API, Edge, WebApp, schema DB,
> lógica de negocio, seguridad e infraestructura. Reemplaza toda la documentación previa.

---

## 1. QUÉ ES NEXO

NEXO es un sistema de control biométrico escolar end-to-end que detecta ingresos/salidas
de estudiantes mediante lectores de huella dactilar en nodos Edge, sincroniza con un
backend en la nube (Render + PostgreSQL + Redis), y presenta dashboards y operaciones a
través de una WebApp React.

**Stack tecnológico:**
- **Edge:** C++20, SQLite, spdlog, libcurl, OpenSSL, SDK DigitalPersona U.are.U 5300
- **Backend:** PHP 8, PostgreSQL (Supabase), Redis (Upstash), PgBouncer, Nginx, Docker
- **WebApp:** React 18, Vite 5, Tailwind CSS 3, Framer Motion, Tauri 2, Axios
- **Infra:** Render (backend), Vercel (frontend), GitHub Actions CI/CD

---

## 2. ARQUITECTURA

```
[Sensor U.are.U 5300]
        │
        ▼
[Nodo Edge (C++)] ── AES-256-GCM ──► [API PHP (Render)]
   SQLite local                        │
   Sync worker (30s)                   ├── PostgreSQL (Supabase) + RLS
                                       ├── Redis (Upstash) — colas
                                       ├── Workers: biometric, twilio, audit
                                       └── Nginx → PHP-FPM
                                              ▲
                                              │
[WebApp React (Vercel)] ── JWT ──────────────┘
   Dashboard, Operaciones, Consultas
   Enrolamiento, Seguimientos, Perfil
```

---

## 3. COMPONENTES DETALLADOS

### 3.1 Edge (C++)

**Ubicación:** `backend/edge/`

**Modos de operación:**
1. **PERPETUO** — Control de asistencia. Loop infinito de captura de huella.
2. **PAE** — DESACTIVADO por decisión de arquitectura.
3. **SECRETARIA** — Enrolar/eliminar estudiantes, sync manual.

**Flujo de detección:**
1. `captureFinger()` captura huella del sensor
2. `dpfj_match()` compara contra templates en RAM
3. Si hay match → `handleBiometricMatch()`
4. `checkLateStatus()` clasifica: PUNTUAL/MANANA/TARDE/MADRUGADA/EXTRAORDINARIO
5. `AuditTrail::logEvent()` persiste en SQLite local
6. `SyncWorker.nudge()` notifica al hilo de sync
7. `SyncWorker` envía al cloud vía HTTP cifrado AES-256-GCM

**Persistencia local (SQLite):**
- `estudiantes` — documento, nombre, teléfono, huella_id, template
- `patrones` — ingresos_temprano, ingresos_tarde, asistencia_total
- `inasistencias` — EXISTE pero funciones son STUBS
- `audit_trail` — documento, event, fecha, synced, attempts
- `config` — key-value (token AES, etc.)

**Sincronización cloud:**
- Cada 30s o cuando se llama `nudge()`
- Backoff exponencial: 1s → 2s → 4s → ... → 60s max
- Después de 5 intentos fallidos: `synced=-1` (DLQ local)
- Payload: `action`, `doc`, `event`, `captured_at`, `device_token`, `device_id`, `nonce`

**Configuración (config.json):**
- `api_url` — URL del backend (o env `NEXO_API_URL`)
- `device_id`, `device_token` — credenciales del dispositivo
- `biometric_sensor` — "uareu5300", "zk9500", o "dev_stub"
- `mqtt_host` — broker MQTT para comandos remotos (opcional)
- `aes_key_file` — ruta al archivo de clave AES (32 bytes)

### 3.2 Backend API (PHP)

**Ubicación:** `backend/api/`

**Entrypoint:** `api.php` — enruta por prefijo de path a archivos en `routes/`

**Rutas principales (40+ endpoints):**

| Prefijo | Archivo | Endpoints clave |
|---------|---------|----------------|
| `/auth` | auth.php | login, logout, verify-2fa, me |
| `/dashboard` | dashboard.php | stats, teacher-group-detail, events |
| `/students` | students.php | GET (listado), POST (crear) |
| `/groups` | groups.php | GET (con filtro teacher_only) |
| `/operations` | operations.php | 12 comandos: sos, inasistencia, citacion, etc. |
| `/devices` | devices.php | GET, POST, DELETE, command, ping, commands |
| `/audit` | audit_full.php | 20+ endpoints de auditoría |
| `/behavior` | behavior.php | risk (estudiantes HIGH/CRITICAL) |
| `/admin` | admin.php | recalc-risk |
| `/tracking` | tracking.php | start, notes, details, active |
| `/consultations` | consultations.php | query (motor dinámico) |
| `/users` | users.php | by-role, me/extended, upload-photo, etc. |
| `/notifications` | misc.php | GET, POST, clear |
| `/security` | security_panic.php | panic (botón de pánico) |
| `/metrics` | metrics.php | Prometheus |
| `/telemetry` | telemetry.php | ingesta telemetría cliente |
| `/webhooks` | twilio_delivery.php, misc.php | status, inbound |
| `/contacto` | misc.php | formulario landing (público) |
| Edge ingest | api.php | payload cifrado AES-256-GCM |

**Workers (procesos background):**
1. `worker_biometric.php` — consume `queue:biometric_ingest`, inserta en `biometric_events`
2. `worker_twilio.php` — consume `queue:twilio`, envía WhatsApp vía Twilio API
3. `worker_audit.php` — consume `queue:audit_logs`, inserta en `global_audit_logs` (con cadena de hashes)

**Autenticación:**
- JWT con RS256/HS256
- `requireAuth()` inicia transacción + `set_config('app.current_school_id', ..., true)` para RLS
- `register_shutdown_function` hace commit automático al final de la request
- Roles: RECTOR, COORDINATOR, TEACHER, SECRETARY, SECURITY, AUXILIARY, COUNSELOR, GUARDIAN
- Permisos granulares por rol (tabla `role_permissions`)

**Seguridad:**
- RLS habilitado en 27 tablas multi-tenant
- Cifrado AES-256-GCM en ingesta del edge
- Replay protection con nonce (Redis, TTL 7 días)
- Rate limiting en login y contacto
- Prepared statements en todas las queries (no SQL injection)

### 3.3 WebApp (React)

**Ubicación:** `WebApp/`

**Páginas (13):**
- `Login.jsx` — login con 2FA opcional
- `Dashboard.jsx` — KPIs (presentes, ausentes, alertas, permisos) + eventos recientes
- `Operation.jsx` — 11 comandos operativos (SOS, citación, permiso, etc.)
- `Notifications.jsx` — centro de notificaciones formato chat
- `Consultation.jsx` — catálogo de módulos de consulta/auditoría (50+ endpoints)
- `ConsultationDrawer.jsx` — drawer con resultados de consultas
- `Enrollment.jsx` — matrícula de estudiantes + enrolamiento biométrico
- `Seguimiento.jsx` — casos de seguimiento activos
- `TrackingModal.jsx` — modal de notas de seguimiento
- `Profile.jsx` — perfil de usuario con OTP
- `Downloads.jsx` — descargas nativas por plataforma
- `InstallPage.jsx` — instalación PWA
- `Unauthorized.jsx` — acceso denegado

**Componentes (23):**
- UI: Button, Input, Card, Badge, Surface, Overlay, Select, Skeleton, Stepper
- Patterns: NexoChat, StatCard, OperationResult, RiskBadge, ScheduleTask, StudentItem
- Shared: ErrorBoundary, LogoNexo, PwaInstallPrompt

**API clients (13):** auth, dashboard, operations, consultations, notifications, audit, behavior, reports, tracking, students, devices, telemetry, users

**Autenticación:**
- JWT en cookie HttpOnly (primary) + localStorage (fallback iOS ITP)
- ProtectedRoute con role-based access control
- Logout limpia cookie, localStorage y estado

---

## 4. SCHEMA DE BASE DE DATOS

**Ubicación:** `backend/api/sql/nexo_full_migration.sql`

### Tablas principales (30+)

**Configuración:**
- `schools`, `departments`, `municipalities` — instituciones
- `roles`, `permissions`, `role_permissions` — RBAC
- `edge_devices` — dispositivos edge (con token_hash)
- `classrooms`, `subjects` — aulas y materias

**Personas:**
- `users` — personal (con `work_shift`: mañana/tarde/completa)
- `students` — estudiantes (SIN work_shift)
- `guardians` — acudientes
- `guardian_student_relationships` — relaciones estudiante-acudiente
- `staff_records` — registros de personal

**Académico:**
- `academic_groups` — grupos (6A, 6B, 7A, etc.)
- `student_group_assignments` — asignación estudiante-grupo
- `schedules` — horarios de clase (docente-grupo-aula-materia)

**Eventos (particionadas por timestamp):**
- `biometric_events` — ingresos/salidas biométricos
- `attendance_incidents` — inasistencias, llegadas tarde, permisos, etc.
- `sos_alerts` — alertas de emergencia
- `internal_messages` — mensajes entre usuarios
- `twilio_messages` — mensajes WhatsApp
- `user_commands` — comandos ejecutados por usuarios
- `student_record_audit` — auditoría de cambios en estudiantes
- `global_audit_logs` — logs globales con cadena de hashes

**Autorizaciones:**
- `school_exit_authorizations` — salidas de institución
- `class_exit_authorizations` — salidas de clase
- `pedagogical_trip_authorizations` — salidas pedagógicas

**Métricas y seguimiento:**
- `student_behavior_metrics` — risk_score, risk_level, late_count, absence_count
- `student_tracking` — seguimientos activos
- `student_tracking_notes` — notas de seguimiento
- `notifications` — notificaciones in-app
- `report_exports` — reportes generados

**Seguridad:**
- `security_incidents` — incidentes de seguridad
- `school_panic_events` — eventos de pánico (SIN RLS — bug)
- `rate_limits` — rate limiting
- `verification_codes` — códigos OTP

### RLS (Row Level Security)

**27 tablas con RLS** — todas filtran por `get_current_school_id()` que lee
`app.current_school_id` del contexto de sesión PostgreSQL.

**Tablas SIN RLS (intencional):** permissions, departments, municipalities, schools, roles,
role_permissions, user_sessions, twilio_message_types, schema_migrations, contact_leads,
system_telemetry, rate_limits, verification_codes

**Tablas SIN RLS (bug):** `school_panic_events` — debería tener RLS

### Funciones PostgreSQL
- `get_current_school_id()` — retorna school_id del contexto RLS
- `fn_calculate_student_risk(student_id, school_id, window_days)` — cálculo de riesgo en SQL
- `fn_recalculate_school_metrics(school_id)` — recálculo masivo de métricas

---

## 5. LÓGICA DE NEGOCIO — ESTADO ACTUAL

### 5.1 Detección de asistencia

| Estado | Implementado | Cómo funciona |
|--------|-------------|---------------|
| PRESENTE | ✅ | `biometric_events` con `event_type LIKE 'INGRESO_%'` del día actual |
| AUSENTE | ❌ MANUAL | Requiere comando `inasistencia` desde WebApp |
| LLEGADA TARDE | ✅ Automático | Edge genera `INGRESO_TARDE` según horario hardcoded |
| SALIDA | ⚠️ Manual | Solo vía comando `AUTHORIZE_EXIT` desde WebApp |

### 5.2 Clasificación de horarios (Edge — HARDCODED)

```
06:40-07:00 → PUNTUAL
07:00-11:00 → MANANA
11:00-11:30 → (HUECO — sin clasificación)
11:30-16:00 → TARDE
<06:40       → MADRUGADA
>16:00       → EXTRAORDINARIO
```

### 5.3 Jornadas estudiantiles

**NO EXISTEN.** Los estudiantes no tienen campo `work_shift` ni `jornada`.
Solo los usuarios (personal) tienen `work_shift` en la tabla `users`.

### 5.4 Métricas de riesgo

**IMPLEMENTADAS** (no son stubs):
- `RiskScoreEngine.php` — algoritmo real con pesos configurables
- `WEIGHT_LATE=5.0`, `WEIGHT_ABSENCE=15.0`, `WEIGHT_OVERFLOW=0.5`
- Umbrales: CRITICAL≥80, HIGH≥60, MEDIUM≥30, LOW<30
- `fn_calculate_student_risk()` — función SQL equivalente
- `recalc_risk.sh` — cron job que recalcula todas las escuelas
- **Problema:** requiere ejecución manual o cron, no es automático en tiempo real

### 5.5 Notificaciones

| Evento | WhatsApp | In-app | Destinatario |
|--------|----------|--------|-------------|
| Inasistencia | ✅ | ❌ | Acudiente |
| Citación | ✅ | ❌ | Acudiente |
| Horario (cambio) | ✅ | ❌ | Todos los padres del grupo |
| Incidente | ✅ (opcional) | ✅ (opcional) | Padre y/o rector |
| SOS | ✅ | ✅ | Rector/Coordinador |
| Situación crítica | ❌ | ✅ | Rector/Coordinador |
| Solicitud | ❌ | ✅ | Usuario destino |
| Riesgo HIGH/CRITICAL | ❌ | ✅ | Coordinador |

### 5.6 Cruce entre roles

| Rol | Dashboard | Operaciones | Consultas | Riesgo |
|-----|-----------|-------------|-----------|--------|
| RECTOR | Toda la institución | Todas | Todas | ✅ |
| COORDINATOR | Toda la institución | Casi todas | Todas | ✅ |
| TEACHER | Solo sus grupos | Limitadas | Solo sus grupos | ✅ |
| COUNSELOR | Vista docente | Seguimientos | Solo sus grupos | ✅ |
| SECRETARY | Toda la institución | Operativas | Limitadas | ❌ |

---

## 6. PROBLEMAS CRÍTICOS ENCONTRADOS

### 🔴 P0 — Críticos (bloquean funcionamiento core)

**P0-1. Worker biométrico no inserta eventos en PostgreSQL**
- **Síntoma:** Edge reporta `synced=1` pero los eventos no aparecen en `biometric_events`
- **Causa:** PgBouncer transaction pooling + `set_config(..., false)` se pierde entre consultas
- **Estado:** Fix en progreso — `set_config(..., true)` dentro de `beginTransaction()`
- **Archivo:** `backend/api/workers/worker_biometric.php`, `backend/api/routes/_auth_middleware.php`

**P0-2. Inasistencia manual NO se registra en la base de datos**
- **Síntoma:** Coordinador reporta inasistencia → WhatsApp se envía → pero NO aparece en dashboard
- **Causa:** `operations.php` case 'inasistencia' solo envía WhatsApp, NO inserta en `attendance_incidents`
- **Impacto:** Dashboard siempre muestra 0 ausentes aunque se hayan reportado
- **Archivo:** `backend/api/routes/operations.php` líneas 400-457

**P0-3. No hay detección automática de ausentes**
- **Síntoma:** Estudiante que no marca huella no aparece como ausente
- **Causa:** No existe proceso automático que compare estudiantes esperados vs ingresos reales
- **Impacto:** El dashboard no refleja la realidad de asistencia
- **Faltante:** Tabla de configuración diaria de horarios + job automático de detección

**P0-4. Estudiantes sin jornada**
- **Síntoma:** No se puede determinar si un estudiante de mañana llegó tarde o no
- **Causa:** Tabla `students` no tiene columna `work_shift` o `jornada`
- **Impacto:** No se puede automatizar detección de ausentes por jornada
- **Faltante:** Columna `work_shift` en `students` + lógica que la use

**P0-5. ScheduleTask del frontend no guarda en backend**
- **Síntoma:** Coordinador configura horarios diarios pero no se persisten
- **Causa:** Frontend envía `changes` con `entry_time`/`exit_time` pero `operations.php` case 'horario' no los procesa
- **Impacto:** La operación diaria obligatoria del coordinador es inútil
- **Archivo:** `WebApp/src/components/patterns/ScheduleTask.jsx`, `backend/api/routes/operations.php`

### 🟡 P1 — Importantes (funcionalidad degradada)

**P1-1. Error del sensor U.are.U 5300 (0x5ba0014)**
- **Código:** `DPFPDD_E_INVALID_PARAMETER` = "No reader with this name found"
- **Causa probable:** Condición de carrera entre `dpfpdd_query_devices` y `dpfpdd_open`
- **Solución:** Re-query + reintento en `openDevice()`, verificar reglas udev
- **Archivo:** `backend/edge/src/hardware/real/UareU5300BiometricSensor.cpp`

**P1-2. Enrolamiento local no se sincroniza con cloud**
- **Síntoma:** Estudiante enrolado en edge no aparece en PostgreSQL
- **Causa:** `CloudManager::registerStudent()` existe pero nunca se llama desde `main.cpp`
- **Archivo:** `backend/edge/src/main.cpp` (línea 700-733), `backend/edge/src/base_de_datos/cloud_manager.cpp`

**P1-3. Horarios del edge hardcoded**
- **Síntoma:** Rangos de PUNTUAL/MANANA/TARDE son fijos, no configurables
- **Causa:** `checkLateStatus()` tiene rangos hardcoded en el código
- **Impacto:** No respeta horarios reales de cada institución
- **Archivo:** `backend/edge/src/main.cpp` líneas 379-389

**P1-4. Rector no ve inasistencias en el feed de eventos**
- **Síntoma:** Dashboard del rector filtra explícitamente `INASISTENCIA`, `CITACION`, `SOLICITUD`
- **Causa:** Query de eventos excluye estos tipos para rector
- **Archivo:** `backend/api/routes/dashboard.php` líneas 534-549

**P1-5. Endpoints del frontend que no existen en backend**
- `GET /consultation/search` — frontend lo llama, backend no lo tiene
- `POST /notifications` — frontend lo llama como crear, backend solo tiene GET/POST en misc.php
- **Archivos:** `WebApp/src/api/consultations.js`, `WebApp/src/api/notifications.js`

**P1-6. `school_panic_events` sin RLS**
- **Síntoma:** Tabla de eventos de pánico no tiene Row Level Security
- **Impacto:** Cualquier school_id podría ver eventos de pánico de otras instituciones
- **Archivo:** `backend/api/sql/nexo_full_migration.sql`

**P1-7. `/debug/worker` sin autenticación**
- Endpoint temporal de diagnóstico abierto al público
- **Archivo:** `backend/api/api.php` líneas 186-244

### 🟢 P2 — Menores (mejoras de calidad)

**P2-1.** `TESTING_MODE = true` en ScheduleTask.jsx (debería ser false en producción)
**P2-2.** Funciones stub de inasistencia en edge (`checkInasistencia`, `deleteInasistencia`)
**P2-3.** `configureUareuEnvironment()` no loguea errores de `dlopen`
**P2-4.** `ALTER TABLE` ejecutado innecesariamente en cada inicio del edge
**P2-5.** Opciones 5 y 6 del menú edge son debug (simulaciones)
**P2-6.** `dpErrorString()` solo muestra hex, no tiene mapeo descriptivo
**P2-7.** Hueco en rangos de horario del edge (11:00-11:30 sin clasificación)
**P2-8.** `METRICS_SECRET_KEY` opcional — si no está configurado, `/metrics` es público
**P2-9.** `UPDATE_FIRMWARE` es placeholder
**P2-10.** Recálculo de riesgo requiere ejecución manual o cron

---

## 7. ARCHIVOS INNECESARIOS

### Eliminar (temporales de build)
- `WebApp/build.exit`
- `WebApp/build.out`
- `WebApp/.nexo/build.exit`

### Archivar (documentación histórica — mover a `docs/archive/`)
- `NEXO_BIOMETRIC_INTEGRATION_ANALYSIS.md`
- `NEXO_BIOMETRIC_PREINTEGRATION_DESIGN.md`
- `PLAN5300_RESULTADOS.md`
- `UAREU5300_ANALISIS_Y_PLAN.md`

### Mantener
- `README.md` — documentación principal
- `UAREU5300_RUNBOOK_PRODUCCION.md` — runbook operativo activo
- `.github/workflows/nexo-ci-cd.yml` — CI/CD

### Agregar a .gitignore
- `.agents/` — configuración personal de agentes IA
- `.obsidian/` — configuración personal de Obsidian

---

## 8. ENDPOINTS DESCONECTADOS

### Frontend llama → Backend no tiene
| Endpoint | Frontend | Estado |
|----------|----------|--------|
| `GET /consultation/search` | consultations.js | ❌ No existe |
| `POST /notifications` (crear) | notifications.js | ❌ No existe como endpoint independiente |

### Backend tiene → Frontend no usa
| Endpoint | Backend | Razón |
|----------|---------|-------|
| `POST /devices` | devices.php | Registro de dispositivos (¿debería usarse?) |
| `DELETE /devices/{id}` | devices.php | Revocar dispositivo (¿debería usarse?) |
| `GET /admin/recalc-risk` | admin.php | Recalcular riesgo manualmente |
| `GET /devices/commands` | devices.php | Solo para edge |
| `POST /devices/ping` | devices.php | Solo para edge |

---

## 9. FLUJO COMPLETO DE ASISTENCIA (ESTADO ACTUAL vs DESEADO)

### Estado actual (con bugs)
```
1. Estudiante pone dedo en lector
2. Edge: checkLateStatus() → "INGRESO_MANANA"
3. Edge: AuditTrail::logEvent() → SQLite local
4. Edge: SyncWorker → API cloud (cifrado AES-256-GCM)
5. API: valida token, encola en Redis queue:biometric_ingest
6. Worker: processJob() → INSERT biometric_events
   ⚠️ BUG: RLS filtra students, INSERT falla, evento se pierde
7. Dashboard: SELECT COUNT(*) FROM biometric_events WHERE event_type LIKE 'INGRESO_%'
   ⚠️ Resultado: 0 presentes (porque el INSERT falló)

Si estudiante NO marca huella:
8. Nadie hace nada
9. Dashboard: SELECT COUNT(*) FROM attendance_incidents WHERE incident_type IN ('INASISTENCIA')
   ⚠️ Resultado: 0 ausentes (porque nadie registró la inasistencia)

Coordinador reporta inasistencia manual:
10. WebApp: POST /operations/inasistencia
11. Backend: envía WhatsApp al acudiente
    ⚠️ BUG: NO inserta en attendance_incidents
12. Dashboard: sigue mostrando 0 ausentes
```

### Estado deseado (post-fix)
```
1. Estudiante pone dedo en lector
2. Edge: checkLateStatus() → "INGRESO_MANANA" (usando horario configurado por coordinador)
3. Edge: AuditTrail::logEvent() → SQLite local
4. Edge: SyncWorker → API cloud
5. API: valida, encola en Redis
6. Worker: INSERT biometric_events ✅ (RLS arreglado)
7. Dashboard: muestra 1 presente ✅

Si estudiante NO marca huella:
8. Job automático (cron o trigger): compara estudiantes esperados vs ingresos reales
9. Para cada estudiante sin ingreso: INSERT attendance_incidents (incident_type='INASISTENCIA')
10. Dashboard: muestra N ausentes ✅
11. Notificación automática al acudiente ✅

Coordinador reporta inasistencia manual:
12. WebApp: POST /operations/inasistencia
13. Backend: INSERT attendance_incidents + envía WhatsApp ✅
14. Dashboard: muestra la inasistencia ✅
```

---

## 10. PLAN DE IMPLEMENTACIÓN

### Fase 1: Fixes críticos (P0) — Sin estos no funciona nada

**1.1 Fix worker biométrico (P0-1)**
- Verificar que `beginTransaction()` + `set_config(..., true)` funciona con PgBouncer
- Usar endpoint `/debug/worker` para diagnosticar
- Si PDO no funciona, usar `exec("BEGIN")` + `exec("SET LOCAL")` correctamente
- **Archivos:** `worker_biometric.php`, `_auth_middleware.php`

**1.2 Fix inasistencia manual (P0-2)**
- En `operations.php` case 'inasistencia', agregar INSERT en `attendance_incidents`
- Tipo: `INASISTENCIA`, detected_at: NOW()
- **Archivo:** `operations.php` líneas 400-457

**1.3 Implementar detección automática de ausentes (P0-3)**
- Crear tabla `daily_schedule_config` (school_id, group_id, config_date, has_classes, expected_entry_time, expected_exit_time)
- Crear job/cron que a las 8:00am compare estudiantes esperados vs ingresos reales
- Para cada ausente: INSERT `attendance_incidents` + notificación WhatsApp
- **Archivos:** nuevo `workers/worker_absence_detector.php`, `operations.php`

**1.4 Agregar jornada a estudiantes (P0-4)**
- `ALTER TABLE students ADD COLUMN work_shift VARCHAR(50) DEFAULT 'mañana'`
- Actualizar `enrollment` para capturar jornada
- Usar `work_shift` en detección automática de ausentes
- **Archivos:** `nexo_full_migration.sql`, `students.php`, `Enrollment.jsx`

**1.5 Persistir ScheduleTask en backend (P0-5)**
- En `operations.php` case 'horario', procesar `params['changes']`
- INSERT/UPDATE en `daily_schedule_config` por grupo y fecha
- **Archivos:** `operations.php`, `ScheduleTask.jsx`

### Fase 2: Fixes importantes (P1) — Funcionalidad degradada

**2.1 Fix sensor U.are.U 5300 (P1-1)**
- Agregar re-query + reintento en `openDevice()` cuando error es `INVALID_PARAMETER`
- Verificar reglas udev instaladas
- Agregar logging de errores de `dlopen` en `configureUareuEnvironment()`
- **Archivo:** `UareU5300BiometricSensor.cpp`

**2.2 Sync enrolamiento con cloud (P1-2)**
- Llamar `CloudManager::registerStudent()` después de `enrollStudentOnDevice()`
- **Archivo:** `main.cpp`

**2.3 Horarios configurables en edge (P1-3)**
- Descargar horarios desde cloud al iniciar edge
- Usar horarios configurados en lugar de hardcoded
- **Archivos:** `main.cpp`, `cloud_manager.cpp`

**2.4 Rector ve inasistencias (P1-4)**
- Modificar query de eventos del dashboard para incluir INASISTENCIA
- **Archivo:** `dashboard.php`

**2.5 Endpoints faltantes (P1-5)**
- Implementar `GET /consultation/search` en backend
- Implementar `POST /notifications` como endpoint independiente
- **Archivos:** `misc.php` o `consultations.php`

**2.6 RLS en school_panic_events (P1-6)**
- Agregar política RLS
- **Archivo:** `nexo_full_migration.sql`

**2.7 Eliminar /debug/worker (P1-7)**
- Eliminar endpoint temporal
- **Archivo:** `api.php`

### Fase 3: Mejoras de calidad (P2)

- Desactivar TESTING_MODE en ScheduleTask.jsx
- Implementar funciones de inasistencia en edge (o eliminar stubs)
- Agregar mapeo descriptivo de errores DP
- Eliminar opciones de debug del menú edge
- Hacer METRICS_SECRET_KEY obligatorio
- Automatizar recálculo de riesgo (cron cada 1h)
- Eliminar archivos temporales de build
- Archivar documentación vieja
- Agregar `.agents/` y `.obsidian/` a .gitignore

---

## 11. MÉTRICAS DEL PROYECTO

- **Commits totales WebApp:** 160+
- **Commits totales backend:** 262+
- **Endpoints API:** 40+
- **Páginas WebApp:** 13
- **Componentes WebApp:** 23
- **Tablas PostgreSQL:** 30+
- **Tablas con RLS:** 27
- **Workers:** 3 (biometric, twilio, audit)
- **Archivos fuente Edge:** 15+
- **Líneas de código Edge (main.cpp):** 1115

---

*Documento generado tras auditoría exhaustiva con 5 subagentes en paralelo.
Reemplaza toda la documentación previa del proyecto.*
