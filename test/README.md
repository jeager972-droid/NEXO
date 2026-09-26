# NEXO Test Suite

## Estructura

```
test/
├── sql/          # Tests de schema SQL (PHPUnit, estáticos)
├── api/          # Tests unitarios de backend PHP (PHPUnit)
├── runners/      # Custom runners PHP (no PHPUnit, se ejecutan con `php`)
├── integration/  # Tests de integración (requieren servidor corriendo)
├── edge/         # Symlink → backend/edge/tests/ (CMake/CTest, C++ Catch2)
├── phpunit.xml   # Configuración PHPUnit para sql/ y api/
└── README.md     # Este archivo
```

## Tests de PWA (Vitest)

Los tests de Vitest permanecen en `frontend/pwa/src/__tests__/` porque Vitest/Vite
no permite cargar tests desde fuera del root del proyecto. Ejecutar con:

```bash
cd PWA && npx vitest run
```

### Cobertura de tests PWA

| Categoría | Archivos | Tests |
|-----------|----------|-------|
| API clients | 16 (audit, auth, behavior, consultations, dashboard, devices, notifications, operations, reports, risk, school, students, telemetry, tracking, users, client) | ~170 |
| UI components | 12 (Badge, Button, Card, EmptyState, IconButton, Input, Overlay, SearchableSelect, Select, Skeleton, Stepper, Surface) | ~80 |
| Patterns | 3 (RiskBadge, StatCard, StudentItem) | 57 |
| Context | 3 (AuthContext, NotificationContext, ThemeContext) | 28 |
| Hooks | 1 (useAuth) | 2 |
| Routes | 1 (ProtectedRoute) | 3 |
| Config | 1 (roles) | 15 |
| Utils | 6 (cn, groupFormat, jwt, formatters, exporters, mobilePermissions) | 83 |
| Pages | 5 (Login, Dashboard, Operation, Enrollment, RiskConfig) | 19 |
| **Total PWA** | **49 archivos** | **590 tests** |

## Ejecutar tests

### SQL tests (estáticos, no requieren DB)
```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "SQL Schema Tests"
```

### API unit tests (estáticos, no requieren servidor)
```bash
cd test && ../backend/api/vendor/bin/phpunit --testsuite "API Unit Tests"
```

### Custom runners (PlanCompliance, FullSystem, etc.)
```bash
php test/runners/PlanComplianceTest.php
php test/runners/FullSystemAlignmentTest.php
php test/runners/SchemaPhpAlignmentTest.php
php test/runners/PanicButtonTest.php
```

### Integration tests (requieren API corriendo)
```bash
php test/integration/EndpointIntegrationTest.php
php test/integration/OnboardingAssignmentsTest.php
```

### PWA tests (Vitest)
```bash
cd PWA && npx vitest run
```

### Edge tests (CMake/CTest)
```bash
cd backend/edge && ctest --test-dir build/dev --output-on-failure
```

### Todo a la vez
```bash
# PHP
cd test && ../backend/api/vendor/bin/phpunit --configuration phpunit.xml
php test/runners/PlanComplianceTest.php
php test/runners/SchemaPhpAlignmentTest.php
php test/runners/FullSystemAlignmentTest.php
php test/runners/PanicButtonTest.php

# PWA
cd ../PWA && npx vitest run

# Edge (requiere build previo)
cd ../backend/edge && ctest --test-dir build/dev --output-on-failure
```

## Cobertura total

| Suite | Tests | Estado |
|-------|-------|--------|
| SQL Schema | 29 | 27 pass, 2 fail (preexistentes) |
| API Unit (PHPUnit) | 137 | 137 pass |
| Custom Runners | 956 | 956 pass |
| PWA (Vitest) | 590 | 590 pass |
| Edge (Catch2) | 9 módulos | Requiere build C++ |
| **Total** | **~1712** | **~1710 pass** |
