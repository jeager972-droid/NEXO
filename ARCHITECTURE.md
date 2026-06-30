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
                         │            (un solo remoto, dos vistas)    │
                         └───────────────┬──────────────┬─────────────┘
                                         │              │
                ┌────────────────────────▼──┐        ┌──▼─────────────────────────────┐
                │ WebApp/                    │        │ backend/alojamiento/           │
                │ Frontend React + espejo de │        │ Backend PHP productivo + Docker│
                │ backend PHP (idéntico)     │        │ (Railway)                      │
                └────────────────────────────┘        └────────────────────────────────┘

Carpetas físicas:
  WebApp/                            ← React/Vite/PWA + ESPEJO de backend (no autoritativo)
  backend/alojamiento/               ← Backend PHP autoritativo (Dockerfile, .htaccess, start.sh)
  backend/edge/                      ← Edge Raspberry Pi (C++)
```

### 2.1 Flujo de datos actual

```
[ESP32 / Edge]                [WebApp/api.php   ≡   alojamiento/api.php]
   biometría                       ├── decryptPayload(AES-256-GCM)
   sqlite local         ─────────► ├── ❌ NO INSERT en PostgreSQL  (R1)
   AES-256-GCM                     └── responde 200 OK falso
                                                    │
                                                    ▼
                                            PostgreSQL (Railway)
                                                    ▲
                                                    │
[Frontend React] ── axios ──► /v1/* (rutas autenticadas con JWT)
```

### 2.2 Problemas estructurales heredados

- Backend duplicado byte-a-byte (ver `STAGE_0_AUDIT.md` §2).
- Edge sigue siendo Arduino/ESP32, no Raspberry Pi/Linux.
- Ingestión cloud descarta datos (`R1`).
- Concurrencia bloqueante (`R3`), rate limit no horizontal (`R4`), auditoría mutable (`R5`).

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
| 1     | Edge: Arduino/ESP32 → Raspberry Pi 4 / Linux  | Parcial    | Hardware no producción-ready |
| 2     | Cimientos: ingesta, SQL safe, async, Redis    | Parcial    | Pérdida silenciosa de datos |
| 3     | SAT, store-and-forward, repo limpio           | Sí         | No hay valor diferencial |
| 4     | Multiplataforma (Tauri / Capacitor / PWA L3)  | Sí         | Comisiones 30% en stores |

> El detalle de cada etapa vive en `STAGE_<N>_PLAN.md` (se crean al iniciar cada etapa).
