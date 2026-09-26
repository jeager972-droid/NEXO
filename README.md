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
        │   OLED/GPIO · MQTT       │ lib/     lógica dominio   │      │
        │   OTA · watchdog         │  └─ nexus_*  IA convers.  │      │
        │          │               │ workers/ 11 daemons       │      │
        │          │ AES-256-GCM   │ core/    db/redis/mqtt    │      │
        │          └──────────────►└─────┬─────────────┬───────┘      │
        │                                │             │              │
        │                          PgBouncer:6432   Redis             │
        │                                │          (colas, dedup,    │
        │                                ▼           cache, rate-lim) │
        │                     PostgreSQL 15 · RLS                    │
        │                     sql/schema.sql — 68 tablas             │
        │                     multi-tenant por school_id             │
        └────────────────────────────────────────────────────────────┘
```

Cada componente tiene documentación exhaustiva en su propio README:

| Componente | Directorio | Documentación |
|---|---|---|
| **API / Backend PHP** | `backend/api/` | [backend/api/README.md](backend/api/README.md) |
| **Nodo edge biométrico** | `backend/edge/` | [backend/edge/README.md](backend/edge/README.md) |
| **IA conversacional (Nexus)** | `backend/api/lib/nexus_*` + `routes/chat.php` | [docs/nexus/NEXUS.md](docs/nexus/NEXUS.md) |
| **PWA (app principal)** | `frontend/pwa/` | [frontend/pwa/README.md](frontend/pwa/README.md) |
| **Landing** | `frontend/landing/` | [frontend/landing/README.md](frontend/landing/README.md) |
| **Frontend global + diseño** | `frontend/` | [frontend/README.md](frontend/README.md) · [design-philosophy/](frontend/design-philosophy/) |
| **Base de datos** | `sql/` | [sql/README.md](sql/README.md) |
| **Pruebas (suites + stack)** | `test/` · `pruebas/` | [test/README.md](test/README.md) · [pruebas/README.md](pruebas/README.md) |
| **Despliegue** | — | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| **Seguridad** | transversal | [docs/SECURITY.md](docs/SECURITY.md) |
| **Utilidades de repo** | `tools/` | repomix (empaquetado del código) |

## Qué hace cada pieza

- **`backend/edge/`** — Daemon C++20 en cada punto de control físico
  (Raspberry Pi embebido). Captura huellas, identifica estudiantes
  *localmente* (los templates biométricos nunca salen del dispositivo),
  encola eventos en SQLite y los sincroniza cifrados (AES-256-GCM) con la
  API. Resiste cortes de red/energía; recibe comandos por MQTT y se
  actualiza por OTA con rollback.

- **`backend/api/`** — API PHP 8 (nginx + php-fpm). Entry único `api.php`
  con dos canales: REST+JWT para la PWA y canal cifrado para el edge.
  25 archivos de rutas, motor de riesgo V3, 11 workers daemon (Twilio,
  auditoría, biométricos, ausencias, evasiones, permisos, salud de nodos),
  Redis para colas/dedup/rate-limit.

- **Nexus** (`docs/nexus/NEXUS.md`) — El chat institucional. El LLM
  interpreta lenguaje natural a intents+entidades (jamás toca datos ni
  decide permisos); capas deterministas (DSM → frame semántico SCP →
  planner → RBAC → executors SQL read-only → validación de resultado)
  garantizan que toda respuesta sale de datos verificados del colegio.
  Soporta referencias («el primero», «su acudiente», «todos»), correcciones,
  multi-goal y navegación de resultados, con memoria de conversación en el
  servidor.

- **`sql/`** — PostgreSQL 15, multi-tenant por `school_id` con RLS.
  `schema.sql` consolidado e idempotente; despliegue con
  `sql/deploy_db.sh` (`DATABASE_URL=... ./sql/deploy_db.sh`).

- **`frontend/`** — La PWA institucional (React 18 + Vite + Tailwind, los
  7 roles), la landing pública, la filosofía de diseño completa
  (`design-philosophy/` = fuente de verdad UX) y prototipos HTML.

## Verificación rápida (local, sin cuota LLM)

```bash
php test/dsm_units.php
php test/nexus_capability_eval_v1.php
php test/real_conversation_v1.php
php test/readonly_guard.php
php test/resilience.php
backend/api/vendor/bin/phpunit --configuration test/phpunit.xml \
    --testsuite 'API Unit Tests' --do-not-cache-result
cd frontend/pwa && npm test        # Vitest
```

Las suites sirven intents del snapshot `test/fixtures/llm_intents.json`.
Las suites *live* (`continuity_50`, `scp_live`, `golden_live`,
`heldout_live`, `live_probe*`) requieren el stack Docker de `pruebas/` y
gastan cuota del parser LLM — ver [test/README.md](test/README.md).

## Convenciones del repo

- Reglas para agentes y verificación local: [AGENTS.md](AGENTS.md).
- Documento técnico-narrativo histórico del sistema:
  [docs/documento_final.txt](docs/documento_final.txt).
- `_cuarentena/` contiene material retirado pendiente de veredicto de
  borrado — **no es fuente de verdad**.
- Commits pequeños con pruebas; no se hace push sin autorización.
