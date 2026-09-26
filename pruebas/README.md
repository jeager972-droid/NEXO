# `pruebas/` — Entorno integral de pruebas NEXO

## Qué es

Levanta el **sistema backend real completo** en Docker — idéntico a producción:

```
PostgreSQL 15 → PgBouncer → API PHP (nginx+php-fpm)
                             ├─ worker_twilio      (daemon)
                             ├─ worker_audit       (daemon)
                             ├─ worker_biometric   (daemon)
                             ├─ worker_absence     (daemon)
                             ├─ worker_evasion     (daemon)
                             ├─ worker_permission  (daemon)
                             ├─ worker_device_health (daemon)   ← F-04
                             ├─ mosquitto MQTT    (dentro del api)
                             └─ supercronic       (crontab)
Redis (colas + dedup)
```

**Nada está simulado a nivel de código:** los workers, el API, la criptografía,
RLS, la cola Redis y PostgreSQL son los binarios reales. Solo el *mundo
exterior* se simula (módem, UPS, huella, tiempo) — igual que haría el hardware.

## Archivos

| Archivo | Propósito |
|---|---|
| `docker-compose.test.yml` | Stack completo idéntico a prod + schema auto-aplicado |
| `env.test` | Credenciales de prueba (JWT keys generadas, AES de prueba) |
| `seed.sql` | Datos semilla deterministas: 1 escuela, 1 grupo, 3 estudiantes, 1 coordinador, 1 docente, 1 acudiente, 1 dispositivo edge |
| `seed_chat_fixture.sql` | Fixture extendido para Nexus/chat: 10-A con 6 estudiantes (incl. Tomás Castaño Gutiérrez + acudiente), 10-B, docente con acceso a 3 grupos, umbrales de riesgo, 8-C sin incidentes |
| `nodo/` | Panel web de pruebas del nodo (`panel_server.py` + `index.html`) — forzado manual de acciones del edge |
| `runner.py` | CLI maestro: levanta el stack, corre escenarios, fuerza acciones, estrés |

## Uso

```bash
./runner.py up          # levanta todo (build la 1ª vez)
./runner.py verify      # sanidad: health, seed, workers vivos
./runner.py all         # corre los 11 escenarios, informe final
./runner.py menu        # consola interactiva — forzar cualquier acción
./runner.py stress 500 40   # estrés: 500 eventos a 40/s
./runner.py down        # baja
./runner.py reset       # baja + borra volúmenes (DB limpia)
```

## Escenarios disponibles (`runner.py all`)

| # | Escenario | Qué verifica (código real) |
|---|---|---|
| 1 | `ping` | F-06: telemetría → `edge_devices.telemetry_json` |
| 2 | `power` | F-09: BATTERY/CRITICAL → incidentes + notificación HIGH |
| 3 | `dedup` | Mismo incidente hoy → no duplica |
| 4 | `signal` | F-10: interfaz celular caída → SENAL_PERDIDA |
| 5 | `dlq` | F-13: dlq_count alto → DLQ_BACKLOG |
| 6 | `ingest` | SYNC_ATTENDANCE AES-GCM real → `biometric_events` |
| 7 | `register` | REGISTER_STUDENT con huella → `biometric_hash` |
| 8 | `absence` | Worker real detecta INASISTENCIA al pasar la hora límite |
| 9 | `manual` | F-02: registro manual por coordinador (JWT real) |
| 10 | `offline` | F-04: sin ping → SIN_DATOS_NODO (workers blindados) |
| 11 | `resilience` | Redis caído → ingest sigue (PG inline) → recuperación |
| 12 | `onboarding` | Bloque B: operación sin onboarding → 428 (genérico/detallado/normal) |
| 13 | `teacher` | Bloque B: regla de aviso docente → worker notifica → onboarding docente |
| 14 | `ota` | Bloque D: publicar → manifiesto firmado → verificación nodo → APPLIED → anti-rollback |

## Variables de entorno nuevas (Bloques C/D)

| Var | Default | Qué controla |
|---|---|---|
| `ABSENCE_FOLLOWUP_INTERVAL` | 300s | Cadencia del worker de seguimiento |
| `ABSENCE_FOLLOWUP_MINUTES` | 60 | Min tras detección para reintentar al acudiente |
| `ABSENCE_FOLLOWUP_MAX` | 2 | Recordatorios máximos antes de escalar |
| `ABSENCE_ESCALATE_MINUTES` | 240 | Min para escalar a `ABSENCE_NO_REPLY` |
| `TWILIO_SMS_FROM` | — | Número SMS-capable → fallback SMS si WhatsApp falla |
| `ota_check_interval_s` (edge) | 1800 | Cadencia del chequeo OTA del nodo |

## Qué le falta a este entorno (pendiente)

- **Escenarios edge-físico:** enrolamiento con sensor real, apagón abrupto del
  contenedor (`docker kill`) vs `stop` (SIGTERM vs SIGKILL — graceful vs no).
- **Stress de workers:** cola Redis con miles de mensajes acumulados.
- **Carga concurrente:** N dispositivos simultáneos (hoy 1 edge).
- **Onboarding e2e:** escenario que complete onboarding real por API.
- **PWA:** este entorno es 100% backend — el frontend (`frontend/pwa`) no
  tiene escenarios e2e aquí.
- **Twilio real:** el worker corre pero con credenciales dummy; valida la
  cola, no el envío.

## Datos de prueba (seed)

| Entidad | Valor |
|---|---|
| Escuela | IE Test NEXO (`22222222-…`) |
| Grupo | 6-A mañana, 3 estudiantes (8001, 8002, 8003-exenta) |
| Coordinador | `coord@test.nexo` / `test1234` |
| Docente | `teach@test.nexo` / `test1234` |
| Acudiente | `guard@test.nexo` / `test1234` |
| Dispositivo | `44444444-…` token `nexo-test-device-token` |
| AES | `0123456789…` (hex 64) |

## Regla de honestidad

El runner **nunca** inserta resultados en la BD para "hacer pasar" un test.
Verifica el estado real persistido. Si el sistema no es resiliente, el
escenario falla — esa es la señal que buscamos, no un verde falso.
