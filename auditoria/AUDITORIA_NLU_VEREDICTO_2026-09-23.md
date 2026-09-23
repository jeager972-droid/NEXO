# Auditoría NLU/Chatbot NEXO — Veredicto y roadmap

> Fecha: 2026-09-23. Auditoría completa de documentación, arquitectura, código,
> benchmarks y comportamiento real del modelo. Incluye pruebas empíricas contra
> los dos modelos que existen en el repo.

---

## 1. VEREDICTO EJECUTIVO

El bot no comprende lenguaje natural por **tres causas compuestas**, en orden de
impacto:

### Causa raíz 1 — El modelo nunca vio lenguaje natural real
El "modelo de IA" es TF-IDF + Regresión Logística entrenado sobre un corpus
**100% sintético** (`backend/nlu/corpus.py` + `corpus_semantic.py`): ~526.660
ejemplos generados por expansión combinatoria de plantillas escritas a mano
(`FRAME × CONCEPT × ENTIDAD × WRAP`). TF-IDF es bolsa de palabras: no hay
sintaxis ni semántica; el modelo reconoce **similitud superficial con sus
propias plantillas**. El 99% de accuracy reportado mide acierto sobre la misma
distribución que generó los datos — es circular, no prueba comprensión.

### Causa raíz 2 — Producción corre un modelo distinto al evaluado
Existen dos artefactos divergentes:

| Artefacto | Fecha | Contenido |
|---|---|---|
| `backend/nlu/model/model.joblib` | 22-sep | V3.2+ con corpus acudiente/citación, 87 intents |
| `backend/api/nlu_runtime/model/model.joblib` | **21-sep** | V3.1, **86 intents** |

El Dockerfile de la API copia `backend/api/` como contexto y arranca
`nlu_runtime/service.py`. El script `backend/nlu/export_runtime.sh` que
sincroniza ambos árboles **no se volvió a ejecutar tras los re-entrenamientos
del 22-sep** (último commit sobre `nlu_runtime`: `28de59a`/`174cdb9`, 21-sep).
Las suites "live" que reportan 53/53 y 9/9 corrieron contra el stack
`nexo-test`, donde `NEXO_NLU_URL` apunta al contenedor NLU con el modelo
**nuevo**. **Todo lo que se mejoró el 22-sep no existe en producción.**

### Causa raíz 3 — Diseño con un techo estructural bajo y sin red de seguridad
- La comprensión real es la unión de: clasificador + `nxSlots` + ~50
  `nxCoverageOverride` + `nxSemanticResolve` + SCP + DSM. En total **~400+
  `preg_match`** repartidos en `nexus_nlu.php` (234), `nexus_semantic.php` (95),
  `nexus_scp.php` (46) y `chat.php` (37). Cada "arreglo" del 20-22 sep fue un
  regex nuevo para un caso del benchmark — *whack-a-mole*: la cola de frases
  naturales posibles es infinita.
- **Fallback PHP muerto en producción**: `nxPhpModel()` busca
  `nlu/model/model_php.json` en una ruta que **no existe dentro del contenedor**
  (el build context es `backend/api`). Si el servicio Python embebido cae —
  arranca una sola vez con `python service.py &`, **sin supervisión**, con
  `pip install scikit-learn` **sin versión fijada** (riesgo de incompatibilidad
  al deserializar un joblib entrenado con otra versión) — todo mensaje cae a
  `out_of_scope` con confianza 0. Y `/health` **no monitorea el NLU**: el API
  puede responder 200 mientras el bot está 100% ciego.

---

## 2. EVIDENCIA EMPÍRICA (prueba ejecutada en esta auditoría)

Mismas 10 frases en español natural (como las escribiría un rector) contra
ambos modelos en local:

| Frase natural | Prod (sep-21) | Nuevo (sep-22) | ¿Correcto? |
|---|---|---|---|
| necesito saber cuántos estudiantes hay en el colegio | `out_of_scope` 0.63 | `students_count` 0.99 | solo nuevo ✓ |
| me puedes decir quiénes faltaron hoy | `attendance_today` | `attendance_today` | ✓ ambos |
| cuántos alumnos tiene el grado sexto | `group_student_count` | `group_student_count` | ✓ ambos |
| quién es el acudiente del niño que llegó tarde | `late_today` | `late_today` | ✗ ambos (pide acudiente, no lista de tardanzas) |
| muéstrame el reporte de asistencia de esta semana | `out_of_scope` | `list_events` | solo nuevo ~ |
| cuál es el grupo con más problemas de convivencia | `attendance_ranking` | `attendance_ranking` | ✗ ambos (convivencia ≠ ranking de asistencia) |
| necesito contactar a los padres de los estudiantes de 8B | `out_of_scope` 0.24 | `students_in_group` | ✗ ambos (pide acudientes/contacto) |
| cuántas novedades se han presentado este mes | `count_events` | `count_events` | ✓ ambos |
| hay algún estudiante que no haya marcado entrada hoy | `out_of_scope` 0.19 | `out_of_scope` 0.39 | ✗ ambos |
| compara la asistencia del lunes con la del viernes | `out_of_scope` 0.35 | `attendance_today` | ✗ ambos |

**Resultado**: modelo en producción → **~50% de fallo a nivel de intent** en
lenguaje natural (antes de que cualquier otra capa pueda fallar). Modelo nuevo →
mejor cobertura pero **errores semánticos** (intento correcto ≠ objetivo del
usuario) y aún OOS en formulaciones negadas/relacionales.

Esto explica la experiencia reportada: "no comprende", "respuestas erradas o
fuera de lugar", "no tiene memoria" (si el turno N cae en OOS/clarify, el turno
N+1 no tiene nada útil que heredar — la memoria existe pero nunca se activa de
forma visible).

---

## 3. QUÉ FUNCIONA DE VERDAD (verificado)

- **Pipeline HTTP completo existe y es coherente**: `chat.php` autentica,
  carga `_ds`, clasifica, compone plan semántico, aplica RBAC, ejecuta SQL
  de solo lectura, persiste estado. No es humo: hay ~53 handlers reales.
- **Memoria server-side real**: `_ds` se serializa en
  `chat_messages.payload_json` y se recarga por `session_id` +
  `jsonb_exists`. El diseño (intent/entities/last_result/cursor/ordinales)
  es razonable. `session_id` existe en el esquema y RLS se setea por request.
- **Suites simuladas pasan**: `dsm_units` 60/60, `real_conversation_v1`
  103/103, `capability_eval` 152/152 — reproducido en local.
- **El evaluador live es legítimo**: `scp_live.php`/`heldout_live.php`
  verifican contenido real del reply contra la API+BD fixture — cuando el
  stack corre.
- **SCP/planner semántico** (`nexus_semantic.php`): registry de ~39
  capacidades con RBAC, validación estructural del plan, ejecutores SQL
  reales para ~12 capacidades. Diseño sano en principio.

## 4. QUÉ ESTÁ INFLADO O ROTO

| Afirmación documentada | Realidad verificada |
|---|---|
| "accuracy ~99%", "held-out 53/53" | Sobre corpus sintético propio. Blind limpio honesto (auditoría previa): **77%**; frases naturales mías: **~50-60%**. |
| "gate 21/21 READY" | Las puertas live (G18-G20) se **omiten silenciosamente** si la API no responde; 21/21 solo se logró en la ventana con Docker arriba. |
| "fallback PHP operativo" | En el contenedor la ruta del `model_php.json` no existe → fallback es `source=none` → OOS total. Solo funciona en el árbol de desarrollo. |
| "memoria conversacional" | Implementada, pero los paths `clarify`/`denied`/`repeat`/fallback multi-parte **no escriben `_ds`**; y con 50% de OOS el usuario percibe "sin memoria". |
| "comprende lenguaje natural" | Comprende las ~87 plantillas y su vecindad léxica. Fuera de eso: abstención o intento errado. |

## 5. POR QUÉ PASÓ (post-mortem del proceso)

1. **Se optimizó el benchmark, no el producto.** Cada iteración: redactar
   frases de eval → fallar → agregar plantilla/regex → pasar. El set de
   evaluación lo escribió el mismo proceso que escribió el corpus → misma
   distribución → métricas infladas que "pasaban de forma exitosa" mientras el
   usuario real veía un bot que no entendía.
2. **Divergencia de artefactos sin control.** Dos árboles de runtime
   (`backend/nlu` vs `backend/api/nlu_runtime`), sincronización manual por
   script, sin hash ni versión expuesta, sin health del NLU. Nadie notó que el
   deploy corría el modelo viejo.
3. **Deuda arquitectónica acumulada**: 5 capas de inferencia solapadas
   (clasificador, overrides, rerank semántico, SCP, DSM) → cada bug puede
   originarse en cualquiera → depuración imposible → más parches.
4. **Sin red de seguridad en producción**: servicio embebido no supervisado,
   fallback PHP con ruta rota, `/health` que no reporta NLU, dependencias
   Python sin pin.

## 6. ROADMAP — para que el bot realmente entienda

### Fase 0 — Honestidad del estado actual (1 sesión)
1. Ejecutar `export_runtime.sh` y redeployar: producción debe correr el modelo
   del 22-sep. Verificar con un endpoint que reporte **versión/hash del modelo
   y del preprocess** cargados.
2. Agregar NLU al `/health` (liveness del :8090 + hash del modelo).
3. Fijar `scikit-learn`/`joblib`/`numpy` a las versiones exactas de
   entrenamiento en `nlu_runtime/requirements.txt`.
4. Supervisar el servicio embebido (restart on crash) y corregir la ruta del
   `model_php.json` para que el fallback PHP exista de verdad.

### Fase 1 — Evaluación honesta (base de todo lo demás)
5. Construir un **set de evaluación real**: 200-500 mensajes escritos por
   usuarios reales (o parafraseados por personas distintas al autor del
   corpus), etiquetados a mano. Prohibido reutilizar vocabulario del corpus.
6. Medir: % intent correcto, % respuesta semánticamente correcta, % OOS,
   % memoria correcta en conversaciones de ≥3 turnos. Publicar el número
   honesto (hoy estimo ~40-55%).
7. Grabar tráfico real anonimizado del chat para alimentar el set.

### Fase 2 — Decisión arquitectónica (la encrucijada real)
El objetivo "comprender lenguaje natural al 100%" **no es alcanzable** con
TF-IDF+LR+regex — ningún clasificador cerrado lo es. Opciones:

- **A. LLM como parser semántico** (recomendada): un LLM (API o local)
  traduce texto libre → el **mismo plan IR verificable** que ya existe
  (`nxSemanticCompose` → capability → SQL). El LLM no toca la BD ni inventa
  datos: solo mapea lenguaje→estructura. Mantiene RBAC, read-only y la
  presentación actuales. Es el cambio que más acerca al objetivo del usuario
  con menor reescritura: la mitad derecha del pipeline (SCP→planner→RBAC→SQL
  →tarjetas) ya está construida y es buena.
- **B. Embeddings + taxonomía**: sentence-embeddings multilingües +
  clasificador sobre ejemplos canónicos + slots por LLM/regex. Menos
  capacidad de composición que A, más barato/predecible.
- **C. Seguir con templates+regex**: viable solo si se acepta un dominio muy
  acotado y se invierte en corpus real. No llega al objetivo declarado.

### Fase 3 — Memoria y conversación
8. Persistir `_ds` en **todos** los paths de salida (clarify, denied, repeat,
   fallback) o mover `_ds` a una columna/tabla de sesión separada del payload
   del mensaje.
9. Test e2e de memoria con conversaciones reales (no guionizadas): correcciones,
   ordinales, "los demás", cambios de tema y regreso.

### Fase 4 — Reducción de deuda
10. Eliminar `nxCoverageOverride` a medida que el parser semántico cubra sus
    casos (cada override es un síntoma de que el clasificador falló).
11. Una sola autoridad de interpretación: hoy compiten clasificador,
    overrides, rerank y SCP. Con LLM/embeddings, el pipeline queda:
    `texto → parser → plan → validación → RBAC → SQL`.

### Criterio de "terminado" honesto
No "100% comprensión" (no existe), sino: ≥85% de respuestas correctas sobre el
set real, abstención elegante en lo desconocido, memoria verificable en
conversaciones reales, y modelo/versionado/health observables en producción.

---

## 7. IMPLEMENTADO EL MISMO DÍA (post-veredicto)

Correcciones aplicadas tras la auditoría (decisión del usuario: parser LLM
externo gratuito, sin usar su PC como servidor):

| Cambio | Archivo | Efecto |
|---|---|---|
| Parser LLM agnóstico | `backend/api/lib/nexus_llm.php` (nuevo) | OpenAI-compatible → intent+entidades; whitelist de taxonomía; `nxSlots` sigue mandando en slots estructurales |
| Cableado en clasificador | `nexus_nlu.php` → `nxClassifyCore()` | Modos `off/fallback/primary` vía `NLU_LLM_MODE`; sin key → comportamiento idéntico (regresión verificada: dsm 60/60, real_conv 381/381) |
| NLU supervisado | `docker-entrypoint.sh` | El servicio embebido ahora reinicia con backoff (antes moría en silencio) |
| Health del NLU | `health.php` | `nlu` y `llm` reportados; NLU caído ⇒ HTTP 503 |
| Runtime sincronizado | `export_runtime.sh` ejecutado | `nlu_runtime/` ahora lleva el modelo 22-sep (87 intents) + preprocess actual |
| Deps fijadas | `requirements.txt` (ambos árboles) | `scikit-learn==1.9.1 numpy==2.4.6 scipy==1.16.2 joblib==1.6.0` — evita incompatibilidad al deserializar |
| Config documentada | `.env.example` | vars `NLU_LLM_*` |
| Sonda de lenguaje natural | `test/llm_probe.php` | 20 frases reales → baseline sin LLM: **12/20** (modelo nuevo :8096) |

### Resultado medido con LLM (Groq `qwen/qwen3.8-27b`, modo `primary`)

- **15-16/20** intents correctos vs **12/20** baseline — y lo decisivo: los
  errores graves desaparecen («acudiente del niño que llegó tarde» →
  `student_field`✓, «no haya marcado entrada» → `attendance_today`✓, ambos
  fallaban antes). Los residuales son abstenciones honestas (no existe
  capacidad de tardanzas-de-docentes) o alternativas razonables.
- Latencia ~0,4 s por llamada.
- Límites free medidos: **1.000 req/día · 8.000 tokens/min** (≈10 llamadas/min
  con el prompt comprimido a ~600 tokens). Si se agota la cuota o Groq cae →
  degrada al clasificador local automáticamente (diseño de `nxLlmRefine`).

### Configuración activa (Render + local)

```
NLU_LLM_URL=https://api.groq.com/openai/v1
NLU_LLM_KEY=gsk_...   (en backend/api/.env local; en Render: dashboard env)
NLU_LLM_MODEL=qwen/qwen3.8-27b
NLU_LLM_MODE=primary
NLU_LLM_TIMEOUT_MS=6000
NEXO_NLU_URL=http://localhost:8090   (embebido; en Render no hace falta)
```

## 8. LIMITACIONES DE ESTA AUDITORÍA

- No se pudo autenticar contra producción (sin credenciales en alcance): el
  comportamiento en vivo del bot desplegado es una **inferencia fuerte**
  (modelo viejo + fallback roto + servicio no supervisado), no una medición
  directa. La salud de prod muestra `redis:false` (solo afecta rate-limit).
- Las suites live (API+BD real) no se ejecutaron: el stack Docker está
  detenido y el usuario eligió solo pruebas locales tras fallos de bind-mount.
- `continuity_50.php` no se corrió (requiere API/BD real y escribe historial).
- No se modificó código ni se re-entrenó nada: esto es diagnóstico.
