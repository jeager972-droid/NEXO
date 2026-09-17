# CORRECCIONES IMPLEMENTADAS — NEXO

Registro obligatorio por fase (ver PLAN_CORRECCIONES.md → "REGLA DE DOCUMENTACIÓN"). Por ítem: qué se hizo, por qué, qué cambió, impacto y bajo qué decisión.

---

## FASE 0 — BUGS Y DEUDAS INMEDIATAS (2026-09-15)

**Alcance:** correcciones puntuales sin diseño nuevo. Verificación: `php -l` en los 17 archivos PHP tocados ✅ · PHPUnit "API Unit Tests" 136/136 ✅ · "SQL Schema Tests" 60/60 ✅ · tests de integración no corren (requieren API levantada).

### B-01 — `$e->getMessage()` fuera de scope de excepción → Error fatal

- **Qué se hizo:** eliminada la clave `'debug' => $e->getMessage()` de 66 respuestas `json_encode` en 13 archivos de `routes/` (operations, misc, dashboard, school_config, devices, students, tracking, admin, behavior, consultations, security_panic, risk, audit_full).
- **Por qué:** en ~50 sitios `$e` no existía (bloques `if` de validación, no `catch`). En PHP 8, `null->getMessage()` = `Error` fatal → las respuestas 400/404/422 crasheaban con 500 en vez de responder el error correcto. La auditoría contó ~20; el barrido encontró 66.
- **Qué cambió:** respuestas de error de validación vuelven a emitir el HTTP correcto con mensaje limpio. Además se eliminó la fuga de detalles internos en los casos donde sí estaba dentro de `catch` (misma clave `debug`).
- **Impacto:** ningún consumidor depende del campo `debug` (verificado: PWA solo lo lee con guard `payload?.debug` en 3 sitios — degradan a vacío). Cero cambio de comportamiento sano; elimina fatals + fuga de info.
- **Decisión:** auditoría B-01 + verificación directa en código (falsos solo parcialmente: la auditoría subestimó el alcance; el bug era real y más amplio).

### B-04 — `/risk/policy` devolvía SQLSTATE + traza al cliente

- **Qué se hizo:** `risk.php` — el catch 500 ahora devuelve `'Error interno al guardar la política'`; el detalle (clase, archivo, línea, SQLSTATE) sigue yendo a `securityLog`. Además se reemplazaron 22 respuestas `'message' => $e->getMessage()` en catch (risk.php ×10, users.php ×10, school_config.php ×2) por `'Error interno del servidor'` — mismo patrón de fuga.
- **Por qué:** el comentario decía "DEBUG (temporal)"; exponía SQLSTATE, paths y mensajes de excepción al cliente — fuga de información explotable.
- **Qué cambió:** cliente recibe mensaje genérico; el detalle completo queda en el log de seguridad (auditable, no público).
- **Impacto:** 422 de validación conserva `details` (son mensajes seguros de `InvalidArgumentException`). Ningún flujo roto.
- **Decisión:** auditoría B-04, extendido por consistencia a toda la familia `message => $e->getMessage()` en respuestas 500.

### B-02 — `ROLES.COORDINATOR` inexistente → "Sensores" invisible para coordinación

- **Qué se hizo:** `PWA/src/config/roles.js:89` → `ROLES.COORDINADOR` (la clave correcta; el *valor* ya era `'COORDINATOR'`).
- **Por qué:** `ROLES.COORDINATOR` evalúa `undefined` → el ítem Sensores (`/dispositivos`) nunca matcheaba el rol del coordinador.
- **Qué cambió:** coordinación ve el menú Sensores.
- **Impacto:** solo restaura navegación.
- **Decisión:** auditoría B-02, verificado en código.

### B-03 — `TESTING_MODE = true` hardcodeado en ScheduleTask

- **Qué se hizo:** `ScheduleTask.jsx` → `import.meta.env.VITE_SCHEDULE_TASK_TESTING === 'true'`; documentado en `.env.example`.
- **Por qué:** la tarea de cambio de horario (obligatoria, ventana 13h+/18h+) se mostraba las 24h a todos los coordinadores — ruido operativo.
- **Qué cambió:** en producción la tarea respeta su ventana; el modo prueba sigue disponible vía `.env.local`.
- **Impacto:** comportamiento de producción corregido; capacidad de testing preservada.
- **Decisión:** auditoría B-03. El TODO del código pedía exactamente esto.

### B-05 — Endpoints duplicados `/audit/global` e `/audit/integrity`

- **Qué se hizo:** eliminados `routes/audit_logs.php` y `routes/audit_integrity.php`; route map `'audit'` → solo `audit_full.php`. Se preservaron los `securityLog` de acceso (`AUDIT_LOGS_VIEWED`, `AUDIT_INTEGRITY_CHECKED`) en los handlers canónicos, y `/audit/integrity` ahora devuelve `data` como array de una fila (el grid de Consultation.jsx renderiza `res.data[]`; con objeto plano no mostraba nada).
- **Por qué:** dos implementaciones por endpoint con contratos distintos; la que ganaba por orden de carga era la más pobre (sin filtros, sin `columns`, integridad no renderizable).
- **Qué cambió:** `/audit/global` ahora acepta filtros `from/to/limit` y devuelve `columns`; `/audit/integrity` se renderiza en el grid y mantiene `integrity_valid` top-level.
- **Impacto:** contrato más rico y único; Consultation.jsx lo consume sin cambios (deriva columnas de las filas).
- **Decisión:** auditoría B-05; audit_full elegido como canónico por ser la versión completa (filtros + metadata) y por el contrato del grid.

### B-06 — `actual_return_time` escrito al salir, no al retornar

- **Qué se hizo:** `worker_biometric.php` — `SALIDA_AUTORIZADA` ahora pone `status='COMPLETED'` (o `'APPROVED'` si `expected_return_time` existe) **sin** tocar `actual_return_time`. Nuevo bloque: un `INGRESO_*` posterior cierra la autorización con `actual_return_time=NOW()` real.
- **Por qué:** el campo registraba el momento de *salida* como si fuera el retorno; el reporte "retornos pendientes" (`status IN ('APPROVED','PENDING') AND actual_return_time IS NULL`) quedaba siempre vacío.
- **Qué cambió:** semántica correcta: salida≠retorno. Hoy `expected_return_time` nunca se escribe → todo queda `COMPLETED` igual que antes (sin timestamp falso). Cuando se use, los retornos pendientes serán visibles y se cerrarán con el INGRESO real.
- **Impacto:** reportes de auditoría de salidas escolares correctos; habilita el reporte de retornos pendientes.
- **Decisión:** auditoría B-06, verificado contra schema y consumidores (audit_full, consultations).

### B-08 — `$_SESSION['user_role']` fuera de contexto en RiskEngineV3

- **Qué se hizo:** `createPolicyVersion()` recibe nuevo parámetro `$actorRole` (default 'COORDINATOR' para compat); `risk.php` pasa `$authUser['role']`.
- **Por qué:** la API es 100% JWT — `$_SESSION` nunca se setea en todo el backend; el audit log de políticas siempre grababa 'COORDINATOR' aunque el actor fuera RECTOR.
- **Qué cambió:** `risk_audit_log.actor_role` registra el rol real del actor.
- **Impacto:** solo trazabilidad; sin efecto en lógica.
- **Decisión:** auditoría B-08, verificado (grep: `$_SESSION` no existe en ningún otro archivo).

### B-09 — `$conn->quote` interpolado en consultations.php

- **Qué se hizo:** validación de formato UUID sobre `$userId` (viene del JWT) antes de interpolar con `quote()`; 403 si no es UUID.
- **Por qué:** patrón inconsistente (resto del archivo usa prepared statements). Convertir el fragmento `$teacherGroupFilter` a `?` exigía tocar ~15 `execute()` con órdenes de parámetros distintos — riesgo alto para beneficio bajo (`quote()` ya escapa y el valor es server-issued).
- **Qué cambió:** defensa en profundidad — aunque `quote()` fallara o el valor fuera manipulado, la validación UUID lo hace inerte.
- **Impacto:** ninguno funcional; cierra la vía teórica de inyección.
- **Decisión:** auditoría B-09; elegida la opción de menor blast radius deliberadamente (consistencia vs. riesgo de tocar 15 call-sites).

### B-10 — `'horario'` en `$presenceRequiredActions`

- **Qué se hizo:** removido `'horario'` del array; comentario actualizado.
- **Por qué:** `horario` es operación de **grupo** (`daily_schedule_config` por nombre de grupo); exigir presencia biométrica de un estudiante para un cambio de jornada es semánticamente erróneo. En la práctica el flujo real (ScheduleTask) no envía `student_id`, así que el check nunca se disparaba — **borde de falso positivo en impacto**, pero la regla estaba mal escrita.
- **Qué cambió:** si un cliente pasa `student_id` con `horario`, ya no se rechaza por ausencia del estudiante. Sin efecto en el flujo principal.
- **Impacto:** solo amplía aceptación de un edge case; no rompe nada.
- **Decisión:** auditoría B-10; confirmado como bug semántico real de bajo impacto (no falso positivo estricto).

### B-11 — health.php no monitorea evasion_detector, permission_status, audit

- **Qué se hizo:** `health.php` y el health embebido de `api.php` ahora incluyen `evasion_detector` y `permission_status`; `audit` se monitorea solo si `AUDIT_WORKER_ENABLED=1` (es worker condicional). Umbral de health.php subido de 120s → 300s.
- **Por qué:** dos detectores daemon invisibles para el health check — podían estar muertos sin que 503 lo reflejara.
- **Qué cambió:** health refleja los 5 workers always-on + audit condicional. El umbral 120s causaría falsos negativos (evasion hace heartbeat cada ~120s); 300s iguala al health de api.php.
- **Impacto:** health más completo; umbral más tolerante evita 503 espurios. `worker_notification_purge` no se monitorea (no escribe heartbeat — corre como cron/una pasada).
- **Decisión:** auditoría B-11 + verificación de intervalos reales de heartbeat en cada worker.

### B-07 — JWT + refresh tokens en `localStorage` → ⏸️ PENDIENTE DE DECISIÓN

- **Estado:** NO corregido. Es un "TEMPORAL ITP WORKAROUND" deliberado: Safari ITP bloquea cookies cross-site, y el backend actual entrega tokens en body JSON (no hay Set-Cookie httpOnly).
- **Riesgo real:** XSS puede robar tokens (biometría de menores detrás — sensibilidad alta).
- **Fix correcto = invasivo:** cookies httpOnly + `SameSite=None; Secure` + CORS con credentials + dominio mismo-sitio o proxy — toca auth.php, client.js, AuthContext, deploy.
- **Opciones sobre la mesa:**
  a) Documentar como riesgo aceptado (ITP constraint real).
  b) Mitigación intermedia: refresh rotation + reuse detection + TTL corto (backend ya tiene refresh; agregar revocación ante reuso).
  c) Migración completa a cookies httpOnly (requiere que API y PWA compartan site o proxy inverso).
- **Se requiere veredicto del usuario.** Recomendación propia: (b) ahora + (c) cuando el despliegue lo permita.

---

## DELIBERACIÓN DE DEPENDENCIAS — antes de Lote 2 (F-04/F-05)

**Qué asume el Lote 2 del trabajo previo:**
- `edge_devices.group_id` se puebla en registro (`devices.php:209`) — verificado; permite mapear nodo→grupo sin esperar F-01. Limitación conocida: un nodo por grupo (si un nodo sirve varios grupos, el gate es conservador).
- `edge_devices.last_ping` lo escribe `/devices/ping` — verificado en devices.php.
- `security_incidents`, `attendance_incidents`, `notifications` aceptan nuevos `incident_type` — verificar constraint antes de insertar `NODO_OFFLINE`/`SIN_DATOS_NODO`/`ANOMALIA_OPERATIVA`.

**Regla transversal que aplica:** F-04 toca señal física (heartbeat del nodo) → requiere `simulaciones/nodo/` (simula `last_ping`/`is_online`/estados en BD) + test bidireccional en `test/` (nodo cae → no se generan ausencias falsas + se genera NODO_OFFLINE; nodo vuelve → detección normal restaurada).

**Riesgo abierto:** si el gate está mal implementado, suprime incidentes reales → mitigación: flag `NODE_HEALTH_GATE_ENABLED` por escuela (default ON con umbral conservador), y `SIN_DATOS_NODO` visible en dashboard en vez de silencio.

---

## LOTE 2 — F-04 SALUD DE NODO + F-05 ANOMALÍA AGREGADA (2026-09-15)

**Alcance:** `worker_device_health.php` (nuevo), `contingency_lib.php` (nuevo), gates en `worker_absence_detector.php` y `worker_evasion_detector.php`, `fn_calculate_category_risk` en `schema.sql`, `dashboard.php`, `health.php`+`api.php`. Simulador `simulaciones/nodo/NodeSimulator.php` + test `test/api/NodeHealthGateTest.php` (regla transversal cumplida). Verificación: php -l ✅ · 147/147 tests API (11 nuevos) ✅ · 60/60 schema ✅.

### F-04 — Monitor de salud de nodos + blindaje de detectores

- **Qué se hizo:**
  1. `workers/contingency_lib.php` — librería de contingencia: funciones puras (`ctOfflineGroupIds`, `ctNodeTransitions`, `ctIsAnomalyCluster`) + helpers BD (`ctFetchDeviceRows`, `ctGetOfflineGroupIds`, `ctStudentOfflineGroupId`, `ctCreateSecurityIncident`, `ctMarkNoNodeData`, `ctNotifyCoordinators`). Los helpers usan duck-typing (`->prepare()`) para que los tests corran sin extensión pdo en CLI.
  2. `workers/worker_device_health.php` — daemon/cron que compara `last_ping` contra `NODE_OFFLINE_MINUTES` (default 10): nodo caído → `security_incidents` `NODO_OFFLINE` (HIGH) + `edge_devices.status='offline'` + notificación a coordinación (dedup por dispositivo). Nodo recuperado → resuelve el incidente + `status='online'` + aviso de recuperación. **Bidireccional**: cubre caída y recuperación.
  3. Gate en `worker_absence_detector.php::processSchool` — grupos con TODOS sus nodos asignados caídos se saltan la detección y reciben marcador `SIN_DATOS_NODO` (dedup 1/grupo/día).
  4. Gate en `worker_evasion_detector.php::insertEvasionIncident` — choke point único: si el grupo del estudiante está sin cobertura → marca `SIN_DATOS_NODO` y retorna `null`; los 3 call-sites (non-rotating, generateEvasionAlert, recess) omiten notificaciones al recibir `null`.
  5. `health.php` + health embebido en `api.php` monitorean `worker:device_health:last_heartbeat`.
- **Por qué:** ningún worker consultaba `edge_devices.last_ping` → un nodo caído producía ausencias/evasiones falsas masivas y WhatsApps falsos a acudientes (V-486, V-520, V-521, V-525, V-582; habilita V-490, V-496, V-649, V-650).
- **Qué cambió:** los detectores dejan de crear incidentes cuando el nodo del grupo está caído; aparece `SIN_DATOS_NODO`/`NODO_OFFLINE` en `security_incidents` (visibles en auditoría) + notificación a coordinación.
- **Impacto / regla de borde documentada:** un grupo SIN dispositivo asignado NO se suprime (conservador — sin mapeo no se puede saber si hay cobertura). Aproximación por `edge_devices.group_id` hasta que F-01 dé el mapeo aula↔nodo preciso. Flag `NODE_HEALTH_GATE=0` desactiva el gate. `NODE_OFFLINE_MINUTES` configurable. **Nota de despliegue:** el health check ahora exige que `worker_device_health` corra en modo daemon — si no se despliega, health devuelve 503 (intencional: visibiliza que el monitor no corre).
- **Decisión:** auditoría F-04 + veredicto del usuario (hardware real existe). Priorizado sobre F-01 porque `edge_devices.group_id` ya se puebla — seguridad operativa inmediata sin esperar el modelo espacial.

### F-05 — Estado "pendiente de contexto" + anomalía agregada

- **Qué se hizo:**
  1. `worker_absence_detector.php` reestructurado a dos fases por grupo: recolectar candidatos (mismas reglas de exclusión) → decisión cluster → insertar.
  2. Si `ausencias_nuevas ≥ ANOMALY_MIN_ABSENCES` (default 5) **y** `≥ ANOMALY_GROUP_FRACTION` del grupo (default 0.5) → `security_incidents` `ANOMALIA_OPERATIVA` (HIGH) + cada INASISTENCIA se inserta con `metadata_json.pending_context=true` y referencia a la anomalía; **no se envía WhatsApp a acudientes**; coordinación recibe una sola notificación agregada (dedup diaria por grupo).
  3. `schema.sql` `fn_calculate_category_risk` — ambas subqueries de `attendance_incidents` excluyen `pending_context='true'` (CREATE OR REPLACE = migración incluida).
  4. `dashboard.php` listado `absent` expone `pending_context` para que coordinación distinga las que esperan confirmación.
- **Por qué:** una condición externa (nodo caído, evento institucional) se convertía en N incidentes individuales + N WhatsApps falsos sin señal agregada (V-487, V-488, V-489, V-567).
- **Qué cambió:** cluster anómalo → incidente agregado + individuales pendientes de contexto que no alimentan el score de riesgo ni notifican a familias hasta confirmación humana.
- **Impacto:** comportamiento intencional nuevo — las ausencias en cluster no envían WA; los incidentes quedan visibles con `pending_context` en dashboard/auditoría. Trade-off documentado: si el cluster es real (p. ej. ausencia masiva verdadera), los acudientes no reciben WA automático — coordinación decide tras revisar.
- **Decisión:** auditoría F-05. Umbrales por defecto conservadores (5 y 50%); ambos configurables por env.

### Simulador + test de validación (regla transversal)

- **`simulaciones/nodo/NodeSimulator.php`**: genera escenarios de estados físicos del nodo con el mismo shape que `ctFetchDeviceRows` (apagón simple, recuperación, flapping 4-tick, cobertura parcial, dispositivos sin mapear/inactivos, escuela mixta) + `applyToDb`/`simulatePing`/`simulateSilence` para integración con BD real en marcha blanca.
- **`test/api/NodeHealthGateTest.php`**: 11 tests / 35 aserciones —
  - *Bidireccional*: bajada (grupo gated + transición went_offline) y subida (grupo restaurado + transición came_online).
  - *Multidimensional*: umbral exacto de tiempo (≤600s online / >600s offline), ping NULL, multi-nodo por grupo, dispositivos inactivos, sin `group_id`, escuela multi-grupo, dedup de SIN_DATOS_NODO (FakePDO scriptado), umbrales de cluster (mínimo absoluto × fracción × grupo vacío).

---

## LOTE 3 — F-02 PRESENCIA MANUAL + EXENCIÓN BIOMÉTRICA (2026-09-15)

**Alcance:** `schema.sql` (students + permiso), `operations.php`, `students.php`, ambos detectores, `contingency_lib.php`, `PWA/Operation.jsx`, `PWA/Enrollment.jsx`. Simulador `simulaciones/biometria/ManualPresenceSimulator.php` + test `test/api/ManualPresenceTest.php`. Verificación: php -l ✅ · vite build ✅ · 157/157 tests API (10 nuevos) ✅ · 60/60 schema ✅.

### F-02 — Registro manual de presencia + exención biométrica

- **Qué se hizo:**
  1. `schema.sql` — `students.biometric_exempt BOOLEAN DEFAULT FALSE` + `exemption_reason TEXT` (CREATE TABLE + `ALTER TABLE … IF NOT EXISTS` idempotente para bases ya desplegadas) + permiso `operations.registro_manual` otorgado a RECTOR, COORDINATOR, TEACHER, SECRETARY, SECURITY.
  2. `operations.php` — nuevo comando `registro_manual` (`/operations/registro_manual`): exige `student_id` + `reason` obligatorios, estudiante activo, y **bloquea doble registro el mismo día** (409 si ya hay cualquier `INGRESO_%`). Resuelve el punto físico: dispositivo asignado al actor, o el nodo activo más reciente de la escuela (`device_id` es NOT NULL; sin ningún nodo activo → 422 claro). Inserta `biometric_events` `INGRESO_MANUAL` + `attendance_incidents` `REGISTRO_MANUAL` con trazabilidad (actor, motivo, exento) — ambos inserts en `ctRegisterManualPresence()` para testabilidad.
  3. `worker_absence_detector.php` — el query de estudiantes trae `biometric_exempt` y la colección de ausentes omite exentos. La presencia manual ya contaba automáticamente (`INGRESO_%` LIKE cubre `INGRESO_MANUAL`).
  4. `worker_evasion_detector.php` — `insertEvasionIncident` verifica `biometric_exempt` antes de insertar: retorna `null` (los callers ya omiten notificaciones ante `null`).
  5. `students.php` — POST acepta `biometric_exempt` + `exemption_reason` (motivo obligatorio si exento, 400); el listado expone ambos campos.
  6. PWA — `Operation.jsx`: comando "Registro manual" (grade/group/student/reason) para DOCENTE, COORDINADOR, SECRETARIA, PORTERO, RECTOR. `Enrollment.jsx`: checkbox de exención + motivo obligatorio en el paso de datos; si exento, salta el paso de huella y cierra con mensaje de vía manual.
- **Por qué:** sin vía manual, un fallo del sensor o una condición médica dejaba al estudiante sin forma de registrar presencia → ausencias falsas sin recurso (V-592, V-593, V-594, V-595, V-596, V-597).
- **Qué cambió:** presencia manual = presencia real en todos los conteos; estudiantes exentos no generan INASISTENCIA/EVASION por falta de huella; cada registro manual queda auditado con actor+motivo.
- **Impacto:** nuevo endpoint (permiso dedicado, no reutiliza uno existente); `INGRESO_MANUAL` aparece en reportes como ingreso normal; REGISTRO_MANUAL visible en auditoría. Sin breaking changes — columnas con DEFAULT, permiso nuevo no altera roles existentes.
- **Decisión:** auditoría F-02. Elección deliberada: el registro manual exige motivo (trazabilidad ante biometría de menores) y requiere al menos un nodo activo (el evento debe anclarse a un punto físico de la institución).

### Simulador + test (regla transversal — biometría es proceso físico)

- **`simulaciones/biometria/ManualPresenceSimulator.php`**: modela escenarios de fallo de captura → fallback manual, exención, duplicado; `countsAsPresence()` codifica el contrato `INGRESO_%`.
- **`test/api/ManualPresenceTest.php`**: 10 tests / 35 aserciones — bidireccional (manual→presencia en todos los chequeos; exento→sin incidentes), multidimensional (normal/manual/exento/exento+manual/doble registro 409), contratos estáticos (endpoint, permiso por rol, columnas+migración idempotente, skip en ambos detectores).

---

## DELIBERACIÓN DE DEPENDENCIAS — antes de Lote 3b (F-03 dos huellas)

**Qué F-02 deja resuelto para F-03:**
- El campo único `students.biometric_hash` sigue existiendo — F-03 debe migrar a un modelo multi-huella SIN romper lecturas existentes (`has_fingerprint`, `fingerprint_id` en students.php).
- La exención biométrica ya está modelada; el segundo dedo no aplica a exentos.
- `INGRESO_MANUAL` ya es presencia — el simulador biométrico de F-03 (`simulaciones/biometria/`) debe coexistir con el simulador manual.

**Riesgo abierto para F-03:** el edge mantiene templates locales (sensor R307/R558) y el central guarda `biometric_hash` — sincronizar "dos dedos por estudiante" exige protocolo nuevo edge↔central (slot de dedo en enrolamiento y en identificación). Se hará con tabla `student_fingerprints` (slot 1/2) manteniendo `biometric_hash` como compat temporal hasta migrar lectores.

---

## LOTE 3b — F-03 DOS HUELLAS POR ESTUDIANTE (2026-09-15)

**Alcance:** edge C++ (`sqlite_manager`, `main.cpp`, `cloud_manager`, header), `schema.sql` (`student_fingerprints`), `devices.php` (enroll-confirm), `worker_biometric.php` (ingest AES), `students.php` (fingerprint_count), `PWA/devices.js` + `Enrollment.jsx`. Simuladores: `simulaciones/biometria/FingerprintSimulator.php` (PHP) + DevStub C++ + `tests/test_multi_finger.cpp` (edge, Catch2). Verificación: nexo-edge compila ✅ · nexo-tests 66 casos/458 aserciones (6 casos multifinger nuevos) ✅ · 168/168 tests API (11 nuevos) ✅ · vite build ✅.

### F-03 — Modelo multi-dedo (slot 1 | slot 2)

- **Qué se hizo:**
  1. **Edge (persistencia):** nueva tabla SQLite `estudiante_huellas(documento, finger_slot∈{1,2}, huella_id UNIQUE, template_huella cifrado)`; `saveHuella`, `getHuellaCount`, `getHuellaIdsByDocumento`, `huellaSlotExists`; `getEstudianteByHuellaID` busca primero en la tabla nueva y cae a `estudiantes.huella_id` (legacy compat); `getNextHuellaID` es global sobre ambas tablas (sin colisiones); `deleteEstudiante` purga todas las huellas.
  2. **Edge (enrolamiento):** `enrollStudentOnDevice(..., fingerSlot)` — dedo 2 exige que el estudiante exista con dedo 1; re-enrolar un slot lo reemplaza (INSERT OR REPLACE); rollback atómico igual que antes. Ambos handlers `ENROLL_REQUEST` (MQTT + HTTP-polling) leen `payload.finger_slot` (clamp a 1|2). Menú local de secretaría ofrece "segundo dedo de respaldo" tras enrolar el primero. Los 3 caminos de borrado purgan **todos** los huella_ids del caché del sensor.
  3. **Edge→central:** `registerStudentWithFingerprint(..., fingerSlot)` envía `finger_slot` en ambos canales (ingest AES + enroll-confirm fallback).
  4. **Central:** tabla `student_fingerprints` (PK uuid, UNIQUE(student_id,finger_slot), edge_huella_id, device_id, CHECK 1|2). `enroll-confirm` acepta `finger_slot` (default 1, validado) y hace upsert; `biometric_hash` queda como flag de compat (NOT NULL = ≥1 huella). El worker de ingest AES hace el mismo upsert.
  5. **Lecturas:** `students.php` expone `fingerprint_count` (conteo real de slots, con fallback a `biometric_hash` → 1). PWA `requestEnrollment` envía `finger_slot`; `Enrollment.jsx` ofrece "Registrar segundo dedo" tras éxito del primero y hace polling por `fingerprint_count` del slot correspondiente.
- **Por qué:** un solo dedo por estudiante deja al sistema sin respaldo ante dedo dañado/sucio/mal capturado — falla física real en colegios (V-598 y auditoría de enrolamiento).
- **Qué cambió:** un estudiante puede tener 1 o 2 dedos; cualquiera identifica; el borrado/revocación purga ambos; el PWA guía el segundo dedo.
- **Impacto:** cambio estructural pero **backward-compatible** — `students.biometric_hash` sigue poblándose (nada rompe `has_fingerprint`); `estudiantes.huella_id` del edge sigue sirviendo para dedo 1; los sensores que no envíen `finger_slot` funcionan igual (default 1). Los templates nunca salen del edge (igual que antes).
- **Bug real encontrado y corregido durante F-03:** `worker_biometric.php` tenía un `$stmt->execute()` duplicado tras el if/else de enrolamiento — en la rama `has_fingerprint` re-ejecutaba un statement de 4 placeholders con 3 params → PDOException → **rollback del enrolamiento por ingest AES** (solo funcionaba el fallback enroll-confirm). Eliminado.
- **Decisión:** modelo normalizado por slot (no `huella_id_2` en students) — permite extensión a N dedos futura sin otra migración; edge mantiene compat binaria con bases SQLite existentes (CREATE IF NOT EXISTS + fallback a huella_id legacy).

### Simuladores + tests (regla transversal — sensor físico)

- **`simulaciones/biometria/FingerprintSimulator.php`** (PHP, en memoria): enrolar por slot, identificar 1:N por cualquier dedo, re-enrol, revocación, cola offline→sync.
- **`backend/edge/tests/test_multi_finger.cpp`** (C++/Catch2 sobre SqliteManager real): 6 casos — ambos dedos resuelven al estudiante; getNextHuellaID global sin colisiones; slots 1|2, inválidos rechazados; re-enrol de slot reemplaza; delete purga todas; dedo 2 sigue identificando tras revocar dedo 1.
- **`test/api/MultiFingerprintTest.php`** (PHP): 11 tests — bidireccional (identificar por cualquier dedo / revocar elimina ambos), multidimensional (desconocido, slot duplicado, inválido, offline→sync, revocación offline) + contratos estáticos (tabla, CHECK, UNIQUE, enroll-confirm, worker, edge `finger_slot`).

---

## DELIBERACIÓN DE DEPENDENCIAS — antes de Lote 4 (F-01 modelo espacial)

**Qué F-02/F-03 dejan resuelto para F-01:**
- Presencia manual y exención ya son de primer orden — la enforcement espacial (F-01c) no puede confundir presencia manual con "no marcó": `INGRESO_MANUAL` ya cuenta.
- `biometric_events.classroom_id/schedule_id` existen como columnas (schema) — F-01b solo debe empezar a escribirlas.
- El gate de nodo (F-04) usa `edge_devices.group_id` — cuando F-01a pueble `classrooms`/`schedules`, el gate podrá refinar a nivel aula↔nodo si se desea (no obligatorio).

**Riesgo principal de F-01:** activar enforcement espacial sin datos de schedules/classrooms poblados rompería la detección en escuelas sin configurar — por eso se hará flag `SPATIAL_ENFORCEMENT_ENABLED` por escuela, default OFF, solo tras validar que los eventos llevan classroom_id/schedule_id.

---

## LOTE 4 — F-01 MODELO ESPACIAL OPERATIVO, FASEADO (2026-09-15)

**Alcance:** `schema.sql` (schools.spatial_enforcement), `school_config.php` (8 endpoints nuevos), `worker_biometric.php` (resolución espacial + enforcement). Test `test/api/SpatialModelTest.php`. Verificación: php -l ✅ · 176/176 tests API (8 nuevos) ✅ · 60/60 schema ✅.

### F-01a — CRUD de aulas, asignaturas y horarios

- **Qué se hizo:** 8 endpoints en `school_config.php`:
  - `GET/POST/DELETE /school/classrooms` — aulas por escuela (DELETE protegido por FK: 409 si hay horarios/dispositivos asociados).
  - `GET/POST /school/subjects` — catálogo global (POST idempotente por nombre).
  - `GET/POST/DELETE /school/schedules` — franjas grupo+aula+profesor+materia+día+bloque; POST valida pertenencia de grupo/aula/profesor a la escuela y acepta `subject_id` o `subject_name` (auto-crea); DELETE con scope por escuela vía grupo.
- **Por qué:** las tablas `classrooms`/`schedules` existían desde el diseño pero no había forma de poblarlas — todos los reportes espaciales (`wrong-classroom`, `audit_full`) leían NULL (V-587..V-591).
- **Impacto:** permite poblar el modelo espacial vía API sin tocar BD; todo con scope de escuela (RLS + validación explícita). Sin cambios en comportamiento existente.

### F-01b — Los eventos biométricos llevan contexto espacial

- **Qué se hizo:** `worker_biometric.php` resuelve en el momento del ingest: `edge_devices.group_id` → `schedules` del grupo en el día ISO y ventana horaria del evento → `schedule_id` + `classroom_id` escritos en `biometric_events`. Resolución envuelta en try/catch: **nunca bloquea el ingest** (degrada a NULLs como antes).
- **Por qué:** sin esto, la auditoría espacial no tenía datos que auditar.
- **Impacto:** reportes `wrong-classroom`, uso-de-aula y correlaciones horarias pasan de vacíos a reales en cuanto se poblé el CRUD. Timezone explícito `America/Bogota` (consistente con el resto del worker).

### F-01c — Enforcement tras flag por escuela

- **Qué se hizo:** `schools.spatial_enforcement BOOLEAN DEFAULT FALSE` (+ ALTER idempotente). Endpoints `GET/POST /school/spatial-enforcement`: el GET reporta flag + conteos (aulas/horarios/dispositivos mapeados); el POST **rechaza activación (422) si no hay aulas y horarios**. Cuando el flag está ON y el aula física del dispositivo (`edge_devices.classroom_id`) difiere del aula programada para ese bloque → el evento se marca `metadata_json.wrong_classroom=true` con ambas aulas.
- **Por qué:** enforcement sin datos = falsos positivos masivos (la deliberación de Lote 3b lo anticipó).
- **Impacto:** OFF por defecto — cero cambio de comportamiento hasta que la escuela configure su modelo y lo active. ON solo añade metadata, no bloquea eventos (enforcement informativo; el bloqueo físico quedaría a criterio del coordinador leyendo reportes).
- **Decisión:** enforcement informativo (marca + reporte) en lugar de rechazo de evento — un rechazo podría negar asistencia legítima por un desfase de horario; la señal queda en auditoría para acción humana.

### Tests

- **`test/api/SpatialModelTest.php`**: 8 tests/33 aserciones — endpoints CRUD existen con validación tenant; worker resuelve contexto espacial (SQL con ISODOW + ventana); resolución no-bloqueante (try/catch); flag existe + enforcement solo con flag ON (orden del flujo); activación exige datos.

### Pendiente deliberado

- **UI de gestión de horarios en PWA** — queda para el lote de UX (F-21+). Los endpoints ya están disponibles; el reporte `wrong-classroom` de audit_full los consume.
- La escritura espacial hoy ocurre en ingest (worker); el edge no conoce schedules localmente — decisión deliberada: el edge es capa de captura, la resolución es del central (el edge podría no tener horario al día offline).

---

## LOTE 5 — HARDWARE/RESILIENCIA: F-06, F-09, F-10, F-11, F-13 (2026-09-15)

Regla transversal aplicada: cada ítem físico tiene implementación real + simulador integrado + tests bidireccionales/multidimensionales. Simuladores en `simulaciones/{termico,energia,m2m,almacenamiento}/`, tests en `test/api/` y `backend/edge/tests/`.

**Alcance:** edge C++ (`node_monitor.h/.cpp` nuevo, `main.cpp`, `sqlite_manager`, `cloud_manager`), `contingency_lib.php` (`ctTelemetryViolations`/`ctProcessTelemetry`), `devices.php` (ping), `schema.sql` (`edge_devices.telemetry_json`). Simuladores PHP ×4. Tests: `test_node_monitor.cpp` (C++) + `NodeTelemetryTest.php`. Verificación: nexo-edge compila ✅ · nexo-tests 79 casos/528 aserciones ✅ · 189/189 tests API (13 nuevos) ✅ · 60/60 schema ✅.

### F-06 — Telemetría operativa del nodo → central

- **Qué se hizo:** edge `NodeTelemetry` recolecta `clock_drift_s` (vs `received_at` del servidor), `disk_free_mb` (statvfs), `pending_events`, `dlq_count`, `cpu_temp_c` (thermal_zone0), `power_state`, `cell{…}` — todo en el body del `/devices/ping` cada 30s. Central: `edge_devices.telemetry_json` + `telemetry_at` persisten el último reporte; `ctProcessTelemetry` evalúa umbrales (env-configurables `TELEM_*`) → `security_incidents` tipados + dedup (máx 1 sin resolver por tipo/nodo/día) + notificación a coordinación en severidad HIGH.
- **Por qué:** el nodo era una caja negra — sin visibilidad de disco, reloj, cola ni energía hasta que se caía (V-494..V-508).
- **Impacto:** dashboard/incidentes ahora ven salud operativa del hardware; umbrales: temp≥80/90°C, disco<512/128MB, deriva>300s, DLQ>20, power_state≠MAINS, señal<15% o interfaz caída.

### F-09 — Gestión de energía / UPS

- **Qué se hizo:** `PowerMonitor` (sysfs `/sys/class/power_supply/<ups>`, ruta inyectable) lee `status`+`capacity` → estados MAINS/BATTERY/LOW_BATTERY(≤25%)/CRITICAL(≤8%). Main loop detecta transiciones → `AuditTrail` `POWER_BACKUP`/`POWER_RESTORED` + nudge de sync; `shouldShutdown()` → apagado ordenado (flush + SIGTERM) ante batería crítica; banner OLED "BATERIA BAJA". El estado llega al central vía telemetría → incidentes `ENERGIA_RESPALDO`/`ENERGIA_CRITICA`.
- **Por qué:** el nodo tiene UPS real — sin monitor, un apagón prolongado corrompía la SD por corte abrupto y perdía eventos en cola (V-229..V-232, V-512..V-518).
- **Decisión:** sysfs genérico (no GPIO crudo) — cualquier UPS HAT con fuel gauge expone status/capacity; sin UPS detectable → UNKNOWN, nunca alerta ni apaga (falso positivo evitado por diseño).

### F-10 — Conectividad celular M2M

- **Qué se hizo:** `CellularManager` lee `operstate` del interfaz wwan (`/sys/class/net/<if>` — iface configurable por `NEXO_CELL_IFACE`, default wwan0) + parser puro de salida `mmcli -m 0` (registered, signal%, carrier, access tech) → `cell{…}` en telemetría → `SENAL_BAJA`/`SENAL_PERDIDA` en central.
- **Por qué:** el módem M2M real es el transporte primario — sin telemetría, una degradación de señal era invisible hasta perder el nodo (V-204..V-207, V-326).
- **Decisión:** lectura pasiva + parser de métricas (watchdog de "reconexión forzada" queda al sistema de red del OS — ModemManager/systemd-networkd ya lo hacen; el nodo solo OBSERVA y reporta. Reiniciar el módem desde el proceso es privilegio peligroso — documentado, no implementado).

### F-11 — Cifrado de almacenamiento local (campos PII)

- **Qué se hizo:** `encField`/`decField` con esquema `enc:v1:`+AES-256-GCM (mismo que templates). Cifrados en reposo: `estudiantes.nombre`, `telefono_acudiente`, `nombre_acudiente`, y `audit_trail.documento`. `documento` de estudiantes queda en claro — es la clave de búsqueda; cifrarlo sin índice ciego haría imposible resolver huella→estudiante. Migración gradual: lecturas sin prefijo `enc:v1:` se tratan como legacy plaintext (sin pérdida).
- **Por qué:** PII de menores en SD card sin cifrar — robo físico del nodo exponía nombres/teléfonos (V-243..V-245, V-328, V-394).
- **Decisión:** cifrado por campo (no SQLCipher) — SQLCipher requiere rebuild de sqlite enlazado; el esquema por campo cubre la PII alta sin tocar la cadena de build. Sin clave provisionada → plaintext con WARN (misma degradación que ya tenía template_huella).

### F-13 — DLQ del edge con reintento de largo plazo + reporte

- **Qué se hizo:** la DLQ ya existía implícita (`synced=-1` tras 5 intentos, purga a 90d). Añadido: `getDlqCount()`/`requeueDlqItems()`/`getPendingAuditCount()`; SyncWorker reactiva hasta 20 DLQ cada ~1h (attempts=0 → nuevo ciclo); `dlq_count`+`pending_events` van en la telemetría → central alerta `DLQ_BACKLOG`>20.
- **Por qué:** los registros en DLQ eran "purgables pero jamás reintentados" — una falla transitoria del central condenaba eventos a muerte silenciosa (V-509).
- **Impacto:** resiliencia de datos real: los eventos sobreviven caídas prolongadas del central y se sincronizan al volver.

### Simuladores + tests (regla transversal)

- **`simulaciones/energia/UpsSimulator.php`**: máquina de estados eléctrica completa (corte, drenaje, crítico, restauración, apagado).
- **`simulaciones/m2m/CellularSimulator.php`**: fading, pérdida de registro, interfaz caída, cambio de portador.
- **`simulaciones/termico/ThermalSimulator.php`**: curva de temperatura con disipación pasiva (F-12: solo observabilidad — no hay ventilador que controlar).
- **`simulaciones/almacenamiento/StorageSimulator.php`**: disco llenándose, cola de pendientes, backlog DLQ, deriva de reloj.
- **`backend/edge/tests/test_node_monitor.cpp`** (C++): 11 casos — transiciones de UPS con fixtures sysfs, shutdown ordenado, parser mmcli real, operstate, JSON del contrato de telemetría, DLQ sobre SQLite real, cifrado PII roundtrip + legacy.
- **`test/api/NodeTelemetryTest.php`** (PHP): 13 tests — bidireccional (sano→nada / violación→incidente tipado / recuperación→limpio), multidimensional (7 dimensiones × niveles), dedup por día, notify HIGH→coordinación, sensores ausentes nunca alertan, contrato edge→central.

### F-12 — RECLASIFICADO (sin código)

Disipación pasiva confirmada por el usuario: no hay ventilador que controlar. V-310 → dossier físico (F-34). La temperatura del SoC sí se reporta (F-06) como observabilidad/alerta.

---

## DELIBERACIÓN DE DEPENDENCIAS — después de Lote 5

- La telemetría del nodo (F-06) **consuma** el health-check de F-04: un nodo "online" pero con DLQ_BACKLOG o ENERGIA_CRITICA sigue marcando presencia correctamente — el gate de nodo-offline no debe bloquearse por estos incidentes (son de severidad, no de cobertura). Verificado: `worker_device_health` solo usa `last_ping`, no telemetría.
- POWER_BACKUP en edge → la detección de ausencias sigue funcionando con UPS; si el nodo muere por batería agotada, F-04 (offline gate) toma el relevo — la cadena de contingencia es coherente.
- F-11 cambió formato de `estudiantes.nombre` en reposo — SyncWorker envía el valor **descifrado** (decField en read), así el central recibe plaintext igual que antes: sin cambio de contrato edge→central.
- Para Lote 6+ (lógica de negocio): ningún detector toca telemetría — cero acoplamiento nuevo.

---

## BLOQUE A — ENTORNO INTEGRAL DE PRUEBAS + AUDITORÍA DE CONSULTAS (2026-09-16)

### Qué se construyó: `pruebas/` — el sistema REAL completo en Docker

- `pruebas/docker-compose.test.yml` — PostgreSQL 15 (schema auto-aplicado) + PgBouncer + Redis + API (nginx+php-fpm + 6 workers daemon + mosquitto + supercronic, misma topología que producción).
- `pruebas/seed.sql` — datos deterministas: escuela, grupo 6-A, 3 estudiantes (1 exenta biométrica), coordinador/docente/acudiente, dispositivo edge con token conocido.
- `pruebas/runner.py` — CLI maestro: levanta el stack, corre **11 escenarios end-to-end contra el código real**, fuerza acciones manuales (ping, ingreso, salida, corte de luz, señal, DLQ, temperatura), estrés de ingest, resiliencia (matar Redis → ingest sigue por fallback PG). Verificación siempre contra la BD persistida — **un test solo pasa si el estado real lo confirma**; nada se simula a nivel de código.
- `pruebas/README.md` — documentación del entorno, qué cubre y qué le falta.

### Bugs reales encontrados POR el entorno (el entorno ya se paga solo)

1. **`schema.sql` no aplicaba en BD nueva** (2 errores de orden):
   - `student_fingerprints` (F-03) referenciaba `edge_devices` antes de existir → movida tras `edge_devices`.
   - `DELETE/UPDATE` sobre `risk_event_level_mapping`/`risk_event_types` antes de su creación → envueltos en `DO` guardado por `to_regclass`.
   - *Impacto:* el schema "idempotente" solo era idempotente sobre BD existente; un deploy limpio fallaba. Ahora aplica de cero.
2. **`/health` exponía código fuente PHP** (seguridad): `try_files /health.php` servía el archivo estático → `rewrite ... last` para que pase por php-fpm.
3. **PgBouncer roto en 3 frentes** (prod habría fallado igual):
   - Alpine 3.18 no crea el usuario `pgbouncer` → `adduser` en Dockerfile.
   - `port=${DB_PORT:-5432}` — envsubst no soporta `:-` → `${DB_PORT}` + export default 5432 (era 6543, bug latente).
   - `auth_type=md5` + userlist md5 → Postgres 15 exige SCRAM → `auth_type=scram-sha-256` + userlist en claro + `ignore_startup_parameters=options` (PHP PDO envía `options` en el startup packet).
4. **`worker_device_health` nunca arrancaba**: no estaba en el entrypoint ni como servicio → el monitor de nodos de F-04 era código muerto en producción. Añadido a `run_periodic_worker`.
5. **Compose de prod redundante/roto**: `audit-worker`/`biometric-worker` duplican workers ya internos del api y apuntan a rutas inexistentes (`/var/www/html/worker_*.php` — los archivos viven en `workers/`). Servicios que crashearían en deploy. *Documentado; no tocado aún — pendiente decisión: eliminarlos o corregir rutas.*
6. **`NODE_OFFLINE_SECONDS`**: `ctOfflineSeconds` solo leía `NODE_OFFLINE_MINUTES` (mínimo 1 min → test lento) → ahora acepta override en segundos para pruebas/simulación, sin cambiar prod.

### Auditoría de `consultations.php` (reportada "rota")

Subagente + verificación propia de los 29 módulos contra `schema.sql` y el PWA:
- **`reports` roto**: columnas `format`/`status` inexistentes → `file_format` + `metadata_json->>'status'`.
- **`sent_messages` desalineado**: faltaban `type_code`/`direction`/`delivery_status` en el SELECT → alineados ambos branches.
- **Privacidad docente**: `student_tracking_completed` y `sos_emitted` no filtraban por docente → añadido `teacherGroupFilter`/scope por emisor.
- **Filtros ignorados**: `attendance_history` e `incidents` aceptaban group/student/grade pero no los usaban → ahora sí.
- **Autorización**: cualquier usuario autenticado (p.ej. GUARDIAN) podía consultar → ahora exige `consultations.global_view` o `teacher_view`.

### Resultado del entorno

`./runner.py all` → **17/17 verificaciones OK** sobre el sistema real: ping+telemetría persistida, ingest edge AES-GCM, register+huella, ciclo de energía (BATTERY→CRITICAL→notificación a coordinación), dedup de incidentes, pérdida celular, backlog DLQ, ausencia detectada por worker real, registro manual por coordinador, nodo offline → SIN_DATOS_NODO, Redis caído → ingest fail-open → recuperación.

---

## BLOQUE B — ONBOARDING ENFORCEABLE + CRITERIOS DE AVISO DOCENTE (F-18) + MIGRACIONES (2026-09-16)

### Problema

Los flags `schools.onboarding_completed`, `groups_onboarding_completed` y `risk_config_completed` existían en el schema pero **ningún endpoint los verificaba**: una escuela podía operar (registrar inasistencias, emitir comandos) sin haber configurado horarios ni grupos — exactamente lo que el documento prohíbe (§10.5: sin estructura configurada los acontecimientos no son interpretables). Además faltaba el mecanismo del documento §4.5: criterios de aviso configurables por docente.

### Implementado

1. **`requireSchoolOnboarding()`** (`_auth_middleware.php`) — gate central: responde HTTP **428** con `{status:'onboarding_required', missing:[schedule|groups|risk_config]}` a RECTOR/COORDINATOR/TEACHER, y mensaje genérico `"La configuración del sistema para su institución no ha sido completada"` al resto de roles. Aplicado en `operations`, `consultations`, `dashboard`. `/risk/*` y `/school/*` quedan abiertos (son parte del onboarding — bloquearlos crearía deadlock).
2. **`teacher_alert_rules`** (schema + migración 001) — docente define `event_kind` (LATE/ABSENCE/EVASION/EXIT/PERMISSION_EXPIRY) + `threshold_count` + `window_days` + alcance (grupo/estudiante/todos).
3. **`routes/teacher_alerts.php`** — CRUD `/teacher/alert-rules` + `GET|POST /teacher/onboarding`; el docente solo puede crear reglas sobre grupos a los que tiene acceso (`teacher_group_access`).
4. **`workers/worker_teacher_alerts.php`** — daemon que evalúa reglas cada `TEACHER_ALERTS_INTERVAL`s y crea notificación interna al docente con dedup sha256(regla|estudiante|día). Arrancado por el entrypoint, con heartbeat en `/health`.
5. **`users.onboarding_completed`** — el docente marca completado (configuró reglas u omitió explícito).
6. **`auditoria/MIGRACIONES.md` + `sql/migrations/001_*`** — migración idempotente para BD existentes + inventario de discrepancias schema↔código.
7. **Runner**: escenarios `onboarding` (428 genérico/detallado/normal) y `teacher` (regla→evento→notificación→onboarding) — verificados en verde sobre el stack real.

### Semántica del gate (decisión)

- 428 (Precondition Required) porque la operación está **bien formada** pero el
  contexto institucional no existe aún — distinto de 403 (sin permiso) o 503 (caído).
- Los roles configuradores reciben `missing[]` para que el PWA sepa qué wizard abrir.

---

## BLOQUE C — SEMÁNTICA DE HORARIO/EVENTOS + TWILIO COMPLETO (2026-09-16)

### Hallazgos corregidos

1. **`extender_bloque` no suprimía la transición al siguiente bloque**: solo
   actualizaba `expected_exit_time` global → el grupo marcaba evasión al no
   pasar de salón aunque el docente lo retuvo legítimamente (doc §4.5). Ahora
   escribe `metadata_json.merged=true` + `expected_entry_time` = primer bloque
   del día → la ventana extendida suprime las transiciones N→N+1 en el
   detector de evasión (mismo mecanismo que `fusionar_bloque`).
2. **`pedagogica` no suprimía ausencias/evasiones**: solo enviaba WhatsApp a
   acudientes; los detectores seguían marcando INASISTENCIA a todo el grupo en
   salida. Ahora inserta `pedagogical_trip_authorizations` por estudiante
   (departure=NOW, return=param o fin de jornada) y ambos detectores excluyen
   estudiantes en salida vigente.
3. **Registro manual pendiente (doc §9.6)**: nuevo `students.manual_pending_until`
   + comando `/operations/registro_manual_pendiente` (coordinador marca la
   suspensión mientras gestiona el registro) + `registro_manual` la limpia;
   ambos detectores respetan el estado.
4. **Twilio inbound incompleto**:
   - Solo aceptaba `1`/`2` → ahora parsea lenguaje natural
     ("no sabía", "no estaba enterado", "justificada", "enfermo", "cita",
     "permiso") y re-envía el menú si la respuesta es irreconocible.
   - La justificación ahora pide excusa/soporte y avisa que el estudiante debe
     ponerse al día (doc: "pedir excusa/documento + catch-up").
   - Enrutamiento hard-coded COORDINATOR+RECTOR → tabla configurable
     `school_notification_routes` (event_kind ABSENCE_RESPONSE /
     ABSENCE_NO_REPLY) con el mismo default.
   - Sin respuesta del acudiente → nuevo `worker_absence_followup`:
     recordatorios cada `ABSENCE_FOLLOWUP_MINUTES` (máx `ABSENCE_FOLLOWUP_MAX`)
     y escalación a `ABSENCE_NO_REPLY` tras `ABSENCE_ESCALATE_MINUTES`.
     Heartbeat `worker:absence_followup:last_heartbeat` en /health.
5. **Fallback SMS (doc §9.8)**: `worker_twilio` intenta SMS plano vía
   `TWILIO_SMS_FROM` cuando WhatsApp falla (incluido el fallback de template).
6. **`worker_twilio` parecía caído estando vivo**: heartbeat solo se escribía
   al procesar mensajes → ahora también en reposo (cada 10s).
7. **Tests de integración obsoletos**: pedían endpoints inexistentes
   (`/dashboard/metrics`, `/users`, `/operations` GET, `/security/panic/status`,
   `/consultations/students`…). Actualizados a la superficie real
   (`/dashboard/stats`, `/users/by-role`, `/consultation/search`, POST
   `/security/panic`, POST `/telemetry`) + header anti-CSRF que faltaba →
   **41/41** en verde contra el stack real.
8. **Bug de seed en schema.sql**: el comentario decía `admin123` pero el hash
   era bcrypt('password'). Corregido a hash real de `admin123` + escuela demo
   nace con onboarding completo + usuarios de rol que esperan los tests
   (`rector@nexo.edu` … `auxiliar@nexo.edu`).

---

## BLOQUE D — OTA M2M + CRYPTO + MOTOR DE RIESGO (2026-09-16)

### OTA M2M (actualización remota de nodos)

Implementación completa, firmada y resistente a apagones:

- **Central**: `ota_updates` (versión, url, sha256, min_version, activa) +
  `ota_deployments` (auditoría por nodo: OFFERED→DOWNLOADING→STAGED→APPLYING→
  APPLIED|FAILED|ROLLED_BACK) + columnas `edge_devices.app_version` y
  `edge_devices.ota_key` (clave OTA por-dispositivo, generada al registrar/
  provisionar). Endpoints: `GET /devices/ota/check` (X-Device-Token →
  manifiesto firmado HMAC-SHA256 con la clave del dispositivo),
  `POST /devices/ota/report`, `POST /devices/ota/publish`,
  `POST /devices/ota/revoke` (RECTOR/COORDINATOR, gate de onboarding).
- **Edge**: `OtaManager` (C++) con máquina de estados persistida en SQLite —
  reanudación de descarga (Range), verificación SHA-256 + firma HMAC,
  swap atómico con `.bak`, confirmación post-arranque y rollback si el binario
  nuevo no arranca. Integrado en `main.cpp` (onBoot + hilo periódico
  `ota_check_interval_s`, default 30 min).
- **Simulador**: `simulaciones/ota/OtaNodeSimulator.php` — apagón a mitad de
  descarga, payload adulterado, firma manipulada, anti-rollback, rollback.
- **Tests**: `test/api/OtaUpdateTest.php` (semver, firma/verificación, reanudación
  tras apagón, rollback) + `tests/test_ota_manager.cpp` (transiciones de estado
  persistentes) + escenario `ota` en el runner integrado (publicar→oferta
  firmada→verificación→APPLIED→app_version actualizado→anti-rollback) — 6/6.

### Crypto-at-rest (nodo robado → datos ilegibles)

Verificado/documentado: la clave AES-256 del nodo se guarda cifrada con una
clave derivada del serial de hardware de la RPi (paquete `NXE1`), los campos
PII y plantillas biométricas viajan `enc:v1:` AES-256-GCM, y la clave vive en
memoria con `mlock`+`OPENSSL_cleanse`. Una imagen de disco robada no puede
descifrarse sin el hardware exacto.

### Motor de riesgos

- `auditoria/MOTOR_RIESGO.md` — documentación formal: ontología de 5 niveles,
  máquina de estados, qué configura la institución (umbrales, vida media,
  cooldowns, mapeo evento→nivel, combinaciones, textos) vs lo protegido.
- **Fix**: `schools.risk_config_completed` nunca se marcaba TRUE → la escuela
  quedaba bloqueada en 428 para siempre. Ahora `POST /risk/policy` lo cierra.
- Los incidentes `pending_context` de ANOMALIA_OPERATIVA (F-05) no alimentan
  el motor hasta triaje humano.

### Estado del entorno integrado

`./runner.py all` → **24/24 verificaciones OK** + escenario `ota` 6/6 +
PHPUnit integration **41/41** + unitarios **249** (incl. 4 OTA). Workers con
heartbeat en /health: twilio, biometric, absence, evasion, permission_status,
device_health, teacher_alerts, absence_followup.

---

## CIERRE DE VERIFICACIONES — TERCERA PASADA (2026-09-17)

**Alcance:** re-auditoría ítem por ítem de las 652 verificaciones tras la
modernización (Bloques A-D), corrección de los remanentes y actualización
honesta de `ANALISIS_AUDITORIA.md` (v2 → v3: 586✅/22⚠️/0❌/44◻️).

**Verificación:** PHPUnit 262 tests / 3361 aserciones (incl. nuevo
`CierreVerificacionesTest` 9/9) · edge Catch2 84 casos / 543 aserciones ·
`runner.py all` **36/36** en stack real (PostgreSQL+Redis+PgBouncer+API+8
workers) incl. escenarios nuevos `horario` (acudiente recibió WhatsApp
HORARIO) y `reconcile` (INASISTENCIA resuelta + REAPARICION_TARDIA) ·
endpoint integration 42 PASS · onboarding assignments 9/9.

### C-01 — `horario` notifica a acudientes (V-030/045)
El comando persistía `daily_schedule_config` pero no avisaba a nadie; la PWA
afirmaba lo contrario. Ahora encola WhatsApp tipo `HORARIO` por acudiente del
grupo (catalogo en schema+migración). Verificado en stack: mensaje generado.

### C-02 — Retorno de permiso valida el espacio (V-031/063/009)
`class_exit_authorizations` guarda `schedule_id`/`classroom_id` esperados al
autorizar; `worker_permission_status` valida el aula del evento de retorno y
marca `RETURN_WRONG_SPACE` si difiere; `actual_return_time` se persiste.

### C-03 — Reconciliación INASISTENCIA (V-530/531/574)
Nueva lib compartida `backend/api/lib/attendance_reconcile.php`
(`nexoReconcileAbsence`): un `INGRESO_*` resuelve la INASISTENCIA abierta del
día y genera `REAPARICION_TARDIA` con el aula/bloque donde apareció. Llamada
desde el ingest síncrono de `api.php` **y** el worker (idempotente).
Bug real encontrado y corregido: la ventana "hoy Bogotá" estaba desfasada
~5 h (`timestamptz >= (NOW() AT TIME ZONE)::date` evaluado en TZ de sesión);
ahora compara fechas locales `(x AT TIME ZONE 'America/Bogota')::date`.

### C-04 — Seguimiento derivable (V-069/151)
`student_tracking` gana `dependency`, `assigned_to_user_id`, `origin_type`,
`origin_id`. `fn_evaluate_student_risk` y `RiskEngineV3::changeEscalationState`
instancian tracking automáticamente cuando una alerta pasa a SEGUIMIENTO.
Nuevo `POST /tracking/derive` (alert_id|incident_id → tracking idempotente).

### C-05 — Combinaciones de riesgo reales + detect_only (V-166/378/436/564/377)
`fn_evaluate_student_risk` evalúa `risk_combination_rules` (condiciones por
categoría con conteo/ventana/nivel mínimo/distintos); `risk_rules.detect_only`
registra `RISK_DETECTED_*` sin generar `risk_alert`. `createPolicyVersion`
persiste combos y el flag.

### C-06 — Reloj y horario sincronizados al edge (V-493/494/495/183/196)
`/devices/ping` devuelve `server_time`, `resync_required` (drift > umbral,
default 300 s) y `schedule{sched_*}` derivado de `school_schedule_config` de
la jornada del grupo del nodo. El edge ejecuta `forceTimeResync()` y persiste
las franjas que usa `checkLateStatus` (ya no hardcodeadas).

### C-07 — Config-vs-realidad y gestión de nodo (V-614/523-535)
`GET /admin/config-check` reporta divergencias (nodo sin aula, grupo sin
schedule, schedule sin aula, docente sin asignación, nodo offline con
config). `POST /devices/reassign` (reubica grupo/aula) y
`POST /devices/reprovision` (rota device_token+ota_key).

### C-08 — Edge: energía, térmica, retención (V-515/310/427)
`INotification::notifyPowerState` + `setFan` (GPIO23): patrones
LED/buzzer por estado MAINS/BATTERY/LOW/CRITICAL; ventilador con histéresis
configurable (`fan_on_temp_c`/`fan_off_temp_c`); retenciones
`retention_days_synced`/`retention_days_dlq` configurables.

### C-09 — OTA endurecido
Anti-rollback **local** (oferta ≤ versión instalada → REJECTED),
`app_version` persistido en config local tras APPLIED, restauración `.bak`
por boot-loop (>5 arranques sin confirmar → ROLLED_BACK) y wrapper
`scripts/nexo-edge-run.sh` que restaura `.bak` si el binario nuevo muere
antes de arrancar (bandera `.pending`).

### C-10 — PII local completa (V-243/245)
`Encryption::keyedHash` (HMAC-SHA256). `estudiantes.documento` se guarda como
clave derivada; el valor real va cifrado en `documento_enc`; migración
automática de filas en claro; lectura con dual-match (clave o legado).
`audit_trail.event` también cifrado. Tests F-11 reforzados (documento en
reposo = 64 hex, no legible).

### C-11 — RLS faltantes + permiso de secretaría
RLS en `teacher_alert_rules` y `school_notification_routes`
(schema + migración 002). `operations.citacion` para SECRETARY (V-127).

### C-12 — Migración consolidada
`sql/migrations/002_cierre_verificaciones.sql`: idempotente, cubre todos los
cambios anteriores para bases ya provisionadas (no solo schema.sql).

### Pendiente honesto (no oculto)
- `school_notification_routes` no cubre el 100% de tipos de notificación.
- `extender_bloque` sigue siendo RECTOR/COORDINATOR (docente: fusionar).
- Consentimiento/base jurídica ARCO no modelados.
- Real-time por polling (documentado en FRONTEND_PENDIENTE).
- Estrés: `runner.py stress` existe pero sin umbrales de latencia.
- ◻️: UPS/módem/ventilador/LEDs requieren hardware físico para validarse.

### C-13 — Bugs latentes hallados por la corrida de cierre (stack real)

La re-verificación sobre el stack completo expuso cuatro bugs que las
verificaciones estáticas no veían:

- **`getDbConnection()` inexistente** → crash periódico de
  `worker_absence_detector`, `worker_biometric` y `worker_evasion_detector`
  al reconectar PDO. Corregido a `require core/db.php` (recrea `$pdo`), el
  patrón que ya usaba `worker_permission_status`.
- **`securityLog()` indefinido en contexto worker** → `worker_absence_detector`
  y `worker_absence_followup` incluyen `routes/operations.php` por
  `enqueueTwilioJob()`; su catch llamaba `securityLog()` (definida en
  api.php) → fatal. Añadido shim guardado con `function_exists`.
- **`set_config(..., true)` sin transacción** (autocommit) en
  `worker_absence_followup` y `worker_teacher_alerts` → el contexto RLS moría
  al final del statement. Cambiado a scope de sesión (`, false`).
- **`worker_twilio` atrapado en PG fallback tras recuperarse Redis** → no
  re-sondeaba y perdía heartbeat en `/health`. Ahora re-sondea cada 60 s y
  vuelve a modo REDIS (`TWILIO_WORKER_REDIS_RECOVERED`).
- **Ventana "hoy Bogotá" desfasada ~5 h** (detalle en C-03).
- `strpos(null)` deprecated en `operations.php:173` → `$cleanPath ?? ''`.

### C-14 — Cierre por simulación y modelo de datos (v4)

- **Tamper físico (V-333/397/398)**: `TamperMonitor` edge (GPIO/sysfs inyectable,
  flanco cerrado→abierto dispara una vez) → `TAMPER_OPEN` en audit trail local +
  telemetría `tamper_open` → `security_incidents` HIGH + notificación a
  coordinación. Simulado end-to-end: Catch2 `[tamper]` + `runner.py tamper`.
- **Autonomía UPS (V-231/517)**: `runner.py ups_jornada` — descarga 90%→5% con
  ingest verificado en cada estado; ENERGIA_RESPALDO/ENERGIA_CRITICA generadas.
- **Consentimiento/ARCO (V-342/344)**: `students.consent_status/channel/
  recorded_at/recorded_by/document_ref` + `POST /students/{id}/consent`;
  REVOCADO → `biometric_exempt=TRUE` automático.
- **Tracking workflow (V-150)**: CHECK enum + `POST /tracking/close`
  (resuelto|descartado|escalado + nota de cierre + auditoría).
- **Origen de notificaciones (V-028)**: `notifications.origin_type/origin_id` +
  trigger `fn_notifications_origin` (extrae ids de metadata_json).
- **Estrés con umbrales (V-607-609)**: `runner.py stress` ahora verifica 0×5xx,
  ≥95% aceptados (429 del limiter = protección, no fallo), p50≤300/p95≤1500/
  max≤5000ms, persistencia≥90%. Rate limit configurable: `RATE_LIMIT_MAX`/
  `RATE_LIMIT_WINDOW`.
- **extender_bloque a TEACHER (V-046/077)** + misma corrección de ventana
  Bogotá aplicada a los dedup de `contingency_lib`, `worker_absence_detector`,
  `worker_evasion_detector`.

### C-15 — Políticas de acción y routing configurables (v5)

- **`school_action_policies`** (school_id, event_type, action, enabled): cada
  escuela activa/desactiva actuaciones por evento. Consumido en:
  - `insertEvasionIncident` — `EVASION_INTERNA` desactivable (V-013/041/058).
  - `enqueueTwilioJob` — `WHATSAPP_<TYPE>` desactivable (V-406; cubre
    INASISTENCIA, HORARIO, CITACION, INCIDENTE, PEDAGOGICA, NOTIFY_ROLE…).
  Verificado en vivo: OFF→sin mensaje, ON→mensaje.
- **`lib/notify_routing.php`**: `nexoRouteUserIds()` consulta
  `school_notification_routes` (event_kind → roles destino, default
  COORDINATOR+RECTOR). Aplicado en: PERMISO, SALIDA (+RECTOR), PEDAGOGICA,
  SEGUIMIENTO (+RECTOR), INCIDENTE (default), NODE_TELEMETRY,
  EVASION_INTERNA, ABSENCE_NO_REPLY, PERMISSION_EXPIRED (nueva notificación
  al vencer permiso, dedup por authorization_id).
- **`GET /events/stream`** (SSE, V-110 backend): push real de notifications
  verificado en vivo (evento recibido en el stream a los ~2s). Requiere
  consumo `EventSource` en PWA — pendiente en FRONTEND_PENDIENTE.
- Rate limit global configurable: `RATE_LIMIT_MAX`/`RATE_LIMIT_WINDOW`.
- **Requisito de despliegue (V-389)**: el cifrado-at-rest de PostgreSQL es
  decisión de infra (LUKS/dm-crypt o cifrado del volumen Docker) — documentado
  como requisito de producción, no implementable en código.
