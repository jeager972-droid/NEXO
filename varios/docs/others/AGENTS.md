# AGENTS.md — NEXO

Guía operativa para agentes y desarrolladores. Mantener actualizada.

## Verificación

| Qué | Comando |
|---|---|
| Sintaxis PHP | `php -l backend/api/routes/chat.php` (y cada archivo tocado de `backend/api/nexus/`) |
| DSM (diálogo) | `php test/dsm_units.php` |
| SCP (frame semántico) | `php test/scp_regression.php` |
| Eval semántico offline | `php test/semantic_eval.php` |
| Corpus real en vivo | `python3 test/e2e/corpus_eval.py --api http://localhost:18080 --email coord@test.nexo --file test/fixtures/real_corpus.txt` |
| Conversación multi-turno | `python3 test/e2e/chat_replay.py --api http://localhost:18080 --email coord@test.nexo --file test/fixtures/transcript_replay.txt` |
| Frontend | `cd frontend/pwa && npx vitest run` (flake conocido: `Input.test.jsx` timeout) |

Stack de pruebas: `test/e2e/docker-compose.test.yml`, proyecto `nexo-test`, API en `localhost:18080`,
credenciales de prueba `*@test.nexo` / `test1234`. Levantar:

```bash
cd test/e2e && docker compose -f docker-compose.test.yml --env-file env.test -p nexo-test up -d --build api
```

- El contenedor no monta el código: tras editar, `docker cp` o reconstruir con `--build`.
- OPcache tiene `validate_timestamps=Off`: tras `docker cp` hay que **reiniciar** el contenedor.
- Layout en el contenedor: `/var/www/html/routes`, `/var/www/html/nexus` (no `/api/...`).
- BD: `docker exec nexo-test-db-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'`.

## Parser LLM

El proveedor de pruebas es Groq (`NLU_LLM_URL`/`NLU_LLM_KEY`/`NLU_LLM_MODEL` en el compose);
producción usará un modelo propio. `NLU_LLM_REASONING_EFFORT`, `NLU_LLM_TIMEOUT_MS`,
`FASTCGI_READ_TIMEOUT` y `VITE_CHAT_TIMEOUT_MS` son configuración por entorno — los defaults
de producción quedan intactos.

---

# Plan de finalización del sistema Nexus

## Objetivo (el techo finito)

Que **todo dato institucional que exista en la base tenga un camino de consulta**, y que cualquier
pregunta en lenguaje natural —con contexto, referencias, derivaciones y correcciones— llegue a él por:

```
texto → LLM (entiende) → JSON frame validado → validador determinista → DSM (contexto)
      → SCP / guía discriminativa → handler (SQL read-only, RBAC) → composer → respuesta
```

El techo es finito y medible porque el dominio es finito: tablas × operaciones × filtros.
Lo que **no** es finito es el idioma; ahí el techo lo pone el modelo, y se sube en producción
cambiando de modelo **sin tocar código**.

## Diagnóstico de partida (medido, no supuesto)

Conversación real del usuario (16 turnos): **5/16 correctas**. Causas verificadas:

1. **LLM relegado.** Las reglas `strong` cortocircuitan al LLM (`nxClassifyCore`); cuando una regla
   se equivoca con seguridad, nadie la corrige. Con la cuota agotada todo lo no cubierto cae a
   `out_of_scope` en silencio — incluso «hola nexus como estas».
2. **Navegación secuestra preguntas nuevas.** «clasifica y ordena mis notificaciones» reordenó la lista
   anterior; «quién de estos es el que más ha evadido» respondió «era el último de la lista».
3. **Sin aclaraciones pendientes.** Tras «¿cuál Tomás?», «el de 10A» se interpretó desde cero (directivos).
4. **Sin grado como entidad.** «los décimos», «grupos de décimo», «grupos 10» → 10-A o el colegio entero.
5. **Colisiones de entidades.** «llegadas *tarde*» → jornada de la tarde; «notificación» → estudiante.
6. **Métricas autorreferenciales.** El 100% anterior medía coincidencia de etiquetas con un fixture
   ajustado a mano. No medía si la respuesta era correcta.
7. **Datos:** con sensores caídos, el detector marca a todos ausentes (526/526) y contamina asistencia.

## Fases

Cada fase cierra con sus pruebas en verde y commit. Estado: `[ ]` pendiente · `[~]` en curso · `[x]` hecho.

### F0 — Infra LLM `[x]`
- Groq es el proveedor de pruebas; producción usará un modelo propio (mismo contrato OpenAI-compatible).
- `NLU_LLM_REASONING_EFFORT` para modelos con razonamiento.

### F1 — Catálogo de datos y matriz de cobertura `[x]`
- Catálogo único (`nexus_catalog.php`): por entidad institucional → tabla(s), campos consultables,
  operaciones válidas (`list`, `count`, `detail`, `rank`, `trend`, `compare`, `field`), filtros
  (`grade`, `group`, `student`, `person`, `set_ref`, período, estado) e intent/handler que la atiende.
- Cada tabla del esquema queda **cubierta** o **fuera de alcance con motivo** (p. ej. `jwt_blocklist`,
  `rate_limits`, `schema_migrations`).
- `test/coverage_matrix.php`: falla si una celda declarada no tiene handler o si un handler responde
  con error para su caso mínimo.

### F2 — Contrato LLM → JSON `[x]`
- **Un solo esquema** de salida: `intent` (enum generado del registro de handlers — imposible inventar),
  `confidence`, `entities` tipadas (`student`, `group`, `grade`, `person`, `module`, `field`, `from`,
  `to`, `range_label`, `set_ref`, `order`, `limit`, `group_by`, `status`, `justified`, `compare`,
  `op`, `nav`, `clarify_answer`), `needs`, `uses_context`.
- Prompt compacto (mantenido a mano, ~1.1K tokens; `test/coverage_matrix.php` verifica que cada intent tenga entrada en el prompt — la paridad está garantizada por test); contexto: últimos turnos, entidades activas,
  último resultado (tipo, conteo, etiqueta) y **aclaración pendiente**.
- Validador determinista: allowlist de claves, coerción de tipos, descarte de basura
  (`field:"evadido"`), fusión con `nxSlots` (fechas, grupos, módulos), resolución de nombres en BD.

### F3 — LLM primero `[x]`
- Invertir `nxClassifyCore`: con LLM disponible, el LLM parsea siempre.
- Reglas solo para: **veto de seguridad** (siempre), **navegación pura** («siguiente», «la tabla»),
  **respaldo** cuando el LLM no responde, y **corrección de entidades**.
- Degradación visible: `nlu_source` en la traza, log y aviso cuando el LLM lleva caído N minutos.
- Snapshot de respuestas del LLM (`NX_CLASSIFY_FIXTURE`) para que las suites corran sin cuota.

### F4 — Diálogo `[x]`
- Navegación **solo** si el turno es navegación pura; un objeto o métrica nueva es consulta nueva.
- `set_ref` («de estos», «entre ellos», «de esa lista») = **filtro** por los IDs del último resultado.
- Aclaración pendiente: `chatAmbiguous` guarda `{intent, slots, candidatos}` en el DS; el turno
  siguiente que elija un candidato (grupo, ordinal, nombre) re-despacha con la entidad resuelta.
- Elipsis («y evasiones?», «y en 8B») hereda intent + alcance y cambia solo lo nombrado.

### F5 — Entidades y alcance `[x]`
- `grade` de primera clase en todos los handlers que aceptan `group` (helper de alcance compartido).
- Desambiguar colisiones: `tarde` (llegada vs jornada), sustantivos de dominio nunca son personas.

### F6 — Handlers: cerrar huecos de la matriz `[x]`
- `group_summary` multi-grupo (por grado); notificaciones por grupo/categoría/detalle; lo que F1 marque.
- Todo handler devuelve: `_facts` (para el composer), `_result_set` (para referencias y navegación),
  estado vacío honesto y `_entities` limpias.

### F7 — Composer `[x]`
- Conectado y listo (`NLU_LLM_COMPOSE=data`), alimentado por `_facts`, con guardia anti-alucinación.
- Apagado en local por CPU; encendido en producción.

### F8 — Evaluación honesta `[x]`
- Dataset de conversaciones reales (la del usuario primero, `chat_messages`, transcripts) con la
  **respuesta esperada**: intent + entidades clave + alcance, multi-turno.
- Partición **dev / test**: se corrige mirando dev; se reporta test. **Prohibido** editar expectativas
  para que coincidan con la salida.
- Métricas: correcta · aclaración (aceptable) · **incorrecta con seguridad (meta: 0)**.

### F9 — Calidad de datos `[x]`
- El detector de ausencias no marca «ausente» si los sensores no reportan: estado «sin datos».

### F10 — Cierre `[ ]`
- Documentación final, suites en verde, commit.

## Criterios de terminado (el techo alcanzado)

1. Matriz de cobertura: **100 %** de las celdas declaradas con handler y prueba.
2. Set de test real: **0 respuestas incorrectas con seguridad**; lo no entendido se aclara.
3. Set de test real: ≥ 90 % correctas con un modelo clase qwen3-8B (más alto con el de producción).
4. Con el LLM caído, el núcleo determinista sigue respondiendo y **lo dice**.
5. DSM, SCP y suites previas sin regresiones.
