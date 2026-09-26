# test/ — Sistema de pruebas de NEXO

Documentación exhaustiva del sistema de pruebas. NEXO tiene **cuatro niveles**
de verificación, del más barato al más real:

| Nivel | Dónde | Qué es real | Qué se simula |
|-------|-------|-------------|----------------|
| (a) Suites PHP locales | `test/*.php` | Pipeline NLU/DSM real (`nxClassify`, `nxDialogueResolve`, `nxSlots`, `chatOperationCmd`, validador de planes) | Respuestas del parser LLM, servidas del snapshot `test/fixtures/llm_intents.json`; contexto de sesión en memoria; **sin DB ni HTTP** |
| (b) PHPUnit | `test/api/`, `test/sql/`, `test/integration/`, `test/runners/` | Código PHP real, schema SQL real (parseo estático), simuladores de hardware | DB/Redis mockeados o ausentes; la mayoría son análisis estáticos |
| (c) Stack Docker | `pruebas/` | **Todo**: PostgreSQL 15 + PgBouncer + Redis + API real + 6 workers en daemon + Mosquitto + supercronic | Solo el mundo exterior (módem, UPS, huella, tiempo) |
| (d) Frontend/edge | `frontend/pwa` (Vitest), `backend/edge/tests/` (Catch2, symlink `test/edge`) | Componentes React, clientes API, módulos C++ del nodo | Hardware con stubs (no se necesita sensor real) |

## 1. Filosofía

- **El parser del chat es el LLM** (`backend/api/nexus/nexus_llm.php`). El viejo
  clasificador TF-IDF+LR se retiró. Para que las suites sean deterministas y
  no gasten cuota, `nxClassifyCore` sirve respuestas grabadas del fixture
  (`NX_CLASSIFY_FIXTURE`); ver §4.
- **Simulado ≠ falso**: las suites (a) ejercitan el pipeline real de
  producción hasta el punto de dispatch, pero no prueban SQL, API HTTP ni
  memoria persistida. Solo el stack (c) lo hace.
- **Lo que requiere entorno real**: las suites `*_live` (`continuity_50`,
  `scp_live`, `golden_live`, `heldout_live`, `live_probe*`, `live_battery`)
  hablan HTTP con la API y gastan cuota LLM. **NO corren en el alcance
  local** — necesitan el stack `pruebas/` (`:18080`) o equivalente.
- **Regla de honestidad del runner Docker**: ningún escenario inserta
  resultados para pasar; verifica el estado real persistido en la BD.

## 2. Comandos canónicos locales (AGENTS.md)

Desde la raíz del repo:

```bash
php test/dsm_units.php
php test/nexus_capability_eval_v1.php
php test/real_conversation_v1.php
php test/readonly_guard.php
php test/resilience.php
backend/api/vendor/bin/phpunit --configuration test/phpunit.xml --testsuite 'API Unit Tests' --do-not-cache-result
```

| Comando | Qué verifica |
|---------|--------------|
| `dsm_units.php` | Unidades del DSM (`nxDialogueResolve`) y resolver de operaciones (`chatOperationCmd`): `days=0`, «el mismo X», «vuelve», «y de hoy», ordinales, posesivos, autonomía tras operación, confirmación/cancelación, preservación de `_op` |
| `nexus_capability_eval_v1.php` | Eval de **capacidades** (planes), no de intents: composiciones nunca entrenadas — lista+posición, grupo+conteo, relación inversa, presentación=tabla, ordenamiento, porcentaje, comparación. Flags: `--json`, `--verbose` |
| `real_conversation_v1.php` | Benchmark de **conversaciones completas** (§31): referencias, carryover de entidades, cambio de objetivo, navegación de resultados, aclaraciones. Métricas: intent, reference, entity_carry, nav, clarify_correct, consistency. Flag: `--json` |
| `readonly_guard.php` | §13 read-only **estructural**: ningún handler `chat_*` de `routes/chat.php` contiene SQL mutativo (INSERT/UPDATE/DELETE/DROP/…); los intents de operación emiten chips de navegación, nunca efectos directos |
| `resilience.php` | §15 degradación segura: sin parser LLM (fixture y `NLU_LLM_KEY` apagados) el sistema responde `out_of_scope` honesto, nunca inventa ni se cuelga |
| PHPUnit `API Unit Tests` | 20 archivos en `test/api/` (ver §8) |

Estas suites fijan `NX_CLASSIFY_FIXTURE=test/fixtures/llm_intents.json`
automáticamente si la variable no está definida (los propios entry points lo
hacen, y `harness_turn.php` también). **No requieren Docker, DB ni cuota LLM.**

## 3. Tabla completa de suites (`test/*.php`)

Tipos: `unit` = lógica aislada · `sim` = simulación pipeline real con fixture ·
`static` = análisis de código · `live` = HTTP+DB+LLM reales · `diag` = diagnóstico ·
`gen` = generador de artefactos · `gate` = puerta agregadora.

| Suite | Tipo | Qué cubre | Cuota LLM | Docker/DB |
|-------|------|-----------|-----------|-----------|
| `audit_single_errors.php` | diag | Auditoría de errores single-turn (§3): dump mensaje→esperado→intent→conf→top-k→entities→slots→causa (vocabulario/sintaxis/semántica/entidad/ambigüedad/taxonomía/contexto/resolver). Usa `production_operational_blind.json` | ⚠️ debe correr en vivo al evaluar calidad (fixture=circular) | No |
| `blind_eval.php` | diag | Evaluación ciega + calibración del NLU por `nxClassify` real; `blind_set.json`; bandas de confianza, falsos-convencidos, abstenciones; `--clean` excluye frases contaminadas corpus↔blind → `/tmp/blind_eval.json` | ⚠️ idem | No |
| `chat_context_sim.php` | sim | Sesión de 2 turnos: T1 «búscame el reporte de Camilo Torres de 11A» → T2 «y cuántas evasiones tiene» hereda entidades (sessionStorage simulado) | No (fixture) | No |
| `chat_forensic_harness.php` | sim/diag | Harness forense: replica `POST /chat/message` (routes/chat.php ~l.239-347) con funciones reales hasta dispatch; 19 campos de traza por turno → `/tmp/forensic_traces.json`. No ejecuta handlers (requeriría PDO) | No (fixture) | No |
| `continuity_50.php` | live | §42: sesión ≥50 turnos alternando entidad/grupo/campo/tiempo/presentación/operación/referencia. **Escribe historial real — NO ejecutar en alcance local** | Sí | Sí (API :18080) |
| `corpus_semantic_gen.php` | gen/sim | Generador masivo por espacio semántico (FASE 4): producto cartesiano sujeto×verbo×grupo×estado×rango×presentación×posición×cardinalidad×relación×comparación×negativos×near-miss; evalúa que el enunciado produzca el plan correcto. `--emit-json`, `--max=N`, `--verbose` | No (fixture) | No |
| `dsm_units.php` | unit | Ver §2 | No (fixture) | No |
| `ecosystem_capability_inventory.php` | diag | §16/§17/§32/§39: auditoría del grafo real — DB schema + rutas + `nxCapabilityRegistry` + intents legacy; cobertura trazable a elementos reales. **Corre dentro del contenedor API** (necesita PDO + archivos deployados) | No | Sí (contenedor api) |
| `gen_llm_fixture.php` | gen | Regenera `llm_intents.json`: llama el LLM en lotes de 15 para las frases del log. Ver §4 | Sí (una vez por regeneración) | No |
| `gen_semantic_benchmark.php` | gen | Genera `semantic_blind.json` (§23): 1000 singles + 260 conversaciones (~2500 turnos) + 500 adversariales; semilla fija `mt_srand(20260921)` → artefacto reproducible | No | No |
| `golden_live.php` | live/gate | §15 conversación golden **bloqueante**: 9 turnos verbatim en una sesión contra API real; un solo fallo bloquea READY | Sí | Sí (:18080) |
| `harness_turn.php` | lib | `simulateTurn()` compartido — ver §5. No es suite | — | — |
| `heldout_live.php` | live | §17 evaluación conversacional held-out: frases nuevas (sin tildes, typos, jerga colombiana, abreviaturas, pronombres, correcciones, multi-goal, veto mutativo) — nada se usa para entrenar | Sí | Sí (:18080) |
| `live_battery.php` | live/diag | Interrogación forense multi-turno por rol: intent, entidades, reply, reply_raw, cards, actions, snapshot `_ds`. Bloques: contexto tablas rangos nombres comparaciones riesgo operaciones seguridad informal export_docente rector coordinador. `PAUSE_S` (def. 13s) respeta el rate-limit del free-tier | Sí | Sí (:18080) |
| `live_probe.php` | live/diag | Runner §1/§2: conversación verbatim, captura intent/confidence/reply/entities/`_ds`(before/after)/source/cards. `--turns=N`, `--sleep=MS` | Sí | Sí (:18080) |
| `live_probe_student.php` | live/diag | Sonda quirúrgica post-fixes (transcripción real 2026-09-24): filtro de estudiante en inasistencias, «y sus ‹módulo›?» hereda estudiante+rango, filtro justified, permisos/citaciones por estudiante, comparación+tendencia, «los grupos a mi cargo»→`scope=mine` | Sí | Sí (:18080) |
| `llm_probe.php` | diag | Sonda de lenguaje natural real (frases como las habla personal de colegio colombiano). Modos: con `NLU_LLM_KEY` en vivo / sin key → degradado a `out_of_scope` / con fixture | Sí si hay key | No |
| `nexus_capability_eval_v1.php` | sim | Ver §2 | No (fixture) | No |
| `nexus_ecosystem_open_composition.php` | sim | §44 benchmark de composición abierta: mide PLAN success end-to-end en 12 dimensiones (capability_selection, plan_correctness, entity/relation/parameter/subplan_resolution, composition, result_reference, presentation_transform, context_continuity, OOD, RBAC). `--verbose` | No (fixture) | No |
| `nexus_release_gate.php` | gate | Puerta de release: corre suites críticas y verifica umbrales G1 forense 36/36, G2 DSM 50/50, G3 paridad PHP↔Python, G4 RBAC estático, G5 operaciones=chips (el modelo nunca ejecuta), G6 probes destructivos→security_probe, G7 benchmark operacional, G8 abstención honesta. Veredicto: `READY FOR CONTROLLED PRODUCTION` / `NOT READY` | Según modo (fixture por defecto) | No |
| `op_eval.php` | eval | Evaluador del benchmark operativo `production_operational_blind.json`: singles por `nxClassify` + conversaciones por `simulateTurn` (misma lógica de ctx que producción); checks intent/not_intent/student/group/module/field. `--clean` | ⚠️ debe correr en vivo al evaluar calidad | No |
| `readonly_guard.php` | static | Ver §2 | No | No |
| `real_conversation_v1.php` | sim | Ver §2. **Es simulación NLU/DSM: no prueba SQL, API HTTP ni memoria persistida** | No (fixture) | No |
| `resilience.php` | unit | Ver §2 | No | No |
| `scp_live.php` | live | §CASOS OBLIGATORIOS A-N end-to-end contra API real: conversaciones multi-turno con sesión propia, traza por capa (USER INPUT → SEMANTIC FRAME → INTENT → REPLY → MEMORY UPDATE) | Sí | Sí (:18080) |
| `scp_regression.php` | sim | Regresión a nivel **semantic frame** (sin DB) de los casos A-N del transcript real: la capa SCP (`nexus_scp.php`) normaliza el significado antes de planificar. El e2e vive en `scp_live.php` | No (fixture) | No |
| `semantic_eval.php` | eval | Evalúa `semantic_blind.json` por la ruta real (`nxClassify`→`nxDialogueResolve`): ablación crudo vs resuelto, métricas por categoría, falsos-convencidos, críticos | ⚠️ debe correr en vivo al evaluar calidad | No |

## 4. El fixture `llm_intents.json`

**Qué es.** Un snapshot tipo VCR de respuestas **reales** del parser LLM:
362 frases normalizadas → `{intent, confidence, entities, domain, top3}`
(~80 KB). La clave es el texto ya normalizado por `nxNorm` (minúsculas, sin
tildes, etc.).

**Activación.** En `nxClassifyCore` (`backend/api/nexus/nexus_nlu.php` ~l.109):

1. `NX_CLASSIFY_FIXTURE=<ruta>` → si la frase normalizada existe en el mapa,
   responde del snapshot con `source: 'fixture'`; si no existe, cae al path real.
2. `NX_CLASSIFY_FIXTURE=` **vacío** → replay apagado: llama al proveedor real
   (`nxLlmClassify`) y gasta cuota API.
3. `NX_CLASSIFY_LOG=<archivo>` → registra cada texto normalizado consultado
   (para recoger las frases de las suites antes de regenerar el fixture).

Las suites lo activan solas: sus entry points hacen
`if (!getenv('NX_CLASSIFY_FIXTURE')) putenv('NX_CLASSIFY_FIXTURE=.../llm_intents.json')`
y `harness_turn.php` hace lo mismo — la variable externa siempre gana.

**Regeneración** (tras cambiar/añadir frases de las suites):

```bash
NX_CLASSIFY_LOG=/tmp/frases.txt NX_CLASSIFY_FIXTURE= php test/<suite>.php
sort -u /tmp/frases.txt > /tmp/frases_u.txt
NLU_LLM_KEY=... php test/gen_llm_fixture.php /tmp/frases_u.txt
# commit del fixture generado (test/fixtures/llm_intents.json)
```

`gen_llm_fixture.php` llama el LLM en lotes de 15 frases por llamada; falla si
`NLU_LLM_KEY` falta o `NLU_LLM_MODE=off`. Gasta cuota **una sola vez** por
regeneración, no por corrida.

**⚠️ Regla anti-circularidad.** Las suites que miden la **calidad del parser**
— `op_eval`, `blind_eval`, `semantic_eval`, `audit_single_errors` — no deben
correr contra el fixture cuando se evalúa calidad: sería medir al parser con
sus propias respuestas grabadas (circular). Para evaluación ejecutarlas en
vivo (`NX_CLASSIFY_FIXTURE=` vacío + `NLU_LLM_KEY`), lo cual gasta cuota.
Con fixture solo sirven como regresión de la capa DSM/resolve, no del parser.

**Otros fixtures** (`test/fixtures/`):

| Archivo | Tamaño | Contenido | Consumidores |
|---------|--------|-----------|--------------|
| `llm_intents.json` | ~80 KB | Snapshot del parser LLM (362 frases) | Todas las suites sim |
| `blind_set.json` | ~39 KB | Set ciego `single` + `conversational` | `blind_eval.php` |
| `production_operational_blind.json` | ~113 KB | Benchmark operativo `single` + `conversations` | `op_eval.php`, `audit_single_errors.php` |
| `semantic_blind.json` | ~660 KB | `seed` + `single` + `adversarial` + `conversations` (generado por `gen_semantic_benchmark.php`) | `semantic_eval.php` |

## 5. Harness compartido

| Archivo | Rol |
|---------|-----|
| `test/harness_turn.php` | Librería: `simulateTurn($text, &$ctx, $last)` — réplica fiel del flujo `/chat/message` hasta el punto de dispatch (normaliza → `nxClassify` → `nxSlots` → `nxDialogueResolve` con herencia de slots → `chatOperationCmd` → handler `chat_<intent>`). `$ctx` es el sessionStorage simulado pasado por referencia entre turnos; registra la traza de 19 campos en `$TRACES`. Activa el fixture por defecto. **La requieren**: `dsm_units`, `op_eval`, `semantic_eval`, `resilience` |
| `test/chat_forensic_harness.php` | Suite ejecutable que usa la misma lógica: corre la conversación forense completa (36 turnos), imprime traza por turno y vuelca `/tmp/forensic_traces.json`. Registra el handler que `chatDispatch` elegiría sin ejecutarlo (los handlers requieren PDO real) |

## 6. `test/simulaciones/` — dobles de hardware

Siete simuladores PHP que modelan el mundo físico del nodo edge; los usan los
tests PHPUnit de `test/api/` (sin hardware ni BD real):

| Simulador | Modela | Test que lo usa |
|-----------|--------|-----------------|
| `nodo/NodeSimulator.php` | Salud del nodo edge (ping, umbrales temporales, recuperación) — F-04/F-05 | `api/NodeHealthGateTest.php` |
| `biometria/ManualPresenceSimulator.php` | Flujo de presencia manual del coordinador — F-02 | `api/ManualPresenceTest.php` |
| `biometria/FingerprintSimulator.php` | Sensor biométrico multi-dedo (enrolar/revocar/cola offline→sync) — F-03 | `api/MultiFingerprintTest.php` |
| `energia/UpsSimulator.php` | Máquina de estados eléctrica: MAINS→BATTERY→LOW_BATTERY→CRITICAL→apagado — F-09 | `api/NodeTelemetryTest.php` |
| `m2m/CellularSimulator.php` | Módem celular M2M: registro, calidad de señal, interfaz up/down, portador — F-10 | `api/NodeTelemetryTest.php` |
| `termico/ThermalSimulator.php` | Curva térmica del SoC (disipación pasiva, sin ventilador — veredicto F-12); solo observabilidad — F-06 | `api/NodeTelemetryTest.php` |
| `almacenamiento/StorageSimulator.php` | Disco + profundidad de cola + backlog DLQ — F-06/F-13 | `api/NodeTelemetryTest.php` |
| `ota/OtaNodeSimulator.php` | Ciclo OTA del `OtaManager` C++: check→download(part)→verify sha256+firma→staged→applying→pending_confirm→APPLIED — Bloque D | `api/OtaUpdateTest.php` |

## 7. `pruebas/` — stack Docker de integración

Levanta el **sistema backend real completo** en Docker, idéntico a
producción: `PostgreSQL 15 → PgBouncer → API PHP (nginx+php-fpm)` con los 6
workers en daemon, Mosquitto MQTT y supercronic dentro del contenedor `api`,
más `Redis 7` (colas + dedup). Nada está simulado a nivel de código — solo el
mundo exterior.

| Archivo | Propósito |
|---------|-----------|
| `pruebas/docker-compose.test.yml` | Stack completo; schema auto-aplicado vía initdb (`sql/schema.sql` + `seed.sql` montados en `/docker-entrypoint-initdb.d/`); API en `:18080`; workers con intervalos acelerados (5–15 s); Twilio con credenciales dummy; `RATE_LIMIT_MAX=100000` para stress |
| `pruebas/env.test` | Credenciales de prueba (claves JWT de test); `NLU_LLM_*` opcional vía defaults del compose (sin key, el chat cae a `out_of_scope`) |
| `pruebas/seed.sql` | Seed determinista: escuela `22222222-…`, grupo 6-A mañana con 3 estudiantes, `coord@test.nexo` / `teach@test.nexo` / `guard@test.nexo` (`test1234`), dispositivo `44444444-…` token `nexo-test-device-token`, AES de prueba |
| `pruebas/seed_chat_fixture.sql` | Fixture extendido para Nexus/chat: 10-A con 6 estudiantes (incl. Tomás Castaño Gutiérrez + acudiente), 10-B, docente con acceso a 3 grupos, umbrales de riesgo, 8-C sin incidentes |
| `pruebas/runner.py` | CLI maestro (requiere docker + compose + python3 + `cryptography`) |
| `pruebas/nodo/` | Panel web del nodo: `panel_server.py` sirve `index.html` + mini-API JSON que acciona los hooks reales (`/api/fingerprint`→SYNC_ATTENDANCE, `/api/register`→REGISTER_STUDENT, `/api/ping`→telemetría) — forzado manual de acciones del edge |

### `runner.py`

```bash
./runner.py up          # levanta todo (build la 1ª vez)
./runner.py verify      # sanidad: health, seed, workers vivos
./runner.py all         # corre los 18 escenarios, informe final
./runner.py scenario <nombre>   # un escenario individual
./runner.py menu        # consola interactiva — forzar cualquier acción
./runner.py stress 500 40       # estrés: 500 eventos a 40/s
./runner.py down | reset        # baja / baja + borra volúmenes
./runner.py status | logs [svc]
```

Toda acción viaja por el endpoint real (`/api.php` con AES-256-GCM real o
REST con JWT) y toda verificación lee la BD real con `psql` — un escenario
solo pasa si el estado persistido lo confirma.

Escenarios (`runner.py all`): `ping` (F-06 telemetría), `power` (F-09
BATTERY/CRITICAL→incidentes), `ups_jornada`, `dedup`, `signal` (F-10
SENAL_PERDIDA), `tamper`, `dlq` (F-13 DLQ_BACKLOG), `ingest` (SYNC_ATTENDANCE
AES-GCM real→`biometric_events`), `register` (REGISTER_STUDENT con huella),
`absence` (worker detecta INASISTENCIA), `manual` (F-02 con JWT real),
`offline` (F-04 SIN_DATOS_NODO), `resilience` (Redis caído→ingest inline→
recuperación), `onboarding` (operación sin onboarding→428), `teacher` (aviso
docente + onboarding), `ota` (manifiesto firmado→verificación→APPLIED→
anti-rollback), `horario`, `reconcile`.

**⚠️ Advertencia bind-mount/SELinux.** Los volúmenes del compose usan la
opción `:z` (re-etiquetado SELinux). En 2026-09-22 hubo un error de permisos
de bind mount en este host y el usuario eligió **solo pruebas locales**; por
regla de AGENTS.md no se altera SELinux ni controles de seguridad para
sortearlo. Si `./runner.py up` falla con permisos de montaje, no forzar —
reportar al usuario.

Pendientes del entorno (según `pruebas/README.md`): escenarios edge-físico
(`docker kill` vs `stop`), stress de workers con cola saturada, carga
concurrente de N dispositivos, onboarding e2e por API, escenarios PWA (este
stack es 100% backend) y Twilio real (credenciales dummy: valida la cola, no
el envío).

## 8. PHPUnit

Configuración: `test/phpunit.xml` (bootstrap `backend/api/vendor/autoload.php`).
Tres testsuites declaradas:

```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "SQL Schema Tests"
cd test && ../backend/api/vendor/bin/phpunit --testsuite "API Unit Tests"
cd test && ../backend/api/vendor/bin/phpunit --testsuite "Integration Tests"
```

`test/runners/` **no está en phpunit.xml**: son scripts autónomos que se
ejecutan directamente con `php`.

### `test/sql/` — SQL Schema Tests (8 archivos, ~60 tests)

Parsean `sql/schema.sql` **estáticamente** (sin PostgreSQL corriendo) con
regex; verifican que el esquema esté completo y coherente.

| Test | Verifica |
|------|----------|
| `SchemaIntegrityTest.php` | Tablas, columnas, tipos, PKs, FKs, constraints, índices, triggers, funciones, roles, policies RLS, particiones, ALTER TABLE |
| `ConstraintTest.php` | Constraints CHECK y UNIQUE |
| `ForeignKeyTest.php` | Integridad referencial (toda FK apunta a tabla existente) |
| `IndexTest.php` | Índices esperados presentes |
| `PartitionTest.php` | Tablas particionadas y `fn_ensure_partitions` |
| `RlsSecurityTest.php` | Policies RLS por tabla |
| `SeedDataTest.php` | Seed mínimo (roles, permisos, admin, geografía) |
| `TriggerTest.php` | Triggers definidos |

### `test/api/` — API Unit Tests (20 archivos)

Unitarios/estáticos de la API PHP; mockean DB/Redis donde hace falta.

| Test | Verifica |
|------|----------|
| `AuthFunctionsTest.php` | Funciones puras de `routes/auth.php` (p. ej. `verifyUserPassword`) |
| `ChatNluTest.php` | Verificación estática del chatbot NLU: endpoint registrado, auth obligatoria, RBAC por intent, prepared statements, fallback <0.66, sin SQL libre |
| `CierreVerificacionesTest.php` | Cierre de ítems V-xxx de la lista de 652 (cambio de horario notifica, permiso con `schedule_id`, INASISTENCIA reconciliada, REAPARICION_TARDIA…) |
| `EndpointSecurityTest.php` | Seguridad estática de `routes/` + `_auth_middleware.php`: `requireAuth`, JWT, prepared statements, sin concatenación en SQL |
| `InstallationTest.php` | Artefactos de instalación: schema/seed presentes, idempotencia (`IF NOT EXISTS`), extensiones declaradas (uuid-ossp, pgcrypto) |
| `ManualPresenceTest.php` | F-02 bidireccional/multidimensional con `ManualPresenceSimulator`: registro manual cuenta como presencia; exención biométrica suprime incidentes sin invisibilizar |
| `MultiFingerprintTest.php` | F-03 con `FingerprintSimulator`: 2 dedos por estudiante, revocación, slot duplicado/inválido, cola offline→sync |
| `NexusPlanInvariantTest.php` | Invariantes del validador de planes Nexus (`nxPlanValidate`): plan válido de lectura, dependencia hacia atrás `@ref`, planes inválidos fallan antes de ejecutar |
| `NodeHealthGateTest.php` | F-04/F-05 con `NodeSimulator`: caída→supresión→incidente y recuperación→resolución→restauración; umbral temporal, ping nulo, cobertura múltiple |
| `NodeTelemetryTest.php` | Lote 5 (F-06/F-09/F-10/F-13) con Ups/Cellular/Thermal/Storage: telemetría sana→sin incidentes; violación de umbral→incidente tipado+dedup; recuperación→sin alarmas |
| `OtaUpdateTest.php` | OTA M2M (Bloque D) con `OtaNodeSimulator` + `lib/ota.php`: firma/verificación HMAC del manifiesto, semver, anti-rollback, ciclo de estados |
| `ProductionReadinessTest.php` | Preparación para producción: sin secretos hardcodeados, RLS en ≥10 tablas sensibles, >20 índices |
| `RegressionTest.php` | Regresión estática: SUPER_RECTOR eliminado (SQL+PHP), GUARDIAN preservado, `whatsapp_phone_normalized` + trigger |
| `RiskEngineV3Test.php` | Funciones puras del motor de riesgo v3: `compareLevels`, `maxLevel`, `validateRuleRanges` |
| `RiskScoreEngineTest.php` | Funciones puras del scoring: `computeScore` (pesos, baseline, techo), `scoreToLevel`, `shouldAlert` |
| `RouteEndpointsTest.php` | Cada archivo de `routes/`: define endpoint, usa `PDO::prepare`, no concatena variables en SQL |
| `SpatialModelTest.php` | F-01 modelo espacial faseado: CRUD classrooms/subjects/schedules, ingest resuelve `classroom_id`+`schedule_id`, enforcement `wrong_classroom` tras flag |
| `TwilioFunctionsTest.php` | Funciones puras de `lib/twilio.php`: `normalizeWhatsAppPhone`, URL de webhook de status |
| `TwilioWebhookAndPollingWorkerTest.php` | Estático: webhook `twilio_delivery.php` (VF-028) y workers polling absence/evasion/permission (VF-029) |
| `WorkerTest.php` | Estático de `workers/`: existen workers biométrico y Twilio, manejo de guardians, prepared statements |

### `test/integration/` — Integration Tests (2 archivos)

End-to-end: **requieren la API corriendo** (local o el stack `pruebas/`) y
seed aplicado.

| Test | Verifica |
|------|----------|
| `EndpointIntegrationTest.php` | Endpoints principales responden: auth, dashboard, operations, users, audit, consultations, devices, students, tracking, behavior, metrics |
| `OnboardingAssignmentsTest.php` | Tras `POST /school/groups-onboarding`: `work_shift` no-NULL, `student_group_assignments` y `teacher_group_access` poblados, rechazo sin `teacher_assignments`/`grade_shifts` |

### `test/runners/` — runners autónomos (`php`, no PHPUnit)

| Script | Verifica |
|--------|----------|
| `PlanComplianceTest.php` | Auditoría profunda SQL↔PHP del plan de migración: canonización, seed alineada, backend alineado, funciones SQL/RLS, sintaxis, etapas del plan |
| `SchemaPhpAlignmentTest.php` | Alineación SQL↔PHP: tablas/columnas referenciadas en PHP existen en SQL, roles/permisos, SUPER_RECTOR eliminado, GUARDIAN preservado, funciones requeridas |
| `FullSystemAlignmentTest.php` | Veredicto final de deploy: comparación esquema↔todo el backend (routes, workers, lib, api.php) |
| `PanicButtonTest.php` | Botón de pánico / revocación JWT con MockRedis: token emitido antes del panic→rechazado; después→aceptado |
| `integration_test.php` | Consistencia estática SQL↔PHP sin BD: roles/permisos/tablas críticas/funciones en SQL, `php -l` en todo el backend, roles canónicos en middleware |

## 9. Frontend y edge

### PWA — Vitest (`frontend/pwa`)

Los tests viven en `frontend/pwa/src/__tests__/` (~50 archivos `*.test.js(x)`:
api clients, components, context, hooks, pages, routes, utils, config).
Vitest no permite cargar tests fuera del root del proyecto, por eso no están
en `test/`.

```bash
cd frontend/pwa && npm test          # vitest run
cd frontend/pwa && npm run test:watch
cd frontend/pwa && npm run build     # verifica build Vite
```

### Edge — Catch2/CTest (`backend/edge/tests/`, symlink `test/edge`)

12 módulos C++ con Catch2 + CMake/CTest: `test_audit_trail`,
`test_cloud_manager`, `test_config_manager`, `test_crypto`,
`test_dev_stub_sensor`, `test_mqtt_command_worker`, `test_multi_finger`,
`test_nexo_result`, `test_node_monitor`, `test_ota_manager`, `test_sqlite`,
`test_watchdog`. Usan stubs de hardware (no se necesita sensor real).

```bash
cd backend/edge && cmake --preset dev-x86 && cmake --build build/dev \
  && ctest --test-dir build/dev --output-on-failure
```

## 10. CI (`.github/workflows/nexo-ci-cd.yml`)

Pipeline en push a `main`/`develop` y PR a `main`. Tres jobs:

| Job | Qué corre |
|-----|-----------|
| `build-webapp-react` | Node 22 · `frontend/pwa`: `npm ci` → `npm run lint` → `npm run build` → `npx vitest run` |
| `test-backend-php` | PHP 8.2 (pdo_pgsql, redis, mbstring) · `composer install` → `php -l` en todo `backend/api` → PHPUnit `API Unit Tests` + `SQL Schema Tests` → los 4 runners PHP (`PlanComplianceTest`, `SchemaPhpAlignmentTest`, `FullSystemAlignmentTest`, `PanicButtonTest`) |
| `build-landing` | Node 22 · `frontend/landing`: `npm ci` → `npm run build` |

**El CI no corre**: las suites de chat PHP locales (`test/*.php`), el stack
Docker de `pruebas/`, los integration tests que requieren servidor, los tests
edge C++, ni —obviamente— las suites live que gastan cuota LLM.

## 11. Reglas de honestidad

1. **No declarar READY con suites simuladas** (AGENTS.md). `dsm_units`,
   `real_conversation_v1`, `readonly_guard`, `resilience` y los PHPUnit son la
   puerta local mínima; READY exige además las live contra el stack real.
   `nexus_release_gate.php` emite el veredicto formal.
2. **Fixture ≠ calidad del parser**: correr `op_eval`/`blind_eval`/
   `semantic_eval`/`audit_single_errors` contra `llm_intents.json` mide al
   parser consigo mismo (circular). Evaluación de calidad = en vivo, con cuota.
3. **`blind_eval` es diagnóstico**, no puerta: produce métricas y
   `/tmp/blind_eval.json`; no bloquea por sí solo.
4. **`continuity_50.php` escribe historial real** en la BD — prohibido en el
   alcance local (AGENTS.md); solo contra el stack de pruebas.
5. **`real_conversation_v1.php` no prueba** SQL, API HTTP ni memoria
   persistida real: es simulación NLU/DSM.
6. **El runner Docker nunca inserta resultados**: un escenario pasa solo si el
   estado real persistido lo confirma; un fallo del sistema es la señal
   buscada, no un verde falso.
7. **Sin key, `out_of_scope` honesto**: sin `NLU_LLM_KEY` el parser devuelve
   `null`→`out_of_scope` (verificado por `resilience.php`); nunca inventa.
8. **No alterar SELinux/seguridad** para hacer pasar el stack Docker.
9. Registrar fallos y limitaciones de ejecución en vez de silenciarlos.
