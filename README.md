# NEXO — Plataforma Educativa de Control de Asistencia Biométrica

<p align="center">
  <img src="https://img.shields.io/badge/version-v1.0.0-blue" alt="Version">
  <img src="https://img.shields.io/badge/license-MIT-green" alt="License">
  <img src="https://img.shields.io/badge/build-passing-brightgreen" alt="Build">
  <img src="https://img.shields.io/badge/coverage-82%25-yellowgreen" alt="Coverage">
  <br>
  <img src="https://img.shields.io/badge/C++20-00599C?logo=c%2B%2B&logoColor=white" alt="C++20">
  <img src="https://img.shields.io/badge/PHP_8.2-777BB4?logo=php&logoColor=white" alt="PHP 8.2">
  <img src="https://img.shields.io/badge/React_18-61DAFB?logo=react&logoColor=black" alt="React 18">
  <img src="https://img.shields.io/badge/PostgreSQL_15-4169E1?logo=postgresql&logoColor=white" alt="PostgreSQL 15">
  <img src="https://img.shields.io/badge/Redis-DC382D?logo=redis&logoColor=white" alt="Redis">
  <img src="https://img.shields.io/badge/Tauri_2.0-24C8DB?logo=tauri&logoColor=white" alt="Tauri 2.0">
</p>

---

## Arquitectura General

```
┌─────────────────────────────────────────────────────────────────────┐
│                         NEXO PLATFORM                               │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│   ┌─────────────┐      AES-256-GCM      ┌─────────────────────┐    │
│   │  Edge Node  │ ◄──────────────────► │   Backend API       │    │
│   │  (RPi 4)    │    + Request ID      │   (Railway.app)     │    │
│   │             │                      │                     │    │
│   │  • ZK9500   │      HTTPS/WSS       │  • PHP 8.2 + Nginx  │    │
│   │  • SQLite3  │◄────────────────────►│  • PostgreSQL 15    │    │
│   │  • libcurl  │   JWT (cookie HttpOnly)  • Redis 7       │    │
│   └─────────────┘                      │  • PgBouncer        │    │
│          │                             └─────────────────────┘    │
│          │                                    │                    │
│          │      Heartbeat (60s)              │                    │
│          └────────────────────────────────────►                    │
│                                                                     │
│   ┌─────────────────────────────────────────────────────────┐      │
│   │                    Frontend Layer                        │      │
│   │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────┐  │      │
│   │  │   Web    │  │ Windows  │  │  macOS   │  │ Linux  │  │      │
│   │  │ (PWA)    │  │  (.msi)  │  │  (.dmg)  │  │(.deb)  │  │      │
│   │  └──────────┘  └──────────┘  └──────────┘  └────────┘  │      │
│   │  ┌──────────┐  ┌──────────┐                             │      │
│   │  │ Android  │  │   iOS    │                             │      │
│   │  │  (.apk)  │  │  (.ipa)  │                             │      │
│   │  └──────────┘  └──────────┘                             │      │
│   └─────────────────────────────────────────────────────────┘      │
│                              │                                      │
│                              ▼                                      │
│                    React 18 + Vite + TailwindCSS                  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Características Principales

- **Captura biométrica con ZK9500**: Timeout de 5 segundos con `std::thread` detached + `std::atomic<bool>`. Umbral de coincidencia configurable (0-100) para niños de primaria.
- **Sincronización offline-first**: Cola local SQLite con exponential backoff + jitter (±30%). Resiliente a fallos de red prolongados.
- **Autenticación segura**: JWT RS256/HS256 en cookies `HttpOnly` + `SameSite=Strict`. Blocklist en Redis con fallback a PostgreSQL.
- **Cola asíncrona para notificaciones**: Twilio WhatsApp enquedado en Redis con worker dedicado (`worker_twilio.php`).
- **Auditoría inmutable**: Hash chain HMAC-SHA256 en `global_audit_logs` con validación criptográfica. Cumple Ley 1581 de Colombia.
- **Dashboard por roles**: 7 roles (Rector, Coordinador, Docente, Secretaria, Portero, Auxiliar, Psicorientador) con vistas diferenciadas.
- **Alertas tempranas**: Risk Score para estudiantes basado en tardanzas e inasistencias (`fn_calculate_student_risk`).
- **App nativa multiplataforma**: Tauri 2.0 genera binarios para Windows, macOS, Linux, Android e iOS desde el mismo código React.
- **Row-Level Security (RLS)**: Aislamiento por colegio en PostgreSQL. Incluso un bug en PHP no expone datos de otro colegio.

---

## Stack Tecnológico

| Capa | Tecnologías |
|------|------------|
| **Edge** | C++20, CMake 3.20+, OpenSSL 3.x, libcurl, SQLite3, spdlog, nlohmann/json, libzkfp (ZK9500 SDK) |
| **Backend** | PHP 8.2, PostgreSQL 15, Redis 7, Nginx + PHP-FPM, PgBouncer |
| **Frontend** | React 18, Vite 5, TailwindCSS 3, Tauri 2.0, Axios, Lucide React |
| **Infraestructura** | Docker, Docker Compose, Railway.app, GitHub Actions |
| **Seguridad** | JWT (RS256/HS256), AES-256-GCM, HMAC-SHA256 audit chain, RLS PostgreSQL, Rate limiting Redis |

---

## Requisitos Previos

- **Docker** y **Docker Compose** (para backend)
- **CMake 3.20+** y compilador C++20 (GCC 11+ o Clang 14+)
- **Node.js 18+** y **npm**
- **PostgreSQL 15+** (o usar Railway)
- **Redis 7+**
- **Rust** (para compilar con Tauri)

---

## Instalación y Despliegue

### Backend (PHP + PostgreSQL + Redis)

```bash
cd "Logica de negocio/alojamiento"
cp .env.example .env
# Editar .env con tus credenciales de Railway/PostgreSQL/Redis/Twilio
docker-compose up -d
```

### Edge (C++20)

```bash
cd "Logica de negocio/edge"
mkdir build && cd build
cmake .. -DCMAKE_BUILD_TYPE=Release
make -j$(nproc)
./nexo-edge
```

### Frontend (React + Tauri)

```bash
cd WebApp
npm install

# Desarrollo web
npm run dev

# Compilación nativa (escritorio)
npm run tauri build

# Compilación móvil (Android)
npx tauri android init
npx tauri android build
```

---

## Generar Documentación Swagger

```bash
cd "Logica de negocio/alojamiento"
composer require zircote/swagger-php
vendor/bin/openapi --output public/swagger.yaml routes/ api.php
```

El archivo `swagger.yaml` se sirve en `/swagger.yaml` y puede visualizarse con Swagger UI.

---

## Estructura del Proyecto

```
NEXO/
├── Gestion de Proyecto/           # Planificación, OKRs, documentación
├── Logica de negocio/
│   ├── alojamiento/               # Backend API (PHP)
│   │   ├── routes/                # Endpoints REST
│   │   ├── sql/                   # Migraciones y scripts
│   │   ├── scripts/               # Cron jobs (recalc_risk.sh)
│   │   ├── pgbouncer/             # Configuración de pool de conexiones
│   │   └── docker-compose.yml     # Stack local
│   └── edge/                      # Firmware edge (C++)
│       ├── src/
│       ├── include/
│       └── CMakeLists.txt
├── WebApp/                        # Frontend React + Tauri
│   ├── src/
│   ├── src-tauri/
│   └── package.json
└── README.md
```

---

## Seguridad

- **Cifrado en tránsito**: TLS 1.3 en todas las comunicaciones.
- **Cifrado en reposo**: AES-256-GCM para templates biométricos en SQLite edge.
- **Prevención de replay**: Nonce único por evento + validación de timestamp (±15 min).
- **Rate limiting**: 100 req/min por IP/usuario vía Redis.
- **Auditoría**: Toda operación sensible se registra en `global_audit_logs` con hash chain inmutable.
- **RLS**: Row-Level Security en PostgreSQL garantiza aislamiento por colegio.

---

## Licencia

MIT — Ver [LICENSE](LICENSE) para detalles.

---

<p align="center">
  <strong>NEXO</strong> — Conectando la seguridad escolar con la tecnología.
</p>
