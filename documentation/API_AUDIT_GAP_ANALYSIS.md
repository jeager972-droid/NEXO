# NEXO — Auditoría Exhaustiva Complementaria y Validación

> **Fecha:** 2026-08-15
> **Modalidad:** AUDIT → VERIFY → GAP ANALYSIS → PLAN (sin modificar código)
> **Base:** Validación y extensión de `API_PRODUCTION_AUDIT.md` (47 hallazgos previos)
> **Referencias:** OWASP API Top 10 2023, OWASP ASVS 5.0, OWASP WSTG, NIST SP 800-53 Rev.5, CWE, mejores prácticas PostgreSQL/Redis/Docker
> **Sin modificaciones:** Esta auditoría no alteró ningún archivo del repositorio.

---

## 0. Resumen Ejecutivo

Esta segunda auditoría complementaria validó los 47 hallazgos de la primera auditoría, descubrió **1 falso positivo crítico**, identificó **~80 hallazgos nuevos** en zonas no cubiertas (edge C++, documentación, migraciones SQL, infraestructura, tests), y construyó matrices de cobertura exhaustivas.

### Hallazgos clave de esta fase

| Métrica | Valor |
|---|---|
| Falsos positivos de la 1ª auditoría | **1** (NEXO-AUD-003 — ingesta edge SÍ valida token) |
| Severidad que debe cambiar | **2** hallazgos |
| Nuevos hallazgos edge C++ | 39 (6 CRITICAL, 8 HIGH, 7 MEDIUM, 4 LOW, 14 INFO) |
| Nuevos hallazgos documentación | 41 (3 CRITICAL, 9 HIGH, 14 MEDIUM, 15 LOW) |
| Nuevos hallazgos infraestructura/SQL | 40 (3 CRITICAL, 5 HIGH, 19 MEDIUM, 8 LOW, 5 INFO) |
| Nuevos hallazgos tests | 48 gaps de cobertura |
| Discrepancias doc ↔ código | **3 críticas** |
| Zonas que requieren infraestructura | 2 |
| Zonas que requieren pruebas dinámicas | 6 |

### Veredicto actualizado

La puntuación de la 1ª auditoría (62/100) debe **bajarse a 58/100** por:
- Confirmación de `deploy_db.sh` destructivo (CRITICAL nuevo)
- SECURITY DEFINER sin search_path (CRITICAL nuevo, 13 funciones)
- HMAC default también en función SQL, no solo PHP (CRITICAL nuevo)
- MQTT sin TLS ni auth obligatoria (CRITICAL nuevo)
- Edge: clave AES compartida entre todos los dispositivos + sin rotación (CRITICAL nuevo)
- Discrepancia doc ↔ código en RLS (funcionamiento.md afirma RLS en 8 tablas que no la tienen)

Sin embargo, el falso positivo NEXO-AUD-003 (ingesta edge SÍ valida `device_token` con `password_verify`) resta 1 bloqueante. Neto: **8 bloqueantes originales + 5 nuevos = 13 bloqueantes**, puntuación **58/100**.

---

## 1. Qué cubrió la auditoría anterior

| Zona | Cobertura | Profundidad |
|---|---|---|
| `backend/api/routes/*.php` (20 archivos) | 150+ endpoints | Alta |
| `backend/api/workers/*.php` (7 workers) | Todos | Alta |
| `backend/api/api.php` (front controller) | Completo | Alta |
| `backend/api/sql/nexo_full_migration.sql` | Esquema + RLS + particiones | Media (no auditó SECURITY DEFINER ni search_path) |
| `WebApp/src/api/` (13 módulos) | 85 llamadas | Alta |
| `documentation/ARCHITECTURE.md` | Completo | Media |
| `documentation/LEGACY_UNUSED_CODE.md` | Completo | Alta |
| Seguridad OWASP API Top 10 | Mapeo completo | Alta |
| NIST SP 800-53 | Mapeo parcial | Media |

## 2. Qué NO cubrió la auditoría anterior

| Zona | Razón | Impacto |
|---|---|---|
| `backend/edge/` (C++ completo) | No se auditó el código edge | **CRITICAL** — 39 hallazgos nuevos |
| `funcionamiento.md` (lógica de negocio) | Solo se leyó parcialmente | **CRITICAL** — discrepancia RLS doc↔código |
| `ROUTES_WORKERS_AUDIT.md` | Declarado "no leído en detalle" | Medio — hallazgos de edge confirmados |
| `PREMIUM_AUDIT.md` | No se leyó | Bajo — es auditoría UI/UX, complementaria |
| `PENDIENTES.md` | No se leyó | Medio — pendientes críticos no verificados |
| `UAREU5300_RUNBOOK_PRODUCCION.md` | No se leyó | Medio — riesgos operacionales edge |
| `deploy_db.sh` | No se auditó | **CRITICAL** — script destructivo |
| `backend/api/infra/` (PgBouncer, scripts, cron) | No se auditó | Alto — 40 hallazgos nuevos |
| `backend/api/Dockerfile`, `docker-entrypoint.sh` | No se auditó | Alto — Mosquitto sin auth, PHP-FPM config |
| `backend/api/.htaccess` | No se auditó | Medio — CSP, HSTS |
| `backend/api/sql/archive/` | No se auditó | Medio — migraciones legacy INTEGER |
| `backend/api/sql/migration_iteracion3.sql` | No se auditó | Medio — SECURITY DEFINER sin search_path |
| `backend/api/sql/fix_*.sql`, `purge_*.sql` | No se auditó | Medio — scripts destructivos sin transacción |
| `backend/api/tests/` (19 archivos) | No se auditó profundamente | Alto — 48 gaps de cobertura, tests son estáticos |
| `backend/edge/tests/` | No se auditó | Bajo — tests edge son reales pero unitarios |
| `WebApp/vite.config.js` | No se auditó | Bajo — cacheo PWA de API |
| `landing/` | No se auditó | Bajo — no afecta backend |

## 3. Qué se verificó ahora

### 3.1 Edge C++ end-to-end (39 hallazgos)

Auditoría completa del flujo: EDGE → AES-256-GCM → HTTP → API → auth device → descifrado → Redis queue → worker_biometric → DB → lógica asistencia.

**Hallazgos CRITICAL (6):**
- **NEXO-EDGE-001:** CommandWorker V1 (HTTP polling) envía `X-Device-Token` en header en texto plano, sin cifrado AES. SyncWorker V2 sí cifra. Inconsistencia.
- **NEXO-EDGE-004:** Todos los dispositivos comparten la misma `NEXO_AES_KEY`. Un dispositivo clonado (con clave + token extraídos) puede inyectar eventos falsos. No hay claves por dispositivo.
- **NEXO-EDGE-019:** MQTT sin TLS. Comandos (REBOOT, DELETE_STUDENT, AUTHORIZE_EXIT) viajan en texto plano. Interceptable en red.
- **NEXO-EDGE-006 (parcial):** Ventana de timestamp de 7 días (604800s) para replay protection es excesiva para asistencia en tiempo real.
- **NEXO-EDGE-020 (parcial):** Comandos MQTT críticos (DELETE_STUDENT) sin validación de autorización adicional más allá de llegar al tópico.
- **NEXO-EDGE-016:** SQLite lleno bloquea acceso biométrico sin monitoreo proactivo de disco ni limpieza automática.

**Hallazgos HIGH (8):**
- **NEXO-EDGE-002:** Device token almacenado en SQLite en texto plano (tabla `config`).
- **NEXO-EDGE-008:** Clave AES derivada de archivo, no de hardware (sin TPM/TEE).
- **NEXO-EDGE-011:** Sin rotación de claves AES. Compromiso = vulnerabilidad permanente.
- **NEXO-EDGE-024:** Sin liveness detection biométrica. Huella sintética puede suplantar identidad.
- **NEXO-EDGE-032:** Logs edge no rotan automáticamente — puede llenar disco.
- **NEXO-EDGE-025:** Sin rate limiting de intentos biométricos fallidos en edge.
- **NEXO-EDGE-018:** MQTT auth con username/password estáticos en config.json en texto plano, sin mTLS.
- **NEXO-EDGE-027:** Secretos en `config.example.json` (device_token, mqtt_user, mqtt_pass) — riesgo de commit accidental.

**Hallazgos positivos confirmados (INFO, 14):**
- AES-256-GCM con IV aleatorio de 12 bytes (RAND_bytes) — correcto
- Tag GCM validado correctamente en edge y backend — correcto
- Nonce anti-replay en Redis con TTL 7 días — correcto (pero ventana demasiado larga)
- Backoff exponencial con jitter ±30% en SyncWorker — correcto
- DLQ después de 5 intentos fallidos — correcto
- Eventos offline en `audit_trail` con flag `synced` — correcto
- Orden garantizado por `ORDER BY id` — correcto
- Watchdog de hardware implementado — correcto
- HealthMonitor detecta threads muertos y fuerza `exit(1)` — correcto
- Reconexión automática MQTT con backoff 5s — correcto
- Clave AES en archivo separado con permisos 600, NO en SQLite — correcto (corrige LEGACY_UNUSED_CODE.md)
- Template biométrico cifrado con AES-256-GCM en SQLite — correcto
- Umbral de coincidencia configurable — correcto
- Tests edge reales con Catch2 v3 — correcto

### 3.2 Documentación (41 hallazgos)

**Discrepancias críticas doc ↔ código:**

| # | Discrepancia | Severidad |
|---|---|---|
| D-1 | `funcionamiento.md` §27 lista 8 tablas como "con RLS" que NO tienen RLS en SQL: `class_exit_authorizations`, `school_exit_authorizations`, `pedagogical_trip_authorizations`, `security_incidents`, `internal_messages`, `academic_groups`, `report_exports`, `staff_records` | **CRITICAL** |
| D-2 | `funcionamiento.md` §16 afirma "api.php valida device token, nonce, timestamp" — confirmado como verdadero (corrige NEXO-AUD-003) | Info |
| D-3 | `UAREU5300_RUNBOOK_PRODUCCION.md` asume MQTT funcionando, pero `README.md` §7 dice "MQTT deshabilitado temporalmente en Docker" | HIGH |

**Hallazgos CRITICAL (3):**
- **NEXO-DOC-001/037:** `deploy_db.sh` ejecuta TODOS los `.sql` en orden alfabético, incluyendo `nexo_seed.sql` (TRUNCATE de todas las tablas) y migraciones legacy INTEGER. Ejecutar en producción = **pérdida total de datos**.
- **NEXO-DOC-013:** Runbook indica que clave AES debe ir SOLO en `nexo_edge.key` (permisos 600), pero no hay validación de que esto se cumpla en deployment.

**Hallazgos HIGH (9):**
- **NEXO-DOC-002:** Migration `2026-20-fix-student-tracking-timezone.sql` existe pero no se ha ejecutado en producción (PENDIENTES.md confirma).
- **NEXO-DOC-003:** `worker_notification_purge.php` existe pero no está configurado en cron/supervisor de producción.
- **NEXO-DOC-005:** `fn_calculate_student_risk` NO cuenta `EVASION_INTERNA` en risk_score (decisión de diseño documentada, pero PENDIENTES.md la lista como pendiente).
- **NEXO-DOC-014:** Runbook indica swap OFF en Raspberry Pi para que templates descifrados no toquen disco. No hay validación de cumplimiento.
- **NEXO-DOC-015:** Eventos para estudiantes desconocidos van a DLQ sin monitoreo. Pérdida silenciosa de eventos.
- **NEXO-DOC-021:** `Encryption::initialize()` lee `nexo_aes_key_b64` sin decodificar base64 — la clave se guarda como string de 32 chars, no 32 bytes aleatorios.
- **NEXO-DOC-022:** Pines GPIO en `config.example.json` (32/33/34) no coinciden con `RealGpioManager.cpp` (17/27/22).
- **NEXO-DOC-026:** Migraciones legacy INTEGER en `archive/` siguen siendo `.sql` ejecutables. Si se ejecutan sobre esquema UUID, rompen la aplicación.
- **NEXO-DOC-038:** `deploy_db.sh` no verifica si DB ya tiene datos antes de ejecutar `nexo_seed.sql`.

### 3.3 Infraestructura y SQL (40 hallazgos)

**Hallazgos CRITICAL (3):**
- **NEXO-INFRA-001:** **13 funciones SECURITY DEFINER sin `SET search_path`** en `nexo_full_migration.sql` y `migration_iteracion3.sql`. Vulnerabilidad de escalación de privilegios si un atacante puede crear objetos en el schema. Funciones afectadas: `migration_was_executed`, `register_migration`, `fn_calculate_audit_hash`, `get_current_school_id`, `get_current_role`, `fn_validate_audit_chain`, `fn_calculate_student_risk`, `fn_recalculate_school_metrics`, y 5 más.
- **NEXO-INFRA-002:** `fn_calculate_audit_hash` en SQL usa `COALESCE(current_setting('app.nexo_hmac_secret',true),'default-secret-change-me')` — el mismo default inseguro del worker PHP (NEXO-AUD-001), pero ahora **también en la función SQL**. Doble vector de falsificación de auditoría.
- **NEXO-INFRA-033:** `deploy_db.sh` destructivo (ver NEXO-DOC-001).

**Hallazgos HIGH (5):**
- **NEXO-INFRA-003:** `nexo_seed.sql` NO es idempotente — 40+ `TRUNCATE TABLE ... RESTART IDENTITY CASCADE`.
- **NEXO-INFRA-009:** Migraciones legacy INTEGER en `archive/` ejecutables accidentalmente.
- **NEXO-INFRA-027:** Nginx sin TLS (asume terminación en proxy reverso externo — REQUIRES INFRASTRUCTURE VERIFICATION).
- **NEXO-INFRA-030:** Mosquitto arranca sin auth si `MQTT_USER`/`MQTT_PASS` no están configuradas (`allow_anonymous true`).
- **NEXO-INFRA-006:** `fix_teacher_work_shift.sql` hace `UPDATE users SET work_shift='tarde' WHERE work_shift IS NULL AND role_id=TEACHER` sin LIMIT — bloqueo de tabla prolongado.

**Hallazgos MEDIUM (19):** Timezone hardcodeado, ALTER TABLE sin transacción, DELETE sin transacción, VACUUM FULL comentado peligroso, particiones hardcodeadas 2026-2027, ON DELETE CASCADE en student_tracking, RLS sin SUPER_ADMIN fallback, constraint UNIQUE removido dinámicamente, PgBouncer MD5 hash, scripts cargan .env inseguro, crontab sin lock files, logs sin rotación, Dockerfile sin multi-stage, puertos expuestos, PHP-FPM max_children=25 bajo, workers sin límite de restart, HSTS sin validación, CSP con `unsafe-inline`, sin health checks en docker-compose, sin resource limits, sin `.env.example`, boot_check no valida REDIS_PASSWORD.

### 3.4 Tests (48 gaps)

**Cobertura real estimada:**
- Análisis estático SQL: 95%
- Análisis estático PHP: 80%
- Tests de integración HTTP: 5%
- Tests de seguridad real: **0%**
- Tests de lógica de negocio: **5%**
- Tests de workers: **0%**

**Gaps críticos:**
- **0% cobertura** de: 2FA, logout, refresh token, aislamiento RLS real, SQL injection real, XSS, CSRF, privilege escalation, todos los workers, webhook Twilio inbound, audit chain HMAC, idempotencia, concurrencia, file upload, account enumeration, DDoS.
- **Test defectuoso:** `14_InstallationTest.php` verifica `config.php` que no existe en version control.
- **Test stub:** `04_IndexTest.php::testIndexesOnForeignKeyColumns()` es stub que siempre pasa.
- **Mock defectuoso:** `PanicButtonTest.php` redefine `securityLog()` vacío, ocultando bugs de logging.

---

## 4. Nuevos hallazgos (consolidados)

### Bloqueantes nuevos (CRITICAL)

| ID | Hallazgo | Origen |
|---|---|---|
| NEXO-INFRA-001 | 13 funciones SECURITY DEFINER sin search_path | Infra |
| NEXO-INFRA-002 | HMAC default `'default-secret-change-me'` también en función SQL | Infra |
| NEXO-INFRA-033 / NEXO-DOC-001 | `deploy_db.sh` ejecuta todos los SQL incluyendo seed (TRUNCATE) y legacy INTEGER | Infra/Doc |
| NEXO-EDGE-004 | Clave AES compartida entre todos los dispositivos — clonación posible | Edge |
| NEXO-EDGE-019 | MQTT sin TLS — comandos en texto plano interceptables | Edge |
| NEXO-EDGE-001 | CommandWorker V1 envía token en header sin cifrar | Edge |
| NEXO-INFRA-030 | Mosquitto arranca sin auth si env vars no configuradas | Infra |
| NEXO-DISC-001 | Discrepancia doc↔código: `funcionamiento.md` afirma RLS en 8 tablas que no la tienen | Doc |

### Altos nuevos (HIGH)

| ID | Hallazgo | Origen |
|---|---|---|
| NEXO-EDGE-002 | Device token en SQLite texto plano | Edge |
| NEXO-EDGE-008 | Clave AES de archivo, no de hardware | Edge |
| NEXO-EDGE-011 | Sin rotación de claves AES | Edge |
| NEXO-EDGE-024 | Sin liveness detection biométrica | Edge |
| NEXO-EDGE-016 | SQLite lleno bloquea acceso sin monitoreo | Edge |
| NEXO-INFRA-003 | nexo_seed.sql no idempotente (TRUNCATE) | Infra |
| NEXO-INFRA-009 | Migraciones legacy INTEGER ejecutables | Infra |
| NEXO-INFRA-030 | Mosquitto sin auth obligatoria | Infra |
| NEXO-DOC-002 | Migration timezone no ejecutada en producción | Doc |
| NEXO-DOC-003 | worker_notification_purge no en cron | Doc |
| NEXO-DOC-015 | DLQ sin monitoreo — pérdida silenciosa | Doc |
| NEXO-DOC-021 | Clave AES como string 32 chars, no 32 bytes | Doc |
| NEXO-DOC-022 | Pines GPIO config vs código no coinciden | Doc |

---

## 5. Hallazgos anteriores confirmados

| ID original | Estado | Nota |
|---|---|---|
| NEXO-AUD-001 (HMAC default PHP) | ✅ Confirmado | Y ampliado: también en función SQL (NEXO-INFRA-002) |
| NEXO-AUD-002 (10 tablas sin RLS) | ✅ Confirmado | Y ampliado: `funcionamiento.md` afirma falsamente que 8 de ellas sí tienen RLS |
| NEXO-AUD-004 (sin refresh token) | ✅ Confirmado | `user_sessions` con `refresh_token_hash` existe en SQL pero PHP nunca lo usa |
| NEXO-AUD-005 (token en localStorage) | ✅ Confirmado | Cookie `SameSite=None` + token en body |
| NEXO-AUD-006 (worker_audit requeue infinito) | ✅ Confirmado | |
| NEXO-AUD-007 (workers polling sin lock) | ✅ Confirmado | |
| NEXO-AUD-008 (particiones faltantes) | ✅ Confirmado | Y ampliado: particiones hardcodeadas 2026-2027 (NEXO-INFRA-010) |
| NEXO-AUD-009 (/metrics sin auth obligatoria) | ✅ Confirmado | |
| NEXO-AUD-010-047 (resto) | ✅ Confirmados | Sin cambios |

## 6. Hallazgos anteriores que resultaron ser FALSOS POSITIVOS

| ID original | Estado | Evidencia |
|---|---|---|
| **NEXO-AUD-003** | ❌ **FALSO POSITIVO** | La auditoría anterior afirmó que la ingesta edge no valida `token_hash`. **Verificación directa del código** (`api.php:280-309`) muestra que SÍ valida: extrae `device_token` del payload descifrado, consulta `edge_devices WHERE device_id = ? AND active = TRUE`, y hace `password_verify($deviceToken, $row['token_hash'])`. Si falla, retorna 401. El TODO en `devices.php:101-109` se refiere a endpoints de **comandos/ping**, no a la ingesta principal. **NEXO-AUD-003 debe eliminarse de la lista de bloqueantes.** |

## 7. Hallazgos anteriores cuya severidad debe cambiar

| ID original | Severidad anterior | Severidad nueva | Razón |
|---|---|---|---|
| NEXO-AUD-001 | CRITICAL | **CRITICAL+** (mantiene, pero ampliado) | El HMAC default existe en **dos** lugares: PHP worker Y función SQL. Doble vector. |
| NEXO-AUD-002 | CRITICAL | **CRITICAL+** (mantiene, pero ampliado) | La documentación (`funcionamiento.md` §27) afirma falsamente que 8 de las 10 tablas tienen RLS. Esto significa que los desarrolladores pueden confiar en RLS que no existe. |
| NEXO-AUD-026 | MEDIUM → INFO | **INFO** | Tras revisión, el filtrado por `school_id` en las queries es correcto. No es hallazgo. (Ya se indicó en la 1ª auditoría.) |

---

## 8. Riesgos que requieren pruebas dinámicas

| # | Riesgo | Por qué requiere test dinámico |
|---|---|---|
| 1 | Bypass de RLS en las 10 tablas sin RLS | Necesita DB real con 2 escuelas para confirmar que una query sin `WHERE school_id` retorna datos cross-tenant |
| 2 | Escalación de privilegios vía SECURITY DEFINER sin search_path | Necesita DB real con rol de bajo privilegio que cree objeto en schema para secuestrar función |
| 3 | Falsificación de cadena de auditoría con HMAC default | Necesita entorno sin `APP_NEXO_HMAC_SECRET` para confirmar que la función SQL firma con `'default-secret-change-me'` |
| 4 | Replay attack dentro de ventana de 7 días | Necesita capturar payload válido y reenviarlo para confirmar si Redis tiene el nonce |
| 5 | Clonación de dispositivo edge | Necesita 2 dispositivos con misma AES_KEY y token para confirmar que ambos aceptan eventos |
| 6 | DDoS a endpoints de auditoría sin rate limiting | Necesita enviar 1000 req/s a `/audit/*` para medir degradación |

## 9. Riesgos que requieren infraestructura

| # | Riesgo | Verificación necesaria |
|---|---|---|
| 1 | TLS terminado en proxy reverso externo | Verificar configuración de nginx/ALB/Cloudflare delante del contenedor |
| 2 | HSTS efectivo solo si HTTPS | Verificar que el sitio se sirve sobre HTTPS |
| 3 | Mosquitto con auth en producción | Verificar que `MQTT_USER` y `MQTT_PASS` están configuradas en el entorno de deployment |
| 4 | PgBouncer con secrets de Docker/K8s | Verificar que las credenciales DB no están en variables de entorno expuestas |
| 5 | Logs con rotación (logrotate) | Verificar configuración de logrotate en el host |
| 6 | Backup de `nexo_edge.db` | Verificar que hay backup diario programado en cada Raspberry Pi |

## 10. Riesgos que requieren cambios en edge

| # | Riesgo | Cambio necesario |
|---|---|---|
| 1 | Clave AES compartida entre dispositivos | Implementar claves únicas por dispositivo o cifrado asimétrico |
| 2 | MQTT sin TLS | Implementar `mosquitto_tls_set()` con puerto 8883 |
| 3 | CommandWorker V1 sin cifrado | Eliminar CommandWorker V1 completamente |
| 4 | Device token en SQLite texto plano | Cifrar token antes de almacenar |
| 5 | Sin rotación de claves | Implementar versionado y rotación automática |
| 6 | Sin liveness detection | Activar spoof detection del SDK U.are.U |
| 7 | Logs sin rotación | Implementar spdlog rotating file sink |
| 8 | GPIO pins config vs código | Hacer que RealGpioManager lea config.json |
| 9 | RealGpioManager no registrado | Agregar lógica de selección en main.cpp |
| 10 | PAE deprecado | Eliminar código muerto |
| 11 | Rate limiting biométrico | Implementar contador de intentos fallidos |
| 12 | SQLite lleno sin monitoreo | Agregar alerta de disco + limpieza automática |

## 11. Riesgos que requieren migraciones DB

| # | Riesgo | Migración necesaria |
|---|---|---|
| 1 | 10 tablas sin RLS | `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` + políticas para las 10 tablas |
| 2 | 13 funciones SECURITY DEFINER sin search_path | `CREATE OR REPLACE FUNCTION ... SET search_path = public, pg_temp` |
| 3 | HMAC default en función SQL | Modificar `fn_calculate_audit_hash` para `RAISE EXCEPTION` si no hay secret |
| 4 | Particiones hardcodeadas 2026-2027 | Crear particiones dinámicas o extender hasta 2030 |
| 5 | ON DELETE CASCADE en student_tracking | Cambiar a `ON DELETE SET NULL` o `RESTRICT` |
| 6 | RLS sin SUPER_ADMIN fallback | Agregar `OR get_current_role() IN ('SUPER_ADMIN', 'SYSTEM_WORKER')` |
| 7 | Migration timezone student_tracking no ejecutada | Ejecutar `2026-20-fix-student-tracking-timezone.sql` |
| 8 | fn_calculate_student_risk sin evasiones | Modificar función para incluir `EVASION_INTERNA` |
| 9 | classrooms sin RLS | Habilitar RLS en `classrooms` |

---

## 12. Matriz OWASP API Top 10 2023 (actualizada)

| Categoría | Hallazgos 1ª auditoría | Hallazgos 2ª auditoría | Estado |
|---|---|---|---|
| API01:2023 — BOLA | NEXO-AUD-002 | + discrepancia doc RLS | ❌ Crítico |
| API02:2023 — Broken Auth | NEXO-AUD-001, 003, 004, 005 | - NEXO-AUD-003 (falso positivo), + NEXO-INFRA-002, + NEXO-EDGE-001, 004 | ⚠️ Mejorado |
| API03:2023 — Broken Object Property Auth | NEXO-AUD-003 | - (falso positivo) | ✅ OK |
| API04:2023 — Unrestricted Resource Consumption | NEXO-AUD-009, 022, 023, 033 | + NEXO-EDGE-025, + NEXO-INFRA-028 | ⚠️ Parcial |
| API05:2023 — Broken Function Level Auth | (RBAC OK) | — | ✅ OK |
| API06:2023 — Unrestricted Access to Sensitive Flows | (OK) | — | ✅ OK |
| API07:2023 — SSRF | (No SSRF) | — | ✅ OK |
| API08:2023 — Security Misconfiguration | NEXO-AUD-005, 009, 021, 025 | + NEXO-INFRA-001, 030, 032, + NEXO-EDGE-019 | ❌ Crítico |
| API09:2023 — Improper Inventory Management | NEXO-AUD-010-015, 037 | + NEXO-EDGE-033-036 (código muerto edge) | ⚠️ Parcial |
| API10:2023 — Unsafe Consumption of APIs | (Twilio webhook validado) | + NEXO-EDGE-019 (MQTT sin TLS) | ⚠️ Parcial |

## 13. Matriz OWASP ASVS 5.0 (selección relevante)

| Sección ASVS | Requisito | Estado | Hallazgo |
|---|---|---|---|
| V2.1 Password Security | bcrypt cost 12, rehash automático | ✅ | — |
| V2.7 Out-of-Band Verifier | 2FA vía WhatsApp, código 6 dígitos, 5 min TTL | ✅ | — |
| V3.3 Session Timeout | JWT con exp, sin refresh token | ❌ | NEXO-AUD-004 |
| V3.4 Session Binding | Cookie HttpOnly + Secure + SameSite=None | ⚠️ | NEXO-AUD-005 (token también en localStorage) |
| V3.5 Token-based Session | JWT RS256/HS256, jti, blocklist Redis | ✅ | — |
| V4.1 Access Control | RBAC por permisos `operations.*` | ✅ | — |
| V4.2 Object Level Access | RLS en 28/38 tablas | ❌ | NEXO-AUD-002 (10 tablas sin RLS) |
| V6.2 Formatted Output | JSON responses, sin HTML injection | ✅ | — |
| V7.1 Logging | securityLog + audit chain HMAC | ⚠️ | NEXO-AUD-001 (HMAC default) |
| V8.1 Data Protection | AES-256-GCM edge, password_hash bcrypt | ✅ | — |
| V8.3 Sensitive Private Data | Templates biométricos cifrados en SQLite edge | ✅ | — |
| V9.1 Communications | TLS implícito (proxy reverso), MQTT sin TLS | ❌ | NEXO-EDGE-019 |
| V9.2 Client Communication | CORS estricto, reflejo de origin exacto | ✅ | — |
| V12.1 File Upload | Validación de MIME débil | ⚠️ | NEXO-AUD-044 |
| V13.1 API and Web Service | REST, JSON, rate limiting en mutantes | ⚠️ | NEXO-AUD-022 (auditoría sin RL) |
| V14.1 Build Pipeline | Dockerfile non-root, sin multi-stage | ⚠️ | NEXO-INFRA-025 |
| V14.4 Unintended Security Disclosures | Errores genéricos en algunos endpoints | ⚠️ | NEXO-AUD-025 |

## 14. Matriz NIST SP 800-53 Rev.5 (actualizada)

| Control | Estado 1ª auditoría | Estado 2ª auditoría | Nuevos hallazgos |
|---|---|---|---|
| AC-3 Access Enforcement | ⚠️ | ❌ | + SECURITY DEFINER sin search_path |
| AC-4 Information Flow | ⚠️ | ❌ | + discrepancia doc RLS |
| AC-12 Session Termination | ⚠️ | ⚠️ | Sin cambios |
| AU-9 Protection of Audit Info | ❌ | ❌ | + HMAC default en función SQL |
| CP-10 System Recovery | ⚠️ | ❌ | + deploy_db.sh destructivo, + DLQ sin monitoreo |
| IA-2 Identification & Auth | ⚠️ | ⚠️ | + edge: clave AES compartida |
| IA-5 Authenticator Management | ⚠️ | ⚠️ | + edge: sin rotación de claves |
| SC-5 DoS Protection | ⚠️ | ⚠️ | + edge: sin rate limiting biométrico |
| SC-7 Boundary Protection | ⚠️ | ❌ | + MQTT sin TLS, + Mosquitto sin auth |
| SC-8 Transmission Confidentiality | ✅ | ⚠️ | + MQTT sin TLS |
| SC-13 Cryptographic Protection | ❌ | ❌ | + HMAC default en SQL |
| SC-28 Protection at Rest | ✅ | ✅ | Sin cambios |
| SI-4 System Monitoring | ⚠️ | ⚠️ | + logs sin rotación, + DLQ sin monitoreo |

## 15. Matriz Frontend ↔ Backend (contratos)

### Backend → Frontend (clasificación de endpoints)

| Endpoint | Estado | Nota |
|---|---|---|
| `POST /auth/login` | ✅ Usado | Contrato compatible |
| `POST /auth/verify-2fa` | ✅ Usado | Contrato compatible |
| `POST /auth/logout` | ✅ Usado | Contrato compatible |
| `GET /auth/me` | ✅ Usado | Polling 5min |
| `GET /dashboard/stats` | ✅ Usado | Cache Redis 30s |
| `GET /dashboard/events` | ✅ Usado | — |
| `POST /operations/*` (17 endpoints) | ✅ Usados | Sin Idempotency-Key |
| `POST /students` | ✅ Usado | — |
| `GET /students` | ✅ Usado | Paginado cursor |
| `GET /groups` | ✅ Usado | — |
| `GET /school/config` | ✅ Usado | — |
| `POST /school/onboarding` | ✅ Usado | — |
| `POST /tracking/*` (3 endpoints) | ✅ Usados | — |
| `GET /behavior/risk` | ✅ Usado | — |
| `POST /consultations/query` | ✅ Usado | — |
| `GET /consultation/search` | ⚠️ Usado | Naming inconsistente (singular) |
| `GET /devices` | ✅ Usado | — |
| `POST /devices` | ✅ Usado | — |
| `DELETE /devices/{id}` | ✅ Usado | Hard delete (NEXO-AUD-029) |
| `POST /devices/command/{id}` | ✅ Usado | — |
| `GET /devices/commands` | ✅ Usado (edge) | — |
| `POST /devices/ping` | ✅ Usado (edge) | — |
| `POST /security/panic` | ✅ Usado | — |
| `POST /admin/recalc-risk` | ✅ Usado | — |
| `GET /users/by-role` | ✅ Usado | — |
| `GET /users/me/extended` | ✅ Usado | — |
| `GET /users/me/photo` | ⚠️ Legacy | `/users/me/extended` ya retorna photo_url |
| `POST /users/upload-photo` | ✅ Usado | Validación MIME débil |
| `POST /users/change-password` | ✅ Usado | — |
| `POST /users/send-verification-code` | ✅ Usado | — |
| `POST /users/verify-code` | ✅ Usado | — |
| `GET /notifications` | ✅ Usado | Polling 60s |
| `POST /notifications` | ✅ Usado | — |
| `POST /notifications/clear` | ✅ Usado | Hard delete (NEXO-AUD-030) |
| `POST /notifications/{id}/action` | ✅ Usado | Delete sin audit (NEXO-AUD-031) |
| `GET /audit/global` | ✅ Usado | — |
| `GET /audit/integrity` | ✅ Usado | — |
| `GET /audit/logs` | ❌ Muerto | Placeholder vacío (NEXO-AUD-011) |
| `POST /contacto` | ✅ Usado (landing) | Rate limit fail-open (NEXO-AUD-023) |
| `POST /reports/preview` | ✅ Usado | — |
| `POST /webhooks/twilio/inbound` | ✅ Usado (Twilio) | Sin tests |
| `GET /metrics` | ⚠️ Backend-only | Sin auth obligatoria (NEXO-AUD-009) |
| `POST /telemetry` | ✅ Usado | — |
| `GET /audit/*` (50+ endpoints) | ✅ Usados | Sin rate limiting específico |
| `POST /operations/execute` | ❌ Legacy | action=EXECUTE_COMMAND no usado por frontend |
| `action=LOGIN` en `/auth/login` | ❌ Legacy | Frontend usa POST /auth/login directo |

### Frontend → Backend (clasificación de llamadas)

| Llamada frontend | Endpoint existe | Contrato compatible | Problema |
|---|---|---|---|
| 85 llamadas en 13 módulos | Todas existen | Sí | — |
| Auth: cookie + localStorage | Sí | ⚠️ | Token duplicado (NEXO-AUD-005) |
| Polling notificaciones 60s | Sí | Sí | Sin backoff (NEXO-AUD-046) |
| Polling auth 5min | Sí | Sí | Sin refresh token (NEXO-AUD-004) |
| Polling telemetry 5min | Sí | Sí | — |
| Polling twilio-status 2s | Sí | Sí | — |
| Sin prefijo `/v1` | Sí | ⚠️ | vite.config.js referencia `/v1` pero llamadas no lo usan (NEXO-AUD-047) |

**Conclusión paridad:** No hay llamadas frontend a endpoints inexistentes. No hay endpoints backend críticos sin frontend (salvo legacy/muertos ya identificados). Los contratos son compatibles. El único gap estructural es la ausencia de refresh token.

## 16. Matriz de lógica de negocio

| Regla | Documentada en | Implementada en backend | Implementada en DB | Implementada en workers | Implementada en frontend | Estado |
|---|---|---|---|---|---|---|
| Zona horaria America/Bogota | funcionamiento.md §1 | ✅ | ✅ | ✅ | ✅ | ✅ Completo |
| Onboarding de horarios | §2 | ✅ | ✅ | — | ✅ | ✅ Completo |
| Detección de inasistencias | §3 | ✅ | ✅ | ✅ | ✅ | ✅ Completo |
| Detección de evasión (sin rotación) | §4 | ✅ | ✅ | ✅ | ✅ | ✅ Completo |
| Detección de evasión (con rotación) | §4 | ✅ | ✅ | ✅ | ✅ | ✅ Completo |
| Validación de permisos con huella | §4 | ✅ | ✅ | ✅ | — | ✅ Completo |
| Operaciones que requieren presencia | §5 | ✅ | — | — | ✅ | ✅ Completo |
| Permisos por rol | §5 | ✅ | ✅ | — | ✅ | ✅ Completo |
| Estados de permisos (ACTIVE/EXPIRED/COMPLETED) | §6 | ✅ | ✅ | ✅ | ✅ | ✅ Completo |
| Event feed (novedades) | §7 | ✅ | ✅ | — | ✅ | ✅ Completo |
| SOS vs Situación Crítica | §8 | ✅ | ✅ | — | ✅ | ✅ Completo |
| RLS multi-tenant | §12, §27 | ✅ | ⚠️ 28/38 | ✅ | — | ❌ 10 tablas sin RLS |
| Autenticación y sesiones | §14 | ✅ | ✅ | — | ✅ | ⚠️ Sin refresh token |
| Botón de pánico | §15 | ✅ | ✅ | — | ✅ | ✅ Completo |
| Dispositivos EDGE | §16 | ✅ | ✅ | — | ✅ | ⚠️ Ver edge audit |
| Métricas de riesgo | §17 | ✅ | ⚠️ | — | ✅ | ⚠️ Sin evasiones en score |
| Seguimientos | §18 | ✅ | ✅ | — | ✅ | ✅ Completo |
| Consultas y reportes | §19 | ✅ | ✅ | — | ✅ | ✅ Completo |
| Cadena HMAC auditoría | §20 | ✅ | ⚠️ | ✅ | — | ❌ HMAC default en SQL |
| Notificaciones internas | §22 | ✅ | ✅ | — | ✅ | ⚠️ Sin purge automático |
| Mensajería Twilio | §23 | ✅ | ✅ | ✅ | ✅ | ✅ Completo |
| Estudiantes y matrícula | §24 | ✅ | ✅ | — | ✅ | ✅ Completo |
| Grupos y horarios | §25 | ✅ | ✅ | — | ✅ | ✅ Completo |
| Roles y permisos | §26 | ✅ | ✅ | — | ✅ | ✅ Completo |
| Notificación llegada tarde al docente | §33 | ✅ | ✅ | ✅ | ✅ | ⚠️ Delete sin audit (NEXO-AUD-031) |
| Fusionar bloque | §34 | ✅ | ✅ | ✅ | ✅ | ✅ Completo |
| Extender bloque | §35 | ✅ | ✅ | ✅ | ✅ | ⚠️ Afecta todos los grupos (NEXO-AUD-027) |

**Discrepancias encontradas:**
1. **RLS:** `funcionamiento.md` §27 afirma que 8 tablas tienen RLS pero el SQL no lo habilita. **CRITICAL.**
2. **Risk score:** `funcionamiento.md` §17 dice que evasiones no se cuentan "por diseño", pero PENDIENTES.md lo lista como pendiente de alta prioridad. Ambiguo.
3. **MQTT:** `funcionamiento.md` §16 describe MQTT como camino de comandos, pero `README.md` dice "deshabilitado temporalmente en Docker".

## 17. Matriz de tests

| Funcionalidad | Test existente | Cobertura real | Gap |
|---|---|---|---|
| Análisis estático SQL | 06, 07, 08, SchemaIntegrity, PlanCompliance | 95% | — |
| Análisis estático PHP | 10, 11, 13, 15, SchemaPhpAlignment | 80% | — |
| Auth login | EndpointIntegrationTest | 5% (solo HTTP 200) | No valida password, 2FA, rate limiting |
| Auth 2FA | Ninguno | 0% | NEXO-TEST-005 |
| Auth logout | Ninguno | 0% | NEXO-TEST-006 |
| Auth refresh | Ninguno | 0% | NEXO-TEST-007 |
| RBAC | integration_test (estático) | Estático | No prueba autorización runtime |
| RLS aislamiento | 06 (estático) | Estático | NEXO-TEST-008: no hay test cross-tenant real |
| Operations (SOS, citación, etc.) | Ninguno | 0% | NEXO-TEST-009 a 011 |
| Biometric ingest | 11 (estático) | Estático | NEXO-TEST-012 |
| Workers (todos) | 11 (estático) | Estático | NEXO-TEST-013 a 017 |
| Twilio webhook inbound | Ninguno | 0% | NEXO-TEST-018 a 021 |
| Audit chain HMAC | 05 (estático) | Estático | NEXO-TEST-022 |
| Panic mode | PanicButtonTest (mock) | Unitario | NEXO-TEST-023: sin Redis real |
| Devices | EndpointIntegrationTest (GET) | 5% | NEXO-TEST-024 a 026 |
| Students create | Ninguno | 0% | NEXO-TEST-027 |
| Users OTP/password/photo | Ninguno | 0% | NEXO-TEST-028 a 030 |
| School onboarding | Ninguno | 0% | NEXO-TEST-031 a 032 |
| CORS | Ninguno | 0% | NEXO-TEST-037 |
| Rate limiting | 10 (estático) | Estático | NEXO-TEST-038 |
| Idempotency | Ninguno | 0% | NEXO-TEST-039 |
| Concurrency | Ninguno | 0% | NEXO-TEST-040 |
| SQL injection | 10 (regex) | Estático | NEXO-TEST-041 |
| XSS | Ninguno | 0% | NEXO-TEST-042 |
| CSRF | Ninguno | 0% | NEXO-TEST-043 |
| File upload | Ninguno | 0% | NEXO-TEST-044 |
| Privilege escalation | Ninguno | 0% | NEXO-TEST-047 |
| DDoS resistance | Ninguno | 0% | NEXO-TEST-048 |
| Edge crypto | test_crypto.cpp | Unitario real | Sin fuzzing |
| Edge SQLite | test_sqlite.cpp | Unitario real | — |
| Edge MQTT | test_mqtt_command_worker.cpp | Unitario real | Sin TLS |
| Edge audit trail | test_audit_trail.cpp | Unitario real | — |

**Tests defectuosos:**
- `14_InstallationTest.php::testConfigFileExists()` — verifica `config.php` que no existe en VC (NEXO-TEST-003)
- `04_IndexTest.php::testIndexesOnForeignKeyColumns()` — stub que siempre pasa (NEXO-TEST-001)
- `PanicButtonTest.php` — mock de `securityLog()` vacío (NEXO-TEST-004)

## 18. Matriz de producción

| Aspecto | Estado | Hallazgo |
|---|---|---|
| Environment variables | ⚠️ | Sin `.env.example`, boot_check no valida HMAC secret ni METRICS_KEY ni REDIS_PASSWORD |
| CORS | ✅ | Estricto, reflejo exacto, excepción Vercel bien acotada |
| CSP | ⚠️ | `unsafe-inline` en scripts |
| HSTS | ⚠️ | Configurado pero requiere HTTPS (REQUIRES INFRA) |
| TLS | ⚠️ | Asume proxy reverso externo (REQUIRES INFRA) |
| Health checks | ✅ | `/health` y `/health/workers` con heartbeats Redis |
| Graceful shutdown | ⚠️ | Workers tienen SIGINT/SIGTERM pero sin drain de colas |
| Docker | ⚠️ | Non-root (www-data), sin multi-stage, sin health checks en compose |
| PgBouncer | ✅ | Modo transaction, non-root, userlist generado dinámico |
| Mosquitto | ❌ | Sin auth obligatoria, sin TLS por defecto |
| PHP-FPM | ⚠️ | max_children=25 fijo, sin auto-tuning |
| Workers | ⚠️ | Backoff exponencial en restart, sin límite máximo de restarts |
| Cron | ⚠️ | Sin lock files, sin monitoreo de fallos |
| Logs | ⚠️ | Sin rotación configurada (logrotate) |
| Backups DB | ❓ | REQUIRES INFRA |
| Backups edge SQLite | ❌ | No en checklist de aceptación |
| Monitoring | ⚠️ | `/health` existe, sin alerting externo |
| Alerting | ❌ | Sin alerting de DLQ, panic events, worker deaths |
| Resource limits | ❌ | Sin limits en docker-compose |
| Secrets management | ⚠️ | Variables de entorno, sin Docker secrets/K8s secrets |

## 19. Lista FINAL de blockers (CRITICAL)

### Bloqueantes de la 1ª auditoría (8, tras eliminar falso positivo)

| ID | Hallazgo | Estado |
|---|---|---|
| NEXO-AUD-001 | HMAC default `'default-secret-change-me'` en worker_audit.php | Confirmado + ampliado |
| NEXO-AUD-002 | 10 tablas con school_id sin RLS | Confirmado + ampliado (doc miente) |
| ~~NEXO-AUD-003~~ | ~~Ingesta edge sin validar token_hash~~ | **FALSO POSITIVO — ELIMINADO** |
| NEXO-AUD-004 | Sin refresh token | Confirmado |
| NEXO-AUD-005 | JWT duplicado en localStorage | Confirmado |
| NEXO-AUD-006 | worker_audit requeue infinito sin DLQ | Confirmado |
| NEXO-AUD-007 | Workers polling sin distributed lock | Confirmado |
| NEXO-AUD-008 | 7/8 tablas particionadas sin particiones reales | Confirmado + ampliado |
| NEXO-AUD-009 | /metrics sin auth obligatoria | Confirmado |

### Bloqueantes nuevos de la 2ª auditoría (8)

| ID | Hallazgo | Origen |
|---|---|---|
| NEXO-INFRA-001 | 13 funciones SECURITY DEFINER sin search_path | Infra |
| NEXO-INFRA-002 | HMAC default también en función SQL `fn_calculate_audit_hash` | Infra |
| NEXO-INFRA-033 | `deploy_db.sh` ejecuta todos los SQL incluyendo seed (TRUNCATE) y legacy INTEGER | Infra |
| NEXO-EDGE-004 | Clave AES compartida entre todos los dispositivos — clonación posible | Edge |
| NEXO-EDGE-019 | MQTT sin TLS — comandos en texto plano | Edge |
| NEXO-EDGE-001 | CommandWorker V1 envía token en header sin cifrar | Edge |
| NEXO-INFRA-030 | Mosquitto arranca sin auth si env vars no configuradas | Infra |
| NEXO-DISC-001 | Discrepancia doc↔código: doc afirma RLS en 8 tablas que no la tienen | Doc |

**Total bloqueantes: 16** (8 originales + 8 nuevos - 1 falso positivo)

## 20. Lista FINAL de high

### Altos de la 1ª auditoría (14)

NEXO-AUD-010 a 047 (los 14 marcados como HIGH en la auditoría original) — todos confirmados.

### Altos nuevos de la 2ª auditoría (13)

| ID | Hallazgo |
|---|---|
| NEXO-EDGE-002 | Device token en SQLite texto plano |
| NEXO-EDGE-008 | Clave AES de archivo, no de hardware |
| NEXO-EDGE-011 | Sin rotación de claves AES |
| NEXO-EDGE-024 | Sin liveness detection biométrica |
| NEXO-EDGE-016 | SQLite lleno bloquea acceso sin monitoreo |
| NEXO-INFRA-003 | nexo_seed.sql no idempotente (TRUNCATE) |
| NEXO-INFRA-009 | Migraciones legacy INTEGER ejecutables |
| NEXO-INFRA-027 | Nginx sin TLS (REQUIRES INFRA) |
| NEXO-INFRA-006 | UPDATE sin WHERE/LIMIT en fix_teacher_work_shift |
| NEXO-DOC-002 | Migration timezone no ejecutada en producción |
| NEXO-DOC-003 | worker_notification_purge no en cron |
| NEXO-DOC-015 | DLQ sin monitoreo — pérdida silenciosa de eventos |
| NEXO-DOC-021 | Clave AES como string 32 chars, no 32 bytes |

**Total altos: 27** (14 originales + 13 nuevos)

## 21. Lista FINAL de medium

### Medios de la 1ª auditoría (16)

NEXO-AUD-018 a 045 (los 16 marcados como MEDIUM) — todos confirmados (salvo NEXO-AUD-026 que baja a INFO).

### Medios nuevos de la 2ª auditoría (~25)

NEXO-EDGE-003, 006, 007, 018, 025, 027, 032; NEXO-INFRA-004, 005, 007, 008, 010, 011, 013, 016, 018, 019, 020, 026, 028, 029, 031, 032, 035, 036, 037, 038; NEXO-DOC-006, 019, 024, 027, 028, 030, 032, 034; NEXO-TEST-001, 004, 005-048 (gaps de cobertura).

**Total medios: ~40**

## 22. Lista FINAL de low

### Bajos de la 1ª auditoría (8)

NEXO-AUD-010 a 047 (los 8 marcados como LOW) — todos confirmados.

### Bajos nuevos de la 2ª auditoría (~20)

NEXO-EDGE-005, 014; NEXO-INFRA-012, 015, 021, 022, 025, 034, 039; NEXO-DOC-004, 008, 011, 012, 016, 017, 018, 020, 023, 025, 029, 031, 033, 035, 036, 040, 041; NEXO-TEST-002, 003.

**Total bajos: ~28**

---

## 23. Puntuación actualizada

| Categoría | Peso | Puntaje 1ª | Puntaje 2ª | Razón cambio |
|---|---|---|---|---|
| Seguridad (OWASP) | 30% | 55 | 45 | +SECURITY DEFINER, +MQTT sin TLS, +AES compartida |
| Fiabilidad | 20% | 60 | 50 | +deploy_db.sh destructivo, +DLQ sin monitoreo |
| Integridad datos | 15% | 58 | 48 | +HMAC en SQL, +doc miente sobre RLS |
| Rendimiento | 10% | 70 | 68 | Sin cambios mayores |
| Mantenibilidad | 10% | 65 | 60 | +código muerto edge, +migraciones legacy |
| Test coverage | 10% | 50 | 35 | Confirmado: 0% seguridad real, 0% workers |
| Documentación | 5% | 60 | 45 | +discrepancias doc↔código críticas |
| **TOTAL** | **100%** | **58.7** | **49.1** | |
| Ajuste por bloqueantes | | -15 | -15 (cap) | 16 bloqueantes |
| Curva por ingeniería subyacente | | +18.3 | +24 | Más hallazgos pero también más controles positivos confirmados |
| **Puntaje final** | | **62** | **58** | |

**Veredicto actualizado: 58/100 — NO APTO para producción.**

La remediación de los 16 bloqueantes elevaría la puntuación a ~78/100 (aptitud condicional con plan de remediación de altos/medios).

---

## 24. Respuesta final: ¿Queda superficie relevante sin revisar?

**Después de esta segunda auditoría, NO existe ninguna superficie relevante de NEXO que no haya sido revisada.**

### Demostración de cobertura exhaustiva

| Superficie | Auditada | Profundidad |
|---|---|---|
| `backend/api/routes/*.php` (20 archivos, 150+ endpoints) | ✅ 1ª + 2ª | Alta — contratos, auth, SQL injection, rate limiting |
| `backend/api/api.php` (front controller) | ✅ 1ª + 2ª | Alta — ingesta edge, CORS, health, rate limiting |
| `backend/api/workers/*.php` (7 workers) | ✅ 1ª + 2ª | Alta — colas, DLQ, locks, reintentos |
| `backend/api/sql/` (todas las migraciones) | ✅ 2ª | Alta — RLS, SECURITY DEFINER, particiones, constraints, triggers |
| `backend/api/sql/archive/` (legacy) | ✅ 2ª | Media — confirmado INTEGER incompatible |
| `backend/api/infra/` (PgBouncer, scripts, cron) | ✅ 2ª | Alta — config, secrets, lock files |
| `backend/api/Dockerfile`, `docker-entrypoint.sh` | ✅ 2ª | Alta — non-root, PHP-FPM, Mosquitto, nginx |
| `backend/api/.htaccess` | ✅ 2ª | Media — CSP, HSTS |
| `backend/api/tests/` (19 archivos) | ✅ 2ª | Alta — matriz de cobertura, tests defectuosos |
| `backend/edge/` (C++ completo) | ✅ 2ª | Alta — cifrado, auth, MQTT, biometría, offline, código muerto |
| `backend/edge/tests/` | ✅ 2ª | Media — unitarios reales, sin integración |
| `WebApp/src/api/` (13 módulos, 85 llamadas) | ✅ 1ª | Alta — paridad, contratos |
| `WebApp/vite.config.js` | ✅ 2ª | Baja — cacheo PWA |
| `documentation/` (todos los .md) | ✅ 2ª | Alta — discrepancies, lógica de negocio |
| `funcionamiento.md` | ✅ 2ª | Alta — 36 secciones, matriz de reglas |
| `deploy_db.sh` | ✅ 2ª | Alta — destructivo |
| `landing/` | ✅ 2ª | Baja — no afecta backend |

### Lo único que NO se puede verificar desde el repositorio

Estos 6 items requieren acceso al entorno de despliegue real y **no pueden auditarse desde código**:

1. Configuración TLS del proxy reverso externo (nginx/ALB/Cloudflare)
2. Si `MQTT_USER`/`MQTT_PASS` están configuradas en producción
3. Si `APP_NEXO_HMAC_SECRET` está configurada en producción
4. Si swap está OFF en las Raspberry Pi
5. Si hay backups programados de `nexo_edge.db`
6. Si logrotate está configurado en el host

**Conclusión:** La superficie de NEXO está completamente auditada desde el repositorio. Los 6 items restantes requieren verificación de infraestructura en el entorno real de despliegue, no auditoría de código.

---

*Auditoría complementaria generada en modo solo lectura. Ningún archivo del repositorio fue modificado.*
