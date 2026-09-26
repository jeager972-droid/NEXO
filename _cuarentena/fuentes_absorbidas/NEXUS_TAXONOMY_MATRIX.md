# NEXO — Matriz de taxonomía conversacional

> Fuente de verdad: `backend/api/lib/nexus_nlu.php` (`nxIntentRoles`,
> `chatOperationCmd`), `backend/api/routes/chat.php` (`chatCanAction`,
> `chatDispatch`), `backend/nlu/model/model_php.json` (86 intents).
> Generada en iter-2 del núcleo conversacional.

## 1. Capas del pipeline

```text
texto → nxNorm → nxClassify (servicio :8090 | fallback PHP) → nxSlots+merge
      → nxCoverageOverride (reglas determinísticas, solo si NLU no resolvió)
      → nxDialogueResolve (DSM: turn-type + slots + ctx)
      → chatAllowed (nxAllowed + políticas de escuela)
      → chatDispatch (smalltalk | help | chat_<intent>)
      → nxPlanResponse (reply fundamentado + sello de procedencia)
      → chatLog + JSON
```

## 2. Intents por categoría (86 totales)

### 2.1 Consulta de datos (inheritable — NX_QUERY_INTENTS)

| Intent | Handler | Slots clave | Roles |
|---|---|---|---|
| list_events | chat_list_events | student, group, module, days, from/to | STAFF |
| count_events | chat_count_events | student, group, module, days | STAFF |
| attendance_today | chat_day_summary | group, module, days | STAFF |
| late_today | chat_late_today | group, days | STAFF |
| count_present | chat_count_present | group, days | STAFF |
| day_summary | chat_day_summary | group, days | STAFF |
| trackings | chat_trackings | student, group | STAFF |
| count_trackings | chat_trackings | student, group | STAFF |
| pending_returns | chat_pending_returns | student, group, days | STAFF |
| permissions | chat_permissions | student, group | STAFF |
| citations | chat_citations | student, group | STAFF |
| student_field | chat_student_field | student, field | STAFF |
| student_summary | chat_student_summary | student | STAFF |
| group_summary | chat_group_summary | group | STAFF |
| group_student_count | chat_group_summary | group | STAFF |
| students_count | chat_students_count | group | STAFF |
| groups_list | chat_groups_list | — | STAFF |
| top_offenders | chat_top_offenders | module, days | STAFF |
| attendance_ranking | chat_top_offenders | module, days | STAFF |
| risk_students | chat_risk_students | — | RECTOR,COORDINATOR,COUNSELOR,TEACHER |
| devices_status | chat_devices_status | — | STAFF |
| notifications_unread | chat_notifications | — | ALL |
| whatsapp_status | chat_whatsapp_status | — | STAFF |
| failed_messages | chat_failed_messages | — | STAFF |
| sos_alerts | chat_sos_alerts | — | STAFF |
| biometric_spam | chat_biometric_spam | — | STAFF |
| birthdays_today | chat_birthdays | — | STAFF |
| schedule_info | chat_schedule_info | group | STAFF |
| teachers_list | chat_teachers_list | — | GLOBAL+COUNSELOR |
| staff_lookup | chat_staff_lookup | field/materia | ALL |
| my_activity | chat_my_activity | — | ALL |
| pending_tasks | chat_pending_tasks | — | STAFF |
| session_summary | chat_session_summary | — | STAFF |
| about_me | chat_about_me | — | ALL |

### 2.2 Operación (chip de navegación — NUNCA ejecuta)

| Intent | Resolución | Roles que ven el chip |
|---|---|---|
| start_operation | chatOperationCmd → acción | ALL (acción validada aparte) |
| derive_action | idem | ALL |
| repeat_op | DSM → _op persistido | ALL |

Acciones (`chatCanAction`):

| Acción | Roles |
|---|---|
| Citar acudiente | RECTOR, COORDINATOR, TEACHER, COUNSELOR, SECRETARY |
| Generar permiso | RECTOR, COORDINATOR, TEACHER |
| Autorizar salida | RECTOR, COORDINATOR |
| Mandar solicitud | RECTOR, COORDINATOR, TEACHER, COUNSELOR, SECRETARY |
| Solicitar seguimiento | RECTOR, COORDINATOR, TEACHER, COUNSELOR |
| Reportar incidente | RECTOR, COORDINATOR, TEACHER, COUNSELOR |
| Reportar daño | ALL operativos |
| Cambio de horario | RECTOR, COORDINATOR |
| Fusionar/Extender bloque | RECTOR, COORDINATOR |
| Registro manual | RECTOR, COORDINATOR, SECRETARY |
| Situación Crítica | todos los operativos |

### 2.3 Datos sensibles

| Intent | Roles |
|---|---|
| audit_query | **RECTOR** únicamente |
| export_data | STAFF |
| risk_config | GLOBAL |

### 2.4 Smalltalk / meta (~40 intents)

greeting, goodbye, thanks, yes, no, joke, wellbeing… → `nxSmalltalk()`
o `chatHelp()` — permitidos a todos (la escuela puede apagarlos por
política `chat.smalltalk.enabled`). **No reescriben el ctx conversacional**
(NX_SMALLTALK_INTENTS — «gracias» no borra el tema).

### 2.5 Reservados del sistema

| Intent | Semántica |
|---|---|
| out_of_scope | abstención — el modelo no puede resolver |
| security_probe | rechazo explícito + `securityLog()` |
| clarify | el DSM pide dato faltante en vez de adivinar |
| confirm_op / repeat_op / cancel | flujo de operación pendiente |

## 3. Slots

| Slot | Extracción | Ejemplo |
|---|---|---|
| student | patrones con trigger + stopwords + dígitos-off | «camila del septimo»→camila |
| group | `8a`, ordinales (con/sin letra), «los del noveno» | «del octavo»→8 |
| module | nxModuleSynonyms | «se la volaron»→EVASION_INTERNA |
| days / from / to / range_label | periodos + `prev_days` para «vuelve» | «del mes pasado»→60 |
| field | ficha: documento/acudiente/teléfono/… | «su documento» |
| _op | comando de operación pendiente (persiste entre consultas) | Generar permiso |

## 4. Turn-types del DSM

| Tipo | Disparador | Efecto |
|---|---|---|
| autonomous | sin marca de dependencia | no hereda nada (regla F) |
| context_modify | followup/deíctico + heredable | hereda slots faltantes |
| correction | «no, me refería a X» / «no, mejor X» | reemplaza slot u operación |
| intent_switch | acción explícita nueva | reinicia tema |
| confirmation | «confirmo/sí/dale» + _op | confirm_op (chip) |
| cancel | «cancela/olvida/mejor no» | borra _op |
| op_repeat | «otro/uno más/nuevo» + _op o tema-op | mismo comando, params nuevos |
| deictic | dependiente sin info nueva | **aclarar, no adivinar** |
| new_request | sin contexto | nuevo tema |

## 5. Reglas de cobertura (`nxCoverageOverride`)

Determinísticas, auditables, solo cuando el clasificador no resolvió o
asignó smalltalk inequívoco. Nunca tocan autorización:

- mensajes/notificaciones → `notifications_unread`
- «qué hora es» → `time` (domina schedule_info)
- «listo, gracias»/«eso era todo» → `thanks`
- «datos/info de <estudiante>» → `student_summary`
- coordinadores/docentes/materias → `teachers_list`/`staff_lookup`
- «cuántas tardanzas» → `late_today`; faltas/evasiones → `count_events`
- op-noun + verbo de consulta → intent de consulta
  («quiero ver el horario»→schedule_info)
