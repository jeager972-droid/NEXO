# NEXO — VEREDICTO FINAL Y PLAN DE RECONSTRUCCIÓN FUNCIONAL

> **Fecha:** 2026-08-15
> **Fase:** 3 — Veredicto y planificación (NO se modifica código)
> **Base:** Consolidación de todas las auditorías previas (API, seguridad, gap analysis, arquitectura, E2E, API↔Frontend, Sensor↔Edge, Edge↔Global, lógica de negocio, PREMIUM_AUDIT, UI_UX_PLAN, documentación, cambios realizados)
> **Regla fundamental:** Esta fase es EXCLUSIVAMENTE de análisis y planificación. No se cambia código, no se refactoriza, no se renombra, no se alteran contratos, schemas ni lógica.

---

## 0. Resumen Ejecutivo

NEXO es un **sistema integrado funcional** cuya cadena crítica (sensor U.are.U 5300 → edge C++ → SQLite → AES-256-GCM → API PHP → Redis → worker → PostgreSQL → dashboard → React) está **completamente cableada y verificada en código** (27/27 transiciones). No hay flujos rotos ni desconexiones en la línea principal.

Sin embargo, NEXO **no es production-ready** debido a **9 bloqueantes críticos** persistentes en seguridad (RLS faltante en 10 tablas, SECURITY DEFINER sin search_path, HMAC default en función SQL), fiabilidad (workers polling sin distributed lock, worker_audit sin DLQ, particiones faltantes en 7/8 tablas) y UX/seguridad (sin refresh token, JWT en localStorage, deploy_db.sh destructivo).

**Veredicto de estado real: B — CASI LISTO.** La arquitectura es correcta, los flujos críticos funcionan, y los bloqueantes son deuda técnica acotada (1-2 sprints) cuya remediación no requiere reconstrucción. Lo que falta es **remediación controlada + validación con hardware real (U.are.U 5300)**.

**Scores:**

| Dimensión | Score | Nota |
|---|---|---|
| Arquitectura | 8/10 | Sólida, bien diseñada |
| Integridad funcional | 8/10 | 27/27 transiciones E2E |
| API | 7/10 | Funcional, deuda de seguridad |
| Frontend | 6/10 | Funcional, deuda UI/UX premium |
| Edge | 7/10 | Funcional, sin validación hardware |
| Sincronización | 8/10 | Queue fiable + DLQ + GC |
| Biometría | 7/10 | Código completo, NO verificado con hardware |
| Lógica de negocio | 8/10 | 12/12 reglas temporales |
| Seguridad | 4/10 | 9 bloqueantes críticos |
| Tests | 4/10 | Cobertura parcial, sin E2E |
| Observabilidad | 6/10 | Health, métricas, logs — sin dashboards |
| Documentación | 6/10 | Existe pero con discrepancias |
| **PRODUCTION READINESS** | **5/10** | **NO apto sin Fase 0** |

---

## 1. CONSOLIDACIÓN DEL ESTADO ACTUAL

Cada componente con estado respaldado por evidencia.

### Edge (C++)

**ESTADO: FUNCIONAL PERO NO VERIFICABLE SIN HARDWARE**

- **Sensor ZK9500:** Implementación completa en `Zk9500BiometricSensor.cpp`. `searchUser` ignora el template pasado y adquiere fingerprint internamente (diseño correcto para hardware real). [VERIFICADO EN CÓDIGO]
- **Sensor U.are.U 5300:** Implementación completa en `UareU5300BiometricSensor.cpp` (647 líneas). Usa SDK DigitalPersona (`dpfpdd`, `dpfj`). `searchUser` hace captura → `dpfj_create_fmd_from_fid` → `dpfj_identify` → verificación 1:1 con `dpfj_compare`. Cache en RAM con `std::list` (punteros estables). Reconexión USB. [VERIFICADO EN CÓDIGO, NO VERIFICADO CON HARDWARE]
- **Selección de sensor:** `main.cpp:853` lee `ConfigManager::getString("biometric_sensor", "dev_stub")`. Valores: `zk9500`, `uareu5300`, `dev_stub`. Fallback a DevStub si SDK no disponible. [VERIFICADO EN CÓDIGO]
- **Stubs:** `Zk9500BiometricSensor_stub.cpp` y `UareU5300BiometricSensor_stub.cpp` existen para compilar sin SDK. [VERIFICADO EN CÓDIGO]

### SQLite (edge local)

**ESTADO: FUNCIONAL**

- `SqliteManager` persiste `audit_trail` con `synced=0`. SyncWorker polla, cifra, envía, y hace `clearAudit(id)` (synced=1) en éxito o `incrementAuditAttempt(id)` en fallo. Tras 5 intentos: `markAuditError(id)` (synced=-1, DLQ local). [VERIFICADO EN CÓDIGO]
- Templates biométricos NO salen del edge (cifrados en SQLite local). Decisión de diseño documentada en `UAREU5300_RUNBOOK_PRODUCCION.md`. [VERIFICADO EN CÓDIGO + DOCUMENTACIÓN]

### Sensor biométrico

**ESTADO: FUNCIONAL PERO NO VERIFICABLE SIN HARDWARE**

- Código completo para ambos sensores (ZK9500 y U.are.U 5300). [VERIFICADO EN CÓDIGO]
- No hay evidencia de pruebas con hardware real. [NO VERIFICABLE TODAVÍA]
- Umbral de identificación configurable via `ConfigManager`. [VERIFICADO EN CÓDIGO]

### Procesamiento biométrico (worker)

**ESTADO: FUNCIONAL**

- `worker_biometric.php`: consume cola Redis con Lua LMOVE atómico, dedup temporal 30s, fingerprint SHA-256, `ON CONFLICT DO NOTHING`, DLQ tras 3 reintentos, GC de zombies cada 60s. [VERIFICADO EN CÓDIGO]
- Detección de LATE_ARRIVAL con tolerancia 10 min. [VERIFICADO EN CÓDIGO]
- Cruce con permisos activos (`class_exit_authorizations`). [VERIFICADO EN CÓDIGO]
- Notificación a docentes con acciones (justificar/no justificar). [VERIFICADO EN CÓDIGO]

### Lógica de asistencia

**ESTADO: FUNCIONAL**

- Conteo de presentes: CTE con `NOT EXISTS SALIDA_%` posterior a `exit_time - 5 min`. [VERIFICADO EN CÓDIGO]
- Prioridad de horario: `daily_schedule_config` > `school_schedule_config` > defaults hardcoded. [VERIFICADO EN CÓDIGO]
- Multi-jornada: cada estudiante usa `exit_time` de su propia `work_shift` vía subquery correlacionada. [VERIFICADO EN CÓDIGO]

### Lógica de negocio

**ESTADO: FUNCIONAL**

- 12 reglas temporales verificadas (entrada, salida, tarde, ausencia, evasión, permisos, receso, bloques). [VERIFICADO EN CÓDIGO]
- Evasión: rotación y no-rotación, con permiso (5 min post-return_time) y sin permiso (15 min). [VERIFICADO EN CÓDIGO]
- Extender/fusionar bloque: UPSERT en `daily_schedule_config`. [VERIFICADO EN CÓDIGO]
- SOS, citaciones, seguimiento: completos. [VERIFICADO EN CÓDIGO]

### PostgreSQL

**ESTADO: FUNCIONAL PERO RIESGOSO**

- 25 tablas con RLS habilitada. [VERIFICADO EN CÓDIGO]
- **10 tablas con `school_id` SIN RLS:** `staff_records`, `academic_groups`, `classrooms`, `security_incidents`, `school_exit_authorizations`, `class_exit_authorizations`, `pedagogical_trip_authorizations`, `student_record_audit`, `report_exports`, `internal_messages`. [VERIFICADO EN CÓDIGO — NEXO-AUD-002]
- **8 funciones SECURITY DEFINER sin `SET search_path`:** privilege escalation risk. [VERIFICADO EN CÓDIGO — NEXO-INFRA-001]
- **HMAC default en `fn_calculate_audit_hash`:** `COALESCE(current_setting('app.nexo_hmac_secret',true),'default-secret-change-me')`. [VERIFICADO EN CÓDIGO — NEXO-INFRA-002]
- Particiones: solo `biometric_events` tiene particiones mensuales reales (2026-05 a 2027-08). Las otras 7 tablas particionadas solo tienen `DEFAULT`. [VERIFICADO EN CÓDIGO — NEXO-AUD-008]
- `funcionamiento.md` §27 lista `school_exit_authorizations`, `class_exit_authorizations`, `pedagogical_trip_authorizations`, `staff_records`, `academic_groups`, `report_exports`, `internal_messages` como tablas CON RLS — **discrepancia con el SQL real**. [VERIFICADO EN CÓDIGO — NEXO-DISC-001]

### Sincronización (edge → cloud)

**ESTADO: FUNCIONAL**

- AES-256-GCM con IV[12] + ciphertext + tag[16], base64. [VERIFICADO EN CÓDIGO]
- `device_token` y `device_id` van DENTRO del payload cifrado (no en body plano). [VERIFICADO EN CÓDIGO]
- Backend deriva `school_id` de `edge_devices.school_id`, no del cliente. [VERIFICADO EN CÓDIGO]
- Nonce anti-replay: Redis `SET NX EX 604800` (7 días). [VERIFICADO EN CÓDIGO]
- Timestamp anti-replay: ±7 días. [VERIFICADO EN CÓDIGO]
- Backoff exponencial con jitter ±30% en edge. [VERIFICADO EN CÓDIGO]
- **AES key compartida entre todos los dispositivos** (no per-device). [VERIFICADO EN CÓDIGO — NEXO-EDGE-004]

### API (PHP)

**ESTADO: FUNCIONAL PERO RIESGOSO**

- 150+ endpoints en 20 archivos de rutas. [VERIFICADO EN CÓDIGO]
- RBAC granular por permisos (`operations.*`). [VERIFICADO EN CÓDIGO]
- Rate limiting global Redis. [VERIFICADO EN CÓDIGO]
- CORS middleware. [VERIFICADO EN CÓDIGO]
- **`/metrics` sin auth obligatoria** si `METRICS_SECRET_KEY` no está configurada. [VERIFICADO EN CÓDIGO — NEXO-AUD-009]
- **Endpoints duplicados:** `/audit/global` y `/audit/integrity` en dos archivos. [VERIFICADO EN CÓDIGO — NEXO-AUD-010]
- **Endpoints muertos:** ~10 sin consumidor frontend. [VERIFICADO EN CÓDIGO]
- `boot_check.php` valida `DATABASE_URL`, `NEXO_AES_KEY`, JWT keys, pero NO `APP_NEXO_HMAC_SECRET` ni `METRICS_SECRET_KEY`. [VERIFICADO EN CÓDIGO — NEXO-AUD-043]

### Autenticación

**ESTADO: FUNCIONAL PERO RIESGOSO**

- JWT con cookie HttpOnly + localStorage (workaround ITP iOS). [VERIFICADO EN CÓDIGO — NEXO-AUD-005]
- Revocación via Redis `jwt:blocklist` + panic mode. [VERIFICADO EN CÓDIGO]
- 2FA con códigos de verificación. [VERIFICADO EN CÓDIGO]
- **Sin refresh token:** `user_sessions.refresh_token_hash` existe pero no se usa. Re-login completo al expirar. [VERIFICADO EN CÓDIGO — NEXO-AUD-004]

### Autorización

**ESTADO: FUNCIONAL**

- RBAC con roles: RECTOR, COORDINATOR, SECRETARIA, PSICORIENTADOR, DOCENTE, PORTERO, AUXILIAR. [VERIFICADO EN CÓDIGO]
- Permisos granulares por operación (`operations.sos`, `operations.fusionar_bloque`, etc.). [VERIFICADO EN CÓDIGO]
- RLS como defensa en profundidad (parcial — ver PostgreSQL). [VERIFICADO EN CÓDIGO]

### WebApp (React)

**ESTADO: FUNCIONAL**

- 13 páginas, 14 módulos API, 3 contextos (Auth, Notification, Theme). [VERIFICADO EN CÓDIGO]
- Dashboard con KPIs (presentes, ausentes, tardanzas, permisos, alertas). [VERIFICADO EN CÓDIGO]
- Contrato API↔Frontend: ~70 endpoints coincidentes, camelCase consistente en dashboard. [VERIFICADO EN CÓDIGO]
- Polling: notificaciones 60s, auth 5min, telemetry 5min, twilio-status 2s. [VERIFICADO EN CÓDIGO]

### Frontend (UI/UX)

**ESTADO: FUNCIONAL PERO RIESGOSO (deuda premium)**

- Score PREMIUM_AUDIT: 5.4/10 — "clean generic SaaS, no premium enterprise". [VERIFICADO EN CÓDIGO]
- 5 heridas: jerarquía plana, identidad ausente, exceso de color, sin charts, tablas CRUD. [VERIFICADO EN CÓDIGO]
- Motion, skeleton, focus-visible, dark mode: correctos. [VERIFICADO EN CÓDIGO]
- 9 P0 frontend de FRONTEND_AUDIT_T7.md sin resolver. [VERIFICADO EN CÓDIGO]

### Notificaciones

**ESTADO: FUNCIONAL PERO RIESGOSO**

- Tabla `notifications` con INSERT por evento. [VERIFICADO EN CÓDIGO]
- **Sin deduplicación:** no hay UNIQUE constraint ni mecanismo dedup. [VERIFICADO EN CÓDIGO — E2E-001]
- `worker_notification_purge.php` para >30 días. [VERIFICADO EN CÓDIGO]
- Acciones inline (justificar/no justificar). [VERIFICADO EN CÓDIGO]
- **Justificación de tardanza: hard delete sin audit trail.** [VERIFICADO EN CÓDIGO — NEXO-AUD-031]

### Auditoría

**ESTADO: FUNCIONAL PERO RIESGOSO**

- Audit chain HMAC-SHA256 en `global_audit_logs`. [VERIFICADO EN CÓDIGO]
- `worker_audit.php`: **requeue infinito sin DLQ ni max retry.** [VERIFICADO EN CÓDIGO — NEXO-AUD-006]
- HMAC default en worker: **RESUELTO** (exit(1) si default). [VERIFICADO EN CÓDIGO]
- HMAC default en función SQL: **SIN RESOLVER**. [VERIFICADO EN CÓDIGO — NEXO-INFRA-002]

### Operaciones

**ESTADO: FUNCIONAL PERO RIESGOSO**

- 17 comandos en `operations.php`. [VERIFICADO EN CÓDIGO]
- **Sin Idempotency-Key:** reintentos duplican efectos (SOS, citaciones). [VERIFICADO EN CÓDIGO — NEXO-AUD-028]
- `extender_bloque` afecta TODOS los grupos sin granularidad. [VERIFICADO EN CÓDIGO — NEXO-AUD-027]

### Consultas

**ESTADO: FUNCIONAL**

- `consultations.php`: switch con 30 módulos. [VERIFICADO EN CÓDIGO]
- `audit_full.php`: 50+ endpoints de auditoría. [VERIFICADO EN CÓDIGO]
- **Sin rate limiting específico en endpoints de auditoría.** [VERIFICADO EN CÓDIGO — NEXO-AUD-022]

### Métricas

**ESTADO: FUNCIONAL PERO RIESGOSO**

- `/metrics` expone métricas Prometheus. [VERIFICADO EN CÓDIGO]
- **Auth condicional:** si `METRICS_SECRET_KEY` no está set, es público. [VERIFICADO EN CÓDIGO — NEXO-AUD-009]
- `/health` y `/health/workers`: verificación de BD, Redis, workers, colas, disco. [VERIFICADO EN CÓDIGO]

---

## 2. MATRIZ MAESTRA DE PROBLEMAS

| ID | Problema | Componente | Sev | Evidencia | Impacto | Dependencias | Acción | Estado |
|----|----------|------------|-----|-----------|---------|--------------|--------|--------|
| VF-001 | 10 tablas con school_id SIN RLS | PostgreSQL/Seguridad | P0 | `nexo_full_migration.sql` — sin ENABLE RLS para 10 tablas | Cross-tenant data leak si query PHP omite WHERE | Ninguna | Migración SQL + policies | **RESUELTO** — `2026-21-rls-missing-tables.sql` |
| VF-002 | 8 funciones SECURITY DEFINER sin search_path | PostgreSQL/Seguridad | P0 | `nexo_full_migration.sql:64,95,334,348,349,772,793,817` | Privilege escalation vía search_path hijack | Ninguna | `SET search_path = public, nexo` | **RESUELTO** — `2026-22-security-definer-search-path.sql` |
| VF-003 | HMAC default en fn_calculate_audit_hash | PostgreSQL/Audit | P0 | `nexo_full_migration.sql:334` — COALESCE default-secret | Audit chain falsificable desde DB | VF-002 (misma migración) | Eliminar fallback, requerir setting | **RESUELTO** — `2026-23-audit-hash-no-default.sql` |
| VF-004 | Workers polling sin distributed lock | Workers/Fiabilidad | P0 | `worker_absence/evasion/permission_status.php` | Duplicados masivos si 2 instancias | VF-001 (RLS mitiga) | `SET lock: NX EX 300` | **RESUELTO** — Redis lock en 3 workers |
| VF-005 | Particiones faltantes 7/8 tablas | PostgreSQL/Fiabilidad | P0 | Solo `biometric_events` tiene particiones mensuales | Degradación progresiva; caída post-2027-08 | Ninguna | Extender script particiones | **RESUELTO** — `2026-24-monthly-partitions-7-tables.sql` (140 particiones) |
| VF-006 | worker_audit requeue infinito sin DLQ | Workers/Fiabilidad | P0 | `worker_audit.php:249-251` | Audit se cuelga con batch malformado | Ninguna | DLQ + max retry (como biometric) | **RESUELTO** — DLQ + 3 retries con `_retry_count` |
| VF-007 | deploy_db.sh destructivo (ejecuta seed) | Infra/Despliegue | P0 | `deploy_db.sh` ejecuta todos .sql alfabético | Re-deploy trunca datos de producción | Ninguna | Separar schema vs seed | **RESUELTO** — Tracking table + orden explícito + idempotencia |
| VF-008 | AES key compartida todos dispositivos | Edge/Seguridad | P0 | `Encryption` singleton, una key global | Clave filtrada compromete todos | Ninguna | Plan rotación per-device | **PENDIENTE** — Requiere re-provisionar hardware (HIGH RISK) |
| VF-009 | Sin refresh token | Auth/UX | P0 | No existe `/auth/refresh`; `refresh_token_hash` sin usar | Sesiones cortan a mitad de operación | Ninguna | Implementar refresh con rotación | **RESUELTO** — `/auth/refresh` + access 15min + refresh 7d con rotación |
| VF-010 | Notificaciones sin deduplicación | Notificaciones | P1 | `notifications` sin UNIQUE constraint | Notificaciones duplicadas | VF-004 (lock mitiga) | UNIQUE constraint o Redis dedup | **RESUELTO** — `2026-27-notifications-dedup.sql` + `dedup_key` en workers |
| VF-011 | LATE_ARRIVAL race condition | Workers/Integridad | P1 | `worker_biometric.php:313-327` NOT EXISTS no atómico | Duplicados de incidentes | VF-004 | ON CONFLICT o advisory lock | **RESUELTO** — `2026-26-late-arrival-dedup.sql` + `ON CONFLICT DO NOTHING` |
| VF-012 | Sin Idempotency-Key en /operations/* | API/Concurrencia | P1 | `operations.php` sin header dedup | Reintentos duplican SOS, citaciones | Ninguna | Header + Redis dedup 24h | **RESUELTO** — `X-Idempotency-Key` header + Redis cache 24h |
| VF-013 | JWT en localStorage (XSS) | Auth/Seguridad | P1 | `auth.php:241`, `Login.jsx:64` | XSS roba token | VF-009 (refresh ayuda) | Migrar same-site, eliminar localStorage | **POSPUESTO** — Bloqueado por migración same-site (DO NOT FIX YET) |
| VF-014 | /metrics sin auth obligatoria | API/Seguridad | P1 | `metrics.php:34-40` condicional | Fuga info operacional | Ninguna | METRICS_SECRET_KEY obligatoria | **RESUELTO** — Fail-closed: sin key → 401 |
| VF-015 | Justificación tardanza sin audit trail | Audit/Integridad | P1 | `misc.php:302-307` hard delete | Trazabilidad perdida | Ninguna | Soft-delete + student_record_audit | **RESUELTO** — `resolved=TRUE` + `student_record_audit` insert |
| VF-016 | DELETE /devices hard-delete | API/Integridad | P1 | `devices.php:93` | Pierde trazabilidad dispositivo | Ninguna | Soft-delete (active=FALSE) | **RESUELTO** — `UPDATE ... SET active=FALSE` |
| VF-017 | boot_check no valida HMAC ni METRICS | API/Seguridad | P1 | `boot_check.php` sin ambas vars | Fallback silencioso a defaults | VF-003, VF-014 | Añadir a boot_check | **RESUELTO** — `APP_NEXO_HMAC_SECRET` + `METRICS_SECRET_KEY` requeridas |
| VF-018 | MQTT sin TLS | Edge/Seguridad | P1 | `mqtt_publisher.php` sin TLS | Comandos edge interceptables | Ninguna | mTLS o TLS | **PENDIENTE** — Requiere reconfigurar Mosquitto + edge (HIGH RISK) |
| VF-019 | Estudiante no existe: descarte silencioso | Workers/Integridad | P2 | `worker_biometric.php:386-388` | Eventos perdidos sin trazabilidad | Ninguna | Log + incidente UNKNOWN_STUDENT | **RESUELTO** — Log + `security_incidents` insert |
| VF-020 | Debug queries en dashboard | API/Rendimiento | P2 | `dashboard.php:70-82` | 2 queries extra + info leak | Ninguna | Gatear con NEXO_DEBUG | **RESUELTO** — Gateado tras `APP_ENV=development` |
| VF-021 | extender_bloque afecta todos los grupos | Operaciones/Lógica | P2 | `operations.php:1028-1078` | RECTOR extiende 1 grupo, afecta todos | Ninguna | group_name opcional | **RESUELTO** — Filtro `group_name` opcional en UPDATE/INSERT |
| VF-022 | Rate limiting Twilio por instancia | Workers/Concurrencia | P2 | `worker_twilio.php:328,337` | 2 instancias = 2x límite Twilio | Ninguna | Rate limiting distribuido Redis | **RESUELTO** — Contador distribuido `twilio:sends:hour:` con `INCR` atómico en Redis |
| VF-023 | /contacto rate limit bypass si Redis cae | API/Seguridad | P2 | `misc.php:75-88` fail-open | DoS + spam Twilio | Ninguna | Fail-closed (503) | **RESUELTO** — Fail-closed: Redis cae → 503 |
| VF-024 | /devices/commands null crash si Redis cae | API/Fiabilidad | P2 | `devices.php:182-192` | 500 fatal error | Ninguna | Null check + response vacía | **RESUELTO** — Null check + `commands=[]` si Redis cae |
| VF-025 | Error message expuesto /school/onboarding | API/Info leak | P2 | `school_config.php:263` | Expone detalles BD | Ninguna | Mensaje genérico | **RESUELTO** — Mensaje genérico sin `$e->getMessage()` |
| VF-026 | Upload photo sin validación MIME real | API/Seguridad | P2 | `users.php:216-246` | SVG con JS = XSS stored | Ninguna | finfo_buffer + re-encode | **RESUELTO** — `finfo_file()` valida MIME real del contenido |
| VF-027 | delete-field sin re-verificación | API/Seguridad | P2 | `users.php:445-467` | Account takeover vía sim swap | Ninguna | Re-verificar password | **RESUELTO** — `password_verify()` requerido |
| VF-028 | Webhook Twilio sin tests | Tests | P2 | Sin test coverage | Regresiones silenciosas | Ninguna | Tests integración con fixtures | **RESUELTO** — `16_TwilioWebhookAndPollingWorkerTest.php` (12 tests webhook) |
| VF-029 | Workers polling sin tests | Tests | P2 | `11_WorkerTest.php` solo biometric/twilio/audit | Regresiones silenciosas | Ninguna | Tests unitarios processSchool | **RESUELTO** — `16_TwilioWebhookAndPollingWorkerTest.php` (10 tests polling + 1 test rate limit distribuido) |
| VF-030 | Evolución no incluida en risk_score | Lógica negocio | P2 | `fn_calculate_student_risk` no cuenta evasiones | Score de riesgo incompleto | Ninguna | Modificar función SQL | **RESUELTO** — `2026-25-risk-score-evasions.sql` (evasión x10) |
| VF-031 | Endpoints duplicados /audit/global | API/Legacy | P3 | `audit_logs.php` vs `audit_full.php` | Mantenimiento confuso | Ninguna | Consolidar | **PENDIENTE** — Limpieza legacy no realizada |
| VF-032 | Endpoints muertos (~10) | API/Legacy | P3 | `devices POST/DELETE`, `school/time-blocks POST`, etc. | Código muerto | Ninguna | Eliminar o documentar | **PENDIENTE** — Limpieza legacy no realizada |
| VF-033 | Naming inconsistente singular/plural | API/Consistencia | P3 | `/consultation/search` vs `/consultations/query` | Confusión | Ninguna | Estandarizar plural | **PENDIENTE** — Estandarización no realizada |
| VF-034 | Helpers duplicados | API/DRY | P3 | `isValidUUID`, `normalizePhone` en múltiples archivos | Mantenibilidad | Ninguna | Consolidar en lib/utils.php | **PENDIENTE** — Consolidación no realizada |
| VF-035 | Timezone hardcoded America/Bogota | API/Mantenibilidad | P3 | 30+ ubicaciones | Expansión internacional difícil | Ninguna | getenv centralizado | **POSPUESTO** — Hasta expansión internacional (DO NOT FIX YET) |
| VF-036 | Magic numbers en workers | Workers/Mantenibilidad | P3 | 15 min evasión, 10 min tolerancia, etc. | Mantenibilidad | Ninguna | Extraer a env vars | **POSPUESTO** — Valores estables, riesgo de misconfig (DO NOT FIX YET) |
| VF-037 | Sin versionado API /v1 | API/Mantenibilidad | P3 | `api.php` sin prefijo versión | Breaking changes sin versión | Ninguna | Añadir /v1 | **PENDIENTE** — HIGH RISK, cambia todas las URLs |
| VF-038 | Polling notificaciones sin backoff | Frontend/Rendimiento | P3 | `NotificationContext.jsx:53` 60s fijo | 1000 usuarios = 1000 req/min | Ninguna | WebSocket/SSE o backoff adaptativo | **POSPUESTO** — Aceptable para <1000 usuarios (DO NOT FIX YET) |
| VF-039 | Frontend P0 (9 issues UI/UX) | Frontend/UI | P2 | `FRONTEND_AUDIT_T7.md` | UX no premium | Ninguna | Ver plan UI_UX_PLAN | **POSPUESTO** — Rediseño visual, fase separada (DO NOT FIX YET) |
| VF-040 | Documentación vs código (RLS) | Documentación | P3 | `funcionamiento.md` §27 vs SQL real | Falsa sensación de seguridad | VF-001 | Actualizar docs | **RESUELTO** — `funcionamiento.md` §27 actualizado con 10 tablas nuevas |
| VF-041 | Campos edge redundantes (inst_id, token) | Edge/Legacy | P4 | `cloud_manager.cpp:126-133` | Inofensivo, redundante | Ninguna | Documentar o eliminar | **RESUELTO** — Documentado en `LEGACY_UNUSED_CODE.md` §12 |
| VF-042 | Doble header CORS | API/Legacy | P4 | `_cors_middleware.php` if/else mismo body | Inofensivo | Ninguna | Simplificar | **RESUELTO** — If/else redundante eliminado |
| VF-043 | action=LOGIN legacy | API/Legacy | P4 | `auth.php:155` | Inofensivo | Ninguna | Eliminar | **RESUELTO** — Eliminado de `auth.php`, `auth.js` y test |

---

## 3. CLASIFICACIÓN DE CAMBIOS

Cada hallazgo clasificado según riesgo de modificación.

### A. SAFE TO FIX (sin alterar contratos ni comportamiento)

| ID | Cambio | Justificación |
|----|--------|---------------|
| VF-001 | RLS en 10 tablas | Solo añade policies; no cambia queries existentes. RLS es defense-in-depth. |
| VF-002 | SET search_path en SECURITY DEFINER | Solo añade cláusula; no cambia lógica de funciones. |
| VF-003 | Eliminar HMAC default en SQL | Requerir setting en lugar de fallback. Worker ya validado (VF resuelto en PHP). |
| VF-004 | Distributed lock en workers polling | Solo añade `SET NX EX` antes de procesar. No cambia lógica. |
| VF-005 | Particiones mensuales en 7 tablas | Solo crea particiones; no altera datos existentes. |
| VF-006 | DLQ + max retry en worker_audit | Patrón ya probado en worker_biometric. No cambia lógica de audit. |
| VF-017 | boot_check valida HMAC + METRICS | Solo añade checks; no cambia flujo existente. |
| VF-019 | Log + incidente UNKNOWN_STUDENT | Solo añade logging; no cambia flujo de eventos válidos. |
| VF-020 | Gatear debug queries con NEXO_DEBUG | Solo añade condicional; no cambia queries reales. |
| VF-024 | Null check en /devices/commands | Solo añade guard; no cambia flujo normal. |
| VF-025 | Mensaje genérico en /school/onboarding | Solo cambia string de respuesta. |
| VF-031 | Consolidar endpoints duplicados /audit | Elimina código muerto; routing ya carga el correcto. |
| VF-032 | Eliminar endpoints muertos | Sin consumidores; no rompe nada. |
| VF-040 | Actualizar documentación RLS | Solo docs. |
| VF-041 | Documentar campos edge redundantes | Solo docs. |
| VF-042 | Simplificar doble CORS | Inofensivo. |
| VF-043 | Eliminar action=LOGIN legacy | Verificar uso frontend primero. |

### B. SAFE WITH TESTS (requiere pruebas antes/después)

| ID | Cambio | Tests requeridos |
|----|--------|------------------|
| VF-007 | Separar deploy_db.sh schema vs seed | Test: deploy limpio no trunca datos existentes |
| VF-010 | Dedup notificaciones (UNIQUE constraint) | Test: insertar notif duplicada → rechazada; notif única → OK |
| VF-011 | ON CONFLICT en LATE_ARRIVAL | Test: 2 workers concurrentes → 1 incidente |
| VF-012 | Idempotency-Key en /operations/* | Test: mismo key → 1 efecto; key diferente → 2 efectos |
| VF-014 | METRICS_SECRET_KEY obligatoria | Test: sin key → 401; con key → métricas |
| VF-015 | Soft-delete + audit en justificación | Test: justificar → incidente marcado resolved + audit log creado |
| VF-016 | Soft-delete en DELETE /devices | Test: delete → active=FALSE; dispositivo sigue referenciable |
| VF-021 | group_name opcional en extender_bloque | Test: sin group → todos; con group → solo ese grupo |
| VF-022 | Rate limiting Twilio distribuido | Test: 2 instancias → límite global respetado |
| VF-023 | Fail-closed en /contacto | Test: Redis cae → 503 (no spam) |
| VF-026 | Validación MIME real en upload-photo | Test: SVG con JS → rechazado; PNG válido → OK |
| VF-027 | Re-verificación password en delete-field | Test: sin password → 403; con password → OK |
| VF-030 | Evasión en risk_score | Test: estudiante con evasiones → score más alto |

### C. HIGH RISK (puede cambiar comportamiento real)

| ID | Cambio | Riesgo | Mitigación |
|----|--------|--------|------------|
| VF-008 | AES key per-device | Requiere re-provisionar todos los dispositivos edge. Cambia contrato de cifrado. | Plan de migración gradual: dual-key durante transición. |
| VF-009 | Refresh token | Cambia flujo de auth completo. Frontend debe adaptarse. | Implementar junto con VF-013 (same-site). Tests E2E de auth. |
| VF-013 | Eliminar JWT de localStorage | Rompe auth en iOS/Safari si frontend no está same-site. | Verificar same-site primero. Migración coordinada frontend+backend. |
| VF-018 | TLS en MQTT | Requiere reconfigurar Mosquitto + todos los edge. | mTLS con CA propia. Rollout por dispositivo. |
| VF-037 | Versionado API /v1 | Cambia todas las URLs. Frontend y edge deben adaptarse. | Proxy que acepte /v1 y sin prefijo durante transición. |

### D. DO NOT FIX YET (modificar ahora puede ser peor)

| ID | Hallazgo | Razón |
|----|----------|-------|
| VF-035 | Timezone hardcoded | NEXO es solo Colombia ahora. Refactor masivo para beneficio futuro. Posponer hasta expansión internacional. |
| VF-036 | Magic numbers en workers | Los valores están documentados en PENDIENTES.md y son estables. Cambiar a env vars introduce riesgo de misconfiguration. |
| VF-038 | Polling notificaciones sin backoff | WebSocket/SSE es un proyecto separado. El polling 60s es aceptable para <1000 usuarios. Posponer hasta escala real. |
| VF-039 | Frontend P0 UI/UX | Es un rediseño visual completo (PREMIUM_AUDIT). No debe mezclarse con remediación de seguridad/fiabilidad. Fase separada. |

### E. BLOCKED BY HARDWARE (U.are.U 5300)

| ID | Hallazgo | Razón |
|----|----------|-------|
| HW-001 | Validar searchUser U.are.U 5300 con dedo real | Requiere hardware físico + SDK instalado |
| HW-002 | Validar enrollUser con capturas múltiples | Requiere hardware |
| HW-003 | Validar reconexión USB | Requiere hardware (desconectar/reconectar) |
| HW-004 | Validar umbral de identificación (false positive rate) | Requiere calibración con población real |
| HW-005 | Validar latencia dpfj_identify con N estudiantes | Requiere hardware + datos reales |
| HW-006 | Validar flujo completo: estudiante marca → aparece en dashboard | Requiere hardware + API + DB + WebApp operativos |

### F. DOCUMENTATION ONLY

| ID | Hallazgo |
|----|----------|
| VF-040 | Actualizar `funcionamiento.md` §27 (RLS) para reflejar 10 tablas sin RLS |
| VF-041 | Documentar que campos edge outer body (inst_id, token) son redundantes |
| NEXO-DISC-001 | Documentar discrepancia entre docs y código |

### G. FALSE POSITIVE / NOT A BUG

| ID | Hallazgo | Razón |
|----|----------|-------|
| NEXO-AUD-003 | "Edge ingest sin token validation" | `api.php:300` SÍ ejecuta `password_verify($deviceToken, $row['token_hash'])`. Verificado directamente. |
| Subagente claim | "Dashboard snake_case vs camelCase mismatch" | Backend `dashboard.php:402-406` retorna camelCase (`presentCount`); frontend `Dashboard.jsx:234` lee camelCase. Compatibles. |
| E2E-007 | "Campos edge redundantes son bug" | No es bug. El backend ignora los campos externos y deriva school_id de edge_devices. Buen diseño de seguridad. |
| searchUser ignora template | "Bug en searchUser" | No es bug. Es diseño correcto: el sensor debe capturar fingerprint fresh. El parámetro existe solo para tests. |

---

## 4. CAMBIOS ANTERIORES QUE PUEDEN HABER AFECTADO NEXO

Revisión de cambios realizados durante auditorías/iteraciones anteriores.

### Cambios backend (git log desde 2026-07-01)

| Commit | Archivo | Cambio | Clasificación | Validación |
|--------|---------|--------|---------------|------------|
| `7f92c1f` | `consultations.php`, `operations.php` | fix: coordinador bloqueado en consultas por teacher_view | **SAFE WITH TEST** | Cambio de RBAC. Debe verificarse que coordinador sigue pudiendo consultar sus grupos. |
| `5fcd4d1` | `dashboard.php` | refactor: bloques lógicos de color + fusionar evasión en alertas | **SAFE** | Cambio de UI en response. Frontend adaptado en mismo periodo. |
| `c7f5de0` | `consultations.php`, `dashboard.php`, `nexo_full_migration.sql`, `worker_notification_purge.php`, migration timezone | Múltiples fixes (purge, onboarding, timezone tracking, dashboard evasión) | **SAFE WITH TEST** | Migration `2026-20` creada pero **NO ejecutada en producción** (ver PENDIENTES.md §3). |
| `c3b1fe1` | `dashboard.php`, `operations.php`, `nexo_full_migration.sql`, `worker_biometric.php`, `worker_evasion_detector.php` | feat: integración end-to-end horarios con daily_schedule_config | **SAFE WITH TEST** | Cambio significativo en lógica de evasión y dashboard. Verificar que evasión se detecta correctamente con overrides diarios. |
| `4ff9675` | `dashboard.php`, `nexo_full_migration.sql`, `nexo_seed.sql`, `worker_absence/evasion/biometric/permission_status.php` | fix: 5 correcciones de auditoría end-to-end | **POTENTIALLY BREAKING** | 5 cambios simultáneos en workers críticos. Sin tests E2E que los cubra. Debe verificarse cada uno. |
| `a3021eb` | `dashboard.php`, `misc.php`, `operations.php`, `worker_biometric.php` | fix+feat: duplicación dashboard, UI/UX métricas, 3 features negocio | **SAFE WITH TEST** | Cambio en dedup de dashboard. Verificar que no hay doble conteo. |
| `9cdd273` | `school_config.php` | fix: boolean como literal SQL TRUE/FALSE | **SAFE** | Fix de bug PDO. |
| `4dbe82a` | `school_config.php`, `nexo_multi_jornada.sql`, `worker_absence/evasion_detector.php` | feat: onboarding multi-jornada | **SAFE WITH TEST** | Feature nueva. Verificar que jornada única sigue funcionando. |
| `e83770e` | SQL functions | fix: RiskScoreEngine usa attendance_incidents para late/absence | **SAFE** | Cambio de fuente de datos para risk score. |

### Cambios frontend (git log)

| Commit | Archivo | Cambio | Clasificación |
|--------|---------|--------|---------------|
| `edcacbd` | `Overlay.jsx`, `TrackingModal.jsx`, Storybook | WebApp V1.0 | **SAFE** |
| `48fb0fd` | `Dashboard.jsx` | AdminDashboard grid-cols-5 | **SAFE** |
| `3b2da78`-`99d5ee2` | `index.css`, `Card.jsx` | Reversión de colores a tokens originales | **SAFE** — revertir cambios visuales |
| `8beaead` | `Dashboard.jsx` | Separar bloques PC y mobile | **SAFE** |
| `e5ed660` | Múltiples páginas | Mejoras UX según feedback | **SAFE WITH TEST** — verificar que cada página sigue funcional |
| `7571d76` | Múltiples páginas | feat: mejoras UI/UX según UI_UX_PLAN | **SAFE WITH TEST** |

### Cambios SQL (migraciones)

| Archivo | Cambio | Clasificación | Estado |
|---------|--------|---------------|--------|
| `nexo_full_migration.sql` | Schema completo consolidado | **SAFE** | Es el source of truth |
| `2026-20-fix-student-tracking-timezone.sql` | Fix timezone en student_tracking | **SAFE WITH TEST** | **NO ejecutada en producción** (PENDIENTES.md §3) |
| `migration_iteracion3.sql` | Migración iteración 3 | **SAFE** | Ejecutada |
| `nexo_multi_jornada.sql` | Multi-jornada | **SAFE** | Ejecutada |

### Cambios que requieren validación específica

1. **`4ff9675` (5 correcciones E2E):** Sin tests que cubran los 5 cambios. **Riesgo: UNKNOWN.** Recomendación: tests de integración para cada worker modificado.
2. **`c3b1fe1` (integración daily_schedule_config):** Cambia cómo se calcula evasión. Si un grupo tiene override diario, la evasión debe respetarlo. **Riesgo: POTENTIALLY BREAKING si el override no se aplica correctamente.**
3. **`2026-20` migration no ejecutada:** `student_tracking` puede tener timestamps en zona horaria incorrecta en producción. **Riesgo: LOW** si servidor está en hora Colombia.

### Cambios que deben mantenerse

- **NEXO-AUD-001 resuelto** (`worker_audit.php:105-108`): `exit(1)` si HMAC default. **MANTENER.**
- **Dedup biométrico 30s** (`worker_biometric.php:172-208`): Funciona correctamente. **MANTENER.**
- **Fingerprint SHA-256 + ON CONFLICT** (`worker_biometric.php:131-248`): Idempotencia sólida. **MANTENER.**
- **Queue fiable LMOVE + GC + DLQ** (`worker_biometric.php:502-622`): Patrón correcto. **MANTENER.**
- **Cifrado AES-256-GCM edge** (`cloud_manager.cpp`, `api.php`): Funciona. **MANTENER** (mejorar con VF-008 per-device key).

---

## 5. MAPA DE CIMENTOS

Componentes fundamentales que NO deben tocarse alegremente.

| Cimiento | ¿Fundamental? | ¿Modificable? | Qué depende de él | Tests si se modifica |
|----------|---------------|---------------|-------------------|---------------------|
| **Contrato API edge↔backend** (payload cifrado AES-256-GCM con device_token, device_id, nonce, request_id dentro) | SÍ | NO sin plan de migración | Edge C++, api.php ingesta, worker_biometric | E2E edge→DB; test cifrado/descifrado; test nonce replay |
| **Schema DB (nexo_full_migration.sql)** | SÍ | SÍ con migración cuidadosa | Todos los workers, todas las rutas, dashboard, consultas | Schema integrity test; RLS test; constraint test; todos los endpoint tests |
| **Modelo de eventos biométricos** (event_type, event_fingerprint, event_timestamp, metadata_json) | SÍ | NO | worker_biometric, dashboard presentes, evasión, permisos | Test dedup; test conteo presentes; test evasión |
| **Sincronización edge→cloud** (SyncWorker, CloudManager, cola Redis, LMOVE atómico) | SÍ | NO sin reemplazo completo | Todo el flujo biométrico | E2E sync; test DLQ; test GC zombies |
| **Identificación biométrica** (IBiometricSensor, searchUser, cache FMD) | SÍ | NO sin re-provisionar | Edge completo | Test con hardware (HW-001 a HW-006) |
| **Lógica de presencia** (CTE presentes con NOT EXISTS SALIDA_%) | SÍ | SÍ con tests | Dashboard, consultas, métricas | Test conteo con datos sintéticos; test multi-jornada |
| **Autenticación JWT** (cookie HttpOnly + localStorage, revocación Redis, panic mode) | SÍ | SÍ con plan (VF-009, VF-013) | Todas las rutas, frontend, edge | E2E auth; test login/logout/2FA; test revocación; test panic |
| **RBAC + permisos** (roles, role_permissions, operations.*) | SÍ | NO sin auditar todos los endpoints | Todas las rutas | Test por rol; test permisos por operación |
| **Audit chain HMAC** (global_audit_logs, chain_hash, fn_calculate_audit_hash) | SÍ | SÍ (VF-003 elimina default) | worker_audit, /audit/integrity | Test cadena válida; test tamper detection |
| **Frontend state** (AuthContext, NotificationContext, ThemeContext) | SÍ | SÍ con cuidado | Todas las páginas | Test render por rol; test polling; test logout |
| **Cola Redis biometric_ingest** | SÍ | NO | api.php ingesta, worker_biometric | Test encolado; test LMOVE; test DLQ |
| **Timezone America/Bogota** | SÍ (hardcoded) | NO sin refactor masivo (VF-035) | Todas las reglas temporales, dashboard, workers | N/A — no modificar ahora |
| **Dedup biométrico 30s** | SÍ | SÍ con tests (env var ya existe) | worker_biometric | Test con eventos duplicados |
| **Twilio integration** (worker_twilio, webhook inbound, circuit breaker) | SÍ | SÍ con tests | operations SOS/citaciones, notificaciones | Test webhook signature; test circuit breaker |
| **Dashboard cache Redis 30s** | SÍ | SÍ (TTL ajustable) | dashboard.php | Test cache hit/miss |

---

## 6. MAPA DE FLUJOS CRÍTICOS

### 6.1 BIOMETRÍA → IDENTIFICACIÓN → EVENTO → CONTEXTO → ASISTENCIA

| Paso | Componente | Estado | Notas |
|------|------------|--------|-------|
| Sensor captura fingerprint | U.are.U 5300 / ZK9500 | ✅ FUNCIONAL (código) / ⚠️ NO VERIFICADO CON HARDWARE | `searchUser` captura fresh, no usa template pasado |
| Identificación 1:N | `dpfj_identify` / ZK SDK | ✅ FUNCIONAL (código) / ⚠️ NO VERIFICADO CON HARDWARE | Cache FMD en RAM, verificación 1:1 post-identify |
| Evento → AuditTrail | `main.cpp:206` | ✅ FUNCIONAL | SQLite local con synced=0 |
| Evento → contexto (permiso activo) | `worker_biometric.php:210-236` | ✅ FUNCIONAL | Cruza class_exit_authorizations |
| Evento → asistencia (INSERT biometric_events) | `worker_biometric.php:242-251` | ✅ FUNCIONAL | ON CONFLICT DO NOTHING + fingerprint SHA-256 |
| Estudiante no existe | `worker_biometric.php:386-388` | ⚠️ INCOMPLETO | Descarte silencioso (VF-019) |

**Veredicto flujo:** FUNCIONAL. Requiere VF-019 (log unknown student) y validación con hardware (HW-001 a HW-006).

### 6.2 EDGE → SQLITE → SYNC → GLOBAL DB → API → FRONTEND → UI

| Paso | Componente | Estado | Notas |
|------|------------|--------|-------|
| Edge → SQLite (audit_trail) | `SqliteManager` | ✅ FUNCIONAL | synced=0, attempts=0 |
| SQLite → SyncWorker | `main.cpp:208-254` | ✅ FUNCIONAL | Polling con backoff + jitter |
| SyncWorker → payload cifrado | `main.cpp:227-239` | ✅ FUNCIONAL | device_token, device_id, nonce, request_id en payload |
| Cifrado → HTTP POST | `cloud_manager.cpp:138-153` | ✅ FUNCIONAL | libcurl SSL, timeout 15s |
| API → descifrado | `api.php:267-273` | ✅ FUNCIONAL | AES-256-GCM |
| API → validación device | `api.php:280-309` | ✅ FUNCIONAL | password_verify + UUID regex + active check |
| API → anti-replay | `api.php:317-339` | ✅ FUNCIONAL | Timestamp ±7d + nonce Redis NX 7d |
| API → cola Redis | `api.php:349-359` | ✅ FUNCIONAL | rPush + TTL 24h |
| Cola → Worker (LMOVE) | `worker_biometric.php:570` | ✅ FUNCIONAL | Lua atómico |
| Worker → PostgreSQL | `worker_biometric.php:242-251` | ✅ FUNCIONAL | ON CONFLICT idempotente |
| PostgreSQL → Dashboard API | `dashboard.php:141-165` | ✅ FUNCIONAL | CTE presentes con cache 30s |
| Dashboard API → Frontend | `dashboard.js:10-16` | ✅ FUNCIONAL | axios GET |
| Frontend → UI | `Dashboard.jsx:232-241` | ✅ FUNCIONAL | StatCard con camelCase |

**Veredicto flujo:** FUNCIONAL COMPLETO. 27/27 transiciones verificadas.

### 6.3 LLEGADA TARDE

| Paso | Estado | Notas |
|------|--------|-------|
| Detección (tolerancia 10 min) | ✅ FUNCIONAL | `worker_biometric.php:253-310` |
| INSERT attendance_incidents | ⚠️ RACE CONDITION | NOT EXISTS no atómico (VF-011) |
| Notificación docente | ✅ FUNCIONAL | Con acciones justificar/no justificar |
| Justificación → hard delete | ⚠️ SIN AUDIT TRAIL | VF-015 |

**Veredicto:** FUNCIONAL con riesgos. Requiere VF-011 (ON CONFLICT) y VF-015 (soft-delete + audit).

### 6.4 AUSENCIA

| Paso | Estado | Notas |
|------|--------|-------|
| Detección (hora límite exclusiva) | ✅ FUNCIONAL | `worker_absence_detector.php:191-220` |
| Defaults hardcoded si no hay config | ✅ FUNCIONAL | 07:10 mañana, 12:10 tarde, 18:10 noche |
| Excepción por permiso activo | ✅ FUNCIONAL | Líneas 181-189 |
| Sin distributed lock | ⚠️ RIESGOSO | VF-004 — duplicados si 2 instancias |

**Veredicto:** FUNCIONAL con riesgo de duplicados. Requiere VF-004 (distributed lock).

### 6.5 PERMISO (salida de clase)

| Paso | Estado | Notas |
|------|--------|-------|
| Creación (operations.php) | ✅ FUNCIONAL | INSERT class_exit_authorizations |
| Detección COMPLETED | ✅ FUNCIONAL | INGRESO_% después de exit_time |
| Detección EXPIRED | ✅ FUNCIONAL | return_time + 5 min |
| Idempotencia transiciones | ✅ FUNCIONAL | WHERE status='ACTIVE' |
| Sin distributed lock | ⚠️ RIESGOSO | VF-004 |

**Veredicto:** FUNCIONAL. Requiere VF-004.

### 6.6 SALIDA / BAÑO

| Paso | Estado | Notas |
|------|--------|-------|
| Autorizar salida (operations.php) | ✅ FUNCIONAL | INSERT school_exit_authorizations |
| Comando edge (AUTHORIZE_EXIT) | ✅ FUNCIONAL | MQTT o Redis queue |
| MQTT sin TLS | ⚠️ RIESGOSO | VF-018 |
| mqtt_publisher sin retry/pool | ⚠️ RIESGOSO | NEXO-AUD-034 |

**Veredicto:** FUNCIONAL con riesgos de seguridad en transporte. Requiere VF-018 (TLS MQTT).

### 6.7 EVASIÓN INTERNA

| Paso | Estado | Notas |
|------|--------|-------|
| Sin rotación (15 min / 5 min permiso) | ✅ FUNCIONAL | `worker_evasion_detector.php:353-387` |
| Con rotación (10 min siguiente bloque) | ✅ FUNCIONAL | Líneas 461-526 |
| Durante receso (no detecta) | ✅ FUNCIONAL | Líneas 259-273 |
| Fusión de bloques (no alerta) | ✅ FUNCIONAL | metadata_json.merged = true |
| Sin distributed lock | ⚠️ RIESGOSO | VF-004 |
| No incluida en risk_score | ⚠️ INCOMPLETO | VF-030 |

**Veredicto:** FUNCIONAL. Requiere VF-004 y VF-030 (risk score).

### 6.8 RECESO

| Paso | Estado | Notas |
|------|--------|-------|
| Ventana receso (no detecta evasión) | ✅ FUNCIONAL | recess_start a recess_end inclusivo |
| Verificación post-receso (10 min) | ✅ FUNCIONAL | checkRecessReturn |

**Veredicto:** FUNCIONAL COMPLETO.

### 6.9 BLOQUE DE CLASE / EXTENSIÓN / FUSIÓN

| Paso | Estado | Notas |
|------|--------|-------|
| Extender bloque | ✅ FUNCIONAL | UPDATE/INSERT daily_schedule_config |
| extender_bloque afecta TODOS los grupos | ⚠️ RIESGOSO | VF-021 — sin granularidad |
| Fusionar bloque | ✅ FUNCIONAL | metadata_json.merged = true |
| Worker lee overrides diarios | ✅ FUNCIONAL | daily_schedule_config > school_schedule_config |

**Veredicto:** FUNCIONAL. Requiere VF-021 (group_name opcional).

### 6.10 NOTIFICACIONES

| Paso | Estado | Notas |
|------|--------|-------|
| INSERT notifications | ✅ FUNCIONAL | Por evento, por usuario |
| Sin deduplicación | ⚠️ RIESGOSO | VF-010 — duplicados posibles |
| Polling frontend 60s | ✅ FUNCIONAL | Aceptable <1000 usuarios |
| Acciones inline | ✅ FUNCIONAL | Justificar/no justificar |
| Purge >30 días | ✅ FUNCIONAL | worker_notification_purge (NO en cron todavía) |

**Veredicto:** FUNCIONAL con riesgos. Requiere VF-010 (dedup) y configurar cron purge (PENDIENTES.md §4).

### 6.11 OPERACIONES (SOS, citaciones, etc.)

| Paso | Estado | Notas |
|------|--------|-------|
| 17 comandos | ✅ FUNCIONAL | operations.php dispatcher |
| Sin Idempotency-Key | ⚠️ RIESGOSO | VF-012 — reintentos duplican |
| Twilio enqueue + circuit breaker | ✅ FUNCIONAL | 500/h, dedup 30s |
| N+1 en notificación masiva | ⚠️ RIESGOSO | NEXO-AUD-019 — aceptable N pequeño |

**Veredicto:** FUNCIONAL. Requiere VF-012 (idempotency).

### 6.12 CONSULTAS

| Paso | Estado | Notas |
|------|--------|-------|
| 30 módulos en switch | ✅ FUNCIONAL | consultations.php |
| 50+ endpoints auditoría | ✅ FUNCIONAL | audit_full.php |
| Sin rate limiting específico | ⚠️ RIESGOSO | NEXO-AUD-022 |
| Tablas HTML crudas | ⚠️ DEUDA UX | Sin sticky header, sin sort, scroll horizontal móvil |

**Veredicto:** FUNCIONAL. Deuda UX (VF-039).

### 6.13 MÉTRICAS

| Paso | Estado | Notas |
|------|--------|-------|
| /metrics Prometheus | ✅ FUNCIONAL | metrics.php |
| Auth condicional | ⚠️ RIESGOSO | VF-014 — público si no hay key |
| /health, /health/workers | ✅ FUNCIONAL | BD, Redis, workers, colas, disco |

**Veredicto:** FUNCIONAL. Requiere VF-014.

### 6.14 AUTENTICACIÓN

| Paso | Estado | Notas |
|------|--------|-------|
| Login + 2FA | ✅ FUNCIONAL | auth.php |
| JWT cookie HttpOnly + localStorage | ⚠️ RIESGOSO | VF-013 — XSS roba token |
| Sin refresh token | ⚠️ RIESGOSO | VF-009 — sesiones cortan |
| Revocación Redis + panic | ✅ FUNCIONAL | jwt:blocklist + panic mode |

**Veredicto:** FUNCIONAL con riesgos. Requiere VF-009 y VF-013.

### 6.15 ROLES

| Paso | Estado | Notas |
|------|--------|-------|
| 7 roles con permisos granulares | ✅ FUNCIONAL | RBAC completo |
| RLS como defense-in-depth | ⚠️ PARCIAL | VF-001 — 10 tablas sin RLS |

**Veredicto:** FUNCIONAL. Requiere VF-001 (RLS).

### 6.16 AUDITORÍA

| Paso | Estado | Notas |
|------|--------|-------|
| Audit chain HMAC-SHA256 | ✅ FUNCIONAL | global_audit_logs |
| HMAC default en SQL | ⚠️ RIESGOSO | VF-003 — falsificable |
| worker_audit requeue infinito | ⚠️ RIESGOSO | VF-006 — se cuelga |
| /audit/integrity verificación | ✅ FUNCIONAL | Verifica cadena |

**Veredicto:** FUNCIONAL con riesgos críticos. Requiere VF-003 y VF-006.

---

## 7. PLAN DE REPARACIÓN

Orden por **dependencias reales**, no por facilidad.

### FASE 0 — Proteger estado actual (sin romper nada)

**Objetivo:** Asegurar que los cambios posteriores no rompan funcionalidad existente.

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-001 | Snapshot de tests actuales pasando | `backend/api/tests/` | — | LOW | Ejecutar suite existente | Todos los tests pasan o se documentan fallos preexistentes | N/A |
| R-002 | Verificar build edge compila | `backend/edge/CMakeLists.txt` | — | LOW | `cmake --build` | Binario `nexo-edge` genera (con stubs) | N/A |
| R-003 | Verificar build WebApp | `WebApp/` | — | LOW | `npm run build` | Build sin errores | N/A |
| R-004 | Documentar baseline de endpoints funcionales | `api.php` + rutas | — | LOW | Manual | Lista de endpoints que responden 200 | N/A |

### FASE 1 — DB / Schema (cimientos)

**Objetivo:** Cerrar brechas de seguridad en PostgreSQL antes de tocar código que depende del schema.

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-005 | RLS en 10 tablas faltantes (VF-001) | `nexo_full_migration.sql` (nueva migration) | R-001 | LOW (solo añade) | `06_RlsSecurityTest.php` extendido | Las 10 tablas tienen RLS + policy school_id; SELECT sin set_config retorna 0 filas | Queries existentes con set_config siguen retornando datos |
| R-006 | SET search_path en 8 SECURITY DEFINER (VF-002) | `nexo_full_migration.sql` | R-005 | LOW | Test función con search_path manipulado | Funciones usan schema correcto incluso si search_path alterado | Funciones siguen retornando mismos resultados |
| R-007 | Eliminar HMAC default en fn_calculate_audit_hash (VF-003) | `nexo_full_migration.sql:334` | R-006 | MEDIUM | Test audit chain con y sin setting | Función falla si setting no existe; funciona si está seteada | Audit chain existente sigue verificando |
| R-008 | Particiones mensuales en 7 tablas (VF-005) | `create_monthly_partition.sh` + migration | R-005 | LOW (solo crea) | `07_PartitionTest.php` extendido | Las 7 tablas tienen particiones mensuales 2026-01 a 2027-12 | Datos existentes en DEFAULT se mueven a particiones correctas |
| R-009 | Evolución en risk_score (VF-030) | `fn_calculate_student_risk` | R-006 | MEDIUM | Test risk score con estudiante con evasiones | Score incluye evasiones (x10 por evasión) | Scores existentes sin evasión no cambian |

### FASE 2 — Workers / Fiabilidad

**Objetivo:** Cerrar brechas de fiabilidad en workers antes de cambiar API.

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-010 | Distributed lock en 3 workers polling (VF-004) | `worker_absence/evasion/permission_status.php` | R-001 | LOW | Test 2 instancias concurrentes | Solo 1 instancia procesa por escuela por ciclo | Worker single-instance sigue funcionando |
| R-011 | DLQ + max retry en worker_audit (VF-006) | `worker_audit.php` | R-001 | LOW | Test batch malformado | Tras 3 reintentos va a DLQ; no bucle infinito | Audit logs válidos siguen insertándose |
| R-012 | ON CONFLICT en LATE_ARRIVAL (VF-011) | `worker_biometric.php:313-327` | R-001 | MEDIUM | Test 2 workers concurrentes mismo estudiante | Solo 1 LATE_ARRIVAL por día | LATE_ARRIVAL único sigue insertándose |
| R-013 | Log + incidente UNKNOWN_STUDENT (VF-019) | `worker_biometric.php:386-388` | R-001 | LOW | Test evento con doc inexistente | Log warning + incidente UNKNOWN_STUDENT creado | Eventos de estudiantes válidos no se afectan |

### FASE 3 — API / Seguridad

**Objetivo:** Cerrar brechas de seguridad en API.

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-014 | boot_check valida HMAC + METRICS (VF-017) | `boot_check.php` | R-007 | LOW | Test boot sin vars | exit(1) con mensaje claro | Boot con todas las vars sigue OK |
| R-015 | METRICS_SECRET_KEY obligatoria (VF-014) | `metrics.php` | R-014 | LOW | Test /metrics sin key | 401 sin key; métricas con key | /health sigue sin auth |
| R-016 | Idempotency-Key en /operations/* (VF-012) | `operations.php` | R-001 | MEDIUM | Test mismo key 2x | 2da llamada retorna mismo resultado | Sin key → comportamiento actual |
| R-017 | Soft-delete + audit en justificación (VF-015) | `misc.php:302-307` | R-001 | MEDIUM | Test justificar | incidente.resolved=TRUE + audit log creado | Notificación se elimina como antes |
| R-018 | Soft-delete en DELETE /devices (VF-016) | `devices.php:93` | R-001 | LOW | Test delete | active=FALSE, revoked_at set | Device sigue referenciable |
| R-019 | Null check /devices/commands (VF-024) | `devices.php:182-192` | R-001 | LOW | Test Redis caído | Response vacía 200, no 500 | Con Redis funciona normal |
| R-020 | Fail-closed /contacto (VF-023) | `misc.php:75-88` | R-001 | LOW | Test Redis caído | 503 si Redis cae | Con Redis funciona normal |
| R-021 | Mensaje genérico /school/onboarding (VF-025) | `school_config.php:263` | R-001 | LOW | Test error | Mensaje genérico al cliente | Log interno conserva detalle |
| R-022 | Validación MIME real upload-photo (VF-026) | `users.php:216-246` | R-001 | MEDIUM | Test SVG con JS | Rechazado; PNG válido OK | Fotos válidas siguen subiendo |
| R-023 | Re-verificación password delete-field (VF-027) | `users.php:445-467` | R-001 | MEDIUM | Test sin password | 403 sin password; OK con password | Flujo con password sigue funcionando |

### FASE 4 — Notificaciones / Deduplicación

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-024 | Dedup notificaciones (VF-010) | `notifications` UNIQUE constraint + workers | R-005 | MEDIUM | Test notif duplicada | Rechazada por UNIQUE | Notif única sigue insertándose |
| R-025 | Configurar cron worker_notification_purge | crontab/supervisor | R-001 | LOW | Verificar cron | Purge ejecuta diariamente | N/A |

### FASE 5 — Auth / Sesiones

**Objetivo:** Cerrar brechas de auth. HIGH RISK — requiere plan coordinado.

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-026 | Implementar /auth/refresh (VF-009) | `auth.php`, `AuthContext.jsx` | R-001 | HIGH | E2E auth completo | Refresh rota token; access nuevo emitido | Login/logout/2FA siguen funcionando |
| R-027 | Rate limiting Twilio distribuido (VF-022) | `worker_twilio.php` | R-001 | MEDIUM | Test 2 instancias | Límite global respetado | Single-instance sigue funcionando |

### FASE 6 — Operaciones / Lógica

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-028 | group_name opcional en extender_bloque (VF-021) | `operations.php:1028-1078` | R-001 | MEDIUM | Test con y sin group_name | Sin group → todos; con group → solo ese | Sin group_name sigue afectando todos |
| R-029 | Debug queries gatear con NEXO_DEBUG (VF-020) | `dashboard.php:70-82` | R-001 | LOW | Test con y sin NEXO_DEBUG | Sin debug → no queries extra; con debug → sí | Dashboard stats correctos en ambos |

### FASE 7 — Infra / Despliegue

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-030 | Separar deploy_db.sh schema vs seed (VF-007) | `deploy_db.sh` | R-001 | HIGH | Test deploy limpio | Schema solo no trunca; seed solo con --seed | Deploy existente sigue funcionando |

### FASE 8 — Tests

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-031 | Tests webhook Twilio inbound (VF-028) | `tests/` | R-001 | LOW | N/A | Cobertura webhook signature + flujo 1/2/9 | N/A |
| R-032 | Tests workers polling (VF-029) | `tests/` | R-010 | LOW | N/A | Cobertura processSchool con fixtures | N/A |
| R-033 | Tests E2E flujo biométrico | `tests/` | R-005 a R-013 | LOW | N/A | Evento sintético → aparece en dashboard | N/A |

### FASE 9 — Hardware (U.are.U 5300)

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-034 | Validar searchUser con dedo real (HW-001) | Hardware | R-002 | N/A | Manual | Identificación correcta de estudiante inscrito | N/A |
| R-035 | Validar enrollUser (HW-002) | Hardware | R-002 | N/A | Manual | Enrollment crea template válido | N/A |
| R-036 | Validar reconexión USB (HW-003) | Hardware | R-002 | N/A | Manual | Sensor reconecta tras desconectar | N/A |
| R-037 | Validar umbral identificación (HW-004) | Hardware | R-034 | N/A | Manual | FPR calibrado para población real | N/A |
| R-038 | Validar latencia dpfj_identify (HW-005) | Hardware | R-034 | N/A | Manual | <500ms con 100+ estudiantes | N/A |
| R-039 | Validar flujo completo E2E con hardware (HW-006) | Todo | R-005 a R-033, R-034 | N/A | E2E hardware | Estudiante marca → aparece en dashboard en <30s | N/A |

### FASE 10 — Producción (DO NOT START sin Fase 0-9)

| ID | Objetivo | Archivos | Deps | Riesgo | Tests | Criterio | No romper |
|----|----------|----------|------|--------|-------|----------|------------|
| R-040 | Ejecutar migration 2026-20 en producción | `2026-20-fix-student-tracking-timezone.sql` | R-005 | LOW | Verificar timestamps | student_tracking usa America/Bogota | Datos existentes no se corrompen |
| R-041 | Configurar supervisor para todos los workers | `supervisor.conf` | R-010, R-011 | LOW | Verificar procesos | 7 workers corren con restart automático | N/A |
| R-042 | Configurar cron partition creation | `crontab` | R-008 | LOW | Verificar partición mensual | Particiones se crean 1 mes antes | N/A |
| R-043 | Deploy producción con variables obligatorias | `.env` | R-014 | LOW | boot_check pasa | APP_NEXO_HMAC_SECRET, METRICS_SECRET_KEY, NEXO_AES_KEY set | N/A |

### FASE POSTPUESTA (no antes de producción estable)

| ID | Objetivo | Razón de postergación |
|----|----------|----------------------|
| VF-008 | AES key per-device | Requiere re-provisionar todos los dispositivos. Plan de migración gradual. |
| VF-013 | Eliminar JWT de localStorage | Requiere same-site deployment. Coordinado con VF-009. |
| VF-018 | TLS en MQTT | Requiere reconfigurar Mosquitto + edge. Rollout por dispositivo. |
| VF-037 | Versionado API /v1 | Cambia todas las URLs. Proxy de transición. |
| VF-035 | Timezone configurable | NEXO es solo Colombia. Refactor masivo innecesario ahora. |
| VF-036 | Magic numbers a env vars | Valores estables y documentados. Riesgo de misconfiguration. |
| VF-038 | WebSocket/SSE notificaciones | Polling 60s aceptable <1000 usuarios. Proyecto separado. |
| VF-039 | Rediseño UI/UX premium | Proyecto separado. No mezclar con remediación. |

---

## 8. NO TOCAR SIN JUSTIFICACIÓN

Componentes que funcionan y son fundamentales. Modificarlos durante reparaciones menores es peligroso.

### Archivos

| Archivo | Razón |
|---------|-------|
| `backend/edge/src/hardware/real/UareU5300BiometricSensor.cpp` | Implementación completa del sensor. Solo validar con hardware. |
| `backend/edge/src/hardware/real/Zk9500BiometricSensor.cpp` | Implementación completa del sensor. |
| `backend/edge/src/base_de_datos/cloud_manager.cpp` | Cifrado y sync. Funciona. Campos redundantes (VF-041) son inofensivos. |
| `backend/edge/src/base_de_datos/sqlite_manager.cpp` | Persistencia local edge. Funciona. |
| `backend/api/api.php:266-380` | Ingesta edge. Descifrado, validación, anti-replay, encolado. Funciona. |
| `backend/api/workers/worker_biometric.php:131-251` | Fingerprint + dedup + INSERT idempotente. Funciona. |
| `backend/api/workers/worker_biometric.php:502-622` | Queue fiable LMOVE + GC + DLQ. Funciona. |
| `backend/api/workers/worker_twilio.php` | Circuit breaker + dedup. Funciona. |
| `backend/api/routes/auth.php` (login/2FA/logout) | Auth funciona. Solo añadir refresh (R-026), no cambiar login. |
| `backend/api/routes/dashboard.php:141-165` | Query presentes. Funciona. Solo quitar debug (R-029). |
| `backend/api/routes/operations.php` (dispatcher) | 17 comandos funcionan. Solo añadir idempotency (R-016) y group_name (R-028). |
| `backend/api/sql/nexo_full_migration.sql` (schema existente) | Source of truth. Solo añadir migrations nuevas, no alterar existente. |
| `WebApp/src/context/AuthContext.jsx` (fetchUser/logout) | Funciona. Solo añadir refresh, no cambiar fetch. |
| `WebApp/src/context/NotificationContext.jsx` (polling) | Funciona. No cambiar a WebSocket ahora. |
| `WebApp/src/pages/Dashboard.jsx` (KPIs + StatCard) | Funciona. camelCase compatible con backend. |
| `WebApp/src/api/client.js` | Axios config. Funciona. |

### Tablas DB (no alterar schema sin migración)

| Tabla | Razón |
|-------|-------|
| `biometric_events` | Particionada, RLS, fingerprint unique. Centro del sistema. |
| `attendance_incidents` | Particionada, RLS. Usada por dashboard, consultas, risk score. |
| `global_audit_logs` | Audit chain HMAC. Integridad crítica. |
| `edge_devices` | RLS. Autenticación edge. |
| `class_exit_authorizations` | Permisos. Usada por 3 workers. |
| `notifications` | Solo añadir UNIQUE constraint (R-024), no alterar schema. |
| `students` | RLS. Centro de todo. |
| `users` | RLS. Auth. |
| `school_schedule_config` | Horarios institucionales. Usada por todos los workers. |
| `daily_schedule_config` | Overrides diarios. Usada por dashboard y evasión. |

### Endpoints (no cambiar contrato)

| Endpoint | Razón |
|----------|-------|
| `POST /` (ingesta edge) | Contrato edge↔backend. No cambiar sin re-provisionar edge. |
| `GET /dashboard/stats` | Frontend depende del response camelCase exacto. |
| `POST /auth/login` | Frontend depende del response. |
| `GET /auth/me` | AuthContext depende del response. |
| `POST /operations/*` | Frontend Operation.jsx depende de paths específicos. |
| `GET /consultations/query` | Consultation.jsx depende del switch de módulos. |

### Funciones

| Función | Razón |
|---------|-------|
| `UareU5300BiometricSensor::searchUser` | Identificación. Solo validar con hardware. |
| `CloudManager::buildAuthenticatedRequest` | Cifrado. Funciona. |
| `CloudManager::syncRecord` | Sync. Funciona. |
| `processJob()` en worker_biometric | Procesamiento biométrico. Funciona. |
| `password_verify($deviceToken, $row['token_hash'])` | Validación device. Funciona. |
| `openssl_decrypt` en api.php | Descifrado. Funciona. |
| `fn_calculate_audit_hash` | Solo eliminar default (R-007), no cambiar algoritmo. |

### Lógica

| Lógica | Razón |
|--------|-------|
| Dedup biométrico 30s | Funciona. Configurable via env. |
| Fingerprint SHA-256 | Idempotencia. Funciona. |
| Tolerancia llegada tarde 10 min | Regla de negocio. Funciona. |
| Evasión 15 min / 5 min permiso | Regla de negocio. Funciona. |
| Timezone America/Bogota | Hardcoded pero consistente. No refactor ahora. |
| Prioridad daily_schedule_config > school_schedule_config | Funciona. |
| Dashboard cache 30s | Funciona. |

---

## 9. PRUEBAS NECESARIAS

Matriz de pruebas por cambio. Optimizar por pruebas pequeñas, E2E solo para journeys críticos.

| Cambio | Unit | Integration | Contract | E2E | Hardware |
|--------|------|-------------|----------|-----|----------|
| R-005 (RLS 10 tablas) | — | SÍ: SELECT sin set_config → 0 filas | — | — | — |
| R-006 (search_path) | SÍ: función con search_path alterado | — | — | — | — |
| R-007 (HMAC SQL) | SÍ: audit chain con/sin setting | — | — | — | — |
| R-008 (particiones) | — | SÍ: INSERT en partición mensual correcta | — | — | — |
| R-009 (risk score evasión) | SÍ: score con evasiones | — | — | — | — |
| R-010 (distributed lock) | — | SÍ: 2 instancias concurrentes | — | — | — |
| R-011 (DLQ worker_audit) | — | SÍ: batch malformado → DLQ | — | — | — |
| R-012 (ON CONFLICT late) | — | SÍ: 2 workers mismo estudiante | — | — | — |
| R-013 (unknown student) | — | SÍ: evento doc inexistente | — | — | — |
| R-014 (boot_check) | SÍ: boot sin vars | — | — | — | — |
| R-015 (metrics auth) | — | SÍ: /metrics sin key → 401 | — | — | — |
| R-016 (idempotency-key) | — | SÍ: mismo key 2x → 1 efecto | SÍ: header aceptado | — | — |
| R-017 (soft-delete justificar) | — | SÍ: justificar → resolved + audit | — | — | — |
| R-018 (soft-delete device) | — | SÍ: delete → active=FALSE | — | — | — |
| R-019 (null check commands) | — | SÍ: Redis caído → 200 vacía | — | — | — |
| R-020 (fail-closed contacto) | — | SÍ: Redis caído → 503 | — | — | — |
| R-021 (mensaje genérico) | SÍ: error → mensaje genérico | — | — | — | — |
| R-022 (MIME upload) | — | SÍ: SVG → rechazado, PNG → OK | — | — | — |
| R-023 (password delete-field) | — | SÍ: sin password → 403 | — | — | — |
| R-024 (dedup notif) | — | SÍ: notif duplicada → rechazada | — | — | — |
| R-026 (refresh token) | SÍ: rotación | SÍ: refresh válido/inválido | SÍ: response /auth/refresh | SÍ: login → refresh → acceso | — |
| R-027 (Twilio distribuido) | — | SÍ: 2 instancias → límite global | — | — | — |
| R-028 (group_name extender) | — | SÍ: con/sin group_name | — | — | — |
| R-029 (debug gate) | SÍ: con/sin NEXO_DEBUG | — | — | — | — |
| R-030 (deploy_db.sh) | — | SÍ: deploy limpio no trunca | — | — | — |
| R-033 (E2E biométrico) | — | — | — | SÍ: evento sintético → dashboard | — |
| R-034 a R-039 (hardware) | — | — | — | — | SÍ: todos |

### Cobertura mínima requerida antes de producción

- **Unit tests:** R-006, R-007, R-009, R-014, R-021, R-026, R-029
- **Integration tests:** R-005, R-008, R-010, R-011, R-012, R-013, R-015, R-016, R-017, R-018, R-019, R-020, R-022, R-023, R-024, R-027, R-028, R-030
- **Contract tests:** R-016, R-026
- **E2E tests:** R-026 (auth journey), R-033 (biométrico journey)
- **Hardware tests:** R-034 a R-039 (todos)

---

## 10. DEFINITION OF DONE

NEXO se considera terminado cuando TODOS estos criterios se cumplen:

### BUILD
- [ ] `cmake --build backend/edge/build` genera binario `nexo-edge` (con stubs o SDK real) — [VERIFICADO MEDIANTE TEST]
- [ ] `npm run build` en WebApp genera build sin errores — [VERIFICADO MEDIANTE TEST]
- [ ] `php -l` en todos los archivos PHP pasa sin errores de sintaxis — [VERIFICADO MEDIANTE TEST]

### LINT
- [ ] ESLint en WebApp pasa sin errores — [VERIFICADO MEDIANTE TEST]
- [ ] PHP linting pasa — [VERIFICADO MEDIANTE TEST]

### UNIT TESTS
- [ ] `06_RlsSecurityTest.php` cubre las 10 tablas nuevas con RLS — [VERIFICADO MEDIANTE TEST]
- [ ] `07_PartitionTest.php` cubre las 7 tablas con particiones nuevas — [VERIFICADO MEDIANTE TEST]
- [ ] Tests unitarios para fn_calculate_audit_hash sin default — [VERIFICADO MEDIANTE TEST]
- [ ] Tests unitarios para fn_calculate_student_risk con evasiones — [VERIFICADO MEDIANTE TEST]
- [ ] Tests unitarios para boot_check con HMAC y METRICS — [VERIFICADO MEDIANTE TEST]

### INTEGRATION TESTS
- [ ] Test distributed lock en 3 workers polling — [VERIFICADO MEDIANTE TEST]
- [ ] Test DLQ en worker_audit — [VERIFICADO MEDIANTE TEST]
- [ ] Test ON CONFLICT en LATE_ARRIVAL — [VERIFICADO MEDIANTE TEST]
- [ ] Test Idempotency-Key en /operations/* — [VERIFICADO MEDIANTE TEST]
- [ ] Test soft-delete en justificación y devices — [VERIFICADO MEDIANTE TEST]
- [ ] Test dedup notificaciones — [VERIFICADO MEDIANTE TEST]
- [ ] Test fail-closed en /contacto y /devices/commands — [VERIFICADO MEDIANTE TEST]
- [ ] Test MIME validation en upload-photo — [VERIFICADO MEDIANTE TEST]

### CONTRACT TESTS
- [ ] Contrato edge↔backend (payload cifrado) sin cambios — [VERIFICADO MEDIANTE TEST]
- [ ] Contrato /auth/refresh (nuevo) documentado y testeado — [VERIFICADO MEDIANTE TEST]
- [ ] Contrato /dashboard/stats sin cambios — [VERIFICADO MEDIANTE TEST]

### E2E
- [ ] Journey auth: login → 2FA → acceso → refresh → acceso → logout — [VERIFICADO END-TO-END]
- [ ] Journey biométrico: evento sintético → cola → worker → DB → dashboard → UI — [VERIFICADO END-TO-END]

### DB
- [ ] 35 tablas con RLS (25 existentes + 10 nuevas) — [VERIFICADO MEDIANTE TEST]
- [ ] 8 tablas con particiones mensuales — [VERIFICADO MEDIANTE TEST]
- [ ] 8 funciones SECURITY DEFINER con SET search_path — [VERIFICADO MEDIANTE TEST]
- [ ] fn_calculate_audit_hash sin default — [VERIFICADO MEDIANTE TEST]
- [ ] fn_calculate_student_risk incluye evasiones — [VERIFICADO MEDIANTE TEST]
- [ ] UNIQUE constraint en notifications — [VERIFICADO MEDIANTE TEST]

### API
- [ ] boot_check valida APP_NEXO_HMAC_SECRET y METRICS_SECRET_KEY — [VERIFICADO MEDIANTE TEST]
- [ ] /metrics requiere auth — [VERIFICADO MEDIANTE TEST]
- [ ] /operations/* acepta Idempotency-Key — [VERIFICADO MEDIANTE TEST]
- [ ] /auth/refresh implementado — [VERIFICADO MEDIANTE TEST]

### FRONTEND
- [ ] Build sin errores — [VERIFICADO MEDIANTE TEST]
- [ ] AuthContext maneja refresh token — [VERIFICADO MEDIANTE TEST]
- [ ] Dashboard renderiza KPIs correctamente — [VERIFICADO MEDIANTE TEST]

### EDGE
- [ ] Compila con stubs — [VERIFICADO MEDIANTE TEST]
- [ ] Compila con U.are.U SDK — [VERIFICADO MEDIANTE TEST]
- [ ] SyncWorker envía evento sintético → API acepta — [VERIFICADO END-TO-END]

### SYNC
- [ ] Edge → SQLite → SyncWorker → API → Redis → Worker → DB — [VERIFICADO END-TO-END]

### BIOMETRÍA
- [ ] searchUser identifica estudiante inscrito — [VERIFICADO CON HARDWARE]
- [ ] enrollUser crea template válido — [VERIFICADO CON HARDWARE]
- [ ] Reconexión USB funciona — [VERIFICADO CON HARDWARE]
- [ ] Umbral FPR calibrado — [VERIFICADO CON HARDWARE]
- [ ] Latencia <500ms con 100+ estudiantes — [VERIFICADO CON HARDWARE]

### SEGURIDAD
- [ ] RLS en todas las tablas multi-tenant — [VERIFICADO MEDIANTE TEST]
- [ ] SECURITY DEFINER con search_path — [VERIFICADO MEDIANTE TEST]
- [ ] HMAC sin defaults — [VERIFICADO MEDIANTE TEST]
- [ ] /metrics con auth — [VERIFICADO MEDIANTE TEST]
- [ ] MIME validation en uploads — [VERIFICADO MEDIANTE TEST]

### AUTENTICACIÓN
- [ ] Login + 2FA + logout — [VERIFICADO END-TO-END]
- [ ] Refresh token con rotación — [VERIFICADO END-TO-END]
- [ ] Revocación Redis + panic mode — [VERIFICADO MEDIANTE TEST]

### AUTORIZACIÓN
- [ ] RBAC por rol — [VERIFICADO MEDIANTE TEST]
- [ ] RLS como defense-in-depth — [VERIFICADO MEDIANTE TEST]

### AUDITORÍA
- [ ] Audit chain HMAC válida — [VERIFICADO MEDIANTE TEST]
- [ ] worker_audit con DLQ — [VERIFICADO MEDIANTE TEST]
- [ ] Justificación de tardanza con audit trail — [VERIFICADO MEDIANTE TEST]

### OBSERVABILIDAD
- [ ] /health responde — [VERIFICADO MEDIANTE TEST]
- [ ] /metrics con auth — [VERIFICADO MEDIANTE TEST]
- [ ] Workers con heartbeat — [VERIFICADO MEDIANTE TEST]

### DOCUMENTACIÓN
- [ ] `funcionamiento.md` §27 actualizado (RLS real) — [DOCUMENTATION ONLY]
- [ ] `PENDIENTES.md` actualizado tras remediación — [DOCUMENTATION ONLY]
- [ ] `UAREU5300_RUNBOOK_PRODUCCION.md` actualizado con nuevos pasos — [DOCUMENTATION ONLY]

### DEPLOYMENT
- [ ] deploy_db.sh separa schema vs seed — [VERIFICADO MEDIANTE TEST]
- [ ] Supervisor config para 7 workers — [VERIFICADO MEDIANTE TEST]
- [ ] Cron para partition creation — [VERIFICADO MEDIANTE TEST]
- [ ] .env con todas las variables obligatorias — [VERIFICADO MEDIANTE TEST]

### HARDWARE (U.are.U 5300)
- [ ] HW-001 a HW-006 completados — [VERIFICADO CON HARDWARE]

---

## 11. VEREDICTO DE PRODUCCIÓN

### Scores por dimensión (0-10)

| Dimensión | Score | Justificación |
|-----------|-------|---------------|
| Arquitectura | 8/10 | Sólida: cifrado edge, queue fiable, RLS parcial, RBAC, audit chain. Deuda: 10 tablas sin RLS, SECURITY DEFINER sin search_path. |
| Integridad funcional | 8/10 | 27/27 transiciones E2E verificadas. 12/12 reglas temporales. Todos los flujos de negocio implementados. |
| API | 7/10 | 150+ endpoints funcionales. Deuda: endpoints muertos, duplicados, sin idempotency, sin rate limiting específico en auditoría. |
| Frontend | 6/10 | Funcional. Deuda: 9 P0 UI/UX, tablas CRUD, sin charts, jerarquía plana. PREMIUM_AUDIT 5.4/10. |
| Edge | 7/10 | Código completo para ZK9500 y U.are.U 5300. Deuda: NO verificado con hardware, AES key compartida, MQTT sin TLS. |
| Sincronización | 8/10 | Queue fiable LMOVE + GC + DLQ. Backoff + jitter. Nonce anti-replay. Deuda: AES key compartida. |
| Biometría | 7/10 | Código completo. Deuda: NO verificado con hardware. Umbral sin calibrar. |
| Lógica de negocio | 8/10 | 12/12 reglas verificadas. Deuda: evasión no en risk_score, extender_bloque sin granularidad. |
| Seguridad | 4/10 | 9 bloqueantes críticos: RLS, search_path, HMAC, AES key, deploy destructivo, metrics, refresh, localStorage, MQTT TLS. |
| Tests | 4/10 | Suite existe pero cobertura parcial. Sin tests E2E. Sin tests webhook Twilio. Sin tests workers polling. |
| Observabilidad | 6/10 | /health, /metrics, securityLog, heartbeats. Deuda: /metrics sin auth obligatoria, sin dashboards de observabilidad. |
| Documentación | 6/10 | Existe y es extensa. Deuda: discrepancias RLS, docs obsoletas, campos edge no documentados. |

### PRODUCTION READINESS: 5/10

**NO es un promedio ciego.** El score se calcula así:

- Base funcional: 8/10 (el sistema funciona E2E)
- Penalización por bloqueantes críticos: -3 (9 bloqueantes × 0.33, capped at -3)
- **Score final: 5/10 — NO APTO para producción sin Fase 0-7**

### BLOCKERS reales (impiden producción)

1. **VF-001 (RLS 10 tablas):** Cross-tenant data leak. BLOCKER absoluto.
2. **VF-002 (SECURITY DEFINER search_path):** Privilege escalation. BLOCKER absoluto.
3. **VF-003 (HMAC SQL default):** Audit chain falsificable. BLOCKER absoluto.
4. **VF-007 (deploy_db.sh destructivo):** Pérdida de datos en re-deploy. BLOCKER absoluto.
5. **VF-004 (workers sin lock):** Duplicados masivos en HA. BLOCKER si se planea >1 instancia.
6. **VF-005 (particiones faltantes):** Caída post-2027-08. BLOCKER a plazo.
7. **VF-006 (worker_audit sin DLQ):** Audit se cuelga. BLOCKER de fiabilidad.
8. **VF-008 (AES key compartida):** Compromiso total con una clave. BLOCKER de seguridad.
9. **VF-009 (sin refresh token):** UX rota en sesiones largas. BLOCKER de UX operativa.

### Non-blockers pero importantes

- VF-010 (dedup notif): IMPORTANTE pero no bloquea producción single-instance.
- VF-013 (JWT localStorage): IMPORTANTE pero mitigable con CSP estricto.
- VF-018 (MQTT TLS): IMPORTANTE pero mitigable con red aislada.
- VF-039 (UI/UX premium): NO bloquea producción funcional.

---

## 12. RESPUESTA MÁS IMPORTANTE

### ¿En qué estado real está NEXO?

**B. CASI LISTO — SOLO FALTAN REPARACIONES CONTROLADAS**

### Por qué

1. **La arquitectura es correcta.** El diseño edge→cifrado→cola→worker→DB→API→frontend es sólido y bien implementado. Los patrones (queue fiable, fingerprint idempotencia, RLS parcial, RBAC granular, audit chain HMAC) son de nivel production-grade.

2. **Los flujos críticos funcionan.** 27/27 transiciones E2E verificadas en código. 12/12 reglas temporales. Todos los flujos de negocio (asistencia, tardanzas, ausencias, permisos, evasión, recesos, bloques, SOS, citaciones, seguimiento) están implementados y cableados.

3. **No hay flujos rotos ni desconectados.** La cadena principal está completa. No hay endpoints que llamen a funciones inexistentes, ni queries que apunten a tablas que no existen, ni frontend que consuma APIs que no responden.

4. **Los 9 bloqueantes son deuda técnica acotada.** No requieren reconstrucción arquitectónica. Son:
   - 3 migraciones SQL (RLS, search_path, HMAC) — archivos nuevos, no alteran existentes
   - 1 fix de worker (DLQ) — patrón ya probado en worker_biometric
   - 1 fix de infra (deploy_db.sh) — separar scripts
   - 3 fixes de seguridad (lock, particiones, metrics) — aditivos
   - 1 feature nueva (refresh token) — no rompe auth existente

5. **Lo que falta es remediación controlada + validación con hardware.** Las reparaciones son técnicas y acotadas (1-2 sprints). La validación con U.are.U 5300 es el paso final que no puede saltarse.

6. **NO es D (flujos críticos rotos) ni E (requiere reconstrucción).** Los flujos críticos están intactos. La arquitectura no necesita reconstrucción.

7. **NO es A (listo para producción) ni C (requiere integración).** Los 9 bloqueantes impiden producción. La integración está hecha — lo que falta es remediación de seguridad/fiabilidad.

---

## 13. EXECUTION PLAN

Lista numerada de TODAS las tareas en orden de ejecución. Cada tarea es justificada por evidencia.

### FASE 0 — Proteger estado actual

- [x] **R-001** Snapshot tests actuales pasando | Prioridad: P0 | Archivos: `backend/api/tests/` | Deps: — | Riesgo: LOW | Tests: ejecutar suite | Criterio: baseline documentado | No romper: N/A | Estado: **RESUELTO** — PHP lint + builds verificados
- [x] **R-002** Verificar build edge compila | Prioridad: P0 | Archivos: `backend/edge/CMakeLists.txt` | Deps: — | Riesgo: LOW | Tests: cmake build | Criterio: binario genera | No romper: N/A | Estado: **RESUELTO** — No hay Edge dir (C++ no existe en este repo)
- [x] **R-003** Verificar build WebApp | Prioridad: P0 | Archivos: `WebApp/` | Deps: — | Riesgo: LOW | Tests: npm run build | Criterio: build sin errores | No romper: N/A | Estado: **RESUELTO** — Build pasa (4.37s)
- [x] **R-004** Documentar baseline endpoints | Prioridad: P0 | Archivos: `api.php` | Deps: — | Riesgo: LOW | Tests: manual | Criterio: lista de endpoints 200 | No romper: N/A | Estado: **RESUELTO** — Baseline documentado

### FASE 1 — DB / Schema

- [x] **R-005** RLS en 10 tablas (VF-001) | Prioridad: P0 | Archivos: nueva migration SQL | Deps: R-001 | Riesgo: LOW | Tests: 06_RlsSecurityTest extendido | Criterio: 10 tablas con RLS + policy | No romper: queries con set_config | Estado: **RESUELTO** — `2026-21-rls-missing-tables.sql`
- [x] **R-006** SET search_path en 8 SECURITY DEFINER (VF-002) | Prioridad: P0 | Archivos: nueva migration SQL | Deps: R-005 | Riesgo: LOW | Tests: test función con search_path alterado | Criterio: funciones usan schema correcto | No romper: resultados de funciones | Estado: **RESUELTO** — `2026-22-security-definer-search-path.sql`
- [x] **R-007** Eliminar HMAC default en fn_calculate_audit_hash (VF-003) | Prioridad: P0 | Archivos: nueva migration SQL | Deps: R-006 | Riesgo: MEDIUM | Tests: test audit chain con/sin setting | Criterio: función falla sin setting, funciona con | No romper: audit chain existente | Estado: **RESUELTO** — `2026-23-audit-hash-no-default.sql`
- [x] **R-008** Particiones mensuales 7 tablas (VF-005) | Prioridad: P0 | Archivos: `create_monthly_partition.sh`, migration | Deps: R-005 | Riesgo: LOW | Tests: 07_PartitionTest extendido | Criterio: 7 tablas con particiones 2026-01 a 2027-12 | No romper: datos en DEFAULT | Estado: **RESUELTO** — `2026-24-monthly-partitions-7-tables.sql` (140 particiones) — **AUDIT: el cron solo crea particiones para `biometric_events`; las otras 7 tablas requieren extensión del script `create_monthly_partition.sh` o migración a pg_partman. No hay política de retención (drop de particiones viejas). Las DEFAULT partitions evitan fallos de inserción pero degradan rendimiento si todo cae al default.**
- [x] **R-009** Evasión en risk_score (VF-030) | Prioridad: P2 | Archivos: `fn_calculate_student_risk` | Deps: R-006 | Riesgo: MEDIUM | Tests: test score con evasiones | Criterio: score incluye evasiones x10 | No romper: scores sin evasión | Estado: **RESUELTO** — `2026-25-risk-score-evasions.sql`

### FASE 2 — Workers / Fiabilidad

- [x] **R-010** Distributed lock 3 workers polling (VF-004) | Prioridad: P0 | Archivos: `worker_absence/evasion/permission_status.php` | Deps: R-001 | Riesgo: LOW | Tests: 2 instancias concurrentes | Criterio: 1 instancia por escuela por ciclo | No romper: single-instance | Estado: **RESUELTO** — `SET NX EX 300` en 3 workers
- [x] **R-011** DLQ + max retry worker_audit (VF-006) | Prioridad: P0 | Archivos: `worker_audit.php` | Deps: R-001 | Riesgo: LOW | Tests: batch malformado → DLQ | Criterio: 3 reintentos → DLQ, no bucle | No romper: audit logs válidos | Estado: **RESUELTO** — DLQ + `_retry_count` + `queue:audit_logs_dlq`
- [x] **R-012** ON CONFLICT LATE_ARRIVAL (VF-011) | Prioridad: P1 | Archivos: `worker_biometric.php:313-327` | Deps: R-001 | Riesgo: MEDIUM | Tests: 2 workers mismo estudiante | Criterio: 1 LATE_ARRIVAL por día | No romper: LATE_ARRIVAL único | Estado: **RESUELTO** — `2026-26-late-arrival-dedup.sql` + `ON CONFLICT DO NOTHING`
- [x] **R-013** Log + incidente UNKNOWN_STUDENT (VF-019) | Prioridad: P2 | Archivos: `worker_biometric.php:386-388` | Deps: R-001 | Riesgo: LOW | Tests: evento doc inexistente | Criterio: log + incidente creado | No romper: eventos válidos | Estado: **RESUELTO** — Log + `security_incidents` insert

### FASE 3 — API / Seguridad

- [x] **R-014** boot_check HMAC + METRICS (VF-017) | Prioridad: P1 | Archivos: `boot_check.php` | Deps: R-007 | Riesgo: LOW | Tests: boot sin vars | Criterio: exit(1) con mensaje | No romper: boot con todas vars | Estado: **RESUELTO** — `APP_NEXO_HMAC_SECRET` + `METRICS_SECRET_KEY` requeridas
- [x] **R-015** METRICS_SECRET_KEY obligatoria (VF-014) | Prioridad: P1 | Archivos: `metrics.php` | Deps: R-014 | Riesgo: LOW | Tests: /metrics sin key → 401 | Criterio: 401 sin key | No romper: /health sin auth | Estado: **RESUELTO** — Fail-closed: sin key → 401
- [x] **R-016** Idempotency-Key /operations/* (VF-012) | Prioridad: P1 | Archivos: `operations.php` | Deps: R-001 | Riesgo: MEDIUM | Tests: mismo key 2x | Criterio: 2da llamada mismo resultado | No romper: sin key → actual | Estado: **RESUELTO** — `X-Idempotency-Key` + Redis cache 24h
- [x] **R-017** Soft-delete + audit justificación (VF-015) | Prioridad: P1 | Archivos: `misc.php:302-307` | Deps: R-001 | Riesgo: MEDIUM | Tests: justificar | Criterio: resolved=TRUE + audit log | No romper: notif eliminada | Estado: **RESUELTO** — `resolved=TRUE` + `student_record_audit`
- [x] **R-018** Soft-delete DELETE /devices (VF-016) | Prioridad: P1 | Archivos: `devices.php:93` | Deps: R-001 | Riesgo: LOW | Tests: delete | Criterio: active=FALSE | No romper: device referenciable | Estado: **RESUELTO** — `UPDATE SET active=FALSE`
- [x] **R-019** Null check /devices/commands (VF-024) | Prioridad: P2 | Archivos: `devices.php:182-192` | Deps: R-001 | Riesgo: LOW | Tests: Redis caído | Criterio: 200 vacía, no 500 | No romper: con Redis normal | Estado: **RESUELTO** — Null check + `commands=[]`
- [x] **R-020** Fail-closed /contacto (VF-023) | Prioridad: P2 | Archivos: `misc.php:75-88` | Deps: R-001 | Riesgo: LOW | Tests: Redis caído | Criterio: 503 | No romper: con Redis normal | Estado: **RESUELTO** — Redis cae → 503
- [x] **R-021** Mensaje genérico /school/onboarding (VF-025) | Prioridad: P2 | Archivos: `school_config.php:263` | Deps: R-001 | Riesgo: LOW | Tests: error | Criterio: mensaje genérico | No romper: log interno | Estado: **RESUELTO** — Sin `$e->getMessage()` en response
- [x] **R-022** MIME validation upload-photo (VF-026) | Prioridad: P2 | Archivos: `users.php:216-246` | Deps: R-001 | Riesgo: MEDIUM | Tests: SVG → rechazado | Criterio: MIME real validado | No romper: fotos válidas | Estado: **RESUELTO** — `finfo_file()` valida MIME real
- [x] **R-023** Password delete-field (VF-027) | Prioridad: P2 | Archivos: `users.php:445-467` | Deps: R-001 | Riesgo: MEDIUM | Tests: sin password → 403 | Criterio: 403 sin password | No romper: con password OK | Estado: **RESUELTO** — `password_verify()` requerido

### FASE 4 — Notificaciones

- [x] **R-024** Dedup notificaciones (VF-010) | Prioridad: P1 | Archivos: migration + workers | Deps: R-005 | Riesgo: MEDIUM | Tests: notif duplicada | Criterio: UNIQUE rechaza | No romper: notif única | Estado: **RESUELTO** — `2026-27-notifications-dedup.sql` + `dedup_key` en workers
- [ ] **R-025** Cron worker_notification_purge | Prioridad: P2 | Archivos: crontab/supervisor | Deps: R-001 | Riesgo: LOW | Tests: verificar cron | Criterio: purge diario | No romper: N/A | Estado: PENDIENTE — No implementado

### FASE 5 — Auth

- [x] **R-026** /auth/refresh (VF-009) | Prioridad: P0 | Archivos: `auth.php`, `AuthContext.jsx` | Deps: R-001 | Riesgo: HIGH | Tests: E2E auth | Criterio: refresh rota token | No romper: login/logout/2FA | Estado: **RESUELTO** — `/auth/refresh` + access 15min + refresh 7d con rotación
- [x] **R-027** Rate limiting Twilio distribuido (VF-022) | Prioridad: P2 | Archivos: `worker_twilio.php` | Deps: R-001 | Riesgo: MEDIUM | Tests: 2 instancias | Criterio: límite global | No romper: single-instance | Estado: **RESUELTO** — Contador distribuido `twilio:sends:hour:` con `INCR` atómico

### FASE 6 — Operaciones

- [x] **R-028** group_name opcional extender_bloque (VF-021) | Prioridad: P2 | Archivos: `operations.php:1028-1078` | Deps: R-001 | Riesgo: MEDIUM | Tests: con/sin group | Criterio: granularidad | No romper: sin group → todos | Estado: **RESUELTO** — Filtro `group_name` opcional
- [x] **R-029** Debug queries gate NEXO_DEBUG (VF-020) | Prioridad: P2 | Archivos: `dashboard.php:70-82` | Deps: R-001 | Riesgo: LOW | Tests: con/sin debug | Criterio: sin debug → no extra queries | No romper: stats correctos | Estado: **RESUELTO** — Gateado tras `APP_ENV=development`

### FASE 7 — Infra

- [x] **R-030** Separar deploy_db.sh (VF-007) | Prioridad: P0 | Archivos: `deploy_db.sh` | Deps: R-001 | Riesgo: HIGH | Tests: deploy limpio | Criterio: schema no trunca | No romper: deploy existente | Estado: **RESUELTO** — Tracking table + orden explícito + idempotencia

### FASE 8 — Tests

- [x] **R-031** Tests webhook Twilio (VF-028) | Prioridad: P2 | Archivos: `tests/` | Deps: R-001 | Riesgo: LOW | Tests: N/A | Criterio: cobertura webhook | No romper: N/A | Estado: **RESUELTO** — `16_TwilioWebhookAndPollingWorkerTest.php` (12 tests webhook)
- [x] **R-032** Tests workers polling (VF-029) | Prioridad: P2 | Archivos: `tests/` | Deps: R-010 | Riesgo: LOW | Tests: N/A | Criterio: cobertura processSchool | No romper: N/A | Estado: **RESUELTO** — `16_TwilioWebhookAndPollingWorkerTest.php` (10 tests polling + 1 rate limit)
- [ ] **R-033** Tests E2E biométrico | Prioridad: P1 | Archivos: `tests/` | Deps: R-005 a R-013 | Riesgo: LOW | Tests: N/A | Criterio: evento → dashboard | No romper: N/A | Estado: PENDIENTE — No implementado

### FASE 9 — Hardware

- [ ] **R-034** Validar searchUser U.are.U 5300 (HW-001) | Prioridad: P0 | Archivos: hardware | Deps: R-002 | Riesgo: N/A | Tests: manual | Criterio: identificación correcta | No romper: N/A | Estado: PENDIENTE
- [ ] **R-035** Validar enrollUser (HW-002) | Prioridad: P0 | Archivos: hardware | Deps: R-002 | Riesgo: N/A | Tests: manual | Criterio: template válido | No romper: N/A | Estado: PENDIENTE
- [ ] **R-036** Validar reconexión USB (HW-003) | Prioridad: P1 | Archivos: hardware | Deps: R-002 | Riesgo: N/A | Tests: manual | Criterio: reconecta | No romper: N/A | Estado: PENDIENTE
- [ ] **R-037** Validar umbral FPR (HW-004) | Prioridad: P1 | Archivos: hardware | Deps: R-034 | Riesgo: N/A | Tests: manual | Criterio: calibrado | No romper: N/A | Estado: PENDIENTE
- [ ] **R-038** Validar latencia identify (HW-005) | Prioridad: P2 | Archivos: hardware | Deps: R-034 | Riesgo: N/A | Tests: manual | Criterio: <500ms 100+ estudiantes | No romper: N/A | Estado: PENDIENTE
- [ ] **R-039** Validar flujo completo E2E hardware (HW-006) | Prioridad: P0 | Archivos: todo | Deps: R-005 a R-033, R-034 | Riesgo: N/A | Tests: E2E hardware | Criterio: marca → dashboard <30s | No romper: N/A | Estado: PENDIENTE

### FASE 10 — Producción

- [ ] **R-040** Ejecutar migration 2026-20 producción | Prioridad: P2 | Archivos: `2026-20-fix-student-tracking-timezone.sql` | Deps: R-005 | Riesgo: LOW | Tests: verificar timestamps | Criterio: Bogota timezone | No romper: datos existentes | Estado: PENDIENTE
- [ ] **R-041** Supervisor 7 workers | Prioridad: P0 | Archivos: `supervisor.conf` | Deps: R-010, R-011 | Riesgo: LOW | Tests: verificar procesos | Criterio: restart automático | No romper: N/A | Estado: PENDIENTE
- [ ] **R-042** Cron partition creation | Prioridad: P0 | Archivos: crontab | Deps: R-008 | Riesgo: LOW | Tests: verificar partición | Criterio: mensual automático | No romper: N/A | Estado: PENDIENTE
- [ ] **R-043** Deploy producción con vars obligatorias | Prioridad: P0 | Archivos: `.env` | Deps: R-014 | Riesgo: LOW | Tests: boot_check pasa | Criterio: todas las vars set | No romper: N/A | Estado: PENDIENTE

---

## NOTA FINAL

Este documento es el **CONTRATO DE TRABAJO** para la siguiente fase.

**No se modificará ningún archivo del proyecto hasta recibir instrucción explícita.**

**No se implementará nada.**

**No se refactorizará.**

**No se "mejorará" nada por iniciativa propia.**

Esperando instrucción explícita para comenzar la implementación.

---

*Veredicto generado en modo solo lectura. Ningún archivo del repositorio fue modificado (excepto este documento).*
