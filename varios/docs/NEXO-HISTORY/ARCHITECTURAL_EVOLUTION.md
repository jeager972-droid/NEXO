# ARCHITECTURAL EVOLUTION — cómo cambió la forma de NEXO

Este documento reconstruye los estados arquitectónicos sucesivos del
sistema. Cada estado se deriva de árboles Git concretos (`git ls-tree`),
no del estado actual. Los hashes permiten reproducir cada uno.

---

## Estado 0 — El puente seguro (R1, 2026-04-12, `0a84b1a7` → `6e5c3fb1`)

```
┌────────────┐   HTTPS + AES-256-ECB    ┌──────────────────────────┐
│  Nodos /    │ ──────────────────────► │  api.php (único archivo) │
│  institución│   {payload, inst_id}    │  mysqli → MySQL          │
└────────────┘                         │  `nexo_global`           │
                                       │   · instituciones        │
[WhatsApp] ──Twilio webhook──────────► │   · auditoria_global     │
  (!inasistencia, !rojo)               │     (hash SHA-256, sin     │
                                       │      PII persistente)    │
                                       └───────────┬──────────────┘
                                                   ▼ Twilio API
                                            WhatsApp a acudiente
```

Qué era: un **puente de interoperabilidad por excepción**. Las
instituciones conservan sus propios sistemas; NEXO solo recibe eventos
cifrados, notifica a familias y guarda un rastro anonimizado. El mismo
archivo también atendía el webhook Twilio: docentes reportaban
inasistencias con comandos de texto (`!inasistencia SALON N_LISTA`).

Lo que no existía: interfaz web, usuarios con sesión, persistencia
relacional completa, edge biométrico. La "UI" era una página de marca
galáctica (`SECURE_NODE_ONLINE`).

---

## Estado 1 — El monolito-bot (R1, 04-18 → 05-05, `9435cf1c` → `d5ce2718`)

`api.php` crece de 267 a **1.486 líneas**. Aparecen:

- tabla `COMMANDS_CONFIG`: ~14 comandos (`!tarde`, `!citacion`,
  `!salida`, `!permiso`, `!excepcion`, `!SOS`, `!daño`, `!lista`,
  `!tardetotal`, `!dañostotal`, `!subsana`, `!help`…) con roles por
  comando (SUPER_ADMIN, RECTOR, COORDINADOR, DOCENTE, SECRETARIA,
  PSICORIENTADOR, PORTERO, PADRE);
- identidad del remitente por teléfono (`personal`/`staff`), logging de
  comandos (`logComando`), respuestas TwiML (`sendTwilioResponse`),
  fallback WhatsApp→SMS, `validateTwilioSignature()`, rate limiting,
  `securityLog()`;
- canal inverso "NEXO PADRES" (los acudientes responden 1/2 a citaciones).

La arquitectura era: **WhatsApp es la interfaz del sistema**. Toda la
operación institucional pasaba por un solo archivo PHP detrás de un
webhook. Dato curioso del diseño: la capa de permisos ya era RBAC —
la matriz de roles nació en el bot, no en la WebApp.

---

## Estado 2 — Separación API + WebApp (R1, 05-07 → 05-08, `5d9b14d2` → `5b6d2820`)

Tres movimientos en ~36 horas:

1. **MySQL → PostgreSQL** y extracción de `db.php` (PDO): `api.php` baja
   de 1.486 a 648 líneas (`5d9b14d2`).
2. **Modularización**: aparece `routes/` (auth, students, groups,
   operations, misc…) y `check_roles.php` (`9f771f6a`, `4023346f`),
   más `sql/2026-05-*-hardening*.sql` (`783ec75d`).
3. **La WebApp llega por merge**: la línea independiente de 0nto
   (`20ebe789`, Vite+React+Tailwind+PWA+Vercel, con páginas Dashboard,
   Consultation, Enrollment, Operation, Audit, Notifications, Reports,
   Login, AuthContext+ProtectedRoute) se fusiona en `5b6d2820`.

```
Docente/staff ──WhatsApp──► api.php + routes/ ──► PostgreSQL
                                │                       ▲
Acudiente ◄──WhatsApp/SMS───────┘                       │
                                                        │
Usuario web ──► React PWA (Vercel) ──axios──► /v1/* ────┘
                   (JWT, HttpOnly cookie)
```

Es la primera arquitectura de dos caras: **el bot sigue siendo el canal
de campo; la web se vuelve la consola institucional**.

---

## Estado 3 — El monorepo refundado (R3, 05-12 → 05-13, `7c468b3` → `71de30ef`)

El nuevo repositorio nace con todo lo que R1 no mostraba:

```
NEXO/
├── WebApp/                React+Vite+PWA + src-tauri (desktop/móvil)
├── Logica de negocio/     → backend/ (05-13)
│   ├── alojamiento/       api.php front controller "v7.5",
│   │                      routes/ (15 archivos: auth, students,
│   │                      devices, security_panic, audit_*, admin…),
│   │                      workers PHP (twilio, audit), sql/ migrado
│   │                      2026-05→13 (incl. edge-devices, audit-chain,
│   │                      RLS policies, user-commands), PgBouncer conf
│   └── edge/              C++ con HAL: IBiometricSensor, IDisplay,
│                          IHttpClient, INotification; dev_stub +
│                          hardware real (ZK9500, OLED, GPIO);
│                          SQLite local + cloud_manager + AES
├── Includes/, Gestion de Proyecto/   legado ESP32/PlatformIO + docs
└── ARCHITECTURE.md        plan de "Etapas": edge RPi4 C++17,
                           cloud API PHP 8.2/PG/Redis, cliente
                           React+Tauri+Capacitor; problemas R1–R5
                           listados (ingesta que descartaba datos,
                           backend duplicado, auditoría mutable…)
```

Dato clave: el edge **ya era C++ para Linux** al entrar al repo (drivers
reales ZK9500/OLED/GPIO), aunque el documento de arquitectura todavía
describía el edge como "Arduino/ESP32" heredado. El código ESP32 real
nunca entró al historial — solo sus librerías vendor y scripts, que se
borraron el 06-30 (`cac1718b`).

## Estado 4 — Servicio único de despliegue (05-17, `1cb9e0ef`)

Tras la saga Railway (~40 commits de Dockerfile/nginx/Apache/502), la
forma desplegable se consolidó así:

```
Railway (un solo contenedor)
   nginx → php-fpm (Unix socket, IPv6 dual-stack)
     ├── /            landing estática (desde backend/alojamiento)
     ├── /v1/*        api.php → routes/*
     └── /app/*       build de la WebApp (dist)
Vercel (paralelo): PWA estática → mismo backend
Datos: PostgreSQL (Railway→luego Supabase/PgBouncer) + Redis/Upstash
```

## Estado 5 — Plataforma operativa institucional (06-01 → 08-15)

La WebApp deja de ser un panel y pasa a operar la sede:

- consultas dinámicas sobre PostgreSQL (`1dcc8ee7`);
- citaciones Twilio con StatusCallback, reintentos por plantilla y
  respuestas de acudientes convertidas en notificaciones internas;
- seguridad de cuenta: OTP por WhatsApp, 2FA opcional, alerta de login,
  datos de usuario fuera de localStorage (en memoria), JWT con
  blocklist en Redis;
- módulo de auditoría completo (~50 endpoints, filtros, exportación);
- seguimiento estudiantil (`tracking.php`, 06-08/09);
- canonización de la base de datos (07-20, `bbf68c76`): migraciones
  consolidadas, `deploy_db.sh`, compatibilidad Supabase/PgBouncer;
- la semana del 04-08: RLS bajo transaction pooling resuelto con
  `SET LOCAL` dentro de transacción (`a77d7a95`); detector de ausentes,
  motor de riesgo, onboarding de horarios multi-jornada.

Estado al RC (`43682b30`, 08-15): **schema.sql canónico de 55 tablas**,
22 archivos de rutas, multi-tenant por `school_id` con RLS.

## Estado 6 — El edge se convierte en flota gestionada (08-17 → 08-21)

Antes de esta etapa el edge era un proceso que *enviaba* eventos.
Ahora la nube también lo *gobierna*:

```
PWA (coordinador) ──► routes/devices.php ──► device_commands (PG/Redis)
                                                │
Edge C++20  ◄── polling /devices/commands ◄──────┘
  · CommandWorker, heartbeat (last_ping)
  · token auto-provisionado desde config.json
  · enrolamiento remoto ENROLL_REQUEST → huella → has_fingerprint
  · revocación de sensores; RLS sobre edge_devices
  · SYNC_ATTENDANCE con fallback PG cuando Redis cae
```

La PWA ganó administración de sensores por grupo, asignación de sensor a
usuario, y confirmación visual del enrolamiento.

## Estado 7 — Hardening verificable (08-20 → 08-24)

`bb0165b2` reorganiza el repo (WebApp→PWA, Tauri fuera, `sql/schema.sql`
único en raíz) y crea `test/` con PHPUnit + Vitest + CI. La misma
semana: `RiskEngineV3` (niveles pedagógicos proporcionales, decisión
humana final), resiliencia a fallos de Redis/PgBouncer (circuit
breaker ~45 min, workers en modo fallback PG, reintentos),
endurecimiento de enrolamiento E2E.

## Estado 8 — Auditoría a especificación y nodos vivos (09-15 → 09-19)

Llega `documento_final.txt` (especificación funcional) y `auditoria/`
documenta una verificación de **652 ítems** documento↔código. El commit
`74dc1b8b` integra lo que faltaba:

- **OTA** de nodos (`lib/ota.php`, `ota_updates`/`ota_deployments`),
- telemetría y salud de dispositivos (`worker_device_health`),
- **modelo espacial**: aulas + horarios → los eventos biométricos quedan
  ligados a bloque y aula (`classrooms`, `schedules`),
- conciliación de asistencia, alertas a docentes, `routes/events.php`,
- simuladores de subsistemas físicos (`simulaciones/`: biometría,
  energía, térmico, OTA, M2M) y stack de integración Docker (`pruebas/`).

El edge ya hace de todo: captura huella, valida salidas autorizadas
(`WAIT_EXIT_FINGERPRINT` → `SALIDA_AUTORIZADA`), marca retornos, detecta
evasión por paridad de eventos, aplica regla de baño de 20 min.

## Estado 9 — Nexus (09-20 → 09-26)

El asistente conversacional pasa por tres arquitecturas en cinco días:

```
v1 (09-20, a6fd4696):              v2 (09-20→22):               v3 (09-23→24, e437d4ff/1b36423e):
mensaje                            mensaje                      mensaje
   │                                  │                            │
   ▼                                  ▼                            ▼
NLU Python (TF-IDF+LR)          NLU + Semantic Core            LLM = parser
 (backend/nlu, service.py)      + DSM (estado conversacional)  (nexus_llm.php)
   │                             + planner universal            │
   ▼                             + memoria de trabajo           ▼
resolver intents                (ee12db93: baseline)          planner NEXO
   │                                  │                            │ read-only garantizado,
   ▼                                  ▼                            ▼ RBAC, auditoría
endpoints existentes              endpoints existentes         endpoints existentes
```

El clasificador TF-IDF+LR fue retirado el 09-23 (`1b36423e`, 89
archivos): el LLM quedó como parser y compositor sobre el motor de
capacidades. El 09-26 Nexus se reubicó como componente canónico
`backend/api/nexus/` (nexus_llm, nexus_nlu, nexus_scp, nexus_semantic).

## Estado final — NEXO 1.0 (HEAD, `35271aef`)

```
            ┌─────────────────────────── FRONTEND ───────────────────────────┐
            │  frontend/pwa   (SPA instalable, 7 roles)                      │
            │  frontend/landing (sitio público)                              │
            └──────────────┬─────────────────────────────────────────────────┘
                           │ HTTPS REST + JWT (cookie HttpOnly / Bearer)
┌──────────────┐   AES-256-GCM  ┌────────────────────────────────────────┐
│  NODO EDGE   │ ─────────────► │  backend/api                           │
│  (colegio)   │   eventos      │   api.php → routes/ (24)               │
│  C++20       │ ◄───────────── │   nexus/  IA conversacional            │
│  SQLite loc. │   comandos/    │   lib/ dominio · workers/ (11 daemons) │
│  UareU/ZK9500│   OTA/heartbeat│   core/ db·redis·mqtt                  │
│  OLED·GPIO   │                └───────┬───────────────┬────────────────┘
│  MQTT·OTA    │                PgBouncer:6432      Redis (colas, cache, │
│  watchdog    │                        │              rate-limit, dedup) │
└──────────────┘                        ▼                                │
                                PostgreSQL 15 + RLS                     │
                                sql/schema.sql — 78 tablas              │
                                multi-tenant por school_id              │
└─────────────── WhatsApp/SMS ◄── workers (twilio, ausentes, evasión, ──┘
                                 riesgo, salud de nodos, auditoría)
```

La distancia entre el Estado 0 y el final es la historia: el puente
que descartaba datos se convirtió en una plataforma multi-tenant que
custodia a estudiantes en tiempo real — sin que ninguna etapa borrara
la anterior por completo: el api.php "discreto" sigue siendo el front
controller; los comandos `!` del bot sobreviven como `user_commands`
y como operaciones de la PWA; la auditoría anonimizada original
evolucionó a cadena de hash inmutable.
