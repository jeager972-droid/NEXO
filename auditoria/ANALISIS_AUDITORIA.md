# ANÁLISIS DE AUDITORÍA — DOCUMENTO → CÓDIGO (NEXO) · v2 revisada

Segunda pasada de verificación de las 652 verificaciones (`LISTA_VERIFICACIONES_DOCUMENTO_CODIGO.md`). Cada ítem argumenta **por qué cumple** (evidencia en código) y **por qué no / limitación** (qué falta o desvía). Estados: ✅ CUMPLE · ⚠️ PARCIAL · ❌ NO CUMPLE · ◻️ N/A-CÓDIGO (atributo físico/instalación/proceso).

Componentes: `backend/api` (PHP), `backend/edge` (C++), `sql/schema.sql`, `PWA` (React), workers, infra.

---

## BLOQUE 1 — V-001 a V-050 (Cap. 4.1, 4.2, 4.3)

### 4.1 Custodia inteligente (V-001 – V-020)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-001 | ✅ | `main.cpp:817` identifica en el nodo y `worker_biometric.php:243` persiste `biometric_events` | "En un aula" se infiere solo por dispositivo (sin aula formal registrada). |
| V-002 | ⚠️ | El evento queda ligado a `student_id`+`device_id`; el grupo se deriva por `student_group_assignments` | `classroom_id` y `schedule_id` del evento **nunca se escriben** (`worker_biometric.php:243-250`); el bloque horario no queda registrado en el evento. |
| V-003 | ⚠️ | Modo rotativo (`worker_evasion_detector.php:537+`) evalúa transición bloque N→N+1 por `school_time_blocks` | La rotación es solo **temporal**: no existe modelo de aula por bloque (`schedules` vacío), así que "nueva identificación → nuevo bloque" se infiere por hora, no por espacio. |
| V-004 | ⚠️ | `fusionar_bloque` marca `metadata_json.merged=true` y el detector lo respeta (`worker_evasion_detector.php:578-582`) | `extender_bloque` solo escribe `expected_exit_time`, que el detector **no usa** para suprimir evasión post-bloque (solo vale `merged`). Continuidad cubierta solo por fusión. |
| V-005 | ✅ | `daily_schedule_config.has_classes=FALSE`/`expected_entry_time` se respetan en `worker_absence_detector.php:118-169` | Sin limitación relevante: actos/reuniones se modelan por config diaria. |
| V-006 | ✅ | `autorizar_salida` → `WAIT_EXIT_FINGERPRINT` → edge exige huella y emite `SALIDA_AUTORIZADA` (`main.cpp:1310-1368`) | Solo aplica al sensor del autorizante (sensor de coordinación). |
| V-007 | ✅ | `worker_biometric.php:211-235` cruza el evento con `class_exit_authorizations` activa y lo etiqueta `permiso_event_role` | Vinculación temporal, no espacial (no valida aula). |
| V-008 | ✅ | `worker_permission_status.php:68-95` marca `COMPLETED` al ver `INGRESO_%` posterior a `exit_time` | Acepta INGRESO en **cualquier** nodo como retorno válido (ver V-063). |
| V-009 | ⚠️ | `exit_time`/`return_time` en `class_exit_authorizations` permiten derivar la duración | No se persiste `actual_return_time` ni duración explícita; en `school_exit_authorizations` `actual_return_time` se escribe al **salir** (`worker_biometric.php:284`), no al retornar. |
| V-010 | ✅ | `authorized_by_user_id` en `class_exit_authorizations` (schema:606-618) | — |
| V-011 | ✅ | `has_active_permiso()` (schema:1454) + filtro `status='ACTIVE'` y ventana en `worker_biometric.php:214-216` | — |
| V-012 | ✅ | `worker_permission_status.php` pasa a `EXPIRED` tras `return_time+5min` sin retorno | — |
| V-013 | ⚠️ | Existe reacción automática: `EXPIRED` + alerta `EVASION_INTERNA` (`worker_evasion_detector.php:453`) | No es "la actuación configurada": la consecuencia es fija en código, no definible por la institución. |
| V-014 | ✅ | `COMPLETED` + `returned_to_class` en el incidente (`worker_biometric.php:256-275`) | — |
| V-015 | ❌ | `pedagogical_trip_authorizations` existe en schema (623-634) y el comando `pedagogica` envía WhatsApp (`operations.php:1010`) | **Ningún código inserta en la tabla**: no hay autorización con vigencia, ni validación de retorno — no se aplica la misma lógica. |
| V-016 | ⚠️ | En modo rotativo se exige `INGRESO` dentro de la ventana del bloque (`worker_absence_detector.php:202-265`) | "Espacio académico" no existe: un INGRESO en **cualquier** nodo cuenta como presente en el bloque. |
| V-017 | ✅ | Transición bloque N→N+1 sin marca → `EVASION_INTERNA` (`worker_evasion_detector.php:699-866`) | Solo modo rotativo; en salón fijo no aplica el concepto. |
| V-018 | ✅ | Por arquitectura solo hay registro en puntos con nodo; no hay tracking de desplazamientos | — |
| V-019 | ⚠️ | `horario`/`fusionar_bloque` modifican el contexto temporal esperado | "Cambio efectivo de espacio" (reubicar la actividad a otra aula) no tiene representación — no hay aulas ni reasignación. |
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
| V-028 | ⚠️ | `notifications.metadata_json` lleva `action`+`student_id`; `internal_messages.metadata_json` existe | Sin FK estructurada al acontecimiento origen: el contexto es metadata libre, no relación verificable. |
| V-029 | ✅ | `horario` → upsert `daily_schedule_config` (`operations.php:1366-1388`) | — |
| V-030 | ❌ | El comando `horario` persiste el cambio correctamente | **No envía WhatsApp ni notificación alguna al acudiente** — la modificación es invisible para la familia. |
| V-031 | ⚠️ | La salida exige huella en el `edge_devices` del autorizante (`operations.php:827-834`) | "Espacio actualizado" no se modela: el punto de validación es siempre el sensor fijo de coordinación, no el espacio donde esté el estudiante. |
| V-032 | ✅ | WhatsApp automático: inasistencia, citación, salida, incidente, SOS | Las condiciones que disparan mensaje están fijas por flujo (ver V-406). |
| V-033 | ✅ | `/webhooks/twilio/inbound` con verificación HMAC Twilio (`misc.php:430+`) | — |
| V-034 | ✅ | Citación 1/2 → confirma/reagenda; inasistencia 2 → `INASISTENCIA_NO_JUSTIFICADA`+alerta; salida "9" → notifica emisor (`misc.php:642-936`) | — |
| V-035 | ✅ | `seguimiento` notifica COUNSELOR/rector; `/tracking/start` | La "derivación" es solo notificación (ver V-151). |
| V-036 | ✅ | `related_event_id`, `metadata_json`, `twilio_messages.student_id` | — |

### 4.3 Automatización (V-037 – V-050)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-037 | ✅ | Pipeline edge→cola→worker→`biometric_events` sin paso manual | La "asociación al contexto académico" es temporal, no espacial. |
| V-038 | ⚠️ | Estudiante+autorización+momento se vinculan automáticamente (V-007) | "Espacio" solo via `device_id` del evento — sin aula explícita. |
| V-039 | ✅ | `worker_permission_status` marca retorno solo | — |
| V-040 | ✅ | Daemon `worker_permission_status` verifica expiración continua | — |
| V-041 | ⚠️ | Tras vencer: `EXPIRED` + `EVASION_INTERNA` automática | Actuación fija, no "la prevista" configurable. |
| V-042 | ✅ | Nuevo `INGRESO_*` → `COMPLETED`/evasión limpiada | Sin validar ubicación del retorno. |
| V-043 | ✅ | Workers leen `daily_schedule_config` vigente | — |
| V-044 | ✅ | Overrides aplicados en detección posterior | — |
| V-045 | ❌ | `horario` persiste la config | **Cero comunicaciones** al cambiar horario (ni acudiente ni actores). |
| V-046 | ⚠️ | El docente tiene `fusionar_bloque` (equivalente funcional de extender permanencia) | Literal `extender_bloque` está restringido a RECTOR/COORDINATOR (schema:2752,2781; `Operation.jsx:46`). El docente no puede extender un bloque sin fusionarlo con el siguiente. |
| V-047 | ✅ | Evasión→docente+coordinación; daño→coordinación; SOS→rector+coordinación | Routing fijo en código (ver V-066). |
| V-048 | ✅ | `enqueueTwilioJob` en todos los flujos con acudiente | — |
| V-049 | ✅ | Webhook inbound actualiza incidentes y notifica | — |
| V-050 | ✅ | `consultations.php` + `audit_full.php` | — |

**Bloque 1:** ✅ 34 · ⚠️ 13 · ❌ 3

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
| V-056 | ❌ | El docente recibe notificaciones de evasión | **No puede establecer** su propio umbral de tardanzas: `POST /risk/policy` solo RECTOR/COORDINATOR y no hay reglas por docente. |
| V-057 | ✅ | `detectEvasionRotating` compara bloque N vs N+1 | Solo temporal; no verifica el aula destino (no existe). |
| V-058 | ⚠️ | La evasión genera `EVASION_INTERNA` + notificaciones | La actuación es fija, no "configurada" por la institución. |
| V-059 | ✅ | Ciclo `class_exit_authorizations` ACTIVE→COMPLETED/EXPIRED | — |
| V-060 | ✅ | `NOW > return_time + 5min` → alerta (`worker_evasion_detector.php:453`) | — |
| V-061 | ❌ | Hay `classroom_id` en `biometric_events` (columna) | **Nunca se escribe**; `schedules`/`classrooms` vacíos → imposible saber el "espacio que corresponde". |
| V-062 | ❌ | — | Sin modelo de aula no hay nada que "informar que dejó de corresponder". |
| V-063 | ❌ | El permiso sí se cierra por nueva identificación | Cierra con **cualquier** `INGRESO_%` (`worker_permission_status.php:68-95`) sin validar dispositivo/espacio — exactamente lo que el requisito prohíbe. |
| V-064 | ✅ | Dashboard stats (caché 30 s) + eventos recientes | — |
| V-065 | ✅ | Workers daemon procesan en continuo | — |
| V-066 | ⚠️ | Cada condición notifica a un actor concreto | Destinatario **hardcodeado** por tipo; la institución no define quién recibe qué. |
| V-067 | ✅ | `worker_evasion_detector.php` notifica docente (schedules→fallback `teacher_group_access`) + COORDINATOR | — |
| V-068 | ✅ | `situacion_critica` → notifica RECTOR+COORDINATOR (`operations.php:436`) | — |
| V-069 | ⚠️ | `risk_alerts.escalation_state='SEGUIMIENTO'` existe y `seguimiento` inicia tracking | El `student_tracking` **no se instancia automáticamente** desde la alerta — requiere acción manual. |
| V-070 | ✅ | `INASISTENCIA` → WhatsApp al acudiente (`worker_absence_detector.php`) | — |
| V-071 | ✅ | Motor solo genera alertas/incidentes; resolución/justificación humana | — |
| V-072 | ✅ | `/risk/alerts/{id}/resolve`, `/risk/justify`, tracking | — |

### 4.5 Configuración institucional (V-073 – V-085)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-073 | ✅ | `school_schedule_config`, `school_time_blocks`, `daily_schedule_config`, `technical_modality_config`, `school_calendar`, `academic_groups` | — |
| V-074 | ⚠️ | Configurables: jornadas, bloques, grupos, docentes, estudiantes, dispositivos | **Aulas y malla `schedules` no son configurables** — tablas sin INSERT en todo el backend. |
| V-075 | ⚠️ | `edge_devices.group_id`, `teacher_group_access`, `school_time_blocks` | La relación "espacio" no existe (sin aulas/schedules). |
| V-076 | ✅ | `operations.horario`→COORDINATOR → `daily_schedule_config` | — |
| V-077 | ⚠️ | Docente tiene `fusionar_bloque` | No tiene `extender_bloque`; la extensión pura la hace rectoría/coordinación. |
| V-078 | ❌ | — | Ningún permiso ni endpoint de criterios de aviso para TEACHER. |
| V-079 | ✅ | `recurrence_count`/`window_days` en `risk_rules` | — |
| V-080 | ✅ | `POST /risk/policy` RECTOR/COORDINATOR; políticas versionadas | — |
| V-081 | ⚠️ | Reglas definen umbrales y niveles | El actor destinatario de cada actuación no es configurable. |
| V-082 | ✅ | Motor genérico + política por colegio | — |
| V-083 | ✅ | Multi-`work_shift`, bloques por jornada, `technical_modality_config` | — |
| V-084 | ⚠️ | `rotates_classrooms` + cambios de horario | "Grupos en un mismo salón" no representable (sin aulas). |
| V-085 | ✅ | Multi-tenant por `school_id` + mismas reglas | — |

### Cap. 5 — Objetivos (V-086 – V-100)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-086 | ✅ | Plataforma única API+PWA+edge | — |
| V-087 | ✅ | Todo integrado | — |
| V-088 | ⚠️ | Eventos llevan estudiante/device/momento/tipo | Falta espacio/bloque en el registro. |
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
| V-099 | ⚠️ | Presencia/salidas/retornos/permisos/seguimiento cubiertos | Salidas pedagógicas sin lógica operativa (V-015); retorno sin validación de espacio. |
| V-100 | ✅ | `internal_messages`+`notifications`+Twilio bidireccional | — |

**Bloque 2:** ✅ 35 · ⚠️ 10 · ❌ 5

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
| V-110 | ⚠️ | Datos frescos (caché 30 s, polling 60 s) | No es tiempo real estricto: polling, sin SSE/WebSocket push. |
| V-111 | ⚠️ | Catálogo cubre permisos/horario/extensión/citación/incidente/seguimiento | "Salidas pedagógicas" solo envían mensaje, sin autorización operativa; "extender bloque" no accesible al docente. |
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
| V-124 | ⚠️ | Docente: consulta/permisos/reporte/seguimiento | **Criterios de aviso no** (V-078). |
| V-125 | ✅ | SECRETARY: students CRUD, enrollment, `global_view` | — |
| V-126 | ✅ | Upsert con acudiente/grupo | — |
| V-127 | ⚠️ | SECRETARY tiene `solicitud`+`situacion_critica` | Sin canal a acudientes (ni `citacion` ni WhatsApp directo). |
| V-128 | ✅ | SECURITY/AUXILIARY: `daño`, `situacion_critica`, `solicitud` | — |
| V-129 | ✅ | `SIDEBAR_ITEMS`/`PRIMARY_ACTIONS` por rol | Bug: `ROLES.COORDINATOR` inexistente oculta "Sensores" a coordinación (B-02). |
| V-130 | ✅ | RBAC + UI filtrada | — |

### 6.3 Consulta y operación (V-131 – V-142)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-131 | ✅ | Consultas con contexto (grupo, motivo, actor) | — |
| V-132 | ⚠️ | Cubre estudiantes/grupos/asistencia/tardanzas/inasistencias/permisos/acontecimientos/seguimientos | **Horarios no consultables** (sin módulo; solo GET `/school/config`); aulas/materias inexistentes. |
| V-133 | ✅ | `institutional_metrics` + filtros por estudiante/grupo/fecha | — |
| V-134 | ✅ | `attendance_history` + audit por estudiante | — |
| V-135 | ⚠️ | Contexto = grupo + motivo + actor | Sin aula/bloque/materia (raíz F-01). |
| V-136 | ⚠️ | `late_arrivals` por estudiante/grupo/fecha | No por "clase" (materia/bloque) — `subjects`/`schedules` vacíos. |
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
| V-150 | ⚠️ | Notas + `status` + documentación de actuaciones | `status` es texto libre sin workflow/enum ni endpoint formal de cierre (`tracking.php:168-173`). |

**Bloque 3:** ✅ 41 · ⚠️ 9 · ❌ 0

---

## BLOQUE 4 — V-151 a V-200 (Cap. 6.4 fin, 6.5, 6.6, 7.1, 7.2 parcial)

### 6.4 fin (V-151 – V-159)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-151 | ❌ | El comando `seguimiento` notifica a COUNSELOR | `student_tracking` **no tiene** campo de dependencia/asignado/derivación; no existe endpoint `derive`. |
| V-152 | ✅ | Webhook inbound + outbound | — |
| V-153 | ✅ | Mensajes con `type_code`+`student_id`+contexto | — |
| V-154 | ✅ | Redis `conversation:`/`salida_context:`/`inasistencia_context:` | — |
| V-155 | ✅ | Inasistencia → WhatsApp con opciones; respuesta determina actuación | — |
| V-156 | ✅ | Opción 2 → `INASISTENCIA_NO_JUSTIFICADA` + COORDINATOR/RECTOR | — |
| V-157 | ⚠️ | Opción 1 → pide motivo → `INASISTENCIA_JUSTIFICADA` + notifica coordinación | Cubre la excusa; no hay "recordatorio de actividades pendientes". |
| V-158 | ✅ | `operations.citacion` | — |
| V-159 | ✅ | Contextos Redis + actualización de incidentes | — |

### 6.5 Configuración operativa (V-160 – V-170)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-160 | ✅ | Tablas de config + endpoints | — |
| V-161 | ⚠️ | Jornadas/bloques/grupos/docentes/estudiantes/dispositivos configurables | Aulas y schedules no. |
| V-162 | ✅ | `groups_onboarding`, `school/onboarding`, `academic_year` | — |
| V-163 | ✅ | `horario` + `daily_schedule_config` | — |
| V-164 | ✅ | `expected_entry/exit_time`, `has_classes`, fusión/extensión | — |
| V-165 | ✅ | `/risk/policy` versionada | — |
| V-166 | ⚠️ | `risk_rules` frecuencias/ventanas | `risk_combination_rules` existe pero **desactivada** en `RiskEngineV3.php:304`. |
| V-167 | ❌ | — | TEACHER sin permisos de criterios. |
| V-168 | ✅ | Motor genérico + política por colegio | — |
| V-169 | ⚠️ | `rotates_classrooms` + bloques; salón fijo = no rotativo | Sin aulas poblados la representación es solo temporal. |
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
| V-183 | ⚠️ | `audit_trail` local con doc+evento+fecha; clasificación horaria en el evento | "Condiciones operativas" no se registran: el edge no sabe aula/bloque/grupo — solo franja horaria hardcodeada. |
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
| V-196 | ⚠️ | El evento lleva clasificación horaria (`INGRESO_PUNTUAL/TARDE/…`) | Las franjas están **hardcodeadas** en `checkLateStatus` (`main.cpp:524`) — el nodo no conoce config del colegio ni aula/bloque; un horario distinto clasifica mal en origen. |
| V-197 | ✅ | Payload `SYNC_ATTENDANCE` completo | — |
| V-198 | ✅ | Plantillas locales cifradas AES-GCM | — |
| V-199 | ✅ | Solo metadatos operativos viajan | — |
| V-200 | ✅ | Ninguna ruta envía `template_huella` | — |

**Bloque 4:** ✅ 42 · ⚠️ 6 · ❌ 2

---

## BLOQUE 5 — V-201 a V-250 (Cap. 7.2 fin, 7.3, 7.4.1–7.4.3 parcial)

### 7.2 fin (V-201 – V-217)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-201 | ✅ | `logEvent` antes de sync | — |
| V-202 | ✅ | `captured_at` viaja en payload | — |
| V-203 | ✅ | Cola + reintentos | — |
| V-204 | ❌ | HTTPS/MQTT funcionan sobre cualquier IP | **Sin gestión de módem/celular M2M** en el código — depende totalmente del SO/despliegue. |
| V-205 | ⚠️ | Intercambio automático sobre IP genérica | "Sin conexión convencional" solo si el hardware lo provee; el software no lo gestiona. |
| V-206 | ⚠️ | ídem | — |
| V-207 | ⚠️ | ídem | — |
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
| V-229 | ❌ | — | **Sin gestión de UPS/batería** en código. |
| V-230 | ❌ | — | Sin detección de corte ni operación en respaldo. |
| V-231 | ◻️ | — | Dimensionamiento energético = hardware. |
| V-232 | ⚠️ | Local+persistencia+sync diferida | Falta el pilar energético. |
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
| V-243 | ⚠️ | `template_huella` cifrado AES-256-GCM | `estudiantes` (doc, nombre, teléfono acudiente) y `audit_trail` (documentos+eventos) en **texto plano**. |
| V-244 | ⚠️ | Clave ligada a CPU serial protege la plantilla | El resto de la DB es legible extrayendo la SD. |
| V-245 | ⚠️ | ídem | PII y log de eventos expuestos ante sustracción. |
| V-246 | ✅ | Almacenamiento tratado como componente (key hw-bound, `mlock`, `OPENSSL_cleanse`) | — |

### 7.4.3 Tránsito (parcial) (V-247 – V-250)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-247 | ✅ | `VERIFYPEER/VERIFYHOST`; MQTT TLS opcional | — |
| V-248 | ✅ | AES-256-GCM aplicación | — |
| V-249 | ✅ | `encryption.cpp:183-277` | — |
| V-250 | ✅ | IV 12 B `RAND_bytes` | — |

**Bloque 5:** ✅ 39 · ⚠️ 7 · ❌ 3 · ◻️ 1

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
| V-272 | ⚠️ | Confinamiento✅, GCM+TLS✅, auth✅, RLS✅, cadena✅, rate-limit✅ | Almacenamiento local no-biométrico sin cifrar — una capa queda a medias. |
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

**Bloque 6:** ✅ 41 · ⚠️ 1 · ◻️ 8 · ❌ 0

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
| V-310 | ❌ | — | **Sin código de ventilador/PWM/temperatura** en el edge (grep `fan|thermal|temperature` sin resultados) — la ventilación activa controlada por software no existe. |
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
| V-326 | ❌ | Transporte agnóstico | Sin integración M2M (V-204). |
| V-327 | ✅ | Múltiples capas | — |
| V-328 | ⚠️ | TLS+GCM+auth+RLS | Almacenamiento local solo cifra plantilla. |
| V-329 | ✅ | RLS | — |
| V-330 | ✅ | `school_id` del registro, no declarado | — |
| V-331 | ✅ | Cola Redis | — |
| V-332 | ✅ | Incidentes/riesgo/notifs async | — |
| V-333 | ◻️ | — | Protección física del nodo. |
| V-334 | ◻️ | — | Ciclo de vida físico. |
| V-335 | ✅ | Compose único → distribuible | — |

### 8.1 Finalidad y datos personales (V-336 – V-345)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-336 | ✅ | Config por colegio define qué se trata | — |
| V-337 | ✅ | Parámetros institucionales | — |
| V-338 | ✅ | Config persistida aplicada por workers | — |
| V-339 | ✅ | Adaptación por configuración | — |
| V-340 | ⚠️ | Evento = estudiante+device+tipo+momento(+permiso) | Sin aula/bloque/materia. |
| V-341 | ✅ | Consultas siempre contextualizadas | — |
| V-342 | ⚠️ | Sistema orientado a custodia | `students` **no modela** consentimiento ni base jurídica por tratamiento. |
| V-343 | ✅ | Estructura completa de consulta | — |
| V-344 | ⚠️ | `DELETE /students/{id}` (supresión) + consulta (acceso) | Sin flujo ARCO/habeas data explícito (rectificación existe vía UPDATE; portabilidad no). |
| V-345 | ✅ | Actuaciones condicionadas a config/permisos | — |

### 8.2 Biometría (parcial) (V-346 – V-350)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-346 | ✅ | Solo FMD/template, nunca imagen | — |
| V-347 | ✅ | Sensor extrae características (FMD 4 capturas) | — |
| V-348 | ✅ | Representación matemática | — |
| V-349 | ✅ | `template_huella` local cifrado | — |
| V-350 | ✅ | Nunca viaja al central | — |

**Bloque 7:** ✅ 20 · ⚠️ 4 · ❌ 2 · ◻️ 24

---

## BLOQUE 8 — V-351 a V-400 (8.2 fin, 8.3, 8.4, 8.5 parcial)

### 8.2 fin (V-351 – V-358)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-351 | ✅ | La captura solo existe en memoria para el match | — |
| V-352 | ✅ | Post-ID viajan referencias | — |
| V-353 | ✅ | Central funciona sin recibir biometría | — |
| V-354 | ✅ | `template_huella` AES-256-GCM | — |
| V-355 | ⚠️ | Clave hw-bound: la plantilla no es accesible sin el nodo | El resto del SQLite (PII, eventos) sí es accesible leyendo el medio — la "vía ordinaria" existe para todo menos la plantilla. |
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
| V-377 | ⚠️ | El motor de riesgo es configurable | Algunas detecciones alertan siempre (EVASION_INTERNA, permiso vencido) — no hay opción "detectar sin alertar". |
| V-378 | ⚠️ | Frecuencias/ventanas configurables | Secuencias (combination rules) desactivadas. |
| V-379 | ❌ | — | Docente no configura condiciones de su actividad. |
| V-380 | ✅ | Políticas por escuela | — |
| V-381 | ✅ | Plantilla confinada al nodo | — |
| V-382 | ✅ | Central recibe resultado operacional | — |
| V-383 | ✅ | No hay tracking de desplazamientos | — |
| V-384 | ✅ | Identificación solo en nodos funcionales | — |
| V-385 | ✅ | Diseño custodia ≠ vigilancia indiscriminada | — |
| V-386 | ✅ | Continuidad sobre acontecimientos relevantes | — |
| V-387 | ✅ | Niveles de riesgo diferenciados | — |
| V-388 | ⚠️ | Umbrales configurables | Destinatario de cada actuación fijo en código. |

### 8.5 Seguridad (parcial) (V-389 – V-400)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-389 | ⚠️ | Plantilla cifrada en edge | Cifrado-at-rest de PostgreSQL no visible en código (depende de infra); PII local en claro. |
| V-390 | ✅ | TLS + GCM | — |
| V-391 | ✅ | RBAC + RLS | — |
| V-392 | ✅ | JWT + permisos | — |
| V-393 | ✅ | Operaciones por permiso/rol | — |
| V-394 | ⚠️ | Solo `template_huella` cifrado | — |
| V-395 | ✅ | TLS/HTTPS/MQTT-TLS | — |
| V-396 | ✅ | Tag GCM + nonce anti-replay | — |
| V-397 | ◻️ | — | Protección física de puntos: hardware. |
| V-398 | ◻️ | — | Estructura físicamente protegida: hardware. |
| V-399 | ✅ | Múltiples condiciones por dimensión | — |
| V-400 | ✅ | Defensa en profundidad | — |

**Bloque 8:** ✅ 41 · ⚠️ 6 · ❌ 1 · ◻️ 2

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
| V-406 | ⚠️ | `incidente` tiene `targets` configurable | Las condiciones que disparan WhatsApp (inasistencia, salida, citación) son fijas por flujo — la institución no las configura. |
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
| V-420 | ❌ | — | Sin respaldo energético gestionado (V-229). |
| V-421 | ❌ | — | Sin autonomía energética en software. |
| V-422 | ⚠️ | Lo escrito sobrevive apagón (WAL+EXTRA) | No hay "suspensión ordenada" al agotar batería — la propiedad de no-pérdida existe por persistencia, no por gestión. |
| V-423 | ✅ | Relación por estudiante/tiempo | — |
| V-424 | ✅ | Purga diferenciada (notifs 30d, particiones 24m, edge 30/90d) | — |
| V-425 | ✅ | Purgas implementadas | — |
| V-426 | ✅ | Particiones + storage central | — |
| V-427 | ⚠️ | Retenciones existen | **Hardcodeadas** (24 meses, 30 días) — no configurables por marco/institución. |

### 8.8 Automatización y responsabilidad (V-428 – V-440)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-428 | ✅ | Motor + detectores | — |
| V-429 | ✅ | Bajo config institucional | — |
| V-430 | ✅ | Decisiones en actores | — |
| V-431 | ✅ | Pipeline automático | — |
| V-432 | ✅ | Alertas no son conclusiones | — |
| V-433 | ✅ | Parámetros de aviso institucionales | — |
| V-434 | ⚠️ | Rectoría/coordinación definen criterios | Docente no. |
| V-435 | ✅ | Políticas por colegio | — |
| V-436 | ⚠️ | Frecuencias→alerta | Secuencias/combinaciones desactivadas (V-166). |
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
| V-450 | ⚠️ | Comunicaciones✅, acceso✅ | Almacenamiento local parcial. |

**Bloque 9:** ✅ 42 · ⚠️ 6 · ❌ 2

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
| V-461 | ⚠️ | Seguridad en comunicaciones y acceso | Almacenamiento local solo cifra plantilla. |
| V-462 | ⚠️ | Institución define umbrales | Docente excluido; routing fijo. |
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
| V-477 | ⚠️ | Identificación/autorización/modificación incorporadas | **Registro manual no existe** (H-26). |
| V-478 | ✅ | Secuencias por estudiante/tiempo | — |
| V-479 | ✅ | Dashboard + config vigente dinámica | — |
| V-480 | ⚠️ | Entradas tardías/salidas anticipadas/extensiones | Cambios de aula/reubicación sin modelo (sin schedules/classrooms). |
| V-481 | ✅ | Workers leen config vigente | — |
| V-482 | ✅ | `has_classes`/expected times evitan falsa anomalía | — |
| V-483 | ⚠️ | Estructura prevista vs realidad en dimensión temporal | Dimensión espacial ausente. |

### 9.2 Validación previa (V-484 – V-497)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-484 | ✅ | Dedup, checks de presencia/permiso/has_classes | — |
| V-485 | ✅ | Permiso activo antes de evasión; has_classes antes de ausencia | — |
| V-486 | ❌ | — | **Ningún detector consulta `edge_devices.last_ping`** — nodo caído → ausencias falsas. |
| V-487 | ⚠️ | z-score por estudiante (`/risk/anomaly`, RiskEngineV3:603-651) | Sin detección de anomalía **masiva/agregada** (N inasistencias simultáneas). |
| V-488 | ❌ | — | Sin detector de concentración anómala. |
| V-489 | ❌ | — | Incidentes se generan de inmediato; no existe estado "pendiente de contexto". |
| V-490 | ⚠️ | Modificaciones planificadas respetadas | Contingencias no previstas (nodo caído) no protegidas. |
| V-491 | ✅ | Timestamps consistentes | — |
| V-492 | ✅ | Secuencias por bloques | — |
| V-493 | ⚠️ | `checkNtpSync` detecta offset>60 s y lo loguea; `checkSystemClock` bloquea si año<2024; central valida `captured_at`±7d | La detección no reporta la magnitud ni marca el evento como "contexto temporal dudoso". |
| V-494 | ❌ | — | El offset solo queda en log local (`main.cpp:575`); el ping envía `timestamp` pero el central **no lo compara** con su hora (`devices.php:988-1047`). |
| V-495 | ⚠️ | NTP check automático | Con offset>60 s solo loguea "sync recommended" — no fuerza resincronización. |
| V-496 | ⚠️ | `is_online` calculado vía `last_ping` (`devices.php:755`) y `/admin/devices?health=1` | Sin alerta proactiva a técnico — hay que consultar para saberlo. |
| V-497 | ⚠️ | `g_clockValid` distingue reloj confiable/no confiable (bloquea) | Distinción binaria y local; el central no sabe que el nodo tiene reloj inválido. |

### 9.3 Conectividad (parcial) (V-498 – V-500)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-498 | ✅ | Identificación+persistencia offline | — |
| V-499 | ✅ | Cola local conserva acontecimientos | — |
| V-500 | ✅ | `synced=0` pendientes | — |

**Bloque 10:** ✅ 35 · ⚠️ 11 · ❌ 4

---

## BLOQUE 11 — V-501 a V-550 (9.3 fin, 9.4, 9.5, 9.6, 9.7 parcial)

### 9.3 fin (V-501 – V-511)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-501 | ✅ | `SyncWorker` reintenta en bucle | — |
| V-502 | ✅ | Sync automático | — |
| V-503 | ✅ | `captured_at` | — |
| V-504 | ⚠️ | SQLite crece hasta purga | **Sin monitor de espacio** — dimensionamiento asumido sin verificación. |
| V-505 | ✅ | `purgeOldAuditTrail(30d/90d)` + `VACUUM` | — |
| V-506 | ✅ | Purga periódica | — |
| V-507 | ❌ | — | Sin chequeo de espacio libre ni umbral. |
| V-508 | ❌ | — | No se reporta capacidad al central. |
| V-509 | ⚠️ | DLQ `synced=-1` persiste y purga a 90 d | Tras 5 intentos deja de reintentarse — pérdida silenciosa si la caída es prolongada. |
| V-510 | ✅ | Recuperación `.bak` + SQLite | — |
| V-511 | ✅ | Pendientes disponibles post-reinicio | — |

### 9.4 Eléctrico (V-512 – V-519)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-512 | ❌ | — | Sin código de UPS/batería. |
| V-513 | ❌ | — | Sin detección de corte ni transición. |
| V-514 | ❌ | — | Sin lógica de autonomía. |
| V-515 | ❌ | LEDs existen (`RealGpioManager` verde/rojo/buzzer) | **No se usan para estado energético**. |
| V-516 | ❌ | — | Sin distinción normal/respaldo/indisponible. |
| V-517 | ◻️ | — | Dimensionamiento hardware. |
| V-518 | ❌ | — | Sin shutdown ordenado por batería. |
| V-519 | ✅ | SQLite WAL+EXTRA: lo escrito sobrevive apagón | — |

### 9.5 Contingencia de nodo (V-520 – V-535)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-520 | ⚠️ | `last_ping` + `is_online` + `/admin/devices?health=1` | Detección pasiva por consulta; **sin proceso/incidente automático** por nodo caído. |
| V-521 | ❌ | — | Workers no verifican salud del nodo → ausencias/evasiones falsas. |
| V-522 | ❌ | — | Sin registro manual ni reasignación temporal de espacio. |
| V-523 | ⚠️ | Cambios de horario sí (`daily_schedule_config`) | Cambios de espacio no modelados. |
| V-524 | ❌ | — | Sin mecanismo de reubicación. |
| V-525 | ❌ | — | Sin lógica "nodo X caído → grupos/períodos afectados". |
| V-526 | ❌ | — | Sin registro manual. |
| V-527 | ❌ | — | ídem. |
| V-528 | ✅ | Motor sigue operando sobre incidentes | — |
| V-529 | ✅ | Eventos posteriores procesan normal | — |
| V-530 | ❌ | `EVASION_INTERNA` recibe `returned_to_class` | `INASISTENCIA` **no se reconcilia** con una identificación posterior (`worker_biometric.php:256-297`). |
| V-531 | ❌ | — | Sin alerta "ausente → apareció en otro espacio". |
| V-532 | ✅ | No inventa registros | — |
| V-533 | ✅ | ídem | — |
| V-534 | ⚠️ | Re-provisión de dispositivo posible | Sin procedimiento de recuperación de datos locales del nodo averiado. |
| V-535 | ⚠️ | ídem | — |

### 9.6 Operación manual (V-536 – V-545)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-536 | ❌ | — | Sin modalidad de registro manual. |
| V-537 | ❌ | — | Sin campo de excepción/no-participación en `students`. |
| V-538 | ❌ | — | **Una sola huella** (`huella_id` UNIQUE, `biometric_hash` sobrescrito). |
| V-539 | ❌ | — | Sin endpoint de registro manual de condición. |
| V-540 | ❌ | — | Sin suspensión del mecanismo automático por caso manual pendiente. |
| V-541 | ✅ | El motor nunca se desactiva | — |
| V-542 | ❌ | — | Sin info manual en contexto. |
| V-543 | ❌ | — | ídem. |
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

**Bloque 11:** ✅ 20 · ⚠️ 6 · ❌ 23 · ◻️ 1 — el bloque con más incumplimientos: la contingencia (9.4/9.5/9.6) es el punto más débil.

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
| V-555 | ❌ | Fallback = plantilla Twilio dentro de WhatsApp | **No hay SMS** ni canal alterno real. |
| V-556 | ⚠️ | Respuestas por WhatsApp vuelven al contexto | Sin canal alterno para responder si WhatsApp cae. |
| V-557 | ✅ | Respuestas continúan el flujo | — |
| V-558 | ✅ | `twilio_messages` es el registro | — |
| V-559 | ✅ | Contextos + actualización | — |

### 9.9 Configuración y control (V-560 – V-567)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-560 | ⚠️ | Entradas tardías/salidas anticipadas/extensiones/modificaciones | Cambios de espacio/reubicaciones no. |
| V-561 | ✅ | Config vigente interpreta nuevos acontecimientos | — |
| V-562 | ✅ | `daily_schedule_config` por `config_date` expira sola | — |
| V-563 | ✅ | `risk_policies`/`risk_rules` | — |
| V-564 | ⚠️ | Frecuencias | Secuencias desactivadas. |
| V-565 | ✅ | Diferencias planificadas no generan riesgo | — |
| V-566 | ✅ | `risk_justifications` + reinterpretación | — |
| V-567 | ❌ | — | Sin detector de concentración ni estado "pendiente". |

### 9.10 Recuperación (V-568 – V-577)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-568 | ✅ | Colas drenan; config diaria expira | — |
| V-569 | ✅ | Sync continúa | — |
| V-570 | ✅ | `daily_schedule_config` solo su fecha | — |
| V-571 | ✅ | Nada queda suspendido permanentemente | — |
| V-572 | ✅ | Dedup `event_fingerprint`+nonce+30s | — |
| V-573 | ✅ | Persistencia previa | — |
| V-574 | ⚠️ | Eventos tardíos conservan timestamp | `INASISTENCIA` ya creada no se reconcilia si el evento llega tarde (V-530). |
| V-575 | ✅ | Eventos de contingencia en la misma línea temporal | — |
| V-576 | ⚠️ | NTP/retry/watchdog auto-corrigen | Cobertura limitada a esos casos. |
| V-577 | ⚠️ | Device health consultable | Sin alerta/ticket técnico automático. |

### 9.11 Resiliencia (V-578 – V-584)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-578 | ✅ | Distribución edge/central | — |
| V-579 | ⚠️ | Persistencia✅, sync✅, config✅, capacidad de almacenamiento⚠️ | Respaldo energético❌, contingencias de nodo❌. |
| V-580 | ⚠️ | Edge conoce reloj/cola; central conoce `last_ping` | Sin modelo integral de "estado del nodo" reportado. |
| V-581 | ✅ | Conserva lo disponible | — |
| V-582 | ❌ | — | Ausencia de datos ≡ ausencia real para detectores. |
| V-583 | ✅ | Procesamiento continúa | — |
| V-584 | ⚠️ | Watchdog+reintentos+sync | Sin recuperación de nodo averiado ni fallback manual. |

### 10.1 Caracterización (V-585 – V-588)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-585 | ◻️ | — | Proceso de implementación. |
| V-586 | ⚠️ | `edge_devices.location`/`group_id` mapean puntos | Sin aula formal asociada al punto. |
| V-587 | ◻️ | — | Volumen físico. |
| V-588 | ◻️ | — | Alimentación eléctrica. |

### 10.2 Enrolamiento (V-589 – V-593)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-589 | ✅ | `POST /students` + `bulk-assign` + enrolamiento | — |
| V-590 | ✅ | `Enrollment.jsx` + `ENROLL_REQUEST`→edge (4 capturas) | — |
| V-591 | ❌ | — | **Una sola huella** por estudiante. |
| V-592 | ✅ | FMD almacenado local cifrado | — |
| V-593 | ❌ | — | Sin ruta de enrolamiento excepcional. |

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

**Bloque 12:** ✅ 30 · ⚠️ 10 · ❌ 5 · ◻️ 5

---

## BLOQUE 13 — V-601 a V-652 (10.5 fin, 10.6, 10.7, 11, 12) — FINAL

### 10.5 fin (V-601 – V-605)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-601 | ⚠️ | Grupos/jornadas/bloques configurables | Espacios (aulas) no. |
| V-602 | ✅ | Risk policies + daily config | — |
| V-603 | ❌ | — | Docente sin capacidad de definir condiciones de información. |
| V-604 | ✅ | Rectoría/coordinación definen métricas/avisos | — |
| V-605 | ✅ | `/risk/policy` + RiskEngineV3 | — |

### 10.6 Pruebas y marcha blanca (V-606 – V-615)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-606 | ✅ | `test/` PHPUnit: API, SQL (constraints/FK/índices/particiones/RLS/triggers/seeds), integración; `test/edge` Catch2 | — |
| V-607 | ❌ | — | Sin pruebas de carga/estrés en el repo. |
| V-608 | ❌ | — | ídem. |
| V-609 | ❌ | — | ídem. |
| V-610 | ⚠️ | Tests de edge cubren persistencia/cripto/watchdog por componente | Sin prueba integral "offline→cola→sync→dedup". |
| V-611 | ❌ | — | Sin mecanismo manual que probar ni tests de contingencia de nodo. |
| V-612 | ✅ | `SchemaPhpAlignmentTest`, `PlanComplianceTest`, `FullSystemAlignmentTest` en `test/runners/` | — |
| V-613 | ◻️ | — | Marcha blanca = proceso operativo. |
| V-614 | ⚠️ | Config contrastable vía dashboard/audit | Sin herramienta de "diff config vs realidad". |
| V-615 | ✅ | Toda la config es editable | — |

### 10.7 Puesta en operación (V-616 – V-617)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-616 | ◻️ | — | Transición = proceso (soportada técnicamente). |
| V-617 | ◻️ | — | Acompañamiento = proceso. |

### 11.1 Continuidad de configuración (V-618 – V-623)

| ID | Est. | Por qué cumple | Por qué no / limitación |
|----|------|----------------|--------------------------|
| V-618 | ⚠️ | Horarios/jornadas/grupos/métricas/avisos ajustables | Espacios no. |
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
| V-649 | ⚠️ | En operación normal la separación conocido/no conocido se mantiene | Nodo caído → "sin registro" se interpreta como hecho (ausencia) — la separación falla en contingencia técnica. |
| V-650 | ⚠️ | ídem | — |
| V-651 | ✅ | Ciclo de vida soportado | — |
| V-652 | ✅ | Extensible sin perder estructura | — |

**Bloque 13:** ✅ 38 · ⚠️ 6 · ❌ 5 · ◻️ 3

---

## RESUMEN GLOBAL DE LA AUDITORÍA (v2 — segunda pasada)

| Bloque | ✅ | ⚠️ | ❌ | ◻️ |
|--------|----|----|----|----|
| 1 (V-001–050) | 34 | 13 | 3 | 0 |
| 2 (V-051–100) | 35 | 10 | 5 | 0 |
| 3 (V-101–150) | 41 | 9 | 0 | 0 |
| 4 (V-151–200) | 42 | 6 | 2 | 0 |
| 5 (V-201–250) | 39 | 7 | 3 | 1 |
| 6 (V-251–300) | 41 | 1 | 0 | 8 |
| 7 (V-301–350) | 20 | 4 | 2 | 24 |
| 8 (V-351–400) | 41 | 6 | 1 | 2 |
| 9 (V-401–450) | 42 | 6 | 2 | 0 |
| 10 (V-451–500) | 35 | 11 | 4 | 0 |
| 11 (V-501–550) | 20 | 6 | 23 | 1 |
| 12 (V-551–600) | 30 | 10 | 5 | 5 |
| 13 (V-601–652) | 38 | 6 | 5 | 3 |
| **TOTAL** | **458** | **95** | **55** | **44** |

**Veredicto global:** ~70% cumple · ~15% parcial · ~8% incumple · ~7% no verificable en código.

**Diferencias v1→v2:** V-003 y V-038 bajaron a ⚠️ (rotación/vínculo de salida solo temporal, sin aula); V-183 bajó a ⚠️ (el edge no registra condiciones operativas); V-355 bajó a ⚠️ (solo la plantilla está cifrada); el ping del nodo envía timestamp que el central ignora (confirma V-494 ❌); `situacion_critica` notifica a RECTOR+COORDINATOR (V-068 ✅ confirmado); enrolamiento sí empuja datos al nodo vía comando (V-595 ✅ confirmado).

## Los 7 déficits estructurales dominantes

1. **Modelo espacial inexistente** — `classrooms`/`schedules` nunca se escriben; eventos sin `classroom_id`/`schedule_id`. Raíz de ~20 verificaciones fallidas/parciales.
2. **Contingencia manual ausente** — sin registro manual, sin exención biométrica, sin 2ª huella.
3. **Ceguera ante nodo caído** — ningún worker consulta salud del dispositivo; ausencias falsas masivas.
4. **Sin respaldo energético** — todo 9.4 (menos durabilidad) falla.
5. **Sin M2M celular** — conectividad agnóstica del SO.
6. **Docentes sin criterios propios** — configuración de análisis solo rectoría/coordinación.
7. **Actuaciones no configurables** — routing condición→actor fijo en código.

Detalle de correcciones priorizadas por dependencia en `PLAN_CORRECCIONES.md`.
