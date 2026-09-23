# Nexus — matriz persistente de verificación

Base: ee12db93aa24bb2e068aceae4111c239b73e8bad. Fecha: 2026-09-22.
Línea base local ejecutada sin modificar expectativas. PHP host 8.5.10; Python local :8096 o fallback forzado :9.
Resultados por ruta NLU no intercambiables. Ninguna simulación prueba la ejecución SQL.

## Resultados iniciales

| Comando (raíz del repo) | Fallback :9 | Python :8096 |
|---|---|---|
| php test/nexus_capability_eval_v1.php | 152/153, exit 1 | 154/154, exit 0 |
| php test/nexus_ecosystem_open_composition.php | 59/59 | 59/59 |
| php test/real_conversation_v1.php | 88/103 conversaciones, 363/381 intents | 101/103 conversaciones, 379/381 intents |
| php test/dsm_units.php | 50/50 | Pendiente individual |
| php test/readonly_guard.php | 53 handlers sin escritura detectada | Independiente de NLU |
| php test/resilience.php | 15/15 | Suite fuerza fallback internamente |
| backend/api/vendor/bin/phpunit --configuration test/phpunit.xml --testsuite 'API Unit Tests' --do-not-cache-result | 210 tests, 880 assertions, exit 0; deprecations 2+1 | No requerido para baseline |

Anteponer NEXO_NLU_URL=http://127.0.0.1:9 o http://127.0.0.1:8096.
La población variable 153/154 en capabilities depende de ramas del harness: no presentarla como cobertura absoluta.
Docker/BD/HTTP API: PENDIENTE POR ALCANCE AUTORIZADO (usuario eligió solo pruebas locales).

| Área | Entrada localizada | Estado |
|---|---|---|
| Inventario | test/ecosystem_capability_inventory.php | Pendiente de inspección |
| Unidad DSM | test/dsm_units.php | Pendiente |
| Unidad/API | test/phpunit.xml, test/api | Pendiente de aislamiento |
| Semántica | test/semantic_eval.php | Pendiente |
| Blind | test/blind_eval.php | Pendiente |
| Capabilities | test/nexus_capability_eval_v1.php | Pendiente |
| Open composition | test/nexus_ecosystem_open_composition.php | Pendiente |
| Continuidad | test/continuity_50.php | Pendiente |
| Conversación real | test/real_conversation_v1.php | Pendiente |
| Read-only | test/readonly_guard.php | Pendiente |
| Resiliencia | test/resilience.php | Pendiente |
| Release | test/nexus_release_gate.php | Pendiente |
| Generalización | test/generalization_eval.py | Pendiente |
| Frontend | PWA/package.json | Pendiente de inspección |

## Resultados tras SECURITY+PLANNER (2026-09-22, rama nexus-longrun-20260922)

| Comando | Resultado | Estado |
|---|---|---|
| php test/nexus_release_gate.php | 18/18 puertas PASS — READY FOR CONTROLLED PRODUCTION (10.7s) | PASS |
| php test/real_conversation_v1.php | 103/103 convos; intent 381/381; refs 25/25; carry 241/241; nav 60/60; consistency 381/381 (real) | PASS |
| php test/chat_forensic_harness.php | 36/36 (G7 acepta derive_action) | PASS |
| php test/dsm_units.php | 50/50 | PASS |
| php test/readonly_guard.php | 53 handlers, 0 escrituras | PASS |
| php test/resilience.php | 15/15 | PASS |
| php test/nexus_capability_eval_v1.php | 154/154 (context ya no auto-compara) | PASS |
| php test/nexus_ecosystem_open_composition.php | 59/59 | PASS |
| phpunit 'API Unit Tests' | 244 tests / 937 assertions (incl. 34 invariantes de plan) | PASS |
| php test/semantic_eval.php | singles por categoría ~95%, adversariales 533/533, convos 320/320 (2732 turnos) | PASS |
| php test/blind_eval.php | 173/235 (73.6%) — diagnóstico, exit 1 honesto con fallos | DIAGNÓSTICO |
| python3 test/generalization_eval.py | gen 87.5% / F1 79.7% / ood 75% / near-miss 90% | DIAGNÓSTICO |
| PWA npm test | 601 tests (incl. ChatDataCard 11) | PASS |

Hardening aplicado a harness en este checkpoint: continuity_50 (exit 1 + aserciones no vacuas + credenciales por env), blind_eval (exit 1), capability_eval (sin auto-compare), real_conversation_v1 (convCtx con turn_type real + parity con chatBuildDs + consistency exige respaldo en ctx), release_gate G7b (críticos =0, no ≤6), ecosystem_inventory ($entityTables corregido vs sql/schema.sql).

Suites fuera de alcance local (requieren API/BD real): ya ejecutadas contra el stack nexo-test reconstruido (api/nlu rebuild, puerto 18080): continuity_50 53/53, inventario dentro del contenedor (39 caps/132 tablas), live_probe §1 verbatim 14/14.

## Resultados LIVE CLOSURE (2026-09-22, rama nexus-longrun-20260922)

| Comando | Resultado | Estado |
|---|---|---|
| php test/live_probe.php (API :18080, sesión real) | §1 verbatim 14/14 turnos, _ds before/after persistido | PASS |
| php test/scp_live.php (API :18080) | casos A–N: 26/26 turnos (tabla→acudiente, correcciones, ranking, umbral, compuestos, «todos», «te faltó lo otro», «no sería empate») | PASS |
| NEXO_NLU_URL=http://127.0.0.1:9 php test/scp_regression.php | 74/74 chequeos de frame semántico | PASS |
| php test/continuity_50.php (API :18080) | 53/53 turnos coherentes | PASS |
| php test/nexus_release_gate.php (NLU :8096) | 18/18 READY FOR CONTROLLED PRODUCTION | PASS |
| php test/nexus_capability_eval_v1.php | 153/153 | PASS |
| php test/real_conversation_v1.php | 103/103 (consistency 381/381) | PASS |
| php test/chat_forensic_harness.php | 36/36 | PASS |
| php test/dsm_units.php | 60/60 | PASS |
| php test/readonly_guard.php | 53 handlers read-only | PASS |
| php test/resilience.php | 15/15 | PASS |
| phpunit 'API Unit Tests' | 244 tests / 937 assertions | PASS |
| php test/blind_eval.php | 169/235 (71.9%) — diagnóstico, exit 1 honesto | DIAGNÓSTICO |
| ecosystem_capability_inventory (en contenedor) | 39 caps, 62 relaciones, 132 tablas, 96 composiciones | PASS |

Operación live: rate-limit real 60/10min por usuario — limpiar `chat_rl:{uid}` en Redis de prueba entre corridas. NLU local se levanta en :8096 (`service.py`, ver AGENTS.md); matar zombies :8090 antes de comparar — divergencia conocida php-model(birthdays_today) vs service(out_of_scope) en «y del mes», ambos resuelven por herencia.

Regresiones cerradas por el gate endurecido: «abre la tabla de usuarios» era derive_action — ahora security_probe (sonda de esquema). Extractor PHP↔Python: residuo tras stopword ya no produce nombres («cuantica», «nombre»); marcador de persona extendido (muchacha/pelada/chica/menor); meta-tema sin dominio → foreign_culture («cuéntame sobre la física cuántica»).

## Criterios de ejecución

Inspeccionar cada harness antes de ejecutarlo: detectar BD real, escrituras, servicios externos y generación de artefactos.
Registrar comando, entorno, población, aprobados, fallidos, salida reproducible y límites de la evidencia.
No usar corpus BLIND para entrenamiento; no alterar expectativas para ocultar fallos.
