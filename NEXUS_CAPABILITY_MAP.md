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

- Validación no contrasta required_parameters genéricos ni elección de executor delegado.
- nxPlanExecuteStep solo reconoce delegación si el plan trae exec/_delegate_intent; el registry por sí solo no basta.
- attendance.today, trackings.active y operations.derive declaran múltiples intents; falta selección inequívoca del executor.
- Los límites SQL (p. ej. students LIMIT 400) también afectan conteos y cardinality=all.
- Los filtros declarados no prueban que el executor los aplique; hace falta inventario de contratos verificable.
