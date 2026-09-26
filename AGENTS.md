# Información persistente para agentes

## Nexus

La documentación canónica del sistema conversacional es `backend/api/nexus/README.md`;
la de cada componente vive en su README (`backend/api/`, `backend/edge/`,
`frontend/`, `frontend/pwa/`, `frontend/landing/`, `sql/`, `test/`).

La memoria de trabajo NEXUS_*.md de la raíz fue archivada en
`_cuarentena/memoria_nexus/` (reorganización 2026-09-26, commit 9b30742…)
pendiente de veredicto de borrado — leerla solo si se necesita historial
de decisiones/métricas de ciclos anteriores; sus cifras describen stacks
retirados. Para continuidad de sesión, el estado vigente lo dan los READMEs
+ `git log`.

El baseline funcional está en ee12db93aa24bb2e068aceae4111c239b73e8bad.
No declarar READY con suites simuladas; registrar fallos y limitaciones de ejecución.

## Restricciones de trabajo verificadas

- Máximo 300 líneas por bloque de edición; conservar comentarios existentes.
- No leer valores de dotenv/secretos ni operar datos de producción.
- El usuario eligió solo pruebas locales tras un error de permisos de bind mount Docker (2026-09-22).
- No alterar SELinux, controles de seguridad ni contenedores para sortearlo.
- No push. Commits pequeños con pruebas y memoria actualizada; no incluir trabajo ajeno.

## Verificación local

Desde la raíz (las suites sirven el parser vía fixture — ver abajo):

```
php test/dsm_units.php
php test/nexus_capability_eval_v1.php
php test/real_conversation_v1.php
php test/readonly_guard.php
php test/resilience.php
backend/api/vendor/bin/phpunit --configuration test/phpunit.xml --testsuite 'API Unit Tests' --do-not-cache-result
```

El clasificador TF-IDF+LR (servicio Python + modelo PHP) se retiró: el parser
del chat es el LLM (nexus/nexus_llm.php). Para las suites, las respuestas del
parser se sirven del snapshot `test/fixtures/llm_intents.json`
(NX_CLASSIFY_FIXTURE lo activan los propios entry points; `= ` vacío fuerza
el path real y gasta cuota API).

Para regenerar el fixture tras cambiar frases de las suites:

```
NX_CLASSIFY_LOG=/tmp/frases.txt NX_CLASSIFY_FIXTURE= php test/<suite>.php
sort -u /tmp/frases.txt > /tmp/frases_u.txt
NLU_LLM_KEY=... php test/gen_llm_fixture.php /tmp/frases_u.txt
```

Las eval suites grandes (op_eval, semantic_eval, blind_eval,
audit_single_errors) miden al parser mismo: correrlas contra el fixture es
circular — ejecutarlas en vivo (gastan cuota) cuando se evalúe calidad.
Las dependencias Python, PHPUnit y PWA/node_modules estaban disponibles al iniciar esta sesión.

Las pruebas PWA existentes se ejecutan con npm test desde frontend/pwa; npm run build verifica Vite.
continuity_50.php usa una API/BD real y escribe historial: NO ejecutarlo dentro del alcance local actual.
real_conversation_v1.php es una simulación NLU/DSM; no prueba SQL, API HTTP ni memoria persistida real.

## Estructura del repositorio (reorganización 2026-09-26)

- `backend/` — `api/` (PHP: rutas, `nexus/` = IA conversacional, lib, workers) y `edge/` (nodo C++).
- `frontend/` — `pwa/` (SPA principal), `landing/`, `prototipos/`, `design-philosophy/`.
- `docs/` — documentación canónica (la vieja `documentation/` se fundió aquí).
- `sql/` — schema, seeds, `deploy_db.sh`.
- `test/` — suites; datos JSON en `test/fixtures/`; dobles de prueba en `test/simulaciones/`.
- `pruebas/` — stack de integración Docker (api+db+redis); `prototipos/` se movió a `frontend/`.
- `tools/` — utilidades de repo (repomix).
- `_cuarentena/` — material pendiente de veredicto de borrado (no usar como fuente).
