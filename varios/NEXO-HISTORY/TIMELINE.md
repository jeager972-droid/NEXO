# TIMELINE — NEXO, de puente cifrado a plataforma institucional

Cronología de grandes acontecimientos. Cada entrada enlaza con evidencia
(hash de commit; repositorio entre corchetes: **R1**=`0nto/NEXO`,
**R3**=repo actual; R2 es prefijo de R3). Detalles en
`EVIDENCE_INDEX.md` y `MAJOR_DECISIONS.md`.

```
2026-04-12                                                    [R1]
   │
   ▼  GÉNESIS — "repo" (0a84b1a7)
   │  Tres archivos: api.php (puente AES-256, auditoría anonimizada),
   │  index.html galáctico, style.css. El mismo día, el MVP
   │  "Interoperabilidad por Excepción" (4aef080b) ya trae webhook
   │  Twilio con comandos !inasistencia / !rojo sobre MySQL/Railway.
   │
   ▼  04-13 — "finalizacion de la version 1.0" (6e5c3fb1)
   │  El puente ya opera: WhatsApp ↔ registro de eventos ↔ aviso a
   │  acudientes y coordinación.
   │
   ▼  04-15 → 04-20 — EL BOT CRECE (api.php: 267 → 1.486 líneas)
   │  04-18: hardening "certificación de ciberseguridad" (9435cf1c):
   │  .htaccess, AES-256-GCM, claves por entorno. Comandos con RBAC:
   │  !tarde !inasistencia !citacion !salida !permiso !excepcion
   │  !SOS !daño !lista !subsana.
   │
   ▼  (silencio 04-20 → 05-05)
   │
   ▼  05-05 → 05-08 — CONSOLIDACIÓN [R1]
   │  0nto se incorpora. 05-07: migración MySQL→PostgreSQL, extracción
   │  de db.php, api.php baja a 648 líneas (5d9b14d2). 05-08: API
   │  modular routes/ (9f771f6a), hardening SQL, flujo de citación
   │  Twilio (79cb2b2e); merge de la app React/Vite/PWA completa de
   │  0nto (20ebe789 → 5b6d2820). Nace la WebApp como parte de NEXO.
   │
   ┊   REFUNDACIÓN — sin continuidad Git, con continuidad de código
   │
   ▼  05-12 — MONOREPO [R3] (7c468b3)
   │  Un commit trae todo: WebApp (React+Vite+Tauri), "Logica de
   │  negocio" (API PHP v7.5 + edge C++ con HAL), ESP32/PlatformIO,
   │  docs de gestión, ARCHITECTURE.md con la arquitectura objetivo.
   │  05-13: backend re-materializado sin el submódulo fantasma
   │  (71de30ef); limpieza de legado ESP32 (e8231a5).
   │
   ▼  05-13 → 05-17 — LA SAGA DE RAILWAY (~40 commits)
   │  Dockerfile ↔ nginx ↔ php-fpm ↔ Apache ↔ MPM ↔ 502 ↔ PORT.
   │  05-17: servicio único definitivo — landing / + API /v1/ +
   │  WebApp /app/ (1cb9e0ef); nginx+fpm con Unix socket e IPv6.
   │
   ▼  05-17 → 06-01 — LANDING Y DISTRIBUCIÓN
   │  Rediseño "singapore govtech" (4c0db6e2), canvas 3D
   │  Three.js/GSAP, APK real de 60 MB (3247af43) → abandonado por
   │  PWA + InstallPage multiplataforma (05-24/25). Saga CORS.
   │
   ▼  05-31 → 06-09 — LA WEBAPP SE VUELVE OPERATIVA
   │  Auditoría con ~50 endpoints reales (18aeb985), consultas
   │  dinámicas PostgreSQL (1dcc8ee7), citaciones Twilio robustas,
   │  OTP WhatsApp + 2FA + alertas de login (1ad18781), fin del
   │  localStorage para datos de usuario (e222b75f), módulo de
   │  seguimiento estudiantil (de2df0de → 3ea392cf).
   │
   ▼  06-09 → 06-30 — MADURACIÓN Y SANEAMIENTO  [fin de R2: 304f1ec]
   │  −84.851 líneas de artefactos repomix; lib/twilio.php;
   │  JWT blocklist; tests iniciales (PanicButtonTest).
   │
   ▼  06-30 → 07-04 — CAMBIO DE MANOS (jhonedisonalvarez21-lab →
   │  jeager972-droid). Gran limpieza: Includes/.pio fuera,
   │  "alojamiento" → "api", landing/ a carpeta propia, edge CMake.
   │  Grind de despliegue con mensajes de frustración en el log.
   │
   ▼  07-20 → 07-22 — CANONIZACIÓN DE DATOS (bbf68c76)
   │  Consolidación de migraciones, deploy_db.sh, compatibilidad
   │  Supabase/PgBouncer, alineación de roles.
   │
   ▼  07-24 → 08-02 — REFACTOR WEBAPP + HARDWARE REAL
   │  UI_UX_PLAN; 07-27: SDK del vendor U.are.U 5300 y drivers reales
   │  entran al edge (edead085, +53.7k líneas) junto a ZK9500.
   │
   ▼  08-04 → 08-05 — LA SEMANA DE PGBOUNCER/RLS
   │  RLS se perdía bajo transaction pooling → SET LOCAL + BEGIN
   │  explícito (a77d7a95). En la misma ventana: detector de ausentes,
   │  jornada estudiantil, motor de riesgo, onboarding de horarios
   │  multi-jornada (215399cb → 4dbe82a1).
   │
   ▼  08-11 — "WebApp V1.0" (edcacbd8)
   │
   ▼  08-15 — "NEXO — RELEASE CANDIDATE" (43682b30)
   │  schema.sql canónico (55 tablas), merge con origin/main.
   │
   ▼  08-17 → 08-21 — LA FLOTA BIOMÉTRICA SE GESTIONA
   │  Onboarding de grupos+sensores, tokens por dispositivo,
   │  revocación, heartbeat, CommandWorker (fd4f9e34), RLS en
   │  edge_devices, enrolamiento de huella edge→API→PWA E2E
   │  (8688e903), justificación de inasistencia por WhatsApp.
   │
   ▼  08-20 → 08-24 — HARDENING SISTEMÁTICO
   │  Reorganización: WebApp→PWA, Tauri eliminado, sql/schema.sql
   │  único en raíz, suite test/ con PHPUnit+Vitest+CI (bb0165b2…).
   │  Motor de Riesgo Pedagógico v3.0 (ad79306e). Resiliencia:
   │  circuit breaker Redis, fallbacks a PostgreSQL, fix de
   │  agotamiento del pool (fcb10046).
   │
   ▼  09-15 → 09-19 — AUDITORÍA E INTEGRACIÓN PROFUNDA
   │  auditoria/: verificación de 652 ítems documento↔código.
   │  Commit de integración masiva (74dc1b8b): OTA, telemetría y
   │  salud de nodos, modelo espacial (aulas/horarios), conciliación
   │  de asistencia, alertas a docentes, simuladores, stack de
   │  pruebas Docker (pruebas/).
   │
   ▼  09-20 — NACE NEXUS (a6fd4696)
   │  "chatbot nlu cascada": servicio NLU Python (TF-IDF+LR),
   │  backend/nlu, routes/chat.php, cliente chat.js en la PWA.
   │
   ▼  09-20 → 09-22 — NÚCLEO CONVERSACIONAL (rama
   │  nexus-conversational-core → merge 0561ffd)
   │  Freeze de baselines (tags nlu-*), Dialogue State Manager,
   │  Semantic Core, gates 16/16→21/21, planner universal +
   │  memoria de trabajo (ee12db93 = baseline auditado).
   │
   ▼  09-23 → 09-24 — PIVOTE AL MODELO HÍBRIDO LLM
   │  e437d4ff: parser+composer LLM sobre el motor NEXO.
   │  1b36423e: el clasificador sintético se retira — el LLM ES el
   │  parser (89 archivos). Capa conversacional LLM, batería forense.
   │
   ▼  09-26 — CIERRE ESTRUCTURAL
   │  Reorganización de raíz (backend/, frontend/, sql/, test/,
   │  docs/, _cuarentena/) (9b30742e); Nexus reubicado como
   │  componente canónico backend/api/nexus/ (35271aef).
   │
   ▼
  NEXO 1.0 — estado consolidado (ver README.md y READMEs por
  componente): plataforma de custodia educativa en tiempo real —
  edge biométrico C++20 + API PHP + PostgreSQL/RLS multi-tenant +
  PWA de 7 roles + notificaciones WhatsApp + Nexus conversacional.
```

## Lecturas rápidas

- **Mayo fue el mes del despliegue**: 200 commits, la mayoría infra
  (Railway/CORS) + landing.
- **Junio–agosto fue el producto institucional**: auditoría, citaciones,
  OTP, seguimiento, onboarding, sensores, riesgo.
- **Septiembre fue la verificación y la IA**: auditoría documento↔código,
  OTA, y todo Nexus (≈50 commits en 7 días).
- Los días con más commits son días de depuración en producción
  (Railway 05-13→17; PgBouncer 08-04), no de diseño tranquilo.
