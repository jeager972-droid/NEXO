# WORKERS.md — Workers en background de NEXO

Documentación de los workers PHP que procesan colas y tareas periódicas. Basada en `backend/api/workers/` y `backend/api/docker-entrypoint.sh`.

## Modelo general

NEXO usa workers PHP CLI de larga duración y workers periódicos (cron). Todos se lanzan desde `docker-entrypoint.sh` del contenedor de la API. Hay dos patrones:

1. **Daemon-loop**: proceso PHP que corre indefinidamente, hace `brpop`/`blpop` de una lista Redis o polling PG, procesa el item, y vuelve a esperar. Reiniciados por el supervisor del contenedor si mueren.
2. **Periodic (cron-mode)**: proceso PHP que corre una vez, hace su trabajo, y termina. Programado por `supercronic` con crontabs del contenedor.

## Redis como broker

Colas implementadas como listas Redis (`LPUSH`/`BRPOP`):

| Cola | Worker | Items |
|---|---|---|
| `queue:biometric_ingest` | `biometric_ingest_worker.php` | Eventos biométricos del edge |
| `queue:audit_logs` | `audit_worker.php` | Logs de auditoría |
| `queue:notifications` | `notification_worker.php` | Notificaciones in-app + WhatsApp |
| `queue:twilio_send` | `twilio_worker.php` | Envíos WhatsApp salientes |
| `queue:webhook_delivery` | `webhook_worker.php` | Webhooks salientes |

Cada worker hace `BRPOP` con timeout de 30s. Si no hay items, duerme y reintenta. Heartbeat en `worker:<name>:last_heartbeat` (Redis, TTL 90s) para que `/health/workers` los monitoree.

### Fallback sin Redis

Si Redis no está disponible, los workers que pueden degradan a polling directo de PostgreSQL (`device_commands`, tablas de staging) o procesan inline en la petición HTTP. El estado de Redis se documenta en `tener_en_cuenta.md` (actualmente con fallback activo). Los workers que no tienen fallback PG simplemente reintentan la conexión a Redis con backoff.

## Workers daemon-loop

### biometric_ingest_worker.php

Procesa eventos biométricos encolados por la API tras recibir un `SYNC_ATTENDANCE` del edge.

- **Input**: `queue:biometric_ingest` (JSON con `event_id`, `student_id`, `school_id`, `event_type`, `timestamp`).
- **Lógica**:
  - Deduplica eventos dentro de una ventana configurable (`BIOMETRIC_DEDUP_WINDOW_SECONDS`, default 300s) usando Redis `biometric_dedup:{school_id}:{student_id}` con NX + TTL. Si Redis caído, fail-open (la API ya dedup con ON CONFLICT en PG).
  - Determina el tipo de incidente: `INGRESO_TEMPRANO`, `INGRESO_TARDIO` → `LATE_ARRIVAL` si llega después del inicio de clase; `INGRESO_RETORNO` si vuelve de un permiso; `SALIDA_BAÑO` si hay `class_exit_authorizations` activa; `EVASION_INTERNA` si sale sin permiso.
  - Inserta `attendance_incidents` (dispara trigger `trg_evaluate_risk_v3`).
  - Cierra `class_exit_authorizations` activas si corresponde.
  - Notifica al acudiente por WhatsApp si es `LATE_ARRIVAL` o `EVASION_INTERNA` (encola en `queue:twilio_send`).
- **GC**: purga la clave de dedup cada hora.
- **Fallback PG**: si Redis cae, los eventos ya se insertaron en `biometric_events` por la API (fast path); el worker solo procesa los que llegan a la cola. Si la cola no está disponible, los incidentes se calculan en la siguiente ejecución del `absence_detector`.

### audit_worker.php

Procesa logs de auditoría encolados por `securityLog()` cuando `AUDIT_WORKER_ENABLED=1`.

- **Input**: `queue:audit_logs` (JSON con `school_id`, `actor_id`, `event_type`, `description`, `ip`, `created_at`).
- **Lógica**: inserta en `global_audit_logs`. El trigger `trg_audit_chain` calcula `prev_audit_id` y `chain_hash` (HMAC con `app.nexo_hmac_secret`).
- **Fallback**: si el worker no está habilitado o Redis cae, `securityLog()` escribe a stderr del contenedor (logs de Nginx/PHP-FPM) y no se persiste en la cadena HMAC.

### notification_worker.php

Procesa notificaciones in-app y encola envíos WhatsApp.

- **Input**: `queue:notifications` (JSON con `user_id`, `type`, `title`, `body`, `metadata`).
- **Lógica**: inserta en `notifications`, y si requiere WhatsApp, encola en `queue:twilio_send`.

### twilio_worker.php

Envía mensajes WhatsApp salientes vía la API de Twilio.

- **Input**: `queue:twilio_send` (JSON con `to`, `body`, `template_sid`, `variables`, `message_id`).
- **Lógica**:
  - Rate limiting por teléfono: `TWILIO_MAX_SENDS_PER_HOUR` y `TWILIO_MAX_DAILY_PER_PHONE` con Redis. Si excede, reencola con delay.
  - Llama a la API de Twilio (`POST https://api.twilio.com/.../Messages`).
  - Actualiza `twilio_messages.delivery_status` con el SID retornado.
  - Reintenta con backoff exponencial si Twilio responde 5xx o timeout.
- **Fallback**: si Redis cae, fail-open (sin rate limit por teléfono, solo el global de Twilio).

### webhook_worker.php

Entrega webhooks salientes a integraciones externas (si están configuradas).

## Workers periódicos (supercronic)

Configurados en `backend/api/infra/crontab` y ejecutados por `supercronic` dentro del contenedor.

### absence_detector (modo daemon o cron)

Detecta inasistencias: estudiantes que no tuvieron `INGRESO_*` antes de una hora configurable y no tienen permiso activo.

- **Modo**: `ABSENCE_DETECTOR_MODE` (`daemon` o `cron`). En `daemon`, corre como loop con `ABSENCE_CHECK_INTERVAL` (default 60s). En `cron`, se ejecuta cada minuto.
- **Lógica**:
  - Para cada grupo con clase hoy, lista estudiantes activos.
  - Verifica `is_student_present_today()` y `has_active_permiso()`.
  - Si no está presente y no tiene permiso, inserta `attendance_incidents` (`INASISTENCIA`).
  - Notifica al acudiente por WhatsApp (encola en `queue:twilio_send`).
  - Dispara `fn_evaluate_student_risk` (vía el trigger de `attendance_incidents`).

### evasion_detector

Detecta evasión interna: estudiante que salió del aula (sin permiso) y no regresó en un tiempo configurable.

- **Modo**: `EVASION_DETECTOR_MODE` (`daemon` o `cron`). Intervalo `EVASION_CHECK_INTERVAL` (default 60s).
- **Lógica**:
  - Busca `SALIDA_*` sin `INGRESO_RETORNO` posterior dentro de la ventana.
  - Inserta `attendance_incidents` (`EVASION_INTERNA`).
  - Notifica al docente y coordinador.

### permission_status_worker

Cierra/expira permisos de salida (`class_exit_authorizations`) que se pasaron del tiempo permitido.

- **Modo**: `PERMISSION_STATUS_MODE` (`daemon` o `cron`). Intervalo `PERMISSION_CHECK_INTERVAL` (default 30s).
- **Lógica**:
  - Busca `class_exit_authorizations` con `status='ACTIVE'` y `expires_at < NOW()`.
  - Si el estudiante ya regresó (`INGRESO_RETORNO`), marca `COMPLETED`.
  - Si no regresó, marca `EXPIRED` y genera `EVASION_INTERNA`.

### create_monthly_partition

Crea la partición mensual siguiente para las 8 tablas particionadas.

- **Cron**: `0 3 1 * *` (primer día del mes, 3 AM UTC).
- **Script**: `backend/api/infra/scripts/create_monthly_partition.sh` → ejecuta `SELECT fn_ensure_partitions(1)`.
- **Idempotente**: si la partición ya existe, no hace nada.

### partition_retention

Dropea particiones antiguas.

- **Cron**: `0 4 1 * *` (primer día del mes, 4 AM UTC).
- **Script**: ejecuta `SELECT fn_drop_old_partitions(24)` (retención 24 meses, configurable vía `NEXO_PARTITION_RETENTION_MONTHS`).
- **Seguro**: nunca toca la partición `DEFAULT`.

### redis_gc

Limpia claves expiradas/zombies de Redis (dedup, rate limits, nonces).

- **Cron**: cada hora.

## Health monitoring

`GET /health/workers` (sin auth) verifica el estado de los workers:

- Lee `worker:<name>:last_heartbeat` de Redis para cada worker conocido.
- Si el heartbeat es más antiguo que 90s, el worker se marca como `down`.
- Si Redis no está disponible, todos los workers se marcan como `unknown`.
- Respuesta: `{"workers": {"biometric_ingest": "up", "absence_detector": "up", ...}, "redis": "up"}`.

## Variables de entorno

| Variable | Default | Worker afectado |
|---|---|---|
| `AUDIT_WORKER_ENABLED` | `1` | audit_worker |
| `ABSENCE_DETECTOR_MODE` | `daemon` | absence_detector |
| `ABSENCE_CHECK_INTERVAL` | `60` | absence_detector |
| `EVASION_DETECTOR_MODE` | `daemon` | evasion_detector |
| `EVASION_CHECK_INTERVAL` | `60` | evasion_detector |
| `PERMISSION_STATUS_MODE` | `daemon` | permission_status_worker |
| `PERMISSION_CHECK_INTERVAL` | `30` | permission_status_worker |
| `BIOMETRIC_DEDUP_WINDOW_SECONDS` | `300` | biometric_ingest_worker |
| `BIOMETRIC_GC_MAX_AGE` | `86400` | biometric_ingest_worker |
| `TWILIO_RATE_LIMIT` | `1` | twilio_worker |
| `TWILIO_MAX_SENDS_PER_HOUR` | `10` | twilio_worker |
| `TWILIO_MAX_DAILY_PER_PHONE` | `5` | twilio_worker |
| `NEXO_PARTITION_RETENTION_MONTHS` | `24` | partition_retention |

## docker-entrypoint.sh

El entrypoint del contenedor de la API:

1. Configura Nginx y PHP-FPM.
2. Inicia Mosquitto (broker MQTT en `127.0.0.1:1883`).
3. Lanza los workers daemon-loop en background (con `nohup` o via supervisor).
4. Registra los crontabs en `supercronic`.
5. Inicia PHP-FPM + Nginx en foreground.

Los workers daemon se reinician automáticamente si mueren (loop `while true; do php worker.php; sleep 5; done` o supervisor). Los workers periódicos los gestiona `supercronic` con logs a stdout del contenedor.

## Tests

`test/runners/` contiene tests de integración que validan el comportamiento de los workers con datos reales en PostgreSQL:

- `AbsenceDetectorTest.php` — inasistencias detectadas correctamente.
- `EvasionDetectorTest.php` — evasión interna detectada.
- `PermissionStatusTest.php` — permisos expirados cerrados.
- `BiometricIngestTest.php` — eventos procesados, incidentes generados.
- `RiskRecalculationTest.php` — recálculo de riesgo masivo.
- `TwilioWorkerTest.php` — envíos encolados y rate limit.

Ejecución:

```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "Runner Tests"
```

Más detalle en [TESTING.md](TESTING.md).
