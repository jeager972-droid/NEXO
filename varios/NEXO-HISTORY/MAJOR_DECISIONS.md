# MAJOR DECISIONS — las decisiones que dieron forma a NEXO

Registro de las decisiones técnicas con mayor impacto estructural.
Cada una distingue lo **demostrado** por Git de lo **inferido** y de lo
**no documentado**. Hashes en R1 salvo indicación (R3 = repo actual).

---

## Decisión 1: Interoperabilidad por excepción con privacidad radical

**Momento**: 2026-04-12 (génesis, R1).
**Situación anterior**: ninguna — es el acto fundacional.
**Cambio**: `api.php` (`0a84b1a7`, luego MVP `4aef080b`): endpoint que
recibe payloads AES-256, verifica institución activa, notifica por
WhatsApp y persiste en `auditoria_global` **solo el hash SHA-256 del
documento del estudiante** — los datos sensibles se procesan en memoria
y se descartan.
**Consecuencia**: NEXO nace como puente entre sistemas escolares, no
como repositorio central de datos personales. La anonimización en el
borde de escritura es la decisión de privacidad más antigua del proyecto.
**Evidencia**: `0a84b1a7`, `4aef080b` (R1).
**Motivación documentada**: el propio código la declara — comentarios
"procesa los datos en memoria volátil", "No se guardan en la DB Global".
El commit `4aef080b` la bautiza "Interoperabilidad por Excepción".
**Evolución posterior**: el principio sobrevive transformado — la v6.5/7.5
hace hash+sal de identificadores, el RC endurece la auditoría con cadena
de hash (`sql/2026-23-audit-hash-no-default.sql`), y el sistema final
sí persiste PII (estudiantes, acudientes) bajo RLS — la privacidad dejó
de ser "no guardar" para ser "guardar con aislamiento y trazabilidad".

## Decisión 2: WhatsApp como interfaz operativa (el bot de comandos)

**Momento**: 04-12 → 04-20 (R1); consolidado 05-05 (`0387a5a6`).
**Situación anterior**: el puente solo recibía transacciones.
**Cambio**: `api.php` crece a 1.486 líneas con el webhook Twilio y la
tabla `COMMANDS_CONFIG`: `!tarde`, `!inasistencia`, `!citacion`,
`!salida`, `!permiso`, `!excepcion`, `!SOS`, `!daño`, `!lista`,
`!subsana`, `!help`, con roles por comando y respuestas TwiML.
**Consecuencia**: la primera "UI" de NEXO fue un chat. RBAC, logging de
comandos (`logComando`), notificaciones a acudientes y confirmaciones
1/2 ("NEXO PADRES") nacieron aquí — antes de cualquier pantalla.
**Evidencia**: `6e5c3fb1` (v1.0), `29345fb0`, `9435cf1c`, `0387a5a6`,
`d5ce2718` (R1).
**Motivación inferida**: operar sin instalar nada — el personal ya tiene
WhatsApp. El historial no lo explicita.
**Evolución posterior**: los comandos migran a `user_commands`
(`sql/2026-12-user-commands.sql`, ya presente el 05-13) y a las
"operaciones" de la PWA; WhatsApp queda como canal de acudientes y OTP.
El patrón "lenguaje natural → acción sobre el sistema" reaparece en
septiembre como Nexus.

## Decisión 3: MySQL → PostgreSQL y modularización del monolito

**Momento**: 2026-05-07/08 (R1, `5d9b14d2`, `2e2df3a5`, `9f771f6a`).
**Situación anterior**: `api.php` monolítico de 1.486 líneas sobre
`mysqli`.
**Cambio**: extracción de `db.php` con PDO/pgsql (`PGHOST/PGDATABASE`…),
`api.php` a 648 líneas; al día siguiente `routes/` separa auth,
students, groups, operations…; `docker-compose.yml` y hardening SQL.
**Consecuencia**: PostgreSQL se vuelve la base canónica — decisión que
habilita después RLS, `SET LOCAL`, schemas versionados, Supabase.
**Evidencia**: diffs de `5d9b14d2` (api.php −1.019 líneas), árboles de
`9f771f6a` y `783ec75d` (R1).
**Motivación documentada**: el mensaje es literal ("postgre sql
update"); la razón no está escrita — inferencia razonable: maduración
para producción (transacciones, RLS posterior, Supabase/Railway PG).

## Decisión 4: La WebApp entra a NEXO (merge de dos proyectos)

**Momento**: 2026-05-08 (R1, merge `5b6d2820` ← raíz `20ebe789`).
**Situación anterior**: NEXO = API PHP + bot WhatsApp; no existía
ninguna interfaz visual propia en el repo.
**Cambio**: se fusiona un proyecto independiente de 0nto: app
Vite/React/Tailwind ya completa — PWA con `manifest.webmanifest`,
páginas institucionales (Dashboard, Consultation, Enrollment, Operation,
Audit, Notifications, Reports), `AuthContext`, `ProtectedRoute`,
cliente axios, CSP en `index.html`.
**Consecuencia**: NEXO pasa de "bot + puente" a **sistema de dos
superficies**: canal de campo (WhatsApp) + consola institucional (web).
El alcance del proyecto se amplía definitivamente.
**Evidencia**: árbol del merge `5b6d2820`; la línea `20ebe789` llega
sin historia (incluye `node_modules/` y `dist/` comiteados — snapshot
de trabajo local).
**Motivación documentada**: no registrada; inferencia: el bot no escala
a consultas, filtros y gestión — una consola era inevitable.

## Decisión 5: Refundación como monorepo

**Momento**: 2026-05-12/13 (R3, `7c468b3`, `71de30ef`, `e8231a5`).
**Situación anterior**: arte disperso — repo del puente (R1), repo
local de WebApp, repo local `Logica de negocio` (backend + edge C++),
carpetas ESP32 y docs sin git.
**Cambio**: un commit lo pliega todo (286 archivos: WebApp+Tauri, edge
C++ con HAL, librerías ESP32, docs de gestión, `ARCHITECTURE.md` con la
hoja de ruta por "Etapas"). `Logica de negocio` entró como gitlink
fantasma y se re-materializó como `backend/` (`817f7267`, `71de30ef`).
**Consecuencia**: por primera vez el sistema completo — edge, API,
cliente, docs — es un solo artefacto versionado. El `ARCHITECTURE.md`
fundacional lista los problemas heredados (R1–R5) y el objetivo:
edge RPi4/C++17, API PHP 8.2, cliente React/Tauri/Capacitor.
**Evidencia**: `7c468b3`, árbol de `71de30ef`, `e8231a5`.
**Motivación inferida**: coordinar el despliegue y la verdad del
sistema en un solo lugar.

## Decisión 6: Un solo servicio desplegable en Railway

**Momento**: 05-13 → 05-17 (saga de ~40 commits; consolidación
`1cb9e0ef`).
**Situación anterior**: frontend en Vercel + backend en Railway +
landing aparte = tres puntos de fallo y CORS cruzado constante.
**Cambio**: un contenedor nginx+php-fpm sirve landing `/`, API `/v1/` y
la app `/app/`; `DATABASE_URL`, `REDISHOST/REDISPORT`, PORT dinámico.
**Consecuencia**: arquitectura de despliegue "single service" que
simplificó CORS (el mismo origen sirve API y app) y el operar dev — al
precio de semanas de debugging de MPM/502/PORT.
**Evidencia**: `633ff56b`…`1cb9e0ef`, `6c1ccf13`, `5b4154c0`.
**Motivación documentada**: los mensajes citan errores concretos
(MPM conflict, 502, PORT binding); la motivación (simplificar a un
servicio) aparece en el propio mensaje de `1cb9e0ef`.

## Decisión 7: PWA en lugar de app nativa

**Momento**: dos tiempos — 05-24 (APK real de 60 MB subido en
`3247af43`, luego botones apuntan a la PWA en Vercel y `InstallPage`
multiplataforma, `c9ba7b9`, `d1b2d29`, `8e83c3ae`) y 08-20 (`bb0165b2`
elimina Tauri por completo).
**Cambio**: la distribución a usuarios se hace PWA instalable
(`beforeinstallprompt`, páginas por plataforma); el wrapper
Tauri/Capacitor que traía el monorepo se abandona y luego se borra.
**Consecuencia**: una sola base de frontend sirve escritorio y móvil;
desaparece la línea de build nativa (apk/exe/dmg) y su mantenimiento.
**Motivación documentada**: el commit `bb0165b2` dice
"WebApp renombrado a PWA (refleja que es una PWA, no app nativa)".
**Evolución posterior**: `PWA/` es hoy `frontend/pwa/`, la app
principal de los 7 roles.

## Decisión 8: PostgreSQL con RLS + `SET LOCAL` bajo PgBouncer

**Momento**: 08-04 (`73d0216f`, `dae5d29c`, `ab014632`, `5221c8f4`,
`a77d7a95`) — tras la migración a Supabase/PgBouncer (07-22,
`b5ebcd26` "emulated prepares").
**Situación**: con transaction pooling, el contexto RLS
(`app.current_role`, `app.school_id`) se perdía entre consultas y los
dashboards devolvían vacíos (saga de debug con endpoints `/debug/worker`
temporales).
**Cambio**: `requireAuth()` abre la transacción **antes** de cualquier
consulta y fija el contexto con `SET LOCAL`/`set_config` dentro de ella.
**Consecuencia**: el aislamiento multi-tenant por `school_id` funciona
bajo pooling — pieza crítica del modelo de seguridad de datos.
**Motivación documentada**: los propios commits ("RLS settings se
perdían entre consultas", "current_role es palabra reservada").
**Evolución posterior**: RLS se extiende a `edge_devices` (08-18,
`7b405fdc`) — hasta los nodos quedan aislados por sede.

## Decisión 9: El edge pasa de sensor a flota gobernada

**Momento**: 08-17 → 08-21 (`906c4dd8`, `21ed2f85`, `d1889922`,
`fd4f9e34`, `601cad04`, `7b405fdc`, `8688e903`).
**Situación anterior**: el edge hacía polling de comandos y enviaba
eventos; no había ciclo de vida de dispositivo.
**Cambio**: tokens por dispositivo auto-provisionados desde
`config.json`, revocación de sensores, heartbeat/`last_ping`,
CommandWorker, RLS en `edge_devices`, enrolamiento remoto de huella
ENROLL_REQUEST→confirmación E2E visible en la PWA.
**Consecuencia**: la nube gobierna el parque de nodos (alta, baja,
salud, enrolamiento) — requisito para operar varias sedes.
**Evolución posterior**: OTA y telemetría de nodos en septiembre
(`74dc1b8b`).

## Decisión 10: Resiliencia por degradación elegante

**Momento**: 08-18 → 08-24 (`6fa05edc`, `8765a884`, `fcb10046`,
`d4c59c6d`, `155b0c3f`, `12bb033c`).
**Situación**: Redis/Upstash y PgBouncer eran puntos únicos: si caían,
los workers morían en bucle y la API devolvía 500s (pool exhaustion).
**Cambio**: circuit breaker para Redis (~45 min de cooldown), workers
con modo fallback PostgreSQL, reintentos en polling de comandos,
"siempre encolar", best-effort Redis.
**Consecuencia**: la indisponibilidad del broker deja de tumbar el
sistema — los trabajos se ejecutan contra PG.
**Motivación documentada**: "Fix PgBouncer 'AUTH failed while
reconnecting'", "si Upstash cae, retornar vacío".

## Decisión 11: Suite de pruebas y CI como infraestructura permanente

**Momento**: 08-20 (`bb0165b2`, `786802d1`, `f2fb2671`, `c4ef7ad7`);
antecedente `PanicButtonTest` (06-30, `304f1ec`) y Vitest (08-02,
`ed4f1769`).
**Cambio**: `test/` con suites SQL/API/integración/edge (PHPUnit),
Vitest en PWA (~170 tests de API clients + ~80 UI), workflow CI.
**Consecuencia**: el proyecto gana una barrera de regresión — lo que
hará posible la metodología de "gates" de Nexus en septiembre.

## Decisión 12: Motor de riesgo pedagógico proporcional

**Momento**: 08-20/21 (`ad79306e`, `9bd15d2b`, `7ad4b781`…).
**Cambio**: `RiskEngineV3` convierte eventos conductuales en señales
graduadas (LEVE→MUY_ALTA) con umbrales configurables; se simplifica a
detección individual y se integra al onboarding.
**Consecuencia**: NEXO articula su principio — "no muestra datos:
detecta situaciones y propone acciones; la decisión final es humana"
(documentado en `auditoria/MOTOR_RIESGO.md`).

## Decisión 13: Auditoría contra especificación + OTA + modelo espacial

**Momento**: 09-15 → 09-19 (`3e329885`, `74dc1b8b`…).
**Situación**: el sistema funcionaba pero no había verificación formal
contra el documento funcional (`documento_final.txt`, incorporado ese
mismo periodo).
**Cambio**: `auditoria/` registra 652 verificaciones documento↔código
con evidencia por ítem; el commit de integración añade OTA
(`ota_updates`/`ota_deployments`), telemetría/salud de nodos, modelo
espacial (aulas+horarios ligados a eventos), conciliación de
asistencia, alertas a docentes, `simulaciones/` y `pruebas/`.
**Consecuencia**: el proyecto adquiere trazabilidad requisito↔código y
capacidad de gestión remota real del hardware.

## Decisión 14: Nexus — de clasificador NLU a LLM como parser

**Momento**: 09-20 → 09-24 (`a6fd4696`, `699e4b44`, `ee12db93`,
`4a278522`, `e437d4ff`, `1b36423e`).
**Situación**: la interfaz era formularios y comandos; no había
consulta en lenguaje natural.
**Cambio en tres saltos**:
1. `a6fd4696`: chatbot con NLU en cascada — servicio Python
   (train.py/corpus Colombia/modelo joblib) + runtime + `chat.php`.
2. Rama `nexus-conversational-core`: DSM compartido, Semantic Core con
   rerank determinista, planner universal + memoria de trabajo
   (`ee12db93`), gates de evaluación 16→21/21, merge `0561ffd`.
3. `e437d4ff` + `1b36423e`: modelo híbrido — el LLM parsea y compone
   sobre el motor de capacidades; **el clasificador TF-IDF se retira**
   (89 archivos), fixture de intents para suites locales.
**Consecuencia**: Nexus queda como capa conversacional read-only
garantizado, con RBAC heredado del sistema y evaluación por gates —
no un chatbot aparte sino una interfaz sobre la API existente.
**Motivación documentada**: los propios mensajes ("LLM es el parser",
"rescate determinista módulo→intent") y la batería de informes
`auditoria/`+`NEXUS_*.md` (veredictos READY/RC).

## Decisión 15: Reorganización estructural final

**Momento**: 09-26 (`9b30742e`, `2a18c57d`, `35271aef`).
**Cambio**: raíz canónica `backend/{api,edge}`, `frontend/{pwa,landing}`,
`sql/`, `test/`, `docs/`, `pruebas/`; memoria de trabajo NEXUS_*.md a
`_cuarentena/`; README exhaustivo por componente.
**Consecuencia**: la forma actual del repositorio — la que describe
`README.md` y `AGENTS.md`.

---

### Decisiones que quedaron atrás (también son historia)

| Decisión abandonada | Sustituto | Evidencia |
|---|---|---|
| MySQL/mysqli | PostgreSQL/PDO | `5d9b14d2` (R1) |
| AES-256-ECB + clave en código | AES-256-GCM + env | `9435cf1c` (R1) |
| Monolito api.php (1.486 ln) | routes/ + db.php | `9f771f6a` (R1) |
| ESP32/Arduino en el edge | C++ Linux + HAL + vendor SDKs | `e8231a5`, `cac1718b`, `edead085` |
| APK nativo / Tauri | PWA instalable | `3247af43`→`c9ba7b9`, `bb0165b2` |
| Backend duplicado (espejo) | backend/ único | `e8231a5`, `591d3a29` |
| Varios servicios (Vercel+Railway) | servicio único Railway | `1cb9e0ef` |
| Clasificador TF-IDF+LR | LLM parser/composer | `1b36423e` |
| Auditoría mutable | cadena de hash + read-only Nexus | `43682b30`, `e05b759` |
