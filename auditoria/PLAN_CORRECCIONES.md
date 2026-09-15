# FINALIZACIÓN DE AUDITORÍA — QUÉ HAY QUE ARREGLAR (NEXO)

Documento de cierre de la auditoría documento→código (652 verificaciones). Consolida los incumplimientos y parciales en un plan de corrección **ordenado por criticidad y por dependencias** (cada fase solo requiere lo que la precede; dentro de cada fase los ítems van de más a menos crítico).

**Resultado de la auditoría (v2, segunda pasada):** 458 ✅ · 95 ⚠️ · 55 ❌ · 44 ◻️ (detalle por ítem en `ANALISIS_AUDITORIA.md`).

---

## MAPA DE DEPENDENCIAS ENTRE DEFICIT ESTRUCTURALES

```
F-01 Modelo espacial (classrooms/schedules/eventos con aula-bloque)
 ├─► V-061/062/063 validación de espacio correcto
 ├─► V-523/524/525 reubicaciones y contingencia de espacio
 ├─► V-132/136 consulta por clase/bloque
 └─► V-031 identificación en espacio actualizado

F-02 Registro manual + exención biométrica
 ├─► V-536..545 operación manual
 ├─► V-522/526/527 continuidad ante nodo caído
 ├─► V-593/342 excepciones de enrolamiento
 └─► F-04 (detectores deben respetar registros manuales)

F-04 Salud del nodo en central
 ├─► V-521/486 no marcar ausencia por nodo caído
 ├─► F-05 anomalía agregada (cluster de ausencias)
 └─► V-525 grupos afectados por nodo

F-15 Actuaciones configurables (routing de condiciones)
 ├─► V-013/041 acción al vencer permiso
 ├─► V-066/081 actor destinatario configurable
 └─► V-377/388/406 condiciones que alertan vs. no

Hardware-dependientes (no bloquean nada, pero nada los desbloquea):
 F-09 energía/UPS · F-10 M2M celular · F-12 térmico · V-293..323 físico
```

---

## FASE 0 — BUGS Y DEUDAS INMEDIATAS (sin dependencias)

Correcciones puntuales de código defectuoso o inseguro. No requieren diseño nuevo.

| ID | Qué arreglar | Dónde | Severidad |
|----|--------------|-------|-----------|
| B-01 | `$e->getMessage()` usado fuera de scope de excepción → `Undefined variable: e` | `routes/operations.php:480,551,659,665,672,679,688,698,705`; `routes/misc.php:80,88,108,114,178,197,275,292,339`; `routes/dashboard.php:524` | Alta (notices + respuestas rotas) |
| B-02 | `ROLES.COORDINATOR` no existe (clave real `COORDINADOR`) → item "Sensores" invisible para coordinación | `PWA/src/config/roles.js:89` | Media |
| B-03 | `TESTING_MODE = true` deja ScheduleTask activo todo el día | `PWA/src/components/patterns/ScheduleTask.jsx:36-37` | Media |
| B-04 | `POST /risk/policy` devuelve debug/SQLSTATE al cliente | `routes/risk.php:102-111` | Alta (fuga de info) |
| B-05 | Endpoints duplicados `/audit/global` y `/audit/integrity` en `audit_logs.php`/`audit_integrity.php` vs `audit_full.php` | routes audit_* | Media |
| B-06 | `school_exit_authorizations.actual_return_time` se setea al **salir**, no al retornar | `worker_biometric.php:284` | Media (semántica) |
| B-07 | Tokens JWT+refresh en `localStorage` (workaround ITP) — riesgo XSS | `PWA AuthContext.jsx:14-19`, `api/client.js:46-49` | Alta |
| B-08 | `$_SESSION['user_role']` en RiskEngineV3 fuera de contexto de sesión → audit log con rol por defecto | `lib/RiskEngineV3.php:319` | Baja |
| B-09 | Consultas con interpolación `$conn->quote` en vez de prepared statements | `consultations.php:76-81` | Media |
| B-10 | `horario` está en `$presenceRequiredActions` — exige presencia de estudiante para un cambio de jornada | `operations.php:277` | Media |
| B-11 | `health.php` no monitorea `evasion_detector`, `permission_status`, `audit` | `health.php:109-113` | Media |

---

## FASE 1 — FUNDACIÓN DE DATOS (desbloquea la mayoría de incumplimientos)

### F-01 · Modelo espacial real: aulas, malla horaria y eventos georreferenciados ★ CRÍTICO

**Problema:** `classrooms` y `schedules` nunca reciben INSERT (tablas huérfanas); `edge_devices` no guarda `classroom_id`; `biometric_events` no registra aula/bloque. Sin esto, todo lo "contextual por espacio" es imposible.

**Trabajo:**
1. `sql/`: migración — `edge_devices.classroom_id` ya existe; asegurar `NOT NULL` donde aplique; índice `(classroom_id)`.
2. `routes/school_config.php`: endpoints CRUD `classrooms` y `schedules` (grupo↔aula↔bloque↔docente↔materia); `groups-onboarding` debe crear aula por grupo (1:1 por defecto).
3. `routes/devices.php:209`: incluir `classroom_id` en registro; `school_config.php:904` idem en auto-creación.
4. `workers/worker_biometric.php:243`: resolver y guardar `classroom_id` (desde `edge_devices`) y `schedule_id` (desde `schedules` por grupo+día+bloque/hora) en cada evento.
5. `workers/worker_evasion_detector.php`: en modo rotativo, validar que el INGRESO del bloque N+1 ocurra en el `classroom_id` esperado → si ocurre en otro nodo: incidente `AULA_INCORRECTA` (no cerrar permisos ni limpiar evasión con él).
6. `workers/worker_permission_status.php:68-95`: `COMPLETED` solo si el INGRESO es en el aula/contexto esperado.
7. `PWA`: `OnboardingGroupsModal` agrega paso de aulas; `Devices.jsx` selecciona aula; Consultation muestra aula/bloque por evento.

**Desbloquea:** V-002, V-016, V-019, V-031, V-061, V-062, V-063, V-074, V-075, V-084, V-132, V-135, V-136, V-161, V-169, V-340, V-480, V-483, V-523, V-524, V-586, V-601, V-618.

### F-02 · Registro manual de presencia + exención biométrica ★ CRÍTICO

**Problema:** no existe forma de registrar presencia sin biometría; no hay exención por estudiante.

**Trabajo:**
1. `sql/`: `students.biometric_exempt BOOLEAN DEFAULT FALSE`, `students.exemption_reason TEXT`; `attendance_incidents.incident_type` acepta `REGISTRO_MANUAL`; opcional `biometric_events.metadata_json.source='manual'` (o `event_type='INGRESO_MANUAL'`).
2. `routes/operations.php`: comando `registro_manual` (roles: TEACHER, COORDINATOR, SECRETARY, SECURITY) — inserta evento manual con actor y motivo.
3. `workers/worker_absence_detector.php` y `worker_evasion_detector.php`: tratar `INGRESO_MANUAL` como presencia; si `biometric_exempt`, no generar INASISTENCIA/EVASION por falta de huella (pero sí procesar sus eventos manuales).
4. `PWA/Operation.jsx`: comando "Registro manual" con estudiante+contexto+motivo.
5. `PWA/Enrollment.jsx`: marcar exención cuando el enrolamiento biométrico no es posible.

**Desbloquea:** V-342(parcial), V-477, V-522, V-526, V-527, V-536, V-537, V-539–V-545, V-593.

### F-03 · Dos huellas por estudiante ★ ALTO

**Problema:** solo 1 huella (`students.biometric_hash` único; edge `huella_id UNIQUE`; enrolar sobrescribe).

**Trabajo:**
1. Edge `sqlite_manager.cpp:69`: nueva tabla `huellas(documento, finger_index, huella_id, template_huella)` con UNIQUE(documento, finger_index); `estudiantes` deja de guardar plantilla.
2. `main.cpp` enrolamiento: capturar 2 dedos (loop finger_index 1..2); `handleBiometricMatch` busca en `huellas`.
3. Central `students`: `biometric_hash` → guardar ambos ids (`biometric_hash` JSONB o `fingerprint_ids[]`); `devices.php` enroll-confirm acepta 2.
4. Payloads `REGISTER_STUDENT`: llevar `huella_ids[]`.
5. PWA `Enrollment.jsx`: paso de huella repite para segundo dedo.

**Desbloquea:** V-538, V-591.

---

## FASE 2 — SALUD DEL NODO Y CONTINGENCIA (depende de F-01/F-02)

### F-04 · Monitor de salud de nodos + blindaje de detectores ★ CRÍTICO

**Problema:** ningún worker consulta `edge_devices.last_ping`; un nodo caído produce ausencias/evasiones falsas masivas.

**Trabajo:**
1. Nuevo `workers/worker_device_health.php`: heartbeat watch — nodo sin ping > X min → `security_incidents` tipo `NODO_OFFLINE` + notificación a coordinación + marca los grupos/aulas afectados.
2. `worker_absence_detector.php` / `worker_evasion_detector.php`: antes de marcar ausencia/evasión, verificar que el dispositivo del grupo/aula esté online; si no → incidente `SIN_DATOS_NODO` (no ausencia).
3. Dashboard: indicador de salud de nodos en tiempo real.

**Desbloquea:** V-486, V-490, V-496, V-520, V-521, V-525, V-580, V-582, V-649, V-650. (Requiere F-01 para mapear nodo↔aula↔grupos preciso; hoy puede aproximarse con `edge_devices.group_id`.)

### F-05 · Estado "pendiente de contexto" + anomalía agregada ★ ALTO

**Problema:** una concentración anómala de ausencias (p. ej. nodo caído o evento institucional) se convierte en N incidentes individuales sin señal de "condición externa".

**Trabajo:**
1. `worker_absence_detector.php`: si ausencias simultáneas de un grupo/escuela superan umbral configurable → incidente agregado `ANOMALIA_OPERATIVA` y marca las individuales `pending_context=TRUE` hasta que un actor confirme.
2. `attendance_incidents.metadata_json.pending_context` + filtro en dashboard ("pendientes de contexto").
3. RiskEngineV3: excluir eventos `pending_context` de la evaluación hasta resolución.

**Desbloquea:** V-487, V-488, V-489, V-567.

### F-06 · Telemetría operativa del nodo → central ★ ALTO

**Problema:** el edge no reporta desviación de reloj, espacio en disco, profundidad de cola ni estado eléctrico.

**Trabajo:**
1. Edge `main.cpp`: en `/devices/ping` (o evento `NODE_TELEMETRY`) incluir `{clock_drift_s, disk_free_mb, pending_events, dlq_count, power_state}`.
2. `devices.php`: persistir en `edge_devices.status`/JSONB; umbrales → `security_incidents` + notificación a técnico/coordinación.
3. Edge: monitor de disco (`statvfs`) y de cola.

**Desbloquea:** V-494, V-496, V-504, V-507, V-508.

### F-07 · Reubicación temporal y reemplazo de nodo ★ MEDIO

**Trabajo:**
1. `daily_schedule_config` (o tabla `temporary_relocations`): grupo→aula/dispositivo alterno por fecha/bloque.
2. Comando `operations/reubicacion` (COORDINATOR); detectores leen la reubicación vigente.
3. Flujo de reemplazo de nodo: `POST /devices/{id}/replace` — conserva `device_id` lógico o migra cola; edge drena pendientes antes de salir de servicio.

**Desbloquea:** V-019, V-523, V-524, V-534, V-535. (Depende de F-01.)

### F-08 · Reconciliación ausencia→identificación posterior ★ ALTO

**Problema:** un `INASISTENCIA` no se corrige si el estudiante aparece después (o en otro espacio).

**Trabajo:** `worker_biometric.php`: al insertar `INGRESO_*`, si existe `INASISTENCIA`/`INASISTENCIA_NO_JUSTIFICADA` del día sin resolver → actualizar metadata (`arrived_late_at`, `reconciled`) y notificar al docente/coordinación; si el evento fue en aula no esperada → `DISCREPANCIA_ESPACIO`.

**Desbloquea:** V-530, V-531, V-574.

---

## FASE 3 — EDGE: ENERGÍA, M2M, ALMACENAMIENTO, TÉRMICO (hardware-dependiente)

| ID | Qué arreglar | Componentes | Desbloquea | Severidad |
|----|--------------|-------------|------------|-----------|
| F-09 | Gestión de energía de respaldo: leer estado UPS (GPIO/UPS HAT/I2C fuel gauge), evento `POWER_BACKUP`/`POWER_LOST`, LED de estado energético, shutdown ordenado al agotar batería | `edge/src/hardware/` (nuevo `PowerMonitor`), `RealGpioManager`, `main.cpp` | V-229–V-232, V-420–V-422, V-512–V-516, V-518 | Crítica |
| F-10 | Conectividad celular M2M: integración módem (ModemManager/ppp scripts), watchdog de interfaz, métricas de señal en telemetría | `edge/scripts/`, nuevo módulo `net/CellularManager` | V-204–V-207, V-326 | Crítica |
| F-11 | Cifrado completo del almacenamiento local: migrar a SQLCipher o cifrar campos `estudiantes`(doc/nombre/teléfonos) y `audit_trail` | `sqlite_manager.cpp`, `encryption.cpp` | V-243–V-245, V-328, V-394 | Alta |
| F-12 | Control térmico: ventilador PWM por temperatura (sensor SoC/`/sys/class/thermal`) | `edge` nuevo `ThermalManager` | V-310 | Media |
| F-13 | DLQ edge con reintento de largo plazo + reporte a central | `main.cpp` SyncWorker | V-509 | Media |

**Nota:** V-293–V-309, V-311–V-323, V-333, V-334, V-587, V-588, V-597, V-598 son atributos físicos/de instalación (◻️). Requieren dossier de hardware y checklist de instalación, no cambios de código — documentarlos como anexo de cumplimiento físico.

---

## FASE 4 — LÓGICA DE NEGOCIO Y CONFIGURACIÓN

| ID | Qué arreglar | Componentes | Desbloquea | Severidad |
|----|--------------|-------------|------------|-----------|
| F-14 | `pedagogica` debe crear `pedagogical_trip_authorizations` (por estudiante o grupo) con vigencia y retorno; detectores respetan viaje activo | `operations.php:1010`, workers, PWA | V-015, V-099, V-111 | Alta |
| F-15 | Tabla `action_routes`/`notification_rules` por escuela: condición → actor(es)/actuación; `worker_permission_status` emite la actuación configurada al vencer | schema + `school_config.php` + workers | V-013, V-041, V-058, V-066, V-081, V-377, V-388, V-406 | Alta |
| F-16 | `horario` envía WhatsApp a acudientes del grupo afectado (opt-out por config) | `operations.php:1366` | V-030, V-045 | Alta |
| F-17 | Extensión de bloque por docente: seed `operations.extender_bloque`→TEACHER (o fusionar semántica), y que `expected_exit_time` extendido suprima evasión post-bloque en detectores | schema seeds, `worker_evasion_detector` (usar `exit_time` del override, no solo `merged`), PWA `Operation.jsx:46` | V-004, V-046, V-077 | Media |
| F-18 | Criterios de aviso por docente: tabla `teacher_alert_rules` (docente, grupo, tipo, umbral, ventana) + endpoints + evaluación en workers/risk | schema, nuevo route, workers | V-056, V-078, V-124, V-167, V-379, V-434, V-603 | Alta |
| F-19 | Seguimiento formal: `student_tracking` + `assigned_role`/`department`, enum de estados, endpoints `derive`/`close`, auto-instanciar cuando `risk_alerts.escalation_state='SEGUIMIENTO'` | schema, `tracking.php`, `RiskEngineV3`/trigger, PWA `TrackingModal` | V-069, V-150, V-151 | Alta |
| F-20 | Activar `risk_combination_rules` en el motor (hoy desactivadas) | `RiskEngineV3.php:304`, `fn_evaluate_student_risk` | V-166, V-378, V-436, V-564 | Media |

---

## FASE 5 — COMUNICACIÓN Y UX

| ID | Qué arreglar | Componentes | Desbloquea | Severidad |
|----|--------------|-------------|------------|-----------|
| F-21 | Canal SMS fallback (Twilio SMS) cuando WhatsApp no está disponible; inbound SMS al mismo contexto | `lib/twilio.php`, `worker_twilio.php`, webhook | V-555, V-556 | Media |
| F-22 | Bandeja de mensajería interna en PWA (`internal_messages`) con hilo y vínculo al acontecimiento origen | `PWA` nueva página, `operations.php`/nuevo endpoint GET | V-027, V-028 | Media |
| F-23 | Consulta de horarios y tardanzas por clase/bloque | `consultations.php` nuevos módulos (requiere F-01) | V-132, V-136 | Baja |
| F-24 | Actuaciones embebidas en consulta (permiso/incidente/citación desde ficha del estudiante) | `PWA ConsultationDrawer` + shortcuts a `Operation` | V-140 | Baja |
| F-25 | Canal secretaría→acudiente: permiso `operations.citacion` (u otro) a SECRETARY + UI | schema seeds, PWA | V-127 | Baja |
| F-26 | Gestión de usuarios por directivos (CRUD usuarios/roles) | `admin.php` + PWA | V-117 (robustez) | Media |
| F-27 | Retenciones configurables por institución | schema config + purgas | V-427 | Baja |
| F-28 | Recordatorio de excusa/actividades pendientes tras respuesta "informado" | `misc.php` inbound | V-157 | Baja |
| F-29 | (Opcional) Push real-time (SSE/WebSocket) en vez de polling | API + PWA | V-110 | Baja |

---

## FASE 6 — PRUEBAS, PROCESO Y CUMPLIMIENTO

| ID | Qué arreglar | Desbloquea | Severidad |
|----|--------------|------------|-----------|
| F-30 | Tests de carga/estrés (k6/locust sobre ingest+workers) | V-607–V-609 | Media |
| F-31 | Test integral de continuidad (offline→cola→sync→dedup) y de contingencia de nodo/manual | V-610, V-611 | Media |
| F-32 | Herramienta de contraste config↔operación real (marcha blanca) | V-614 | Baja |
| F-33 | Flujo de derechos de titulares (acceso/rectificación/supresión documentado) | V-344 | Media |
| F-34 | Dossier físico del nodo (gabinete, prensaestopas, fijación, térmico, puesta a tierra) — documental | V-293–V-323 etc. | Requerido para cumplimiento total |

---

## ORDEN DE EJECUCIÓN RECOMENDADO (resumen)

1. **Fase 0** — bugs inmediatos (B-01…B-11). Barato, sin riesgo estructural.
2. **F-01** modelo espacial → **F-02** registro manual/exención → **F-03** dos huellas.
3. **F-04** salud del nodo (usa F-01; puede empezar con `group_id` actual) → **F-05** anomalía agregada → **F-06** telemetría nodo → **F-08** reconciliación → **F-07** reubicación.
4. **Fase 3** (edge/hardware): F-09, F-10, F-11 en paralelo — dependen de hardware real.
5. **Fase 4**: F-14, F-15, F-16, F-17, F-18, F-19, F-20.
6. **Fase 5** y **Fase 6** — cierre funcional, pruebas y cumplimiento.

**Regla de dependencias crítica:** nada de validación espacial (F-01 hijos, F-04, F-07, F-08 espacial, F-23) puede implementarse antes del modelo de aulas/horarios poblado; nada de contingencia manual antes de F-02.

---

*Fin del documento de auditoría. Los tres artefactos: `LISTA_VERIFICACIONES_DOCUMENTO_CODIGO.md` (652 ítems), `ANALISIS_AUDITORIA.md` (veredictos por bloque de 50 con evidencia), `PLAN_CORRECCIONES.md` (este documento).*
