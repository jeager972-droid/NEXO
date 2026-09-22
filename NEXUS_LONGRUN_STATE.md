# Nexus — memoria persistente de ejecución

CURRENT_PHASE: HARDENING + REGRESSION
CURRENT_OBJECTIVE: Todo el hardening de tests hecho (exit-codes, aserciones no vacuas, consistency real, G7b=0) y accesibilidad de DataCard; verificar contra los criterios terminales y documentar el alcance honesto.
LAST_SUCCESSFUL_MILESTONE: Release gate 18/18 PASS con umbral honesto (G7b críticos=0, antes ≤6) — READY FOR CONTROLLED PRODUCTION (10741ms). La tolerancia endurecida destapó 1 crítico real (sonda «tabla de usuarios») ya corregido.
CURRENT_FAILURE: Ninguna en las suites ejecutadas. Gaps vivos documentados: 3 paráfrasis de acudiente a nivel de modelo (corpus), blind_eval 73.6% diagnóstico, suites live (continuity_50, inventory) sin ejecutar por alcance local, LIMIT 400 en conteos all.
ROOT_CAUSE: (resueltos este ciclo) — (k) «tabla de usuarios» era derive_action: añadida sonda de esquema a nxCoverageOverride; (l) residuo post-stopword producía nombres («cuantica», «nombre»): saltar artículos/marcadores + primer término no-stopword, espejo Python; (m) «cuéntame sobre X» sin dominio forzaba student_summary: foreign_culture; (n) convCtx del harness no leía turn_type (nunca heredaba) ni excluía field: paridad real con chatBuildDs.
FILES_CHANGED: backend/api/lib/nexus_nlu.php (sonda esquema, extractor first-word, meta-tema foreign_culture), backend/nlu/preprocess.py (espejo extractor), test/continuity_50.php, test/blind_eval.php, test/nexus_capability_eval_v1.php, test/real_conversation_v1.php, test/nexus_release_gate.php, test/ecosystem_capability_inventory.php, PWA/src/pages/Chat.jsx (DataCard paginado accesible), PWA/src/__tests__/components/ChatDataCard.test.jsx (nuevo).
TESTS_PASSED: Release gate 18/18 (READY FOR CONTROLLED PRODUCTION, G7b=0); real_conversation 103/103 (consistency 381/381 real); forensic 36/36; DSM 50/50; readonly 53; resiliencia 15/15; capability 154/154; composition 59/59; phpunit 244/937; semantic_eval adversarial 533/533 + convos 320/320; PWA 601 tests; generalización 87.5%/F1 79.7%.
TESTS_FAILED: blind_eval 173/235=73.6% exit 1 — diagnóstico esperado (emocion/fronteras/fuera_dominio son gaps de cobertura NLU documentados, no regresión).
KNOWN_REGRESSIONS: Ninguna tras re-correr toda la matriz post-fix. Baseline ee12db9 preservado.
NEXT_ACTION: (1) Barrido de referencias colgantes en registry (result_nav, students.summary, risk.alerts — ¿delegación válida u huérfanos?); (2) LIMIT 400 en conteos all — verificar si el executor cuenta antes de cortar; (3) evaluar los 16 criterios terminales contra evidencia; (4) commit checkpoint; (5) cuando el usuario autorice, correr continuity_50 + inventory contra entorno real.
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
