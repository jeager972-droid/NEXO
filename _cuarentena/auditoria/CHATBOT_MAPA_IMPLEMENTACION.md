# Mapa Real de Implementación — «Pregúntale a Nexus»

> Documento de auditoría — describe el código **tal como está**, no cómo debería estar.
> Fecha de la instantánea: 2026-09-20. Commit base: `12a7278`.

---

## 1. Arquitectura en 4 capas (todas existen y están cableadas)

```
texto ──► normalización ──► NER+slots ──► máscara ──► router ──► submodelo ──► PHP handler ──► SQL ──► reply
             │                  │            │            │           │            │
          nxNorm()        regex+listas   tokens fijos   P(formal)   softmax     chat_<intent>()
          preprocess.py   stop-vocab     estudiante_ent bias 0.30   THRESH 0.65 tabla real
          (paridad PHP)   entidades      grupo_ent      guardia     arbitraje   scope+RBAC
```

Dos rutas de inferencia — **mismo modelo, dos runtimes**:

1. **Servicio Python** `backend/nlu/service.py` (HTTP `:8090`, `/classify`, timeout 900ms en PHP) — scikit-learn real.
2. **Fallback PHP** `backend/api/lib/nexus_nlu.php::nxClassifyLocal()` — lee `model/model_php.json` (export word-level del mismo clasificador) y corre softmax nativo. Misma guardia, mismo arbitraje, mismos umbrales — paridad deliberada.

---

## 2. Las 87 intenciones — exactamente como están implementadas

`corpus.py` + `corpus_colombia.py` definen los intents vía decorador `@intent`. La partición dominio la decide `domains.py::domain_of()` por nombre de intent.

### Dominio FORMAL (datos vivos / operación — 42 intents)

| Intent | Qué captura | Handler PHP | Datos |
|---|---|---|---|
| `day_summary` | resumen jornada (puede llevar `days`) | `chat_day_summary` | events hoy |
| `attendance_today` | asistencia del día | `chat_attendance_today` | events INGRESO |
| `late_today` | tardanzas del día | `chat_late_today` | LATE_ARRIVAL |
| `count_events` | conteo por módulo+rango+grupo | `chat_count_events` | events filtrado |
| `count_present` | presentes vs matrícula | `chat_count_present` | students+events |
| `count_trackings` | seguimientos abiertos | `chat_count_trackings` | trackings |
| `list_events` | lista (no cuenta) | `chat_list_events` | events |
| `top_offenders` | ranking de reincidentes | `chat_top_offenders` | events agregado |
| `pending_returns` | permisos sin retorno | `chat_pending_returns` | permissions |
| `attendance_ranking` | grupos con más faltas | `chat_attendance_ranking` | agregado×grupo |
| `group_student_count` | «cuántos en el 8A» | `chat_group_student_count` | assignments |
| `students_count` | matrícula total | `chat_students_count` | students |
| `groups_list` | listado de grupos | `chat_groups_list` | academic_groups |
| `staff_lookup` | quién es X (personal) | `chat_staff_lookup` | users/staff |
| `teachers_list` | listado docentes | `chat_teachers_list` | users |
| `student_summary` | ficha completa de estudiante | `chat_student_summary` | student+eventos+seguimientos |
| `student_field` | campo puntual (doc, acudiente, celular, jornada…) | `chat_student_field` | campo elegido |
| `group_summary` | resumen de grupo | `chat_group_summary` | grupo+eventos |
| `risk_students` | en riesgo | `chat_risk_students` | risk engine |
| `trackings` | casos/seguimientos | `chat_trackings` | trackings |
| `permissions` | permisos activos | `chat_permissions` | permissions |
| `citations` | citaciones | `chat_citations` | citations |
| `devices_status` | nodos/sensores | `chat_devices_status` | devices |
| `notifications_unread` | avisos del usuario | `chat_notifications` | notifications |
| `audit_query` | auditoría | `chat_audit` | global_audit_logs |
| `sos_alerts` | alertas SOS | `chat_sos_alerts` | events SOS |
| `biometric_spam` | spam biométrico | `chat_biometric_spam` | events SPAM |
| `birthdays_today` | cumpleaños hoy | `chat_birthdays_today` | students.birth_date |
| `my_activity` | actividad del propio usuario | `chat_my_activity` | audit propia |
| `failed_messages` | mensajes fallidos | `chat_failed_messages` | notifications |
| `risk_config` | umbrales configurados | `chat_risk_config` | config tabla |
| `schedule_info` | horarios/jornada | `chat_schedule_info` | schedule |
| `export_data` | exportar datos | `chat_export_data` | acción→descarga |
| `session_summary` | «de qué hemos hablado» | `chat_session_summary` | chat_messages propia |
| `pending_tasks` | pendientes del usuario | `chat_pending_tasks` | agregado mixto |
| `whatsapp_status` | cola de mensajería | `chat_whatsapp_status` | notifications |
| `derive_action` | «hay que hacer algo con X» | `chat_derive_action` | chips contextuales |
| `start_operation` | «quiero citar/permiso/solicitud…» | `chat_start_operation` | chip→/operacion?cmd= |
| `random_student` | «un estudiante al azar del 8A» | `chat_random_student` | select order random |
| `about_me` | datos del usuario que pregunta | `chat_about_me` | users+groups propios |
| `help` / `capabilities` | qué puedes hacer | inline `chatHelp($role)` | rol-dependiente |

### Dominio INFORMAL (charla / conocimiento / utilidad — 45 intents)

| Intent | Respuesta |
|---|---|
| `greeting`, `wellbeing`, `wellbeing_reply`, `thanks`, `goodbye`, `yes`, `no`, `apology`, `compliment`, `insult`, `bored`, `love`, `human_check`, `do_for_me`, `emotion_sad`, `meaning_life`, `confused`, `sing`, `dance`, `story`, `motivation` | `nxSmalltalk()` — pools de respuestas escritas a mano, se elige con `nxPickNoRepeat` (excluye la última respuesta guardada en ctx) |
| `joke`, `fun_fact` | pools `NX_JOKES`/`NX_FUNFACTS` (~20 y ~30 entradas) con no-repetición |
| `about_nexus`, `name_meaning`, `creator`, `age` | respuestas fijas sobre el propio bot |
| `weather`, `news_sports`, `food_music` | «no es mi área» elegante — sin datos en vivo |
| `time`, `date` | hora/fecha del servidor (`gmdate`) |
| `math_operation` | `math_ner.py` estructura → `lib/calculator.php` calcula (suma/resta/mult/div/pow/sqrt/%/factorial/trig/log/mcm/mcd/regla3/áreas/pitágoras/cuadrática) — números por dígitos **y** por letras |
| `colombia_capital` | KB estática — 32 departamentos + caso especial Bogotá |
| `colombia_department` | capital+región del departamento nombrado |
| `colombia_president` | KB de presidentes **históricos** (Bolívar→Duque); «actual/en ejercicio» → respuesta honesta «no cargo figuras en ejercicio» |
| `colombia_history` | KB eventos: 1991, El Dorado, bogotazo, independencia, mil días… |
| `colombia_geography`, `colombia_culture` | KB estática |
| `colombia_fun_fact` | datos curiosos Colombia (equivale a fun_fact en la métrica) |
| `foreign_culture` | speech patrio — rechaza temas extranjeros con orgullo colombiano |
| `random_number` | `rand(min,max)` entre límites dados |
| `random_department` | sorteo de departamento+datos |
| `security_probe` | intent de seguridad — loggea `CHAT_SECURITY_PROBE` y responde «no hay atajos» |
| `out_of_scope` | fallback universal |

### Intent legacy presente pero sin efecto
`_yes_legacy_unused` existe en corpus pero `domain_of` no lo lista en FORMAL → informal; sin handler — cae a smalltalk.

---

## 3. Qué intención activa cada entrada — el pipeline exacto

`service.py::classify(text)`:

1. `normalize()` — lowercase, quita tildes (NFD→Mn strip), quita puntuación.
2. **Multi-intención** — `_MULTI_SPLIT` corta por `y|además|también|e|,` → hasta 4 segmentos, cada uno pasa por el clasificador completo. Dedupe por **(intent, texto del segmento)** — no por intent: «acudiente de X y su documento» son dos `student_field` distintos y sobreviven ambos.
3. Cada segmento → `preprocess()` → `_classify_one(masked, entities)`:
   - **Nivel 0**: si el texto enmascarado es UNA palabra sin entidades → `_WORD_INTENT` lookup (`sorprendeme`→fun_fact 0.97, `cantame`→sing, `baila`→dance, `motivame`→motivation…).
   - **Nivel 1 router**: TF-IDF + LR binario → `P(formal)`. `FORMAL_BIAS=0.30` — con 30% de señal formal ya va al formal.
   - **Guardia informal determinística**: regex de léxico de charla puro (chiste, clima, vida, horóscopo, como estas, sorprendeme…) → salta a informal directo **salvo** entidad crítica.
   - **Entidad crítica** = `student` o `group` extraídos → fuerza formal.
   - **Nivel 2 submodelo**: el formal o informal según dominio.
   - **Arbitraje dual**: si `0.20 ≤ P(formal) ≤ 0.80` y no hay crítica ni guardia, el OTRO clasificador gana si `conf_otro > conf_elegido + 0.15`.
4. `THRESH=0.65` — confianza menor → `intent='out_of_scope'`, `fallback=true`.
5. `math_operation` → `math_ner.extract_math()` estructura la operación.
6. Orden: partes formales primero (misión crítica arriba de cortesía).

Respuesta de servicio: `{domain, intent, confidence, domain_conf, top3, entities, parts?, math?, fallback}`.

---

## 4. Entidades y slots — quién extrae qué

**Python** `preprocess.py::extract_entities` (sobre texto normalizado):

| Entidad | Cómo se extrae |
|---|---|
| `days` | regex: `últimos N días`, `N semanas`→×7, `hoy`→0, `ayer`→1, `esta semana`→7, `este mes`→30, `mes pasado`→60, `semana pasada`→14, `este año`→365 |
| `group` | `8A`, `8-2`, `11.2`→`11-2`, `11 2`, `prescolar/jardin/transicion/kinder`, ordinales (`octavo a`→`8A`, `grado noveno`→`9`) |
| `student` | 2 patrones: `(estudiante|alumno|niño…) X` y `(de|del|para|a|tuvo|tiene|falto|llego|capo|volo|evadio…) X` con boundary — X filtrada por `_STOP` (~200 palabras: dominio, verbos, números, geografía, clima, ordinales) |
| `threshold` | `más de N`/`al menos N`/`N veces` |
| `topic`/`_regions` | departamentos/países (match más largo) — una región capturada como student se **elimina** (falso positivo) |

**PHP** `nexus_nlu.php::nxSlots` — mismo set más:

| Slot | Cómo |
|---|---|
| `from`/`to`/`range_label` | calculados de `days` con `gmdate` |
| `module` | `nxModuleSynonyms()` — 14 type_code canónicos con sinónimos (`evasiones|fugas|se salio`→EVASION_INTERNA, `llegadas tarde|tardanzas`→LATE_ARRIVAL…) |
| `field` | `nxFieldSynonyms()` — documento, celular, acudiente, grupo, jornada, nacimiento, estado |
| `student` | `nxExtractStudent` — espejo del regex Python + mismo stop-vocab |
| `math` | viene del servicio en `entities.math` |

**Enmascarado** (ambos lados, idéntico): `estudiante_ent`, `grupo_ent`, `num_ent` (dígitos Y palabras: `uno|dos|…|millones`), `region_ent`, `extranjero_ent`. El modelo ve **estructura** — «cuantas evasiones tuvo estudiante_ent del grupo_ent».

---

## 5. Contexto entre mensajes — tres mecanismos distintos

1. **sessionStorage del navegador** (`PWA/src/lib/chatContext.js`): el front guarda `{last_intent, last_reply, entities{student,group,module,days,field,from,to}, ts}` y lo manda en `ctx` con cada POST. `Chat.jsx::injectCtx()` no reescribe el texto — viaja como objeto separado.
2. **Herencia PHP** (chat.php líneas 309-329): slots ausentes se rellenan desde `ctx.entities`, marcados en `slots._inherited[]` (auditable). Si la clasificación quedó bajo umbral y hay `ctx.last_intent`, se hereda la intención entera.
3. **Follow-up server-side** («dame otro», «otra», «más», «siguiente», «de nuevo»): regex determinista en línea 265 — **antes de clasificar** — recupera `chatLastPayload()` de la DB y re-despacha el último intent con `_repeat=true`. Los handlers aleatorios usan `nxPickNoRepeat` que excluye `vars._last_reply` del pool.
4. **Persistencia**: `chatLog()` escribe par user+assistant en `chat_messages` (con `session_id` si la columna existe — detectada vía `information_schema`; degrada sin ella). Cada mensaje también → `global_audit_logs` (CHAT_QUERY, intent, confianza).

---

## 6. Ambigüedad y baja confianza

| Caso | Comportamiento |
|---|---|
| `conf < 0.65` en el intent elegido | `intent='out_of_scope'`, fallback=true → respuesta elegante + sugerencias de la ayuda — **nunca** un handler adivinado |
| Router dudoso (0.20-0.80) | arbitraje dual: el otro submodelo gana si domina por +0.15 |
| Estudiante ambiguo (>1 match) | `chatResolveStudent` devuelve la lista → el handler responde «¿cuál de estos?» con opciones |
| Estudiante no encontrado | respuesta «no encontré a X en tu alcance» — nunca inventa |
| Sin estudiante y sin ctx | el handler pide el nombre explícito |
| `math_ner` no estructura | la operación no se resuelve — se responde con el intent pero sin número |
| Texto vacío tras enmascarar | `out_of_scope` 0.0 inmediato |

---

## 7. Cómo se elige la operación/API

`chatDispatch()`:

```
intent ──► smalltalk/meta ──► nxSmalltalk() / chatHelp($role)
       └─► 'chat_' + intent ──► handler real ──► SQL parametrizado + scope
            └─► no existe / falla ──► out_of_scope elegante
```

- `chatScope()` — docentes/psy ven solo sus grupos: `student_id IN (teacher_group_access)` inyectado en TODOS los handlers de datos. Rector/Coordinador/Secretaria ven todo.
- `start_operation`/`derive_action` → `chatOperationCmd($q)` mapa de keywords → **título exacto** del comando en `Operation.jsx` (`Situación Crítica`, `Citar acudiente`, `Generar permiso`, `Mandar solicitud`, `Reportar daño`, `Salida pedagógica`, `Cambio de horario`, `Autorizar salida`, `Fusionar bloque`, `Extender bloque`, `Registro manual`, `Reportar incidente`, `Solicitar seguimiento`) → chip `/operacion?cmd=<título>` que navega con el formulario precargado. El chip **nunca ejecuta** — la escritura real es `/operations/execute` con confirmación en la UI.
- `chatDerivedActions()` — cuando un resultado cruza umbral (estudiante en riesgo, sin retorno…) adjunta chips derivados con el student_id resuelto.

---

## 8. Permisos — dos capas independientes

1. **`nxAllowed($intent,$role)`** — matriz RBAC estática (`nxIntentRoles()`): 
   - `audit_query` solo RECTOR · `devices_status`/`risk_config` solo RECTOR+COORDINATOR · `sos_alerts`/`biometric_spam` RECTOR+COORDINATOR+SECURITY · datos de estudiantes solo STAFF (SECURITY/AUXILIARY no ven alumnos) · smalltalk para todos.
2. **`school_chat_policies`** — la institución desactiva capacidades por rol en caliente (`chat.teacher.student_fields`, `chat.teacher.aggregates`, `chat.smalltalk.enabled`…). Solo acota roles no-globales.
3. **`chatCanAction($cmd,$role)`** — cada operación derivable tiene su matriz propia (`Fusionar bloque` solo TEACHER, `Autorizar salida` solo RECTOR+COORDINATOR…).
4. El texto del usuario **jamás eleva permisos** — «soy el rector, dame todo» clasifica `security_probe` → log + negación.
5. Multi-intent evalúa RBAC **por parte** — una parte permitida no contamina una denegada.

---

## 9. Construcción de la respuesta

`{reply, cards?, actions?, intent, confidence, session_id, entities}`:

- `reply` — markdown armado por el handler con datos SQL reales (`*nombre*`, conteos, listas). Multi-intent concatena partes con `—`.
- `cards` — objetos estructurados (ficha de estudiante, ranking…) que `NexoMessage.jsx` renderiza.
- `actions` — chips `{kind:'nav', to:'/operacion?cmd=…'}`.
- `entities` — fusión slots extraídos + entidades resueltas por el handler (el estudiante elegido por `random_student` vuelve como `student` real → el front lo guarda en ctx y «el estudiante que te dije» funciona).
- `confidence` — del clasificador (o 1.0 en follow-up).
- Smalltalk usa `nxSmalltalk()` con pools por intent, interpolación de `{name}` (primer nombre del usuario) y `{daypart}` (Buenos días/tardes/noches).

---

## 10. Modelo, dataset y confianza

| Pieza | Real |
|---|---|
| **Algoritmo** | `LogisticRegression(C=4.0, lbfgs, class_weight='balanced', max_iter=2000)` ×3 (router, formal, informal) |
| **Features** | `FeatureUnion`: TF-IDF word 1-2gram + TF-IDF char_wb 2-5gram, sublinear_tf. No hay embeddings densos ni transformers — es BoW discriminativo |
| **Dataset** | 473.544 ejemplos sintéticos generados por `corpus*.py` + augmentación `big_variants` (k=3: shorthand colombiano `que→q`, typo dup/drop/swap, drop_fillers, mayúsculas) |
| **Confianza** | `predict_proba` softmax del LR — calibrada empíricamente (media 0.98 en correctas, 0.74 en erróneas) |
| **Entrenamiento** | `train.py`: split 85/15 estratificado, seed 42. El router se entrena además con 4.000 mezclas sintéticas «cortesía+misión crítica»→formal para enseñar el sesgo |
| **Métricas** | router 99.67% · formal 98.76% · informal 98.52% (test interno) — selftest externo: **93.44% efectivo en 18.880 frases nuevas**, 0/48 escapes adversariales |
| **Export PHP** | `model_php.json` (31 MB) — word-level re-entrenado aparte: {classes, vocab, idf, coef, intercept}; PHP corre softmax nativo idéntico |
| **Latencia** | ~3-6 ms clasificación local · timeout PHP→servicio 900 ms |

---

## 11. Mecanismos de fallback (todos los que existen)

| # | Fallback | Trigger |
|---|---|---|
| 1 | `nxClassifyLocal` (modelo PHP) | servicio Python no responde (timeout 900ms / error) |
| 2 | `out_of_scope` elegante | conf < 0.65 — sugiere qué pedir, no inventa |
| 3 | Herencia de ctx | frase dependiente («su grupo») sin intent propio |
| 4 | Follow-up payload | «dame otro» sin clasificación |
| 5 | chatLog degradado | DB sin `session_id` → INSERT sin la columna |
| 6 | Redis rate-limit | Redis caído → el chat sigue funcionando sin límite |
| 7 | Handler exception | try/catch → «no pude consultar eso» + `securityLog` |
| 8 | `nxPickNoRepeat` | pools aleatorios agotados → array_rand normal |

---

## 12. Tests existentes y qué cubren

| Suite | Archivo | Cubre |
|---|---|---|
| NLU masivo | `backend/nlu/selftest.py` | 18.880 frases generadas × todas las combinaciones intent×wraps×ruido(tildes/mayús) + 48 adversariales (inyección, escalación, fuera de dominio) — reporta recall por intent, confianzas, falsos fallback, escapes |
| Chat NLU PHP | `test/api/ChatNluTest.php` | endpoint registrado, auth obligatoria, RBAC×rol, sin SQL libre, umbral de fallback, modelo PHP exportado clasifica, extracción de entidades, smalltalk sin inyección |
| Contexto | `test/chat_context_sim.php` | simulación 2 turnos: estudiante resuelto → «sus evasiones» hereda la entidad |
| Seguridad | `EndpointSecurityTest.php`, `RouteEndpointsTest.php` | ninguna ruta con SQL concatenado ni `{$text}` en prepare |
| Frontend | `PWA vitest` 590 tests | componentes, sessionStorage, chat UI |
| Integración | `RouteEndpointsTest.php` etc. | 210 tests API completos |

---

## 13. Limitaciones reconocidas explícitamente

En el propio código/responses:

- «no cargo figuras en ejercicio» — `colombia_president` rechaza actualidad política.
- `weather`/`news_sports`/`food_music` → «no es mi área» (sin APIs externas).
- `foreign_culture` → solo cultura colombiana, explícito.
- `human_check` → «soy software, no tengo sentimientos».
- `do_for_me` → «no hago tareas, gestiono la jornada».
- `security_probe` → «no hay atajos ni modos ocultos».
- `denied` → «corresponde a otro rol».
- Comentarios en código: «ningún número sale del modelo», «el regex es solo slot-filling y red de emergencia», «nunca un handler adivinado».

Limitaciones **no** explícitas (honestidad técnica): confusión residual ~5% entre smalltalk y smalltalk (greeting↔wellbeing — respuesta válida igualmente); estudiante sin contexto en frases como «falto juan» resuelve bien pero nombres de una sola letra no; el servicio Python es single-process (HTTPServer simple) — bajo carga concurrente el PHP fallback toma el relevo.

---

## Archivos fuente de verdad

| Qué | Dónde |
|---|---|
| Pipeline + multi-intent + guardia + arbitraje | `backend/nlu/service.py` |
| NER/máscara/normalización | `backend/nlu/preprocess.py` |
| Corpus de intents (87) | `backend/nlu/corpus*.py` |
| Entrenamiento + export | `backend/nlu/train.py` |
| Modelo binario | `backend/nlu/model/model.joblib` |
| Export PHP | `backend/nlu/model/model_php.json` |
| Espejo PHP del pipeline | `backend/api/lib/nexus_nlu.php` |
| Dispatch + RBAC + contexto + log | `backend/api/routes/chat.php` |
| KB Colombia | `backend/api/lib/kb_colombia.php` |
| Calculadora | `backend/api/lib/calculator.php` |
| NER matemático | `backend/nlu/math_ner.py` |
| Selftest masivo | `backend/nlu/selftest.py` |
| Contexto front | `PWA/src/lib/chatContext.js` |
| UI | `PWA/src/pages/Chat.jsx`, `patterns/NexoChat.jsx` |
