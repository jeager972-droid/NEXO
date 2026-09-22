# Nexus — mapa persistente de capacidades

Estado: inventario pendiente de ejecución; no repetir cifras de informes anteriores como hechos actuales.
Fuente primaria inicial: nxCapabilityRegistry() en backend/api/lib/nexus_semantic.php.
Comparación requerida: rutas reales, esquema SQL, handlers, permisos, UI y tests.

## Contrato del inventario

Cada capacidad debe registrar: capability, goal, entity, relation, operation, fields, filters, sorting, aggregation, position, slice, presentation, scope, RBAC, executor, endpoint y query.
Estados permitidos: FULL, PARTIAL, LEGACY, MISSING, INVALID, DUPLICATE.
FULL requiere evidencia de ejecución y controles, no únicamente una entrada en el registry.

## Grafo de entidades

Por entidad: name, identifier, fields, relationships, foreign_keys, scope, permissions, searchability, sortability, aggregations y derived_information.
Los campos ausentes o ambiguos se marcarán como no verificados, no se inventarán.

## Clasificación de efectos

READ puede ejecutarse automáticamente tras validación y RBAC.
WRITE, DELETE y EXTERNAL_SIDE_EFFECT solo pueden explicar, navegar o sugerir en esta capa.

## Línea base

BD local consultada antes de la restricción de integración: 132 tablas public y 91 claves foráneas.
Inventario desplegado: 24 archivos de rutas; 53 handlers; 39 capabilities (12 semánticas, 27 delegadas).
Estos números NO prueban cobertura: el inventario confunde tablas con entidades y relaciones entre capacidades con FK.
El script busca biometric_devices, audit_log/audit_logs y notification_queue, aunque el registry referencia edge_devices, global_audit_logs y notifications.
Cobertura conversacional sobre capacidades de NEXO: NO DEMOSTRADA.

## Gaps ya localizados en la superficie declarativa

- ~~Validación no contrasta required_parameters genéricos ni elección de executor delegado~~ **RESUELTO** (nxPlanValidate genérico + NexusPlanInvariantTest 34).
- ~~nxPlanExecuteStep solo reconoce delegación si el plan trae exec/_delegate_intent~~ **RESUELTO** (delegación re-verifica intents declarados, normaliza `a|b`).
- ~~attendance.today/trackings.active/operations.derive con múltiples intents~~ **RESUELTO** (selección entre intents declarados + re-auth).
- ~~Límites SQL LIMIT 400 en conteos~~ **RESUELTO** (count/percent usan COUNT real; ordinales acotados a ventana).
- ~~`related`/`nearby` con 17 refs colgantes~~ **RESUELTO** (0 refs a capacidades inexistentes tras barrido).
- Los filtros declarados no prueban que el executor los aplique; hace falta inventario de contratos verificable — **siguiente**: `$entityTables` corregido en ecosystem_inventory (corre con PDO real, fuera de alcance local).

## Estado del grafo (post-auditoría)

- 39 capacidades; 12 con ejecutor SQL propio; 27 delegadas a intents `chat_*` con re-verificación RBAC.
- `related`/`nearby` son pistas de navegación, no ejecutables — ahora todas apuntan a capacidades existentes.
- `result_nav` (DSM, no capability) removido del grafo.
