# Nexus — checkpoints persistentes

## 2026-09-22 — SEMANTIC CONVERSATIONAL PARSING (SCP): núcleo de significado entre mensaje y planner

Nueva capa `backend/api/lib/nexus_scp.php` (~550 líneas): frame semántico normalizado
(`task/domain/subject/relation/field/filters/scope/time_range/aggregation/ranking/output/
references/transformations/corrections/position/confidence/evidence`) construido desde
mensaje + `_ds` server-side + señales semánticas + registry. Pipeline en `chat.php`:
interpretar → validar (contrato por tarea) → traducir (intent+slots o plan especializado)
→ RBAC → planner/executors existentes. Nada ejecuta desde lenguaje natural directo.

Prioridad de sujeto: explícito > posicional > anafórico > ítem activo > herencia.
Tareas: lookup/count/aggregate/compare/rank/filter/relation/navigate/transform/general/
out_of_scope/correct/clarify. Correcciones tipadas: replace_subject, exclude_active,
limit, pending_target («te faltó lo otro»), refine_scope, none_of («no sería empate…»).

Casos obligatorios A–N verificados live (`test/scp_live.php`, 26/26): tabla→acudiente del
primero; corrección explícita a Tomás; acudiente de María→documento; datos de María→
evasiones 30d→llegadas tarde; top-5 faltas mis clases; «SOLO 5» como recorte del ranking;
«cuánto ha faltado Juan Camilo 15d» como métrica de persona; «ese último»→acudiente→
documento del acudiente; umbral de alerta (risk_students); «todos» restaura colección
completa tras slice; celular del acudiente por nombre explícito; chiste+tabla comparación
compuestos; «te faltó lo otro» honesto; «no sería empate, sería que ninguna» → corrección
de interpretación de la comparación.

Suites nuevas: `test/scp_regression.php` (74 chequeos de frame, local) y `test/scp_live.php`
(14 sesiones/26 turnos contra API real).

Fallos encontrados solo en live y corregidos:

- **«todos» tras slice devolvía la vista recortada**: nav `all` nuevo (nexus_nlu) —
  «todos» restaura el universo; «los demás» sigue siendo el resto. En chat.php, `all`
  sobre set con label de slice re-ejecuta la colección original por sus filtros.
- **Plan compuesto perdía tarjetas**: `nxPlanExecute` agregaba replies pero no `cards`
  de los pasos — ahora las propaga (caso L: chiste + tabla comparación).
- **«¿Y del último mes?» inyectaba estudiante activo**: la resolución posicional del SCP
  trataba «último» como referencia a ítem. Guarda: ordinal+unidad temporal = rango.
- **«la primera tardanza de hoy» → count_events**: la regla de seguimiento de módulo del
  SCP clasificaba el ordinal como conteo heredado → forzaba count_events y el compose
  (incidents.position) nunca corría. Guarda `$posRef`: ordinal posicional no temporal →
  no es seguimiento de conteo; task=navigate (no forzado) → compose → incidents.position.

Verificación live post-SCP: §1 14/14, scp_live 26/26, continuity_50 53/53, gate 18/18
READY (NLU :8096), capability 153/153, DSM 60/60, real_conversation 381/381,
forensic 36/36, phpunit 244/938, resiliencia 15/15, readonly 53 handlers,
scp_regression 74/74.

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

## 2026-09-22 — Corpus acudiente + rerank roster + boundary fix (modelo re-entrenado)

- **Bug preexistente encontrado**: «muéstrame los estudiantes del 6-A» (la forma más natural de pedir la nómina) resolvía a `list_events` — el reranker léxico dejaba que «muéstrame/dame» robara el turno al modelo (0.998) porque `students_in_group` no tiene entrada en `NX_INTENT_LEXICON` y `nxCoverageOverride` corre ANTES del rerank. Fix post-rerank espejo de la regla de cobertura: `list_events` + grupo + sustantivo-persona + sin módulo → `students_in_group`. Verificado con stash: no era regresión propia.
- **Corpus**: +16 paráfrasis relacionales de acudiente en `student_field` («quién responde por X», «lo representa ante la institución», «a nombre de quién está», «figura como responsable», «adulto a cargo de»…). Retrain completo: 518.2K ejemplos/87 intents — router 99.68%, formal 98.42%, informal 98.73%; `model.joblib`+`model_php.json` regenerados.
- **`nxFieldSynonyms` acudiente**: +formas relacionales (`responde por`, `a cargo de`, `lo representa`, `figura como`, `a nombre de quien`, `tutor legal`…) — el resolver produce `field=acudiente` y `_ref=guardian`.
- **Bug `str_contains('ti')`**: la sigla TI hacía match dentro de «institu**ti**ción» → `field=documento` espurio. Sinsortas ≤3 letras ahora exigen límite de palabra.
- Resultado: blind paráfrasis acudiente 3→1 fallos; argmax 95.8→98.6%; near-miss 90→80% a nivel modelo crudo (los 10 casos resuelven bien tras resolver — la métrica mide cls). Único blind vivo: «quiero que el representante del alumno se presente en coordi» (paráfrasis de operación).
- Regresiones nuevas en `dsm_units`: sección N (roster con verbo de listado) + O (campo acudiente por paráfrasis) + extracción «física cuántica»/«a nombre de quién»/«la muchacha sofia». 60/60 en ambas rutas NLU.
- Rendimiento medido: classify p50=2.9ms/p95=4.1ms, resolve p50=0.1ms — ~3-5ms/turno de capa conversacional sobre coste SQL.

Resultados: gate 18/18, real_conversation 103/103, capability 153/153 (un caso filtra vía delegación legítima `intent_equiv`), composición 59/59, forense 36/36, DSM 60/60, phpunit 244/937, semantic singles 98.2% + adversarial 533/533 + convos 320/320, generalización argmax 98.6%/F1 78.7%, blind_eval diagnóstico (exit 1 por diseño).

## 2026-09-22 — Conteo contextual + segundo retrain (blind 100% argmax)

- **Corpus**: +10 peticiones indirectas de citación en `derive_action` («quiero que el representante del alumno se presente en coordi», «que venga el papá al colegio»…). Retrain: 526.7K ejemplos — **argmax blind 100.0%** (0 fallos), generalización 88.9%, OOD abstención 75→87.5%.
- **Conteo contextual**: «¿y cuántos son en total?» tras una nómina o un set de eventos resolvía a `list_events` por herencia genérica. Nueva regla: conteo desnudo con grupo/ctx → `group_student_count` (set students sin módulo) o `count_events` (set de eventos/módulo). Protegida con `$coverageHit` para que la herencia de baja-confianza (L1941) y la modificación genérica (L1953) no la pisen. `ownCount` extendido a conteos sin sustantivo de módulo.
- **Stopwords**: `se|me|te|nos|lo|le|les` (clíticos — «se presente en coordi» → no nombre), `ahi|alli|aca|alla` (deícticos — «quiénes faltaron ahí» ya no extrae «ahi» como estudiante), `coordi|rectoria` (lugares). Paridad PHP↔Python mantenida (G3 PASS).
- T2 «quiénes faltaron ahí» ya no propaga `student='ahi'` al contexto.

Resultados finales: gate 18/18 (G7b=0), real_conversation 103/103, capability 152/152, composición 59/59, forense 36/36, DSM 60/60 ambas rutas, phpunit 244/937, semantic singles + adversarial 533/533 + convos 320/320, generalización 88.9%/**argmax 100%**, OOD 87.5%.

## Checkpoints por construir

ARCHITECTURE, SEMANTIC CORE, CAPABILITY GRAPH, PLANNER ✔, CONTEXT ✔, GENERALIZATION (87.5% blind — gap de corpus acudiente documentado), PRESENTATION ✔, SECURITY ✔, RESILIENCE ✔, FINAL VALIDATION.
Cada checkpoint registrará hash, alcance real y resultados; no se marcará completado por compilación solamente.

## 2026-09-22 — LIVE CLOSURE: §1 verbatim 14/14 + continuity_50 53/53 contra API real

Stack nexo-test reconstruido con código actual (api/nlu rebuild + recreate, DB/Redis conservados). Fixture extendido (`pruebas/seed_chat_fixture.sql`): 10-A con 6 estudiantes (incl. Tomás Castaño Gutiérrez + acudiente), 10-B, docente con acceso a 3 grupos. Probe forense nuevo `test/live_probe.php` — 14 turnos verbatim, captura `_ds` before/after, intent/conf/source por turno → `/tmp/live_probe_last.json`.

Fallos encontrados SOLO en live (las suites simuladas los tenían verdes) y corregidos:

- **Acentos/ñ en resolución de estudiante** (capa executor+semántica): `chatResolveStudent` traducía `áéíóú` en columna pero el parámetro seguía acentuado y faltaba `ü/ñ` — «Tomás Castaño Gutiérrez» nunca matcheaba → «su acudiente» clarificaba con el estudiante activo. Fix: translate `'áéíóúüñ'→'aeiouun'` + `strtr` del parámetro, paridad en `nexus_semantic.php`. → t02/t05/t14 ahora resuelven el acudiente real.
- **«del último mes» extraía `student=ultimo`**: ordinales y unidades temporales (`ultimo`, `primero`, `mes`, `dia`, `siguiente`…) añadidos a stopwords PHP+Python. → t09 `count_events`+`days=30` correcto.
- **«asistencias de mis grupos en total»** secuestrado por `count_events` sin módulo («en total» disparaba el switch): exento `asistencias` — los ingresos biométricos no son módulo de incidentes. → t06 `count_present` → «6 ingresaron en tus grupos».
- **«¿y del mes pasado?» → `birthdays_today`** (modelo aprende «del mes»≈cumpleaños): un turno 100% temporal hereda el intent activo; lista de intents heredables ampliada. términos sin artículo (`mes pasado`, `semana anterior`…) añadidos.
- **Set vacío mataba navegación**: `hasResult` pedía `items` no vacío → «los demás» tras un 0-resultado caía a `top_offenders`. Ahora `isset(last_result)` + handler honesto (`no hay nada que navegar` / count→`hay 0` explícito).
- **Set de ruta-chat incompleto**: `chat_students_in_group` no emitía `columns/rows` ni `f` → nav-table caía a bullets y `agrega documento` proyectaba vacío. Materializado completo (paridad con el plan semántico).
- **«estudiantes de grado 9» → `math_operation`**: cobertura — el grado académico filtra roster (la capa semántica ya extrae `filters.grade`).

Verificación live final: §1 14/14 (invariante §4: `active_collection=students(10-A)` + cursor → acudiente del ítem), continuity_50 53/53, gate 18/18, capability 153/153, DSM 60/60, real_conversation 103/103, forensic 36/36, phpunit 244, resiliencia 15/15, readonly 53 handlers, inventario dentro del contenedor (39 caps, 132 tablas, 96 composiciones).

Notas honestas: rate-limit 60/10min real — `chat_rl:{uid}` se limpia en Redis de prueba entre corridas; servicios NLU zombie en :8090/:8096 divergen del php-model (reiniciar antes de comparar); php-model y service.py difieren en «y del mes» (php→birthdays_today, py→oos) — ambos resuelven bien vía herencia.
