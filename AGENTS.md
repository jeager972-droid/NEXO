# Información persistente para agentes

## Nexus

Leer NEXUS_LONGRUN_STATE.md y los otros seis NEXUS_* de la raíz antes de continuar el trabajo conversacional.
El baseline funcional está en ee12db93aa24bb2e068aceae4111c239b73e8bad; trabajo en nexus-longrun-20260922.
No declarar READY con suites simuladas; registrar fallos y limitaciones de ejecución.

## Restricciones de trabajo verificadas

- Máximo 300 líneas por bloque de edición; conservar comentarios existentes.
- No leer valores de dotenv/secretos ni operar datos de producción.
- El usuario eligió solo pruebas locales tras un error de permisos de bind mount Docker (2026-09-22).
- No alterar SELinux, controles de seguridad ni contenedores para sortearlo.
- No push. Commits pequeños con pruebas y memoria actualizada; no incluir trabajo ajeno.

## Verificación local

Desde la raíz:

```
NEXO_NLU_URL=http://127.0.0.1:9 php test/dsm_units.php
NEXO_NLU_URL=http://127.0.0.1:9 php test/nexus_capability_eval_v1.php
NEXO_NLU_URL=http://127.0.0.1:9 php test/real_conversation_v1.php
php test/readonly_guard.php
php test/resilience.php
backend/api/vendor/bin/phpunit --configuration test/phpunit.xml --testsuite 'API Unit Tests' --do-not-cache-result
```

El puerto :9 fuerza la ruta fallback PHP. Para usar el modelo Python real sin Docker, ejecutar desde backend/nlu:

```
python3 -u -c 'from service import Handler, HTTPServer; HTTPServer(("127.0.0.1", 8096), Handler).serve_forever()'
```

Después anteponer NEXO_NLU_URL=http://127.0.0.1:8096 a las suites PHP.
El servicio no recarga cambios automáticamente; reiniciarlo al modificar service.py/preprocess.py.
Las dependencias Python, PHPUnit y PWA/node_modules estaban disponibles al iniciar esta sesión.

Las pruebas PWA existentes se ejecutan con npm test desde PWA; npm run build verifica Vite.
continuity_50.php usa una API/BD real y escribe historial: NO ejecutarlo dentro del alcance local actual.
real_conversation_v1.php es una simulación NLU/DSM; no prueba SQL, API HTTP ni memoria persistida real.
