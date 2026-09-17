# FUNCIONAMIENTO FINAL DEL SISTEMA — NEXO

> Auditoría de cierre (2026-09-17, v3). Mapea cada capacidad a su implementación,
> su validación y la sección del plan/auditoría que la respalda.
>
> - Plan: `auditoria/PLAN_CORRECCIONES.md`
> - Correcciones: `auditoria/CORRECCIONES_IMPLEMENTADAS.md`
> - Migraciones: `auditoria/MIGRACIONES.md`
> - Motor de riesgo: `auditoria/MOTOR_RIESGO.md`
> - Pendiente PWA: `auditoria/FRONTEND_PENDIENTE.md`
> - Entorno de pruebas: `pruebas/README.md`

## Cómo validar (un solo comando)

```bash
cd pruebas
docker compose -f docker-compose.test.yml --env-file env.test -p nexo-test up -d --build
python3 runner.py all          # 36/36 sobre el stack real (incl. horario/reconcile)
API_BASE_URL=http://localhost:18080 ../backend/api/vendor/bin/phpunit --testsuite "Integration Tests"   # 42 PASS + onboarding 9/9
../backend/api/vendor/bin/phpunit --no-coverage ../test/api ../test/sql                  # 262 tests / 3361 aserciones
cd ../backend/edge && cmake --build build/dev && ./build/dev/bin/nexo-tests              # 84 casos / 543 aserciones
```

El entorno levanta PostgreSQL + Redis + PgBouncer + API (Nginx+PHP-FPM+MQTT)
+ todos los workers — nada es simulado por mocks HTTP; los workers reales
procesan los eventos reales.

---

## 1. Identificación y asistencia

| Capacidad | Implementación | Validación |
|---|---|---|
| Ingesta biométrica edge→central cifrada AES-256-GCM | `api.php` payload | runner `ingest` |
| Asistencia por bloques con jornadas rotativas | `worker_absence_detector` + `school_time_blocks` | runner `absence` |
| 2 huellas por estudiante | `student_fingerprints` + `test_multi_finger.cpp` | unit + edge |
| Registro manual de presencia (F-02) | `/operations/registro_manual` + `ctRegisterManualPresence` | runner `manual` |
| Exención biométrica permanente | `students.biometric_exempt` + `exemption_reason` | unit + runner |
| **Registro manual pendiente (§9.6)** | `students.manual_pending_until` + `/operations/registro_manual_pendiente` | suspende detectores hasta N min |
| Evasión interna entre bloques | `worker_evasion_detector` + permisos | workers + runner |

## 2. Jornada, horarios y casos excepcionales

| Capacidad | Implementación | Validación |
|---|---|---|
| Horario por jornada + bloques | `school_schedule_config`, `school_time_blocks`, `schedules` | seed + unit |
| Sin clases / festivo | `has_classes=FALSE` (también `school_calendar`) | suppress detectores |
| `fusionar_bloque` | `daily_schedule_config` + `merged` → suprime transición N→N+1 | evasion worker |
| `extender_bloque` | ahora también escribe `merged` + `entry_time` → ventana extendida suprime transiciones (Bloque C) | misma vía |
| `pedagogica` (salida grupal) | notifica acudientes + `pedagogical_trip_authorizations` → detectores excluyen | Bloque C |
| `horario` (sin clases) | `has_classes=FALSE` + **WhatsApp HORARIO a acudientes** | runner `horario` (stack real) |
| Franjas de tardanza al edge | ping publica `sched_*` de `school_schedule_config`; `checkLateStatus` lee `ConfigManager` | edge tests + ping |
| Resincronización de reloj | `resync_required` en ping cuando `|drift| > CLOCK_RESYNC_THRESHOLD_S`; edge ejecuta `forceTimeResync()` | CierreVerificacionesTest |

## 3. Detección y distinción de contextos

| Capacidad | Implementación | Validación |
|---|---|---|
| Inasistencia individual | `worker_absence_detector` | runner `absence` |
| Evasión | `worker_evasion_detector` | workers |
| Anomalía operativa grupal (F-05) | `ANOMALIA_OPERATIVA` + `pending_context` (no alimenta riesgo ni acudientes) | env `ANOMALY_*` |
| Nodo sin datos (F-04) | `SIN_DATOS_NODO` + gate de detectores | runner `offline` |
| Permiso individual | `class_exit_authorizations` ACTIVE + `schedule_id`/`classroom_id` | workers |
| **Reconciliación ausencia↔ingreso tardío** | `lib/attendance_reconcile.php` → `resolved` + `REAPARICION_TARDIA` (con aula/bloque) | runner `reconcile` (stack real) |
| Retorno valida espacio esperado | `RETURN_WRONG_SPACE` cuando el aula difiere | worker permission_status |

## 4. Comunicaciones (Twilio/WhatsApp)

| Capacidad | Implementación | Validación |
|---|---|---|
| Cola Redis + fallback PG + retry + DLQ | `worker_twilio` + `enqueueTwilioJob` | runner `dlq`/resilience |
| Inbound: menú 1/2 + **lenguaje natural** | `misc.php` webhook | parse "no sabía"/"justificada" |
| Justificación pide excusa + ponerse al día | reply actualizado | Bloque C |
| "No estaba al tanto" → coord/rectoría | rutas configurables `school_notification_routes` | Bloque C |
| **Sin respuesta → recordatorios + escalación** | `worker_absence_followup` (`ABSENCE_FOLLOWUP_*`, `ABSENCE_ESCALATE_MINUTES`) | heartbeat + metadata |
| **Fallback SMS** cuando WhatsApp falla | `TWILIO_SMS_FROM` en `sendTwilioWhatsAppSmart` | §9.8 |
| StatusCallback + DLQ durable + métricas | F-13 | runner `dlq` |

## 5. Resiliencia y contingencia

| Capacidad | Implementación | Validación |
|---|---|---|
| Apagón → respaldo/UPS → crítico | `simulaciones/energia/UpsSimulator` + eventos | runner `power` |
| M2M celular (F-10) | `CellularSimulator` + telemetría `cell` + SENAL_PERDIDA | runner `signal` |
| Nodo offline → blindar detectores | `contingency_lib` gate | runner `offline` |
| Redis caído → fail-open PG | `enqueueTwilioJob` fallback | runner `resilience` |
| DLQ durable + reintento | F-13 + `requeueDlqItems` | runner `dlq` |
| **OTA M2M resistente a apagones** | `OtaManager` (edge C++) + `/devices/ota/*` + `OtaNodeSimulator` | runner `ota` + tests C++/PHP |
| OTA anti-rollback + post-reboot | anti-rollback local, `app_version` persistente, `.bak` boot-loop, wrapper `nexo-edge-run.sh` | tests C++/PHP |
| LEDs de estado energético | `notifyPowerState` GPIO 17/27 + buzzer | simulador + hw real |
| Control térmico/ventilador | `/sys/class/thermal` + GPIO23 con histéresis | tests C++ |
| Retención configurable | `retention_days_synced`/`retention_days_dlq` | edge config |

## 6. Criptografía

| Capacidad | Implementación | Validación |
|---|---|---|
| AES-256-GCM campos PII/plantillas | `enc:v1:` edge + central `NEXO_AES_KEY` | test_crypto + runner |
| Clave ligada al hardware (nodo robado) | `Encryption` key file cifrado con clave derivada del serial RPi | `[C5]` encryption.cpp |
| **Documento seudonimizado** | `estudiantes.documento` = HMAC-SHA256(clave, doc) + `documento_enc` cifrado; lectura dual (clave/legado) | tests F-11 edge |
| Cadena de auditoría HMAC | `audit_trail` edge + `audit/integrity` | runner + audit |
| Firma OTA por-dispositivo | HMAC-SHA256 `nexo-ota|ver|sha|url` con `ota_key` | runner `ota` |

## 7. Gobernanza, roles y onboarding

| Capacidad | Implementación | Validación |
|---|---|---|
| Bloqueo operativo sin onboarding | `requireSchoolOnboarding` → 428 | runner `onboarding` |
| RLS multi-tenant | políticas `school_id = get_current_school_id()` | esquema + tests |
| Criterios de aviso docente (F-18) | `teacher_alert_rules` + `worker_teacher_alerts` + **RLS** | runner `teacher` |
| Onboarding docente | `/teacher/onboarding` + `users.onboarding_completed` | runner `teacher` |
| `risk_config_completed` cerrable | `POST /risk/policy` lo marca | Bloque D fix |
| Routing de notificaciones | `school_notification_routes` (enabled/priority) + RLS | misc + followup |
| **Config-vs-realidad** | `GET /admin/config-check` — divergencias config↔operación | CierreVerificacionesTest |
| Reubicación/recuperación nodo | `POST /devices/reassign` + `POST /devices/reprovision` | CierreVerificacionesTest |
| Derivación a seguimiento | `student_tracking.dependency`/`origin_*` + auto desde risk_alert SEGUIMIENTO + `POST /tracking/derive` | CierreVerificacionesTest |

## 8. Motor de riesgo pedagógico

Ver `MOTOR_RIESGO.md`. Resumen: políticas versionadas por escuela, 5 niveles
fijos, umbrales/vida-media/cooldowns configurables dentro de rangos protegidos,
combinación entre categorías, máquina de estados OBSERVACION→…→ATENCION_INMEDIATA,
alertas informativas no diagnósticas, decisión humana final.

## 9. Salud operativa

`/health` reporta db, redis y heartbeat por worker: `twilio`, `biometric`,
`absence_detector`, `evasion_detector`, `permission_status`, `device_health`,
`teacher_alerts`, `absence_followup`. `worker_twilio` ya hace heartbeat en
reposo (fix Bloque C — antes parecía caído con cola vacía).

## 10. Decisiones arquitectónicas y límites conocidos

- **B-07**: decisión documentada (ver PLAN_CORRECCIONES).
- **OTA payload**: la URL del binario debe ser alcanzable por el nodo vía M2M
  (servidor de archivos propio o del central); el sistema firma y verifica,
  no hospeda el binario.
- **Rollback OTA**: la restauración de `.bak` tras fallo de arranque depende
  del wrapper de arranque del nodo (systemd/script); la confirmación la hace
  el propio OtaManager tras 120 s de uptime.
- **Anomalía grupal**: umbral por defecto min 5 y ≥50% del grupo
  (`ANOMALY_MIN_ABSENCES`, `ANOMALY_GROUP_FRACTION`) — configurable.
- **Comandos manuales**: `inasistencia`, `inasistencia_bano` (SALIDA_BAÑO),
  `registro_manual`, `registro_manual_pendiente`, `pedagogica`, `horario`,
  `fusionar_bloque`, `extender_bloque`, `permiso`, `salida`, `citacion`,
  `sos`, `entrega`, `anomalia`, `madrugada`, `asamblea`, `recuperacion`,
  `reagendar`, `normal`, `emergencia`, `policia`, `devolver_dispositivo`,
  `reasignar_dispositivo`.
- **Frontend**: todo lo pendiente está en `FRONTEND_PENDIENTE.md`.
- **Compose de prod**: servicios `audit-worker`/`biometric-worker` duplicados
  con rutas rotas — documentado, pendiente eliminarlos del compose prod.

## 11. Cobertura de validación (resumen)

- Runner integrado sobre stack real: **36/36** + escenarios nuevos
  `tamper` (apertura de gabinete → TAMPER_OPEN HIGH + notif + dedup),
  `ups_jornada` (descarga 90%→5% con ingest ininterrumpido) y
  `stress` con umbrales (0×5xx, ≥95% aceptados, p50/p95/max, persistencia).
- Nuevos endpoints verificados en vivo: `POST /students/{id}/consent`
  (habeas data, REVOCADO→biometric_exempt), `POST /tracking/close`
  (workflow formal), `notifications.origin_*` (trigger automático).
- Integración HTTP: **42 PASS** + onboarding assignments **9/9**.
- Unit PHP (api+sql): **262 tests, 3361 aserciones** (incl.
  `CierreVerificacionesTest` 9/9).
- Edge C++ (Catch2): **84 casos, 543 aserciones** — incluye OTA, crypto,
  PII seudonimizada, energía, térmica.
- Simuladores en `simulaciones/`: energía, m2m, nodo, biometría, térmico,
  almacenamiento, **ota** — todos estrictos (fallan si la implementación falla).
- Bug real hallado y corregido en esta pasada: ventana "hoy Bogotá" desfasada
  ~5 h en reconciliación/permisos/evasión (TZ de sesión vs fecha local).
