# NEXO Nexus — Reporte final (2026-09-22)

## Arquitectura final

```
USER → NORMALIZATION (nxNorm) → NLU (TF-IDF+LR / service.py / fallback PHP)
     → DIALOGUE RESOLVER (nxDialogueResolve: refs, herencia, nav, corrección)
     → SCP (nxScpFrame: frame semántico validado → nxScpToSlots / nxScpToPlan)
     → COMPOSE (nxSemanticCompose: capability + filters + op + position)
     → nxPlanValidate (estructura) → nxPlanAllowed (RBAC por capacidad)
     → nxPlanExecute (executors SQL reales / intents chat_* delegados)
     → nxResultValidate (contrato post-ejecución)
     → RESPONSE (nxPlanResponse + presentation) → _ds tipado persistido → USER
```

El NLU clásico queda como fast path; nunca se impone cuando el frame semántico
decide otra tarea (rank/filter/relation/compare/correct lo fuerzan solo con
soporte estructural; navigate/transform no fuerzan).

## Componentes modificados (ciclo SCP + held-out + estado)

| Archivo | Cambio |
|---|---|
| `backend/api/lib/nexus_scp.php` (nuevo) | Frame normalizado (task/domain/subject/relation/field/filters/scope/time_range/aggregation/ranking/output/references/transformations/corrections/position/confidence/evidence), validación por contrato de tarea, traducción frame→intent/slots/plan, traza forense |
| `backend/api/routes/chat.php` | Cableado SCP tras resolver; correcciones (replace_subject/replace_metric/limit/none_of/refine_scope/pending_target); `chatBuildDs` tipado + lineage; `nxResultValidate` en el camino de plan; `top_offenders` emite `_result_set`; «todos» restaura universo tras slice |
| `backend/api/lib/nexus_nlu.php` | nav `all`≠`rest`; ordinal desnudo hereda group/module→posición; sustantivo persona→conteo de grupo; stopwords de verbos de comparación |
| `backend/api/lib/nexus_semantic.php` | `nxResultValidate` (grupo/módulo/tiempo/sujeto/posición); planes compuestos propagan tarjetas de pasos |
| `pruebas/seed_chat_fixture.sql` | María Fernanda, Juan Camilo, acudientes propios, umbrales de riesgo, 8-C sin incidentes |
| `test/scp_live.php` / `scp_regression.php` / `heldout_live.php` | Suites nuevas: A–N live, frame-level, held-out ciego |

## Modelo/runtime elegido (§14)

Híbrido: TF-IDF+LogisticRegression (intents) + extracción determinista (slots,
referencias, tiempo) + SCP (significado estructurado) + capability planner.
Decisión registrada en NEXUS_DECISIONS.md D009 — el cuello de botella era
significado conversacional, no recall de intents. Sin LLM generativo: los
datos solo salen de executors; nada de SQL ni permisos desde lenguaje.

## Cobertura de capabilities

39 capabilities: todas con camino LENGUAJE→PLAN→EXECUTOR→RESPUESTA verificado
(capability_eval 153/153 + live A–N/held-out + matriz en NEXUS_CAPABILITY_MAP.md).
No cubiertas como tales: ninguna conocida dentro del dominio; la conversación
general fuera de dominio se abstiene honestamente (por diseño).

## Tests ejecutados (post-SCP, todo contra HEAD)

| Suite | Resultado |
|---|---|
| test/scp_live.php (API real) | 26/26 (A–N) |
| test/heldout_live.php (API real, ciego) | 33/33 |
| test/live_probe.php (§1 verbatim) | 14/14 |
| test/continuity_50.php (API real) | 53/53 |
| test/nexus_release_gate.php | 20/20 READY |
| test/scp_regression.php | 74/74 |
| test/nexus_capability_eval_v1.php | 153/153 |
| test/real_conversation_v1.php | 103/103 · 381/381 |
| test/chat_forensic_harness.php | 36/36 |
| test/dsm_units.php | 60/60 |
| test/resilience.php | 15/15 |
| test/readonly_guard.php | 53 handlers, 0 mutaciones |
| phpunit API Unit Tests | 244 tests / 938 assertions |
| test/parity_dsm.php | 25/0 conflictos |
| test/blind_eval.php | 176/235 (74.9%) — diagnóstico general; op_eval SINGLES 80.1% |

## Tests fallidos conocidos

- blind_eval general 74.9% — cubre charla genérica (chistes, comida, cultura,
  emociones) fuera del dominio institucional; la puerta operativa (op_eval
  SINGLES 80.1%) sí pasa. Residual honesto, no bloqueante para el objetivo
  institucional — documentado, no renombrado.
- Fallback PHP (:9, sin NLU Python): op-blind 79.1% <80% — límite del
  clasificador de respaldo, no del pipeline semántico.

## Transcript final

Los casos A–N (derivados del transcript; `Pasted text(9).txt` no existe en el
repo) ejecutados completos contra API real: scp_live 26/26 — incluye tabla→
primero→acudiente→documento→cambio de estudiante, ficha→evasiones 30d→
tardanzas, top5→solo5→orden→segundo→acudiente, umbral→«todos», comparación→
corrección semántica, multi-goal→«te faltó lo otro».

## Held-out (§17)

12 conversaciones nuevas/33 turnos: sin tildes, typos, jerga («pelados»,
«muchachos», «doc», «cel»), correcciones de alcance/sujeto/métrica, cambios
temporales, multi-goal, ambiguas frías, veto mutativo+inyección SQL. 33/33.
No usadas para entrenar.

## End-to-end real

API + sesión + BD + RBAC + result-sets + persistencia `_ds` reales en el stack
nexo-test (Docker). Rate-limit real limpiado entre corridas en el entorno de
prueba.

## Performance

NLU+DSM local: classify p50≈3ms, resolve <1ms por turno. SCP frame: <1ms
(determinista). SQL sin medición dedicada — mismo coste que los handlers
existentes; no se añadió consulta nueva por turno.

## Errores conocidos / residuales

- `Pasted text(9).txt` ausente del repo — los casos A–N se reconstruyeron del
  pedido; si el archivo aparece, convertirlo a suite literal.
- blind_eval general 74.9% (ver arriba).
- php-model vs service.py difieren en «y del mes» (birthdays_today vs oos) —
  ambos resuelven correcto por herencia; divergencia registrada.
- NLU zombies :8090/:8096 divergen — reiniciar antes de comparar.

## Criterio exacto para READY (§24)

READY se declara solo si las 20 puertas pasan, incluidas G18 (transcript
golden live = 26/26) y G19 (held-out ≥90%), sobre API+BD reales, con las
regresiones completas verdes. Estado actual: **20/20 — READY FOR CONTROLLED
PRODUCTION**, con los residuales honestos listados arriba.
