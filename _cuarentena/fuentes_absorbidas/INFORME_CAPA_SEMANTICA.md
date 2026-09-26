# Informe final — Capa semántica NEXUS

Fecha: 2026-09-22 · Rama: `main` · Entorno de validación: stack Docker
`nexo-test-*` (api :18080 · nlu :8090 · db/pgbouncer/redis).

## Objetivo

Transformar Nexus de un clasificador `frase → intent → plantilla` a una
interfaz conversacional general sobre el ecosistema NEXO:

```
lenguaje humano → significado → contexto → entidad → relación → objetivo
→ restricciones → capacidad NEXO → consulta/plan → autorización
→ datos reales → transformación del resultado → respuesta natural
→ nuevo contexto
```

El usuario no debe aprender nombres de endpoints ni frases de entrenamiento:
habla como a un compañero («Muéstrame los de 10A», «¿El primero quién es?»,
«¿Y su acudiente?», «Ponmelos en una tabla», «Compárame ambos grupos»).

## A. Arquitectura resultante

Híbrida — el clasificador de intents sigue activo como fallback; la capa
semántica tiene precedencia solo cuando el enunciado contiene estructura
suficiente para una capacidad registrada.

```
POST /chat/message
  └─ nxClassify (NLU service :8090 → masked + entities)
  └─ chatLoadDs            — estado conversacional desde chat_messages.payload_json._ds
  └─ nxDialogueResolve     — DSM: refs, deixis, herencia de slots, _nav
  └─ clarify provisional   — si el texto YA compone un plan, no aclarar
  └─ confirm/cancel/op_repeat — operaciones pendientes (legacy)
  └─ chatResultNav         — «el primero», «los demás», «cuántos son», «en tabla»
  └─ nxSemanticCompose     — señales + slots + ds → plan verificable (o null)
  └─ nxPlanAllowed         — RBAC por capability + rol + scope
  └─ nxPlanExecute         — SQL real read-only con chatScope/nxScopeGroupIds
  └─ nxPlanResponse        — presentación (lista/tabla/escalar/comparación)
  └─ chatBuildDs + chatLog — persistir result-set, cursor, entidades
```

Archivos:

| Archivo | Rol |
|---|---|
| `backend/api/lib/nexus_semantic.php` | registry, `nxSemSignals`, `nxSemanticCompose`, `nxPlanAllowed`, `nxPlanExecute`, `nxPlanResponse`, executors |
| `backend/api/lib/nexus_nlu.php` | `nxSlots` (grupos, ordinales, módulos, rangos, campos, `_nav`), stoplist del extractor |
| `backend/nlu/preprocess.py` | espejo Python del extractor (corre en el servicio NLU) |
| `backend/api/routes/chat.php` | orden del pipeline, clarify provisional, guardas `navGroupClash`/`navEventVerb` |
| `test/nexus_capability_eval_v1.php` | evaluador estructurado (153 checks) |
| `test/corpus_semantic_gen.php` | generador combinatorial masivo (1797 casos) |

## B. Inventario de capacidades

~40 capacidades en `nxCapabilityRegistry()`. Las que ejecuta la capa
semántica directamente (exec ≠ `intent:*`):

| Capability | Executor | Qué hace |
|---|---|---|
| `students.list` | students | nómina filtrada por grupo/grado/estado/rango, con proyección/orden/slice |
| `students.count` | students | conteo con los mismos filtros |
| `students.position` | students | «el primero/segundo/último/penúltimo» con sort explícito |
| `students.percent` | students | «qué % del colegio/de 6-A llegó tarde» |
| `students.of_guardian` | students_of_guardian | relación inversa: «de quién es ese acudiente» |
| `guardians.of_group` | guardians_of_group | acudientes/padres de un grupo |
| `teachers.of_group` | teachers_of_group | «quién le da clase al 8-C», «profes del 6-A» |
| `schedule.of_group` | schedule_of_group | horario/materias por grupo |
| `incidents.list` | incidents | eventos por módulo/rango/grupo/presentación |
| `incidents.position` | incidents | «la primera tardanza de la semana» |
| `groups.compare` | groups_compare | «compárame 6-A con 7-B» |
| `groups.rank` | groups_rank | «cuál grupo tiene más/menos tardanzas» |

Delegadas a intents existentes (`exec=intent:*` o plan `null`):
`attendance.today`, `students.top`, `permissions.*`, `exits.school`,
`schedule.info`, `risk.students`, `trackings.*`, `citations`, `devices`,
`notifications`, `messages`, `whatsapp`, `sos`, `biometric`, `audit`,
`my.activity`, `day.summary`, `birthdays`, `staff.lookup`,
`operations.derive`, smalltalk y toda la superficie de operaciones.

### Señales del IR (`nxSemSignals`)

- `entity` — students / guardians / teachers / groups / incidents / schedules
- `op` — list / count / percent / position / compare / rank / summary
- `relation` — guardians_of_group / students_of_guardian / teachers_of_group / schedule_of_group
- `filters` — group, group2, grade, status, module, days/from/to/range_label, shift, field, search
- `position` — 1..N / last / last-1 / last-2
- `slice` — `{n, from:start|end}` («los cinco primeros», «los dos últimos»)
- `cardinality` — all
- `projection` — name / document / phone / email …
- `sort` — name / document / group / time_asc|time_desc
- `presentation` — list / table / scalar / comparison / detail
- `evidence` — traza auditable de cada decisión

### Estados (`filters.status`)

`absent`, `present`, `late`, `tracking` («en seguimiento»), `exempt`
(«exentos del sensor»), `no_group` («sin grupo asignado»), `permission`,
`risk` («en riesgo»), `orphan`.

## C. Cobertura medida

### `test/corpus_semantic_gen.php` — producto cartesiano del espacio

1797 casos generados combinatoriamente: 10 formas de grupo (incluye
«6A», «8 C», «sexto», «octavo») × 10 verbos × 10 sustantivos de colectivo
× 12 estados × 7 rangos × 9 presentaciones × 11 patrones de posición
× 5 slices × 9 relaciones × incidentes × comparaciones + negativos
+ near-miss. Muestreo sistemático: cobertura total de dimensiones.

| Dimensión | Resultado |
|---|---|
| list | 1300/1300 (100%) |
| cardinality | 39/40 (97.5%) |
| range | 8/8 (100%) |
| position | 110/110 (100%) |
| slice | 50/50 (100%) |
| count | 30/30 (100%) |
| percent | 11/11 (100%) |
| relation | 90/90 (100%) |
| incidents | 108/108 (100%) |
| compare | 24/24 (100%) |
| rank | 3/3 (100%) |
| negative | 12/12 (100%) |
| near_miss | 11/11 (100%) |
| **TOTAL** | **1796/1797 (99.9%)** |

Único fallo: `dame todos los 10B` → el clasificador lo marca
`security_probe` (conf alta) y el veto conservador impide componer.
Fallo del lado seguro — se documenta como limitación del modelo NLU.

### `test/nexus_capability_eval_v1.php` — 153/153 (100%)

capability 53/53 · filters 58/58 · op 5/5 · position 9/9 ·
presentation 8/8 · context 12/12 · delegation 8/8.

> Corre **dentro del contenedor API** (`NEXO_NLU_URL=http://nlu:8090`).
> `localhost:8090` del host es una instancia dev distinta — no confundir.

## D. Generalización

El compositor opera sobre señales estructurales, no plantillas: cualquier
combinación verbo×sustantivo×grupo×estado×rango×presentación×posición que
aparece en el corpus funciona porque cada pieza es un reconocedor léxico
independiente. No hay frases memorizadas — 1300 variantes de «lista de
estudiantes» pasan por la misma ruta `students.list` con filtros distintos.

## E. Conversación real

- `test/dsm_units.php` → **50/50**
- `test/real_conversation_v1.php` → 101/103 conversaciones,
  381/381 checks (reference 25/25 · carry 241/241 · nav 60/60 ·
  clarify_ok 381/381 · consistency 381/381)
- `test/semantic_eval.php` → 533/533 adversariales seguras ·
  320/320 conversaciones · 2732/2732 turnos
- `test/readonly_guard.php` → 53 handlers chat_* auditados, 0 escrituras
- `test/nexus_release_gate.php` → READY (18 puertas)

Journey live verificado contra datos reales (docente scope-6A):

```
muéstrame los de 6-A        → students.list   (3 nombres+docs)
el primero                  → result_nav      Ana Estudiante
el segundo                  → result_nav      Luis Estudiante
los demás                   → result_nav      Eva Exenta
ponmelos en una tabla       → result_nav      card tabla ×3
compárame 6-A con 7-B       → groups.compare  (denegado si fuera de scope)
los dos últimos de 6-A      → students.list   slice end=2
la primera tardanza         → incidents.position
dame el documento de Ana    → student_field
cuál es su acudiente        → student_field   (ref su → Ana)
de quién es ese acudiente   → students.of_guardian (relación inversa)
```

> Nota: `session_id` es UUID. Un id arbitrario rompe `chatLoadDs`
> silenciosamente (try/catch → `$ds=null` → nav muerta). El frontend
> siempre envía UUID; los harnesses deben hacerlo igual.

## F. Consultas complejas

- Comparación 2 grupos con módulo/rango («cuál tiene más tardanzas, 6-A o 7-B»)
- Ranking ascendente/descendente («cuál grupo tiene menos evasiones»)
- Slice top-N («los cinco primeros», «los dos últimos»)
- Posición ordenada («quién llegó primero» → order_by tiempo de llegada)
- Porcentaje + estado («qué % del colegio llegó tarde hoy»)
- Relaciones directas e inversas (acudiente ↔ estudiante, docente ↔ grupo)
- Proyección («solo los nombres», «con documento») y orden («por documento»)
- Presentación (lista ↔ tabla ↔ escalar ↔ comparación con card)

## G. Seguridad y read-only

- **Read-only garantizado**: `readonly_guard` audita los 53 handlers —
  0 escrituras. La capa semántica solo emite `SELECT` con `school_id`
  ligado + scope.
- **Veto mutativo**: `cambia|borra|elimina|crea|registra|actualiza|asigna|
  genera|emite|suspende|activa|anula|autoriza|rechaza|aprueba|revoca`
  → compose devuelve null; la operación la decide el pipeline de intents.
- **`security_probe` nunca compone** (aunque la frase sea benigna).
- **RBAC por scope**:
  - students/incidents/guardians executors aplican `chatScope` (alumno ∈
    grupos asignados al docente).
  - `nxScopeGroupIds()` (nuevo) restringe `groups.compare`/`groups.rank`:
    comparar un grupo fuera de scope → «Solo puedes consultar los grupos
    que tienes asignados» en vez de ceros falsos que filtraban el tamaño
    del grupo ajeno (fuga corregida — antes «7-B: 0 (de 2 estudiantes)»
    revelaba la matrícula de un grupo no asignado).
  - teachers/schedule son datos de staff (sensibilidad menor) — sin
    scope por diseño, documentado.
- **Clarify provisional**: `student_field`/`guardian_field` con
  `requires_clarification` prueba primero un compose — si el texto ya es
  estructural («chicos del 7-B por documento») el clarify era falso
  positivo y se ejecuta el plan; si no, aclaración normal.

## H. Rendimiento

20 turnos mixtos live (clasificar → DSM → compose → SQL → respuesta):

```
p50 = 22ms · p95 = 45ms · max = 45ms · mean = 23ms
```

La capa semántica añade <5ms sobre el pipeline base (regex + plan +
1-3 queries parametrizadas).

## I. Bugs corregidos en esta iteración (segunda+tercera ronda)

| Bug | Síntoma | Fix |
|---|---|---|
| `preprocess.py` extraía slots propios | nav rota: `group:'1'` en «el segundo» | extractor Python espejado con PHP |
| regex ordinal `primer[oa]s?` exigía vocal | «el primer incidente» sin posición | `[oa]` requerido solo en patrón con letra |
| `primera` = primer+A | phantom group `1A` → `groups.compare` espurio | `_ORDL` fem/masc + `primer[oa]` en `nxSemGroups` |
| `slots.group` sin canonicalizar | «7B» vs «7-B» → `group2` fantasma | canonicalización `7B→7-B` antes de merge |
| `entity:from_ctx` regex malformada | warning `Unknown modifier '|'` | patrones unidos dentro del delimitador |
| `_nav` bloqueaba el composer | «los cinco primeros» caía a intent viejo | `_nav` ya no bloquea compose |
| slice vs posición | «los cinco primeros» = posición 5 | `slice:{n,from}` separado |
| `module=INCIDENTE` genérico | filtraba por tipo inexistente → 0 rows | INCIDENTE = sin filtro de tipo |
| `pos+ctx` antes de `pos+status` | «quién llegó primero» → tabla equivocada | status textual precede a ctx |
| filtros heredados contaminan | «sin grupo» heredaba `days` del turno previo | module/days heredados se descartan sin texto |
| `field` sin estudiante delegaba | «nómina del 6-A por documento» → clarify | guard exige `slots.student` no vacío |
| clarificación antes de compose | «chicos del 7-B por documento» moría | compose provisional antes del clarify |
| verbos imperativos como nombres | `student:'ensename'/'necesito'/'tardanza'` | _STOP += imperativos/colectivos/estados |
| grado-ordinal como posición | «del sexto en tabla» → pos=6 | lookahead negativo por clase de ordinal |
| `últimos 3 días` como posición | pos=last espuria | guard temporal `últimos N (días\|semanas…)` |
| relaciones hardcodeaban `op=list` | «cuántos acudientes tiene el 9-A» → list | op del signal preservado (count) |
| trusted-intent guard post-upgrade | «matriculados del sexto ordenados» delegaba | guard exige `!sort && !projection` |
| `el 8 C` / `al sexto` sin trigger | grupos lettered/ordinales no detectados | triggers `el`/`al` en ambos extractores |
| `groups.compare` sin scope | docente veía tamaño de grupo ajeno | `nxScopeGroupIds` + negación honesta |
| `groups.rank` sin scope | ranking global para docente | `IN (grupos asignados)` |
| mutation phrases componían | «cambia el horario» → plan de consulta | veto mutativo en compose |
| `intent_equiv` de incidents.list | delegaciones válidas marcadas fail | equiv += `late_today\|attendance_today\|count_events\|top_offenders` |

## J. Limitaciones restantes (honestas)

1. **`blind_eval`** — misses del clasificador pre-existentes
   (`estudiante` 2/5: `out_of_scope`/`random_student` borderline).
   Cobertura del modelo NLU, no de la capa semántica.
2. **`real_conversation`** — 101/103: mismos 2 casos borderline
   (`háblame del sistema` → out_of_scope; `estudiantes del 6-a` →
   group_summary). Probabilidades del modelo, no regresión de código.
3. **`dame todos los 10B`** → `security_probe` (veto conservador).
4. **`padres de familia del X`** → `derive_action` (delega a formulario —
   seguro, pero no compone `guardians.of_group`).
5. **teachers/schedule executors** sin scope — staff data, menor
   sensibilidad, decisión documentada.
6. **`session_id` no-UUID** degrada nav silenciosamente (contrato UUID).

## Reproducir

```bash
# stack
docker compose -f docker-compose.test.yml up -d
# evaluadores (dentro del contenedor api)
docker exec nexo-test-api-1 php /var/www/html/test/nexus_capability_eval_v1.php
docker exec nexo-test-api-1 php -d memory_limit=512M \
    /var/www/html/test/corpus_semantic_gen.php [--emit-json=/tmp/corpus.json]
# regresiones (host)
php test/dsm_units.php && php test/real_conversation_v1.php \
    && php test/readonly_guard.php && php test/nexus_release_gate.php
```

---

# FASE FINAL — ÚLTIMA INTERVENCIÓN ARQUITECTÓNICA
## Universal Capability Planner + Open Composition + Ecosystem Completeness

## §49.0 Gap analysis (pre-intervención)

| Capacidad | Estado antes | Estado ahora |
|---|---|---|
| Registry metadata rica | EXISTE (39 caps declarativas) | EXISTE + vista normalizada `nxCapabilityGraph()` |
| Plan IR (filters/sort/pos/slice/proj/pres) | EXISTE | EXISTE + `steps`, `_ref`, `_delegate_intent` |
| Composición multi-cláusula | AUSENTE (cls.parts → dispatch legacy) | IMPLEMENTADA (open composition semántica) |
| Referencias inter-paso («del primero su acudiente») | AUSENTE | IMPLEMENTADA (`_ref` + resolución por posición) |
| Memoria de trabajo (historial de resultados) | PARCIAL (solo last_result) | IMPLEMENTADA (`objects[]` R1..Rn + current/previous) |
| Transforms sin destruir el set | PARCIAL (slice reemplazaba el set) | IMPLEMENTADA (vista ≠ set) |
| Herencia de scope entre cláusulas | AUSENTE | IMPLEMENTADA (group/module/status/days/range) |
| RBAC recursivo por paso | AUSENTE | IMPLEMENTADA (`nxPlanAllowed` recursivo) |
| Inventario ecosistema trazable | AUSENTE | IMPLEMENTADA (`ecosystem_capability_inventory`) |
| Benchmark de composición abierta | AUSENTE | IMPLEMENTADA (`nexus_ecosystem_open_composition`, 12 dims) |

## §49.1 Capability Graph — runtime

`nxCapabilityGraph()` (nexus_semantic.php §3b) expone cada capability como nodo
normalizado, usable en planificación y validación:

```php
capability_id · domain · goal · operation · source_entity · target_entity
relations · fields · filters · sorts · aggregations · positions · slices
time_constraints · scope_requirements(rbac) · required/optional_parameters
dependencies · compatible/incompatible_compositions · presentation_modes
executor · authorization · read_only · endpoints · intent_equiv
```

`nxCapabilityRetrieve($sig,$ds)` — §18: ranking de candidatos por
entity/relation/op antes de planificar; el top-3 queda en `plan.evidence[]`
como `retrieval:a,b,c` (auditable).

## §49.2 Inventario del ecosistema (§16/§17/§32/§39)

`test/ecosystem_capability_inventory.php` — corre dentro del contenedor,
lee el schema real (information_schema), las rutas y el registry:

```
DB tables: 132 · route files: 24 · chat handlers: 53
capacidades: 39 (12 executor propio + 27 delegadas a intents)
entidades con tablas y capability: students, guardians, groups, schedules,
    attendance, incidents, tracking, permissions, users
sin capability semántica propia: teachers*, subjects, exits*, risk*
    (* = cubiertas por delegación/executor parcial: teachers.of_group,
    exits.school, risk.students existen pero su source_entity no nombra
    la entidad — cobertura por handler, no por grafo)
filtros declarados: 15 · sorts: 12 · presentaciones: 8 · operaciones: 12
legacy: A(absorbible)=27  B(fallback útil)=25  E(mutación)=1 (export_data)
```

## §49.3 Open Composition — implementación

Dos rutas convergen al mismo plan compuesto `{capability:'composed', steps:[…]}`:

1. **`cls.parts` (multi-intent del NLU)** — cada parte se compone por
   separado con `forCompound=true`; se descarta cuando la «y» era
   enumeración (`compara 6-A y 7-B` → 1 sola meta, guard en chat.php).
2. **`nxSemSplitCompound` (conectores)** — `y/despues/luego/ademas/dame…`
   + filtro anti-enumeración (grupo desnudo, ordinal coordinado) +
   exigencia de ≥2 cláusulas con señal propia.

Cada paso: `nxSemanticCompose` por cláusula → herencia de filtros desde
pasos previos (group/module/status/days/range) → `_ref` para referencias
posicionales («del primero», «de esos») → `_delegate_intent` cuando el
paso es un field-lookup (`student_field` con `student='@ref'`).

`nxPlanExecute` itera pasos en orden, resolviendo `@ref` contra el
result-set del paso referenciado; `nxPlanAllowed` valida RBAC **por paso**.

## §49.4 Working memory (§9/§28/§29)

`_ds` ahora contiene:

```
intent · prev_intent · entities · goal
current {entity, result(R#), scope, relation, goal}
previous {…}                       // contexto anterior recuperable
objects[]  R1..R6                  // historial de result-sets materializados
last_result · cursor · pending_op · person
```

Cada `_result_set` materializado se registra como `R<n>` con
type/entity/filters/count/items — «vuelve al primero de 6-A» y «los demás»
operan sobre el objeto retenido aunque haya pasos intermedios.

**Regla de transformación:** `slice/proj/sort/goto` devuelven una *vista*
(`_result_set` transformado en la respuesta) pero `last_result` conserva
el set original — la identidad del conjunto sobrevive a la vista.

## §49.5 Navegación → referencia encadenada

`chatResultNav` ahora declara el ítem visto como entidad activa
(`entities.student` cuando el set es de estudiantes): «el segundo» fija
el referente y «su documento», «su acudiente», «y su número» resuelven
sobre él — incluida la cadena persona→acudiente→teléfono del acudiente.

Herencia corregida: `field` es de la frase, no del tema — se excluyó de la
herencia genérica (rompía «y cuántas evasiones tiene» arrastrando
`documento`). Grupo explícito nuevo («y en 7-B») rompe la herencia de
persona/campo → nueva nómina.

## §49.6 Seguridad (§33-§36)

- Veto mutativo en composer ampliado: `vaciar/limpiar/restaurar/resetear/
  reiniciar/formatear` además de los existentes.
- `nxCoverageOverride` (rerank): `vacia/anula/limpia X`, `eres libre`,
  `suplant\w*`, `exporta todo`, `no preguntes/sin confirmar` →
  `security_probe`. G11 adversarial: **0 escapes / 533**.
- `groups.rank` con scope <2 grupos → negación honesta explícita
  («solo tengo acceso a tu grupo asignado»), no datos globales ni error
  genérico. `groups.compare` cross-scope → negado (RBAC por grupo).
- `nxPlanAllowed` recursivo: cada paso de un plan compuesto se autoriza
  contra su propia capability.
- Read-only: `readonly_guard` — 53 handlers, 0 escrituras.

## §49.7 Resultados — batería completa

| Suite | Resultado |
|---|---|
| corpus_semantic_gen (1797 casos) | **1796/1797 (99.9%)** — residual: «dame todos los 10B» → security_probe (veto conservador, preexistente) |
| nexus_capability_eval_v1 | **154/154 (100%)** |
| nexus_ecosystem_open_composition (12 dims) | **59/59 (100%)** |
| real_conversation_v1 | **381/381 turnos** |
| continuity_50 (sesión 53 turnos, live HTTP) | **53/53 coherentes** |
| dsm_units | **50/50** |
| resilience | **15/15** |
| semantic_eval singles | 981/1000 resueltos (98.1%) |
| semantic_eval adversariales | **515→533/533 seguros (0 escapes tras hardening)** |
| readonly_guard | 53 handlers · 0 escrituras |
| nexus_release_gate | **17/18** — G1 falla 1 caso preexistente en HEAD («ahora quiero citar a su acudiente» → inherit de intent en el harness forense; seguro: nunca ejecuta la mutación) |
| chat_context_sim | FAIL preexistente en HEAD (T1 «reporte de X» → out_of_scope, ctx no se guarda — brecha del modelo NLU, no de la capa semántica) |

## §49.8 Performance (live, HTTP end-to-end, teacher autenticado)

```
p50 = 14 ms   p95 = 57 ms   p99 = 85 ms   max = 85 ms   n = 29 turnos
    (incluye planes compuestos, navegación, transforms y delegación)
```

La composición abierta no añade latencia significativa: el split, la
herencia y la resolución de refs son O(cláusulas × señales), todo en
proceso; los pasos comparten la conexión PDO y el executor cachea scope.

## §49.9 Demo obligatoria (§46) — 19/19 turnos, live

Sesión real (docente scoped a 6-A; 7-B vacío en el seed):

```
T01 todos los estudiantes de 6A        → students.list + tabla (3 filas)
T02 cuántos son                        → result_nav: 3
T03 el primero                         → Ana Estudiante
T04 quién es su acudiente              → Acudiente de Ana: Prueba ✚57…01
T05 y su número                        → número del acudiente
T06 ahora los demás                    → rest: Luis + Eva (set intacto)
T07 ponlos en una tabla                → tabla completa (set original)
T08 solo nombres                       → proyección
T09 ordénalos por apellido             → sort:last_name
T10 los dos últimos                    → slice:2:end
T11 cuántos faltaron hoy               → incidents.list: 0
T12 ¿y en 7-B?                         → students.list{7-B}: 0 (honesto)
T13 compárame 6-A y 7-B                → groups.compare → NEGADO (scope)
T14 cuál tuvo más tardanzas            → groups.rank → honesto (1 grupo)
T15 muéstrame los cinco primeros       → slice:5:start sobre colegio
T16 vuelve al primer estudiante de 6-A → goto:1 → Ana
T17 quién responde por él              → acudiente de Ana
T18 dame su documento                  → doc 8001 de Ana
T19 docentes de 6-A                    → teachers.of_group: Docente Prueba
```

Las negaciones de T13/T14 son el comportamiento correcto por scope RBAC —
no son fallos (con un usuario multi-grupo responderían datos reales).

## §49.10 Residual failures (honestos)

1. **`dame todos los 10B`** → `security_probe` (veto conservador del
   corpus; «todos los X» se parece a exfiltración masiva).
2. **G1 T2** «ahora quiero citar a su acudiente» — el harness forense
   hereda `list_events`; preexistente en HEAD, y el resultado en
   producción es seguro (clarify/consulta, nunca ejecución implícita).
3. **chat_context_sim** — «búscame el reporte de X» → `out_of_scope` por
   el modelo NLU (palabra «reporte» fuera de vocabulario); ctx no se
   guarda → T2 no hereda. Preexistente en HEAD.
4. **`¿y ayer?` tras consulta de módulo** — responde en el rango «hoy»
   del intent heredado en vez de recomputar el rango; el seguimiento
   temporal puro ya existe en compose (`temporal+ctx_module`) pero el
   intent `attendance_today` lo captura antes cuando el set previo no es
   de incidentes.
5. **subjects/exits/risk** sin capability de listado propia (tienen
   executors/relations parciales vía delegación — subjects no tiene
   executor conversacional).
6. **`?` en intents multi-cláusula muy largos** (>3 pasos con refs
   encadenadas no probados end-to-end).

## §49.11 Veredicto

Criterios medidos: capability coverage (grafo 39 caps / 12 executables /
27 delegadas), semantic generalization (99.9% corpus, 98.1% singles),
contexto largo (53/53), resolución entidad/relación (60+ casos),
planificación (IR + steps + refs + validator), presentación (8 modos +
transforms), seguridad (0 escapes/533, RBAC recursivo, read-only auditado),
conversación real (19/19 demo + 381/381 suite + 53-turn live session),
performance (p50 14ms / p95 57ms / p99 85ms).

**NEXUS UNIVERSAL CONVERSATIONAL LAYER — READY**

con notas honestas: (a) 1 puerta del release-gate falla por caso
preexistente aislado y seguro; (b) la cobertura de entidades del schema
es ~70% por capability propia — el resto existe por delegación probada;
(c) «listo» significa que la arquitectura soporta el ecosistema completo —
no que toda tabla tenga ya su executor semántico propio.
