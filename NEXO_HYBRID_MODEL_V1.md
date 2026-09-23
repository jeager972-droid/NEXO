# NEXO Hybrid Model v1.0 — LLM + NLU determinista

> Estado: implementado (2026-09-23). Arquitectura de dos etapas LLM sobre el
> motor determinista NEXO. Documento de referencia para el trabajo futuro.

## 1. La idea en una frase

El LLM nunca toca los datos ni decide la verdad. Solo hace las dos cosas para
las que un modelo lingüístico es bueno: **interpretar** lo que el usuario dijo
y **expresar** lo que el sistema verificó.

```
Usuario ──► LLM #1 PARSER          ──► intent + entidades (JSON)
              │                      (sin key / caído → out_of_scope honesto)
              ▼
        DSM → SCP → Planner → RBAC → SQL read-only
              │                          (la verdad: solo NEXO la conoce)
              ▼
        VerifiedResult (reply determinista + cards + rows)
              │
              ▼
        LLM #2 RESPONSE COMPOSER ──► reply en español natural
              │
              ▼
            UI Nexus
```

## 2. Componentes

### LLM #1 — Parser semántico (`nxLlmClassify`)
Archivo: `backend/api/lib/nexus_llm.php`. **Es EL clasificador** — el stack
TF-IDF+LR (servicio Python, modelo PHP, reranker léxico, overrides) se
retiró: era la fuente de la deuda sintética (~526K ejemplos de plantilla,
~400 regex de parcheo). Ver auditoría.

- API compatible-OpenAI (`/chat/completions`), provider agnóstico por env.
- Salida: `{"intent","confidence","entities"}`. `intent` validado contra la
  taxonomía (whitelist `NX_LLM_FORMAL ∪ NX_LLM_INFORMAL`); un intent fuera
  de lista → `out_of_scope`.
- `nxSlots` (determinista) sigue mandando en slots estructurales: el LLM solo
  rellena huecos (nombres, campo pedido, persona).
- Entra por `nxClassifyCore()` en `nexus_nlu.php` (`source: 'llm'`).
- Degradación: sin `NLU_LLM_KEY`, `NLU_LLM_MODE=off` o proveedor caído →
  `out_of_scope` → flujo de clarificación honesto. Nunca intent inventado.

Modos (`NLU_LLM_MODE`): `off` | `on` (default; `primary`/`fallback` se
aceptan como `on` por compatibilidad de env).

### LLM #2 — Response Composer (`nxLlmComposeReply`)
Mismo archivo. Se engancha en `chatLog` **por referencia** (`array &$out`):
cubre los ~22 caminos de salida sin tocar cada handler; el reply compuesto se
persiste y se devuelve.

- Recibe: `user_text`, `intent`, `verified_reply`, meta (conteos de
  cards/result_set, denied).
- Devuelve `{"reply":"..."}`. Reglas duras: no inventar datos/nombres/cifras,
  no valorar ("excelente noticia"), no contradecir, conservar opciones en
  aclaraciones.
- Las tarjetas, filas, tablas y números los renderiza el pipeline — el modelo
  **nunca** los toca (un LLM puede cambiar un nombre o un documento; un
  renderer determinista no).
- Modos (`NLU_LLM_COMPOSE`): `off` (default) | `data` (solo intents
  operativos + clarify — smalltalk ya suena natural y ahorra cuota) | `all`.

### Motor determinista (sin cambios)
DSM (`nxDialogueResolve`), SCP, `nxSemanticCompose`, capability registry,
RBAC, ejecutores SQL read-only, `chatDispatch`. La memoria `_ds` sigue en
`chat_messages.payload_json`.

### Lo que se retiró (deuda sintética)
Servicio Python embebido + modelo PHP + `nxCoverageOverride` +
`nxSemanticResolve`/`NX_INTENT_LEXICON` + temporal-guard: ~630 líneas de
`nexus_nlu.php`, `backend/nlu` (723MB de corpus/entrenamiento),
`backend/api/nlu_runtime` (84MB), Python/scikit-learn del Dockerfile.
`/health` ahora reporta `llm` como dependencia crítica.

Lo que SÍ se quedó de esa capa (no era deuda): `nxNorm`, `nxSlots`
(entidades/fechas deterministas), `nxRegions`/`nxIsForeign`, smalltalk,
`nxAllowed` (RBAC), `nxDialogueResolve` completo (correcciones, herencia de
slots, navegación de resultados, referencias deícticas, multi-intent) y el
`parts` multi-segmento de `nxClassify`.

## 3. Configuración

```
NLU_LLM_URL=https://api.groq.com/openai/v1     # cualquier compatible-OpenAI
NLU_LLM_KEY=gsk_...                            # vacío ⇒ LLM off total
NLU_LLM_MODEL=qwen/qwen3.8-27b
NLU_LLM_MODE=on                                # off|on
NLU_LLM_COMPOSE=data                           # off|data|all
NLU_LLM_TIMEOUT_MS=6000
```

Local: `backend/api/.env` (gitignored). Render: dashboard → Environment.

## 4. Cuota Groq free (medida en headers)

`qwen/qwen3.8-27b`: **1.000 req/día · 8.000 tokens/min** (~10 llamadas/min con
el prompt de ~600 tok). Con parser + `compose=data`: ~2 llamadas por turno
de datos → **~500 turnos/día** de margen. Si se agota la cuota o Groq falla:
el parser devuelve `out_of_scope` honesto (clarificación) y el composer pasa
el reply original — degradación segura, nunca un error ni un intent inventado.

## 5. Resultados medidos (sonda de 20 frases naturales, `test/llm_probe.php`)

| | Clasificador TF-IDF (retirado) | LLM parser |
|---|---|---|
| Intent correcto | 12/20 | **16/20** |
| Errores graves (conf alta, intent errado) | 4 | **0** |
| out_of_scope | 1 | 1 (capacidad inexistente) |
| Latencia/llamada | ~30 ms | ~0,4 s |

Composer (verificado manual): «10-A tiene 21 estudiante(s) activos.» → «En el
grupo 10-A hay 21 estudiantes activos.»; aclaraciones conservan candidatos
exactos; jokes intactos en modo `data`.

## 6. Qué NO hace (diseño a propósito)

- El LLM no escribe SQL ni ve la base de datos.
- No puede emitir intents fuera de la taxonomía.
- No toca cards/rows/tables.
- No decide autorización: RBAC se aplica después del parse igual que antes.
- Inyección de prompt solo puede mover el intent a `security_probe` u
  `out_of_scope` — nunca a una acción de escritura (no existen en el chat).

## 7. Trabajo pendiente (roadmap)

1. Deploy: push → Render (el modelo sync, entrypoint, health y las dos capas
   LLM viajan en este commit).
2. `_ds` en todos los paths de salida (clarify/denied/repeat no lo escriben).
3. Set de evaluación real (frases de usuarios reales, no del corpus).
4. Pasar contexto de conversación (`_ds` resumido) al prompt del parser para
   resolver referencias vagas por sí solo (hoy lo hace el DSM determinista).
5. Si la cuota se queda corta: caché de consultas repetidas, o
   `openai/gpt-oss-120b`/`gpt-oss-20b` (misma cuenta, límites distintos).
6. Migración futura a servidor propio: mismo contrato (SemanticFrame /
   VerifiedResult / ResponseEnvelope), solo cambia `NLU_LLM_URL`.

## 8. Verificación

- `test/llm_probe.php` — sonda de lenguaje natural en vivo (gasta cuota).
- `test/fixtures/llm_intents.json` — snapshot de respuestas REALES del parser
  para las frases de las suites de pipeline (`NX_CLASSIFY_FIXTURE` lo activan
  los entry points). Regenerar: `NX_CLASSIFY_LOG` + `test/gen_llm_fixture.php`
  (ver AGENTS.md). Las eval suites de calidad del parser (op_eval,
  semantic_eval, blind_eval, audit_single_errors) corren en vivo, no contra
  el fixture.
- Suites contra el fixture: `dsm_units`, `real_conversation`, `resilience`,
  `capability_eval`, `scp_regression`, `phpunit`, `readonly_guard`.
