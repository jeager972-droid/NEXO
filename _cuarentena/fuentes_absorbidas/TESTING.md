# TESTING.md — Pruebas en NEXO

Documentación de la estrategia de pruebas de NEXO. Basada en `test/README.md` y la estructura de `test/`.

## Estructura general

```
test/
├── README.md
├── bootstrap.php                 # Bootstrap PHPUnit (carga schema.sql en memoria)
├── phpunit.xml                   # Configuración PHPUnit + suites
├── sql/                          # Tests del esquema PostgreSQL (parseo estático)
├── api/                          # Tests unitarios de la API PHP
├── runners/                      # Tests de integración de workers
├── integration/                  # Tests end-to-end
└── scripts/                      # Scripts de ayuda
```

## Suites

### 1. SQL Schema Tests (`test/sql/`)

Parsean `sql/schema.sql` estáticamente (sin PostgreSQL corriendo) para verificar que el esquema esté completo y coherente.

| Test | Verifica |
|---|---|
| `SchemaIntegrityTest.php` | Tablas, columnas, tipos, PKs, FKs, constraints, índices, triggers, funciones, roles, policies RLS, particiones, ALTER TABLE |
| `ConstraintTest.php` | Constraints CHECK y UNIQUE |
| `ForeignKeyTest.php` | Integridad referencial (todas las FKs apuntan a tablas existentes) |
| `IndexTest.php` | Índices esperados presentes |
| `PartitionTest.php` | Tablas particionadas y `fn_ensure_partitions` |
| `RlsSecurityTest.php` | Policies RLS por tabla |
| `SeedDataTest.php` | Seed mínimo presente (roles, permisos, admin, geografía) |
| `TriggerTest.php` | Triggers definidos |

```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "SQL Schema Tests"
```

### 2. API Unit Tests (`test/api/`)

Tests unitarios de la API PHP. Mockean la base de datos y Redis donde es necesario. Validan lógica de endpoints, validación de input, permisos, y respuestas.

```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "API Unit Tests"
```

### 3. Runner Tests (`test/runners/`)

Tests de integración de los workers. Requieren PostgreSQL real (con `sql/schema.sql` aplicado). Validan el comportamiento end-to-end de los workers con datos reales.

| Test | Verifica |
|---|---|
| `AbsenceDetectorTest.php` | Inasistencias detectadas correctamente |
| `EvasionDetectorTest.php` | Evasión interna detectada |
| `PermissionStatusTest.php` | Permisos expirados cerrados |
| `BiometricIngestTest.php` | Eventos procesados, incidentes generados |
| `RiskRecalculationTest.php` | Recálculo de riesgo masivo |
| `TwilioWorkerTest.php` | Envíos encolados y rate limit |
| `SchemaPhpAlignmentTest.php` | El código PHP no usa columnas inexistentes en el schema |

```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "Runner Tests"
```

### 4. Integration Tests (`test/integration/`)

Tests end-to-end que levantan la API y verifican flujos completos (login, operaciones, sync edge, etc.). Requieren PostgreSQL + Redis.

```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "Integration Tests"
```

### 5. PWA Tests (`frontend/pwa/tests/`)

Tests del frontend con Vitest + Testing Library. Cubren componentes, hooks, y flujos de autenticación.

```bash
cd PWA && npm test
```

### 6. Edge Tests (`backend/edge/tests/`)

Tests del nodo edge con Catch2 v3 + CMake/CTest. Cubren SQLite, cifrado, config, sensor stub, CloudManager, MQTT, AuditTrail, watchdog.

```bash
cd backend/edge && ctest --test-dir build/dev --output-on-failure
```

## CI/CD

`.github/workflows/nexo-ci-cd.yml` define los pipelines:

### WebApp (PWA)

- **On**: push/PR a `main`/`develop`, cambios en `frontend/pwa/**`.
- **Jobs**: `lint` (eslint), `test` (vitest), `build` (vite build), `deploy` (Vercel, solo en `main`).

### Backend (PHP)

- **On**: push/PR a `main`/`develop`, cambios en `backend/**` o `sql/**`.
- **Jobs**: `lint` (php -l), `test-sql` (SQL Schema Tests), `test-api` (API Unit Tests), `test-runners` (Runner Tests, con PostgreSQL service), `build` (docker build), `deploy` (Render, solo en `main`).

### Landing

- **On**: push/PR a `main`/`develop`, cambios en `frontend/frontend/landing/**`.
- **Jobs**: `build` (vite build), `deploy` (Vercel, solo en `main`).

### Edge

- **On**: push/PR a `main`/`develop`, cambios en `backend/edge/**`.
- **Jobs**: `test` (CTest en x86), `build` (CMake release x86 + cross-compile ARM64).

### Servicios de CI

- **PostgreSQL 15**: service container para Runner Tests e Integration Tests. Se aplica `sql/schema.sql` antes de correr.
- **Redis**: service container para tests que usan Redis (twilio rate limit, dedup, etc.).

## Ejecución local

### Requisitos

- PHP 8.2+ con extensiones: `pdo_pgsql`, `mbstring`, `openssl`, `curl`.
- Composer (instala PHPUnit en `backend/api/vendor/`).
- PostgreSQL 15+ (para Runner e Integration Tests).
- Redis (opcional, para tests que lo usan).
- Node 18+ y npm (para PWA y landing).
- CMake 3.20+, compilador C++17, Catch2 v3 (para edge).

### Comando completo

```bash
# Backend
cd backend/api && composer install
cd ../../test && ../backend/api/vendor/bin/phpunit

# PWA
cd PWA && npm install && npm test

# Edge
cd backend/edge && cmake --preset dev-x86 && cmake --build build/dev && ctest --test-dir build/dev --output-on-failure
```

### Solo una suite

```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "SQL Schema Tests"
cd test && ../backend/api/vendor/bin/phpunit --testsuite "API Unit Tests"
cd test && ../backend/api/vendor/bin/phpunit --testsuite "Runner Tests"
cd test && ../backend/api/vendor/bin/phpunit --testsuite "Integration Tests"
```

## Cobertura

No hay un umbral de cobertura configurado. Las suites se enfocan en:

- **Esquema**: 100% de tablas/policies/triggers verificados estáticamente.
- **API**: endpoints críticos (auth, operaciones, dispositivos, ingesta).
- **Workers**: flujos de detección (inasistencia, evasión, permisos, riesgo).
- **Edge**: módulos core (SQLite, crypto, config, sync).
- **PWA**: componentes y hooks clave.

## Convenciones

- Los tests de SQL no necesitan PostgreSQL: parsean el `.sql` con regex.
- Los tests de runners/integration sí necesitan PostgreSQL: usan una base de test con `schema.sql` aplicado.
- Los tests de la API mockean DB/Redis cuando es posible para velocidad.
- Los tests del edge usan stubs para hardware (no necesitan sensor real).
- Los nombres de tests siguen `*Test.php` (PHP) y `*.test.jsx`/`*.test.js` (PWA).
