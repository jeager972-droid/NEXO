# NEXO — Sistema Inteligente de Gestión Escolar

NEXO es una plataforma de **custodia educativa en tiempo real** para
instituciones escolares colombianas: control de acceso biométrico en los
puntos de entrada/salida, trazabilidad estudiantil, detección de situaciones
(ausencias, tardanzas, evasiones, permanencias prolongadas en baño),
notificaciones automáticas a familias por WhatsApp/SMS, salidas autorizadas,
citas, seguimientos — y **Nexus**, el asistente conversacional que permite
consultar todo el sistema en lenguaje natural.

> NEXO no muestra datos: detecta situaciones y propone acciones.

## Arquitectura del sistema

```
                        ┌─────────────────────────────────────────────┐
                        │                  FRONTEND                    │
                        │  frontend/pwa/      SPA instalable — app     │
                        │                   principal de los 7 roles   │
                        │  frontend/landing/  sitio público/marketing  │
                        └──────────────┬──────────────────────────────┘
                                       │ HTTPS REST + JWT
        ┌──────────────────────────────┼──────────────────────────────┐
        │                              ▼                              │
        │   NODO EDGE (colegio)    backend/api/  — API PHP 8          │
        │   backend/edge/          ┌───────────────────────────┐      │
        │   C++20 · SQLite local   │ api.php (entry único)     │      │
        │   huella (UareU/ZK9500)  │ routes/  25 archivos      │      │
        │   OLED/GPIO · MQTT       │ nexus/   IA conversacional│      │
        │   OTA · watchdog         │ lib/     lógica dominio   │      │
        │          │               │ workers/ 11 daemons       │      │
        │          │               │ core/    db/redis/mqtt    │      │
        │          │ AES-256-GCM   └─────┬─────────────┬───────┘      │
        │          └──────────────►      │             │              │
        │                          PgBouncer:6432   Redis             │
        │                                │          (colas, dedup,    │
        │                                ▼           cache, rate-lim) │
        │                     PostgreSQL 15 · RLS                    │
        │                     sql/schema.sql — 68 tablas             │
        │                     multi-tenant por school_id             │
        └────────────────────────────────────────────────────────────┘
```

## Componentes

Cada componente tiene documentación exhaustiva en su propio README
(escrito contra el código vigente, no contra reportes históricos):

| Componente | Directorio | Líneas doc | Documentación |
|---|---|---|---|
| **IA conversacional (Nexus)** | `backend/api/nexus/` + `routes/chat.php` | ~1.020 | [backend/api/nexus/README.md](backend/api/nexus/README.md) |
| **API / Backend PHP** | `backend/api/` | ~800 | [backend/api/README.md](backend/api/README.md) |
| **Nodo edge biométrico** | `backend/edge/` | ~684 | [backend/edge/README.md](backend/edge/README.md) |
| **Base de datos** | `sql/` | ~783 | [sql/README.md](sql/README.md) |
| **PWA (app principal)** | `frontend/pwa/` | ~427 | [frontend/pwa/README.md](frontend/pwa/README.md) |
| **Landing** | `frontend/landing/` | ~241 | [frontend/landing/README.md](frontend/landing/README.md) |
| **Frontend global + diseño** | `frontend/` | ~118 | [frontend/README.md](frontend/README.md) · [design-philosophy/](frontend/design-philosophy/) |
| **Pruebas (suites + stack)** | `test/` · `pruebas/` | ~360 | [test/README.md](test/README.md) · [pruebas/README.md](pruebas/README.md) |
| **Despliegue** | `docs/DEPLOYMENT.md` | — | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| **Seguridad** | transversal | — | [docs/SECURITY.md](docs/SECURITY.md) |
| **Utilidades de repo** | `tools/` | — | repomix (empaquetado del código para IA) |

## Qué hace cada pieza

### `backend/edge/` — captura en campo (C++20, Raspberry Pi)

Daemon que corre en cada punto de acceso del colegio (portería, aula).
Identifica estudiantes **localmente** por huella (los templates biométricos
nunca salen del dispositivo — a la nube solo viaja un `huella_id` entero),
persiste eventos en SQLite local y los sincroniza cifrados (AES-256-GCM) con
la API. **Offline-first**: sigue registrando asistencia sin red y reintenta.
Capa HAL que desacopla sensores reales (U.are.U 5300, ZK9500) de stubs de
desarrollo; OLED/GPIO por libgpiod; comandos remotos por MQTT; OTA firmado
con rollback automático; watchdog + supervisor de threads.

### `backend/api/` — núcleo (PHP 8, nginx + php-fpm)

Entry único `api.php` con dos canales: REST+JWT para la PWA y canal cifrado
para los nodos edge. 25 archivos de rutas, motor de riesgo V3, y **11
workers daemon** que hacen el trabajo asíncrono: envío Twilio (con
contingencia a BD), auditoría con cadena HMAC, enrolamiento biométrico,
detección de ausencias/evasiones, permisos, salud de nodos, recálculo de
métricas. Redis para colas, deduplicación, rate-limiting y cache — con
degradación honesta cuando no está disponible.

### `backend/api/nexus/` — Nexus, la IA conversacional

El chat institucional («Pregúntale a Nexus»). Arquitectura **LLM híbrido**:
el modelo interpreta lenguaje natural a intents+entidades — jamás escribe
SQL, decide permisos ni toca datos. Cuatro capas deterministas conservan la
autoridad: DSM (estado de diálogo, referencias «el primero»/«su acudiente»,
correcciones, multi-goal), frame semántico SCP, planner + registro de 39
capacidades, RBAC y executors SQL **read-only**, y validación del resultado.
Sin clave LLM o con el proveedor caído, el sistema degrada a
`out_of_scope` honesto + caminos deterministas — nunca inventa.

> Regla rectora: el LLM interpreta y expresa; NEXO decide la verdad.

### `sql/` — PostgreSQL 15 multi-tenant

68 tablas + particiones mensuales dinámicas, ~90 FKs, 23 funciones, RLS por
`school_id` en 53 tablas (los datos de un colegio son invisibles para otro,
incluso con un bug en la API). Acceso vía PgBouncer (`transaction` pooling →
el contexto RLS se fija por transacción, no por sesión). `schema.sql` es
autocontenido e idempotente; despliegue con `sql/deploy_db.sh`.

### `frontend/` — PWA, landing, diseño y prototipos

- **`pwa/`** — React 18 + Vite + Tailwind + PWA instalable. Los 7 roles
  (rector, coordinador, docente, secretaría, portero, auxiliar,
  psicoorientador) con rutas protegidas, chat Nexus, operaciones,
  notificaciones, enrolamiento. Spec de diseño: `design-philosophy/`.
- **`landing/`** — sitio público/marketing con formulario de contacto y
  consentimiento de datos.
- **`prototipos/`** — mockups HTML estáticos.
- **`design-philosophy/`** — fuente de verdad UX/DES: filosofía, tokens,
  patrones, guía de implementación.

## Flujo de datos de extremo a extremo

```
Dedo del estudiante
  → sensor edge (identificación 1:N local, en RAM)
  → SQLite local (audit_trail, WAL, retry)
  → HTTPS AES-256-GCM → api.php /ingest
  → PostgreSQL biometric_events (RLS school_id)
  → workers: detección tardanza/ausencia/evasión → notifications
  → worker_twilio: WhatsApp al acudiente
  → PWA: el rol consulta «¿quién llegó tarde hoy?» → Nexus → cards verificadas
```

## Seguridad (resumen)

- **Multi-tenant RLS** por `school_id` — defensa en profundidad a nivel BD.
- **Biometría**: templates cifrados AES-GCM solo en el edge; la nube ve IDs.
- **JWT + refresh** con blocklist y sesiones revocables; 2FA por OTP.
- **Edge**: llave maestra por escuela (bcrypt), token por dispositivo, OTA
  firmado HMAC, revocación con countdown.
- **Nexus**: el LLM no decide — whitelist de intents, RBAC por intent,
  compuerta `safety`, executors read-only, auditoría `CHAT_QUERY`.
- **Auditoría**: `global_audit_logs` con cadena HMAC-SHA256 inmutable.

Detalle completo: [docs/SECURITY.md](docs/SECURITY.md).

## Verificación local (sin cuota LLM)

```bash
php test/dsm_units.php
php test/nexus_capability_eval_v1.php
php test/real_conversation_v1.php
php test/readonly_guard.php
php test/resilience.php
backend/api/vendor/bin/phpunit --configuration test/phpunit.xml \
    --testsuite 'API Unit Tests' --do-not-cache-result
cd frontend/pwa && npm test        # Vitest (563 pruebas)
```

Las suites sirven intents del snapshot `test/fixtures/llm_intents.json` —
prueban el pipeline determinista completo menos la llamada al LLM. Las
suites *live* (`continuity_50`, `scp_live`, `golden_live`, `heldout_live`,
`live_probe*`, `blind_eval`, `op_eval`, `semantic_eval`) requieren el stack
Docker de `pruebas/` o API/BD reales y gastan cuota del parser — ver
[test/README.md](test/README.md) y AGENTS.md para cuándo correr cada una.

## Estructura del repo

```
├── backend/    api/ (PHP: rutas, nexus/, lib, workers) · edge/ (C++)
├── frontend/   pwa/ · landing/ · prototipos/ · design-philosophy/
├── docs/       DEPLOYMENT.md · SECURITY.md · documento_final.txt
├── sql/        schema.sql · seed.sql · factory_reset.sql · deploy_db.sh
├── test/       suites PHP + fixtures/ + simulaciones/ + api/ + runners/
├── pruebas/    stack Docker de integración (api+db+redis+nodo)
├── tools/      repomix (empaquetado del código)
└── _cuarentena/ material retirado pendiente de veredicto — no es fuente
```

## Convenciones

- Reglas para agentes y verificación local: [AGENTS.md](AGENTS.md).
- Documento técnico-narrativo histórico: [docs/documento_final.txt](docs/documento_final.txt).
- Commits pequeños con pruebas; no se hace push sin autorización.
- `_cuarentena/` está pendiente de tu veredicto de borrado — ver
  [_cuarentena/LEEME.md](_cuarentena/LEEME.md).
