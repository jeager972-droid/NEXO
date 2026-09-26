# Tener en cuenta

## Redis: estado actual y fallback PostgreSQL

### Situación actual (2026-08-20)

Redis **no está en uso**. El servicio de Redis (Upstash) configurado en Render está caído con error `AUTH failed while reconnecting`. El sistema funciona **sin Redis** gracias a un fallback en PostgreSQL.

### Qué es Redis y para qué se usaba

Redis es un almacén in-memory clave-valor que se usaba en NEXO para:

1. **Cola de comandos a dispositivos edge** — `ENROLL_REQUEST`, `AUTHORIZE_EXIT`, `DELETE_STUDENT` se encolaban en `device:{id}:commands` para que el edge los recogiera via polling HTTP.
2. **Cache del dashboard** — `dashboard:stats:{schoolId}:{role}:{group}` con TTL 30s para evitar queries repetidas.
3. **Blocklist de JWT** — `jwt:blocklist:{jti}` para invalidar tokens antes de su expiración.
4. **Modo pánico** — `panic:school:{id}` para verificar rápido si una escuela está en pánico.
5. **Rate limiting** — limitar peticiones en endpoints sensibles (ej. `/contacto`).
6. **Colas de workers** — `queue:twilio`, `queue:biometric_ingest` para procesamiento asíncrono.
7. **Conversaciones de WhatsApp** — `conversation:{phone}` con TTL 48h para contexto de chat.

### Cómo funciona el fallback PostgreSQL

Cuando Redis no está disponible, el sistema usa la tabla `device_commands` en PostgreSQL como reemplazo para la **cola de comandos a dispositivos edge**.

#### Flujo con Redis (preferido, cuando funciona):

```
WebApp → POST /devices/command/{id} → Redis lPush("device:{id}:commands")
                                              ↓
Edge polling GET /devices/commands → Redis rPop("device:{id}:commands") → ejecuta comando
```

#### Flujo con fallback PostgreSQL (actual, sin Redis):

```
WebApp → POST /devices/command/{id} → Redis falla → INSERT INTO device_commands
                                                                    ↓
Edge polling GET /devices/commands → Redis falla/vacío → SELECT FROM device_commands
                                                          WHERE delivered_at IS NULL
                                                          → marca delivered_at = NOW()
                                                          → ejecuta comando
```

#### Diferencias clave:

| Aspecto | Redis | Fallback PostgreSQL |
|---|---|---|
| Latencia | ~0.1-1ms | ~5-50ms |
| Persistencia | In-memory (se pierde al reiniciar) | Disco (duradero) |
| Entrega | Push (instantáneo con MQTT) o polling | Polling cada 30s |
| Límite de comandos | Ilimitado (RAM) | Limitado por disco |
| Dependencias | Servicio externo (Upstash) | Misma BD que todo el sistema |

### Qué NO funciona sin Redis

El fallback PostgreSQL **solo cubre la cola de comandos a dispositivos edge**. Estas funciones siguen sin funcionar mientras Redis esté caído:

- **Cache del dashboard** — cada carga del dashboard hace queries completas a la BD (más lento pero funcional).
- **Blocklist de JWT** — los tokens revocados no se verifican contra Redis. `isJwtRevoked()` retorna `false` (fail-open). Esto significa que un token revotado manualmente podría seguir siendo válido hasta su expiración natural.
- **Rate limiting de `/contacto`** — el endpoint retorna 503 (fail-closed) si Redis no está. No se pueden enviar solicitudes de contacto.
- **Colas de Twilio** — los envíos de WhatsApp se encolan en Redis. Sin Redis, los mensajes no se procesan.
- **Modo pánico** — `isSchoolInPanicMode()` retorna `false` (fail-open). El modo pánico no se verifica en tiempo real.

### Qué hacer cuando quieras volver a usar Redis

#### 1. Verificar/arreglar las credenciales de Redis

El error actual es `AUTH failed while reconnecting`. Posibles causas:

- **Contraseña cambiada o expirada** — Upstash puede haber rotado el token. Ve al dashboard de Upstash y copia la URL de conexión actual.
- **URL mal formada** — verifica que `REDIS_URL` en Render tenga el formato correcto: `rediss://default:TOKEN@HOST:PORT` (nota la doble `s` en `rediss` para TLS).
- **Plan gratuito expirado** — Upstash free tier tiene límites. Verifica que la cuenta siga activa.

#### 2. Configurar la variable en Render

Ve a Render → servicio del backend → Environment → `REDIS_URL`:

```
REDIS_URL=rediss://default:xxxxx@xxxxx.upstash.io:6379
```

También puedes usar variables individuales:

```
REDISHOST=xxxxx.upstash.io
REDISPORT=6379
REDIS_PASSWORD=xxxxx
REDIS_TLS=true
```

#### 3. Verificar que Redis responde

```bash
# Test directo con redis-cli
redis-cli -u "rediss://default:TOKEN@HOST:PORT" ping
# Debe responder: PONG
```

O verificar via el health check del backend:

```bash
curl https://nexo-80go.onrender.com/api.php/health
# "redis": { "status": "healthy" }
```

#### 4. Hacer deploy en Render

Después de actualizar la variable de entorno, Render hace deploy automáticamente. Si no, trigger un Manual Deploy.

#### 5. El sistema usa Redis automáticamente

No hay que cambiar código. El backend ya tiene la lógica:

- **POST /devices/command/{id}**: intenta Redis primero. Si Redis responde, usa Redis. Si no, usa PG.
- **GET /devices/commands**: intenta Redis primero. Si Redis tiene comandos, los entrega. Si está vacío o caído, lee de PG.

Cuando Redis vuelva, los comandos nuevos irán a Redis y los viejos en PG se entregarán normalmente.

### Migración SQL aplicada

La migración `2026-34-device-commands-pg-fallback.sql` crea la tabla:

```sql
CREATE TABLE IF NOT EXISTS device_commands (
    command_id    BIGSERIAL PRIMARY KEY,
    device_id     UUID NOT NULL REFERENCES edge_devices(device_id) ON DELETE CASCADE,
    command       TEXT NOT NULL,
    payload       JSONB NOT NULL DEFAULT '{}'::jsonb,
    issued_at     BIGINT NOT NULL,
    issued_by     UUID,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    delivered_at  TIMESTAMPTZ
);
```

### Archivos relevantes

- `backend/api/redis.php` — conexión a Redis (singleton, fail-open retorna null)
- `backend/api/routes/devices.php` — enqueue (Redis → PG fallback) y polling (Redis → PG fallback)
- `backend/api/sql/2026-34-device-commands-pg-fallback.sql` — migración que crea la tabla
- `backend/edge/src/main.cpp` — CommandWorker con cola para procesar ENROLL_REQUEST via HTTP polling
