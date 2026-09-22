# Nexus — memoria persistente de ejecución

CURRENT_PHASE: AUDIT
CURRENT_OBJECTIVE: Verificar arquitectura y cobertura reales, establecer línea base y reproducir fallos antes de modificar producción.
LAST_SUCCESSFUL_MILESTONE: Línea base local medida en PHP fallback y servicio Python local; rama nexus-longrun-20260922 preserva main.
CURRENT_FAILURE: Conversaciones simuladas 88/103 completas con fallback, 101/103 con Python; integración actual no autorizada.
ROOT_CAUSE: Divergencia de resolución NLU/DSM; además existen validación débil de dependencias, memoria con IDs reciclados y aserciones vacías por reproducir.
FILES_CHANGED: Siete archivos de memoria NEXUS_* y AGENTS.md; tareas de pruebas/fixes acotadas en curso.
TESTS_PASSED: API PHPUnit 210 tests/880 assertions; DSM 50/50; readonly 53 handlers; resiliencia 15/15; composición declarativa 59/59; capacidades Python 154/154.
TESTS_FAILED: Fallback capacidades 152/153; conversaciones fallback 88/103 y Python 101/103; release gate aún en ejecución (G1 falla).
KNOWN_REGRESSIONS: Fallos anteriores son baseline de ee12db9, no se han ocultado ni cambiado umbrales.
NEXT_ACTION: Añadir invariantes fallidos para validación de planes, dependencias y memoria; corregir por familias y ejecutar regresión local con ambas rutas NLU.
ARCHITECTURAL_DECISIONS: Preservar implementación y tests existentes; cambios mínimos respaldados por reproducciones; solo READ automático.
OPEN_QUESTIONS: Cobertura real del ecosistema, aislamiento del entorno de pruebas, dependencia de BD/servicios, integridad de memoria y planes compuestos.

## Checkpoint inicial

- Fecha: 2026-09-22.
- Repositorio: /home/john/proyectos/NEXO.
- HEAD inicial: ee12db93aa24bb2e068aceae4111c239b73e8bad.
- Rama inicial: main; sin cambios staged ni unstaged.
- No se leerán valores de dotenv, credenciales ni datos personales para el inventario.
- No se ejecutarán migraciones, operaciones destructivas ni acciones externas sin aprobación específica.
- No se hará push.

## Recuperación

1. Leer estos siete archivos de memoria y las reglas del repositorio.
2. Inspeccionar git status y HEAD; preservar cambios ajenos.
3. Ejecutar las pruebas mínimas documentadas en NEXUS_TEST_MATRIX.md.
4. Continuar desde NEXT_ACTION; no reiniciar auditorías ya verificadas.
5. Cada edición contendrá como máximo 300 líneas y conservará los comentarios existentes.

FINAL_STATE: NOT_READY — pendiente de evidencia integral, no confundir benchmarks con cobertura de producto.
