# NEXO Hybrid Model v1.0 — LLM + NLU determinista

> Estado: implementado (2026-09-23). Arquitectura de dos etapas LLM sobre el
> motor determinista NEXO. Documento de referencia para el trabajo futuro.

## 1. La idea en una frase

El LLM nunca toca los datos ni decide la verdad. Solo hace las dos cosas para
las que un modelo lingüístico es bueno: **interpretar** lo que el usuario dijo
y **expresar** lo que el sistema verificó.

```
Usuario ──► LLM #1 PARSER          ──► intent + entidades (JSON)
              │
              ▼
        Clasificador TF-IDF local (respaldo/validación cruzada)
              │
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

### LLM #1 — Parser semántico (`nxLlmClassify`, `nxLlmRefine`)
Archivo: `backend/api/lib/nexus_llm.php`.

- API compatible-OpenAI (`/chat/completions`), provider agnóstico por env.
- Salida: `{"intent","confidence","entities"}`. `intent` validado contra la
  taxonomía (whitelist `NX_LLM_FORMAL ∪ NX_LLM_INFORMAL` — espejo de
  `backend/nlu/domains.py`); un intent fuera de lista → `out_of_scope`.
- `nxSlots` (determinista) sigue mandando en slots estructurales: el LLM solo
  rellena huecos (nombres, campo pedido, persona).
- Entra por `nxClassifyCore()` en `nexus_nlu.php` → mismo contrato que
  `nxClassifyService`/`nxClassifyLocal` (`source: 'llm'`).

Modos (`NLU_LLM_MODE`):
- `off` o sin `NLU_LLM_KEY` → comportamiento idéntico al sistema anterior.
- `fallback` → el LLM solo rescata cuando el clasificador local falla o queda
  débil. ~70% menos cuota que primary.
- `primary` → el LLM parsea todo; lo local es respaldo si Groq cae.

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

### Clasificador TF-IDF local (respaldo)
Servicio Python embebido (:8090) + modelo PHP. Ahora **supervisado** en
`docker-entrypoint.sh` (reinicio con backoff) y reportado en `/health`
(`nlu`, `llm`). Runtime sincronizado: `nlu_runtime` = modelo 22-sep, 87
intents; deps fijadas (`scikit-learn==1.9.1` …).

## 3. Configuración

```
NLU_LLM_URL=https://api.groq.com/openai/v1     # cualquier compatible-OpenAI
NLU_LLM_KEY=gsk_...                            # vacío ⇒ LLM off total
NLU_LLM_MODEL=qwen/qwen3.8-27b
NLU_LLM_MODE=primary                           # off|fallback|primary
NLU_LLM_COMPOSE=data                           # off|data|all
NLU_LLM_TIMEOUT_MS=6000
NEXO_NLU_URL=http://localhost:8090             # embebido; opcional
```

Local: `backend/api/.env` (gitignored). Render: dashboard → Environment.

## 4. Cuota Groq free (medida en headers)

`qwen/qwen3.8-27b`: **1.000 req/día · 8.000 tokens/min** (~10 llamadas/min con
el prompt de ~600 tok). Con `primary` + `compose=data`: ~2 llamadas por turno
de datos → **~500 turnos/día** de margen. Si se agota la cuota o Groq falla:
el parser cae al clasificador local y el composer devuelve el reply original
— degradación silenciosa, nunca un error al usuario.

## 5. Resultados medidos (sonda de 20 frases naturales, `test/llm_probe.php`)

| | Sin LLM (modelo 22-sep) | Con LLM primary |
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
5. Si la cuota se queda corta: `NLU_LLM_MODE=fallback`, caché de consultas
   repetidas, o `openai/gpt-oss-120b`/`gpt-oss-20b` (misma cuenta).
6. Migración futura a servidor propio: mismo contrato (SemanticFrame /
   VerifiedResult / ResponseEnvelope), solo cambia `NLU_LLM_URL`.

## 8. Verificación

- `test/llm_probe.php` — sonda de lenguaje natural (con/sin LLM).
- Suites intactas con LLM off: `dsm_units` 60/60, `real_conversation` 381/381,
  `resilience` 15/15 (se arregló crash preexistente de contexto corrupto),
  `capability_eval` 153/153, `phpunit` 244/244, `readonly_guard` ✓.
