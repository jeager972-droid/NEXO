# Nexus — memoria persistente de ejecución

CURRENT_PHASE: FIX + REGRESSION
CURRENT_OBJECTIVE: Cerrar los gaps auditados (seguridad RBAC, planner, memoria, NLU/DSM) con cambios pequeños verificados; luego endurecer tests débiles y la accesibilidad de Chat.jsx.
LAST_SUCCESSFUL_MILESTONE: Release gate 18/18 PASS — READY FOR CONTROLLED PRODUCTION (10902ms). Conversaciones reales 103/103. Forense 36/36.
CURRENT_FAILURE: Ninguna en las suites ejecutadas; pendientes: hardening de tests débiles (continuity_50 exit-code, blind_eval, capability self-compare, consistency metric), accesibilidad/paginación en Chat.jsx.
ROOT_CAUSE: (resueltos) — (a) «otro/siguiente» re-ejecutaba intents denegados sin chatAllowed; (b) fallback multi-parte despachaba intents crudos sin nxDialogueResolve (probes mutativos pasaban); (c) guardian.of_student omitía la política chat.teacher.student_fields en planes compuestos; (d) nxPlanValidate aceptaba refs débiles, executors arbitrarios, ops/slices inválidos; (e) nxAllowed era fail-open; (f) R-ids reciclados tras trim; (g) gmdate UTC desfasaba «hoy/ayer» vs Bogotá; (h) percent usaba group_id nulo en fallback a grado; (i) inasistencias justificadas invisibles; (j) herencia de intent pisaba reroute opVerb→derive_action.
FILES_CHANGED: backend/api/lib/nexus_semantic.php (validator endurecido, RBAC/política, ejecutores scope, percent, absent, tz, each, _ctx_person), backend/api/lib/nexus_nlu.php (nxAllowed default-deny, coverage «háblame del sistema», students_in_group desde group_summary, guardia herencia↔operación, nxToday), backend/api/routes/chat.php (repeat-gate, lastPayload sin denied, parts fallback con DSM, history decode+orden, ctx cliente saneado, probe log en deny, nxToday), test/api/NexusPlanInvariantTest.php (nuevo), test/chat_forensic_harness.php (G7 acepta derive_action).
TESTS_PASSED: Release gate 18/18 (READY FOR CONTROLLED PRODUCTION); real_conversation 103/103 (intent 381/381, refs 25/25, carry 241/241, nav 60/60); forensic 36/36; DSM 50/50; readonly 53 handlers; resiliencia 15/15; capability eval 154/154; open composition 59/59; NexusPlanInvariantTest 34/34.
TESTS_FAILED: Ninguno en el último ciclo completo.
KNOWN_REGRESSIONS: Ninguna detectada tras los fixes; baseline ee12db9 preservado en main.
NEXT_ACTION: (1) Endurecer tests débiles — continuity_50 exit-code+aserciones, blind_eval exit-code, capability_eval auto-compare, consistency metric en real_conversation; (2) accesibilidad/paginación en Chat.jsx (caption, scope=col, aria-label, split-once, stable keys); (3) ejecutar PWA vitest; (4) actualizar TEST_MATRIX/GAPS/DECISIONS/CHANGELOG; (5) commit checkpoint.
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

FINAL_STATE: CONTROLLED_PRODUCTION_GATE_GREEN — el gate formal pasa 18/18; queda hardening de tests y UI antes del estado terminal «NEXUS UNIVERSAL CONVERSATIONAL LAYER — READY».
