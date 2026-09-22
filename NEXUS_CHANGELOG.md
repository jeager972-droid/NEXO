# Nexus — checkpoints persistentes

## 2026-09-22 — Inicialización de memoria externa

- HEAD inicial: ee12db93aa24bb2e068aceae4111c239b73e8bad.
- Árbol inicial limpio; rama main.
- Creados los siete archivos de memoria solicitados antes de modificar código.
- Localizados núcleo semántico, NLU, entrada de chat e infraestructura de pruebas existente.
- Advertencia: documentación arquitectónica histórica no equivale a arquitectura actual.
- Tests: no ejecutados aún; sigue auditoría de seguridad de los harness.
- Estado terminal: NOT_READY, pendiente de demostración.

## 2026-09-22 — Baseline local y alcance

- Rama de trabajo: nexus-longrun-20260922; main conserva ee12db9.
- API PHPUnit: 210/210; DSM: 50/50; guard readonly: 53 handlers; resiliencia: 15/15.
- Python local: capabilities 154/154, composición 59/59, conversaciones simuladas 101/103.
- Fallback PHP: capabilities 152/153, conversaciones simuladas 88/103.
- Inventario vivo previo al límite local: 132 tablas, 91 FK; inventario desplegado 39 capabilities, 53 handlers, 24 archivos de rutas.
- Detectadas aserciones vacías y duplicación de constructores/estado: se necesitan invariantes de código real, no nuevas mutaciones del corpus.
- Docker bind mount denegado; usuario seleccionó Solo pruebas locales. No tocar políticas, contenedores ni credenciales.
- Siguiente intervención: pruebas fallidas por familia, validador de planes/dependencias, memoria estable y presentación conservadora.

## 2026-09-22 — SECURITY+PLANNER: gate 18/18 verde

Rama nexus-longrun-20260922. Cambios por familia, cada uno verificado:

- **F1 (HIGH) repeat-after-denial**: `chatLastPayload` excluye payloads `denied` y la ruta de seguimiento («otro/siguiente») re-corre `chatAllowed` antes de despachar — una denegación ya no se puede re-ejecutar.
- **F2 multi-parte sin DSM**: el fallback de `parts` ahora pasa cada cláusula por `nxDialogueResolve` — coverage-override y el downgrade destructivo→`security_probe` aplican dentro de compuestos.
- **F3 guardian.of_student sin política**: añadido al mapa `chat.teacher.student_fields`; `nxPlanExecuteStep` además re-verifica `chatAllowed` sobre el intent delegado y solo despacha intents declarados por la capacidad (`exec`/`intent_equiv`), eligiendo dentro de exec `intent:a|b`.
- **F11 fail-open**: `nxAllowed` niega por defecto intents fuera de la matriz, salvo la allowlist explícita de smalltalk/meta/utilidades.
- **F9 rbac:ALL tautología**: `!== false || true` → `return true` honesto.
- **F12 probe sin log**: `chatAllowed` registra `CHAT_SECURITY_PROBE` también cuando la política lo niega.
- **Planner**: `nxPlanValidate` endurecido — steps no vacíos/arrays, refs solo hacia atrás con pos válida (1..n|-1|-2|each), `_ref` raíz huérfano rechazado, `@ref` sin `_ref` rechazado, filters tipados, ops contra action_type/aggregation/pagination de la capacidad, `exec`/`_delegate_intent` deben ser los declarados, efecto solo READ, position entero 1..500|last|last-N, slice {n:int≥1, from:start|end}, parámetros requeridos genéricos desde el registry (incl. `groups.compare` exige group+group2). Nuevo `test/api/NexusPlanInvariantTest.php` (34 tests) fija el contrato.
- **F4 percent**: denominador usa group_id solo si el grupo resolvió; el fallback numérico cae a grade_level (antes `ag.group_id=NULL` → denominador 1).
- **Absent**: ejecutor incluye `INASISTENCIA_JUSTIFICADA`/`_NO_JUSTIFICADA` (el composer ya las colapsaba a `absent`).
- **Scope parity**: `schedule.of_group` y `guardians.of_group` exigen `teacher_group_access` para TEACHER/COUNSELOR — negación explícita, no «sin datos» silencioso.
- **TZ**: `nxToday()` (America/Bogota) reemplaza todos los `gmdate('Y-m-d')` de chat.php, `nxSemRange` y `nxSlots` — «hoy/ayer» alineados con el sistema de asistencia.
- **F6 `each`**: `nxPlanExecute` itera el result-set referenciado (acotado a 12, «…y N más»).
- **F7 `_ctx_person`**: propagado a los pasos compuestos.
- **Memoria**: R-ids monotónicos (`next_rid`, nunca reciclados tras trim); `objects[]` conserva identidad+filtros+count sin ítems PII (los ítems viven una sola vez en `last_result`); `person` se descarta cuando el turno ancla otro estudiante.
- **F5 history**: `payload_json` se decodifica (cards/actions/intent se restauran); solo la rama DESC se invierte — la rama por sesión queda oldest→newest y conserva los últimos 200.
- **F10 ctx cliente**: solo entidades visibles — `_op`/`_ds`/`last_intent`/`result_set` no llegan del cliente.
- **F8a**: `$conf` indefinido en la rama compuesta → `$plan['conf']`.
- **Registry**: `students.detail` endpoint `POST /consultation/search` → `/consultations/query`; caso muerto `students.in_group` eliminado del switch.
- **DSM**: la herencia de intent (regla 3) ya no pisa reroutes a operación (`derive_action`/`start_operation`/`repeat_op`/`confirm_op`/`security_probe`) — «ahora quiero citar a su acudiente» resuelve la acción.
- **Coverage**: «háblame/cuéntame del sistema|nexo|asistente» → `about_nexus`; `students_in_group` también corrige desde `group_summary` con sustantivo explícito.
- **Test**: G7 del forense acepta `derive_action` (documentado equivalente a `start_operation` en la propia suite).

Resultados: release gate 18/18 PASS (READY FOR CONTROLLED PRODUCTION, 10.9s); real_conversation 103/103; forense 36/36; DSM 50/50; readonly 53; resiliencia 15/15; capability 154/154; open composition 59/59; invariantes 34/34.

Pendiente: hardening de tests débiles (exit-codes, métricas vacuas), accesibilidad/paginación Chat.jsx, evaluación de criterios terminales.

## 2026-09-22 — Harness honesto + fix real de sonda/esquema y extractor

- **G7b endurecido**: `críticos-fallidos ≤6` → `=0`. Al apretar el umbral apareció 1 crítico real: «abre la tabla de usuarios» → `derive_action` (op=null, student='usuarios'). Nueva regla en `nxCoverageOverride`: `tabla(s) de (usuarios|roles|claves|…)` y verbo+`base de datos|esquema` → `security_probe`. Tablas presentacionales legítimas («tabla de tardanzas») intactas.
- **Extractor PHP↔Python**: el residuo tras filtrar stopwords ya no produce nombres — se saltan artículos/marcadores iniciales y el primer término restante debe no ser stopword («sobre LA física cuántica» → `null`, no «cuantica»; «el mismo juan» → `juan`). Patrón-1 extendido a `muchacho|muchacha|pelado|pelada|chico|chica|menor` («la ficha de la muchacha sofia» → `sofia`). Stopwords: `nombre|nombres`. Espejo en `preprocess.py` — G3 paridad PASS.
- **Meta-tema generalizado**: `háblame/cuéntame/explícame/enséñame/infórmame (de|sobre|acerca de) X` sin persona ni sustantivo de dominio → `foreign_culture` («cuéntame sobre la física cuántica» ya no inventa student_summary). Dominio y persona resuelta siguen intactos.
- **Harness endurecidos**: `continuity_50` exit-code + aserciones `/\w{3,}/` reemplazadas por intent/contenido + credenciales por env (`NEXO_TEST_USER/PASS`); `blind_eval` exit 1 con fallos (173/235=73.6% — diagnóstico honesto); `capability_eval` sin auto-compare en CTX; `real_conversation_v1` `convCtx` recibe DSM completo (turn_type estaba indefinido — nunca heredaba tema; ahora paridad real con `chatBuildDs`, `field` excluido) + `consistency` real: todo `inherited` debe tener respaldo en ctx o `_ref` (isset, no empty — `days=0` válido); `ecosystem_inventory` `$entityTables` corregido contra `sql/schema.sql` (notification_queue→notifications+twilio, biometric_devices→edge_devices, audit_log→global_audit_logs, teacher_subject_assignments eliminada).
- **UI**: `DataCard` paginado y accesible (10 filas/página, caption sr-only, scope=col, aria-live status, nav con select+botones aria-controls, reset al reemplazar card, celdas null→—) + `ChatDataCard.test.jsx` (11 tests). `RichText` split único.
- Servicios NLU :8090/:8094/:8095/:8096 reiniciados con `preprocess.py` nuevo.

Resultados: gate 18/18 (con G7b=0), real_conversation 103/103, capability 154/154, composición 59/59, forense 36/36, DSM 50/50, phpunit 244, semantic_eval adversariales 533/533 + convos 320/320, generalización 87.5%/F1 79.7%, PWA 601 tests.

Pendiente honesto: paráfrasis de acudiente a nivel de modelo (3 casos blind — cobertura de corpus, no resolver); blind_eval 73.6% documenta calibración; suites live (continuity_50, inventory) sin ejecutar por alcance local.

## 2026-09-22 — Registry auditado + conteos reales (LIMIT 400)

- **Refs colgantes → 0**: `related`/`nearby` del registry nombraban 17 capacidades inexistentes (result_nav, students.in_group, attendance.absent_list, guardians.field, staff.list, attendance.ranking, exits.list, risk.alerts, session.summary, about.me, operations.start, groups.count, students.summary…). Mapeadas a la capacidad real o retiradas (result_nav es DSM, no capability). El grafo ahora solo referencia capacidades existentes.
- **LIMIT 400 → conteos truncados**: `students.count`/`students.percent`/`incidents.count` usaban `count($rows)` tras LIMIT 400 — un colegio >400 estudiantes o >400 tardanzas del mes reportaba «400» como cifra real. Ahora op∈{count,percent} ejecuta `COUNT(DISTINCT)`/`COUNT(*)` sobre el mismo WHERE — el listado sigue paginado pero la cifra es el universo real.
- **Ordinales acotados a la ventana**: `position`/`last`/`last-N` en students e incidents ahora verifican contra las filas traídas (≤400), no contra el total — antes un ordinal >400 pasaba el check y luego `items[$idx]` era null.
- Verificación: `php -l` limpio; suites conversacionales intactas (los ejecutores SQL no se ejercen en alcance local — cambio verificado por contrato, no por ejecución).

## Checkpoints por construir

ARCHITECTURE, SEMANTIC CORE, CAPABILITY GRAPH, PLANNER ✔, CONTEXT ✔, GENERALIZATION (87.5% blind — gap de corpus acudiente documentado), PRESENTATION ✔, SECURITY ✔, RESILIENCE ✔, FINAL VALIDATION.
Cada checkpoint registrará hash, alcance real y resultados; no se marcará completado por compilación solamente.
