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

## Restricciones vigentes

- Solo READ automático; RBAC entre comprensión y ejecución.
- No SQL arbitrario generado por lenguaje.
- Memoria estructurada externa a la ventana del agente.
- Máximo 300 líneas por bloque de edición; conservar comentarios existentes.
- Commits pequeños con pruebas y estado actualizado; no push.
- No modificar controles de seguridad para superar fallos del entorno.
