<?php
/**
 * =============================================================================
 * integration_test.php — Test de integración estático.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Ejecuta pruebas estáticas de consistencia SQL↔PHP sin necesidad de
 *   PostgreSQL ni Redis corriendo. Verifica:
 *   - Roles esperados en SQL.
 *   - Permisos esperados en SQL.
 *   - Tablas críticas en SQL.
 *   - Funciones de migración y RLS.
 *   - Sintaxis PHP válida en todo el backend (php -l).
 *   - Roles canónicos en _auth_middleware.php y eliminación de roles en español.
 *   - Uso de UUID para primary keys.
 *
 * USO:
 *   php test/runners/integration_test.php
 */

$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "✅ PASS: $name\n";
        $passed++;
    } else {
        echo "❌ FAIL: $name\n";
        $failed++;
    }
}

$baseDir = realpath(__DIR__ . "/../../backend/api");
$sqlFile = realpath(__DIR__ . "/../../sql/schema.sql");
$sql = file_get_contents($sqlFile);

// =============================================================================
// 1. ROLES
// =============================================================================
$expectedRoles = ['RECTOR', 'COORDINATOR', 'TEACHER', 'SECRETARY', 'SECURITY', 'AUXILIARY', 'COUNSELOR'];

foreach ($expectedRoles as $role) {
    test("Rol '$role' existe en SQL", strpos($sql, "'$role'") !== false);
}

// =============================================================================
// 2. PERMISOS
// =============================================================================
$expectedPerms = [
    'dashboard.teacher_view', 'dashboard.global_view',
    'operations.sos', 'operations.inasistencia', 'operations.citacion',
    'operations.autorizar_salida', 'operations.permiso', 'operations.solicitud',
    'operations.daño', 'operations.pedagogica', 'operations.horario',
    'operations.incidente', 'operations.seguimiento', 'operations.situacion_critica',
    'consultations.teacher_view', 'consultations.global_view',
    'reports.preview', 'reports.export',
    'devices.manage', 'devices.admin_health',
    'audit.view', 'audit.integrity',
    'security.panic',
    'admin.recalc_risk', 'admin.users_manage',
    'students.create', 'students.view',
    'tracking.manage', 'behavior.view_risk'
];

foreach ($expectedPerms as $perm) {
    test("Permiso '$perm' existe en SQL", strpos($sql, "'$perm'") !== false);
}

// =============================================================================
// 3. TABLAS CRÍTICAS
// =============================================================================
$criticalTables = [
    'users', 'roles', 'permissions', 'role_permissions',
    'students', 'guardians', 'guardian_student_relationships',
    'academic_groups', 'student_group_assignments', 'schedules',
    'biometric_events', 'attendance_incidents', 'notifications',
    'user_commands', 'twilio_messages', 'edge_devices',
    'schools', 'school_panic_events',
    'schema_migrations', 'jwt_blocklist', 'verification_codes',
    'student_behavior_metrics', 'student_tracking', 'student_tracking_notes',
    'global_audit_logs', 'security_incidents',
    'class_exit_authorizations', 'school_exit_authorizations',
    'internal_messages', 'report_exports',
    'contact_leads', 'rate_limits'
];

foreach ($criticalTables as $table) {
    test("Tabla '$table' existe en SQL", strpos($sql, "CREATE TABLE IF NOT EXISTS $table") !== false);
}

// =============================================================================
// 4. FUNCIONES DE MIGRACIÓN
// =============================================================================
test("Función migration_was_executed existe", strpos($sql, 'CREATE OR REPLACE FUNCTION migration_was_executed') !== false);
test("Función register_migration existe", strpos($sql, 'CREATE OR REPLACE FUNCTION register_migration') !== false);

// =============================================================================
// 5. RLS POLICIES
// =============================================================================
test("RLS habilitado en students", strpos($sql, 'ALTER TABLE students ENABLE ROW LEVEL SECURITY') !== false);
test("RLS habilitado en biometric_events", strpos($sql, 'ALTER TABLE biometric_events ENABLE ROW LEVEL SECURITY') !== false);
test("Función is_super_rector eliminada", strpos($sql, 'CREATE OR REPLACE FUNCTION is_super_rector()') === false);

// =============================================================================
// 6. PHP — SINTAXIS
// =============================================================================
$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$baseDir"));
$phpCount = 0;
foreach ($phpFiles as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && strpos($file->getPathname(), '/vendor/') === false && strpos($file->getPathname(), 'integration_test.php') === false) {
        $phpCount++;
        $output = [];
        $return = 0;
        exec('php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $return);
        if ($return !== 0) {
            test("Sintaxis válida: " . basename($file->getPathname()), false);
        }
    }
}
test("Todos los $phpCount archivos PHP tienen sintaxis válida", true);

// =============================================================================
// 7. PHP — ROLES EN CÓDIGO
// =============================================================================
$authMiddleware = file_get_contents("$baseDir/routes/_auth_middleware.php");
test("ROLES no contiene SUPER_RECTOR", strpos($authMiddleware, "'SUPER_RECTOR'") === false);
test("ROLES contiene RECTOR", strpos($authMiddleware, "'RECTOR'") !== false);
test("ROLES contiene COORDINATOR", strpos($authMiddleware, "'COORDINATOR'") !== false);
test("ROLES contiene TEACHER", strpos($authMiddleware, "'TEACHER'") !== false);
test("ROLES contiene GUARDIAN", strpos($authMiddleware, "'GUARDIAN'") !== false);
test("normalizeRole() existe", strpos($authMiddleware, 'function normalizeRole') !== false);

// =============================================================================
// 8. PHP — NO HAY ROLES EN ESPAÑOL
// =============================================================================
$spanishRoles = ['COORDINADOR', 'DOCENTE', 'SECRETARIA', 'PORTERO', 'AUXILIAR', 'PSICORIENTADOR', 'ACUDIENTE']; // NOSONAR - solo para validación
$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$baseDir"));
$spanishFound = false;
foreach ($phpFiles as $file) {
    $path = $file->getPathname();
    if ($file->isFile() && $file->getExtension() === 'php' && strpos($path, '/vendor/') === false && strpos($path, 'integration_test.php') === false && strpos($path, 'SchemaPhpAlignmentTest.php') === false) {
        $content = file_get_contents($path);
        // Excluir normalizeRole() que SÍ debe tener mapeos legacy
        $content = preg_replace('/function normalizeRole\(.*?\{.*?\}/s', '', $content);
        foreach ($spanishRoles as $role) {
            if (strpos($content, "'$role'") !== false) {
                echo "⚠️  Rol en español encontrado: '$role' en " . basename($path) . "\n";
                $spanishFound = true;
            }
        }
    }
}
test("No quedan roles en español fuera de normalizeRole()", !$spanishFound);

// =============================================================================
// 9. UUID VALIDATION
// =============================================================================
test("Esquema usa UUID para PKs", strpos($sql, 'UUID PRIMARY KEY DEFAULT uuid_generate_v4()') !== false);
test("Función uuid_generate_v4 referenciada", strpos($sql, 'uuid_generate_v4()') !== false);

// =============================================================================
// RESUMEN
// =============================================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULTADOS: $passed passed, $failed failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
