# FRONTEND PENDIENTE — Trabajo solo-PWA no implementado

> Estado al 2026-09-17 (v3). El backend ya expone todos estos endpoints y el
> runner integrado los verifica; falta la capa de presentación en `PWA/`.
> Nota: el aviso de `Operation.jsx` al cambiar `horario` ("se notificará a los
> acudientes") ya es cierto — el backend encola WhatsApp tipo `HORARIO`.

## Onboarding institucional (Bloque B)

- **Manejo de HTTP 428**: cualquier `POST/GET` a `/operations/*`,
  `/consultations/*`, `/dashboard/*` puede responder
  `{status:'onboarding_required', missing:['schedule','groups','risk_config']}`
  para RECTOR/COORDINATOR/TEACHER (mensaje genérico al resto).
  → Interceptor en `api/client.js` que redirija al wizard correspondiente.
- **Wizard de completado**: horarios (`/school/*` schedule endpoints), grupos
  (Enrollment ya cubre), política de riesgo (`POST /risk/policy` — página
  `RiskConfig.jsx` existe pero debe marcar completion; el backend ya cierra
  `risk_config_completed` al guardar).

## Operación (Bloque C)

- **Botón "Registro manual pendiente"**: `POST /operations/registro_manual_pendiente`
  `{student, minutes?, reason?}` — suspende detectores hasta N min o hasta
  `registro_manual`. Falta el comando en `Operation.jsx` (ya existe
  `registro_manual`; este es la suspensión previa).
- **`pedagogica` con `return_time`**: el formulario solo envía
  `{group, reason, destination}`; añadir campo opcional `return_time`
  (datetime) para fijar cuándo vuelven del recorrido — default = fin de
  jornada.
- **Justificación de inasistencia**: el PWA no muestra el estado
  `metadata_json.guardian_response`/`reminders_sent`/`escalated` de
  `attendance_incidents` (ya poblado por worker_absence_followup + webhook).
  Columna en Consultation de inasistencias.

## Docente (Bloque B)

- **CRUD de criterios de aviso**: `GET/POST/PUT/DELETE /teacher/alert-rules`
  (event_kind, threshold_count, window_days, scope). Página nueva o sección en
  Dashboard docente.
- **Onboarding docente**: `GET/POST /teacher/onboarding` — el docente marca
  completado al configurar (u omitir) criterios. Pantalla de primer acceso.
- **Nodo asignado al docente**: `assigned_user_id` en Devices — el registro
  manual usa ese nodo como punto de registro.

## Dispositivos / OTA (Bloque D)

- **`app_version` en Devices.jsx**: `GET /devices` ya la devuelve — columna.
- **Publicar/revocar OTA**: `POST /devices/ota/publish` `{version, payload_url,
  payload_sha256, min_version?, notes?}` y `POST /devices/ota/revoke`
  `{update_id}` (RECTOR/COORDINATOR).
- **Estado de despliegues**: tabla `ota_deployments` consultable vía consulta
  de auditoría (pendiente endpoint de lectura si se requiere vista).
- **`ota_key`**: se devuelve UNA vez en `POST /devices` y `POST
  /devices/{id}/configure` — el PWA debe mostrarla/copiarla al provisionar
  (igual que `token`).

## Configuración institucional

- **`school_notification_routes`**: rutas de notificación por evento
  (`ABSENCE_RESPONSE`, `ABSENCE_NO_REPLY` → roles destino). UI de edición por
  escuela (RECTOR).
- **Variables de entorno** a documentar en despliegue (no PWA):
  `ABSENCE_FOLLOWUP_MINUTES|MAX`, `ABSENCE_ESCALATE_MINUTES`,
  `TEACHER_ALERTS_INTERVAL`, `OTA_*`, `TWILIO_SMS_FROM`,
  `NODE_OFFLINE_SECONDS` (test).

## Consultas

- `PWA/src/pages/Consultation.jsx` — corregido el `AbortSignal` (Bloque A).
  Pendiente: filtros de `grade`/`student` completos por módulo y columna de
  estado de respuesta de acudiente.
- Paginación/exportación de consultas pesadas (`report_exports` ya existe en
  backend — falta UI de descargas; existe `Downloads.jsx` revisar wiring).

## Seguimiento / derivación (cierre v3)

- **`POST /tracking/derive`** `{alert_id|incident_id}`: derivación manual de
  una alerta/incidente a `student_tracking` — falta el botón en el detalle de
  alerta de `Tracking.jsx`/panel de riesgo.
- **Campos nuevos de `student_tracking`**: `dependency`, `assigned_to_user_id`,
  `origin_type`/`origin_id` — mostrar origen (alerta/incidente) y dependencia
  responsable en la ficha de seguimiento.
- **`REAPARICION_TARDIA`**: nuevo tipo de `attendance_incidents` (ausente que
  reapareció) — la lista de incidentes debe renderizarlo (etiqueta +
  `metadata_json.reconciled_classroom_id`/`schedule_id`).

## Dispositivos / config (cierre v3)

- **`GET /admin/config-check`**: reporte config-vs-realidad (nodo sin aula,
  grupo sin schedule, docente sin asignación, nodo offline configurado) —
  vista de auditoría operativa para RECTOR/COORDINATOR.
- **`POST /devices/reassign`** `{device_id, group_id?, classroom_id?}` y
  **`POST /devices/reprovision`** `{device_id}` (rota token+ota_key) — acciones
  en `Devices.jsx` para contingencia de nodo.
- **`resync_required`/`clock_drift_s`** en la respuesta de `/devices/ping` —
  columna de drift en Devices (el nodo ya se resincroniza solo).
- **`RETURN_WRONG_SPACE`**: retornos de permiso en aula distinta a la esperada
  quedan marcados en `metadata_json.return_space_validated=false` — indicador
  en la lista de permisos.
