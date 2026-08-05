# NEXO — Funcionamiento del Sistema

Documento vivo que describe la lógica de negocio de NEXO Institucional.
Última actualización: 2026-08-04

---

## 1. Zona Horaria

**TODO el sistema usa `America/Bogota` (UTC-5, sin DST).**

- Backend: Todas las queries de "hoy" usan `(NOW() AT TIME ZONE 'America/Bogota')::date`.
  Nunca `CURRENT_DATE` ni `(CURRENT_TIMESTAMP)::date` (esos son UTC y causan bugs
  entre 19:00 y 23:59 Bogotá, donde la fecha UTC es "mañana").
- Frontend: `new Date()` con `toLocaleDateString('es-CO')` o `DateTimeZone('America/Bogota')`.
- Workers: `new DateTime('now', new DateTimeZone('America/Bogota'))`.

---

## 2. Onboarding de Horarios Institucionales

### Cuándo se activa
- Cuando un RECTOR o COORDINADOR entra al dashboard y `schools.onboarding_completed = FALSE`.
- El dashboard muestra **SOLO** el modal de onboarding (bloqueante, no se puede cerrar).
- No puede ver stats ni hacer operaciones hasta completarlo.

### Qué pide
1. **Jornada**: mañana, tarde, completa.
2. **Hora de entrada y salida** de la jornada.
3. **¿Rota de salones?**: Sí/No.
   - Si Sí: pide **N bloques horarios** (cuántas clases hay cada día + hora inicio/fin de cada una).
   - Si No: no pide bloques.
4. **Receso** (opcional pero recomendado): hora de inicio y fin del receso/almuerzo.

### Dónde se guarda
- `school_schedule_config` (1 fila por institución).
- `school_time_blocks` (N filas por institución, solo si rota salones).
- `schools.onboarding_completed = TRUE`.

### Quién puede editarlo después
- RECTOR y COORDINADOR, en **Perfil → Configuración de horarios** (sección debajo de contraseña).

---

## 3. Detección de Inasistencias (worker_absence_detector)

### Lógica
- Por cada grupo con `has_classes = TRUE` hoy:
  - Obtiene estudiantes activos asignados al grupo.
  - Obtiene estudiantes que marcaron `INGRESO_%` hoy.
  - Para cada estudiante sin ingreso, pasada la **hora límite** de su jornada:
    - Inserta `attendance_incidents` con `incident_type = 'INASISTENCIA'`.
    - Envía WhatsApp al acudiente.

### Hora límite
- Si `daily_schedule_config.expected_entry_time` existe para el grupo hoy: `entry_time + 10 min`.
- Si no:
  - mañana/completa: 07:10
  - tarde: 12:10

### Excepciones
- Si el estudiante tiene un **permiso activo** (`class_exit_authorizations.status = 'ACTIVE'` y `exit_time ≤ NOW ≤ return_time`), NO se marca inasistencia.
- Si `daily_schedule_config.has_classes = FALSE`, no se detectan ausentes.

### Ejecución
- Cron cada 1 minuto entre 06:30–08:00 (mañana) y 11:30–13:00 (tarde).
- O daemon con loop cada 60s.

---

## 4. Detección de Evasión (worker_evasion_detector)

### Concepto
Evasión = el estudiante está en la institución pero salió del salón y no volvió
dentro del plazo, o no asistió a la siguiente clase (si rota salones).

### Modo 1: Colegio que NO rota de salones

**Flujo del estudiante:**
1. Entra a la institución → marca huella → `INGRESO_INSTITUCION`.
2. Si marca huella nuevamente → se interpreta como **SALIDA** (baño, permiso, etc).
3. Si marca de nuevo → **REGRESO**.

**Reglas de alerta:**
- **Sin permiso**: si salió y no ha vuelto en **15 minutos** → alerta `EVASION_INTERNA`.
- **Con permiso**: la alerta se activa **5 minutos después de que expire el permiso**
  (`return_time + 5 min`).
- Si regresa antes del plazo → se considera **ida al baño** (no genera alerta).
- **5 minutos antes de `exit_time`**: es válido marcar salida final. Este evento
  resta del conteo de presentes y **desactiva** el monitoreo de evasión para ese estudiante.

**Notificaciones:**
- Al **profesor responsable** del momento (según `schedules` del día/hora actual).
- Al **coordinador**.
- **NO al rector** (evasión interna no llega a rector).

### Modo 2: Colegio que SÍ rota de salones

**Flujo del estudiante:**
1. Marca huella al **llegar a cada clase** (no al salir).
2. Entre el fin de una clase y el inicio de la siguiente hay un **margen de 10 minutos**.
3. Si no marca ingreso en la siguiente clase dentro de esos 10 minutos → `EVASION_INTERNA`.

**Reglas adicionales (igual que modo 1):**
- Permiso activo excluye de evasión.
- Receso: 10 min después de `recess_end_time`, si no regresó y no tiene permiso → alerta.

### Receso (ambos modos)
- Durante el receso: NO se detecta evasión.
- **10 minutos después de `recess_end_time`**: si un estudiante que estaba presente
  antes del receso no marcó ingreso después del receso y no tiene permiso → alerta `EVASION_INTERNA`.

### Validación de permisos con huella
- Cuando un estudiante con permiso pone su huella al salir, se cruzan los datos:
  - El permiso queda **validado** (status sigue ACTIVE).
  - Empieza el conteo de expiración del permiso.
- Si el estudiante pone huella antes de tener permiso (salió sin permiso), se aplica
  la regla de 15 minutos.

### Ejecución
- Cron cada 2 minutos durante la jornada escolar.
- O daemon con loop cada 120s.
- No procesa si `onboarding_completed = FALSE` o si no hay `entry_time`/`exit_time`.

---

## 5. Operaciones y Validación de Presencia

### Operaciones que requieren estudiante PRESENTE
- `permiso` (generar permiso)
- `autorizar_salida` (autorizar salida)
- `pedagogica` (salida pedagógica)
- `horario` (cambio de horario)
- `incidente` (reportar incidente)

**Validación:** `is_student_present_today(school_id, student_id)` verifica si hay
un evento `INGRESO_%` en `biometric_events` hoy (Bogotá). Si no, devuelve error 422.

### Operaciones que NO requieren presencia
- `seguimiento` (solicitar seguimiento) — puede hacerse aunque el estudiante no esté.
- `citacion` (citar acudiente) — puede hacerse aunque el estudiante no esté.
- `sos` / `situacion_critica` — no dependen de un estudiante.
- `solicitud` — mensaje interno, no depende de estudiante.
- `daño` — reporte de daño físico, no depende de estudiante.

### Permisos por rol (operations.*)

| Operación | RECTOR | COORDINADOR | DOCENTE | PSICORIENTADOR | SECRETARIA | PORTERO | AUXILIAR |
|-----------|--------|-------------|---------|----------------|------------|---------|----------|
| citacion | ✓ | ✓ | ✓ | ✓ | — | — | — |
| seguimiento | ✓ | ✓ | ✓ | ✓ | — | — | — |
| permiso | ✓ | ✓ | ✓ | — | — | — | — |
| autorizar_salida | ✓ | ✓ | — | — | — | — | — |
| salida_pedagogica | ✓ | ✓ | — | — | — | — | — |
| cambio_horario | ✓ | ✓ | — | — | — | — | — |
| situacion_critica | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| incidente | — | — | ✓ | ✓ | — | — | — |
| daño | — | — | — | — | — | ✓ | ✓ |
| solicitud | — | — | ✓ | — | ✓ | — | ✓ |

### Twilio — Mensajes con rol+nombre
- Las citaciones incluyen `Enviado por: {Rol} {Nombre}` (ej: "Enviado por: Coordinador Juan Pérez").
- Los seguimientos incluyen el rol del remitente en las notificaciones internas.

---

## 6. Permisos (class_exit_authorizations)

### Validación al crear
1. `student_id` obligatorio.
2. `reason` obligatorio.
3. `timeStart` y `timeEnd` obligatorios (formato HH:MM).
4. Estudiante debe existir, estar activo y pertenecer a la escuela.
5. `timeEnd` (retorno) debe ser una hora **futura**.
6. Estudiante debe estar **presente** (validación de presencia).

### Estados
- `ACTIVE`: permiso vigente (`exit_time ≤ NOW ≤ return_time`).
- `EXPIRED`: expiró sin retorno (NOW > return_time + 5 min).
- `COMPLETED`: el estudiante regresó (marcó huella de ingreso después del permiso).
- `CANCELLED`: cancelado manualmente.

### Interacción con evasión
- Mientras un permiso esté `ACTIVE`, el worker de evasión NO marca al estudiante.
- 5 minutos después de `return_time`, si no regresó, se activa alerta de evasión.

---

## 7. Novedades / Event Feed (dashboard/events)

### Qué se muestra (RECTOR/COORDINADOR)
**SOLO** novedades específicas:
- `SOS` (alertas SOS)
- `SITUACION_CRITICA` (situaciones críticas)
- `INCIDENTE` (reportes de incidentes)
- `DAÑO` (reportes de daño)
- `SEGUIMIENTO` (solicitudes de seguimiento)

### Qué NO se muestra
- `PERMISO` — no es novedad, es operación rutinaria.
- `AUTORIZAR_SALIDA` — ídem.
- `INASISTENCIA` — se ve en las StatCards, no en novedades.
- `HORARIO` — cambio de horario, no es novedad.
- `PEDAGOGICA` — salida pedagógica, no es novedad.

### Deduplicación
- Si hay múltiples eventos del mismo tipo para el mismo estudiante en el día,
  solo se muestra el más reciente (`DISTINCT ON (command_type, student_id)`).

### Barra azul vertical
- La barra azul vertical con "Novedades" es un **indicador visual permanente**.
- Se muestra SIEMPRE, haya o no novedades.
- Cuando no hay novedades: muestra "No hay novedades para mostrar."
- Cuando hay novedades: muestra la lista de eventos.

---

## 8. SOS vs Situación Crítica

- **SOS**: botón eliminado del frontend. El comando `sos` sigue existiendo en el backend
  para compatibilidad con datos históricos.
- **Situación Crítica**: comando único que reemplaza a SOS. Disponible para todos los roles.
  - Notifica a RECTOR y COORDINADOR vía WhatsApp + notificación interna.
  - Se registra en `attendance_incidents` con `incident_type = 'SITUACION_CRITICA'`.
  - Aparece en novedades.

---

## 9. Lector Biométrico

### Eventos
- `INGRESO_INSTITUCION`: entrada a la institución.
- `INGRESO_AULA`: entrada a un aula (colegios que rotan).
- `SALIDA_AULA`: salida de un aula.
- `SALIDA_INSTITUCION`: salida final de la institución.
- `INGRESO_BAÑO` / `SALIDA_BAÑO`: idas al baño.

### Frontend
- Los eventos biométricos se muestran humanizados (no raw `group: null`).
- Si un estudiante no tiene grupo, se omite el campo (no se muestra "null").
- Se usa `formatGroupName()` para formatear nombres de grupo.

---

## 10. Categorías del Dashboard

| Categoría | incident_type / fuente | Color | Icono |
|-----------|------------------------|-------|-------|
| Presentes | `biometric_events.INGRESO_%` | accent (azul) | Users |
| Inasistentes | `attendance_incidents.INASISTENCIA` | accent (azul) | UserMinus |
| Llegadas tarde | `attendance_incidents.LATE_ARRIVAL` | warning (naranja) | Clock |
| Alertas | `SOS + LATE_ARRIVAL + EARLY_EXIT + EVASION_INTERNA + ...` | danger (rojo) | AlertTriangle |
| Permisos | `attendance_incidents.PERMISO + AUTORIZAR_SALIDA` | success (verde) | Activity |

### Bordes de cards
- El borde de cada card debe ser del **mismo color que el icono** (versión oscura).
- Se maneja via el prop `tone` del componente `Card` (`warning`, `danger`, `success`, `accent`).
- **No** se debe sobrescribir el borde con `className` explícito.

---

## 11. Tablas Principales

| Tabla | Propósito |
|-------|-----------|
| `schools` | Instituciones educativas (+ `onboarding_completed`) |
| `school_schedule_config` | Config horarios institución (jornada, entrada, salida, receso, rota salones) |
| `school_time_blocks` | Bloques horarios (solo si rota salones) |
| `students` | Estudiantes (+ `work_shift`: mañana/tarde/completa) |
| `academic_groups` | Grupos académicos |
| `schedules` | Horarios de clase por grupo/profesor/materia/bloque/día |
| `daily_schedule_config` | Config diaria por grupo (has_classes, entry_time, exit_time) |
| `biometric_events` | Eventos de huella (ingresos, salidas) |
| `attendance_incidents` | Incidentes (inasistencia, evasión, permiso, late, SOS, etc.) |
| `class_exit_authorizations` | Permisos de salida de clase (+ `status`) |
| `school_exit_authorizations` | Autorizaciones de salida de institución |
| `user_commands` | Log de comandos ejecutados (event feed) |
| `notifications` | Notificaciones internas a usuarios |
| `sos_alerts` | Alertas SOS históricas |

---

## 12. RLS (Row Level Security)

- Todas las tablas de datos tienen RLS basado en `school_id = get_current_school_id()`.
- `get_current_school_id()` lee el setting `app.current_school_id` (seteado por `_auth_middleware.php`).
- Se usa `set_config('app.current_school_id', ..., true)` (transaction-level) en lugar de
  `SET LOCAL` porque `current_role` es palabra reservada de PostgreSQL.
- Workers setean `app.current_role = 'SYSTEM_WORKER'`.

---

## 13. Integraciones Completadas (Iteración 2)

1. **Marcar permisos como COMPLETED/EXPIRED automáticamente**: `worker_permission_status.php`
   ejecuta cada 1 minuto. Si hay un `INGRESO_%` después de `exit_time` → `COMPLETED`.
   Si `NOW() > return_time + 5 min` sin retorno → `EXPIRED`.

2. **Validación de permiso con huella**: `worker_biometric.php` cruza cada evento
   biométrico con `class_exit_authorizations` ACTIVE. Si hay permiso activo, se
   enriquece `metadata_json` del evento con `permiso_id`, `exit_time`, `return_time`,
   `reason`, y `permiso_event_role` (`RETURN` o `EXIT_WITH_PERMISSION`).

3. **Salida final (no rota salones)**: `dashboard.php` ahora resta del conteo de
   presentes a los estudiantes que tienen un evento `SALIDA_%` después de su último
   `INGRESO_%`. Si existe `school_schedule_config.exit_time`, solo se consideran
   salidas finales las que ocurren después de `exit_time - 5 min`.

4. **Deduplicación de eventos biométricos**: `worker_biometric.php` descarta eventos
   duplicados del mismo estudiante dentro de una ventana configurable
   (`BIOMETRIC_DEDUP_WINDOW_SECONDS`, default 30s). Solo deduplica si el tipo es
   el mismo; si el tipo es diferente (INGRESO → SALIDA), es un cambio legítimo.

5. **EVASION_INTERNA en novedades**: `dashboard.php` endpoint `/dashboard/events`
   ahora incluye evasiones desde `attendance_incidents` vía `UNION ALL`, con
   `issuer_role = 'SISTEMA'` y `command_type = 'EVASION_INTERNA'`.

6. **Worker de ausentes verifica permisos activos**: `worker_absence_detector.php`
   ahora consulta `class_exit_authorizations` con `status='ACTIVE'` antes de marcar
   inasistencia. Si el estudiante tiene permiso activo, NO se marca inasistencia.

7. **Tracking valida existencia del estudiante**: `tracking.php` endpoint
   `/tracking/start` ahora valida que el estudiante exista, esté activo y pertenezca
   a la escuela antes de iniciar el seguimiento.

---

## 14. Autenticación y Sesiones

### Login
- `POST /auth/login`: valida email + password, emite JWT en cookie HttpOnly.
- Si `LOGIN_2FA_ENABLED=true` y el teléfono está verificado: solicita 2FA vía WhatsApp.
- Rate limiting: 8 intentos por IP+email en ventana de 900s. Bloqueo temporal.
- `password_needs_rehash`: si el hash está en formato antiguo, se re-hashea automáticamente.

### 2FA
- `POST /auth/verify-2fa`: valida código de 6 dígitos enviado por WhatsApp.
- Código válido por 5 minutos. Un solo uso.
- Fallback: si Redis/Twilio no está disponible, se envía vía `sendTwilioDirect`.

### JWT
- Cookie HttpOnly + Secure + SameSite=Lax.
- Refresh token en `user_sessions` con expiración.
- Blocklist en Redis `jwt:blocklist:<jti>` para revocación inmediata.
- Al activar pánico: todas las sesiones JWT de la escuela se invalidan.

### Logout
- `POST /auth/logout`: revoca el JWT actual (blocklist) y limpia cookie.

---

## 15. Botón de Pánico (security_panic)

### Quién puede activarlo
- Solo RECTOR y COORDINADOR.

### Qué hace
1. Desactiva todos los `edge_devices` de la escuela (`active = FALSE`).
2. Registra evento en `school_panic_events`.
3. Cachea timestamp en Redis `panic:school:<id>` (TTL 24h) para invalidación rápida JWT.
4. Notifica a todos los RECTOR y COORDINATOR de la escuela vía notificación interna.
5. Registra en auditoría global.

### Consecuencias
- Todas las sesiones JWT de la escuela se invalidan (todos los usuarios deben volver a login).
- Todos los lectores biométricos se desactivan (no pueden enviar eventos).
- El sistema queda en modo "emergencia" hasta que un administrador reactive los dispositivos.

---

## 16. Dispositivos EDGE (Lectores Biométricos)

### Registro
- `POST /devices`: RECTOR registra un dispositivo, genera token raw (entregado al admin).
- El edge usa `X-Device-Token` para autenticar.

### Flujo de ingestión
1. Edge envía payload cifrado (AES-256-GCM) a `api.php`.
2. `api.php` valida device token, nonce (anti-replay), timestamp (±7 días).
3. Si todo OK: encola en Redis `queue:biometric_ingest`, responde 202 Accepted.
4. `worker_biometric.php` consume la cola:
   - `SYNC_ATTENDANCE`: INSERT en `biometric_events` + cruce con permisos + deduplicación.
   - `REGISTER_STUDENT`: INSERT/UPDATE en `students` + acudiente.
   - `DELETE_STUDENT`: UPDATE `students SET active=FALSE`.

### Comandos a dispositivos
- `POST /devices/command/{id}`: envía comando vía MQTT (fallback Redis `device:{id}:commands`).
- `GET /devices/commands`: edge hace polling de comandos pendientes.
- `POST /devices/ping`: heartbeat del edge.

### Seguridad
- Nonce anti-replay: cada nonce solo puede usarse una vez (7 días TTL en Redis).
- Timestamp: rechaza paquetes con más de 7 días de diferencia.
- Token: `password_verify` contra `token_hash` en DB.
- RLS: el edge se autentica con `app.current_role = 'EDGE_NODE'`.

---

## 17. Métricas de Comportamiento y Riesgo

### Cálculo
- `fn_calculate_student_risk(student_id, school_id, window_days=30)`:
  - `late_count`: incidentes `LATE_ARRIVAL` en los últimos 30 días.
  - `absence_count`: incidentes `INASISTENCIA` o `UNAUTHORIZED_ABSENCE` en 30 días.
  - `total_events`: eventos biométricos en 30 días.
  - `risk_score = min(100, late*5 + absence*15 + max(0, (total-20)*0.5))`.
  - `risk_level`: CRITICAL (≥80), HIGH (≥60), MEDIUM (≥30), LOW (<30).
  - Si `risk_score ≥ 70`: inserta `RISK_ALERT_{level}` en `attendance_incidents` (máx 1 cada 7 días).

### Recalculación
- `POST /admin/recalc-risk`: RECTOR/COORDINATOR recalcula métricas de toda la escuela.
- `fn_recalculate_school_metrics(school_id)`: recalcula para todos los estudiantes activos.

### Consulta
- `GET /behavior/risk`: retorna estudiantes con `risk_level IN ('HIGH', 'CRITICAL')`.
- Si no hay métricas calculadas, sugiere llamar a `/admin/recalc-risk`.

### Nota sobre EVASION_INTERNA
- `fn_calculate_student_risk` **NO cuenta** `EVASION_INTERNA` en el `risk_score`.
- Esto es una decisión de diseño: la evasión es un evento puntual, no un patrón de comportamiento.
- Si se desea incluir evasión en el risk_score, modificar la función para añadir:
  `+ (evasion_count * 10.0)` al score.

---

## 18. Seguimientos (student_tracking)

### Ciclo de vida
1. **Solicitud**: RECTOR/COORDINADOR ejecuta operación `seguimiento` → inserta notificación
   a PSICORIENTADOR con `action='iniciar_seguimiento'`.
2. **Inicio**: PSICORIENTADOR ve la notificación, entra a `/casos`, acepta el caso.
   - `POST /tracking/start`: valida estudiante existe, crea `student_tracking` con
     `status='en proceso'`, busca motivo en notificación, añade nota inicial,
     elimina notificación de solicitud.
3. **Notas**: `POST /tracking/notes`: añade nota y opcionalmente cambia `status`.
4. **Cierre**: `POST /tracking/notes` con `status='cerrado'` o `'completado'`.
5. **Detalle**: `GET /tracking/details`: retorna tracking + notas ordenadas por fecha.

### Estados
- `en proceso`: seguimiento activo.
- `cerrado`: cerrado por el orientador.
- `completado`: resuelto satisfactoriamente.

### Permisos
- `tracking.manage`: RECTOR, COORDINADOR, PSICORIENTADOR.
- Solo RECTOR y COORDINADOR pueden **solicitar** seguimientos (operación `seguimiento`).
- PSICORIENTADOR los recibe y gestiona.

---

## 19. Consultas y Reportes

### Motor de consultas
- `POST /consultations/query`: endpoint genérico que retorna datos por módulo.
- Filtros: `module`, `group_name`, `student_id`, `from_date`, `to_date`, `grade`.
- Docentes solo ven estudiantes de sus grupos (validación por `schedules`).

### Módulos disponibles
- `group_students`: estudiantes de un grupo.
- `late_arrivals`: llegadas tarde.
- `absences`: inasistencias.
- `active_permissions`: permisos activos.
- `attendance_history`: historial de asistencia biométrica.
- `incidents`: incidentes.
- `student_tracking_active`: seguimientos activos.
- `student_tracking_completed`: seguimientos completados.
- `sent_messages`: mensajes Twilio enviados.
- `internal_messages`: mensajes internos.
- `biometric_spam`: eventos biométricos sospechosos (spam).
- `issued_permissions`: permisos emitidos.
- `school_exits`: salidas de institución.
- `pedagogical_trips`: salidas pedagógicas.
- `evasions`: evasiones internas.
- `all_groups`, `all_teachers`, `all_students`, `all_guardians`: directorios.
- `institutional_metrics`: métricas consolidadas.
- `staff`: personal.

### Reportes
- `POST /reports/preview`: previsualización de eventos biométricos.
- `GET /audit/attendance/evasion`: reporte histórico de evasiones.
- `GET /audit/logs`: logs de auditoría global (RECTOR/COORDINATOR).
- `GET /audit/integrity`: verificación de cadena de auditoría (HMAC chain).

### Exportaciones
- `report_exports`: registro de exportaciones generadas.
- Formatos: CSV, PDF (según implementación).

---

## 20. Auditoría y Cadena de Confianza

### Cadena HMAC
- `global_audit_logs`: cada log tiene `prev_audit_id` y `chain_hash`.
- `chain_hash = HMAC-SHA256(prev_hash | school_id | actor | event | details | ip | timestamp, secret)`.
- `fn_validate_audit_chain(school_id)`: verifica que la cadena no esté rota.
- Si un log fue modificado/eliminado, la cadena se rompe y se detecta.

### Tipos de auditoría
- `student_record_audit`: cambios en registros de estudiantes.
- `global_audit_logs`: acciones de usuarios (login, operaciones, configuración).
- `securityLog()`: helper PHP que loguea a stderr + DB.

### Verificación
- `GET /audit/integrity`: RECTOR/COORDINATOR pueden verificar la cadena.
- Retorna `status='ok'` o `status='compromised'` con `broken_at_audit_id`.

---

## 21. Telemetría

### Eventos
- `JS_ERROR`: errores JavaScript del frontend.
- `API_LATENCY`: latencia de llamadas a la API.
- `BIOMETRIC_LATENCY`: latencia de eventos biométricos.
- `APP_PING`: heartbeat de la app (cada 30s).
- `RENDER_SLOW`: renders lentos de React.

### Plataformas
- `web`, `desktop`, `android`, `ios`.

### Retención
- Los datos se acumulan en `system_telemetry` sin purga automática.
- Recomendado: purge cada 90 días vía script SQL.

---

## 22. Notificaciones Internas

### Creación
- `POST /notifications`: crea notificación para un usuario.
- `POST /notifications/clear`: elimina todas las notificaciones del usuario autenticado.

### Tipos
- `INFO`: información general (permisos, cambios de horario).
- `ALERT`: alertas (SOS, evasión, incidentes).
- `CRITICAL`: críticas (pánico, seguridad).

### Limpieza
- Manual: el usuario puede limpiar sus notificaciones desde el frontend.
- `tracking/start`: elimina notificaciones de seguimiento al aceptar el caso.
- **No hay purge automático** por antigüedad. Recomendado: ejecutar
  `purge_notification_garbage.sql` periódicamente.

### Conteo
- El frontend hace polling de `notificationsApi.getAll()` y emite evento
  `nexo:notif-count` para actualizar el badge del sidebar.

---

## 23. Mensajería Twilio (WhatsApp)

### Flujo
1. `operations.php` construye el mensaje (con rol+nombre del remitente).
2. Encola en Redis `queue:twilio` con `to`, `message`, `school_id`, `student_id`,
   `guardian_id`, `sender_user_id`, `type_code`.
3. `worker_twilio.php` consume la cola:
   - Envía vía API de Twilio.
   - Registra en `twilio_messages` con `delivery_status`.
   - Si falla: reintenta 3 veces, luego marca `FAILED_PERMANENT`.
4. `POST /webhooks/twilio/inbound`: procesa respuestas de acudientes (1=confirmar, 2=reagendar).
5. Webhook de delivery: actualiza `delivery_status` (SENT, DELIVERED, READ, FAILED).

### Tipos de mensaje
- `CITACION`: citación a acudiente.
- `SEGUIMIENTO`: notificación de seguimiento.
- `INASISTENCIA`: notificación de inasistencia.
- `PERMISO`: notificación de permiso.
- `SOS`: alerta SOS.
- `SITUACION_CRITICA`: situación crítica.
- `LOGIN_2FA`: código de doble factor.

### Normalización de teléfonos
- `guardians.whatsapp_phone_normalized`: trigger `fn_guardians_normalize_phone`
  normaliza a formato internacional (+57...).

---

## 24. Estudiantes y Matrícula

### Creación
- `POST /students`: SECRETARY/RECTOR/COORDINATOR crea estudiante (upsert por
  `school_id + document_number`).
- Asigna a grupo académico automáticamente.
- `work_shift`: mañana, tarde, completa (default: mañana).

### Listado
- `GET /students`: paginado, con filtros por búsqueda, grupo y cursor.
- Requiere autenticación.

### Enrolamiento
- `POST /students` con `biometric_hash`: enrola la huella del estudiante.
- El edge usa el `biometric_hash` para identificar al estudiante al poner la huella.

### Soft delete
- `DELETE /students` o `DELETE_STUDENT` desde edge: `UPDATE students SET active=FALSE, deleted_at=NOW()`.
- No se elimina físicamente (preservación de datos).

---

## 25. Grupos y Horarios

### Grupos académicos
- `academic_groups`: grupos por escuela (ej: "6A", "7B", "11C").
- `grade_level`: grado (6, 7, 8, 9, 10, 11).
- `academic_year`: año escolar.

### Asignación de estudiantes
- `student_group_assignments`: relaciona estudiantes con grupos.
- `active = TRUE`: asignación vigente.
- Un estudiante puede tener historial de asignaciones (cambio de grupo).

### Horarios de clase
- `schedules`: por grupo, profesor, aula, materia, día de la semana, bloque, hora.
- `day_of_week`: 1=Lunes, 7=Domingo.
- `block_number`: número de bloque (1, 2, 3, ...).

### Configuración diaria
- `daily_schedule_config`: por grupo y fecha específica.
- `has_classes`: si hay clases ese día (FALSE = festivo/salida).
- `expected_entry_time`: hora esperada de entrada (para detección de tardanzas).

---

## 26. Roles y Permisos (Sistema Completo)

### Roles
| Rol | Descripción |
|-----|-------------|
| RECTOR | Director de la institución. Acceso total. |
| COORDINATOR | Coordinador académico. Gestiona operaciones, casos, configuración. |
| TEACHER | Docente. Ve sus grupos, hace citaciones, seguimientos, incidentes. |
| COUNSELOR | Psicorientador. Gestiona seguimientos, ve consultas. |
| SECRETARY | Secretaría. Matricula estudiantes, hace consultas. |
| SECURITY | Seguridad. Ve estudiantes, consultas. |
| AUXILIARY | Auxiliar. Ve dashboard, hace solicitudes. |

### Permisos (operations.*)
- `operations.citacion`: RECTOR, COORDINATOR, TEACHER, COUNSELOR
- `operations.seguimiento`: RECTOR, COORDINATOR, TEACHER
- `operations.permiso`: RECTOR, COORDINATOR, TEACHER
- `operations.autorizar_salida`: RECTOR, COORDINATOR
- `operations.salida_pedagogica`: RECTOR, COORDINATOR
- `operations.cambio_horario`: RECTOR, COORDINATOR
- `operations.situacion_critica`: TODOS
- `operations.incidente`: TEACHER, COUNSELOR
- `operations.daño`: SECURITY, AUXILIARY
- `operations.solicitud`: TEACHER, SECRETARY, AUXILIARY

### Permisos (dashboard.*)
- `dashboard.global_view`: RECTOR, COORDINATOR, SECRETARY, AUXILIARY
- `dashboard.teacher_view`: TEACHER, COUNSELOR

### Permisos (students.*)
- `students.create`: SECRETARY, RECTOR, COORDINATOR
- `students.view`: TODOS (menos SECURITY que solo view)

### Permisos (consultations.*)
- `consultations.global_view`: RECTOR, COORDINATOR, SECRETARY
- `consultations.teacher_view`: TEACHER, COUNSELOR

### Permisos (tracking.*)
- `tracking.manage`: RECTOR, COORDINATOR, COUNSELOR

### Permisos (behavior.*)
- `behavior.view_risk`: RECTOR, COORDINATOR, TEACHER, COUNSELOR

### Permisos (reports.*)
- `reports.preview`: RECTOR, COORDINATOR, SECRETARY, TEACHER, SECURITY
- `reports.export`: RECTOR, COORDINATOR, SECRETARY

---

## 27. RLS (Row Level Security) — Detalle

### Mecanismo
- Todas las tablas de datos tienen RLS basado en `school_id = get_current_school_id()`.
- `get_current_school_id()`: lee `current_setting('app.current_school_id', true)`.
- `_auth_middleware.php` setea `app.current_school_id` al validar el JWT.
- Workers: setean `app.current_school_id` y `app.current_role = 'SYSTEM_WORKER'`.
- Edge: setea `app.current_school_id` y `app.current_role = 'EDGE_NODE'`.

### PgBouncer compatibilidad
- Se usa `set_config('app.current_school_id', ..., true)` (transaction-level)
  en lugar de `SET LOCAL` (que no funciona con PgBouncer en modo transaction).
- `current_role` es palabra reservada de PostgreSQL, por eso se usa `app.current_role`.

### Tablas con RLS
- `students`, `users`, `biometric_events`, `attendance_incidents`, `class_exit_authorizations`,
  `school_exit_authorizations`, `pedagogical_trip_authorizations`, `notifications`,
  `user_commands`, `sos_alerts`, `security_incidents`, `student_tracking`,
  `student_tracking_notes`, `twilio_messages`, `internal_messages`, `edge_devices`,
  `academic_groups`, `schedules`, `daily_schedule_config`, `student_behavior_metrics`,
  `school_schedule_config`, `school_time_blocks`, `report_exports`, `staff_records`.

### Tablas SIN RLS (globales)
- `schools`, `roles`, `permissions`, `role_permissions`, `departments`, `municipalities`,
  `subjects`, `schema_migrations`, `rate_limits`, `jwt_blocklist`, `verification_codes`,
  `system_telemetry`, `contact_leads`, `twilio_message_types`.

---

## 28. Particionamiento de Tablas

Las siguientes tablas están particionadas por rango (RANGE) en una columna de timestamp:
- `biometric_events` (por `event_timestamp`)
- `attendance_incidents` (por `detected_at`)
- `user_commands` (por `executed_at`)
- `sos_alerts` (por `emitted_at`)
- `internal_messages` (por `sent_at`)
- `twilio_messages` (por `sent_at`)
- `student_record_audit` (por `performed_at`)
- `global_audit_logs` (por `created_at`)

### Mantenimiento
- Las particiones se crean mensualmente.
- Script de mantenimiento: `cleanup_maintenance.sql`.
- Particiones antiguas pueden archivarse o eliminarse.

---

## 29. Workers del Sistema

| Worker | Frecuencia | Responsabilidad |
|--------|------------|-----------------|
| `worker_biometric.php` | Continuo (cola Redis) | Procesa eventos biométricos, deduplicación, cruce con permisos |
| `worker_absence_detector.php` | 1 min | Detecta inasistencias (verifica permisos activos) |
| `worker_evasion_detector.php` | 2 min | Detecta evasión interna (rota/no-rota, recesos, permisos) |
| `worker_permission_status.php` | 1 min | Marca permisos COMPLETED/EXPIRED automáticamente |
| `worker_twilio.php` | Continuo (cola Redis) | Envía mensajes WhatsApp |
| `worker_audit.php` | Continuo (cola Redis) | Procesa logs de auditoría (opcional) |

### Modos de ejecución
- **Cron**: una ejecución y exit (para cron jobs).
- **Daemon**: loop continuo con sleep (para procesos persistentes).
- Configurable vía variable de entorno (ej: `EVASION_DETECTOR_MODE=daemon`).

### Heartbeat
- Cada worker escribe `worker:{name}:last_heartbeat` en Redis cada 30s.
- `GET /health` verifica los heartbeats para reportar salud del sistema.

---

## 30. Fallas Lógicas Encontradas y Corregidas

### 1. Worker de ausentes no verificaba permisos activos
- **Problema**: `worker_absence_detector.php` marcaba inasistencia aunque el estudiante
  tuviera un permiso activo, enviando notificación al acudiente injustificadamente.
- **Corrección**: Añadida query a `class_exit_authorizations` con `status='ACTIVE'`
  antes de marcar inasistencia.

### 2. Tracking no validaba existencia del estudiante
- **Problema**: `tracking.php` `/tracking/start` no verificaba que el `student_id`
  existiera, permitía crear seguimientos para estudiantes inexistentes.
- **Corrección**: Añadida validación de existencia, actividad y pertenencia a la escuela.

### 3. Timezone en funciones SQL de riesgo
- **Problema**: `fn_calculate_student_risk` y `fn_recalculate_school_metrics` usan
  `NOW() - INTERVAL '30 days'` sin zona Bogotá. Para ventanas de 30 días el impacto
  es mínimo (diferencia de horas), pero idealmente debería usar
  `(NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days'`.
- **Estado**: Documentado, no corregido (impacto mínimo para ventanas de 30 días).

### 4. Notificaciones sin purge automático
- **Problema**: Las notificaciones se acumulan indefinidamente. Solo se limpian
  manualmente o al aceptar un seguimiento.
- **Estado**: Documentado. Existe `purge_notification_garbage.sql` para limpieza manual.

### 5. student_tracking usa TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
- **Problema**: Las tablas `student_tracking` y `student_tracking_notes` usan
  `CURRENT_TIMESTAMP` como default (UTC) en lugar de `NOW() AT TIME ZONE 'America/Bogota'`.
- **Impacto**: Los timestamps de seguimientos pueden mostrar horas UTC en lugar de
  hora Bogotá si el frontend no convierte.
- **Estado**: Documentado. El frontend usa `formatChatTime` que formatea a hora local.

---

## 31. Endpoints REST Completos

### Auth
- `POST /auth/login` — Login con email+password (o 2FA)
- `POST /auth/verify-2fa` — Verificar código 2FA
- `POST /auth/logout` — Logout (revocar JWT)
- `GET /auth/me` — Usuario actual

### Dashboard
- `GET /dashboard/stats` — Conteos (presentes, ausentes, alertas, permisos, tarde)
- `GET /dashboard/teacher-group-detail` — Detalle por grupo/categoría
- `GET /dashboard/events` — Novedades (event feed)

### Operations
- `POST /operations/execute` — Ejecutar comando (sos, citacion, permiso, etc.)
- `POST /operations/{action}` — Endpoints específicos por acción
- `GET /operations/twilio-status` — Estado de mensajes Twilio

### Students
- `POST /students` — Crear/actualizar estudiante
- `GET /students` — Listar estudiantes

### Groups
- `GET /groups` — Listar grupos académicos

### School Config
- `GET /school/config` — Configuración de horarios
- `POST /school/onboarding` — Completar onboarding
- `PUT /school/config` — Actualizar configuración
- `POST /school/time-blocks` — Actualizar bloques horarios

### Tracking
- `POST /tracking/start` — Iniciar seguimiento
- `POST /tracking/notes` — Agregar nota
- `GET /tracking/details` — Detalle del seguimiento

### Behavior
- `GET /behavior/risk` — Estudiantes con riesgo HIGH/CRITICAL

### Consultations
- `POST /consultations/query` — Consulta por módulo
- `GET /consultation/search` — Buscar estudiantes

### Devices
- `GET /devices` — Listar dispositivos
- `POST /devices` — Registrar dispositivo
- `DELETE /devices/{id}` — Revocar dispositivo
- `POST /devices/command/{id}` — Enviar comando
- `GET /devices/commands` — Polling de comandos
- `POST /devices/ping` — Heartbeat

### Security
- `POST /security/panic` — Activar pánico

### Admin
- `POST /admin/recalc-risk` — Recalcular métricas de riesgo

### Users
- `GET /users/by-role` — Directorio por rol
- `GET /users/me/extended` — Perfil extendido
- `POST /users/upload-photo` — Subir foto
- `POST /users/change-password` — Cambiar contraseña
- `POST /users/send-verification-code` — Enviar código de verificación
- `POST /users/verify-code` — Verificar código

### Notifications
- `GET /notifications` — Listar notificaciones
- `POST /notifications` — Crear notificación
- `POST /notifications/clear` — Limpiar notificaciones

### Audit
- `GET /audit/global` — Logs globales
- `GET /audit/integrity` — Verificar cadena de auditoría
- `GET /audit/attendance/evasion` — Reporte de evasiones
- `GET /audit/*` — Endpoints de reportes específicos

### Misc
- `POST /contacto` — Formulario de contacto
- `POST /reports/preview` — Previsualización de reportes
- `POST /webhooks/twilio/inbound` — Webhook Twilio inbound
- `GET /metrics` — Métricas Prometheus
- `POST /telemetry` — Telemetría del frontend

---

## 32. Próximas Integraciones Pendientes (Post-Iteración 2)

1. **fn_calculate_student_risk con zona Bogotá**: cambiar `NOW() - INTERVAL '30 days'`
   por `(NOW() AT TIME ZONE 'America/Bogota') - INTERVAL '30 days'` en las funciones
   SQL de cálculo de riesgo.

2. **Purge automático de notificaciones**: crear un worker o pg_cron job que elimine
   notificaciones de más de 30 días automáticamente.

3. **Incluir EVASION_INTERNA en risk_score**: si se desea que la evasión afecte el
   puntaje de riesgo, modificar `fn_calculate_student_risk` para contar evasiones.

4. **Onboarding en todas las rutas**: actualmente el onboarding solo se verifica en
   el Dashboard. Si un RECTOR/COORDINADOR entra directamente a `/operacion` sin pasar
   por el dashboard, no verá el modal. Considerar mover la verificación al Layout.

5. **student_tracking con timezone Bogotá**: cambiar `CURRENT_TIMESTAMP` por
   `NOW() AT TIME ZONE 'America/Bogota'` en los defaults de `student_tracking` y
   `student_tracking_notes`.

4. **Deduplicación de eventos biométricos**: evitar que múltiples huellas en corto
   tiempo generen múltiples salidas/ingresos falsos.

5. **Dashboard de evasión**: mostrar conteo de evasiones del día en las StatCards.

6. **Reportes de evasión**: reportes históricos de evasión por estudiante/grupo/fecha.

7. **Configuración de qué tipos de eventos aparecen en novedades**: hacer configurable
   qué `command_type` aparecen en el event feed, en lugar de hardcodear la lista.
