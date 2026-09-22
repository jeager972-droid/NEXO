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

## Checkpoints por construir

ARCHITECTURE, SEMANTIC CORE, CAPABILITY GRAPH, PLANNER, CONTEXT, GENERALIZATION, PRESENTATION, SECURITY, RESILIENCE, FINAL VALIDATION.
Cada checkpoint registrará hash, alcance real y resultados; no se marcará completado por compilación solamente.
