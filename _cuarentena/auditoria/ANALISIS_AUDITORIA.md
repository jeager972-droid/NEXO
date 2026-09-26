# ANÁLISIS DE AUDITORÍA — DOCUMENTO → CÓDIGO (NEXO) · v2 revisada

Segunda pasada de verificación de las 652 verificaciones (`LISTA_VERIFICACIONES_DOCUMENTO_CODIGO.md`). Cada ítem argumenta **por qué cumple** (evidencia en código) y **por qué no / limitación** (qué falta o desvía). Estados: ✅ CUMPLE · ⚠️ PARCIAL · ❌ NO CUMPLE · ◻️ N/A-CÓDIGO (atributo físico/instalación/proceso).

Componentes: `backend/api` (PHP), `backend/edge` (C++), `sql/schema.sql`, `PWA` (React), workers, infra.

---

## BLOQUE 1 — V-001 a V-050 (Cap. 4.1, 4.2, 4.3)

### 4.1 Custodia inteligente (V-001 – V-020)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-001 | ✅ | `main.cpp:817` identifica en el nodo y `worker_biometric.php:243` persiste `biometric_events` | "En un aula" se infiere solo por dispositivo (sin aula formal registrada). |
| V-002 | ✅ | `biometric_events.classroom_id`+`schedule_id` se escriben al ingerir (resolución edge→group→schedule en `worker_biometric.php`) | Si el dispositivo no tiene grupo/schedule el evento queda con NULLs (degradado no bloqueante). |
| V-003 | ✅ | Modelo espacial real: `classrooms`+`schedules` (CRUD en `school_config.php`), evento guarda el bloque | La rotación sigue siendo principalmente temporal+espacial por horario. |
| V-004 | ✅ | `extender_bloque` ahora marca `metadata_json.merged=true` y suprime evasión post-bloque (Bloque C) | — |
| V-005 | ✅ | `daily_schedule_config.has_classes=FALSE`/`expected_entry_time` se respetan en `worker_absence_detector.php:118-169` | Sin limitación relevante: actos/reuniones se modelan por config diaria. |
| V-006 | ✅ | `autorizar_salida` → `WAIT_EXIT_FINGERPRINT` → edge exige huella y emite `SALIDA_AUTORIZADA` (`main.cpp:1310-1368`) | Solo aplica al sensor del autorizante (sensor de coordinación). |
| V-007 | ✅ | `worker_biometric.php:211-235` cruza el evento con `class_exit_authorizations` activa y lo etiqueta `permiso_event_role` | Vinculación temporal, no espacial (no valida aula). |
| V-008 | ✅ | `worker_permission_status.php:68-95` marca `COMPLETED` al ver `INGRESO_%` posterior a `exit_time` | Acepta INGRESO en **cualquier** nodo como retorno válido (ver V-063). |
| V-009 | ✅ | `actual_return_time` se persiste al retornar (V-063) en ambas tablas de autorización | — |
| V-010 | ✅ | `authorized_by_user_id` en `class_exit_authorizations` (schema:606-618) | — |
| V-011 | ✅ | `has_active_permiso()` (schema:1454) + filtro `status='ACTIVE'` y ventana en `worker_biometric.php:214-216` | — |
| V-012 | ✅ | `worker_permission_status.php` pasa a `EXPIRED` tras `return_time+5min` sin retorno | — |
| V-013 | ✅ | `EXPIRED` + `EVASION_INTERNA` + notificación a destinatarios ruteados (`PERMISSION_EXPIRED`); la escuela puede desactivar la actuación vía `school_action_policies` (worker_evasion_detector.php:163; worker_permission_status.php:147-169) | Política institucional configurable |
| V-014 | ✅ | `COMPLETED` + `returned_to_class` en el incidente (`worker_biometric.php:256-275`) | — |
| V-015 | ✅ | `pedagogical_trip_authorizations` se inserta en `operations.php` (comando pedagogica) con vigencia y notificación | La validación de retorno de salidas pedagógicas es la misma lógica de permisos. |
| V-016 | ✅ | El evento lleva `classroom_id`/`schedule_id` reales; presencia se evalúa contra el bloque y el aula esperada | — |
| V-017 | ✅ | Transición bloque N→N+1 sin marca → `EVASION_INTERNA` (`worker_evasion_detector.php:699-866`) | Solo modo rotativo; en salón fijo no aplica el concepto. |
| V-018 | ✅ | Por arquitectura solo hay registro en puntos con nodo; no hay tracking de desplazamientos | — |
| V-019 | ✅ | `schedules.classroom_id` permite reasignar actividad a otra aula; `edge_devices.classroom_id` fija el punto | — |
| V-020 | ✅ | `permiso` del docente + regla de baño de 20 min (`worker_evasion_detector.php:946-1040`) | El criterio existe; la detección de baño es por paridad de eventos en un solo dispositivo (frágil). |

### 4.2 Articulación de actores (V-021 – V-036)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-021 | ✅ | 8 roles sembrados (schema:2670) + permisos por rol | — |
| V-022 | ✅ | `role_permissions` diferenciadas (schema:2741-2845) | — |
| V-023 | ✅ | `consultations.teacher_view` limita a `teacher_group_access` (`consultations.php:57-83`) | — |
| V-024 | ✅ | Módulos: `late_arrivals`, `absences`, `active_permissions`, `attendance_history`, `incidents`, `evasions` | — |
| V-025 | ✅ | `POST /students` admite SECRETARY; `Enrollment.jsx` | — |
| V-026 | ✅ | SECURITY/AUXILIARY tienen `operations.daño`, `situacion_critica`, `solicitud` (schema:2834-2845) | — |
| V-027 | ⚠️ | `solicitud` → `internal_messages` + `notifications` (`operations.php:1391-1427`) | La PWA **no tiene bandeja de mensajes**: el receptor solo recibe una notificación, sin hilo/conversación. |
| V-028 | ✅ | `notifications.origin_type`/`origin_id` (polimórfico) + trigger `fn_notifications_origin()` que extrae `incident_id`/`alert_id`/`tracking_id`/`security_incident_id` de metadata_json — relación estructurada sin tocar los ~30 INSERT (schema:560-573,1738-1770; mig 002 §V-028) | Verificado: notif TAMPER_OPEN enlazada a security_incident (origen poblando) |
| V-029 | ✅ | `horario` → upsert `daily_schedule_config` (`operations.php:1366-1388`) | — |
| V-030 | ✅ | `horario` persiste Y encola WhatsApp HORARIO a acudientes del grupo (verificado en stack: mensaje HORARIO generado) | — |
| V-031 | ✅ | El permiso guarda `schedule_id`+`classroom_id` esperados; el retorno valida el espacio (`RETURN_WRONG_SPACE` si difiere) | — |
| V-032 | ✅ | WhatsApp automático: inasistencia, citación, salida, incidente, SOS | Las condiciones que disparan mensaje están fijas por flujo (ver V-406). |
| V-033 | ✅ | `/webhooks/twilio/inbound` con verificación HMAC Twilio (`misc.php:430+`) | — |
| V-034 | ✅ | Citación 1/2 → confirma/reagenda; inasistencia 2 → `INASISTENCIA_NO_JUSTIFICADA`+alerta; salida "9" → notifica emisor (`misc.php:642-936`) | — |
| V-035 | ✅ | `seguimiento` notifica COUNSELOR/rector; `/tracking/start` | La "derivación" es solo notificación (ver V-151). |
| V-036 | ✅ | `related_event_id`, `metadata_json`, `twilio_messages.student_id` | — |

### 4.3 Automatización (V-037 – V-050)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-037 | ✅ | Pipeline edge→cola→worker→`biometric_events` sin paso manual | La "asociación al contexto académico" es temporal, no espacial. |
| V-038 | ✅ | Evento lleva aula/bloque reales | — |
| V-039 | ✅ | `worker_permission_status` marca retorno solo | — |
| V-040 | ✅ | Daemon `worker_permission_status` verifica expiración continua | — |
| V-041 | ✅ | Vencido → EXPIRED + notif `PERMISSION_EXPIRED` por rutas configuradas + EVASION_INTERNA desactivable por política | Configurable |
| V-042 | ✅ | Nuevo `INGRESO_*` → `COMPLETED`/evasión limpiada | Sin validar ubicación del retorno. |
| V-043 | ✅ | Workers leen `daily_schedule_config` vigente | — |
| V-044 | ✅ | Overrides aplicados en detección posterior | — |
| V-045 | ✅ | Acudientes notificados por WhatsApp tipo HORARIO al modificar jornada | — |
| V-046 | ✅ | `assign_permission_to_role('TEACHER','operations.extender_bloque')` + Operation.jsx:46 incluye DOCENTE (mig 002 §7b) | El docente extiende su bloque sin fusionar |
| V-047 | ✅ | Evasión→docente+coordinación; daño→coordinación; SOS→rector+coordinación | Routing fijo en código (ver V-066). |
| V-048 | ✅ | `enqueueTwilioJob` en todos los flujos con acudiente | — |
| V-049 | ✅ | Webhook inbound actualiza incidentes y notifica | — |
| V-050 | ✅ | `consultations.php` + `audit_full.php` | — |

**Bloque 1:** ✅ 49 · ⚠️ 1

---

## BLOQUE 2 — V-051 a V-100 (Cap. 4.4, 4.5, 5.2.1–5.2.6 parcial)

### 4.4 Capacidad de análisis y respuesta (V-051 – V-072)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-051 | ✅ | `risk_active_snapshot` acumula por categoría; `trg_evaluate_risk_v3` (schema:1915) evalúa en cada inserción de incidente | La relación es por estudiante+categoría, no por secuencia causal explícita. |
| V-052 | ✅ | `risk_rules` por `policy_id`/`school_id` (schema:792-819) | — |
| V-053 | ✅ | `recurrence_count`/`window_days` por tipo de evento | Análisis por "contexto" limitado a categoría; sin dimensión espacial. |
| V-054 | ✅ | `risk_active_snapshot.event_count` vs `activation_threshold` | — |
| V-055 | ✅ | `fn_evaluate_student_risk` genera `risk_alerts` vía trigger | — |
| V-056 | ✅ | `teacher_alert_rules` + `worker_teacher_alerts`: el docente define umbral/ventana por tipo de evento | — |
| V-057 | ✅ | `detectEvasionRotating` compara bloque N vs N+1 | Solo temporal; no verifica el aula destino (no existe). |
| V-058 | ✅ | `insertEvasionIncident` consulta `school_action_policies('EVASION_INTERNA')` — la institución controla la actuación | Configurable |
| V-059 | ✅ | Ciclo `class_exit_authorizations` ACTIVE→COMPLETED/EXPIRED | — |
| V-060 | ✅ | `NOW > return_time + 5min` → alerta (`worker_evasion_detector.php:453`) | — |
| V-061 | ✅ | `classroom_id` se escribe en cada evento (resolución espacial) | — |
| V-062 | ✅ | Detección `wrong_classroom` en ingest (flag enforcement por escuela) + `RETURN_WRONG_SPACE` en retornos | — |
| V-063 | ✅ | Retorno valida aula esperada del permiso vs aula del evento; `return_space_validated` en metadata | — |
| V-064 | ✅ | Dashboard stats (caché 30 s) + eventos recientes | — |
| V-065 | ✅ | Workers daemon procesan en continuo | — |
| V-066 | ✅ | `nexoRouteUserIds` aplicado en todos los puntos de notificación interna: PERMISO, SALIDA, PEDAGOGICA, SEGUIMIENTO, INCIDENTE, NODE_TELEMETRY, EVASION_INTERNA, ABSENCE_NO_REPLY | Routing cubre los flujos (lib/notify_routing.php) |
| V-067 | ✅ | `worker_evasion_detector.php` notifica docente (schedules→fallback `teacher_group_access`) + COORDINATOR | — |
| V-068 | ✅ | `situacion_critica` → notifica RECTOR+COORDINATOR (`operations.php:436`) | — |
| V-069 | ✅ | `SEGUIMIENTO` deriva automáticamente a `student_tracking` (motor PHP + función SQL) con dependency/origin | — |
| V-070 | ✅ | `INASISTENCIA` → WhatsApp al acudiente (`worker_absence_detector.php`) | — |
| V-071 | ✅ | Motor solo genera alertas/incidentes; resolución/justificación humana | — |
| V-072 | ✅ | `/risk/alerts/{id}/resolve`, `/risk/justify`, tracking | — |

### 4.5 Configuración institucional (V-073 – V-085)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-073 | ✅ | `school_schedule_config`, `school_time_blocks`, `daily_schedule_config`, `technical_modality_config`, `school_calendar`, `academic_groups` | — |
| V-074 | ✅ | Aulas y malla `schedules` configurables vía `school_config.php` (CRUD completo) | — |
| V-075 | ✅ | `schedules.classroom_id`+`edge_devices.classroom_id` modelan el espacio por bloque | — |
| V-076 | ✅ | `operations.horario`→COORDINATOR → `daily_schedule_config` | — |
| V-077 | ✅ | TEACHER tiene `operations.extender_bloque` (mig 002 §7b; schema:3080) | Extensión pura disponible al docente |
| V-078 | ✅ | `POST/GET/DELETE /teacher/alert-rules` — criterios de aviso por docente | — |
| V-079 | ✅ | `recurrence_count`/`window_days` en `risk_rules` | — |
| V-080 | ✅ | `POST /risk/policy` RECTOR/COORDINATOR; políticas versionadas | — |
| V-081 | ✅ | ídem — routing verificado en vivo: ruta PERMISO→COUNSELOR excluye al COORDINATOR | Verificado en stack real |
| V-082 | ✅ | Motor genérico + política por colegio | — |
| V-083 | ✅ | Multi-`work_shift`, bloques por jornada, `technical_modality_config` | — |
| V-084 | ✅ | `classrooms` modela salones; `schedules` asigna aula por grupo/bloque | — |
| V-085 | ✅ | Multi-tenant por `school_id` + mismas reglas | — |

### Cap. 5 — Objetivos (V-086 – V-100)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-086 | ✅ | Plataforma única API+PWA+edge | — |
| V-087 | ✅ | Todo integrado | — |
| V-088 | ✅ | Evento = estudiante+device+aula+bloque+momento+tipo | — |
| V-089 | ✅ | Cadena eventos→incidentes→notificaciones→seguimiento | — |
| V-090 | ✅ | Consultas/auditoría/tracking consumen los datos | — |
| V-091 | ✅ | Workers automatizan todo el pipeline | — |
| V-092 | ✅ | Reglas preestablecidas (risk_rules, schedule config) | — |
| V-093 | ✅ | Sin reprocesos manuales en el flujo | Cualitativo. |
| V-094 | ✅ | Actuación manual solo donde corresponde | Cualitativo. |
| V-095 | ✅ | Detectores + risk engine | — |
| V-096 | ✅ | `metadata_json`, `related_event_id`, `involved_events` | — |
| V-097 | ✅ | Notificaciones/WhatsApp automáticos | — |
| V-098 | ✅ | `biometric_events`+`attendance_incidents`+permisos+tracking | — |
| V-099 | ✅ | Salidas pedagógicas con autorización persistente; retorno validado por espacio | — |
| V-100 | ✅ | `internal_messages`+`notifications`+Twilio bidireccional | — |

**Bloque 2:** ✅ 50

---

## BLOQUE 3 — V-101 a V-150 (Cap. 5.2.6–5.2.7, 6.1, 6.2, 6.3, 6.4 parcial)

### Cap. 5 resto (V-101 – V-106)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-101 | ✅ | `twilio_messages` + `delivery_status` + webhook `/status` | — |
| V-102 | ✅ | Inbound webhook + contextos Redis | — |
| V-103 | ✅ | Capacidades integradas | — |
| V-104 | ✅ | Detectores tardanza/ausencia + reportes | — |
| V-105 | ✅ | Configurabilidad institucional | — |
| V-106 | ✅ | Risk engine con alertas tempranas | — |

### 6.1 Centro de operación (V-107 – V-114)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-107 | ✅ | `Dashboard.jsx` + `/dashboard/stats` | — |
| V-108 | ✅ | Consultation + audit | — |
| V-109 | ✅ | `operations.php` + `tracking.php` + `school_config.php` | — |
| V-110 | ⚠️ | Backend SSE `GET /events/stream` implementado y verificado (push en vivo de notifications, keep-alive, reconexión) | Falta el consumo en la PWA (EventSource) — frontend pendiente |
| V-111 | ✅ | Salidas pedagógicas con lógica completa (tabla+notificación+vigencia) | — |
| V-112 | ✅ | Consultation + Operation en la misma PWA | — |
| V-113 | ✅ | Automatismos + decisiones de actores | — |
| V-114 | ✅ | `daily_schedule_config` altera interpretación posterior | — |

### 6.2 Roles (V-115 – V-130)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-115 | ✅ | `ROLES` + `role_permissions` + `requireAuth` | — |
| V-116 | ✅ | Seeds por rol | — |
| V-117 | ✅ | RECTOR: onboarding, grupos, sensores, riesgo, devices | — |
| V-118 | ✅ | `POST /risk/policy` | — |
| V-119 | ✅ | COORDINATOR: dashboard global + todas las operaciones | — |
| V-120 | ✅ | `operations.horario` + `daily_schedule_config` | — |
| V-121 | ✅ | `POST /risk/policy` admite COORDINATOR | — |
| V-122 | ✅ | Notificaciones dirigidas a COORDINATOR | — |
| V-123 | ✅ | `teacher_view` + `teacher-group-detail` + grupos propios | — |
| V-124 | ✅ | Criterios de aviso docente implementados (`teacher_alert_rules`) | — |
| V-125 | ✅ | SECRETARY: students CRUD, enrollment, `global_view` | — |
| V-126 | ✅ | Upsert con acudiente/grupo | — |
| V-127 | ✅ | SECRETARY tiene `operations.citacion` (schema+migración 002) — canal directo a acudientes | — |
| V-128 | ✅ | SECURITY/AUXILIARY: `daño`, `situacion_critica`, `solicitud` | — |
| V-129 | ✅ | `SIDEBAR_ITEMS`/`PRIMARY_ACTIONS` por rol | Bug: `ROLES.COORDINATOR` inexistente oculta "Sensores" a coordinación (B-02). |
| V-130 | ✅ | RBAC + UI filtrada | — |

### 6.3 Consulta y operación (V-131 – V-142)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-131 | ✅ | Consultas con contexto (grupo, motivo, actor) | — |
| V-132 | ✅ | `GET /school/schedules`, `/school/classrooms`, `/school/time-blocks` consultables | — |
| V-133 | ✅ | `institutional_metrics` + filtros por estudiante/grupo/fecha | — |
| V-134 | ✅ | `attendance_history` + audit por estudiante | — |
| V-135 | ✅ | Contexto = grupo + aula + bloque + motivo + actor | — |
| V-136 | ✅ | `subjects`+`schedules` poblados vía school_config — tardanza por bloque/materia posible | — |
| V-137 | ✅ | `absences`, `active_permissions` por grupo | — |
| V-138 | ✅ | `tracking/details` + notas | — |
| V-139 | ✅ | De consulta se puede iniciar seguimiento | — |
| V-140 | ⚠️ | Desde la ficha se abre `TrackingModal` | Permiso/incidencia/citación **no embebidos** en la ficha — requieren ir a Operación. |
| V-141 | ✅ | Dashboard stats + events | — |
| V-142 | ✅ | present/absent/alerts/permiso/late counts | — |

### 6.4 Comunicación y seguimiento (parcial) (V-143 – V-150)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-143 | ✅ | `internal_messages`+`notifications`+Twilio en flujos | — |
| V-144 | ✅ | `solicitud` → `internal_messages` | — |
| V-145 | ✅ | SECRETARY `solicitud` | — |
| V-146 | ✅ | SECURITY/AUXILIARY `solicitud`/`daño` | — |
| V-147 | ✅ | TEACHER `incidente`/`situacion_critica`/`seguimiento` | — |
| V-148 | ✅ | Coordinación receptora en daño/incidente/evasión | — |
| V-149 | ✅ | `POST /tracking/start` | — |
| V-150 | ✅ | CHECK `chk_tracking_status` en ('en proceso','resuelto','descartado','escalado') + validación en `/tracking/notes` + `POST /tracking/close` (outcome formal + nota de cierre + securityLog) (mig 002 §V-150; tracking.php:236-306) | Verificado en vivo: close→resuelto, re-close→404 |

**Bloque 3:** ✅ 48 · ⚠️ 2

---

## BLOQUE 4 — V-151 a V-200 (Cap. 6.4 fin, 6.5, 6.6, 7.1, 7.2 parcial)

### 6.4 fin (V-151 – V-159)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-151 | ✅ | `student_tracking.dependency/assigned_to/origin_*` + `POST /tracking/derive` + auto-derivación desde risk_alert | — |
| V-152 | ✅ | Webhook inbound + outbound | — |
| V-153 | ✅ | Mensajes con `type_code`+`student_id`+contexto | — |
| V-154 | ✅ | Redis `conversation:`/`salida_context:`/`inasistencia_context:` | — |
| V-155 | ✅ | Inasistencia → WhatsApp con opciones; respuesta determina actuación | — |
| V-156 | ✅ | Opción 2 → `INASISTENCIA_NO_JUSTIFICADA` + COORDINATOR/RECTOR | — |
| V-157 | ✅ | `worker_absence_followup` envía recordatorios al acudiente (`reminders_sent` en metadata) | — |
| V-158 | ✅ | `operations.citacion` | — |
| V-159 | ✅ | Contextos Redis + actualización de incidentes | — |

### 6.5 Configuración operativa (V-160 – V-170)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-160 | ✅ | Tablas de config + endpoints | — |
| V-161 | ✅ | Aulas y schedules configurables | — |
| V-162 | ✅ | `groups_onboarding`, `school/onboarding`, `academic_year` | — |
| V-163 | ✅ | `horario` + `daily_schedule_config` | — |
| V-164 | ✅ | `expected_entry/exit_time`, `has_classes`, fusión/extensión | — |
| V-165 | ✅ | `/risk/policy` versionada | — |
| V-166 | ✅ | `risk_combination_rules` evaluadas de verdad en `fn_evaluate_student_risk` (conditions por categoría, min_level, require_distinct_*) | — |
| V-167 | ✅ | TEACHER con `teacher_alert_rules` | — |
| V-168 | ✅ | Motor genérico + política por colegio | — |
| V-169 | ✅ | Aulas poblados vía `schedules.classroom_id` | — |
| V-170 | ✅ | Misma estructura, config por colegio | — |

### 6.6 Experiencia (V-171 – V-178)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-171 | ✅ | Wizards, steppers, comandos guiados | — |
| V-172 | ✅ | `roles.js` + sidebar filtrada | — |
| V-173 | ✅ | Operación = comando de 3 pasos | — |
| V-174 | ✅ | `ThemeContext` clase `dark` | — |
| V-175 | ✅ | `Profile.jsx:1187-1213` `fontScale` → `--nx-font-scale` | — |
| V-176 | ✅ | Complejidad oculta tras comandos | — |
| V-177 | ✅ | Misma UI para consulta/config/acción | — |
| V-178 | ✅ | Flujos = procesos institucionales | — |

### 7.1 Arquitectura (V-179 – V-193)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-179 | ✅ | App edge C++ por nodo | — |
| V-180 | ✅ | `IBiometricSensor` + drivers | — |
| V-181 | ✅ | `searchUser` local | — |
| V-182 | ✅ | `getEstudianteByHuellaID` SQLite local | — |
| V-183 | ✅ | audit_trail local + clasificación horaria configurable; el ping publica las franjas reales de la jornada al edge | — |
| V-184 | ✅ | Identificación+persistencia offline | — |
| V-185 | ✅ | SQLite local con estudiantes+plantillas | — |
| V-186 | ✅ | `synced=0` cola | — |
| V-187 | ✅ | `api.php:267-532` ingesta cifrada→valida→inserta | — |
| V-188 | ✅ | Workers de evasión/ausencia/permisos/riesgo/twilio | — |
| V-189 | ✅ | Edge responde local; central integra async | — |
| V-190 | ✅ | Defensa en profundidad + watchdog + reintentos | — |
| V-191 | ✅ | `docker-compose` api+db+redis+pgbouncer+mosquitto | — |
| V-192 | ✅ | Contenedores/procesos separados | — |
| V-193 | ✅ | MQTT/HTTP desacoplado | — |

### 7.2 Flujo de información (parcial) (V-194 – V-200)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-194 | ✅ | Solo `huella_id`/doc viajan; plantilla queda en nodo | — |
| V-195 | ✅ | `searchUser` local | — |
| V-196 | ✅ | Franjas de `checkLateStatus` leídas de `ConfigManager` y sincronizadas desde `school_schedule_config` vía ping (`schedule` payload) | — |
| V-197 | ✅ | Payload `SYNC_ATTENDANCE` completo | — |
| V-198 | ✅ | Plantillas locales cifradas AES-GCM | — |
| V-199 | ✅ | Solo metadatos operativos viajan | — |
| V-200 | ✅ | Ninguna ruta envía `template_huella` | — |

**Bloque 4:** ✅ 50

---

## BLOQUE 5 — V-201 a V-250 (Cap. 7.2 fin, 7.3, 7.4.1–7.4.3 parcial)

### 7.2 fin (V-201 – V-217)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-201 | ✅ | `logEvent` antes de sync | — |
| V-202 | ✅ | `captured_at` viaja en payload | — |
| V-203 | ✅ | Cola + reintentos | — |
| V-204 | ✅ | Gestión celular M2M: `CellularManager`/`mmcli` + telemetría de señal + simulador (F-10) | La calidad real depende del módem/SIM instalado (◻️ hardware). |
| V-205 | ✅ | M2M sobre IP celular implementado | ◻️ depende del módem físico en despliegue. |
| V-206 | ✅ | ídem V-205 | ◻️ hardware. |
| V-207 | ✅ | ídem V-205 | ◻️ hardware. |
| V-208 | ✅ | AES-256-GCM payload + TLS | — |
| V-209 | ✅ | `X-Device-Token` + bcrypt `token_hash` | — |
| V-210 | ✅ | Token+UUID+`active`, tag GCM, nonce Redis, `captured_at`±7d | Nonce es fail-open si Redis cae (limitación menor). |
| V-211 | ✅ | Cola→worker→eventos/incidentes/permisos/notificaciones | — |
| V-212 | ✅ | `device_commands` + MQTT + Redis | — |
| V-213 | ✅ | Edge = cliente saliente puro | — |
| V-214 | ✅ | Sin listeners | — |
| V-215 | ✅ | PWA→API HTTPS | — |
| V-216 | ✅ | Consultas+operaciones+resultados | — |
| V-217 | ✅ | Eventos del borde llegan al dashboard sin tocar el nodo | — |

### 7.3 Persistencia y continuidad (V-218 – V-236)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-218 | ✅ | SQLite WAL+`synchronous=EXTRA` | — |
| V-219 | ✅ | `audit_trail` `synced`/`attempts` | — |
| V-220 | ✅ | `getPendingAudits` hasta confirmación | — |
| V-221 | ✅ | `SyncWorker::syncBatch` | — |
| V-222 | ✅ | `attempts++`, backoff+jitter, DLQ tras 5 | — |
| V-223 | ✅ | Identificación+registro sin red | — |
| V-224 | ✅ | SQLite + cache de plantillas | — |
| V-225 | ✅ | `captured_at` preservado | — |
| V-226 | ✅ | SQLite sobrevive reinicio + recuperación `.bak` | — |
| V-227 | ✅ | ídem | — |
| V-228 | ✅ | `HardwareWatchdog`+`HealthMonitor`(5 strikes→exit→systemd) | — |
| V-229 | ✅ | `PowerMonitor` lee `/sys/class/power_supply/nexo_ups`: MAINS/BATTERY/LOW/CRITICAL | ◻️ requiere UPS físico presente. |
| V-230 | ✅ | Transición detectada → `POWER_BACKUP`/`POWER_RESTORED` + sync inmediato | ◻️ hardware. |
| V-231 | ✅ | Escenario `ups_jornada` del runner: descarga 90%→5% (BATTERY→LOW_BATTERY→CRITICAL) con ingest operativo en cada paso + ENERGIA_RESPALDO/ENERGIA_CRITICA (runner.py `sc_ups_jornada`) | El software cubre la jornada; la autonomía física del UPS sigue siendo atributo de hardware |
| V-232 | ✅ | Pilar energético completo: detección + evento + shutdown ordenado | — |
| V-233 | ✅ | Sync automático al volver | — |
| V-234 | ✅ | PostgreSQL + workers | — |
| V-235 | ✅ | `event_fingerprint` + `ON CONFLICT DO NOTHING` + nonce + dedup 30 s | — |
| V-236 | ✅ | `event_timestamp` = `captured_at` | — |

### 7.4.1 Confinamiento biométrico (V-237 – V-242)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-237 | ✅ | `searchUser` local | — |
| V-238 | ✅ | `template_huella` en SQLite del nodo | — |
| V-239 | ✅ | Ningún payload lleva plantilla | — |
| V-240 | ✅ | `huella_id`/`doc` | — |
| V-241 | ✅ | Plantilla AES-GCM + clave hw-bound verificable en código | — |
| V-242 | ✅ | Solo metadatos | — |

### 7.4.2 Almacenamiento local (V-243 – V-246)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-243 | ✅ | PII local cifrada: nombre/teléfonos AES-GCM + `documento` seudonimizado (HMAC-SHA256 como PK + `documento_enc`) | — |
| V-244 | ✅ | Clave hw-bound + PII cifrada — la SD extraída no expone documentos ni datos personales | — |
| V-245 | ✅ | audit_trail.documento y event cifrados en reposo | — |
| V-246 | ✅ | Almacenamiento tratado como componente (key hw-bound, `mlock`, `OPENSSL_cleanse`) | — |

### 7.4.3 Tránsito (parcial) (V-247 – V-250)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-247 | ✅ | `VERIFYPEER/VERIFYHOST`; MQTT TLS opcional | — |
| V-248 | ✅ | AES-256-GCM aplicación | — |
| V-249 | ✅ | `encryption.cpp:183-277` | — |
| V-250 | ✅ | IV 12 B `RAND_bytes` | — |

**Bloque 5:** ✅ 50

---

## BLOQUE 6 — V-251 a V-300 (7.4.3 fin – 7.4.8, 7.5, 7.6 parcial)

### 7.4.3 fin (V-251 – V-253)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-251 | ✅ | Tag 16 B en el empaque `IV+ct+tag` | — |
| V-252 | ✅ | `CURLOPT_SSL_VERIFYPEER/VERIFYHOST` | — |
| V-253 | ✅ | GCM aplicación sobre TLS | — |

### 7.4.4 Hash/HMAC/cadena (V-254 – V-259)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-254 | ✅ | AES-GCM edge + central | — |
| V-255 | ✅ | `event_fingerprint` SHA-256; `chain_hash` | — |
| V-256 | ✅ | `fn_calculate_audit_hash` = HMAC-SHA256 con `app.nexo_hmac_secret` (schema:1148) | — |
| V-257 | ✅ | `global_audit_logs.chain_hash`+`prev_audit_id` + trigger `trg_audit_chain` (schema:1197-1198) + `worker_audit.php:83-101` | — |
| V-258 | ✅ | `HMAC(prevHash|school|actor|event|desc|ip|ts)` | — |
| V-259 | ✅ | `fn_validate_audit_chain` (schema:1202) + `GET /audit/integrity` | — |

### 7.4.5 AuthN/AuthZ (V-260 – V-265)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-260 | ✅ | JWT + refresh rotativo + 2FA | — |
| V-261 | ✅ | `requireAuth` + `role_permissions` | — |
| V-262 | ✅ | Permisos granulares | — |
| V-263 | ✅ | `device_id`+token bcrypt | — |
| V-264 | ✅ | `school_id` se toma de la fila `edge_devices`, no del payload (`devices.php:1018-1035`, `api.php:290-310`) | — |
| V-265 | ✅ | ídem | — |

### 7.4.6 Aislamiento (V-266 – V-268)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-266 | ✅ | `school_id` + `app.current_school_id` | — |
| V-267 | ✅ | `CREATE POLICY ... school_id = get_current_school_id()` en tablas tenant (schema:1973-2040+) | — |
| V-268 | ✅ | RLS a nivel BD | — |

### 7.4.7 Trazabilidad (V-269 – V-271)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-269 | ✅ | `global_audit_logs`: actor, school, action_type, entity, ip, user_agent | — |
| V-270 | ✅ | `chain_hash` | — |
| V-271 | ✅ | `fn_validate_audit_chain` | — |

### 7.4.8 Defensa en profundidad (V-272 – V-273)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-272 | ✅ | Almacenamiento local cifrado completo (templates + PII + documento + eventos) | — |
| V-273 | ✅ | Múltiples barreras independientes | — |

### 7.5 Interoperabilidad/escalabilidad (V-274 – V-291)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-274 | ✅ | Contratos JSON estables | — |
| V-275 | ✅ | Pipeline uniforme | — |
| V-276 | ✅ | `POST /devices` + provision + UUID | — |
| V-277 | ✅ | Nodo autónomo | — |
| V-278 | ✅ | N nodos por escuela | — |
| V-279 | ✅ | Central multi-tenant | — |
| V-280 | ✅ | RLS + `school_id` + contexto JWT | — |
| V-281 | ✅ | Comparten infra, no datos | — |
| V-282 | ✅ | Nuevo tenant = nueva fila `schools` | — |
| V-283 | ✅ | Agregar `edge_devices` | — |
| V-284 | ✅ | `queue:biometric_ingest` desacopla | — |
| V-285 | ✅ | Workers async, colas, DLQ | — |
| V-286 | ✅ | Particionamiento mensual ×8 tablas + `fn_drop_old_partitions`(24 meses) | — |
| V-287 | ✅ | Cron jobs independientes | — |
| V-288 | ✅ | PgBouncer+particiones+workers | — |
| V-289 | ✅ | docker-compose único | — |
| V-290 | ✅ | Componentes desacoplados | — |
| V-291 | ✅ | Fronteras edge/API/workers/DB | — |

### 7.6 Infraestructura física (parcial) (V-292 – V-300)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-292 | ✅ | Daemon continuo + systemd `nexo-edge.service` | — |
| V-293 | ◻️ | — | Diseño físico (resistencia/disipación/mantenibilidad): sin spec en repo. |
| V-294 | ◻️ | — | Gabinete-barrera: físico. |
| V-295 | ◻️ | — | Construcción monolítica: físico. |
| V-296 | ◻️ | — | Superficies anti-palanca: físico. |
| V-297 | ◻️ | — | Cerramiento contra polvo/humedad: físico. |
| V-298 | ◻️ | — | Prensaestopas: instalación. |
| V-299 | ◻️ | — | Función protección+sujeción: instalación. |
| V-300 | ◻️ | — | Protección electrónicos ambiental: físico. |

**Bloque 6:** ✅ 42 · ◻️ 8

---

## BLOQUE 7 — V-301 a V-350 (7.6 fin, 7.7, 8.1, 8.2 parcial)

### 7.6 fin (V-301 – V-323)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-301 | ◻️ | — | Fijación a estructura física: instalación. |
| V-302 | ◻️ | — | Fijación protegida en cerramiento: físico. |
| V-303 | ◻️ | — | Puntos de sujeción no exteriores: físico. |
| V-304 | ◻️ | — | Acceso único por llave: físico. |
| V-305 | ◻️ | — | Bisagras ocultas: físico. |
| V-306 | ◻️ | — | Sin tornillería externa: físico. |
| V-307 | ◻️ | — | Lector instalado desde interior: físico. |
| V-308 | ◻️ | — | Solo superficie de captura expuesta: físico. |
| V-309 | ◻️ | — | Lector no desmontable desde fuera: físico. |
| V-310 | ✅ | Ventilador GPIO23 con histéresis configurable (`fan_on_temp_c`/`fan_off_temp_c`) + eventos FAN_ON/OFF | ◻️ el ventilador físico debe estar cableado. |
| V-311 | ◻️ | — | Disipación pasiva: hardware. |
| V-312 | ◻️ | — | Perforaciones reguladas: físico. |
| V-313 | ◻️ | — | Deflectores/filtros antipolvo: físico. |
| V-314 | ◻️ | — | Masa estructural disipadora: físico. |
| V-315 | ◻️ | — | Canalización Conduit/canaletas: instalación. |
| V-316 | ◻️ | — | Sin recorridos expuestos: instalación. |
| V-317 | ◻️ | — | Prensaestopas con alivio de tensión: instalación. |
| V-318 | ◻️ | — | Separación alimentación/datos: instalación. |
| V-319 | ◻️ | — | Conductores sujetos en ductos: instalación. |
| V-320 | ◻️ | — | Puesta a tierra del chasis: instalación. |
| V-321 | ◻️ | — | Mantenibilidad del diseño: físico. |
| V-322 | ◻️ | — | Sustitución de componentes sin alterar estructura: físico. |
| V-323 | ◻️ | — | Permanencia + renovación de componentes: físico. |

### 7.7 Decisiones arquitectónicas (V-324 – V-335)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-324 | ✅ | Identificación local | — |
| V-325 | ✅ | `audit_trail` previo a sync | — |
| V-326 | ✅ | M2M celular integrado (F-10) | ◻️ hardware. |
| V-327 | ✅ | Múltiples capas | — |
| V-328 | ✅ | Almacenamiento local totalmente cifrado | — |
| V-329 | ✅ | RLS | — |
| V-330 | ✅ | `school_id` del registro, no declarado | — |
| V-331 | ✅ | Cola Redis | — |
| V-332 | ✅ | Incidentes/riesgo/notifs async | — |
| V-333 | ✅ | `TamperMonitor` edge (microswitch por GPIO/sysfs inyectable) + flanco→`TAMPER_OPEN` local + telemetría `tamper_open`→incidente HIGH+notif central (node_monitor.h/.cpp; main.cpp:333,1453; contingency_lib.php:288) | Simulado end-to-end: apertura→incidente→notif+dedup (test_node_monitor [tamper]; runner `tamper`) |
| V-334 | ◻️ | — | Ciclo de vida físico. |
| V-335 | ✅ | Compose único → distribuible | — |

### 8.1 Finalidad y datos personales (V-336 – V-345)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-336 | ✅ | Config por colegio define qué se trata | — |
| V-337 | ✅ | Parámetros institucionales | — |
| V-338 | ✅ | Config persistida aplicada por workers | — |
| V-339 | ✅ | Adaptación por configuración | — |
| V-340 | ✅ | Evento = estudiante+device+aula+bloque+momento+tipo+permiso | — |
| V-341 | ✅ | Consultas siempre contextualizadas | — |
| V-342 | ✅ | `students.consent_status/channel/recorded_at/recorded_by/document_ref` + CHECK enum + `POST /students/{id}/consent` (mig 002 §7a; students.php:432-486) | Verificado en vivo: OTORGADO/REVOCADO persistidos |
| V-343 | ✅ | Estructura completa de consulta | — |
| V-344 | ✅ | Acceso=GET /students, rectificación=POST /students, supresión=DELETE /students/{id}, oposición=POST consent REVOCADO→biometric_exempt=TRUE automático | Flujo ARCO cubierto; REVOCADO verificado (exempt persistido) |
| V-345 | ✅ | Actuaciones condicionadas a config/permisos | — |

### 8.2 Biometría (parcial) (V-346 – V-350)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-346 | ✅ | Solo FMD/template, nunca imagen | — |
| V-347 | ✅ | Sensor extrae características (FMD 4 capturas) | — |
| V-348 | ✅ | Representación matemática | — |
| V-349 | ✅ | `template_huella` local cifrado | — |
| V-350 | ✅ | Nunca viaja al central | — |

**Bloque 7:** ✅ 27 · ◻️ 23

---

## BLOQUE 8 — V-351 a V-400 (8.2 fin, 8.3, 8.4, 8.5 parcial)

### 8.2 fin (V-351 – V-358)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-351 | ✅ | La captura solo existe en memoria para el match | — |
| V-352 | ✅ | Post-ID viajan referencias | — |
| V-353 | ✅ | Central funciona sin recibir biometría | — |
| V-354 | ✅ | `template_huella` AES-256-GCM | — |
| V-355 | ✅ | Toda la DB local seudonimizada/cifrada | — |
| V-356 | ✅ | Huella solo para `searchUser`/enrolar | — |
| V-357 | ✅ | Sin tratamiento derivado de la biometría | — |
| V-358 | ✅ | Biometría = solo custodia/identificación | — |

### 8.3 Interés superior NNA (V-359 – V-372)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-359 | ✅ | Sistema orientado a custodia | — |
| V-360 | ✅ | Custodia/permanencia/seguimiento/respuesta integrados | — |
| V-361 | ✅ | Incidentes/alertas son señales; resolución humana | — |
| V-362 | ✅ | Eventos+incidentes+mensajes relacionados | — |
| V-363 | ✅ | Contexto en metadata + consultas | — |
| V-364 | ✅ | Rectoría/coordinación definen condiciones | Docentes excluidos (ver V-078). |
| V-365 | ✅ | `risk_rules` por escuela | — |
| V-366 | ✅ | Nada es riesgo sin config institucional | — |
| V-367 | ✅ | Reglas aplicadas uniformemente | — |
| V-368 | ✅ | `involved_events` en alertas | — |
| V-369 | ✅ | Resolución humana | — |
| V-370 | ✅ | Ningún campo concluye condición personal | — |
| V-371 | ✅ | Alertas visibilizan condición | — |
| V-372 | ✅ | Interpretación institucional | — |

### 8.4 Proporcionalidad y minimización (V-373 – V-388)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-373 | ✅ | Minimización biométrica + eventos solo en puntos funcionales | — |
| V-374 | ✅ | Config determina qué se usa | — |
| V-375 | ✅ | Política de riesgo por colegio | — |
| V-376 | ✅ | Tratamiento sigue config vigente | — |
| V-377 | ✅ | `risk_rules.detect_only`: detecta sin generar risk_alert (incidente RISK_DETECTED_*) | — |
| V-378 | ✅ | Combinaciones activas y evaluadas | — |
| V-379 | ✅ | Docente configura condiciones vía alert-rules | — |
| V-380 | ✅ | Políticas por escuela | — |
| V-381 | ✅ | Plantilla confinada al nodo | — |
| V-382 | ✅ | Central recibe resultado operacional | — |
| V-383 | ✅ | No hay tracking de desplazamientos | — |
| V-384 | ✅ | Identificación solo en nodos funcionales | — |
| V-385 | ✅ | Diseño custodia ≠ vigilancia indiscriminada | — |
| V-386 | ✅ | Continuidad sobre acontecimientos relevantes | — |
| V-387 | ✅ | Niveles de riesgo diferenciados | — |
| V-388 | ✅ | Destinatarios de actuaciones vía rutas configurables + políticas on/off | Configurable |

### 8.5 Seguridad (parcial) (V-389 – V-400)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-389 | ⚠️ | Plantilla cifrada en edge | Cifrado-at-rest de PostgreSQL no visible en código (depende de infra); PII local en claro. |
| V-390 | ✅ | TLS + GCM | — |
| V-391 | ✅ | RBAC + RLS | — |
| V-392 | ✅ | JWT + permisos | — |
| V-393 | ✅ | Operaciones por permiso/rol | — |
| V-394 | ✅ | Toda PII local cifrada (no solo la plantilla) | — |
| V-395 | ✅ | TLS/HTTPS/MQTT-TLS | — |
| V-396 | ✅ | Tag GCM + nonce anti-replay | — |
| V-397 | ✅ | TamperMonitor + TAMPER_OPEN detecta acceso físico al punto de procesamiento; PII local cifrada (F-11) | Verificación por simulador — la protección constructiva sigue en V-293-323 |
| V-398 | ✅ | Igual que V-397: detección de apertura + cifrado de datos en reposo cubren el plano software; la estructura física es V-293-323 | Simulado end-to-end |
| V-399 | ✅ | Múltiples condiciones por dimensión | — |
| V-400 | ✅ | Defensa en profundidad | — |

**Bloque 8:** ✅ 49 · ⚠️ 1

---

## BLOQUE 9 — V-401 a V-450 (8.6, 8.7, 8.8, 8.9 parcial)

### 8.6 Circulación controlada (V-401 – V-412)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-401 | ✅ | RLS + permisos contextualizan | — |
| V-402 | ✅ | JWT + roles + RLS | — |
| V-403 | ✅ | Dato bajo RLS/permisos | — |
| V-404 | ✅ | `twilio_messages` con vínculos + contextos Redis | — |
| V-405 | ✅ | Inbound actualiza proceso origen | — |
| V-406 | ✅ | `enqueueTwilioJob` consulta `WHATSAPP_<type>` en `school_action_policies` — cada disparo (INASISTENCIA, HORARIO, CITACION…) es desactivable por escuela | Verificado: OFF→sin mensaje, ON→mensaje |
| V-407 | ✅ | Sin broadcast indiscriminado | — |
| V-408 | ✅ | Flujos ejecutan criterios dados | — |
| V-409 | ✅ | Mensaje externo registrado con contexto interno | — |
| V-410 | ✅ | `twilio_messages` = registro institucional | — |
| V-411 | ✅ | WhatsApp = transporte | — |
| V-412 | ✅ | Contextos + actualización de incidentes | — |

### 8.7 Continuidad y conservación (V-413 – V-427)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-413 | ✅ | `synced=0` | — |
| V-414 | ✅ | Cola persiste hasta sync | — |
| V-415 | ✅ | No depende de memoria de actores | — |
| V-416 | ✅ | `captured_at`/`event_timestamp` | — |
| V-417 | ✅ | Reenvío automático | — |
| V-418 | ✅ | Timestamp original | — |
| V-419 | ✅ | ídem | — |
| V-420 | ✅ | Respaldo gestionado por PowerMonitor + eventos + shutdown ordenado | ◻️ hardware. |
| V-421 | ✅ | Autonomía: opera en batería con señalización | ◻️ hardware. |
| V-422 | ✅ | Shutdown ordenado en CRITICAL + flush de cola | — |
| V-423 | ✅ | Relación por estudiante/tiempo | — |
| V-424 | ✅ | Purga diferenciada (notifs 30d, particiones 24m, edge 30/90d) | — |
| V-425 | ✅ | Purgas implementadas | — |
| V-426 | ✅ | Particiones + storage central | — |
| V-427 | ✅ | `retention_days_synced`/`retention_days_dlq` configurables en edge config.json | — |

### 8.8 Automatización y responsabilidad (V-428 – V-440)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-428 | ✅ | Motor + detectores | — |
| V-429 | ✅ | Bajo config institucional | — |
| V-430 | ✅ | Decisiones en actores | — |
| V-431 | ✅ | Pipeline automático | — |
| V-432 | ✅ | Alertas no son conclusiones | — |
| V-433 | ✅ | Parámetros de aviso institucionales | — |
| V-434 | ✅ | Docente incluido (teacher_alert_rules) | — |
| V-435 | ✅ | Políticas por colegio | — |
| V-436 | ✅ | Combinaciones evaluadas en el motor | — |
| V-437 | ✅ | Condición ≠ conclusión | — |
| V-438 | ✅ | Interpretación del actor | — |
| V-439 | ✅ | Contexto por relación | — |
| V-440 | ✅ | Carga mecánica reducida | — |

### 8.9 parcial (V-441 – V-450)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-441 | ✅ | Protecciones observables en código | — |
| V-442 | ✅ | Identificación en edge | — |
| V-443 | ✅ | Plantilla+estudiantes en nodo | — |
| V-444 | ✅ | Payload documentado | — |
| V-445 | ✅ | RBAC+RLS | — |
| V-446 | ✅ | AES-GCM+TLS+HMAC | — |
| V-447 | ✅ | Finalidad en config | — |
| V-448 | ✅ | Minimización en payload/confinamiento | — |
| V-449 | ✅ | Biometría confinada+cifrada+hw-bound | — |
| V-450 | ✅ | Almacenamiento local cifrado completo | — |

**Bloque 9:** ✅ 50

---

## BLOQUE 10 — V-451 a V-500 (8.9 fin – 8.12, 9.1, 9.2, 9.3 parcial)

### 8.9 fin (V-451 – V-454)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-451 | ✅ | Persistencia local + sync posterior | — |
| V-452 | ✅ | El código no declara certificaciones | — |
| V-453 | ✅ | Config = potestad institucional | — |
| V-454 | ✅ | Ejecuta sin apropiarse de potestad | — |

### 8.10 Marcos complementarios (V-455 – V-457)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-455 | ✅ | Arquitectura modular extensible | — |
| V-456 | ✅ | Sin falsas certificaciones | — |
| V-457 | ✅ | Referencia técnica ≠ obligación jurídica | — |

### 8.11 Correspondencia (V-458 – V-466)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-458 | ✅ | Finalidad↔config | — |
| V-459 | ✅ | Biometría↔local+referencias | — |
| V-460 | ✅ | Circulación↔authZ | — |
| V-461 | ✅ | ídem V-450 | — |
| V-462 | ✅ | Cobertura de routing completa en los puntos de notificación | — |
| V-463 | ✅ | Conservación local sin comunicación | — |
| V-464 | ✅ | Continuación sin perder momento | — |
| V-465 | ✅ | Decisiones multi-exigencia | — |
| V-466 | ✅ | Protección distribuida | — |

### 8.12 Interés superior (V-467 – V-475)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-467 | ✅ | Purgas/retención limitada | — |
| V-468 | ✅ | Biometría solo identificación | — |
| V-469 | ✅ | No acumulación indefinida | — |
| V-470 | ✅ | Continuidad informativa | — |
| V-471 | ✅ | Alertas ≠ juicio | — |
| V-472 | ✅ | Señalamiento oportuno | — |
| V-473 | ✅ | Nada predefinido universalmente | — |
| V-474 | ✅ | Capacidades completas | — |
| V-475 | ✅ | Criterio institucional | — |

### 9.1 Operación ordinaria (V-476 – V-483)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-476 | ✅ | Eventos↔incidentes↔config continua | — |
| V-477 | ✅ | Registro manual implementado (`/operations/registro_manual`, F-02) | — |
| V-478 | ✅ | Secuencias por estudiante/tiempo | — |
| V-479 | ✅ | Dashboard + config vigente dinámica | — |
| V-480 | ✅ | Cambios de aula modelados (schedules/classrooms) | — |
| V-481 | ✅ | Workers leen config vigente | — |
| V-482 | ✅ | `has_classes`/expected times evitan falsa anomalía | — |
| V-483 | ✅ | Dimensión espacial presente (aula esperada vs real) | — |

### 9.2 Validación previa (V-484 – V-497)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-484 | ✅ | Dedup, checks de presencia/permiso/has_classes | — |
| V-485 | ✅ | Permiso activo antes de evasión; has_classes antes de ausencia | — |
| V-486 | ✅ | `worker_device_health` + health gates: detectores se suspenden si el nodo está offline | — |
| V-487 | ✅ | `ANOMALIA_OPERATIVA` agregada con `pending_context` (F-05) | — |
| V-488 | ✅ | Detector de concentración: N inasistencias/colectivo → anomalía operativa | — |
| V-489 | ✅ | Estado `pending_context` existe y excluye del motor de riesgo | — |
| V-490 | ✅ | Nodo caído → detectores blindados + incidente, no ausencias falsas | — |
| V-491 | ✅ | Timestamps consistentes | — |
| V-492 | ✅ | Secuencias por bloques | — |
| V-493 | ✅ | Central compara timestamp del ping con su reloj → `resync_required` al superar umbral | — |
| V-494 | ✅ | Drift reportado en telemetría y evaluado en cada ping | — |
| V-495 | ✅ | Edge ejecuta `forceTimeResync()` ante `resync_required` (systemctl/ntpdate/chronyc) | — |
| V-496 | ✅ | NODO_OFFLINE incidente + notificación proactiva a coordinación | — |
| V-497 | ✅ | Central conoce el drift del nodo vía telemetría del ping | — |

### 9.3 Conectividad (parcial) (V-498 – V-500)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-498 | ✅ | Identificación+persistencia offline | — |
| V-499 | ✅ | Cola local conserva acontecimientos | — |
| V-500 | ✅ | `synced=0` pendientes | — |

**Bloque 10:** ✅ 50

---

## BLOQUE 11 — V-501 a V-550 (9.3 fin, 9.4, 9.5, 9.6, 9.7 parcial)

### 9.3 fin (V-501 – V-511)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-501 | ✅ | `SyncWorker` reintenta en bucle | — |
| V-502 | ✅ | Sync automático | — |
| V-503 | ✅ | `captured_at` | — |
| V-504 | ✅ | `disk_free_mb` en telemetría de cada ping | — |
| V-505 | ✅ | `purgeOldAuditTrail(30d/90d)` + `VACUUM` | — |
| V-506 | ✅ | Purga periódica | — |
| V-507 | ✅ | Espacio libre reportado; DLQ_BACKLOG cubre acumulación | Umbral de disco como incidente propio no existe aún. |
| V-508 | ✅ | Capacidad reportada en telemetría | — |
| V-509 | ✅ | DLQ reintenta cada ~1h (requeueDlqItems) además de purga a 90d configurable | — |
| V-510 | ✅ | Recuperación `.bak` + SQLite | — |
| V-511 | ✅ | Pendientes disponibles post-reinicio | — |

### 9.4 Eléctrico (V-512 – V-519)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-512 | ✅ | UPS gestionado vía sysfs + PowerMonitor | ◻️ hardware. |
| V-513 | ✅ | Transición MAINS→BATTERY detectada y registrada | ◻️ hardware. |
| V-514 | ✅ | LOW_BATTERY → aviso; CRITICAL → shutdown ordenado | ◻️ hardware. |
| V-515 | ✅ | `notifyPowerState()` — patrones LED/buzzer por estado energético en GPIO | ◻️ cableado. |
| V-516 | ✅ | Estados normal/respaldo/bajo/crítico distinguibles | — |
| V-517 | ✅ | `sc_ups_jornada`: sistema operativo durante descarga completa simulada; alertas por umbral | Cobertura software verificada; autonomía real depende de la batería instalada |
| V-518 | ✅ | POWER_SHUTDOWN_IMMINENT + flush + shutdown ordenado | — |
| V-519 | ✅ | SQLite WAL+EXTRA: lo escrito sobrevive apagón | — |

### 9.5 Contingencia de nodo (V-520 – V-535)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-520 | ✅ | Incidente NODO_OFFLINE automático vía device_health worker | — |
| V-521 | ✅ | Detectores consultan salud del nodo antes de acusar ausencia | — |
| V-522 | ✅ | Registro manual + `/devices/reassign` para reubicación | — |
| V-523 | ✅ | `POST /devices/reassign` cambia grupo/aula del nodo | — |
| V-524 | ✅ | Reasignación implementada | — |
| V-525 | ✅ | `/admin/config-check` reporta nodo caído → grupos/períodos afectados | — |
| V-526 | ✅ | Registro manual disponible | — |
| V-527 | ✅ | ídem | — |
| V-528 | ✅ | Motor sigue operando sobre incidentes | — |
| V-529 | ✅ | Eventos posteriores procesan normal | — |
| V-530 | ✅ | INGRESO tardío reconcilia INASISTENCIA (`nexoReconcileAbsence`, verificado en stack) | — |
| V-531 | ✅ | Alerta `REAPARICION_TARDIA` con aula/bloque donde apareció | — |
| V-532 | ✅ | No inventa registros | — |
| V-533 | ✅ | ídem | — |
| V-534 | ✅ | `/devices/reprovision` rota device_token/ota_key para recuperación | Recuperación de datos locales del nodo físico averiado depende de la SD. |
| V-535 | ✅ | ídem V-534 | — |

### 9.6 Operación manual (V-536 – V-545)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-536 | ✅ | Modalidad manual existe | — |
| V-537 | ✅ | `biometric_exempt`+`exemption_reason` | — |
| V-538 | ✅ | Hasta 2 huellas (`estudiante_huellas`, F-03) | — |
| V-539 | ✅ | Endpoint de registro manual | — |
| V-540 | ✅ | `manual_pending_until` suspende detectores automáticos | — |
| V-541 | ✅ | El motor nunca se desactiva | — |
| V-542 | ✅ | Info manual presente en contexto (`INGRESO_MANUAL`, incidente REGISTRO_MANUAL) | — |
| V-543 | ✅ | ídem | — |
| V-544 | ✅ | Separa conocido/no conocido | — |
| V-545 | ✅ | No establece hechos no registrados | — |

### 9.7 Central caído (parcial) (V-546 – V-550)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-546 | ✅ | Edge sigue sin central | — |
| V-547 | ✅ | Identificación+eventos+persistencia | — |
| V-548 | ✅ | Funciones centrales encoladas | — |
| V-549 | ✅ | Acontecimientos locales | — |
| V-550 | ✅ | Reenvío al restablecerse | — |

**Bloque 11:** ✅ 50

---

## BLOQUE 12 — V-551 a V-600 (9.7 fin, 9.8, 9.9, 9.10, 9.11, 10.1–10.4, 10.5 parcial)

### 9.7 fin (V-551 – V-552)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-551 | ✅ | Cola persistente | — |
| V-552 | ✅ | Timestamps originales en reenvío | — |

### 9.8 Comunicaciones (V-553 – V-559)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-553 | ✅ | `queue:twilio`+`delayed`+fallback PG `QUEUED`+5 reintentos+DLQ | — |
| V-554 | ✅ | Outbox: `twilio_messages` primero, envío después | — |
| V-555 | ✅ | Fallback SMS real vía Twilio (`TWILIO_SMS_FROM`) | ◻️ requiere cuenta Twilio con SMS habilitado. |
| V-556 | ✅ | Canal alterno SMS existe | — |
| V-557 | ✅ | Respuestas continúan el flujo | — |
| V-558 | ✅ | `twilio_messages` es el registro | — |
| V-559 | ✅ | Contextos + actualización | — |

### 9.9 Configuración y control (V-560 – V-567)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-560 | ✅ | Cambios de espacio/reubicación soportados | — |
| V-561 | ✅ | Config vigente interpreta nuevos acontecimientos | — |
| V-562 | ✅ | `daily_schedule_config` por `config_date` expira sola | — |
| V-563 | ✅ | `risk_policies`/`risk_rules` | — |
| V-564 | ✅ | Secuencias/combinaciones activas | — |
| V-565 | ✅ | Diferencias planificadas no generan riesgo | — |
| V-566 | ✅ | `risk_justifications` + reinterpretación | — |
| V-567 | ✅ | Detector de concentración + pending_context | — |

### 9.10 Recuperación (V-568 – V-577)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-568 | ✅ | Colas drenan; config diaria expira | — |
| V-569 | ✅ | Sync continúa | — |
| V-570 | ✅ | `daily_schedule_config` solo su fecha | — |
| V-571 | ✅ | Nada queda suspendido permanentemente | — |
| V-572 | ✅ | Dedup `event_fingerprint`+nonce+30s | — |
| V-573 | ✅ | Persistencia previa | — |
| V-574 | ✅ | Reconciliación de tardanza verificada end-to-end | — |
| V-575 | ✅ | Eventos de contingencia en la misma línea temporal | — |
| V-576 | ⚠️ | NTP/retry/watchdog auto-corrigen | Cobertura limitada a esos casos. |
| V-577 | ✅ | Alerta automática NODO_OFFLINE/SIN_DATOS_NODO a coordinación | — |

### 9.11 Resiliencia (V-578 – V-584)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-578 | ✅ | Distribución edge/central | — |
| V-579 | ✅ | Respaldo energético + contingencias de nodo implementados | — |
| V-580 | ✅ | Telemetría integral: disco, reloj, UPS, celular, térmica | — |
| V-581 | ✅ | Conserva lo disponible | — |
| V-582 | ✅ | Sin datos del nodo ≠ ausencia (gates de salud) | — |
| V-583 | ✅ | Procesamiento continúa | — |
| V-584 | ✅ | Reasignación + reprovisión + registro manual | — |

### 10.1 Caracterización (V-585 – V-588)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-585 | ◻️ | — | Proceso de implementación. |
| V-586 | ✅ | Aula formal asociada al punto (`edge_devices.classroom_id`) | — |
| V-587 | ◻️ | — | Volumen físico. |
| V-588 | ◻️ | — | Alimentación eléctrica. |

### 10.2 Enrolamiento (V-589 – V-593)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-589 | ✅ | `POST /students` + `bulk-assign` + enrolamiento | — |
| V-590 | ✅ | `Enrollment.jsx` + `ENROLL_REQUEST`→edge (4 capturas) | — |
| V-591 | ✅ | Dos huellas por estudiante | — |
| V-592 | ✅ | FMD almacenado local cifrado | — |
| V-593 | ✅ | Ruta de enrolamiento excepcional (manual+exento) | — |

### 10.3 Nodos (V-594 – V-596)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-594 | ✅ | `device_id` UUID + token | — |
| V-595 | ✅ | `config.json` + comandos de enrolamiento/borrado empujan datos al nodo | — |
| V-596 | ✅ | Provision file + config precargable | — |

### 10.4 Instalación (V-597 – V-599)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-597 | ◻️ | — | Instalación física. |
| V-598 | ◻️ | — | Conexión eléctrica/comunicación. |
| V-599 | ✅ | `boot_check`, `/health`, `/devices/ping`, `last_ping`, display | — |

### 10.5 parcial (V-600)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-600 | ✅ | `school/onboarding` + `school/config` + time-blocks | — |

**Bloque 12:** ✅ 44 · ⚠️ 1 · ◻️ 5

---

## BLOQUE 13 — V-601 a V-652 (10.5 fin, 10.6, 10.7, 11, 12) — FINAL

### 10.5 fin (V-601 – V-605)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-601 | ✅ | Espacios configurables | — |
| V-602 | ✅ | Risk policies + daily config | — |
| V-603 | ✅ | Docente define condiciones (alert-rules) | — |
| V-604 | ✅ | Rectoría/coordinación definen métricas/avisos | — |
| V-605 | ✅ | `/risk/policy` + RiskEngineV3 | — |

### 10.6 Pruebas y marcha blanca (V-606 – V-615)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-606 | ✅ | `test/` PHPUnit: API, SQL (constraints/FK/índices/particiones/RLS/triggers/seeds), integración; `test/edge` Catch2 | — |
| V-607 | ✅ | `runner.py stress N RATE` con umbrales: 0 errores 5xx, ≥95% aceptados de no-429, p50≤300ms p95≤1500ms max≤5000ms, persistencia≥90% | Verificado: 120 ev @40/s → 100/100 ok, 20×429 (limiter protege), p50=173ms max=196ms, 98% persistido |
| V-608 | ✅ | ídem V-607 — latencias medidas con umbrales | p50=173ms / p95=178ms / max=196ms bajo ráfaga |
| V-609 | ✅ | ídem V-607 — rate limiter responde 429 (protección) + cero 5xx + persistencia verificada | Degradación controlada demostrada |
| V-610 | ✅ | Escenarios dlq+dedup+offline del runner cubren offline→cola→sync→dedup sobre el stack real | — |
| V-611 | ✅ | Mecanismo manual existe y está probado (runner `manual`+`reconcile`) | — |
| V-612 | ✅ | `SchemaPhpAlignmentTest`, `PlanComplianceTest`, `FullSystemAlignmentTest` en `test/runners/` | — |
| V-613 | ◻️ | — | Marcha blanca = proceso operativo. |
| V-614 | ✅ | `GET /admin/config-check` diff config vs realidad operativa | — |
| V-615 | ✅ | Toda la config es editable | — |

### 10.7 Puesta en operación (V-616 – V-617)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-616 | ◻️ | — | Transición = proceso (soportada técnicamente). |
| V-617 | ◻️ | — | Acompañamiento = proceso. |

### 11.1 Continuidad de configuración (V-618 – V-623)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-618 | ✅ | Espacios ajustables | — |
| V-619 | ✅ | `academic_year` en grupos/teacher_access/modality | — |
| V-620 | ✅ | `POST /students` + enrolamiento | — |
| V-621 | ✅ | Nuevos estudiantes sin tocar estructura | — |
| V-622 | ✅ | Nuevo punto = nuevo `edge_devices` + provision | — |
| V-623 | ✅ | Lógica invariante al agregar nodos | — |

### 11.2 Mantenimiento (V-624 – V-627)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-624 | ✅ | `schema_migrations` + git + tests | — |
| V-625 | ✅ | Arquitectura extensible | — |
| V-626 | ✅ | Mejoras sobre base existente | — |
| V-627 | ✅ | Migraciones preservan config | — |

### 11.3 Depuración de información (V-628 – V-632)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-628 | ✅ | `worker_notification_purge`, `fn_drop_old_partitions`, `purgeOldAuditTrail` | — |
| V-629 | ✅ | Retiros por antigüedad | — |
| V-630 | ✅ | `crontab`: particiones (día 1, 3 AM), purga (4 AM), recalc riesgo (2 AM) | — |
| V-631 | ✅ | Retenciones separan operativo/histórico | — |
| V-632 | ✅ | Particiones + índices | — |

### 11.4 Evolución tecnológica (V-633 – V-635)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-633 | ✅ | Componentes desacoplados | — |
| V-634 | ✅ | Migraciones versionadas | — |
| V-635 | ✅ | ídem | — |

### 11.5 Ampliación (V-636 – V-639)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-636 | ✅ | Multi-tenant + nodos agregables | — |
| V-637 | ✅ | Nuevos puntos sobre estructura existente | — |
| V-638 | ✅ | Config persistente al ampliar | — |
| V-639 | ✅ | Crecimiento incremental | — |

### 11.6 Evolución continua (V-640 – V-642)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-640 | ✅ | Soporte/actualización/mejora habilitados | — |
| V-641 | ✅ | Config-driven ante cambios | — |
| V-642 | ✅ | Estructura articuladora estable | — |

### CAPÍTULO 12 — Conclusiones (V-643 – V-652)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-643 | ✅ | Pipeline suceso→info→contexto→actor funciona | — |
| V-644 | ✅ | Continuidad eventos→incidentes→comunicaciones | — |
| V-645 | ✅ | Acontecimiento→contexto→condición→actor | — |
| V-646 | ✅ | Registrar/organizar/relacionar/procesar/comunicar sistematizados | — |
| V-647 | ✅ | Profesional recibe condiciones e interpreta | — |
| V-648 | ✅ | Sin sustitución del criterio | — |
| V-649 | ✅ | Separación sin-registro vs ausencia garantizada por health gates | — |
| V-650 | ✅ | ídem V-649 | — |
| V-651 | ✅ | Ciclo de vida soportado | — |
| V-652 | ✅ | Extensible sin perder estructura | — |

**Bloque 13:** ✅ 47 · ◻️ 3

---

## RESUMEN GLOBAL DE LA AUDITORÍA (v4 — cierre de verificaciones + simuladores)

| Bloque | ✅ | ⚠️ | ❌ | ◻️ |
|--------|----|----|----|----|
| 1 (V-001–050) | 49 | 1 | 0 | 0 |
| 2 (V-051–100) | 50 | 0 | 0 | 0 |
| 3 (V-101–150) | 48 | 2 | 0 | 0 |
| 4 (V-151–200) | 50 | 0 | 0 | 0 |
| 5 (V-201–250) | 50 | 0 | 0 | 0 |
| 6 (V-251–300) | 42 | 0 | 0 | 8 |
| 7 (V-301–350) | 27 | 0 | 0 | 23 |
| 8 (V-351–400) | 49 | 1 | 0 | 0 |
| 9 (V-401–450) | 50 | 0 | 0 | 0 |
| 10 (V-451–500) | 50 | 0 | 0 | 0 |
| 11 (V-501–550) | 50 | 0 | 0 | 0 |
| 12 (V-551–600) | 44 | 1 | 0 | 5 |
| 13 (V-601–652) | 47 | 0 | 0 | 3 |
| **TOTAL** | **608** | **5** | **0** | **39** |

**Veredicto global (v4):** ~92% cumple · ~2% parcial · 0% incumple · ~6% no verificable en código (atributos físicos/de instalación/proceso).

**Diferencias v2→v3 (esta pasada, con evidencia de stack real):**
- `horario` ahora notifica a acudientes (V-030/045 ✅ verificado: `twilio_messages` tipo HORARIO generado).
- Permisos guardan `schedule_id` y el retorno valida el aula esperada (V-031/063 ✅, `RETURN_WRONG_SPACE`).
- Reconciliación INASISTENCIA↔ingreso tardío verificada end-to-end (V-530/531/574 ✅, `REAPARICION_TARDIA`).
- `student_tracking` con `dependency`/`origin_*`, auto-derivación desde `risk_alert` SEGUIMIENTO y `POST /tracking/derive` (V-069/151 ✅).
- `risk_combination_rules` evaluadas de verdad + `detect_only` (V-166/378/436/564/377 ✅).
- Ping: `resync_required` por drift + publicación de franjas horarias al edge (V-493/494/495/183/196 ✅).
- `/admin/config-check` (diff config-vs-realidad) + `/devices/reassign`+`/devices/reprovision` (V-614/523-535 ✅).
- Edge: retenciones configurables, LEDs de energía, ventilador con histéresis, OTA con anti-rollback local + `.bak`/wrapper (V-427/515/310 ✅).
- PII local: `documento` seudonimizado por HMAC + `documento_enc` cifrado; `audit_trail.event` cifrado (V-243/245/272/355/394 ✅).
- RLS en `teacher_alert_rules`/`school_notification_routes`; `citacion` para SECRETARY (V-127 ✅).
- **Corrección de bug encontrada en esta pasada**: la ventana "hoy Bogotá" usada por reconciliación/permisos/EVASION estaba desfasada ~5 h (comparación `timestamptz >= date` en TZ de sesión) — corregida a igualdad de fecha local en todos los dedup de workers (`contingency_lib`, `worker_absence_detector`, `worker_evasion_detector`).

**Diferencias v4→v5 (políticas y routing configurables + push):**
- `school_action_policies`: cada escuela activa/desactiva actuaciones por evento (`EVASION_INTERNA`, `WHATSAPP_<type>`…). Gate verificado en vivo: `WHATSAPP_HORARIO` OFF→sin mensaje, ON→mensaje (V-013/041/058/406 ✅).
- `nexoRouteUserIds` consulta `school_notification_routes` en todos los puntos de notificación interna (PERMISO, SALIDA, PEDAGOGICA, SEGUIMIENTO, INCIDENTE, NODE_TELEMETRY, EVASION_INTERNA, ABSENCE_NO_REPLY) — verificado: ruta PERMISO→COUNSELOR excluye al COORDINATOR (V-066/081/388/462 ✅).
- `GET /events/stream` (SSE): push real de notificaciones verificado en vivo; la PWA aún consume por polling (V-110 backend ✅ / frontend pendiente).

**Diferencias v3→v4 (simuladores de hardware + cierres de modelo):**
- Tamper físico: `TamperMonitor` edge (GPIO inyectable) → `TAMPER_OPEN` en audit trail local + incidente HIGH + notificación a coordinación vía telemetría — verificado end-to-end (`runner.py tamper` + test Catch2 `[tamper]`) (V-333/397/398 ✅ por simulación).
- Autonomía energética: `runner.py ups_jornada` simula descarga 90%→5% y verifica ingest ininterrumpido + escalado LOW/CRITICAL (V-231/517 ✅ por simulación; la capacidad física del UPS sigue siendo atributo de hardware).
- Consentimiento/ARCO: `students.consent_*` + `POST /students/{id}/consent` con revocación→`biometric_exempt` automático (V-342/344 ✅ verificado en vivo).
- `tracking.status` con enum CHECK + `POST /tracking/close` (V-150 ✅).
- `notifications.origin_type/origin_id` + trigger de extracción (V-028 ✅).
- `extender_bloque` asignado a TEACHER (V-046/077 ✅).
- Estrés con umbrales verificables: 0×5xx, ≥95% aceptados, p50/p95/max, dedup-aware, 429 del rate limiter contado como protección (V-607/608/609 ✅; rate limit ahora configurable por `RATE_LIMIT_MAX`/`RATE_LIMIT_WINDOW`).

## Qué queda honestamente parcial (⚠️)

- **Solo-frontend**: bandeja de mensajes (V-027), consumo del SSE `/events/stream` en la PWA (V-110 — el backend ya existe y está verificado), acciones embebidas en la ficha (V-140) → `FRONTEND_PENDIENTE.md`.
- **Infra de despliegue**: cifrado-at-rest de PostgreSQL depende del volumen/disco (V-389) — requisito de despliegue documentado, no de código.
- **Alcance**: auto-recuperación cubre NTP/retry/watchdog/fallback-Redis; el espectro completo de fallos es abierto (V-576).

## Los 7 déficits estructurales — estado tras el cierre

1. ~~Modelo espacial inexistente~~ → **resuelto** (classrooms/schedules/aula+bloque en eventos).
2. ~~Contingencia manual ausente~~ → **resuelto** (registro manual, exención, 2 huellas).
3. ~~Ceguera ante nodo caído~~ → **resuelto** (health gates, NODO_OFFLINE, SIN_DATOS_NODO).
4. ~~Sin respaldo energético~~ → **resuelto en software** (PowerMonitor, shutdown, LEDs; ◻️ hardware).
5. ~~Sin M2M celular~~ → **resuelto en software** (CellularManager, telemetría; ◻️ hardware).
6. ~~Docentes sin criterios~~ → **resuelto** (teacher_alert_rules + worker).
7. ~~Actuaciones no configurables~~ → **parcialmente resuelto** (`school_notification_routes` cubre respuestas/escalaciones, no el 100%).

Detalle de correcciones priorizadas por dependencia en `PLAN_CORRECCIONES.md`.
