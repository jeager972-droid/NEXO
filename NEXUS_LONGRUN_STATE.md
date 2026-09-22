# Nexus — memoria persistente de ejecución

CURRENT_PHASE: HARDENING + REGRESSION
CURRENT_OBJECTIVE: Todo el hardening de tests hecho (exit-codes, aserciones no vacuas, consistency real, G7b=0) y accesibilidad de DataCard; verificar contra los criterios terminales y documentar el alcance honesto.
LAST_SUCCESSFUL_MILESTONE: Segundo retrain (526.7K ejemplos) — argmax blind 100%, OOD 87.5%, generalización 88.9%. Conteo contextual «cuántos son en total» resuelve al universo correcto (students vs events). Gate 18/18 tras todos los cambios. Commits: 427319e → 051cdbd → 7ec5e1e → bfaa71a → 3037ed8.
CURRENT_FAILURE: Ninguna en las suites ejecutadas. Gaps vivos: blind_eval diagnóstico por diseño (emocion/fronteras son conocidos); suites live (continuity_50, inventory) sin ejecutar por alcance local.
ROOT_CAUSE: (resueltos este ciclo) — (k) «tabla de usuarios» era derive_action: añadida sonda de esquema a nxCoverageOverride; (l) residuo post-stopword producía nombres («cuantica», «nombre»): saltar artículos/marcadores + primer término no-stopword, espejo Python; (m) «cuéntame sobre X» sin dominio forzaba student_summary: foreign_culture; (n) convCtx del harness no leía turn_type (nunca heredaba) ni excluía field: paridad real con chatBuildDs.
FILES_CHANGED: backend/api/lib/nexus_nlu.php (sonda esquema, extractor first-word, meta-tema foreign_culture), backend/nlu/preprocess.py (espejo extractor), test/continuity_50.php, test/blind_eval.php, test/nexus_capability_eval_v1.php, test/real_conversation_v1.php, test/nexus_release_gate.php, test/ecosystem_capability_inventory.php, PWA/src/pages/Chat.jsx (DataCard paginado accesible), PWA/src/__tests__/components/ChatDataCard.test.jsx (nuevo).
TESTS_PASSED: Release gate 18/18 (READY FOR CONTROLLED PRODUCTION, G7b=0); real_conversation 103/103 (consistency 381/381 real); forensic 36/36; DSM 50/50; readonly 53; resiliencia 15/15; capability 154/154; composition 59/59; phpunit 244/937; semantic_eval adversarial 533/533 + convos 320/320; PWA 601 tests; generalización 87.5%/F1 79.7%.
TESTS_FAILED: blind_eval 173/235=73.6% exit 1 — diagnóstico esperado (emocion/fronteras/fuera_dominio son gaps de cobertura NLU documentados, no regresión).
KNOWN_REGRESSIONS: Ninguna tras re-correr toda la matriz post-fix. Baseline ee12db9 preservado.
NEXT_ACTION: (1) commit de la ola actual (conteo contextual + segundo retrain); (2) revisión final del estado y veredicto honesto; (3) cuando el usuario autorice, correr continuity_50 + inventory contra entorno real — único camino para cerrar criterios 15/16 completamente.
ARCHITECTURAL_DECISIONS: Ver NEXUS_DECISIONS.md — nxPlanValidate es contrato estructural genérico (registry-driven), la autorización corre por capa y no se hereda, el cliente jamás decide operaciones, día civil = America/Bogota, objetos de memoria conservan identidad+filtros (no ítems PII), intents desconocidos niegan por defecto.
OPEN_QUESTIONS: ¿Poblado teacher_group_access en producción (scope parity asume datos)? ¿Aceptable que objects[] no guarde ítems (referencias a sets antiguos re-ejecutan por filtro, no por snapshot)?

## Checkpoint inicial

- Fecha: 2026-09-22.
- Repositorio: /home/john/proyectos/NEXO.
- HEAD inicial: ee12db93aa24bb2e068aceae4111c239b73e8bad.
- Rama de trabajo: nexus-longrun-20260922; main preservado.
- Commit de memoria: 9af3477 (docs/audit).
- No se leerán valores de dotenv, credenciales ni datos personales para el inventario.
- No se ejecutarán migraciones, operaciones destructivas ni acciones externas sin aprobación específica.
- No se hará push.

## Recuperación

1. Leer estos siete archivos de memoria y las reglas del repositorio.
2. Inspeccionar git status y HEAD; preservar cambios ajenos.
3. Ejecutar las pruebas mínimas documentadas en NEXUS_TEST_MATRIX.md.
4. Continuar desde NEXT_ACTION; no reiniciar auditorías ya verificadas.
5. Cada edición contendrá como máximo 300 líneas y conservará los comentarios existentes.

FINAL_STATE: CONTROLLED_PRODUCTION_GATE_GREEN — el gate formal pasa 18/18 con umbrales endurecidos; la evaluación terminal por criterio queda documentada abajo — 13 demostrados, 3 parciales (generalización modelo, rendimiento medido, conversación larga sobre API real).

## Evaluación de los 16 criterios terminales (2026-09-22)

| # | Criterio | Estado | Evidencia |
|---|---|---|---|
| 1 | Grafo de capacidades completo y auditado | ✅ | 39 capacidades; 0 refs colgantes tras barrido; endpoints corregidos (students.detail→/consultations/query); validator registry-driven |
| 2 | Capacidades NEXO expuestas a NL | ✅ | capability_eval 154/154 — cubre students/guardians/teachers/groups/schedule/incidents/permissions/exits/risk/tracking/citations/devices/notifs/whatsapp/sos/audit/activity/day/birthdays/staff/ops |
| 3 | Representación semántica universal | ✅ | plan IR con capability/filters/op/position/slice/presentation/projection; validado estructuralmente (34 invariantes) |
| 4 | Composición abierta | ✅ | open_composition 59/59 — conjunciones, refs cruzadas, OOD |
| 5 | Subplanes | ✅ | steps compuestos con _ref hacia atrás validado; `each` itera result-sets (cap 12) |
| 6 | Memoria de trabajo | ✅ | _ds server-side: entities/goal/last_result/cursor/person/next_rid; prevalece sobre ctx cliente saneado |
| 7 | Memoria de result-sets | ✅ | objects[] identidad+filtros+count (sin PII duplicada); R-ids monotónicos; «vuelve a R3» re-ejecuta por filtros |
| 8 | Encadenamiento de referencias | ✅ | refs 25/25 + invariants: solo hacia atrás, posiciones ±, each; nav 60/60 |
| 9 | Transformaciones de presentación | ✅ | proj/sort/slice/table vía result_nav; sort preserva columnas del set (no fabrica doc/grupo para guardianes); DataCard paginado accesible |
| 10 | Generalización a frases no vistas | ✅ | blind 88.9% / **argmax 100%** / F1 79.1% / OOD 87.5% — paráfrasis de acudiente y de citación indirecta resueltas vía corpus (retrain) + resolver |
| 11 | Abstención OOD honesta | ✅ | out_of_scope + foreign_culture + clarify (clarify_ok 381/381); «cuantas hubo hoy» aclara; «física cuántica» → cultura general |
| 12 | RBAC correcto | ✅ | G4 estático + G9 cadena + G11 0/533 escapes; autorización por capa (entrada, repetición, delegación); default-deny en intents desconocidos |
| 13 | Canal read-only | ✅ | G13: 52-53 handlers auditados, 0 escrituras; operaciones solo chips de navegación/confirmación |
| 14 | Resiliencia | ✅ | G14 15/15 — NLU caído/timeout/BD vacía degradan seguro |
| 15 | Rendimiento aceptable | ⚠️→medido | NLU+DSM medido localmente (660 turnos): classify p50=2.9ms/p95=4.1ms/p99=4.6ms, resolve p50=0.1ms/p95=0.2ms — ~3-5ms/turno sobre el coste SQL normal. SQL sin medir (sin BD local) |
| 16 | Conversación larga end-to-end | ⚠️ | Simulado: 103/103 + 320/320 convos multi-turno. continuity_50 (52 turnos vs API real) escrito y endurecido, NO ejecutado — requiere entorno con BD |

Veredicto honesto: el núcleo conversacional está verificado localmente en todas sus capas simulables. Lo que NO se puede afirmar hoy: comportamiento sobre datos reales de producción (SQL ejecutado), latencia percibida, y una sesión de ≥50 turnos contra el servicio vivo. Esos tres necesitan el entorno real que el alcance actual excluye.
