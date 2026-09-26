# DEPLOYMENT.md — Despliegue de NEXO

Documentación de los entornos de NEXO y cómo desplegar cada componente. Basada en `docker-entrypoint.sh`, `vercel.json`, `.github/workflows/nexo-ci-cd.yml`, y los scripts de infra.

## Topología

```
                    ┌─────────────┐
                    │   Vercel    │
                    │  (PWA +     │  HTTPS
                    │  Landing)   │
                    └──────┬──────┘
                           │
                    ┌──────▼──────┐
                    │   Render    │  HTTPS (Nginx + PHP-FPM 8.2)
                    │  (Backend   │  ← Mosquitto MQTT (127.0.0.1:1883)
                    │   API)      │  ← Workers PHP (daemon + supercronic)
                    └──┬──────┬───┘
                       │      │
              ┌────────▼┐  ┌──▼─────────┐
              │PostgreSQL│  │   Redis    │
              │  15+     │  │ (opcional, │
              │(Render)  │  │  fallback) │
              └──────────┘  └────────────┘

    ┌──────────────────────────────────────┐
    │  Raspberry Pi 4 (x N aulas)          │
    │  nexo-edge (C++)                     │
    │  SQLite local + sensor biométrico    │
    │  → POST /ingest (HTTPS, AES-256-GCM) │
    │  → MQTT /commands (TLS 8883)         │
    └──────────────────────────────────────┘
```

## Componentes

| Componente | Tecnología | Hosting | Repo path |
|---|---|---|---|
| Backend API | PHP 8.2 + Nginx + PHP-FPM | Render (Docker) | `backend/api/` |
| Base de datos | PostgreSQL 15+ | Render | `sql/schema.sql` |
| Cache/colas | Redis 7+ | Render (opcional) | — |
| PWA | React + Vite | Vercel | `frontend/pwa/` |
| Landing | React + Vite + GSAP | Vercel | `frontend/landing/` |
| Edge | C++ (ARM64) | Raspberry Pi 4 (on-prem) | `backend/edge/` |
| CI/CD | GitHub Actions | GitHub | `.github/workflows/` |

## Backend API (Render)

### Dockerfile

`backend/api/Dockerfile` construye una imagen con:

- PHP 8.2-FPM + extensiones (`pdo_pgsql`, `mbstring`, `openssl`, `curl`, `bcmath`, `redis`).
- Nginx (reverse proxy a PHP-FPM).
- Supervisor (gestiona PHP-FPM, Nginx, Mosquitto, workers).
- Mosquitto MQTT broker.
- PgBouncer (opcional, transaction-pooling).
- supercronic (cron jobs).

### docker-entrypoint.sh

Secuencia de arranque del contenedor:

1. Genera config de Nginx (server block, upstream PHP-FPM).
2. Genera config de PHP-FPM (pool, workers, `php.ini`).
3. Genera config de Mosquitto (`mosquitto.conf` con auth `MQTT_USER`/`MQTT_PASS`).
4. Inicia Mosquitto en background.
5. Lanza workers daemon-loop en background (biometric_ingest, audit, notification, twilio, webhook, absence_detector, evasion_detector, permission_status).
6. Registra crontabs en supercronic (create_monthly_partition, partition_retention, redis_gc).
7. Inicia PHP-FPM + Nginx en foreground.

### Variables de entorno (Render)

Configurar en el dashboard de Render (ver `backend/api/.env.example` para el listado completo):

**Obligatorias:**
- `DATABASE_URL` — `postgresql://user:pass@host:5432/nexo`
- `JWT_SECRET` — secreto para firma JWT HS256
- `NEXO_AES_KEY` — clave AES-256 (32 bytes hex/base64) para payloads edge
- `NEXO_HMAC_SECRET` — secreto para cadena de auditoría HMAC
- `CORS_ALLOW_ORIGINS` — orígenes permitidos (URL de la PWA y landing en Vercel)

**Recomendadas:**
- `REDISHOST`, `REDISPORT`, `REDIS_PASSWORD`, `REDIS_TLS` — Redis (si no se setea, fallback a PG/inline)
- `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_WHATSAPP_TEMPLATE_SID` — WhatsApp
- `MQTT_USER`, `MQTT_PASS` — auth del broker Mosquitto
- `APP_ENV=production`
- `LOGIN_2FA_ENABLED=true` (recomendado en producción)
- `AUDIT_WORKER_ENABLED=1`

**Workers:**
- `ABSENCE_DETECTOR_MODE`, `ABSENCE_CHECK_INTERVAL`
- `EVASION_DETECTOR_MODE`, `EVASION_CHECK_INTERVAL`
- `PERMISSION_STATUS_MODE`, `PERMISSION_CHECK_INTERVAL`
- `BIOMETRIC_DEDUP_WINDOW_SECONDS`, `BIOMETRIC_GC_MAX_AGE`
- `TWILIO_RATE_LIMIT`, `TWILIO_MAX_SENDS_PER_HOUR`, `TWILIO_MAX_DAILY_PER_PHONE`
- `NEXO_PARTITION_RETENTION_MONTHS`

### Health check

Render usa `GET /health` como health check. El endpoint verifica PostgreSQL, Redis (si configurado), workers (heartbeat Redis), y disco. Responde 200 si todo está OK, 503 si algún componente crítico falla.

### Deploy automático

El job `backend-deploy` del CI/CD ejecuta en push a `main`:

1. Construye la imagen Docker.
2. Push a Render (vía Render Deploy Hook o API).
3. Render reconstruye y redeploya el servicio.

## Base de datos (Render PostgreSQL)

### Instalación inicial

```bash
DATABASE_URL="postgresql://user:pass@host:5432/nexo" ./sql/deploy_db.sh
```

`sql/deploy_db.sh` ejecuta `psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f sql/schema.sql`. Crea todas las tablas, funciones, triggers, policies RLS, particiones iniciales, y el seed mínimo.

### PgBouncer

`backend/api/infra/pgbouncer/entrypoint.sh` configura PgBouncer en modo transaction-pooling. Necesario porque los settings `app.current_school_id`/`app.current_role` se fijan por transacción. Si PgBouncer no se usa, los settings persisten entre peticiones (riesgo de cross-tenant).

### Particiones mensuales

`create_monthly_partition.sh` corre el primer día de cada mes (vía supercronic) y crea la partición del mes siguiente con `fn_ensure_partitions(1)`. `partition_retention` dropea particiones > 24 meses con `fn_drop_old_partitions(24)`.

### Backups

Render PostgreSQL incluye backups automáticos diarios con retención configurable. Para backups manuales:

```bash
pg_dump "$DATABASE_URL" > backup_$(date +%Y%m%d).sql
```

## Redis (Render, opcional)

Redis acelera cache, colas, rate limiting, dedup, y JWT blocklist. Si no está configurado, el sistema degrada gracefully:

- **Colas**: los workers hacen polling PG o procesan inline.
- **Cache**: la API consulta PG directamente (más lento pero funcional).
- **Rate limiting**: fail-open (login, twilio) o fail-closed (contacto → 503).
- **JWT blocklist**: los tokens revocados expiran naturalmente en 15 min.
- **Dedup biométrico**: fail-open (la API dedup con ON CONFLICT en PG).

Variables: `REDISHOST`, `REDISPORT`, `REDIS_PASSWORD`, `REDIS_TLS` (o `REDIS_URL`).

## PWA (Vercel)

### Configuración

`frontend/pwa/vercel.json`:

- Build command: `npm run build`.
- Output: `dist`.
- Base `/app/`: la PWA se sirve bajo `/app/` (configurada en `vite.config.js`).
- Redirects legacy: rutas sin `/app/` (`/login`, `/operacion`, `/perfil`, `/consulta`, etc.) redirigen a su equivalente con prefijo `/app/`.
- SPA fallback: rewrite `/app/(.*)` a `/app/index.html`.
- Headers de seguridad: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`.
- Cache de estáticos con hash inmutable (`/app/assets/:file*`).

### Variables de entorno (Vercel)

- `VITE_API_BASE_URL` — URL del backend en Render (ej. `https://nexo-80go.onrender.com`).

### Deploy

Automático vía CI/CD (job `webapp-deploy` en push a `main`) o vía Vercel CLI:

```bash
cd PWA && vercel --prod
```

## Landing (Vercel)

### Variables de entorno (Vercel)

- `VITE_API_BASE_URL` — URL del backend (para `POST /contacto`).

### Deploy

Automático vía CI/CD (job `landing-deploy`) o:

```bash
cd landing && vercel --prod
```

## Edge (Raspberry Pi 4)

### Requisitos de hardware

- Raspberry Pi 4 (2GB+ RAM recomendado).
- Sensor biométrico U.are.U 5300 (DigitalPersona, VID 05ba) o ZK9500 (ZKTeco).
- Display OLED SSD1306 (I2C, dirección 0x3C).
- LEDs verde/rojo + buzzer (GPIO).
- MicroSD 16GB+ ( clase 10).
- Conexión de red (WiFi o Ethernet).

### Instalación

1. **OS**: Raspberry Pi OS Lite 64-bit (Debian Bullseye/Bookworm).
2. **Dependencias**:

   ```bash
   cd backend/edge
   sudo bash scripts/install_deps_debian.sh
   ```

3. **Compilación**:

   ```bash
   cmake --preset cross-arm64-pi4   # si se cross-compila desde x86
   cmake --build build/arm64-pi4
   # o en la propia RPi4:
   cmake --preset release-x86  # ajustar preset para ARM64 nativo
   cmake --build build/release
   ```

   Más simple: `./nexo-reader.sh --build` compila automáticamente.

4. **Configuración**: copiar `config.example.json` a `config.json` y editar:

   ```json
   {
     "api_url": "https://nexo-80go.onrender.com",
     "device_id": "<UUID v4 desde la WebApp>",
     "device_token": "<token generado al registrar el dispositivo>",
     "biometric_sensor": "uareu5300",
     "mqtt_host": "<host del backend>",
     "mqtt_port": 8883,
     "mqtt_use_tls": true
   }
   ```

5. **Provisioning de claves**: colocar `/boot/nexo_provision.json` con `{ "aes_key", "api_token", "device_id" }` en la MicroSD antes del primer boot, o usar el modo interactivo (TTY).

6. **systemd**:

   ```bash
   sudo cp nexo-edge.service /etc/systemd/system/
   sudo systemctl enable nexo-edge
   sudo systemctl start nexo-edge
   ```

7. **udev rules** (para permisos USB del sensor):

   ```bash
   ./nexo-reader.sh --setup
   ```

### Registro del dispositivo

Desde la WebApp (`/dispositivos`), un RECTOR/COORDINATOR registra el dispositivo:

1. `POST /devices` con `{ name, location, group_id, assigned_user_id }`.
2. La API genera un `device_token` (bcrypt en `edge_devices.token_hash`).
3. El `device_id` (UUID v4) y `device_token` se copian a `config.json` del edge.

### Docker (alternativo)

`Dockerfile.edge` construye una imagen ARM64 multi-stage. Útil para testing en CI, no recomendado para producción en RPi4 (overhead de Docker).

## CI/CD

`.github/workflows/nexo-ci-cd.yml` define un único workflow con jobs condicionales por path:

### Triggers

- Push a `main` o `develop`.
- Pull request a `main` o `develop`.

### Jobs

| Job | Condición | Qué hace |
|---|---|---|
| `webapp-lint` | cambios en `frontend/pwa/**` | eslint |
| `webapp-test` | cambios en `frontend/pwa/**` | vitest |
| `webapp-build` | cambios en `frontend/pwa/**` | vite build |
| `webapp-deploy` | push a `main` + cambios en `frontend/pwa/**` | deploy a Vercel |
| `backend-lint` | cambios en `backend/**` | php -l |
| `backend-test-sql` | cambios en `backend/**` o `sql/**` | SQL Schema Tests |
| `backend-test-api` | cambios en `backend/**` | API Unit Tests |
| `backend-test-runners` | cambios en `backend/**` o `sql/**` | Runner Tests (con PostgreSQL service) |
| `backend-build` | cambios en `backend/**` | docker build |
| `backend-deploy` | push a `main` + cambios en `backend/**` | deploy a Render |
| `landing-build` | cambios en `frontend/frontend/landing/**` | vite build |
| `landing-deploy` | push a `main` + cambios en `frontend/frontend/landing/**` | deploy a Vercel |
| `edge-test` | cambios en `backend/edge/**` | CTest (x86) |
| `edge-build` | cambios en `backend/edge/**` | CMake release (x86 + cross-compile ARM64) |

### Secretos de GitHub

- `VERCEL_TOKEN`, `VERCEL_ORG_ID`, `VERCEL_PROJECT_ID_PWA`, `VERCEL_PROJECT_ID_LANDING` — deploy Vercel.
- `RENDER_DEPLOY_HOOK_URL` — deploy Render.
- `PGPASSWORD` — para tests con PostgreSQL service (si no usa `DATABASE_URL`).

## Onboarding de una institución nueva

1. **Crear la institución**: insertar en `schools` (o via seed). El admin inicial (`admin@nexo.edu`) se asigna a esta escuela.
2. **Login**: el rector entra a la PWA con sus credenciales.
3. **Onboarding de horarios**: `/onboarding` → configurar turnos, bloques horarios, calendario lectivo. Marca `schools.onboarding_completed = TRUE`.
4. **Onboarding de grupos**: crear `academic_groups`, asignar docentes (`teacher_group_access`), asignar estudiantes.
5. **Onboarding de riesgo**: configurar política de riesgo (o usar la default vía `fn_seed_default_risk_policy`). Marca `schools.risk_config_completed = TRUE`.
6. **Sensor master key**: setear `schools.sensor_master_key_hash` para habilitar revocación de sensores.
7. **Registrar dispositivos edge**: desde `/dispositivos`, registrar cada sensor y asignarlo a un grupo.
8. **Provisionar edge**: copiar `device_id` + `device_token` + clave AES a cada Raspberry Pi.
9. **Enrolar estudiantes**: desde el edge (modo secretaria) o desde la PWA, enrolar las huellas.

## Troubleshooting de despliegue

- **Backend no arranca**: revisar logs del contenedor en Render. Común: `DATABASE_URL` malformada, extensiones PG faltantes, `NEXO_HMAC_SECRET` no seteado (las funciones abortan).
- **PWA no carga**: verificar `VITE_API_BASE_URL` en Vercel, CORS en el backend (`CORS_ALLOW_ORIGINS` debe incluir la URL de Vercel).
- **Edge no sincroniza**: verificar `api_url`, `device_token`, conectividad de red, y que el dispositivo esté registrado y activo en `edge_devices`.
- **Workers caídos**: `GET /health/workers` muestra el estado. Común: Redis no disponible (los workers daemon reintentan con backoff).
- **Particiones no se crean**: verificar que `create_monthly_partition.sh` corre en el crontab de supercronic. Si falla, ejecutar `SELECT fn_ensure_partitions(6)` manualmente.
