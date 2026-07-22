# NEXO — Visión general del proyecto

> Documento de nivel de proyecto. Aquí se explica, en un solo lugar, qué es NEXO, cómo se conectan sus partes, para qué se usa y cómo funciona a alto nivel. Para detalles técnicos profundos ver los documentos enlazados al final.

---

## 1. Qué es NEXO

NEXO es una **plataforma de gestión institucional educativa** orientada a colegios en Colombia. Su propósito es centralizar la operación diaria de una sede educativa y conectarla con el aula física mediante dispositivos de borde (Raspberry Pi + lector de huella).

Los principales casos de uso son:

- **Asistencia biométrica**: el nodo (edge) lee la huella del estudiante y registra entrada/salida.
- **Comunicación con acudientes**: envío automático de alertas y citaciones por WhatsApp (Twilio).
- **Operaciones institucionales**: citaciones, permisos de salida, salidas pedagógicas, alertas SOS, incidentes.
- **Auditoría y seguimiento**: dashboards, consultas dinámicas, seguimiento estudiantil y reportes.
- **Gestión de estudiantes y personal**: matrícula, grupos, perfiles, roles y permisos.

---

## 2. Arquitectura general

NEXO está dividido en cuatro componentes principales:

```
┌────────────────────────────────────────────────────────────────────────────┐
│                              NEXO PLATFORM                                   │
├──────────────────────┬──────────────────────┬───────────────────────────────┤
│   WebApp/            │   landing/           │   backend/api/                │
│   React + Vite       │   React + Vite       │   PHP 8.2 + PostgreSQL + Redis│
│   PWA / Tauri        │   Landing estática   │   API REST + Workers          │
│   app.nexo.com       │   nexo.com           │   api.nexo.com                │
└──────────┬───────────┴──────────┬───────────┴───────────────┬───────────────┘
           │                      │                           │
           │   HTTPS / axios      │   HTTPS / fetch           │   HTTPS POST cifrado
           │   cookie HttpOnly    │   formulario contacto     │   AES-256-GCM
           ▼                      ▼                           ▼
┌────────────────────────────────────────────────────────────────────────────┐
│                         backend/api/api.php (front controller)               │
│  ─ CORS + rate limit                                                        │
│  ─ JWT / RBAC / RLS contexto                                               │
│  ─ Routing a routes/*.php                                                  │
│  ─ Ingesta cifrada del edge                                                │
└──────────────────┬─────────────────────────────────────┬───────────────────┘
                   │                                     │
        ┌──────────▼──────────┐              ┌──────────▼──────────┐
        │  PostgreSQL (Supabase)│             │  Redis (Upstash)    │
        │  Datos + RLS         │              │  Colas + cache      │
        └──────────┬───────────┘              └──────────┬──────────┘
                   │                                     │
                   │   triggers / RLS                    │   queue:biometric_ingest
                   │                                     │   queue:twilio
                   │                                     │   queue:audit_logs
                   │                                     │
                   │                        ┌────────────▼────────────┐
                   │                        │  workers/               │
                   │                        │  worker_biometric.php   │
                   │                        │  worker_twilio.php      │
                   │                        │  worker_audit.php       │
                   │                        └─────────────────────────┘
                   │                                     │
                   └─────────────────────────────────────┘
                                         │
                            ┌────────────▼────────────┐
                            │  backend/edge/          │
                            │  Raspberry Pi 4 (C++)   │
                            │  Sensor ZK9500 + OLED   │
                            │  SQLite local           │
                            └─────────────────────────┘
```

### 2.1 Componentes

| Carpeta | Tecnología | Responsabilidad |
|---------|-----------|-------------------|
| `WebApp/` | React 18, Vite 5, Tailwind 3, Framer Motion, Axios | SPA institucional: login, dashboard, operaciones, auditoría, seguimiento, perfil. PWA y futuro Tauri. |
| `landing/` | React 19, Vite 6, Tailwind v4, GSAP, React Three Fiber | Sitio promocional: hero, secciones de valor, formulario de contacto, descargas. |
| `backend/api/` | PHP 8.2, PostgreSQL, Redis, Twilio | API REST autoritativa: autenticación, RBAC, RLS, operaciones, ingesta edge, workers. |
| `backend/edge/` | C++20, SQLite, MQTT, libgpiod, ZK9500 | Nodo físico en cada sede: captura biométrica, pantalla OLED, sincronización con la nube. |

---

## 3. Conexiones y flujos principales

### 3.1 Usuario institucional → WebApp → API

```
Usuario (rector/docente/secretaría)
  │
  ▼
WebApp/  ──login──▶  POST /auth/login  (api.php)
  │                   │  valida bcrypt
  │                   ▼  emite JWT en cookie HttpOnly
  │
  ├──▶  axios + withCredentials  ──▶  /dashboard/stats, /operations/*, /audit/*
  │                                     │
  │                                     ▼
  │                               routes/*.php + _auth_middleware.php
  │                                     │
  │                                     ▼
  │                               PostgreSQL (RLS por school_id / role)
  │
  ▼
Pantalla de dashboard / operación / auditoría / seguimiento
```

**Seguridad del flujo:**

- El frontend **no guarda el JWT en localStorage**. Se usa cookie `HttpOnly` o Bearer token según la implementación del middleware.
- `X-Requested-With: XMLHttpRequest` en requests mutantes mitiga CSRF.
- PostgreSQL aplica RLS (Row Level Security) con `app.current_school_id` y `app.current_role`; cada usuario solo ve filas de su sede.

### 3.2 Edge biométrico → API

```
Lector ZK9500 (Raspberry Pi 4)
  │
  ▼
backend/edge/main.cpp
  │
  ├── SqliteManager::getEstudianteByHuellaID
  ├── AuditTrail::logEvent (SQLite local)
  ├── INotification::notifySuccess
  ├── IDisplay::showMessage
  └── SyncWorker::nudge()
        │
        ▼
CloudManager::curlPost(payload cifrado)
        │
        ▼
POST /  (api.php)
  │
  ▼
decryptPayload(AES-256-GCM) → valida device_token
  │
  ▼
rPush queue:biometric_ingest (Redis)
  │
  ▼
worker_biometric.php (scriptReliablePop + Lua + GC)
  │
  ▼
INSERT biometric_events / UPSERT students / UPDATE active
```

**Características del flujo:**

- El payload viaja cifrado con AES-256-GCM.
- La cola usa patrón reliable queue (`LMOVE` + timestamp + Lua + garbage collector) para evitar pérdida de eventos.
- Cada evento tiene `event_id` único; reintentos son idempotentes.

### 3.3 Operaciones con WhatsApp (Twilio)

```
WebApp → POST /operations/citacion
            │
            ▼
     routes/operations.php
            │
            ├── requireAuth() + logUserCommand()
            ├── INSERT user_commands
            ├── INSERT twilio_messages
            └── enqueueTwilioJob() → rPush queue:twilio
            │
            ▼
     worker_twilio.php consume
            │
            ▼
     sendTwilioWhatsAppSmart() → Twilio API
            │
            ▼
     Acudiente recibe WhatsApp
            │
            ▼
     POST /webhooks/twilio/inbound  (Twilio callback)
            │
            ▼
     routes/twilio_delivery.php → UPDATE delivery_status
```

### 3.4 Auditoría inmutable

```
Cualquier acción institucional
  │
  ▼
INSERT global_audit_logs
  │
  ▼
rPush queue:audit_logs
  │
  ▼
worker_audit.php
  │
  ▼
INSERT global_audit_logs con cadena de hashes HMAC
```

La cadena de hashes permite validar integridad vía `/audit/integrity`.

---

## 4. Roles y permisos

NEXO usa RBAC en la base de datos. Los roles principales del frontend son:

| Rol | Uso principal |
|-----|---------------|
| `SUPER_RECTOR` | Superadministrador (bypasea RLS). |
| `RECTOR` | Director de sede; acceso completo a su institución. |
| `COORDINADOR` | Coordinador académico; auditoría, operaciones, seguimiento. |
| `DOCENTE` | Dashboard de grupo, consultas, operaciones de aula. |
| `SECRETARIA` | Matrícula de estudiantes, reportes, consultas. |
| `PORTERO` | Registro de salidas, permisos, seguridad. |
| `AUXILIAR` | Consultas y solicitudes. |
| `PSICORIENTADOR` | Seguimientos, comportamiento, riesgo estudiantil. |
| `ACUDIENTE` | Interactúa solo vía WhatsApp (sin acceso a WebApp). |
| `EDGE_NODE` | Rol de sistema para el dispositivo físico. |

Los permisos se cargan dinámicamente desde `role_permissions` en cada autenticación.

---

## 5. Capas de datos

| Sistema | Uso | Ejemplos |
|---------|-----|----------|
| **PostgreSQL** | Fuente de verdad de la nube | usuarios, estudiantes, asistencia, auditoría, RLS. |
| **Redis** | Colas, cache, rate limiting, JWT blocklist, panic mode | `queue:biometric_ingest`, `queue:twilio`, `jwt:blocklist`, `panic:school:*`. |
| **SQLite (edge)** | Base local en Raspberry Pi | estudiantes cacheados, patrones biométricos, audit trail local. |
| **Twilio** | Mensajería WhatsApp outbound/inbound | alertas, citaciones, OTP 2FA, respuestas de acudientes. |

---

## 6. Seguridad a alto nivel

- **Autenticación**: JWT (RS256 preferido, HS256 legacy) emitido tras login con bcrypt.
- **Sesión**: cookie `HttpOnly` + `X-Requested-With` para CSRF; WebApp usa `withCredentials: true`.
- **Autorización**: RBAC en PHP + RLS en PostgreSQL.
- **Cifrado en tránsito**: HTTPS everywhere; payload edge con AES-256-GCM.
- **Cifrado en reposo**: PostgreSQL en Supabase; AES key en variable de entorno `NEXO_AES_KEY`.
- **Auditoría**: `global_audit_logs` con cadena HMAC; `/audit/integrity` valida la cadena.
- **Panic mode**: invalida sesiones activas de una escuela vía `school_panic_events` + Redis.

---

## 7. Despliegue actual (MVP)

La arquitectura oficial de despliegue es:

- **Backend PHP API** → Render (`api.nexo.com` o URL de Render).
- **WebApp** → Vercel (`app.nexo.com`) con `base: '/app/'`.
- **Landing Page** → Vercel (`nexo.com`).
- **PostgreSQL** → Supabase.
- **Redis** → Upstash.
- **MQTT** → Mosquitto propio (deshabilitado temporalmente en Docker).
- **Edge**: compila para Raspberry Pi 4 con `setup_nexo.sh` / `CMakePresets.json`.

La configuración se realiza mediante variables de entorno; no hay URLs de servicios hardcodeadas en el repositorio.

---

## 8. Arquitectura objetivo (roadmap)

El proyecto está evolucionando hacia una arquitectura de 3 dominios desacoplados:

```
┌──────────────┐    ┌──────────────┐    ┌─────────────────────────────┐
│   nexo.com   │    │ app.nexo.com │    │      api.nexo.com           │
│   landing/   │    │   WebApp/    │    │  nexo-cloud-api (PHP + Pg)  │
└──────────────┘    └──────┬───────┘    └──────────────┬──────────────┘
       │                   │                         │
       │                   │  HTTPS + cookie         │
       └───────────────────┼─────────────────────────┘
                           │
                           ▼
              ┌────────────────────────┐
              │   nexo-edge/ (Pi 4)    │
              │   C++17, libgpiod,     │
              │   ZK9500, MQTT         │
              └────────────────────────┘
```

El plan maestro de etapas vive en `plan.md` (Etapas 2-8).

---

## 9. Cómo se usa este repositorio

### 9.1 Desarrollo del backend

```bash
cd backend/api
composer install
psql $DATABASE_URL -f sql/nexo_full_migration.sql
php workers/worker_biometric.php &
php workers/worker_twilio.php &
php workers/worker_audit.php &
php -S localhost:8080
```

### 9.2 Desarrollo de la WebApp

```bash
cd WebApp
npm install
npm run dev
# La app se sirve bajo /app/ (basename configurado en vite.config.js)
```

### 9.3 Desarrollo de la landing

```bash
cd landing
npm install
npm run dev
```

### 9.4 Build del edge

```bash
cd backend/edge
bash setup_nexo.sh
# o manualmente con CMake presets
```

---

## 10. Documentación relacionada

| Documento | Para qué sirve |
|-----------|----------------|
| `documentation/ARCHITECTURE.md` | Arquitectura actual vs objetivo, diagramas, flujos, dependencias. |
| `documentation/ROUTES_WORKERS_AUDIT.md` | Auditoría completa de rutas PHP, workers, SQL, tests y edge. |
| `documentation/LEGACY_UNUSED_CODE.md` | Código legacy, duplicaciones, deuda técnica y candidatos a refactor. |
| `plan.md` | Plan de trabajo Etapas 2-8 (migraciones, compatibilidad, roles, seguridad, deuda técnica). |
| `backend/api/README.md` | Quick start, variables de entorno, endpoints y estructura del API. |
| `backend/edge/README.md` | Guía de build y operación del nodo Raspberry Pi. |
| `WebApp/BUILD_INSTRUCTIONS.md` | Instrucciones para compilar WebApp con Tauri. |
| `landing/BUILD.md` | Instrucciones de build de la landing page. |

---

## 11. Convenciones importantes

- **Base de datos**: esquema único en `backend/api/sql/nexo_full_migration.sql` (idempotente, UUID, RLS, particiones mensuales).
- **Routing backend**: un solo punto de entrada `backend/api/api.php` que incluye `routes/*.php` según `$cleanPath`.
- **Workers**: procesos PHP persistentes que consumen colas Redis; se recomienda ejecutarlos con `systemd` o supervisor.
- **Frontend WebApp**: cookie HttpOnly para autenticación, RBAC en `WebApp/src/config/roles.js`, Axios con timeout adaptativo.
- **Edge**: desarrollo con stubs (`DevStub*`) y compilación condicional para hardware real (`Real*` ZK9500, GPIO, OLED).

---

> **Nota final**: este documento es una visión de conjunto. Para decisiones de implementación, refactor o seguridad, siempre consultar los documentos técnicos específicos listados en la sección 10.
