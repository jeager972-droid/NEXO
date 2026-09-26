# Reporte Final — Chatbot «Pregúntale a Nexus»

> Auditoría de ejecución post-refactor. Fecha: 2026-09-20.
> Estado: **verificado en terminal** — suites verdes, servicio en vivo, casos del usuario resueltos.

---

## 1. Arquitectura final

```
PWA (React)
  └─ sessionStorage: ctx { last_intent, last_reply, entities{student,group,module,days…}, ts }
       └─ injectCtx(): frases dependientes («su grupo», «dame otro», «y cuántas evasiones»)
            └─ POST /chat/message { text, session_id, ctx }
                 └─ PHP chat.php
                      ├─ Herencia ctx→slots marcada _inherited (auditable)
                      ├─ «dame otro» → repite último intent del payload guardado
                      ├─ Multi-intent: partes por conjunción, dedupe por segmento (no por intent)
                      │     └─ cada parte: RBAC + políticas + nxSlots(segmento) independientes
                      ├─ nxClassify → servicio Python :8090 (embed) → fallback modelo PHP local
                      │     ├─ router formal/informal (sesgo crítico)
                      │     ├─ guardia informal determinística (léxico de charla puro)
                      │     ├─ arbitraje dual simétrico cuando el router duda
                      │     └─ NER: student/group/days/module/field + num_ent (letras y dígitos)
                      └─ handlers chat_* → SQL vivo → reply + cards + chips
                           └─ chatLog → chat_messages (con session_id; degrada si falta)
```

Regla inviolable: **Python clasifica y extrae; PHP consulta y responde**. Ningún número,
nombre, perfil ni conteo sale del modelo — todo es `SELECT` sobre la DB viva.

## 2. Modelo NLU — cifras reales

| Métrica | Valor |
|---|---|
| Ejemplos de entrenamiento | 472.824 |
| Intents | 87 (44 informales / 42 formales + router) |
| Router accuracy | 99.64% |
| Formal accuracy | 98.54% |
| Informal accuracy | 98.46% |
| **Selftest 18.880 frases NO vistas** | **93.44% efectivo** (intent + conf≥0.65) |
| Escapes adversariales | 0 / 48 (inyección, escalación de rol, fuera de dominio) |
| Latencia | ~3-6 ms por clasificación local |
| Export PHP (fallback sin Python) | 31 MB — model_php.json |

Los intents erróneos restantes son confusiones charla↔charla (greeting/wellbeing/story)
que producen respuestas válidas, o confianza <0.65 → fallback elegante con sugerencias
— nunca una respuesta institucional inventada.

## 3. Intents — catálogo real del sistema

**Datos vivos**: day_summary, attendance_today, late_today, count_events, count_present,
count_trackings, list_events, top_offenders, pending_returns, attendance_ranking,
group_student_count, students_count, groups_list, staff_lookup, teachers_list,
student_summary, student_field, group_summary, risk_students, trackings, permissions,
citations, devices_status, notifications_unread, audit_query, sos_alerts,
biometric_spam, birthdays_today, my_activity, failed_messages, whatsapp_status,
risk_config, schedule_info, export_data, pending_tasks, session_summary.

**Acciones** (chip → /operacion?cmd=Título real): derive_action, start_operation —
Situación Crítica, Citar acudiente, Generar permiso, Mandar solicitud, Reportar daño,
Salida pedagógica, Cambio de horario, Autorizar salida, Fusionar bloque, Extender
bloque, Registro manual, Reportar incidente, Solicitar seguimiento.

**Utilidad**: math_operation (aritmética, %, potencias, raíces, trig, mcm/mcd, regla
de tres, áreas — por dígitos y letras), random_student (scoped), random_number,
random_department, follow_up («dame otro» — nunca repite).

**Conocimiento Colombia**: capital (32 dptos + Bogotá/Cundinamarca), departamento,
presidente (históricos, sin figuras en ejercicio — «actual»→respuesta honesta),
historia (independencia, 1991, El Dorado, mil días, bogotazo…), geografía, cultura,
fun_fact, foreign_culture (rechazo patriótico elegante).

**Social**: greeting, wellbeing, thanks, joke, fun_fact, story, sing, compliment,
insult, emotion_*, human_check, meaning_life, creator, age, name_meaning…

## 4. Bugs del usuario — resueltos

| Reporte | Causa | Fix |
|---|---|---|
| Conversaciones no guardan | `session_id` faltaba en DB prod → INSERT fallaba en try{} | chatLog detecta columna vía information_schema y degrada; patch SQL idempotente |
| «llegadas tarde 15d y coseno de 30» ignoraba la primera parte | dedupe por intent mataba el conteo | dedupe por segmento; slots por parte (module LATE_ARRIVAL) |
| «acudiente de Johnson + su documento» solo respondía uno | mismo intent 2× → dedupe | mismo intent con params distintos = dos partes |
| «el estudiante que te dije» perdía contexto | random_student no devolvía el nombre elegido | handlers devuelven entities resueltas → ctx las guarda |
| «capital de Bogotá» | sin caso especial | Bogotá → capital de Colombia y Cundinamarca |
| «última constitución» → Gran Colombia | llave KB equivocada | llaves `ultima constitucion`/`politica`/`actual` → 1991 |
| «leyenda del dorado» → lista de staff | `del dorado` extraía estudiante | `dorado` al stop-vocab + corpus colombia_history |
| «presidente actual» → Petro quemado | literal en KB y handler | figuras en ejercicio eliminadas; respuesta honesta |
| «dame otro» repetía el chiste | array_rand puro | nxPickNoRepeat excluye last_reply del ctx |
| «petición a directivo» → chip de citación | cmd map viejo + `?cmd=` busca **título** | mapa completo a títulos reales del catálogo |
| «uno mas uno» | palabras-numéricas no enmascaraban | num_ent para letras |
| «asdfgh zzz 12345» → math | dígitos sueltos activaban math | corpus out_of_scope + arbitraje |
| «octavo A», «11.2», «llegaron tarde», «inasistieron» | extracción/formatos | ordinales + `11 2` + corpus nuevo |

## 5. RBAC y políticas

- `nxAllowed()` por intent×rol — SECURITY/AUXILIARY no ven datos de estudiantes.
- `chatScope()` limita docentes a `teacher_group_access` en TODOS los handlers.
- `school_chat_policies` puede apagar capacidades por rol en caliente.
- Multi-intent evalúa RBAC por parte — una parte permitida no contamina otra.
- Las acciones escriben solo vía /operations/execute (RBAC + confirmación).
- Auditoría: herencias ctx se registran con `_inherited` en el payload.

## 6. Verificación ejecutada

```
php -l routes/chat.php · lib/nexus_nlu.php ............ OK
PHPUnit  test/api ........................ 210 tests · 879 assertions · 0 fallos
Vitest   PWA ............................. 590 tests · 49 archivos · 0 fallos
selftest NLU ............................. 18.880 frases nuevas · 93.44% efectivo
adversarial .............................. 48 ataques · 0 escapes a datos
chat_context_sim.php ..................... herencia Camilo Torres→evasiones ✔
servicio /health ......................... 42+44 intents · threshold 0.65 ✔
```

## 7. Lo que falta / honestidad

- **Prod**: correr `deploy/patch_existing_db.sql` en Render (session_id + dedup_key).
- ~6.5% de frases caerán al fallback elegante — es diseño seguro, no error.
- `random_student`/`sos_alerts`/`biometric_spam` dependen de que haya datos reales.
- La PWA muestra el drawer «Conversaciones» con `GET /chat/sessions`.
- Chips de operación navegan con `cmd=<título>` — coincide con Operation.jsx.
