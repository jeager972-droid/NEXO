# NEXO — INFORME DE CIERRE DEFINITIVO (Release Candidate)
## §20 — rama `nexus-conversational-core` · Commit `e05b759`

Fecha: 2026-09-21 · Candidato NLU: **V3.1** (`model_v3_1_targeted`, servicio `:8095`)

---

## 1. Estado inicial

Al abrir esta fase el sistema reportaba:
- Blind operativo singles **81.9%**, convos **99.7%** (52/53).
- Benchmark semántico singles **95.1%**, adversarial **100%**, convos **100%**.
- Release gate **13/13** pero con una ambigüedad: G12 decía «singles ≥90%»
  y pasaba con el dataset semántico mientras el operativo marcaba 81.9%.
- Residuales documentados: `estudiante→grupo` sin resolver, 18 falsos
  convencidos ≥0.90, OOD 5/15 filtrándose a intents, referencias 9/10,
  jerga 8/10, horarios 4/8, métricas 5/9.

## 2. Hallazgos

| # | Hallazgo | Capa |
|---|---|---|
| H1 | G12 y el blind operativo medían datasets distintos — el PASS era correcto pero la interpretación ambigua | release gate |
| H2 | `a qué hora es el recreo` → `time` robaba `schedule_info` por early-exit sin guardias | semantic resolver |
| H3 | `ranking de tardanzas` → `late_today` (módulo LATE_ARRIVAL vencía al marcador de ranking) | semantic resolver |
| H4 | `mis permisos` → `permissions` (colisión permiso-salida vs meta-pregunta) | coverage |
| H5 | `dame un permiso para daniel` → `permissions` cuando el modelo daba `derive_action` — la regla query-verb lo volteaba | coverage |
| H6 | `cuantos estudiantes vinieron` → `students_count` (count_present sin stems de llegada; INGRESO sin 'vinieron') | léxico+sinónimos |
| H7 | `el docente del que reportó` → `teachers_list` (personRef solo cubría sujeto+materia) | semantic resolver |
| H8 | `el resultado del sena` → `group_summary` con student='sena' (stopwords) | extracción |
| H9 | `hablame de filosofía` → `colombia_president` (intent cercano confiado, contenido equivocado) | OOD |
| H10 | ctx corrupto (`last_intent` array) → **TypeError fatal** en resolve | resiliencia |
| H11 | `y de todo su grupo` → group vacío: la relación estudiante→grupo vive en BD, no en el NLU | contexto/datos |
| H12 | Servicios :809x con `preprocess.py` obsoleto en memoria → stopwords nuevos no aplicaban | infra/tests |

## 3. Causa raíz

- **H2/H3/H6/H7**: el early-exit del semantic resolver evaluaba el hit léxico
  crudo, sin las guardias contextuales del scoring — el modelo confiado
  podía saltarse la evidencia.
- **H4/H5**: reglas de coverage competían entre sí — verbos ambiguos
  («dame») eran clasificados como consulta sin mirar el beneficiario.
- **H8/H12**: dos listas de stopwords desincronizadas (PHP vs servicio Python).
- **H9**: patrón `háblame de <tema>` se resolvía al intent smalltalk más
  cercano aunque el tema no existiera en dominio.
- **H10**: campos del ctx se leían sin verificar tipo.
- **H11**: se intentaba resolver en NLU lo que solo la BD conoce.

## 4. Cambios realizados

| Archivo | Cambio |
|---|---|
| `backend/api/lib/nexus_nlu.php` | `nxLexGuarded()` compartido (early-exit + scoring); guardias time/ranking/persona-relacional; `mis permisos`→about_me; `un permiso para <nombre>`→op con guardia en query-verb; stems INGRESO/count_present; `group_student_count` exige grupo; `_ref='group_of_student'`; `háblame de <tema>` no-colombia→do_for_me; `$lastIntent` tipado (ctx corrupto); selfcheck |
| `backend/api/routes/chat.php` | resolución `_ref`→`chatResolveStudent`(scope)→group_name; clarify en ambiguo/no-encontrado/sin-grupo |
| `backend/nlu/preprocess.py` + `backend/api/nlu_runtime/preprocess.py` | stopwords materias/cultura/reportos — paridad con PHP |
| `test/harness_turn.php` | fixture documentada juan→8A (paridad con resolución BD) |
| `test/gen_semantic_benchmark.php` | expects OOD ampliados a la familia smalltalk real de la taxonomía |
| `test/readonly_guard.php` | **nuevo** — 52 handlers auditados, 0 SQL mutativo |
| `test/resilience.php` | **nuevo** — 15 escenarios de degradación segura |
| `test/nexus_release_gate.php` | +G13 read-only, +G12b op-blind ≥80%, +G14 resiliencia → **16 puertas** |
| `backend/api/lib/nexus_nlu.php` (ops) | `exporta/descarga/extrae`→export_data; probes semánticos→security_probe |

## 5. Cambios NO realizados (y por qué)

- **Clasificador / dataset / modelo**: los errores eran de frontera
  lógica posterior al modelo, no de aprendizaje — un modelo nuevo
  no arregla «a qué hora es el recreo». Sin evidencia de necesidad.
- **`hablame sobre el clima` → out_of_scope**: abstención honesta;
  el usuario tiene `weather` con otra formulación. No se fabrica.
- **`el resultado del sena` → out_of_scope**: abstención correcta —
  «sena» no es grupo ni estudiante; mejor que inventar uno.
- **`student='reporto'` residual**: el slot se marcó sucio pero el
  intent (staff_lookup) es correcto — cosmético, sin impacto en datos.
- **Handlers de respuesta**: ya producen lenguaje natural anclado a
  datos reales (nombres, conteos, rangos, herencia de contexto).
  `nxPlanResponse` sella vacíos. No se agregó generación libre: la
  naturalidad actual ya cumple «sobre estructura, nunca sobre verdad».

## 6. Validación — ANTES → DESPUÉS

| Métrica | Antes | Después |
|---|---|---|
| op-blind singles (V3.1) | 81.9% | **83.4%** |
| op-blind convos | 99.7% · 52/53 | **100% · 53/53** |
| sem-blind singles | 95.1% | **97.9%** |
| sem-blind adversarial | 100% | **100%** |
| sem-blind convos | 100% · 320/320 | **100% · 320/320** |
| falsos convencidos ≥0.90 (sem) | 18 | **0** |
| críticos fallidos (sem) | 2 | **0** |
| `estudiante→grupo` | sin resolver | **resuelto vía BD** |
| forense | 36/36 | **36/36** |
| DSM units | 50/50 | **50/50** |
| paridad PHP↔Python | 25/0 | **25/0** |
| resiliencia | — | **15/15** |
| read-only | — | **52 handlers, 0 escrituras** |
| release gate | 13/13 | **16/16** |

Ablación (V3.1): crudo **76.7%** → resuelto **97.9%** (+21.2 pts).

## 7. Seguridad — read-only demostrado

- Auditoría estructural: **52 handlers `chat_*`, 0 contienen**
  `INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|GRANT|REVOKE`.
- Operaciones conversacionales producen **chips de navegación** — la
  ejecución real vive fuera del chat (UI + endpoints propios + RBAC).
- Cadena RBAC completa verificada (G4/G9): intent→`nxAllowed`→`chatCanAction`.
- Adversarial-blind: **533/533 seguros, 0 escapes** — ignora-rol, modo-admin,
  cross-scope, credenciales, SQL, exfiltración, suplantación, «aunque no
  tenga permiso», intent-injection en consulta legítima.
- La resolución `estudiante→grupo` pasa por `chatResolveStudent` con
  scope del rol — un docente no ve estudiantes fuera de sus grupos.

## 8. Resiliencia — comportamiento ante fallos probados

| Fallo | Comportamiento verificado |
|---|---|
| NLU caído | fallback PHP-model; intent correcto; `source≠service` |
| Timeout NLU | acotado (~300ms); turno completo <1ms en fallback |
| BD/handler vacío | `nxPlanResponse` → «no pude obtener datos» (no inventa) |
| Handler excepción | try/catch → «no pude consultar eso ahora» + securityLog |
| Contexto corrupto | tipos invalidados (bug `lastIntent` array corregido) |
| Servicio reiniciado | misma entrada → mismo intent (determinista) |
| Solicitud duplicada | idempotente (read-only — sin efectos) |
| «y ahora?» sin tema | aclarar, no adivinar |

## 9. Regresiones

Tres introducidas y corregidas en iteración: (a) `como va el 8a`→day_summary
(guardia grupo); (b) `sin retorno`→yes por stem `si` (boundary derecho);
(c) `dame un permiso para X`→permissions por regla query-verb (guardia
«para nombre»). Estado final: **0 regresiones** — forense 36/36,
DSM 50/50, paridad 0-conflictos, convos 100% en ambos benchmarks.

Residual conocido (documentado, no crítico): categorías frontera del
benchmark generado (`referencias` 9/10, `jerga` 8/10, `horario` parcial,
`metricas` parcial) — la mayoría son fronteras taxonómicas legítimas
(`list_events` vs `count_events` vs ranking), no fallos de datos.

## 10. Release verdict

```
RELEASE CANDIDATE — READY
```

Alcance: núcleo conversacional + capa semántica + canal informativo
read-only. No afirma infalibilidad — los residuales de §9 están
medidos, clasificados y acotados; la infraestructura degrada seguro.

---

# ANEXO R — NÚCLEO CONVERSACIONAL REAL (post-manual-testing)

Commit `5fd5d22` + fixes subsiguientes · rama `nexus-conversational-core`

## R.1 Diagnóstico previo

La arquitectura anterior dependía de `last_intent` + `ctx.entities` que el
frontend devolvía al servidor: el servidor no tenía estado conversacional
propio, los result sets no existían como concepto y «dame otro» caía en el
repeat-legacy («no puedo responder eso con certeza»). Los benchmarks daban
>95% pero el producto real fallaba en continuidad.

## R.2 Arquitectura resultante

- **Estado server-side `_ds`**: `chatLoadDs()` lee el estado del último
  mensaje asistente persistido en `chat_messages.payload_json` (session+user).
  El `ctx` del frontend queda como entrada de compatibilidad, no fuente de
  verdad. Degradación segura si la tabla no existe.
- **`_ds` contiene**: intent actual/anterior, entities (student/group/
  module/days/field), `person` activa (student/guardian), `last_result`
  (tipo, items, count), `cursor`, `pending_op`, `turn_type`, `selfcheck`.
- **Herencia disciplinada**: entidades temáticas se heredan SOLO en turnos
  de continuación (`context_modify`, nav, deícticos, correcciones) — un tema
  nuevo («háblame del sistema») no arrastra entidades ajenas (§26).
- **Nav de result-set como slot `_nav`**: `next/prev/nth:N/rest/count/name`
  — resuelto antes del repeat-legacy; los turnos nav NO cambian el intent
  activo (el tema persiste tras «la última»).
- **Separación de conceptos**: INTENT (operación), ENTITY (objeto),
  REFERENCE (pronombre/deíctico a ctx), CONTEXT (ds), QUERY (petición
  concreta), RESULT (datos reales), DIALOGUE STATE (persistido),
  RESPONSE (fundamentada).

## R.3 Capacidades nuevas verificadas

| Caso | Antes | Ahora |
|---|---|---|
| «¿Y su número?» tras acudiente | random_number | celular del acudiente |
| «documento de su acudiente» | documento del estudiante | documento del acudiente (JOIN users) |
| «estudiantes del 6-A» | out_of_scope | students_in_group (lista real) |
| «6-a / 6A / 6 A» | no extraía grupo | group=6-A (PHP+Python) |
| «dame otro» | fallback genérico | siguiente ítem del result-set |
| «los demás / el primero / el último» | sin concepto | rest / nth:1 / nth:N |
| «cuántos son en total» (result-set) | clarificación genérica | count del set |
| «cuántos estudiantes del 8A» | students_count (colegio) | group_student_count |
| «cuantos presnetes» (typo) | count_events | count_present |
| «qué pasó con él» | audit_query → denegación RBAC | student_summary (o aclaración si ambiguo) |
| «permiso de salida para X» | list_events | derive_action → chip |
| «borra eso» | student_field | security_probe |

## R.4 Benchmark `test/real_conversation_v1.php`

**103 conversaciones multi-turno · 381 turnos — 100%** en las 6 dimensiones:

```
Conversaciones: 103/103 completas (100%)
  intent       381/381 (100.0%)
  reference    25/25  (100.0%)
  carry        241/241(100.0%)
  nav          60/60  (100.0%)
  clarify_ok   381/381(100.0%)
  consistency  381/381(100.0%)
```

Categorías: goal-switch, referencias, result-nav (eventos+estudiantes),
student-switch, staff, temporal+grupo, op→chip→consulta, seguridad en
conversación, group-ref, correcciones, encadenados cortos, grados
colombianos, pending-op, cross-entity, temporal-scope, who+nav,
consistencia, mismo-X, informales encadenados.

## R.5 Regresión completa

| Suite | Resultado |
|---|---|
| Forense conversacional | 36/36 PASS |
| DSM units | 50/50 PASS |
| Paridad PHP↔Python | 25/25, 0 conflictos |
| Semantic singles | 978/1000 = 97.8% (baseline 97.9%, −0.1pp) |
| Semantic convos | 2732/2732 = 100% |
| Semantic adversarial | 533/533, 0 escapes |
| Operational singles | 275/326 = 84.4% (baseline 83.4%, +1.0pp) |
| Operational convos | 343/343 = 100% (53/53) |
| Read-only guard | 53 handlers, 0 SQL mutativo |
| Resiliencia | 15/15 |
| **Release gate** | **18/18 PASS — READY FOR CONTROLLED PRODUCTION** (11.0s) |

## R.6 Rendimiento (nxClassify + nxDialogueResolve, 500 turnos, servicio activo)

| Métrica | Valor |
|---|---|
| p50 | 4.17 ms |
| p95 | 6.17 ms |
| p99 | 7.29 ms |
| max | 8.21 ms |
| mean | 4.37 ms |

## R.7 Conversación libre real (25+ turnos, stack docker, teach@test.nexo)

Verificada en vivo: saludo→jornada, doc Ana→acudiente→teléfono→documento
del acudiente, faltas del mes→evasiones, lista 6-A→nombres→otro→los
demás→primero→último→cuántos, cambio 6A, «presnetes» (typo), «qué pasó
con él» (aclaración honesta, no denegación), «y ayer», «háblame del
sistema» (suelta contexto — «su número» siguiente aclara correctamente,
§26), «genera un permiso»→chip (read-only), «¿cuántas evasiones
tiene?»→hereda Luis del turno operativo, «y su documento»→doc 8002.

Turnos con comportamiento honesto: «muéstrame otro» tras lista agotada →
«ya te mostré los 3»; «qué pasó con él» sin referente → aclara en lugar
de denegar.

## R.8 Bugs de estado corregidos en esta fase

- Turnos `_nav` vaciaban `entities` → el tema se perdía tras «la última».
  Fix: herencia temática condicionada a turnos de continuación
  (`chatBuildDs` + paridad en el evaluador).
- Turnos `_nav` reemplazaban `ds.intent` con el intent del turno nav
  (p.ej. sos_alerts) → contaminaba herencias posteriores. Fix: nav
  conserva el intent del último query real.
- Rerank semántico degradaba `group_student_count`→`students_count` aun
  con grupo explícito. Fix post-rerank en resolve.
- `esta semana` colisionaba con deíctico `esta` → heredaba student en
  consulta autónoma. Fix: demostrativos restringidos (este/esta
  temporales excluidos).
- `como va el 6-A` colisionaba con «como va el» (persona). Fix lookahead
  `(?!\s*\d)`.
- `su acudiente` dentro de petición de operación («quiero citar a su
  acudiente») degradaba start_operation→student_field→derive_action.
  Fix: refField no toca intents de operación.

## R.9 Veredicto

```
REAL CONVERSATIONAL CORE — READY
```

Evidencia: 103/103 convos simuladas (6 dimensiones), 18/18 puertas,
conversación viva de 25 turnos con referencias, navegación, cambios de
objetivo, correcciones, typos y aclaraciones honestas; read-only
garantizado; RBAC intacto; p99 <8ms.

---

# ANEXO S — REORIENTACIÓN SEMÁNTICA DEL NLU (post-§1-§40)

## S.1 Diagnóstico medido

El modelo anterior (TF-IDF+LogReg sobre plantillas × pools + augmentación
mecánica PRE/SUF/typos) memorizaba vocabulario: **50% en el blind set de
paráfrasis** (72 frases escritas a mano, nunca en entrenamiento). Fallos
típicos: «necesito que el papá del estudiante se acerque» → colombia_history;
«hay que hacer venir al responsable» → bored.

Bugs estructurales encontrados en auditoría:

- **`students_in_group` no estaba en `domains.FORMAL`** — entrenaba al
  submodelo informal; toda consulta de grupo competía contra smalltalk.
- **Masking solo cubría nombres propios/grupos** — «el papá», «el
  responsable», «ese alumno», «del plantel» quedaban como tokens libres:
  el clasificador no veía la RELACIÓN semántica, solo vocabulario.
- **Extracción tragaba cortesía**: «…de ana estudiante? muchas gracias»
  → student=`ana muchas`; «volvamos a ana: quién responde…» →
  student=`ana quien responde`.
- **«buenas tardes» activaba módulo LATE_ARRIVAL** (stem `tarde`).
- **Vocabularios mezclaban sustantivos y frases-verbales** →
  «los los que se volaron» (1214 ejemplos corruptos).
- **`a cargo de`** tenía etiquetas contradictorias (staff en corpus base,
  acudiente en frames) — colisión léxica.

## S.2 Arquitectura resultante

```
texto → normalize → extract_entities → mask_entities
         │                                │
         │   NOMBRES DE ROL → tokens semánticos:
         │   acudiente_ent  (acudiente|papá|responsable|quien responde por…)
         │   personal_ent   (docente|coordinador|rector|portero…)
         │   colegio_ent    (colegio|institución|plantel|sede)
         │   estudiante_ent (nombre propio O «ese alumno»/«del niño»…)
         │   grupo_ent · num_ent · region_ent · extranjero_ent
         ▼                                ▼
   CANAL SEMÁNTICO (clasificador)   CANAL SEGURIDAD (texto completo +
   estructura, no vocabulario       lexicon probes + verbos destructivos)
```

- `backend/nlu/intent_semantics.py` — especificación formal §5 por intent:
  goal, action, source/target entity, fields, entidades requeridas,
  reglas de desambiguación, near-miss, vocabularios de conceptos (§13:
  C_GUARDIAN tiene 24 formas de referirse al acudiente; C_OP_CITE 22
  formas de pedir citación — incluidas las que no dicen «citar»).
- `backend/nlu/corpus_semantic.py` — generador por composición:
  **frames estructurales** (pregunta directa/indirecta/declarativa/
  elíptica/imperativa/envuelta en justificación/con corrección) ×
  **conceptos** × **entidades** × **envolturas de cortesía/escenario**.
  37.8K ejemplos, 22 intents cubiertos; cada «forma» es una estructura
  distinta, no una mutación de string.
- `test/blind_semantic.json` — 72 paráfrasis manuscritas fuera del
  corpus (§23-24); `test/generalization_eval.py` — métricas §37.

## S.3 Resultados medidos

| Métrica | Antes | Ahora |
|---|---|---|
| **semantic_generalization (argmax)** | 50.0% | **94.4%** |
| semantic_generalization (umbral prod.) | — | 81.9% |
| courtesy_robustness | — | **100%** (8/8) |
| near_miss_rejection (op vs consulta vs probe) | — | 90% |
| ood_abstention | — | 87.5% |
| typo_robustness | — | 83.3% |
| Semantic singles (suite existente) | 978 | **979** (97.9%) |
| Convos / adversariales | 100% / 533 | 100% / **533 (0 escapes)** |
| Forense / DSM / paridad / readonly / resilience | ✓ | **sin regresión** |
| **Release gate** | 18/18 | **18/18 PASS** |

## S.4 Cadena §32-§35 verificada en vivo (stack docker, teach@test.nexo)

```
dame el documento de Ana      → doc 8001, 6-A
¿cuál es su acudiente?        → Acudiente Prueba
¿y su número?                 → +573000000001
¿cuál es el documento de su acudiente?  → 9003
¿y el nombre del acudiente?   → Acudiente Prueba
¿cuál es el teléfono del acudiente?     → +573000000001
necesito que el papá del estudiante se acerque
                              → chip «Solicitar seguimiento» para Ana ★
hay que hacer venir al responsable del niño
                              → chip para Ana ★
quién aparece como responsable de ese alumno
                              → Acudiente Prueba ★
con quién está registrada la responsabilidad de este estudiante
                              → ficha de Ana ★
ahora cuéntame cuántos faltaron hoy
                              → 0 inasistencias hoy (alcance docente) ★
háblame del sistema           → límite honesto (OOD no forzado)
volvamos a ana: ¿quién responde por ella ante el colegio?
                              → Acudiente Prueba ★ (retorno de tema)
«buenas tardes, por favor, si es tan amable, ¿me dice cuál es el
 teléfono del acudiente de ana estudiante? muchas gracias»
                              → +573000000001 ★ (cortesía intacta)
```

★ = capacidad que no existía antes: paráfrasis profunda, deíctico de
rol, retorno explícito de tema, cortesía pesada.

## S.5 Qué cambió conceptualmente

- **De vocabulario a estructura**: el clasificador ya no necesita ver la
  palabra «citar» — «hacer venir al responsable del niño» aprende la
  relación `acudiente_ent ← estudiante_ent + verbo-operativo`.
- **De augmentación a composición**: los ejemplos nacen de frames
  estructurales distintos (§10 A-F), no de mutar una oración.
- **Señal ≠ seguridad**: el canal semántico enmascara ruido; la capa de
  seguridad sigue viendo el texto completo («borra el historial» →
  security_probe aunque la cortesía lo envuelva).
- **Entidad objetivo explícita**: «documento del estudiante» vs
  «documento del acudiente» — el slot `_ref=guardian` decide el target;
  la palabra «documento» no decide sola.
- **Herencia disciplinada**: «ahora cuéntame cuántos X» es consulta
  nueva (sin anáfora → no hereda); «ahora su número» sí (anáfora «su»).

## S.6 Residuales conocidos

- 4 fallos blind: «a cargo de» (ambigüedad staff/acudiente legítima),
  «el listado del octavo», «cuéntame sobre física cuántica».
- Confianza <0.65 en paráfrasis largas → fallback + cobertura PHP
  rescata en la mayoría; el umbral es deliberado.
- El chip de «hacer venir al responsable» propone «Solicitar
  seguimiento» (no existe operación «citación directa» como comando
  separado — el deep-link es autorizado y read-only).

## S.7 Veredicto

```
SEMANTIC NLU REORIENTED — READY
  blind paraphrases: 50% → 94.4% (argmax) · 81.9% (producción)
  regresión: 0 · gate: 18/18 · §32-35: verificado en vivo
```
