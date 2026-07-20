# NEXO Backend API

API RESTful para gestión institucional educativa con biometría, mensajería WhatsApp (Twilio), dispositivos EDGE y auditoría completa.

**Versión:** 1.0  
**PHP:** >= 8.2  
**PostgreSQL:** >= 15  
**Requiere:** Redis (colas), extensión pdo_pgsql

---

## Quick Start

```bash
# 1. Instalar dependencias
composer install

# 2. Configurar variables de entorno (ver .env.example)
export DATABASE_URL="postgresql://user:pass@host:5432/nexo"
export NEXO_AES_KEY="32-byte-key-for-payload-encryption"
export REDISHOST="localhost"
export CORS_ALLOW_ORIGINS="https://tu-dominio.com"
export JWT_SECRET="min-32-char-secret-for-hs256"

# 3. Ejecutar esquema de base de datos
psql $DATABASE_URL -f sql/nexo_full_migration.sql

# 4. Ejecutar tests de integridad
php tests/integration_test.php

# 5. Iniciar workers (en terminales separadas)
php workers/worker_biometric.php
php workers/worker_twilio.php
php workers/worker_audit.php
```

---

## Variables de Entorno

| Variable | Requerida | Descripción |
|----------|-----------|-------------|
| `DATABASE_URL` | ✅ | URL PostgreSQL completa |
| `PGHOST` / `PGDATABASE` / `PGUSER` / `PGPASSWORD` | ⚠️ | Alternativa a DATABASE_URL |
| `NEXO_AES_KEY` | ✅ | Clave AES-256-GCM para cifrado de payloads EDGE |
| `REDISHOST` | ✅ | Host Redis para colas y rate limiting |
| `REDISPORT` | ❌ | Puerto Redis (default: 6379) |
| `REDIS_PASSWORD` | ❌ | Password Redis |
| `REDIS_DB` | ❌ | DB Redis (default: 0) |
| `CORS_ALLOW_ORIGINS` | ✅ | Orígenes CORS exactos |
| `JWT_SECRET` | ⚠️ | Clave HS256 (si no se usa RS256) |
| `JWT_PRIVATE_KEY` / `JWT_PUBLIC_KEY` | ⚠️ | Claves RS256 PEM base64 |
| `JWT_ISSUER` | ❌ | Issuer JWT (default: nexo-api) |
| `JWT_AUDIENCE` | ❌ | Audience JWT (default: nexo-webapp) |
| `JWT_ACCESS_TTL_SECONDS` | ❌ | TTL token (default: 86400) |
| `TWILIO_ACCOUNT_SID` | ❌ | SID Twilio (solo para outbound WhatsApp) |
| `TWILIO_AUTH_TOKEN` | ❌ | Token Twilio |
| `TWILIO_WEBHOOK_URL_BASE` | ❌ | URL base para validación de firma webhook |
| `NEXO_OWNER_WHATSAPP` | ❌ | Teléfono para notificaciones de contacto |
| `APP_ENV` | ❌ | development / production |
| `APP_NEXO_HMAC_SECRET` | ❌ | Secret para cadena de auditoría |
| `LOGIN_2FA_ENABLED` | ❌ | "true" para activar 2FA vía WhatsApp |

---

## Endpoints

### Autenticación

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| POST | `/auth/login` | Público | Login con email/password |
| POST | `/auth/verify-2fa` | Público | Verificación código 2FA |
| POST | `/auth/logout` | Bearer | Cerrar sesión |
| GET | `/auth/me` | Bearer | Datos del usuario actual |

### Dashboard

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| GET | `/dashboard/stats` | Bearer | Estadísticas del panel |
| GET | `/dashboard/teacher-group-detail` | Bearer | Detalle por grupo (docente) |
| GET | `/dashboard/events` | Bearer | Eventos recientes |

### Estudiantes

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| GET | `/students` | Bearer | Listar estudiantes |
| POST | `/students` | Bearer | Crear estudiante (SECRETARY, RECTOR, COORDINATOR) |

### Operaciones

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| POST | `/operations/sos` | Bearer | Alerta SOS |
| POST | `/operations/inasistencia` | Bearer | Reportar inasistencia |
| POST | `/operations/citacion` | Bearer | Citación a acudiente |
| POST | `/operations/permiso` | Bearer | Permiso de salida de clase |
| POST | `/operations/salida` | Bearer | Autorizar salida del colegio |
| POST | `/operations/pedagogica` | Bearer | Salida pedagógica grupal |
| POST | `/operations/horario` | Bearer | Cambio de horario |
| POST | `/operations/incidente` | Bearer | Reportar incidente |
| POST | `/operations/seguimiento` | Bearer | Solicitar seguimiento |
| POST | `/operations/solicitud` | Bearer | Solicitud interna |
| POST | `/operations/daño` | Bearer | Reportar daño |

### Consultas

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| POST | `/consultations/query` | Bearer | Motor de consultas dinámicas |

### Dispositivos EDGE

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| GET | `/devices` | Bearer | Listar dispositivos |
| POST | `/devices` | Bearer | Registrar dispositivo |
| DELETE | `/devices/{uuid}` | Bearer | Revocar dispositivo |
| POST | `/devices/command/{uuid}` | Bearer | Enviar comando M2M |
| GET | `/devices/commands` | X-Device-Token | Edge polling |
| POST | `/devices/ping` | X-Device-Token | Heartbeat |
| GET | `/admin/devices` | SUPER_RECTOR | Health check global |

### Auditoría

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| GET | `/audit/attendance/*` | RECTOR, COORDINATOR | Reportes de asistencia |
| GET | `/audit/discipline/*` | RECTOR, COORDINATOR | Incidentes disciplinarios |
| GET | `/audit/permissions/*` | RECTOR, COORDINATOR | Permisos y salidas |
| GET | `/audit/messaging/*` | RECTOR, COORDINATOR | Mensajería |
| GET | `/audit/teacher/*` | RECTOR, COORDINATOR | Actividad docente |
| GET | `/audit/security/*` | RECTOR, COORDINATOR | Seguridad y accesos |
| GET | `/audit/integrity` | RECTOR, COORDINATOR, SUPER_RECTOR | Validar cadena de hashes |

### Administración

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| POST | `/admin/recalc-risk` | RECTOR, COORDINATOR | Recalcular métricas de riesgo |

### Seguridad

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| POST | `/security/panic` | RECTOR, COORDINATOR | Botón de pánico |

### Usuarios

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| GET | `/users/by-role` | Bearer | Directorio por rol |
| GET | `/users/me/extended` | Bearer | Perfil extendido |
| POST | `/users/upload-photo` | Bearer | Subir foto de perfil |
| POST | `/users/send-verification` | Bearer | Enviar OTP WhatsApp |
| POST | `/users/verify-code` | Bearer | Verificar código OTP |
| POST | `/users/update-profile` | Bearer | Actualizar perfil |
| POST | `/users/change-password` | Bearer | Cambiar contraseña |

### Webhooks

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| POST | `/webhooks/twilio/inbound` | Firma Twilio | Respuestas de acudientes vía WhatsApp |

---

## Roles y Permisos

| Rol | Descripción | Permisos clave |
|-----|-------------|----------------|
| `SUPER_RECTOR` | Super administrador | Todos |
| `RECTOR` | Director de institución | Todos excepto health check de dispositivos |
| `COORDINATOR` | Coordinador académico | Igual a RECTOR sin funciones de admin |
| `TEACHER` | Docente de aula | Operaciones de aula, dashboard filtrado |
| `SECRETARY` | Secretaria administrativa | Estudiantes, reportes, consultas |
| `COUNSELOR` | Psicoorientador | Seguimientos, comportamiento, consultas |
| `SECURITY` | Portero | Ver estudiantes, reportes básicos |
| `AUXILIARY` | Auxiliar administrativo | Consultas, reportes, solicitudes |
| `GUARDIAN` | Acudiente | Sin permisos de API (interactúa vía WhatsApp) |

Los permisos se leen dinámicamente desde la tabla `role_permissions` en cada autenticación.

---

## Arquitectura

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Cliente   │────▶│  api.php    │────▶│   Rutas     │
│  (Web/App)  │◄────│  (router)   │◄────│  (CRUD)     │
└─────────────┘     └──────┬──────┘     └─────────────┘
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
        ┌─────────┐  ┌─────────┐  ┌─────────┐
        │PostgreSQL│  │  Redis  │  │ Twilio  │
        │  (RLS)  │  │ (colas) │  │(WhatsApp│
        └─────────┘  └─────────┘  └─────────┘
                           │
                    ┌──────┴──────┐
                    ▼             ▼
              ┌─────────┐   ┌─────────┐
              │worker_  │   │worker_  │
              │biometric│   │ twilio  │
              └─────────┘   └─────────┘
```

### Flujo de autenticación

1. Cliente envía `POST /auth/login` con email/password
2. Servidor valida contra `users` (bcrypt)
3. Emite JWT con `sub=user_id`, `role`, `school_id`
4. Cliente envía JWT en header `Authorization: Bearer <token>`
5. `requireAuth()` verifica JWT, carga permisos desde `role_permissions`, setea RLS context

### Flujo EDGE (dispositivo biométrico)

1. Dispositivo cifra payload con AES-256-GCM
2. Envía a `POST /` (api.php) con `payload` cifrado
3. Servidor descifra, valida `device_token` contra `edge_devices.token_hash`
4. Encola en Redis `queue:biometric_ingest`
5. `worker_biometric.php` procesa la cola y escribe en DB

### RLS (Row Level Security)

Todas las tablas de datos tienen RLS habilitado. Las queries solo ven filas donde `school_id` coincide con el contexto de sesión (`app.current_school_id`). El rol `SUPER_RECTOR` bypassa RLS vía `is_super_rector()`.

---

## Tests

```bash
# Tests de integridad estática (sin DB)
php tests/integration_test.php

# Verificar sintaxis PHP
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;
```

---

## Deployment

### Base de datos limpia

```bash
psql $DATABASE_URL -f sql/nexo_full_migration.sql
```

Este archivo único crea:
- Todas las tablas (UUID), índices, constraints
- Triggers (normalización de teléfono, cadena de auditoría)
- Funciones (migraciones, RLS helpers, métricas de riesgo)
- Políticas RLS
- Particiones mensuales
- Seed: 1 escuela, 9 roles, 27 permisos, 1 admin user

### Migraciones existentes

Si la base ya tiene datos, usar `schema_migrations_backfill.sql` para registrar migraciones previas sin reejecutarlas.

### Workers

Los workers deben ejecutarse como procesos persistentes:

```bash
# systemd service example
[Unit]
Description=NEXO Biometric Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/nexo/backend/api
ExecStart=/usr/bin/php workers/worker_biometric.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

---

## Estructura del proyecto

```
backend/api/
├── api.php                 # Entry point, router, payload EDGE
├── boot_check.php          # Validación de variables de entorno
├── db.php                  # Conexión PostgreSQL
├── composer.json           # Dependencias (php-mqtt/client)
├── routes/
│   ├── _auth_middleware.php    # JWT, RBAC, RLS context
│   ├── _cors_middleware.php    # CORS
│   ├── auth.php                # Login, logout, 2FA
│   ├── dashboard.php           # Estadísticas
│   ├── students.php            # CRUD estudiantes
│   ├── operations.php          # Comandos (SOS, permisos, etc.)
│   ├── consultations.php       # Motor de consultas
│   ├── devices.php             # Gestión EDGE
│   ├── audit_full.php          # Auditoría completa
│   ├── audit_integrity.php     # Validación de cadena
│   ├── behavior.php            # Métricas de riesgo
│   ├── tracking.php            # Seguimientos
│   ├── users.php               # Perfil, OTP, fotos
│   ├── groups.php              # Grupos académicos
│   ├── misc.php                # Contacto, notificaciones, webhooks
│   ├── security_panic.php      # Botón de pánico
│   └── ...
├── workers/
│   ├── worker_biometric.php    # Procesa cola biometrica
│   ├── worker_twilio.php       # Procesa cola WhatsApp
│   └── worker_audit.php        # Procesa cola auditoría
├── lib/
│   ├── twilio.php              # Cliente Twilio
│   └── RiskScoreEngine.php     # Cálculo de riesgo
├── sql/
│   ├── nexo_full_migration.sql # ESQUEMA OFICIAL
│   ├── nexo_seed.sql           # Datos de desarrollo
│   └── ...
└── tests/
    └── integration_test.php    # Tests estáticos
```

---

## Documentación adicional

- `sql/SCHEMA_CONSOLIDATION.md` — Historia de consolidación del esquema
- `sql/MIGRATION_STRATEGY.md` — Sistema de versionado de migraciones
- `sql/COMPATIBILITY_REPORT.md` — Auditoría Etapa 3 (referencia histórica)

---

*Documento consolidado en Etapa 6.*
