# Nexus — decisiones persistentes

## D001 — Conservar e integrar antes de reemplazar

DECISION: Auditar y medir antes de modificar la arquitectura existente.
DATE: 2026-09-22.
PROBLEM: El repositorio ya contiene un núcleo semántico y conversacional significativo; informes históricos pueden estar desactualizados.
OPTIONS_CONSIDERED: Reescritura; extensión lexical inmediata; auditoría de código y pruebas con cambios mínimos.
CHOSEN_APPROACH: Auditoría reproducible y regresiones que fallen antes de cada corrección.
WHY: Evita duplicar capacidades y confundir mejoras de benchmark con funcionamiento real.
TRADEOFF: No prometer cobertura completa hasta verificar el ecosistema y las conversaciones.
AFFECTED_COMPONENTS: Registry, NLU, IR, planner, estado, executors, UI y harness.
TESTS_SUPPORTING_DECISION: Pendientes de línea base; decisión de metodología, no afirmación de calidad.

## D002 — Respetar el límite local de verificación

DECISION: Continuar sin integración Docker/BD/HTTP de la API.
DATE: 2026-09-22.
PROBLEM: Los bind mounts del repositorio devuelven Permission denied; no alterar controles para acceder.
OPTIONS_CONSIDERED: Copia selectiva a contenedor temporal; montaje habilitado por usuario; solo pruebas locales.
CHOSEN_APPROACH: Solo pruebas locales, seleccionado explícitamente por el usuario.
WHY: Respeta autorización y evita cambios de seguridad o acceso no aprobado.
TRADEOFF: Se pueden probar funciones reales con datos sintéticos y NLU local, pero no afirmar corrección SQL ni release end-to-end.
AFFECTED_COMPONENTS: Pruebas, inventario vivo, demostración final, criterio terminal.
TESTS_SUPPORTING_DECISION: API PHPUnit 210 tests; NLU Python local /health operativo; suites semánticas ejecutadas también con fallback PHP.

## D003 — La autorización corre por capa y nunca se hereda

DECISION: Todo camino que despacha un intent o plan re-corre el gate en el punto de ejecución, no solo en el punto de entrada.
DATE: 2026-09-22.
PROBLEM: Tres agujeros reales: «otro/siguiente» re-ejecutaba el último intent sin chatAllowed (una denegación persistida se re-ejecutaba); el fallback multi-parte despachaba intents crudos del modelo sin nxDialogueResolve (el downgrade destructivo→security_probe no aplicaba); guardian.of_student dentro de planes compuestos omitía la política chat.teacher.student_fields.
OPTIONS_CONSIDERED: Gate único temprano; gate por capa (entrada + repetición + delegación).
CHOSEN_APPROACH: Gate por capa — chatAllowed en la ruta de repetición, chatLastPayload excluye denied, parts fallback pasa por el DSM, nxPlanExecuteStep re-verifica el intent delegado contra su propia matriz.
WHY: Las políticas pueden cambiar entre turnos y un payload persistido no es prueba de autorización actual.
TRADEOFF: Alguna consulta legítima puede denegarse si la política cambió mid-sesión — es el comportamiento deseado.
AFFECTED_COMPONENTS: chat.php (repeat path, parts fallback, chatLastPayload), nexus_semantic.php (nxPlanAllowed mapa + nxPlanExecuteStep delegación).
TESTS_SUPPORTING_DECISION: release gate G9 (12 casos RBAC), forense 36/36, DSM 50/50.

## D004 — nxPlanValidate es contrato estructural genérico

DECISION: La validación de planes deriva sus reglas del registry (required_parameters, action_type, aggregation, pagination, exec/intent_equiv), no de listas hardcodeadas por capacidad.
DATE: 2026-09-22.
PROBLEM: El validador previo aceptaba _ref huérfanos y forward-refs, executors arbitrarios (exec/_delegate_intent inyectables), efectos no-READ, posiciones/slices inválidos y groups.compare sin grupo base.
OPTIONS_CONSIDERED: Whitelist por caso; reglas genéricas derivadas del registry.
CHOSEN_APPROACH: Reglas genéricas — una capability nueva hereda la validación sin tocar el validador.
WHY: El grafo de capacidades crece; hardcodear casos garantiza drift.
TRADEOFF: El registry debe declarar correctamente su forma (si un campo falta, la validación asume el default permisivo del contrato).
AFFECTED_COMPONENTS: nxPlanValidate (nexus_semantic.php), NexusPlanInvariantTest.
TESTS_SUPPORTING_DECISION: 34 invariantes nuevos + capability eval 154/154 + open composition 59/59 sin regresión.

## D005 — Intents desconocidos niegan por defecto

DECISION: nxAllowed deja de ser fail-open: fuera de la matriz solo pasan smalltalk/meta/utilidades explícitas.
DATE: 2026-09-22.
PROBLEM: Cualquier intent nuevo sin entrada en nxIntentRoles era ejecutable por todos los roles, incluido SECURITY/AUXILIARY.
OPTIONS_CONSIDERED: Mantener fail-open documentado; default-deny con allowlist.
CHOSEN_APPROACH: Default-deny con allowlist const (NX_SMALLTALK_INTENTS ∪ meta ∪ colombia_*/math/random).
WHY: El modelo puede emitir clases nuevas tras retraining; el canal de datos debe ser cerrado por construcción.
TRADEOFF: Un intent de datos legítimo nuevo requiere entrada explícita en la matriz — coste deliberado.
AFFECTED_COMPONENTS: nxAllowed (nexus_nlu.php).
TESTS_SUPPORTING_DECISION: verify con labels del modelo (87) vs matriz+allowlist — 10 utilidades cubiertas, 0 intents de datos sin rol.

## D006 — Día civil = America/Bogota en toda la capa conversacional

DECISION: nxToday() (DateTimeZone America/Bogota) reemplaza gmdate() en chat.php, nxSemRange y nxSlots.
DATE: 2026-09-22.
PROBLEM: «hoy/ayer» en UTC desfasaba ±5h los filtros detected_at/event_timestamp, que el resto del sistema compara en Bogotá.
OPTIONS_CONSIDERED: Corregir en SQL (AT TIME ZONE); corregir en la capa de generación de rangos.
CHOSEN_APPROACH: Generación de rangos — las queries comparan fechas 'Y-m-d' ya resueltas; cambiar la fecha que se produce alinea chat con dashboard/workers sin tocar SQL.
WHY: Una sola fuente del «día civil» evita drift entre handlers.
TRADEOFF: Despliegues en otra zona horaria requerirían configurar nxToday — hoy la institución es colombiana por diseño.
AFFECTED_COMPONENTS: nexus_nlu.php (nxToday, nxSlots), nexus_semantic.php (nxSemRange), chat.php (day_summary, chatRange, group_summary, pending_tasks, whatsapp_status, student_field).
TESTS_SUPPORTING_DECISION: suites de conversación/DSM intactas; boundary probado por contrato (rangos idénticos a dashboard).

## D007 — Memoria con identidad estable, ítems PII una sola vez

DECISION: objects[] conserva id+type+entity+filters+count (sin ítems); los ítems viven solo en last_result; los R<N> son monotónicos (next_rid persistido).
DATE: 2026-09-22.
PROBLEM: Cada payload duplicaba hasta 3 veces los ítems con documentos/teléfonos (result_set + last_result + objects); los ids R<N> se reciclaban tras el trim, haciendo ambigua «vuelve al primero de 10A».
OPTIONS_CONSIDERED: Snapshot completo por objeto; solo identidad+filtros.
CHOSEN_APPROACH: Identidad+filtros — «vuelve a R3» re-ejecuta la consulta por sus filtros, no restaura un snapshot con PII envejecida.
WHY: Menos superficie de datos personales en historial y referencias inequívocas.
TRADEOFF: «Volver a un set viejo» devuelve datos actuales, no el snapshot del momento — coherente con read-only en vivo.
AFFECTED_COMPONENTS: chatBuildDs (objects, next_rid, person staleness).
TESTS_SUPPORTING_DECISION: real_conversation 103/103 con nav/referencias intactas.

## D008 — El cliente jamás decide operaciones

DECISION: El ctx de entrada se sanea: solo entidades visibles; _op, _ds, last_intent y result_set no llegan del cliente.
DATE: 2026-09-22.
PROBLEM: Sin _ds server-side (primer turno), el cliente podía inyectar entities._op fabricando un chip de confirmación de una operación nunca propuesta.
OPTIONS_CONSIDERED: Confiar con gates posteriores; sanitizar a whitelist.
CHOSEN_APPROACH: Whitelist — el ctx del cliente solo aporta entities minus prefijo '_'/_ds.
WHY: El estado conversacional es propiedad del servidor (payload_json._ds); el cliente solo respalda entidades de display.
TRADEOFF: Clientes antiguos que enviaban ctx completo pierden la herencia de último intent en el primer turno — el servidor ya es la fuente autoritativa por diseño.
AFFECTED_COMPONENTS: chat.php (rama ctx fallback).
TESTS_SUPPORTING_DECISION: real_conversation 103/103; clarificaciones intactas.

## Restricciones vigentes

- Solo READ automático; RBAC entre comprensión y ejecución.
- No SQL arbitrario generado por lenguaje.
- Memoria estructurada externa a la ventana del agente.
- Máximo 300 líneas por bloque de edición; conservar comentarios existentes.
- Commits pequeños con pruebas y estado actualizado; no push.
- No modificar controles de seguridad para superar fallos del entorno.
