# NEXO — Arquitectura: estado actual y objetivo

## 1. Visión

NEXO es una plataforma educativa para Colombia que combina:

- **Edge biométrico** en cada sede educativa (lectura de huella, asistencia, eventos).
- **API en la nube** que centraliza datos, alertas SAT y notificaciones a acudientes.
- **Cliente institucional** (rector, coordinador, docente, secretaría, portería, auxiliar, psicorientador) para operar la sede.

---

## 2. Estado actual (validado en código)

```
                         ┌────────────────────────────────────────────┐
                         │             GitHub: 0nto/NEXO              │
                         │            (un solo remoto)                │
                         └───────────────┬──────────────┬─────────────┘
                                         │              │
                ┌────────────────────────▼──┐        ┌──▼─────────────────────────────┐
                │ WebApp/                    │        │ backend/api/                   │
                │ Frontend React + Tauri     │        │ Backend PHP productivo + Docker│
                │ (PWA + Desktop)            │        │ (Render)                       │
                └────────────────────────────┘        └────────────────────────────────┘
                                         │
                                         └──────────┐
                                                    │
                                         ┌────────▼─────────────────────┐
                                         │ landing/                       │
                                         │ Landing page estática (Vite)  │
                                         └────────────────────────────────┘

Carpetas físicas:
  WebApp/                            ← React/Vite/PWA + Tauri (Desktop)
  backend/api/                       ← Backend PHP autoritativo (Dockerfile, .htaccess)
  backend/edge/                      ← Edge Raspberry Pi 4 (C++20)
  landing/                           ← Landing page promocional (Three.js)
```

### 2.1 Flujo de datos actual

```
[Edge C++ / Raspberry Pi]      [backend/api/api.php]
   biometría                       ├── decryptPayload(AES-256-GCM)
   sqlite local         ─────────► ├── ingest asíncrona (Redis queue)
   AES-256-GCM                     └── workers procesan → PostgreSQL
                                                    │
                                                    ▼
                                            PostgreSQL (Supabase)
                                                    ▲
                                                    │
[Frontend React] ── axios ──► /v1/* (rutas autenticadas con JWT)
```

### 2.2 Problemas estructurales heredados

- Ingestión cloud requiere validación adicional de device tokens (TODO en devices.php).
- Edge C++ en desarrollo activo - infraestructura completa pero requiere validación en hardware real.
- Mosquitto MQTT deshabilitado temporalmente para pruebas de red.

### 2.3 Edge actual (estructura C++ validada)

```
backend/edge/
├── include/
│   ├── base_de_datos/       CloudManager, Encryption, SqliteManager
│   ├── hal/                 IBiometricSensor, IDisplay, IHttpClient, INotification
│   ├── hardware/dev_stub/   Implementaciones stub
│   ├── hardware/watchdog.h  Wrapper /dev/watchdog
│   ├── interoperabilidad/   AuditTrail facade
│   ├── mqtt/                MqttCommandWorker
│   └── utils/               ConfigManager, Logger, NexoResult
├── src/
│   ├── base_de_datos/       cloud_manager.cpp, encryption.cpp, sqlite_manager.cpp
│   ├── hardware/            Stub, real (GPIO, OLED, ZK9500), watchdog
│   ├── mqtt/                mqtt_command_worker.cpp
│   ├── utils/               ConfigManager.cpp, edge_monitor.py
│   └── main.cpp             Punto de entrada, SyncWorker, HealthMonitor
```

Flujo local: sensor `searchUser()` → `main.cpp` → `SqliteManager` → `AuditTrail` → `SyncWorker` → `CloudManager` POST AES-256-GCM.

### 2.4 Build, scripts y configuración del edge

| Archivo | Propósito |
|---------|-----------|
| `CMakeLists.txt` | Definición del ejecutable `nexo-edge`, dependencias y tests Catch2. |
| `CMakePresets.json` | Presets `dev-x86`, `release-x86`, `cross-arm64-pi4`. |
| `setup_nexo.sh` | Setup + build automático en Linux (instala deps, crea `/var/lib/nexo`, `/var/log/nexo`). |
| `scripts/install_deps_debian.sh` | Instalador de dependencias para Debian/Ubuntu. |
| `scripts/install_deps_fedora.sh` | Instalador de dependencias para Fedora/RHEL/CentOS. |
| `Dockerfile.edge` | Build multi-stage para imagen ARM64 (Pi 4). |
| `config.example.json` | Ejemplo de configuración runtime del edge. |
| `.clang-format` / `.clang-tidy` | Estilo de código y análisis estático. |
| `tests/test_crypto.cpp` / `tests/test_sqlite.cpp` | Tests Catch2 de cripto y SQLite. |

### 2.5 Landing page (Vite + React + Three.js)

```
landing/
├── index.html              # Punto de entrada HTML con SEO/OpenGraph
├── package.json            # Deps: React, Vite, Tailwind v4, GSAP, R3F
├── vite.config.js          # Build con compresión, code-splitting, Terser
├── build.sh                # Atajo `npm run build`
├── src/
│   ├── main.jsx            # React 18 root
│   ├── App.jsx             # Router raíz / + Preloader
│   ├── index.css           # Tokens CSS, utilidades, responsive
│   ├── landing/
│   │   ├── LandingPage.jsx # Orquesta secciones, cookies, ScrollTrigger
│   │   ├── components/     # Navbar, modales, canvas, hooks
│   │   ├── core/           # NexoModel (R3F)
│   │   ├── hooks/          # useCookieConsent
│   │   └── sections/       # Hero, Problem, HowItWorks, ValueProp, Node,
│   │                       # Roles, Security, Download, FinalCTA, Footer
│   └── dashboard/
│       └── DashboardPage.jsx   # Placeholder ruta /dashboard
```

**Flujo de renderizado:**

```
index.html
  └── main.jsx
        └── App.jsx (BrowserRouter + Suspense + Preloader)
              └── LandingPage.jsx
                    ├── CustomCursor (desktop)
                    ├── Navbar
                    ├── HeroSection              [id="hero"]
                    ├── ProblemSection           [id="el-problema"]
                    ├── HowItWorksSection        [id="como-funciona"]
                    ├── ValuePropSection         [id="propuesta-de-valor"]
                    ├── NodeSection              [id="el-nodo"]
                    ├── RolesSection             [id="roles"]
                    ├── SecuritySection          [id="seguridad"]
                    ├── DownloadSection          [id="descarga"]
                    ├── FinalCTASection          [id="contacto"]
                    └── Footer
```

**Build y despliegue:**

- `npm install` → `bash build.sh` (o `npm run build`) genera `landing/dist/`.
- `dist/` es un sitio estático; sirve con nginx, Apache, Vercel, Render, etc.
- Assets pesados (modelo 3D, imágenes, logo) viven en `public/assets/`.

**Dependencias clave:**

- React 19 + Vite 6 + React Router DOM 7.
- Tailwind CSS v4 vía `@tailwindcss/vite`.
- GSAP + ScrollTrigger para animaciones de scroll.
- React Three Fiber / Drei para modelo 3D del nodo y red institucional.
- lucide-react para iconos.

---

### 2.6 WebApp institucional (React + Vite + PWA + Tauri)

```
WebApp/
├── index.html              # Entrada HTML, manifest PWA, CSP, SEO
├── package.json            # Deps: React 18, Vite 5, Tailwind 3, Framer Motion, Axios
├── vite.config.js          # Base /app/, PWA Workbox, code-splitting manual
├── tailwind.config.js      # Tokens institucionales (gov, bio), darkMode class
├── postcss.config.js       # Tailwind + autoprefixer
├── vercel.json             # Rewrites/redirects /app/* a index.html + headers HSTS
├── src/
│   ├── main.jsx            # Root: BrowserRouter basename /app/, PWA SW, providers
│   ├── App.jsx             # Routing lazy, ProtectedRoute, PWA install, deep links
│   ├── index.css           # Tailwind directives + estilos globales
│   ├── api/                # client, auth, audit, dashboard, notifications, behavior,
│   │                       # consultations, operations, reports, students, telemetry,
│   │                       # tracking, users
│   ├── components/         # ErrorBoundary, LogoNexo, PwaInstallPrompt
│   ├── config/roles.js     # ROLES y SIDEBAR_ITEMS (fuente de verdad RBAC)
│   ├── context/            # AuthContext, ThemeContext
│   ├── hooks/useAuth.js    # Consumidor de AuthContext
│   ├── layout/             # Layout (shell) + Sidebar
│   ├── pages/              # Login, Dashboard, Operation, Notifications, Reports,
│   │                       # Consultation, ConsultationDrawer, Enrollment, Audit,
│   │                       # Seguimiento, TrackingModal, Downloads, InstallPage,
│   │                       # Profile, Unauthorized
│   ├── routes/             # ProtectedRoute
│   ├── store/userStore.js  # Singleton en memoria del usuario actual
│   └── utils/              # cn, formatters, mobilePermissions, nativeAuth
```

**Flujo de renderizado:**

```
index.html
  └── main.jsx (BrowserRouter basename /app/)
        └── App.jsx (Routes + Suspense)
              ├── Layout (rutas protegidas)
              │     ├── Dashboard
              │     ├── Operation
              │     ├── Notifications
              │     ├── Reports
              │     ├── Consultation + ConsultationDrawer
              │     ├── Enrollment
              │     ├── Audit
              │     ├── Seguimiento
              │     └── Profile
              ├── Login
              ├── Unauthorized
              ├── Downloads
              └── InstallPage
```

**Arquitectura de seguridad y comunicación:**

- Autenticación con cookie **HttpOnly** (`withCredentials: true`).
- Token JWT en cookie gestionado por el backend; el frontend no almacena tokens en localStorage.
- RBAC en frontend via `ROLES` + `SIDEBAR_ITEMS` + `ProtectedRoute`.
- Telemetría no sensible: errores, latencia API, eventos biométricos y pings, con cola de 100 eventos y flush cada 5 min.
- PWA: service worker Workbox con cache de assets, rutas API con `NetworkFirst` y cola de sincronización para POST `/v1`.
- Tauri desktop/mobile: plugins de biometría y store seguro para refresh token.

**Dependencias clave:**

- React 18 + Vite 5 + React Router DOM 6.
- Tailwind CSS 3 con darkMode basado en clase.
- Framer Motion para transiciones y modales.
- Axios con interceptores adaptativos de timeout y redirección 401.
- lucide-react para iconografía.

---

## 3. Arquitectura objetivo (post-Etapa 4)

```
nexo-edge/        (Raspberry Pi 4, C++17, libgpiod, ZK9500)
  ├── core/                        Lógica pura, sin Arduino
  ├── platform/linux/              GPIO, I2C, hilo, NTP, libgpiod
  ├── biometric/zk9500/            Driver real, RAII, sin leaks
  ├── persistence/sqlite/          Prepared statements only
  ├── sync/                        Thread pool + cola persistente
  ├── rules/                       Motor SAT (alertas tempranas)
  ├── notifiers/whatsapp/          Store-and-forward
  └── tests/

nexo-cloud-api/   (PHP 8.2, PostgreSQL, Redis, Docker)
  ├── public/index.php             Front controller único
  ├── src/Routes/                  auth, dashboard, students, ...
  ├── src/Ingestion/               Pipeline transaccional Edge → DB
  ├── src/Security/                JWT, rate limit (Redis), CSP
  ├── src/Audit/                   Append-only + hash chain
  ├── migrations/                  SQL versionado
  └── docker/

nexo-client/      (React + Tauri + Capacitor + PWA)
  ├── src/                         UI institucional
  ├── src-tauri/                   Desktop (Win/Mac/Linux)
  ├── android/                     APK sideload (Capacitor)
  ├── ios/                         PWA L3 (Add to Home Screen)
  └── public/manifest.webmanifest
```

### 3.1 Contrato Edge ↔ Cloud (objetivo)

```jsonc
// POST /v1/ingest/biometric  (Authorization: Bearer <device_token>)
{
  "device_id": "uuid-del-nodo",
  "school_id": 12,
  "events": [
    {
      "event_id":   "uuid-evento",      // idempotencia
      "type":       "ATTENDANCE_IN",
      "student_doc":"1001234567",
      "captured_at":"2026-05-10T11:32:08Z",
      "payload":    { /* datos específicos */ },
      "signature":  "hex-hmac-sha256"   // integridad
    }
  ]
}
```

- `event_id` es **único** por evento físico → cualquier reintento es seguro.
- Cloud devuelve `200` solo tras `COMMIT` exitoso.
- Cloud devuelve por evento un estado: `accepted`, `duplicate`, `rejected:<motivo>`.

### 3.2 Principios no negociables

- **Idempotencia** en ingesta.
- **Prepared statements** en toda capa SQL.
- **No bloqueo** del hilo de captura biométrica.
- **Auditoría inmutable** con hash chain en PostgreSQL.
- **Secretos** fuera del código (env, Vault, o NVS firmado).
- **Observabilidad** (`request_id`, `device_id`, latencia ingesta).

---

## 4. Cómo llegamos del estado actual al objetivo

| Etapa | Foco                                          | Reversible | Riesgo si se omite |
|-------|-----------------------------------------------|------------|--------------------|
| 0     | Contención, audit, source-of-truth            | Sí         | Cambios destructivos sin red de seguridad |
| 1     | Edge: Infraestructura C++ Raspberry Pi 4     | Parcial    | Hardware no producción-ready |
| 2     | Cimientos: ingesta, SQL safe, async, Redis    | Parcial    | Pérdida silenciosa de datos |
| 3     | SAT, store-and-forward, repo limpio           | Sí         | No hay valor diferencial |
| 4     | Multiplataforma (Tauri / Capacitor / PWA L3)  | Sí         | Comisiones 30% en stores |

> El detalle de cada etapa vive en `STAGE_<N>_PLAN.md` (se crean al iniciar cada etapa).
