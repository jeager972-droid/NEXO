<?php
/**
 * =============================================================================
 * SchemaPhpAlignmentTest — Test de alineación completa SQL ↔ PHP.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Script autónomo que compara el esquema SQL con todo el código PHP del
 *   backend para detectar discrepancias:
 *   - Tablas referenciadas en PHP (FROM/JOIN/INTO/UPDATE) existen en SQL.
 *   - Columnas en INSERTs PHP existen en SQL.
 *   - Roles y permisos usados en PHP existen en SQL.
 *   - SUPER_RECTOR eliminado por completo.
 *   - GUARDIAN preservado como rol.
 *   - Funciones SQL requeridas definidas.
 *   - RLS en tablas críticas.
 *
 * USO:
 *   php tests/SchemaPhpAlignmentTest.php
 */

class SchemaPhpAlignmentTest {
    private $passed = 0;
    private $failed = 0;
    private $sql;
    private $allPhp;
    private $sqlTables = [];
    private $phpTables = [];

    public function __construct() {
        $this->sql = file_get_contents(__DIR__ . '/../sql/nexo_full_migration.sql');
        $this->loadAllPhp();
        $this->extractSqlTables();
        $this->extractPhpTables();
    }

    private function loadAllPhp() {
        $this->allPhp = '';
        $baseDir = realpath(__DIR__ . '/..');
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
        foreach ($iter as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && strpos($file->getPathname(), '/vendor/') === false && strpos($file->getPathname(), '/tests/') === false) {
                $this->allPhp .= file_get_contents($file->getPathname()) . "\n";
            }
        }
    }

    private function extractSqlTables() {
        preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z_]+)/', $this->sql, $m);
        $this->sqlTables = array_unique($m[1]);
        sort($this->sqlTables);
    }

    private function extractPhpTables() {
        preg_match_all('/\b(FROM|INTO|JOIN|UPDATE)\s+([a-z_]+)/', $this->allPhp, $m);
        $this->phpTables = array_unique($m[2]);
        $aliases = ['u', 's', 'ag', 'sga', 'be', 'ai', 'si', 'uc', 'tm', 'im', 'us', 'ur', 'cea', 'sea', 'st', 'n', 'r', 'p', 'c', 'sch', 'g', 'gsr', 'f', 'd', 'v', 'present_cte', 'absent_cte', 'alerts_cte', 'perm_cte', 'con', 'pg_stat_activity'];
        $this->phpTables = array_diff($this->phpTables, $aliases);
        sort($this->phpTables);
    }

    private function test($name, $condition) {
        if ($condition) {
            echo "✅ PASS: $name\n";
            $this->passed++;
        } else {
            echo "❌ FAIL: $name\n";
            $this->failed++;
        }
    }

    public function run() {
        echo "=== Schema ↔ PHP Alignment Test ===\n\n";

        // 0. SUPER_RECTOR NO debe existir
        echo "--- 0. SUPER_RECTOR eliminado ---\n";
        $this->test("SUPER_RECTOR no está en SQL", strpos($this->sql, 'SUPER_RECTOR') === false);
        $this->test("SUPER_RECTOR no está en PHP", strpos($this->allPhp, 'SUPER_RECTOR') === false);

        // 0b. GUARDIAN SÍ debe existir como rol
        echo "\n--- 0b. GUARDIAN preservado ---\n";
        $this->test("GUARDIAN está en SQL como rol", strpos($this->sql, "'GUARDIAN'") !== false);
        $this->test("GUARDIAN está en PHP constante ROLES", strpos($this->allPhp, "'GUARDIAN'") !== false);

        // 1. Toda tabla usada en PHP existe en SQL
        echo "\n--- 1. Tablas PHP → SQL ---\n";
        foreach ($this->phpTables as $table) {
            $this->test("Tabla '$table' en PHP existe en SQL", in_array($table, $this->sqlTables));
        }

        // 2. Tablas críticas existen en SQL
        echo "\n--- 2. Tablas críticas en SQL ---\n";
        $critical = ['users', 'roles', 'permissions', 'role_permissions', 'students', 'schools', 'biometric_events', 'attendance_incidents', 'notifications', 'edge_devices', 'twilio_messages', 'user_commands', 'sos_alerts', 'global_audit_logs', 'security_incidents', 'student_tracking', 'student_tracking_notes', 'school_panic_events', 'system_telemetry', 'schema_migrations', 'jwt_blocklist', 'verification_codes', 'rate_limits', 'contact_leads', 'guardians', 'guardian_student_relationships'];
        foreach ($critical as $table) {
            $this->test("Tabla crítica '$table' existe", in_array($table, $this->sqlTables));
        }

        // 3. No hay tablas _default referenciadas en PHP
        echo "\n--- 3. Tablas _default no usadas en PHP ---\n";
        $defaultTables = array_filter($this->sqlTables, fn($t) => str_ends_with($t, '_default'));
        foreach ($defaultTables as $table) {
            $this->test("Tabla partición '$table' no referenciada en PHP", !in_array($table, $this->phpTables));
        }

        // 4. Columnas en INSERTs PHP existen en SQL
        echo "\n--- 4. Columnas en INSERTs PHP ---\n";
        preg_match_all('/INSERT INTO ([a-z_]+)\s*\(([^)]+)\)/i', $this->allPhp, $inserts, PREG_SET_ORDER);
        foreach ($inserts as $insert) {
            $table = $insert[1];
            $cols = array_map('trim', explode(',', $insert[2]));
            foreach ($cols as $col) {
                $col = trim($col);
                if (empty($col) || $col === '?' || str_contains($col, '$')) continue;
                $found = preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\b.*?\b' . preg_quote($col, '/') . '\b/s', $this->sql);
                $this->test("Columna '$col' en INSERT de '$table' existe en SQL", $found === 1);
            }
        }

        // 5. Roles en PHP constante existen en SQL
        echo "\n--- 5. Roles PHP → SQL ---\n";
        $phpRoles = ['RECTOR', 'COORDINATOR', 'TEACHER', 'SECRETARY', 'SECURITY', 'AUXILIARY', 'COUNSELOR', 'GUARDIAN'];
        foreach ($phpRoles as $role) {
            $this->test("Rol '$role' en PHP existe en SQL", strpos($this->sql, "'$role'") !== false);
        }

        // 6. Permisos en PHP existen en SQL
        echo "\n--- 6. Permisos PHP → SQL ---\n";
        preg_match_all("/'([a-z]+\.[a-z_]+)'/", $this->allPhp, $m);
        $phpPerms = array_filter($m[1] ?? [], fn($p) => str_contains($p, '_'));
        $phpPerms = array_unique($phpPerms);
        foreach ($phpPerms as $perm) {
            $this->test("Permiso '$perm' en PHP existe en SQL", strpos($this->sql, "'$perm'") !== false);
        }

        // 7. No hay roles en español fuera de normalizeRole
        echo "\n--- 7. Roles en español eliminados ---\n";
        $spanish = ['COORDINADOR', 'DOCENTE', 'SECRETARIA', 'PORTERO', 'AUXILIAR', 'PSICORIENTADOR', 'ACUDIENTE'];
        $phpWithoutNormalize = preg_replace('/function normalizeRole\(.*?\{.*?\}\s*\}/s', '', $this->allPhp);
        foreach ($spanish as $role) {
            $this->test("No queda rol en español '$role'", strpos($phpWithoutNormalize, "'$role'") === false);
        }

        // 8. Funciones SQL usadas en PHP existen
        echo "\n--- 8. Funciones SQL definidas ---\n";
        $funcs = ['migration_was_executed', 'register_migration', 'fn_calculate_audit_hash', 'fn_audit_chain_trigger', 'fn_validate_audit_chain', 'fn_calculate_student_risk', 'fn_recalculate_school_metrics', 'get_current_school_id', 'fn_guardians_normalize_phone'];
        foreach ($funcs as $fn) {
            $this->test("Función SQL '$fn' definida", strpos($this->sql, "CREATE OR REPLACE FUNCTION $fn") !== false);
        }

        // 9. RLS policies en tablas críticas
        echo "\n--- 9. RLS en tablas críticas ---\n";
        $rlsTables = ['students', 'biometric_events', 'attendance_incidents', 'sos_alerts', 'global_audit_logs', 'twilio_messages', 'edge_devices', 'user_commands'];
        foreach ($rlsTables as $table) {
            $this->test("RLS habilitado en '$table'", strpos($this->sql, "ALTER TABLE $table ENABLE ROW LEVEL SECURITY") !== false);
        }

        // 10. Seed data alineada
        echo "\n--- 10. Seed data ---\n";
        $this->test("Seed: al menos 1 escuela", preg_match('/INSERT INTO schools/', $this->sql) === 1);
        $this->test("Seed: 8 roles (7 institucionales + GUARDIAN)", substr_count($this->sql, "INSERT INTO roles") === 8);
        $this->test("Seed: permisos definidos", preg_match('/INSERT INTO permissions/', $this->sql) === 1);
        $this->test("Seed: admin user con rol RECTOR", preg_match('/INSERT INTO users.*role_name = .RECTOR./s', $this->sql) === 1);
        $this->test("Seed: role_permissions asignados", preg_match('/assign_permission_to_role/', $this->sql) === 1);

        // 11. No hay is_super_rector en SQL
        echo "\n--- 11. is_super_rector eliminado ---\n";
        $this->test("Función is_super_rector() eliminada", strpos($this->sql, 'is_super_rector') === false);

        // Resumen
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "RESULTADOS: {$this->passed} passed, {$this->failed} failed\n";
        echo str_repeat('=', 60) . "\n";
        return $this->failed === 0;
    }
}

$test = new SchemaPhpAlignmentTest();
$ok = $test->run();
exit($ok ? 0 : 1);
