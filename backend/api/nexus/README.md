# NEXUS — IA conversacional de NEXO

> **Nexus** es el parser semántico + motor conversacional del chat de NEXO
> (asistente institucional escolar). Este documento describe la arquitectura
> **vigente** verificada contra el código en `HEAD`, no contra documentos
> históricos.

**Componente:** `backend/api/nexus/` (4 librerías PHP) +
`backend/api/routes/chat.php` (entry HTTP). Nexus **no es un servicio
independiente**: corre dentro del proceso PHP-FPM de la API y comparte su
bootstrap, pool PostgreSQL, Redis y contexto RBAC. La única dependencia
externa es el proveedor LLM (HTTP saliente, compatible-OpenAI).

**Arquitectura vigente:** LLM híbrido — el parser es un LLM vía API
(compatible-OpenAI, Groq por defecto) **+ capas deterministas** (normalización,
slots, DSM, SCP, planner semántico, RBAC, executors SQL read-only,
presentación determinista).

**Principio rector:**

> El LLM interpreta y expresa; NEXO decide la verdad, autoriza, consulta
> y presenta.

El modelo de lenguaje **nunca**:

- escribe ni ejecuta SQL;
- decide permisos ni roles;
- modifica la base de datos;
- genera ni modifica cards, filas o tablas de datos;
- puede emitir intents fuera de la whitelist — un intent no permitido se
  convierte en `out_of_scope`.

El modelo de lenguaje **sí puede**:

- clasificar el mensaje del usuario en un intent con entidades;
- abstenerse (`out_of_scope`);
- conversar informalmente bajo guardarraíles institucionales;
- reformular respuestas ya verificadas (composer) sin alterar cifras.

Fuentes de verdad usadas para este documento: `backend/api/routes/chat.php`,
`backend/api/nexus/nexus_llm.php`, `backend/api/nexus/nexus_nlu.php`,
`backend/api/nexus/nexus_scp.php`, `backend/api/nexus/nexus_semantic.php`,
`backend/api/lib/kb_colombia.php`, `frontend/pwa/src/pages/Chat.jsx`,
`frontend/pwa/src/components/patterns/NexoChat.jsx`,
`frontend/pwa/src/api/chat.js`, `frontend/pwa/src/lib/chatContext.js`,
`test/fixtures/llm_intents.json` y la memoria de trabajo `NEXUS_*.md`
(hoy archivada en `_cuarentena/memoria_nexus/`). Las referencias
`archivo:línea` apuntan al código leído.

---

## 1. Propósito

Nexus da a rectoría, coordinación, secretaría, docencia, psicoorientación,
portería y auxiliares un canal conversacional en español natural para:

- consultar la jornada escolar (ingresos, tardanzas, inasistencias,
  evasiones, permisos, citaciones, seguimientos, incidentes, alertas SOS,
  dispositivos, mensajería);
- consultar fichas y campos de estudiantes y acudientes;
- navegar resultados multi-turno («el primero», «los demás», «en tabla»);
- componer consultas (comparaciones, rankings, conteos, porcentajes);
- derivar operaciones mutativas (permiso, citación, seguimiento…) como
  **navegación a formularios autorizados**, nunca como escritura directa;
- conversar (smalltalk, cultura general colombiana, matemáticas básicas)
  con límites institucionales.

Todo lo que sale del chat viene de la base de datos real del colegio del
usuario autenticado o de un repertorio determinista. Si no hay datos, el
sistema lo dice; no inventa.

## 2. Vista de arquitectura

Flujo de un turno (`POST /chat/message`, `routes/chat.php:301`):

```text
Usuario (Chat.jsx)
  │  POST /chat/message {text, session_id, ctx}
  ▼
┌─ Entrada HTTP (chat.php:301-377) ───────────────────────────────┐
│ requireAuth → user/role/school_id                               │
│ validación 1..500 chars · session_id UUID · rate limit Redis    │
│ atajo «otro/siguiente» sin set navegable → repetición con gate  │
└─────────────────────────────────────────────────────────────────┘
  ▼
Parser NLU (nxClassify, nexus_nlu.php:135)
  ├── fixture replay (NX_CLASSIFY_FIXTURE, solo suites)
  ├── parser LLM (nxLlmClassify, nexus_llm.php:200) — intent+entidades
  ├── fusión con slots deterministas nxSlots (nexus_nlu.php:196)
  ├── split multi-intención (chat.php:384) → open composition
  └── umbral 0.65 → out_of_scope honesto
  ▼
Compuerta de seguridad (chat.php:512-524)
  flag `safety` del LLM ∨ nxSafetyScreen() → bloqueo + securityLog
  ▼
DSM — nxDialogueResolve (nexus_nlu.php:909)
  turn_type · herencia de slots · correcciones · referencias · clarify
  ▼
SCP — nxScpFrame/Validate/ToSlots/ToPlan (nexus_scp.php)
  frame semántico validado; puede forzar intent+slots o emitir plan
  ▼
Planner semántico (nexus_semantic.php)
  nxSemanticCompose → plan {capability, entity, op, filters, …}
  nxPlanValidate (estructural) → nxPlanAllowed (RBAC) → nxPlanExecute
  → nxResultValidate (contrato post-ejecución)
  ▼
Gate de intents + dispatch (chat.php:1063, 1609)
  chatAllowed (matriz rol × política escuela) → handler chat_* SQL
  ▼
Presentación + estado (chatLog, chatBuildDs)
  cards/tablas deterministas · composer LLM opcional · _ds persistido
  en chat_messages.payload_json · auditoría CHAT_QUERY
  ▼
Respuesta JSON → UI Nexus (texto, cards, actions, suggestions)
```

Las capas deterministas conservan la autoridad sobre: normalización,
extracción de slots, estado conversacional, interpretación estructurada,
validación, planificación, autorización, grounding SQL, presentación de
tablas, memoria de result-sets y read-only.

## 3. Mapa de archivos

| Archivo | Rol |
|---|---|
| `backend/api/routes/chat.php` | Endpoint `/chat/*`, orquestación del turno, handlers SQL, navegación de result-sets, políticas, auditoría (~3060 líneas) |
| `backend/api/nexus/nexus_llm.php` | Parser LLM, composer de respuestas, chat informal, whitelists, saneamiento de entidades, config `NLU_LLM_*` |
| `backend/api/nexus/nexus_nlu.php` | `nxNorm`, `nxSlots`, `nxExtractStudent`, sinónimos, smalltalk, matriz RBAC `nxAllowed`, DSM `nxDialogueResolve` (~1716 líneas) |
| `backend/api/nexus/nexus_scp.php` | Semantic Conversational Parsing: `nxScpFrame`, `nxScpValidate`, `nxScpToSlots`, `nxScpToPlan`, trazas `NEXO_SCP_TRACE` |
| `backend/api/nexus/nexus_semantic.php` | Registry de 39 capacidades, `nxSemanticCompose`, `nxPlanValidate`, `nxPlanAllowed`, `nxPlanExecute`, `nxResultValidate`, executors (~2030 líneas) |
| `backend/api/lib/kb_colombia.php` | KB determinista de cultura colombiana (departamentos, presidentes, geografía, historia) |
| `backend/api/lib/calculator.php` | `math_operation` determinista |
| `frontend/pwa/src/pages/Chat.jsx` | UI principal: envío, cards, paginación, acciones, exportación, sesiones |
| `frontend/pwa/src/components/patterns/NexoChat.jsx` | Avatar `/imagenbot.png`, burbuja, skeleton |
| `frontend/pwa/src/api/chat.js` | Cliente HTTP de los endpoints `/chat/*` |
| `frontend/pwa/src/lib/chatContext.js` | ctx de compatibilidad en `sessionStorage` (TTL 30 min), solo entidades visibles |
| `test/fixtures/llm_intents.json` | Snapshot de respuestas reales del parser LLM para suites offline |

## 4. Entrada HTTP y límites

`POST /chat/message` (`chat.php:301`):

- `requireAuth()` resuelve `user_id`, `role` y `school_id` (JWT propio de
  NEXO); todo lo demás se deriva de ahí — el cliente no declara rol.
- `text` obligatorio, máx. 500 caracteres (`chat.php:307-311`); el front
  impone el mismo límite en el `<textarea>`.
- `session_id`: UUID v4 del cliente o generado por `chatNewSessionId()`
  (`chat.php:1771`). Permite múltiples conversaciones por usuario.
- `ctx` opcional del navegador: solo se usa como **fallback** si no existe
  `_ds` server-side, y se le quitan `_op` y `_ds` — el cliente nunca puede
  fabricar operaciones pendientes ni estado (`chat.php:537-544`).
- **Rate limit:** 60 mensajes / 10 min por usuario vía Redis `INCR`+`EXPIRE`;
  si Redis cae, el chat sigue funcionando (`chat.php:316-326`).
- Respuesta envelope: `{status:'ok', data:{reply, intent, confidence,
  session_id, cards?, actions?, suggestions?, denied?, _interpretation?}}`.

### Atajo de repetición (`chat.php:350-377`)

«otro», «siguiente», «más» sin result-set navegable recarga el **último
payload de asistente** (`chatLastPayload`, `chat.php:1593`, excluye
respuestas `denied`) y re-despacha su intent. La autorización **no se
hereda**: `chatAllowed` corre de nuevo antes del dispatch, porque las
políticas pueden haber cambiado. `_repeat` indica a handlers aleatorios
(`random_student`, chistes) que elijan otro valor.

### Multi-intención (`chat.php:384-510`)

`nxClassify` parte el mensaje en segmentos por `y/además/también/,`
(`nexus_nlu.php:136-153`), clasifica cada uno (máx. 4, confianza ≥0.55) y
devuelve `parts[]`. En `chat.php` primero se intenta **open composition**:
si todas las partes componen planes semánticos válidos, se arma un plan
multi-paso con `_ref` entre pasos y herencia de scope desde pasos previos
(`chat.php:394-463`). Si no, cada parte pasa por el pipeline completo
(DSM→gate→dispatch) y las respuestas se concatenan. Enumeraciones tipo
«compara 6-A y 7-B» se rearman como consulta única antes de partir
(`chat.php:388-393`).

## 5. Parser LLM (`nexus_llm.php`)

### 5.1 Configuración (`nxLlmCfg`, `nexus_llm.php:23`)

| Variable | Efecto |
|---|---|
| `NLU_LLM_URL` | Base compatible-OpenAI; default `https://api.groq.com/openai/v1` |
| `NLU_LLM_KEY` | API key; **vacío deshabilita el LLM** (parser devuelve null → `out_of_scope`) |
| `NLU_LLM_MODEL` | Modelo; alias histórico `GROQ_MODEL` aceptado |
| `NLU_LLM_MODE` | `off`/`on`; `primary`/`fallback` se normalizan a `on` por compatibilidad |
| `NLU_LLM_TIMEOUT_MS` | Timeout cURL; default 6000 ms, mínimo efectivo 500 ms |
| `NLU_LLM_COMPOSE` | `off`/`data`/`all` — activa el composer de respuestas |
| `NLU_LLM_CHAT` | Activa el chat informal; por defecto sigue al parser si hay key |
| `NLU_LLM_CTX_TURNS` | Turnos compactos de historial para el parser; en `chat.php:345` se acota a 3–40, default 20 |

Sin key o con proveedor caído, `nxLlmClassify` devuelve `null` y el turno
termina en `out_of_scope` honesto — nunca en un intent adivinado
(`nexus_nlu.php:99`, `154-156`).

### 5.2 Llamada (`nxLlmClassify`, `nexus_llm.php:200`)

- `POST {NLU_LLM_URL}/chat/completions` con `nxLlmSystemPrompt()`
  (`nexus_llm.php:176`), `response_format=json_object`, baja temperatura.
- Contexto compacto construido en `chat.php:337-346`: entidades activas
  del `_ds`, descriptor del result-set (tipo, etiqueta, conteo — no los
  ítems) y los últimos N turnos (`chatRecentTurns`, `chat.php:231`).
- Respuesta esperada: `{intent, confidence, entities{…}, safety?}`.
- `confidence` se recorta a `[0,1]`; `intent` se valida contra las
  whitelists — lo no permitido se convierte en `out_of_scope`.

### 5.3 Whitelist de intents

`NX_LLM_FORMAL` (`nexus_llm.php:45`) — capacidades de datos/operación que
el parser puede emitir:

```text
day_summary attendance_today late_today count_events list_events
student_field student_summary group_summary risk_students trackings
permissions citations devices_status notifications_unread audit_query
students_count groups_list teachers_list schedule_info export_data
derive_action about_me help capabilities security_probe random_student
staff_lookup start_operation count_present count_trackings
students_in_group top_offenders pending_returns sos_alerts
biometric_spam group_student_count birthdays_today my_activity
failed_messages risk_config attendance_ranking session_summary
pending_tasks whatsapp_status frequency_table
```

`NX_LLM_INFORMAL` (`nexus_llm.php:58`) — dominio social/general: smalltalk
(`greeting`, `joke`, `thanks`, `insult`, …), cultura general sobre
Colombia (`colombia_capital`, `colombia_department`, `colombia_president`,
`colombia_history`, `colombia_geography`, `colombia_fun_fact`,
`random_department`), `foreign_culture`, `math_operation`, `random_number`,
`time`, `date`.

Intents internos del motor que **no** emite el parser pero que el DSM/
pipeline producen: `repeat_op`, `confirm_op`, `result_nav`, `clarify`,
`deictic`, `composed`, `safety_guard`, `cancel`, `repeat`.

### 5.4 Saneamiento de entidades

El parser puede devolver claves como `student`, `group`, `module`,
`field`, `days`, `from`, `to`, `person`, `grade`, `shift`, `search`,
`range_label`, `nav`, `position`, `relation`, `presentation`,
`export_format`, `compare`, `topic`, `target_role`, `op`, `group_by`,
`trend`, `justified`, `status`, `scope`, `detail`, `needs`.

Dentro de `nxLlmClassify` se aplican, antes de que nada entre al pipeline:

- allowlists/enums para `group_by`, `justified`, `status`, `scope`,
  `relation`, `presentation`, `export_format`;
- límites de longitud por campo;
- `student`/`person` se limpian contra `nxStudentStopwords()`
  (`nexus_nlu.php:350`) — una lista extensa de vocabulario del dominio,
  temporales, pronombres y colectivos que **nunca** son un nombre;
- literales de alcance inválidos (`all`, `todos`, `mis`) se descartan;
- `days` y `position` se convierten a numérico;
- arrays `compare`, `detail`, `needs` se acotan;
- se conservan `safety` y `_uses_context`.

Después, en `nxClassify` (`nexus_nlu.php:157-185`), las entidades del
parser se **fusionan con** `nxSlots` (los slots deterministas mandan en
lo estructural) y el contrato amplio se traduce a slots internos:
`nav`→`_nav` (con mapeo `others`→`rest`, `another`→`next`, `last`→
posición, `nth:N`), `presentation`→`_presentation`, `export_format`→
`_export_format`, `compare`→`_compare`, `relation`→`_ref`, `op`→`_op`,
`_except` limpia un `group` que era exclusión.

### 5.5 Modos de fallo

| Condición | Resultado |
|---|---|
| Sin `NLU_LLM_KEY` / modo `off` | `null` → `out_of_scope`, `source:'none'` |
| Proveedor caído / timeout / JSON inválido | igual: `out_of_scope` honesto |
| Confianza < `NX_NLU_THRESHOLD` (0.65) | intent pasa a `out_of_scope` + flag `fallback` (`nexus_nlu.php:186-189`) |
| Intent fuera de whitelist | `out_of_scope` |
| `safety:'risky'` | bloqueo en compuerta de seguridad |

El `out_of_scope` no es un callejón: el DSM puede rescatar el turno por
contexto (herencia, módulos, conteos desnudos) y el chat informal puede
responderlo como conversación.

## 6. Normalización y slots deterministas (`nexus_nlu.php`)

### 6.1 `nxNorm` (`nexus_nlu.php:34`)

Minúsculas, eliminación de acentos (incluye `ñ`→`n`), puntuación a
espacios y una tabla conservadora de corrección ortográfica del dominio
(`presnetes`→`presentes`, `tardansas`→`tardanzas`, `acudinte`→
`acudiente`, ~20 entradas) que solo toca vocabulario del sistema, jamás
nombres propios.

`nxToday()` (`nexus_nlu.php:26`) fija el día civil en
`America/Bogota`: «hoy»/«ayer» son el día local del colegio, no el UTC.

### 6.2 `nxSlots` (`nexus_nlu.php:196`)

Extracción estructural que siempre corre en PHP, aunque el parser traiga
sus propias entidades (el merge es `nxSlots` + entidades LLM, con los
slots deterministas como autoridad estructural):

- **Rangos temporales** (`days`/`from`/`to`/`range_label`): `hoy`→0,
  `ayer`→1, `esta semana`→7, `este mes`→30, `semana pasada`→14,
  `mes pasado`→60, `este año`→365, `últimos N días`, `N semanas`→N·7.
  Los períodos pasados específicos se evalúan **antes** que los genéricos
  («del mes pasado» no es «del mes»).
- **Grupo**: `6A`, `7-1`, `10.A`, `prescolar`, `jardin`, `transicion`,
  ordinales textuales (`octavo a`→`8A`, `grado noveno`→`9`), y alias de
  scope propio `*mine*` («mi grupo») / flag `_my_scope` («mis grupos»).
  Los ordinales desnudos tienen guardas para no confundir «el primero de
  la lista» (posición) con «del primero» (grado 1).
- **Módulo** por `nxModuleSynonyms()` (`nexus_nlu.php:536`): canon a
  `LATE_ARRIVAL`, `INASISTENCIA`, `INASISTENCIA_JUSTIFICADA`,
  `INASISTENCIA_NO_JUSTIFICADA`, `EVASION_INTERNA`, `PERMISO`,
  `SALIDA_BAÑO`, `SALIDA_COLEGIO`, `SOS`, `CITACION`, `SEGUIMIENTO`,
  `INCIDENTE`, `DAÑO`, `INGRESO`, `SALIDA_PEDAGOGICA`. Con bordes de
  palabra estrictos («inasistieron» no cuenta como ingreso) y guarda de
  saludo («buenas tardes» no es tardanza). La negación de asistencia
  («no vinieron», «faltaron») fija `INASISTENCIA` aun sin sinónimo.
- **Campo de estudiante** por `nxFieldSynonyms()` (`nexus_nlu.php:556`):
  `documento`, `celular`, `acudiente` (incluye paráfrasis relacionales:
  «quién responde por», «a cargo de»), `grupo`, `jornada`, `nacimiento`,
  `estado`. Siglas ≤3 chars solo cuentan como palabra completa.
- **Estudiante**: `nxExtractStudent` (`nexus_nlu.php:498`) — patrones con
  marcador de persona («el estudiante X», «la niña X»), conector
  de/de/para/… y nombre+«del <grado>»; el candidato se filtra contra la
  stopword list completa.
- **Modificadores**: exclusión `_except` («todos menos los del 8A»),
  exclusividad `_only`, justificación `justified=yes|no` («con excusa» /
  «sin justificar»), umbral `threshold` («más de 3», «al menos 2»).
- **Regiones** (`nxRegions`, `nexus_nlu.php:54`): ~100 departamentos,
  ciudades y gentilicios geográficos de Colombia y del extranjero se
  marcan en `_regions`/`topic` y **anulan** un `student` espurio — un
  lugar nunca es estudiante.

### 6.3 `nxClassify` (`nexus_nlu.php:135`)

Orquesta: split multi-segmento → `nxClassifyCore` (fixture → LLM) → merge
con `nxSlots` → traducción de contrato amplio → umbral. El resultado:
`{domain, intent, confidence, entities, top3, source}` donde
`source ∈ {fixture, llm, multi, none}`.

Fixture replay (`nxClassifyCore`, `nexus_nlu.php:109-133`): con
`NX_CLASSIFY_FIXTURE=<path>` las respuestas del parser se sirven del
snapshot `test/fixtures/llm_intents.json` (indexado por texto
normalizado) — suites deterministas offline sin gastar cuota.
`NX_CLASSIFY_LOG` registra los textos normalizados para regenerar el
fixture con `test/gen_llm_fixture.php`.

## 7. DSM — `nxDialogueResolve` (`nexus_nlu.php:909`)

Única fuente de verdad de herencia contextual; la consume `chat.php` y el
harness de tests (`test/harness_turn.php`) — paridad garantizada.

Contrato de salida (`nexus_nlu.php:1700-1715`):

```php
['said'=>['normalized'], 'inferred'=>{domain,intent,confidence,top3,entities},
 'resolved'=>{intent, slots, confidence, inherited[], new_slots[], selfcheck},
 'turn_type', 'explicit_action', 'followup_mark', 'correction_mark',
 'requires_clarification', 'clarify', 'ctx']
```

### 7.1 Tipos de turno

`new_request` · `autonomous` · `context_modify` · `correction` ·
`confirmation` · `cancel` · `op_repeat` · `intent_switch` · `deictic`.

Marcadores detectados sobre el texto normalizado: `followupMark`
(«y/ahora/pero/las/esas/su…» al inicio, con guarda para «ahora cuántos…»
que es consulta nueva), `explicitAction` (verbos de acción),
`correctionStrong` («digo», «me refería», «corrijo») /
`correctionWeak` («no», «perdón» + slot nuevo), `confirmMark`,
`cancelMark`.

### 7.2 Reglas principales (en orden)

1. **Corrección explícita** — el intent previo se mantiene; los slots
   nuevos (student/group/module/days/field…) **reemplazan** al heredado,
   no se acumulan.
2. **Herencia de slots** solo en turnos dependientes (regla F): marcador
   de seguimiento, verbo deíctico («acumula», «tiene», «lleva»),
   interrogativo sin sustantivo de tema («cuáles fueron justificadas»),
   sustantivo desnudo de campo («documento», «el teléfono»), o deícticos
   espaciales («ahí», «del mismo»). `days=0` («hoy») es valor válido.
   `field` no se hereda.
3. **Navegación sobre el result-set activo** (`hasResult`):
   transformaciones `proj:name`, `proj:+document|+phone|+group`, `sort:*`,
   `slice:N:start|end`, `goto:N`, `next/prev/nth:N`, `all`, `rest`,
   `table`, `count`, `name` → `slots._nav` + `turn_type=context_modify`.
   Un set vacío sigue siendo un set: «los demás» sobre 0 ítems es nav
   honesta («no hay nada»), no consulta nueva.
4. **Ordinales posicionales**: «del primero/segundo/último» con referente
   activo (set, grupo en contexto o campo relacional) es posición sobre
   el tema, nunca grado — se quita `group` y se fija `position`/`_nav`.
5. **Referencias posesivas** «su <campo>», «el documento de su
   acudiente», «su número» (si el persona activa es `guardian` →
   `celular_acudiente`): fijan `field`, heredan `student` del contexto y
   rerutean a `student_field`. Los verbos de operación se excluyen —
   «citar a su acudiente» es operación, no consulta de campo.
6. **Turnos temporales puros** («y ayer», «y la semana pasada») con tema
   heredable: conservan `last_intent`, solo cambian el rango.
   `prev_days` guarda el rango anterior para «vuelve al mes».
7. **Conteos desnudos contextuales** («¿y cuántos son en total?»):
   nómina/estudiantes → `group_student_count`; con módulo →
   `count_events`. Marca `$coverageHit` para que resoluciones genéricas
   no lo pisen.
8. **Sustantivo de módulo rescata out_of_scope**: «permisos que ha
   tenido X» → `permissions`, «las citaciones del mes» → `citations`,
   «seguimientos activos» → `trackings`, módulos de eventos →
   `list_events`/`count_events` — salvo que haya verbo de operación.
   «compara/ranking … por grupos» → `attendance_ranking` +
   `group_by=group`.
9. **`_my_scope`** («mis grupos», «los que tengo a mi cargo») →
   `groups_list` — gana sobre la herencia de tema.
10. **Posesivos/deícticos de sujeto** («su ficha», «para ella», «ese
    estudiante», «su grupo») recuperan `student` del contexto;
    `group_of_student` marca `_ref` para resolución vía BD.
11. **Repetición de operación** (`repeat_op`): «otro para Camila» reutiliza
    el `_op` pendiente o el inferido del intent (`permissions`→Generar
    permiso, `citations`→Citar acudiente, `trackings`→Solicitar
    seguimiento). Un mensaje puramente temporal («otro día») es rango,
    no operación.
12. **Verbo de operación domina**: `citar/generar/autorizar/derivar/…`
    rerutea intents de consulta a `derive_action`; `exportar/descargar/
    sacar a excel` → `export_data`; corrección de operación («no, mejor
    una citación») conserva el flujo; verbo destructivo + datos
    («borra las tardanzas») → `security_probe`.
13. **Herencia bajo umbral**: intent `out_of_scope` o confianza <0.65 en
    turno dependiente sin sustantivo de operación hereda `last_intent`.
14. **Confirmación/cancelación**: `confirmMark` con tema activo →
    `confirmation`; `cancelMark` → `cancel`.
15. **Deícticos sin información** («¿y ahora?» sin tema) → `deictic` +
    `clarify` explícito: el sistema pregunta en vez de adivinar.

### 7.3 Contexto resultante

- Smalltalk no cambia el tema (el ctx se conserva entero — «el mismo
  grupo» tras «gracias» sigue resolviendo).
- `confirmation`/`cancel`/`op_repeat` fusionan slots nuevos sobre el ctx.
- `out_of_scope` conserva las entidades del mensaje («Camila del séptimo»
  sin intent → «su documento» la recupera) pero **no** cambia
  `last_intent`.
- `_op` pendiente se preserva entre turnos salvo cancelación.
- `selfcheck` (`strong`/`borderline`/`abstained`) sella la evidencia de la
  interpretación final para auditoría.

## 8. SCP — Semantic Conversational Parsing (`nexus_scp.php`)

Capa de significado entre DSM y planner. No ejecuta ni inventa: produce
un **frame semántico** normalizado, validable y trazable. Envoltura en
`chat.php:571-639` dentro de `try/catch` — un error del SCP nunca rompe
el turno.

### 8.1 El frame (`nxScpFrame`, `nexus_scp.php:298`)

```php
['task', 'domain', 'subject'=>{name, entity, source, qualifies, evidence},
 'relation', 'field', 'targets', 'filters', 'scope'=>{kind,label,inherited},
 'time_range'=>{from,to,days,label,inherited}, 'aggregation',
 'ranking'=>{metric,order,limit}, 'output', 'references',
 'transformations', 'corrections', 'position', 'pending',
 'confidence'=>{nlu,frame,signals}, 'evidence', '_source_intent']
```

- **Tarea** (`nxScpTask`, `nexus_scp.php:27`): `lookup | count |
  aggregate | compare | rank | filter | relation | navigate | transform |
  general | out_of_scope | correct | clarify` — cada decisión lleva
  evidencia `lex:*`/`sig:*`/`nav:*`. La corrección siempre gana
  (meta-conversación); el superlativo → `rank`; `vs/contra/compara` →
  `compare`; `cuántos` → `count` (con guarda: un campo de persona es dato,
  no agregación); seguimiento corto que solo cambia módulo/rango hereda
  la tarea activa; campo relacional o `sig.relation` → `relation`;
  umbral de riesgo → `filter`; `_nav` → `navigate`/`transform`.
- **Sujeto** (`nxScpSubject`): prioridad *explícito > referencia > ítem
  activo > herencia* — resuelve «su», «ese», «el primero» contra la
  memoria conversacional, nunca re-pregunta lo ya resuelto.
- **Correcciones** (`nxScpCorrections`): detectan correcciones de grupo,
  límite, sujeto, tiempo… que actualizan el plan activo.
- **Alcance**: grupo explícito (normalizado a forma canónica `10-A`) >
  scope docente («mis grupos») > grupo heredado > colegio.
- **Confianza del frame**: mezcla NLU con soporte estructural de señales
  (`0.4 + 0.15×evidencias + bonos por sujeto/rango/scope`, acotado a 1).
- **Targets**: si el mensaje es compuesto (`nxSemSplitCompound`), cada
  cláusula conserva su propia tarea.

### 8.2 Validación (`nxScpValidate`, `nexus_scp.php:423`)

Contrato por tarea — un frame inválido se rechaza antes de planificar:
`relation` exige sujeto+campo; `count`/`rank` exigen contexto (sujeto,
filtros o rango); `compare` exige dos grupos; `navigate` exige
referencias/set/transformaciones; `correct` exige corrección tipada.
Errores tipados (`relation_sin_sujeto`, `count_sin_contexto`,
`compare_requiere_dos_grupos`, …).

### 8.3 Salidas del SCP

- `nxScpToSlots` (`nexus_scp.php:461`): traduce a `[intent, slots,
  forced]`. `rank`→`top_offenders` (+`_rank_limit`, `_my_scope`),
  `compare`→`groups_compare`, `filter`→`risk_students`, `count`→
  `group_student_count`/`count_events`, `relation`→`student_field`
  (con `_ref=guardian` cuando aplica); `navigate`/`transform` solo afinan
  `_nav`. `forced=true` sobreescribe el intent del DSM — **excepto**
  cuando el DSM ya resolvió una operación/exportación y el frame
  degradaría a consulta: «cita al acudiente de X» no se convierte en
  `student_field` (`chat.php:600-631`).
- `nxScpToPlan` (`nexus_scp.php:537`): plan especializado para
  `compare` con dos grupos → `capability:'groups.compare'`, que pasa por
  `nxPlanValidate` + `nxPlanAllowed` como cualquier plan.
- Correcciones tipadas se manejan en `chatScpCorrections` (pueden
  modificar el set activo, el límite del ranking o el sujeto sin
  re-consultar).
- `nxScpTrace` (`nexus_scp.php:562`): con `NEXO_SCP_TRACE=1` emite una
  línea por capa — `INPUT → FRAME → ENTITIES → ACTIVE → INHERITED →
  PLAN → RBAC → EXECUTION → RESULT → MEMORY` — a `error_log`.

## 9. Capa semántica (`nexus_semantic.php`)

Del mensaje al resultado por composición estructural:

```text
mensaje → señales → plan IR verificable → validación → RBAC → SQL real
        → presentación → result-set navegable → nuevo contexto
```

Reglas duras declaradas en el encabezado del archivo: solo lectura, todo
query con `school_id` + scope docente, composer determinista (sin
evidencia estructural devuelve `null` y el pipeline de intents decide),
orden canónico `last_name, first_name`, cardinalidad `all`/`table`
entrega todas las filas.

### 9.1 Registry de capacidades (`nxCapabilityRegistry`, `nexus_semantic.php:36`)

39 capacidades reales del ecosistema — no intents. Cada entrada declara
`name`, `description`, `user_goal`, `action_type`, `source_entity`,
`target_entity`, `fields`, `filters`, `sorting`, `aggregation`,
`pagination`, `time_scope`, `required_context`, `required_parameters`,
`related`, `nearby`, `endpoints` (endpoints REST equivalentes del
ecosistema), `service`, `query`, `response_shape`, `presentation`,
`rbac`, `read_only`, `exec`, `intent_equiv`.

```text
students.*        list · count · position · percent · detail · field
                  · of_guardian
guardian(s).*     of_student · of_group
teachers.*        of_group · list
groups.*          list · compare · rank
attendance.today
incidents.*       list · count · position
students.top      (reincidencia)
permissions.*     active · pending
exits.school
schedule.*        of_group · info
risk.students  trackings.active  citations.list
devices.status  notifications.unread  messages.failed  whatsapp.status
sos.alerts      biometric.spam
audit.query     my.activity       day.summary   birthdays.today
staff.lookup    operations.derive
```

`exec` decide el ejecutor: nombre propio en el archivo (executors
propios), `intent:<intent>` (delega al pipeline de intents, con
`intent_equiv` como equivalencias declaradas) o `ui` (capacidad mutativa:
el chat solo navega).

`nxCapabilityGraph`/`nxCapabilityRetrieve` (`nexus_semantic.php:1014`,
`1052`) exponen el registry como grafo consultable.

### 9.2 Composición (`nxSemanticCompose`, `nexus_semantic.php:718`)

Con `nxSemSignals` (`:515`, señales estructurales: entidad, relación,
posición, filtros, evidencia) construye el plan solo si hay soporte —
si no, devuelve `null` y el turno sigue por el pipeline de intents.
También se usa para composición abierta multi-parte (`chat.php:437`,
`957`) con `nxSemRefOf` (`:998`) para referencias entre pasos
(`_ref:{step,pos}`, posiciones `-1`/`-2` = último/penúltimo, `each` =
por ítem, acotado a 12).

### 9.3 Validación del plan (`nxPlanValidate`, `nexus_semantic.php:1080`)

Estructural, antes de ejecutar:

- capability registrada; `read_only` obligatorio; `effect≠READ` rechazado
  (`non_read_operation`);
- `exec`/`_delegate_intent` deben coincidir con lo declarado por la
  capacidad (`invalid_executor`);
- `op` debe estar en el conjunto permitido que deriva de `action_type`,
  `aggregation`, `pagination` de la capacidad (`unsupported_operation`);
- `required_parameters` (`missing_parameter:*`);
- reglas de dominio: `groups.compare` exige `group`+`group2`,
  `students.of_guardian` exige sujeto;
- `position` ∈ 1..500 | `last` | `last-N`; `slice` = `{n≥1, from∈
  {start,end}}`;
- planes compuestos: cada paso se valida recursivo (profundidad ≤3) y las
  `_ref` solo pueden apuntar hacia atrás — sin ciclos ni forwards
  (`invalid_reference`).

`nxPlanFailure` (`:1165`) traduce cada código a una respuesta específica
(«¿Qué dos grupos comparo?», «¿De qué grupo?», …) — nunca un «no puedo»
genérico.

### 9.4 Autorización (`nxPlanAllowed`, `:930`)

El plan se autoriza por capacidad, no por el intent superficial: cada
paso de un plan compuesto se verifica por separado; `rbac:'ALL'` pasa a
todo rol y `rbac:'chatCanAction'` pasa porque las operaciones navegan
(la UI destino autoriza); de lo contrario el rol debe estar en la lista
`rbac` y, para TEACHER/COUNSELOR, la capacidad se mapea a las mismas
llaves de `school_chat_policies` (`chat.teacher.*`) — un plan también
puede quedar denegado por política institucional.

### 9.5 Ejecución (`nxPlanExecute`, `:1228`)

- Plan compuesto: corre cada paso en orden; `_ref` materializa el ítem
  del result-set del paso origen (`step`, `pos` — con `each` ejecuta por
  ítem hasta 12); las cards de cada paso se acumulan; el último
  result-set queda como activo.
- Paso único: `nxPlanExecuteStep` (`:1279`) despacha al `exec` declarado.
  Executors propios en el archivo: `nxExecStudents` (`:1346`),
  `nxExecGuardiansOfGroup` (`:1603`), `nxExecStudentsOfGuardian` (`:1659`),
  `nxExecTeachersOfGroup` (`:1713`), `nxExecGroupSchedule` (`:1755`),
  `nxExecIncidents` (`:1814`), `nxExecGroupsCompare` (`:1948`),
  `nxExecGroupsRank` (`:1995`); utilidades `nxSemRange` (`:1331`),
  `nxSemGroupId` (`:1339`), `nxScopeGroupIds` (`:1941`). El resto delega
  vía `intent:*` a los handlers de `chat.php`.
- Toda consulta parametrizada, con `school_id` ligado y scope docente.

### 9.6 Validación del resultado (`nxResultValidate`, `:1195`)

Contrato post-ejecución conservador — solo falla cuando ambos valores
existen y difieren: grupo pedido = grupo ejecutado (`result_mismatch:
group`, con normalización `8A`↔`8-A`), módulo conservado, posición
ubicada, rango temporal conservado, sujeto conservado. Un mismatch se
convierte en `nxPlanFailure`, nunca en datos silenciosamente distintos.

## 10. Seguridad y autorización

Cuatro barreras independientes, en orden de ejecución:

### 10.1 Compuerta de seguridad (`chat.php:512-524`)

Antes de DSM/SCP/dispatch. Dos detectores: el flag `safety:'risky'` del
parser LLM **y** `nxSafetyScreen()` (`chat.php:253`), backstop
determinista generalista por categorías de riesgo (sexualización hacia
menores, falsificación/eliminación de registros, extracción de
credenciales, instrucciones de hackeo) — nunca un intent por tema. El
bloqueo: `securityLog('CHAT_SAFETY_GUARD', …)`, respuesta fija seria,
`denied:true`, **sin alterar el contexto** (`_ds` se reenvía intacto).
También inspecciona `parts[]` en mensajes multi-intención.

### 10.2 RBAC por intent (`nxIntentRoles`, `nexus_nlu.php:789`)

Matriz estática intent→roles. Convenciones: `$ALL` (los 7 roles),
`$STAFF` (RECTOR/COORDINATOR/SECRETARY/TEACHER/COUNSELOR), `$GLOBAL`
(RECTOR/COORDINATOR/SECRETARY). Ejemplos: `audit_query` solo RECTOR;
`devices_status`, `risk_config` solo RECTOR+COORDINATOR; `sos_alerts` y
`biometric_spam` añaden SECURITY; `failed_messages`, `whatsapp_status`
solo GLOBAL; `notifications_unread`, `my_activity`, `birthdays_today`,
`session_summary`, `pending_tasks`, `staff_lookup`, `about_me`, `time`,
`date` para todos. `nxAllowed` (`:845`) niega por defecto cualquier
intent de datos sin entrada en la matriz; solo pasan smalltalk/meta/
utilidades conocidas.

### 10.3 Políticas de escuela (`NX_CHAT_POLICY_MAP`, `chat.php:110`)

`school_chat_policies` permite a cada colegio desactivar capacidades
para roles acotados (TEACHER/COUNSELOR): `chat.teacher.risk_students`,
`chat.teacher.student_fields`, `chat.teacher.aggregates`,
`chat.teacher.derive_actions`, más el interruptor global
`chat.smalltalk.enabled`. Defaults = permitido; tabla ausente → TRUE
(`chatPolicyEnabled`, `chat.php:135`). `chatAllowed` (`chat.php:150`)
combina matriz + política y audita los probes negados
(`CHAT_SECURITY_PROBE denied-by-gate`). `GET/POST /chat/policies`
(`chat.php:1730`, `1746`) — solo RECTOR/COORDINATOR; cada cambio queda en
`global_audit_logs` (`CHAT_POLICIES_UPDATED`).

### 10.4 Scope de datos (`chatScope`, `chat.php:39`)

Roles globales ven todo el colegio; TEACHER/COUNSELOR y demás roles
acotados llevan `AND s.student_id IN (… teacher_group_access …)` en cada
consulta sobre estudiantes. `school_id` siempre ligado en el WHERE —
aislamiento multi-tenant por colegio.

### 10.5 Otras garantías

- **Read-only**: ningún handler ni executor escribe. Las mutaciones
  existen solo como navegación (ver §13).
- **SQL parametrizado** en todos los handlers/executors; sin SQL libre ni
  construcción con input crudo (los pocos identificadores dinámicos son
  literales internos como `LIMIT {$limit}` ya acotado a 1..20).
- **Auditoría**: cada mensaje → `global_audit_logs` (`CHAT_QUERY` con
  texto truncado a 200 chars, intent, confianza) (`chat.php:1826-1830`);
  eventos de seguridad vía `securityLog` (`CHAT_SECURITY_PROBE`,
  `CHAT_SAFETY_GUARD`, `CHAT_HANDLER_ERROR`).
- **Denegación inerte**: `denied:true` no altera `_ds` ni el tema
  conversacional (`chat.php:1063-1067`); el atajo de repetición excluye
  payloads denegados (`chat.php:1596`).
- **Handler caído**: `Throwable` en dispatch → `securityLog` + mensaje
  honesto, sin stack ni datos (`chat.php:1639-1644`).
- **Rate limit** 60 msg/10 min (§4).

## 11. Dispatch y handlers (`chat.php`)

`chatDispatch` (`chat.php:1609`):

- `help`/`capabilities` → `chatHelp($role)` — capacidades reales del rol
  (`chat.php:1837`).
- Smalltalk/meta → intenta `nxLlmChat` (chat informal LLM, §14.2) y cae a
  `nxSmalltalk` (repertorio determinista, `nexus_nlu.php:643`).
  `security_probe` registra y responde fijo — no pasa por el LLM.
- Intent de datos → `chat_<intent>` (con alias
  `notifications_unread`→`chat_notifications`, `audit_query`→
  `chat_audit`). Handler ausente → `out_of_scope`; excepción → mensaje
  honesto logueado.
- Toda la respuesta pasa por `nxPlanResponse` (`nexus_nlu.php:899`): si el
  handler no produjo reply ni cards, devuelve fallo explícito y sella
  `source`/`intent` (procedencia auditable).

Los handlers `chat_*` (`chat.php:1857` en adelante) son SQL read-only
parametrizado sobre las tablas reales: `attendance_incidents`,
`biometric_events`, `students`, `student_group_assignments`,
`academic_groups`, `class_exit_authorizations`, `student_tracking`,
`notifications`, `guardians`, `edge_devices`, `global_audit_logs`,
`school_schedule_config`, `chat_messages`, etc. Patrones comunes:
`chatScope` en el WHERE, `nxToday` para rangos, resolución fuzzy de
estudiante (`chatResolveStudent`, `chat.php:53` — translate sin acentos,
nombre en ambos órdenes, o `document_number` exacto, LIMIT 3; >1 fila =
ambigua y el handler pregunta) y de grupo (`chatResolveGroup`,
`chat.php:83`). Emisión de `_result_set` para navegación posterior.

`export_data` no es un handler de escritura: produce la card/tabla con el
formato pedido y la descarga la hace el cliente (§15).

## 12. Result-sets, navegación y multi-turno

### 12.1 `_result_set`

Los handlers y executors emiten un descriptor:

```php
'_result_set' => ['type'=>'students|events|ranking|frequency|…',
  'label'=>'…', 'items'=>[['id','label','sub','f'=>{fn,ln,…}]],
  'columns'=>[…], 'rows'=>[[…]], 'count'=>N]
```

`chatLog` (`chat.php:1787-1794`): si un handler materializó
`columns`+`rows` sin emitir card, la UI la recibe igual — las consultas
viven como tabla, nunca como texto plano.

### 12.2 `chatResultNav` (`chat.php:1469`)

Operaciones sobre el set activo (todas sobre datos ya traídos — `rest`
no re-consulta implícitamente):

| `_nav` | Efecto |
|---|---|
| `count` | total del set (0 si vacío) |
| `name` | nombre del ítem en el cursor |
| `first`/`prev`/`next`/`nth:N` | mueve cursor y muestra el ítem |
| `all` | set completo |
| `table` | card con `columns`+`rows` reales |
| `rest` | los que faltan tras el slice actual |
| `sort:*` | reordena (`first_name`/`last_name`/`document`/`group`) |
| `slice:N:start|end` | primeros/últimos N |
| `goto:N` | salto directo |
| `proj:name`, `proj:+document|+phone|+group` | proyección/añade columna |

La posición se acota al set; un `nth` fuera de rango responde con el
borde. La navegación posicional puede materializar el estudiante
(re-ejecuta la consulta heredada para fijar el sujeto activo). El parser
no debe copiar `student` cuando la navegación ya define el sujeto.

### 12.3 Estado conversacional `_ds`

Autoridad server-side en `chat_messages.payload_json._ds`:
`chatLoadDs` (`chat.php:1124`) lee el último payload de asistente con
`_ds` de la sesión; `chatBuildDs` (`chat.php:1144`) construye el estado
del próximo turno:

- `entities`: tema activo (student/group/module/days/prev_days/from/to/
  range_label/field/_op) — lo que `nxDialogueResolve` hereda;
- `intent`, `turn_type`, `last_reply`;
- `last_result` + `cursor` — el set navegable y la posición;
- `person` — persona activa tipada (`type:'guardian'` cambia a quién se
  refiere «su número»);
- `objects` + `next_rid`: objetos con identidad `R1`, `R2`, … (filtros y
  conteos persistidos; los ítems con PII quedan en `last_result`);
- `pending_targets`, `last_plan`, `last_execution`, correcciones,
  referencias, `lineage` (trazabilidad de cómo se formó el tema).

Ruido que **no** actualiza tema: `out_of_scope`, clarify, denied,
smalltalk (`chat.php:1155-1159`). El `ctx` del cliente es solo
fallback (§4).

## 13. Operaciones derivadas — nunca escritura

El chat no ejecuta mutaciones. Los intents `derive_action` /
`start_operation` y las acciones derivadas producen **chips de
navegación** a `/operacion?cmd=<Comando>` (con `&student=<id>` cuando hay
sujeto), donde el formulario real vive con su propio RBAC y
confirmación (`chat.php:1711-1724`):

- `chatOperationCmd` (`chat.php:200`): palabra clave → título exacto del
  comando en `Operation.jsx`, con límite de palabra («solicitud» no
  dispara «citar») y precedencia de específico sobre genérico (SOS >
  salida pedagógica > autorizar salida > solicitud > citación > permiso >
  daño > cambio de horario > fusionar/extender bloque > registro manual >
  incidente → default «Solicitar seguimiento»).
- `chatCanAction` (`chat.php:170`): matriz rol×acción — p.ej. «Autorizar
  salida» solo RECTOR/COORDINATOR; «Fusionar bloque» solo TEACHER;
  «Reportar daño» incluye SECURITY/AUXILIARY.
- `chatActionChip`/`chatDerivedActions` (`chat.php:266`, `273`): chips
  `{kind:'nav', label, to}`; los derivados aparecen cuando un resultado
  cruza umbral (riesgo, reincidencia).
- `POST /chat/action` valida `chatCanAction` de nuevo y devuelve el
  destino; la escritura real ocurre en `/operations/execute`, fuera del
  chat.

## 14. Respuestas, composer y chat informal

### 14.1 Presentación determinista

- `reply` en español natural con cifras del handler; `cards` =
  `{title, columns[], rows[][]}` para la UI; `actions` = chips;
  `suggestions` = siguientes pasos.
- Nulos se renderizan `—` en el front; `all`/`table` entregan todas las
  filas (nunca «5 y pide otro» cuando pidieron la lista completa).

### 14.2 Composer LLM (`nxLlmComposeReply`, `nexus_llm.php:107`)

Activado por `NLU_LLM_COMPOSE` (`off`/`data`/`all` —
`nxLlmComposeMode`, `:81`). Corre dentro de `chatLog`
(`chat.php:1795-1806`) sobre la respuesta ya verificada: recibe el texto
del usuario, el reply determinista, los datos/cards serializados y los
últimos 2 turnos (`_recent`, no persistido); el prompt
(`nxLlmComposerPrompt`, `:86`) exige no alterar cifras, fechas ni nombres.
Si falla o devuelve vacío, queda el reply original; el compuesto se
guarda en `reply` y el original en `reply_raw`. Nunca toca cards.

### 14.3 Chat informal (`nxLlmChat`, `nexus_llm.php:359`)

`nxLlmChatEnabled` (`:328`) + `NLU_LLM_CHAT`: para intents smalltalk/
meta/out_of_scope conversacionales, el LLM conversa con el historial
real (últimos 4 turnos) bajo `nxLlmChatPrompt` (`:333`) — persona
institucional, sin inventar datos del colegio. `security_probe` queda
excluido (respuesta determinista fija). Si el LLM no está disponible o
responde vacío, cae a `nxSmalltalk()` — repertorio por intent con
variación (`nxPickNoRepeat` evita repetir el chiste/dato anterior). La
KB de Colombia (`kb_colombia.php`) y la calculadora
(`calculator.php`) responden deterministas a `colombia_*` y
`math_operation`.

## 15. Frontend

### 15.1 `Chat.jsx` (`frontend/pwa/src/pages/Chat.jsx`, ~387 líneas)

- Envía `{text, session_id, ctx}` a `/chat/message` vía `chatApi`
  (`frontend/pwa/src/api/chat.js`); input limitado a 500 caracteres.
- Renderiza `reply` (markdown-lite), `cards` → `DataCard` (paginación
  cliente de 10 filas, `CARD_PAGE=10`, reinicia al cambiar de card;
  accesibilidad: `caption`, `scope`, `aria-live`, `aria-controls`;
  nulos → `—`), `actions` → chips nav, `suggestions`.
- Exportación client-side: `EXPORT_FORMATS` (`src/utils/exporters`) —
  el chip pide `format` y el browser genera el archivo; el backend solo
  entrega los datos.
- Sidebar de sesiones (`/chat/sessions`), historial (`/chat/history`),
  nueva conversación (UUID local que el backend reutiliza).

### 15.2 `NexoChat.jsx` (`components/patterns/NexoChat.jsx`)

Avatar institucional `/imagenbot.png` con fallback `N`, burbujas
user/bot, skeleton de carga.

### 15.3 `chatContext.js` (`lib/chatContext.js`)

Contexto de compatibilidad en `sessionStorage` con TTL de 30 min:
guarda entidades **visibles** del último turno para reenviarlas como
`ctx`. Nunca es autoridad — el `_ds` server-side manda, y el backend
descarta `_op`/`_ds` del ctx entrante (`chat.php:540`).

## 16. Contrato HTTP

| Endpoint | Método | Descripción |
|---|---|---|
| `/chat/message` | POST | Turno completo. In: `{text≤500, session_id?, ctx?}`. Out: `{status, data:{reply, intent, confidence, session_id, cards?, actions?, suggestions?, denied?, _interpretation?}}` |
| `/chat/history` | GET | `?session_id=` → hasta 200 mensajes de la sesión; sin ella → últimos 60 del usuario. Devuelve `{from, text, cards, actions, intent, ts}` |
| `/chat/sessions` | GET | Hasta 30 conversaciones del usuario (inicio, último mensaje, primer texto) |
| `/chat/action` | POST | `{action}` → `{to:'/operacion?cmd=…'}` tras `chatCanAction`; 403 si el rol no puede |
| `/chat/policies` | GET/POST | Interruptores por escuela; solo RECTOR/COORDINATOR; auditado |

Errores: 400 texto vacío/largo, 429 rate limit, 403 acción/política no
permitida. Los denegados de intent son 200 con `denied:true`.

## 17. Configuración y operación

- Variables `NLU_LLM_*` (tabla en §5.1), `NX_CLASSIFY_FIXTURE`,
  `NX_CLASSIFY_LOG`, `NEXO_SCP_TRACE`.
- Dependencias runtime: Redis (rate limit — degradable), PostgreSQL vía
  `PDO` en `$conn`, proveedor LLM externo opcional-pero-recomendado (sin
  él, solo responden los caminos deterministas: navegación, handlers ya
  encaminados por contexto, smalltalk, KB, ayuda — la clasificación de
  lenguaje libre queda en `out_of_scope`).
- Tablas: `chat_messages` (`payload_json` jsonb, `session_id` —
  compatible con esquemas sin la columna, `chat.php:1807-1813`),
  `school_chat_policies`, `global_audit_logs`, más las tablas de dominio.
- Trazas: `NEXO_SCP_TRACE=1` → líneas `[SCP:<capa>]` en error_log;
  `_interpretation` en cada payload (`timing_ms`, `turn_type`, plan,
  heredados) para auditoría/depuración.

## 18. Pruebas y evidencia

- **Fixture** `test/fixtures/llm_intents.json`: snapshot de respuestas
  reales del parser LLM indexado por texto normalizado (~362 frases en
  el conteo realizado). Lo activa `NX_CLASSIFY_FIXTURE`; regeneración
  con `NX_CLASSIFY_LOG` + `test/gen_llm_fixture.php` (ver AGENTS.md).
  **No es un clasificador vigente** — es un doble de pruebas.
- **Suites locales** (fixture — pipeline completo menos el LLM):
  `test/dsm_units.php` (DSM), `test/nexus_capability_eval_v1.php`,
  `test/real_conversation_v1.php` (simulación NLU/DSM; no prueba SQL ni
  HTTP), `test/readonly_guard.php`, `test/resilience.php`,
  `test/scp_regression.php`, `test/harness_turn.php` (paridad con
  producción), PHPUnit `test/phpunit.xml` (`ChatNluTest`,
  `NexusPlanInvariantTest`, …).
- **Suites live** (gastan cuota API / requieren servicios):
  `blind_eval.php`, `op_eval.php`, `semantic_eval.php`,
  `audit_single_errors.php`, `live_battery.php`, `golden_live.php`,
  `heldout_live.php`, `live_probe*.php`, `scp_live.php`,
  `llm_probe.php`. Las eval grandes miden al parser: correrlas contra el
  fixture es circular.
- `continuity_50.php` usa API/BD real y escribe historial — fuera del
  alcance local.
- Auditorías históricas (`_cuarentena/auditoria/`) y reportes `NEXUS_*.md`
  (`_cuarentena/memoria_nexus/`) documentan ciclos de evaluación; sus
  cifras de acierto miden al parser **de su momento** — se citan como
  evidencia histórica, no como garantía de la versión actual.

## 19. Extender Nexus: añadir una capacidad

Añadir una consulta nueva al chat toca **cinco puntos del pipeline** (en
orden del flujo):

1. **Intent** — declararlo en la whitelist `NX_LLM_FORMAL`
   (`nexus_llm.php:45`). Si el LLM emite un intent ausente, se convierte en
   `out_of_scope`. Para intents conversacionales usar `NX_LLM_INFORMAL`.
2. **Slots y sinónimos** — si el intent necesita entidades nuevas
   (p. ej. un módulo o un tipo de fecha), ampliar `nxSlots` / los mapas de
   sinónimos de `nexus_nlu.php` y las frases del prompt del parser.
3. **RBAC** — añadir la entrada en `nxIntentRoles` (`nexus_nlu.php:789`):
   qué roles pueden usarlo. La matriz rol × política de escuela
   (`NX_CHAT_POLICY_MAP`, `chat.php:110`) puede además apagarlo por colegio.
4. **Capacidad semántica** — registrar el capability en
   `nxCapabilityRegistry` (`nexus_semantic.php:36`): `filters`,
   `required_parameters`, `time_scope`, `related`/`nearby` (para
   composición y sugerencias), `rbac`, `exec` (executor) e
   `intent_equiv` (puente intent→capability). `nxPlanValidate` aplicará
   los `required_parameters`; `nxPlanAllowed` el `rbac`.
5. **Executor** — el `exec` apunta a un handler en `nexus_semantic.php` o
   a un `chat_*` en `chat.php`. Regla absoluta: **SQL read-only** —
   `test/readonly_guard.php` escanea los handlers y falla si aparece
   escritura. La presentación (card/tabla) la decide
   `response_shape`+`presentation`; el composer reformula el texto.

Después: añadir frases a las suites (`test/dsm_units.php`,
`test/nexus_capability_eval_v1.php`, `test/real_conversation_v1.php`) y
regenerar el fixture si el intent pasa por el parser
(`NX_CLASSIFY_LOG` + `test/gen_llm_fixture.php`, ver AGENTS.md).

## 20. Limitaciones y gaps comprobados

- Sin `NLU_LLM_KEY` o con el proveedor caído, toda clasificación de
  lenguaje libre termina en `out_of_scope` (honesto pero degradado).
- La extracción de estudiante es por nombre difuso; ambigüedad (>1
  candidato) → el handler pregunta en vez de elegir.
- `nxClassify` parte en «y» de forma ingenua; la reparación de
  enumeraciones comparativas es heurística (`chat.php:388`).
- El slice/nav opera sobre el `last_result` persistido — un set muy
  largo navega lo que el handler devolvió, no la tabla entera.
- El composer informal puede ser desactivado; sin él el repertorio
  determinista de `nxSmalltalk` es la única voz social.
- `chat.php` concentra orquestación + ~40 handlers en un archivo
  (~3060 líneas) — deuda estructural conocida.
- La evidencia de calidad proviene de suites con fixture (offline,
  deterministas) y de evals live históricas; no hay una suite que cubra
  HTTP+BD+LLM de extremo a extremo en el alcance local.
- Redis caído desactiva silenciosamente el rate limit.

## 21. Historia breve: el stack retirado

La primera versión del NLU fue un clasificador estadístico **TF-IDF +
regresión logística** servido por un microservicio Python (`NEXO_NLU_URL`,
puertos `:8090`/`:8096` en documentos antiguos) con un modelo de fallback
en PHP y corpus sintético. Ese stack fue **retirado**: el parser actual
es el LLM de `nexus_llm.php` y no existe runtime Python en la ruta del
chat. Los documentos que lo describen como vigente (parte del material
`NEXUS_*.md` y de `auditoria/` anteriores a la migración, archivados en
`_cuarentena/`) son **historia**:
sus métricas miden al clasificador viejo, no al parser actual. La taxonomía
de intents y las lecciones de normalización/DSM se conservaron; la
clasificación estadística, no.

## 22. Glosario

| Término | Significado |
|---|---|
| **Parser LLM** | `nxLlmClassify` — LLM vía API compatible-OpenAI que emite `intent`+`entities`+`safety` bajo whitelist |
| **Slots** | Entidades estructurales (`group`, `module`, `days`, `field`, `student`, `justified`, `_nav`, `_op`, …) |
| **DSM** | `nxDialogueResolve` — resolución contextual: turn_type, herencia, correcciones, referencias |
| **SCP** | Semantic Conversational Parsing — frame semántico validado (`nxScpFrame`) entre DSM y planner |
| **Frame** | Estructura `{task, subject, filters, scope, time_range, …}` normalizada con evidencia |
| **Plan** | `{capability, entity, op, filters, position, slice, _ref?}` ejecutable tras validate+allowed |
| **Capability registry** | Catálogo de 39 capacidades reales con RBAC, executor y equivalencias de intent |
| **`_ds`** | Dialogue state server-side persistido en `chat_messages.payload_json` — autoridad del contexto |
| **Result-set** | Último resultado navegable (`items`, `columns`, `rows`, `count`) con cursor |
| **`_nav`** | Operación interna de navegación/transformación sobre el set activo |
| **`_op`** | Operación mutativa pendiente (se resuelve como chip de navegación) |
| **Composer** | `nxLlmComposeReply` — reformula el reply verificado sin alterar datos |
| **Chat informal** | `nxLlmChat` — conversación libre bajo persona institucional |
| **Fixture** | `test/fixtures/llm_intents.json` — snapshot del parser para suites offline |
| **`out_of_scope`** | Abstención honesta: sin evidencia no hay intent |
| **`security_probe`** | Intent de sondeo/escalada: respuesta fija + `securityLog` |
| **`safety_guard`** | Bloqueo transversal de contenido riesgoso (flag LLM ∨ screen PHP) |

---

*Documento generado por inspección directa del código (2026). Si el
código y este texto difieren, el código manda — reportar la deriva.*
