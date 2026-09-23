# Nexus — Cerebro Conversacional de NEXO

Documento único: qué es, cómo funciona, por qué está diseñado así y qué contiene.

---

## 1. Qué es

Nexus es el asistente conversacional institucional de NEXO. Convierte lenguaje
natural en español (con tildes, typos, jerga colombiana, abreviaturas y
coloquialismos) en consultas verificables sobre los datos reales del colegio:
estudiantes, grupos, acudientes, docentes, asistencia, tardanzas, evasiones,
permisos, citaciones, seguimientos, dispositivos, notificaciones, horarios,
riesgo y auditoría.

No es un chatbot de respuestas memorizadas ni un generador de texto: es un
intérprete semántico conectado a ejecutores reales. Todo dato que responde sale
de PostgreSQL vía consultas parametrizadas, filtradas por `school_id` (tenant)
y por el alcance del rol del usuario.

---

## 2. Principios de diseño (el porqué)

1. **El modelo interpreta, NEXO ejecuta.** Ningún componente de lenguaje
   genera SQL, decide permisos ni inventa datos. La autoridad final es
   siempre determinista.
2. **Significado antes que intent.** La unidad de interpretación no es una
   etiqueta de intención sino un *frame semántico estructurado*: qué tarea,
   sobre qué dominio, con qué sujeto, relación, filtros, alcance, tiempo,
   métrica y presentación.
3. **El estado conversacional vive en el servidor.** El cliente no decide
   contexto: solo aporta entidades visibles; el `_ds` persistido es la fuente.
4. **Read-only por construcción.** El canal conversacional solo lee; las
   operaciones mutativas se derivan a chips de navegación hacia la UI formal.
5. **Honestidad obligatoria.** Ambigüedad real → aclaración; sin datos →
   "no hay"; resultado que no cumple el plan → fallo explícito, nunca una
   respuesta parcial que parezca correcta.

---

## 3. Pipeline de un turno

```
USUARIO
  → NORMALIZACIÓN (nxNorm: minúsculas, tildes fuera, espacios)
  → NLU (clasificador TF-IDF+LogisticRegression — fast path / candidato)
  → DIALOGUE RESOLVER (nxDialogueResolve: referencias, herencia, nav,
     correcciones, desambiguación por contexto)
  → SCP — SEMANTIC CONVERSATIONAL PARSING (nxScpFrame + validación)
       autoridad de interpretación: puede forzar la tarea
  → CORRECCIONES (si el frame dice 'correct': mutan plan/contexto activo)
  → COMPOSE / PLANNER (capability + entity + op + filters + position +
     steps compuestos para multi-goal)
  → nxPlanValidate (estructura del plan)
  → nxPlanAllowed (RBAC por capacidad y rol)
  → EXECUTORS (SQL parametrizado real o intents chat_* delegados
     con re-verificación)
  → nxResultValidate (contrato post-ejecución: grupo/módulo/tiempo/
     sujeto/posición ejecutados = pedidos)
  → PRESENTACIÓN (reply + cards + acciones + lineage)
  → _ds tipado persistido en payload_json del mensaje
USUARIO
```

El NLU nunca se impone cuando el frame semántico decide otra cosa: el fast
path solo sirve cuando no contradice el significado.

---

## 4. Componentes

### 4.1 Normalización y extracción (`backend/api/lib/nexus_nlu.php`)

- `nxNorm`: normalización compartida PHP↔Python (sin tildes, minúsculas).
- `nxSlots`: slot-filling determinista — grupos (`10A`, `6A`, `octavo`,
  `8-C`), módulos por sinónimos con **borde de palabra**, campos de
  estudiante (`documento`, `acudiente`, `celular`, `grupo`…), alcance
  (`mis grupos`, `del colegio`), excepciones (`excepto el 8A`).
- `nxClassify`/`nxClassifyFallback`: TF-IDF+LR por HTTP al servicio Python
  (`backend/nlu/service.py`, `corpus.py`, `preprocess.py`) o modelo PHP
  local si el servicio no responde. Produce intent + confianza + top3.

### 4.2 Dialogue Resolver (`nxDialogueResolve`)

Resuelve el turno contra el `_ds` previo:

- **Herencia contextual**: turnos dependientes («y», «ahora», deícticos)
  heredan student/group/module/days del contexto; turnos nuevos no
  arrastran nada.
- **Navegación de result-set**: `el primero|segundo|último|anterior|
  siguiente|los demás|todos|en tabla|solo nombres|con documento|
  ordénalos por apellido|los 3 primeros` → `_nav` + cursor.
- **Posición sin set**: ordinal desnudo tras conteo de grupo hereda
  group/module → el planner materializa `students.position`.
- **Conteos contextuales**: «cuántos son» sobre set de estudiantes →
  `group_student_count`; con sustantivo-persona en el turno + grupo →
  idem; con módulo → `count_events`.
- **Extracción de nombres**: patrones con stopwords institucionales —
  verbos de consulta («compara»), unidades temporales («último mes») y
  ordinales nunca se toman por apellidos.
- **Correcciones léxicas** alimentan el frame (`me refiero`, `no era`,
  `mejor`, `solo cinco`…).

### 4.3 SCP — Semantic Conversational Parsing (`nexus_scp.php`)

La **autoridad de interpretación**. `nxScpFrame` produce el contrato:

```json
{
  "task": "lookup|count|aggregate|compare|rank|filter|relation|
           navigate|transform|correct|general",
  "domain": "students|groups|guardians|teachers|incidents|schedules|…",
  "subject": {"name","entity","source","reference"},
  "relation": "guardian|schedule_of_group|teachers_of_group|…",
  "field": "documento|acudiente|celular|horario|…",
  "targets": [], "filters": {"group","group2","module","student","status"},
  "scope": {"kind": "school|group|teacher|student","label"},
  "time_range": {"days","from","to","label"},
  "aggregation": "count|percent",
  "ranking": {"metric","order","limit"},
  "output": "list|table|metric",
  "references": [{"kind":"position|deictic|result","value"}],
  "transformations": [], "corrections": [{"kind","value"}],
  "position": n, "pending": [],
  "confidence": {"frame","evidence"}, "evidence": []
}
```

- `nxScpValidate` exige el contrato por tarea (relation necesita sujeto;
  compare necesita dos grupos; rank necesita contexto…).
- `nxScpToSlots` traduce frame→intent+slots y decide `forced` — rank,
  compare, filter, count, relation fuerzan; navigate/transform no
  fuerzan (el resolver ya los maneja). Relaciones de **grupo**
  (`schedule_of_group`, `teachers_of_group`, `guardians_of_group`) van
  al compose, nunca a `student_field`.
- `nxScpToPlan` emite planes especializados (p.ej. `groups.compare` con
  métrica) que pasan por `nxPlanValidate` + `nxPlanAllowed` igual que
  cualquier plan.
- **Reglas clave**: temporal nunca es posicional; «número de celular del
  acudiente» es dato de persona, no conteo; «los 3 que más han faltado»
  es rank con límite; el sujeto explícito siempre gana al heredado.

### 4.4 Correcciones (`chatScpCorrections`)

Tipos soportados que mutan el estado sin reiniciar:

- `replace_subject` — «me refiero al de Tomás» re-ejecuta la tarea
  activa con el sujeto nuevo; si el valor es un grupo, re-ejecuta la
  colección con el grupo nuevo.
- `replace_metric` — «no, eran las faltas» re-ejecuta comparación/conteo
  con el módulo corregido.
- `limit` — «solo cinco» recorta el ranking activo.
- `refine_scope` — «de mi clase» restringe al alcance del docente.
- `none_of` — «sería que ninguna» reencuadra una comparación.
- `pending_target` — «te faltó lo otro» entrega el objetivo pendiente.
- `exclude_active` — «no esos» descarta honestamente y pide criterio.

### 4.5 Planner — capabilities (`nexus_semantic.php`)

- `nxSemanticCompose` construye el plan: `capability`, `entity`, `op`
  (list/count/percent/compare/position/detail), `filters`, `position`,
  `conf`, `evidence`.
- **Multi-goal**: frases con varias cláusulas («un chiste y una tabla de
  6A vs 10A») → plan `composed` con `steps`; cada paso es un plan pleno
  con `_ref` al result-set de un paso anterior; lo no componible queda en
  `pending_targets` para «te faltó lo otro».
- **Delegación**: capacidades sin SQL propio delegan a intents `chat_*`
  con re-verificación RBAC.

### 4.6 Validación y RBAC

- `nxPlanValidate` — contrato estructural pre-ejecución (parámetros
  requeridos, tipos, entity/op coherentes). `NexusPlanInvariantTest` lo
  cubre.
- `nxPlanAllowed` — RBAC por capacidad: rol del usuario → capacidades
  permitidas; scope del docente (sus grupos) se aplica en SQL
  (`chatScope`), nunca desde el lenguaje.
- `nxResultValidate` — contrato **post-ejecución**: grupo, módulo, rango
  temporal, sujeto y posición ejecutados deben coincidir con lo pedido;
  mismatch → `nxPlanFailure` honesto.

### 4.7 Executors

- 39 capabilities: 12 con executor SQL propio, 27 delegadas a handlers
  `chat_*` verificados.
- Toda consulta: parámetros preparados, `school_id`, scope de rol,
  límites acotados; conteos usan `COUNT` real.
- Cada respuesta materializa `_result_set` cuando corresponde (incluido
  el ranking `top_offenders`).

### 4.8 Result-sets y navegación (`chatResultNav`)

Todo resultado persistido en `_ds.last_result` lleva:

```
type, entity, label, order, count, columns, rows, items[{id,label,sub,f}],
_filters, _capability, rid, parent_result, source_capability,
source_intent, transformation, active_item, visible_items
```

Soporta: `first|prev|next|nth:N|last|rest|all|count|name|table|goto:N|
slice:N:start|end|sort:ln|fn|doc|grp|proj:name|+document|+phone|+group`.
Los slices/sorts son **vistas**: el set completo sobrevive en el parent.

### 4.9 Dialogue State (`chatBuildDs` / `chatLoadDs`)

Persistido por mensaje en `payload_json._ds` (PostgreSQL):

```
intent, prev_intent, entities (slots+salida fusionados),
goal, current{entity,result,scope,relation,goal}, previous{},
objects[] (R1..Rn monotónicos: id,type,entity,filters,count),
last_result (arriba), cursor, pending_op, pending_targets, next_rid,
person (referente no-estudiante con caducidad por cambio de sujeto),
active{task,entity,collection,result,relation,field,filters,scope,
       time_range,metric,aggregation,sort,limit,position},
pending_clarification, last_correction, last_plan, last_execution
```

### 4.10 Presentación

- `reply` en lenguaje natural del colegio (variedad controlada
  `nxVary*`/`nxSmalltalk`), `cards` para tablas/rankings, `actions` para
  chips de navegación (las operaciones mutativas NUNCA se ejecutan: se
  ofrece el chip a la pantalla formal tras `confirm_op`).
- `_interpretation` + `_ds` en dev dan traza forense del turno.

---

## 5. Semántica cubierta

- **Agregaciones**: list | count | aggregate (percent) | rank | compare |
  filter | lookup | relation | navigation | transform.
- **Temporal** (centralizada en `nxSlots`/`preprocess.py`, TZ
  America/Bogota): hoy, ayer, esta semana, semana pasada, este mes,
  mes pasado, últimos N días, N semanas, este año.
- **Resolución de entidades** contra BD real: nombre completo, parcial,
  apellido, con/without tildes y ñ, typos, posición en result-set,
  relación (acudiente↔estudiante), grupo, docente; múltiples coincidencias
  → lista de candidatos, nunca elección arbitraria.
- **Referencias**: ordinales, «ese», «el último», «el que me diste»,
  «el primero de 10A», «su X» (posesivo sobre entidad activa).
- **Alcances**: grupo explícito, «mis grupos/clases» (teacher_group_access),
  colegio entero explícito, grado.
- **Jerga**: pelados, muchachos, chicos, doc, cel, profe, salon, «los del
  10A», «se volaron», «caparon» (evasión)…
- **Multi-goal** y **correcciones** descritos arriba.

---

## 6. Seguridad

- **RBAC determinista**: el rol decide; el modelo jamás concede.
- **Tenant**: todo query lleva `school_id`; cross-tenant imposible.
- **Read-only**: 53 handlers auditados estáticamente (`readonly_guard`);
  cero escrituras desde conversación.
- **Anti-inyección**: lenguaje destructivo/SQL/elevación → `security_probe`;
  533 casos adversariales, 0 escapes.
- **El modelo no ve secretos**: sin credenciales ni plantillas biométricas
  en el camino semántico; ítems con PII viven una sola vez en `last_result`.
- **`nxResultValidate`**: nada de respuestas parcialmente correctas.

---

## 7. Observabilidad

- `NEXO_SCP_TRACE=1` → línea por capa: FRAME → TRANSLATED → PLAN →
  RESULT_MISMATCH…
- `chat_forensic_harness.php` → traza por turno: input, intent, slots,
  heredados, handler (36/36).
- `_interpretation` en el payload: turn_type, nlu_intent, scp.task,
  inherited, source (plan:capability | intent | composed).

---

## 8. Evaluación (suites existentes)

| Suite | Qué cubre | Estado |
|---|---|---|
| `golden_live.php` | §15 golden 9 turnos verbatim — BLOQUEANTE | 9/9 |
| `scp_live.php` | casos A–N multi-turno API real | 26/26 |
| `heldout_live.php` | 20 conversaciones ciegas (typos, jerga, correcciones, multi-goal, veto) | 53/53 |
| `live_probe.php` | §1 verbatim + _ds forense | 14/14 |
| `continuity_50.php` | continuidad larga real | 53/53 |
| `nexus_release_gate.php` | puerta de release (21 puertas, incluye G18-G20 live) | 21/21 |
| `scp_regression.php` | frame-level (74 checks) | 74/74 |
| `nexus_capability_eval_v1.php` | 39 capabilities | 153/153 |
| `real_conversation_v1.php` | 103 conversaciones simuladas | 381/381 |
| `dsm_units.php`, `resilience.php`, `readonly_guard.php`, `parity_dsm.php`, phpunit | unidades, degradación, read-only, paridad PHP↔PY | verdes |

---

## 9. Archivos clave

| Archivo | Rol |
|---|---|
| `backend/api/routes/chat.php` | ruta HTTP, auth+sesión, pipeline completo, correcciones, nav, ds, handlers `chat_*` |
| `backend/api/lib/nexus_scp.php` | frame semántico + validación + traductores + correcciones léxicas |
| `backend/api/lib/nexus_nlu.php` | normalización, clasificador, slots, dialogue resolver, smalltalk |
| `backend/api/lib/nexus_semantic.php` | registry de 39 capabilities, señales, compose, validate/allowed/execute/result-validate |
| `backend/nlu/service.py` + `corpus.py` + `preprocess.py` | NLU Python (TF-IDF+LR) espejo del fallback PHP |
| `PWA/src/pages/Chat.jsx` | UI del chat (renderiza reply/cards/actions, chips de operación) |
| `pruebas/seed_chat_fixture.sql` | fixture determinista del stack `nexo-test` |
| `test/*.php` | suites de la sección 8 |

---

## 10. En una frase

**Comprender → planificar → ejecutar → verificar → recordar.** El lenguaje
propone el significado (frame); NEXO dispone: planner determinista, RBAC,
SQL real, validación del resultado y memoria conversacional tipada que hace
que «el primero», «su acudiente», «todos», «los demás», «no, eran las
faltas» y «te faltó lo otro» signifiquen lo mismo que para una persona.
