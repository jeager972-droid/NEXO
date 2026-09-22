# Nexus — memoria persistente de ejecución

CURRENT_PHASE: LIVE CLOSURE VERIFICADO — stack real corriendo (api/nlu/db/redis recreados con código actual).
CURRENT_OBJECTIVE: Cierre de integración conversacional live cerrado: §1 verbatim 14/14 + continuity_50 53/53 contra API real con _ds persistido por turno.
LAST_SUCCESSFUL_MILESTONE: LIVE 14/14 (§1 verbatim) + continuity_50 53/53 + gate 18/18 + capability 153/153 + DSM 60/60 + real_conversation 103/103 + phpunit 244 — todo contra API/DB/Redis reales (nexo-test, puerto 18080). Fixture extendido: 10-A (6 estudiantes, Tomás Castaño Gutiérrez con acudiente), 10-B, teacher con 3 grupos (pruebas/seed_chat_fixture.sql).
CURRENT_FAILURE: Ninguna en producción live. blind_eval 169/235=71.9% (diagnóstico NLU por diseño; -4 vs baseline 173 por deriva de modelo/corpus, no del resolver — blind_eval solo ejerce nxClassify).
ROOT_CAUSE: (resueltos este ciclo live) — (o) chatResolveStudent translate() no cubría ñ/ü ni normalizaba el parámetro: «Tomás Castaño» no matcheaba (paridad en nexus_semantic.php); (p) «del último mes» extraía student=«ultimo»: ordinales+unidades temporales añadidos a stopwords PHP+Python; (q) «asistencias…en total» era secuestrado por count_events sin módulo: exento «asistencias» del switch a count_events + coverage count_present; (r) «¿y del mes pasado?» caía a birthdays_today: turno 100% temporal ahora hereda el intent activo (lista ampliada, incl. birthdays_today); (s) set vacío mataba la navegación: hasResult=isset(last_result) + nav-on-empty honesto + count→0 explícito; (t) set de ruta-chat sin columns/rows/f: nav-table emitía bullets y proj:+document salía vacío — materializado completo.
FILES_CHANGED: backend/api/lib/nexus_nlu.php (inherit temporal ampliado, coverage grupos/asistencias/grado, stopwords, hasResult), backend/api/lib/nexus_semantic.php (translate ñ/ü + parámetro desacentuado), backend/api/routes/chat.php (translate ñ/ü, nav-on-empty, set chat-path con columns/rows/f), backend/nlu/preprocess.py (stopwords espejo), test/continuity_50.php (checks actualizados a fixture nuevo), test/live_probe.php (nuevo — probe forense live 14 turnos), pruebas/seed_chat_fixture.sql (nuevo).
TESTS_PASSED: live §1 verbatim 14/14 API real; continuity_50 53/53 API real; release gate 18/18 (READY, con NLU local :8096); real_conversation 103/103; forensic 36/36; DSM 60/60; capability 153/153; readonly 53 handlers; resiliencia 15/15; phpunit 244/937; inventory dentro del contenedor (39 capacidades, 132 tablas).
TESTS_FAILED: blind_eval 169/235=71.9% exit 1 — diagnóstico esperado (emocion/fronteras/fuera_dominio son gaps de cobertura NLU documentados).
KNOWN_REGRESSIONS: Ninguna verificada. OJO: servicios NLU zombie en :8090/:8096 pueden dar respuestas divergentes del php-model (ej. «y del mes») — matar/reiniciar antes de comparar suites.
NEXT_ACTION: commit de esta ola live (invariante §4 verificado: active_collection+active_item → acudiente).
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
