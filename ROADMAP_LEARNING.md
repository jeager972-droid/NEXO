# ROADMAP_LEARNING.md — Guía de aprendizaje de NEXO

Ruta de lectura y estudio sugerida para incorporarse al proyecto NEXO, desde cero hasta poder modificar y desplegar cualquier componente. Pensada para un desarrollador con experiencia previa en web y bases de datos, pero sin conocimiento del dominio (instituciones educativas, biometría, multi-tenant).

## Tiempo estimado

- **Semana 1**: visión general + dominio + esquema de base de datos.
- **Semana 2**: API REST + autenticación + multi-tenant.
- **Semana 3**: Workers + flujos de asistencia/riesgo + edge.
- **Semana 4**: PWA + despliegue + CI/CD + contribuir.

Ajustar según experiencia previa. Un desarrollador senior con PHP + PostgreSQL + React puede comprimir esto a 2 semanas.

## Fase 0 — Visión general (día 1)

**Objetivo**: entender qué hace NEXO y qué componentes tiene.

1. Leer [`README.md`](README.md) completo. Es la fuente canónica del sistema.
2. Identificar los 6 componentes: Backend API, PostgreSQL, Redis, PWA, Landing, Edge.
3. Entender el flujo principal: estudiante pone la huella → edge identifica → envía a la API → API inserta en PostgreSQL → workers generan incidentes → notifican por WhatsApp → PWA muestra el estado.

**Checkpoint**: explicar con tus propias palabras qué pasa cuando un estudiante llega tarde a clase.

## Fase 1 — Dominio (día 2)

**Objetivo**: entender el vocabulario y los flujos del negocio.

1. Releer la sección "Flujos principales" del `README.md`.
2. Memorizar los roles: RECTOR, COORDINATOR, TEACHER, SECRETARY, SECURITY, AUXILIARY, COUNSELOR, GUARDIAN.
3. Entender los conceptos:
   - **Asistencia**: INGRESO_TEMPRANO, INGRESO_TARDIO (→ LATE_ARRIVAL), INGRESO_RETORNO.
   - **Evasión interna**: estudiante sale del aula sin permiso.
   - **Permiso de salida**: `class_exit_authorizations` (baño) y `school_exit_authorizations` (salida de institución).
   - **Riesgo pedagógico v3.0**: score con decaimiento exponencial en 3 categorías (asistencia, evasión, comportamiento).
   - **Onboarding**: configuración inicial de la institución (horarios, grupos, riesgo, sensor master key).
4. Entender el modelo multi-tenant: cada institución es un `school_id`, todo se filtra por RLS.

**Checkpoint**: dibujar en papel el flujo completo de un estudiante que llega tarde, sale al baño, y no regresa (evasión).

## Fase 2 — Base de datos (días 3-5)

**Objetivo**: dominar el esquema PostgreSQL.

1. Leer [`README_schema.md`](README_schema.md) completo.
2. Abrir `sql/schema.sql` y seguir la estructura sección por sección:
   - Extensiones y tabla `schema_migrations`.
   - Tablas de identidad (`users`, `roles`, `permissions`, `role_permissions`).
   - Tablas de institución (`schools`, `departments`, `municipalities`).
   - Tablas de estudiantes (`students`, `guardians`, `guardian_student_relationships`).
   - Tablas de grupos y horarios (`academic_groups`, `schedules`, `school_schedule_config`, `school_time_blocks`).
   - Tablas de eventos (`biometric_events`, `attendance_incidents`) — notar el particionado.
   - Tablas de operaciones (`sos_alerts`, `class_exit_authorizations`, `school_exit_authorizations`, etc.).
   - Tablas de riesgo (`risk_policies`, `risk_rules`, `risk_active_snapshot`, `risk_alerts`).
   - Tablas de auditoría (`global_audit_logs` con cadena HMAC).
3. Estudiar las funciones clave:
   - `fn_calculate_audit_hash` y `fn_audit_chain_trigger` (cadena HMAC).
   - `fn_evaluate_student_risk` y `fn_trigger_evaluate_risk_v3` (motor de riesgo).
   - `fn_calculate_category_risk` (decaimiento exponencial + clustering).
   - `fn_ensure_partitions` y `fn_drop_old_partitions` (particiones).
   - `get_current_school_id()` y `get_current_role()` (RLS).
4. Estudiar las políticas RLS de 3-4 tablas representativas (`students`, `biometric_events`, `global_audit_logs`, `user_sessions`).
5. Instalar una base local y aplicar el schema:

   ```bash
   createdb nexo_dev
   DATABASE_URL="postgresql://$USER@localhost/nexo_dev" ./deploy_db.sh
   ```

6. Explorar con `psql`:

   ```sql
   SELECT * FROM schools;
   SELECT * FROM roles;
   SELECT * FROM permissions;
   SELECT * FROM users WHERE email='admin@nexo.edu';
   ```

7. Correr los tests del schema:

   ```bash
   cd test && ../backend/api/vendor/bin/phpunit --testsuite "SQL Schema Tests"
   ```

**Checkpoint**: explicar cómo funciona el multi-tenant con RLS, qué hace `fn_evaluate_student_risk`, y por qué `global_audit_logs` usa una cadena HMAC.

## Fase 3 — API REST (días 6-8)

**Objetivo**: entender la API PHP y poder añadir un endpoint.

1. Leer [`README_API.md`](README_API.md) completo.
2. Abrir `backend/api/api.php` (front controller) y seguir el dispatch:
   - Carga de dependencias (`vendor/autoload.php`).
   - CORS middleware.
   - Parseo de la ruta (`cleanPath`).
   - Dispatch por prefijo a `routes/*.php`.
3. Estudiar `backend/api/core/db.php` (conexión PG + settings de sesión).
4. Estudiar `backend/api/routes/_auth_middleware.php`:
   - `requireAuth()`: valida JWT, fija `app.current_school_id` y `app.current_role`.
   - `requirePermission($code)`: valida permiso.
   - `securityLog()`: auditoría.
5. Estudiar 3-4 archivos de rutas en detalle:
   - `routes/auth.php` (login, refresh, logout, me, 2FA).
   - `routes/operations.php` (SOS, inasistencia, permisos, salidas — ver helpers Twilio).
   - `routes/devices.php` (registro, comandos, ping, enroll-confirm).
   - `routes/risk.php` (políticas, alertas, recálculo).
6. Entender el endpoint de ingesta edge (cualquier path con `payload` en el body):
   - Descifrado AES-256-GCM.
   - Validación de `device_token` (bcrypt).
   - Fast path para `SYNC_ATTENDANCE` y `REGISTER_STUDENT`.
   - Encolado en Redis para otros actions.
7. Correr los tests de la API:

   ```bash
   cd test && ../backend/api/vendor/bin/phpunit --testsuite "API Unit Tests"
   ```

**Checkpoint**: añadir un endpoint simple `GET /students/{id}/risk` que retorne el `risk_active_snapshot` del estudiante, con auth y permiso.

## Fase 4 — Workers (días 9-10)

**Objetivo**: entender el procesamiento asíncrono.

1. Leer [`documentation/WORKERS.md`](documentation/WORKERS.md) completo.
2. Abrir `backend/api/workers/` y estudiar:
   - `biometric_ingest_worker.php` (dedup, incidentes, notificaciones).
   - `absence_detector` (inasistencias).
   - `evasion_detector` (evasión interna).
   - `permission_status_worker` (cierre de permisos).
3. Entender el patrón daemon-loop vs periodic (supercronic).
4. Entender el fallback sin Redis.
5. Estudiar `backend/api/docker-entrypoint.sh` para ver cómo se lanzan los workers.
6. Correr los tests de runners (requiere PostgreSQL):

   ```bash
   cd test && ../backend/api/vendor/bin/phpunit --testsuite "Runner Tests"
   ```

**Checkpoint**: explicar qué pasa cuando un estudiante no llega a clase: qué worker lo detecta, qué inserta, qué notifica, y cómo se recalcula el riesgo.

## Fase 5 — Edge (días 11-13)

**Objetivo**: entender el nodo edge en C++.

1. Leer [`documentation/EDGE.md`](documentation/EDGE.md) completo.
2. Explorar `backend/edge/src/`:
   - `main.cpp` (punto de entrada, SyncWorker, HeartbeatWorker, HealthMonitor).
   - `base_de_datos/sqlite_manager.cpp` (SQLite local, WAL, retry).
   - `base_de_datos/encryption.cpp` (AES-256-GCM, clave hardware-bound).
   - `base_de_datos/cloud_manager.cpp` (HTTP al backend).
   - `hardware/real/UareU5300BiometricSensor.cpp` (sensor DigitalPersona).
   - `mqtt/mqtt_command_worker.cpp` (comandos remotos).
3. Entender el flujo: captura → identificación 1:N → SQLite → sincronización → API.
4. Entender el offline-first: si no hay red, los eventos se guardan en SQLite y se sincronizan después.
5. Compilar y correr los tests:

   ```bash
   cd backend/edge
   cmake --preset dev-x86
   cmake --build build/dev
   ctest --test-dir build/dev --output-on-failure
   ```

6. Probar el sensor stub (`DevStubBiometricSensor`) en modo desarrollo.

**Checkpoint**: explicar por qué los templates biométricos nunca salen del edge, y cómo se cifra la comunicación con la API.

## Fase 6 — PWA (días 14-15)

**Objetivo**: entender el frontend y poder añadir una página.

1. Leer [`documentation/WEBAPP.md`](documentation/WEBAPP.md) completo.
2. Explorar `PWA/src/`:
   - `App.jsx` (rutas + ProtectedRoute).
   - `api/client.js` (axios + interceptores de auth/refresh).
   - `context/AuthContext.jsx` (estado de auth).
   - 2-3 páginas representativas (`Dashboard.jsx`, `Asistencia.jsx`, `Consulta.jsx`).
3. Entender el flujo de autenticación: login → cookies HttpOnly → interceptor 401 → refresh → retry.
4. Correr la PWA localmente:

   ```bash
   cd PWA && npm install && npm run dev
   ```

5. Correr los tests:

   ```bash
   cd PWA && npm test
   ```

**Checkpoint**: añadir una página simple que liste las alertas de riesgo activas (`GET /risk/alerts`).

## Fase 7 — Seguridad (día 16)

**Objetivo**: entender el modelo de seguridad completo.

1. Leer [`documentation/SECURITY.md`](documentation/SECURITY.md) completo.
2. Revisar en el código:
   - JWT en `routes/_auth_middleware.php`.
   - RLS en `sql/schema.sql` (sección policies).
   - Cadena HMAC en `fn_calculate_audit_hash` + `trg_audit_chain`.
   - Cifrado edge en `backend/edge/src/base_de_datos/encryption.cpp`.
   - CSRF (`X-Requested-With`) en `routes/_cors_middleware.php`.
3. Entender las decisiones de fail-open vs fail-closed (Redis caído).

**Checkpoint**: explicar qué pasa si Redis se cae en producción: qué sigue funcionando, qué degrada, y qué se bloquea.

## Fase 8 — Despliegue y CI/CD (días 17-18)

**Objetivo**: poder desplegar el sistema completo.

1. Leer [`documentation/DEPLOYMENT.md`](documentation/DEPLOYMENT.md) completo.
2. Estudiar `.github/workflows/nexo-ci-cd.yml`.
3. Entender el flujo de onboarding de una institución nueva.
4. Desplegar localmente con Docker (si se quiere practicar):
   - Backend: `docker build -t nexo-api backend/api/ && docker run -p 8080:8080 nexo-api`.
   - PWA: `cd PWA && npm run build && npx serve dist`.
5. Revisar `vercel.json` de la PWA y `Dockerfile` del backend.

**Checkpoint**: describir los pasos para desplegar un fix en producción (rama → PR → CI → merge a main → deploy automático).

## Fase 9 — Testing (día 19)

**Objetivo**: poder escribir y correr tests.

1. Leer [`documentation/TESTING.md`](documentation/TESTING.md) completo.
2. Correr todas las suites localmente.
3. Estudiar 2-3 tests existentes para entender el patrón.
4. Escribir un test nuevo para el endpoint añadido en la Fase 3.

**Checkpoint**: explicar la diferencia entre SQL Schema Tests, API Unit Tests, Runner Tests e Integration Tests.

## Fase 10 — Contribuir (día 20+)

**Objetivo**: hacer un cambio real de principio a fin.

1. Elegir un issue o mejora pequeña.
2. Implementar el cambio:
   - Si toca el esquema: editar `sql/schema.sql`, mantener idempotencia, añadir/ajustar RLS, correr SQL Schema Tests.
   - Si toca la API: editar/añadir en `routes/`, mantener auth + permisos, añadir `securityLog`, escribir test.
   - Si toca workers: editar en `workers/`, mantener fallback sin Redis, escribir test de runner.
   - Si toca edge: editar en `src/`, mantener offline-first, escribir test Catch2.
   - Si toca PWA: editar en `src/`, mantener patrones de auth, escribir test Vitest.
3. Correr todas las suites relevantes.
4. Actualizar la documentación afectada (`README.md`, `README_API.md`, etc.).
5. Abrir PR.

## Recursos

- **Documentación del repo**: `README.md`, `README_API.md`, `README_schema.md`, `documentation/`.
- **Notas operativas**: `tener_en_cuenta.md` (cuidado: contiene referencias históricas, verificar vigencia).
- **Tests**: `test/README.md` y `test/` para ejemplos de uso.
- **Config de ejemplo**: `backend/api/.env.example`, `PWA/.env.example`, `backend/edge/config.example.json`.

## Conceptos clave para memorizar

1. **Multi-tenant via RLS**: `school_id` + `app.current_school_id`. Nunca pasar `school_id` desde el cliente.
2. **Offline-first edge**: SQLite local + sync diferida. Los templates no salen del edge.
3. **Cadena HMAC de auditoría**: `global_audit_logs` con `prev_audit_id` + `chain_hash`. Inmutable y verificable.
4. **Riesgo v3.0**: decaimiento exponencial (`λ = ln(2)/half_life`), 3 categorías, nivel máximo, cooldown de alertas.
5. **JWT con refresh rotation**: cada refresh revoca la sesión anterior.
6. **Fallback sin Redis**: el sistema degrada gracefully. Solo `/contacto` es fail-closed.
7. **Particiones mensuales**: 8 tablas particionadas, `fn_ensure_partitions` + `fn_drop_old_partitions`.
8. **Schema canónico**: `sql/schema.sql` es la única fuente. No hay migraciones que ejecutar.
