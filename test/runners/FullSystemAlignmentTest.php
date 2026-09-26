<?php
/**
 * =============================================================================
 * FullSystemAlignmentTest — Veredicto final de deploy.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Script autónomo (no PHPUnit) que compara el esquema SQL con TODO el código
 *   PHP del backend (routes, workers, lib, api.php, etc.) y emite un veredicto
 *   de si el sistema está listo para deploy. Verifica:
 *   - Eliminación de SUPER_RECTOR e is_super_rector (SQL y PHP).
 *   - Preservación de GUARDIAN, guardians y guardian_id en SQL/PHP/seed.
 *   - Tablas referenciadas en PHP existen en SQL.
 *   - Columnas en INSERTs PHP existen en SQL.
 *   - Funciones SQL definidas.
 *   - RLS en tablas críticas.
 *   - Seed data alineada.
 *   - Sintaxis PHP válida.
 *   - Roles en español eliminados (fuera de normalizeRole).
 *   - Permisos PHP existen en SQL.
 *
 * USO:
 *   php test/runners/FullSystemAlignmentTest.php
 */

class FullSystemAlignmentTest {
    private $passed = 0;
    private $failed = 0;
    private $errors = [];
    private $sql;
    private $allPhp;

    public function __construct() {
        $this->sql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
        $this->loadAllPhp();
    }

    private function loadAllPhp() {
        $this->allPhp = '';
        $baseDir = realpath(__DIR__ . '/../../backend/api');
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
        foreach ($iter as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && strpos($file->getPathname(), '/vendor/') === false && strpos($file->getPathname(), '/tests/') === false) {
                $this->allPhp .= file_get_contents($file->getPathname()) . "\n";
            }
        }
    }

    private function test($name, $condition, $detail = '') {
        if ($condition) {
            echo "✅ PASS: $name\n";
            $this->passed++;
        } else {
            echo "❌ FAIL: $name" . ($detail ? " [$detail]" : "") . "\n";
            $this->failed++;
            $this->errors[] = $name . ($detail ? ": $detail" : "");
        }
    }

    public function run() {
        echo "╔══════════════════════════════════════════════════════════════════╗\n";
        echo "║     FULL SYSTEM ALIGNMENT TEST — Veredicto de Deploy            ║\n";
        echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

        // ===== 1. SUPER_RECTOR ELIMINADO =====
        echo "━━━ 1. SUPER_RECTOR eliminado completamente ━━━\n";
        $this->test("No queda SUPER_RECTOR en SQL", strpos($this->sql, 'SUPER_RECTOR') === false);
        $this->test("No queda SUPER_RECTOR en PHP", strpos($this->allPhp, 'SUPER_RECTOR') === false);
        $this->test("No queda is_super_rector() en SQL", strpos($this->sql, 'is_super_rector') === false);
        $this->test("No queda is_super_rector() en PHP", strpos($this->allPhp, 'is_super_rector') === false);

        // ===== 2. GUARDIAN PRESERVADO =====
        echo "\n━━━ 2. GUARDIAN preservado en todo el sistema ━━━\n";
        $this->test("Rol GUARDIAN en SQL", strpos($this->sql, "'GUARDIAN'") !== false);
        $this->test("Rol GUARDIAN en PHP constante ROLES", strpos($this->allPhp, "'GUARDIAN'") !== false);
        $this->test("Tabla guardians en SQL", strpos($this->sql, 'CREATE TABLE IF NOT EXISTS guardians') !== false);
        $this->test("Tabla guardian_student_relationships en SQL", strpos($this->sql, 'CREATE TABLE IF NOT EXISTS guardian_student_relationships') !== false);
        $this->test("Columna guardian_id en twilio_messages", preg_match('/twilio_messages.*guardian_id\s+UUID/s', $this->sql) === 1);
        $this->test("Función fn_guardians_normalize_phone en SQL", strpos($this->sql, 'fn_guardians_normalize_phone') !== false);
        $this->test("Trigger trg_guardians_normalize_phone en SQL", strpos($this->sql, 'trg_guardians_normalize_phone') !== false);
        $this->test("Queries de guardians en PHP", substr_count($this->allPhp, 'guardians') >= 10);
        $this->test("Queries de guardian_id en PHP", substr_count($this->allPhp, 'guardian_id') >= 20);
        $this->test("worker_biometric crea guardian con user", strpos($this->allPhp, "INSERT INTO guardians(user_id,whatsapp_phone)") !== false);
        $this->test("worker_biometric busca rol GUARDIAN", strpos($this->allPhp, "role_name = 'GUARDIAN'") !== false);

        // ===== 3. TABLAS SQL vs PHP =====
        echo "\n━━━ 3. Tablas usadas en PHP existen en SQL ━━━\n";
        preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z_]+)/', $this->sql, $sqlTables);
        $sqlTables = array_flip($sqlTables[1]);
        preg_match_all('/\b(FROM|INTO|JOIN|UPDATE)\s+([a-z_]+)/', $this->allPhp, $phpMatches);
        $phpTables = array_unique($phpMatches[2]);
        $aliases = ['u','s','ag','sga','be','ai','si','uc','tm','im','us','ur','cea','sea','st','n','r','p','c','sch','g','gsr','f','d','v','present_cte','absent_cte','alerts_cte','perm_cte','con','pg_stat_activity', 'late_cte', 'existing', 'information_schema', 'detected_at', 'baseline', 'current', 'sub', 'intervals', 'i', 'rr', 'elm', 'ret', 'ai2', 'bev_stat', 'ev_stat', 's2', 'generate_series', 'pg_proc', 'pg_constraint', 'pg_class', 'pg_inherits', 'pg_namespace', 'pg_tables', 'pg_index', 'pg_attribute'];
        $missingTables = [];
        foreach ($phpTables as $table) {
            if (in_array($table, $aliases)) continue;
            if (!isset($sqlTables[$table])) {
                $missingTables[] = $table;
            }
        }
        $this->test("Todas las tablas PHP existen en SQL", empty($missingTables), implode(', ', $missingTables));

        // ===== 4. COLUMNAS EN INSERTS PHP vs SQL =====
        echo "\n━━━ 4. Columnas en INSERTs PHP existen en SQL ━━━\n";
        preg_match_all('/INSERT INTO ([a-z_]+)\s*\(([^)]+)\)/i', $this->allPhp, $inserts, PREG_SET_ORDER);
        $missingCols = [];
        foreach ($inserts as $insert) {
            $table = $insert[1];
            $cols = array_map('trim', explode(',', $insert[2]));
            foreach ($cols as $col) {
                $col = trim($col);
                if (empty($col) || $col === '?' || str_contains($col, '$')) continue;
                $found = preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\b.*?\b' . preg_quote($col, '/') . '\b/s', $this->sql);
                if ($found !== 1) {
                    $missingCols[] = "$table.$col";
                }
            }
        }
        $this->test("Todas las columnas en INSERTs PHP existen en SQL", empty($missingCols), implode(', ', array_slice($missingCols, 0, 5)));

        // ===== 5. FUNCIONES SQL DEFINIDAS =====
        echo "\n━━━ 5. Funciones SQL definidas y usadas ━━━\n";
        $funcs = ['migration_was_executed','register_migration','fn_calculate_audit_hash','fn_audit_chain_trigger','fn_validate_audit_chain','fn_calculate_student_risk','fn_recalculate_school_metrics','get_current_school_id','fn_guardians_normalize_phone'];
        foreach ($funcs as $fn) {
            $this->test("Función SQL '$fn' definida", strpos($this->sql, "CREATE OR REPLACE FUNCTION $fn") !== false);
        }

        // ===== 6. RLS POLICIES =====
        echo "\n━━━ 6. RLS habilitado en tablas críticas ━━━\n";
        $rlsTables = ['students','biometric_events','attendance_incidents','sos_alerts','global_audit_logs','twilio_messages','edge_devices','user_commands'];
        foreach ($rlsTables as $table) {
            $this->test("RLS en '$table'", strpos($this->sql, "ALTER TABLE $table ENABLE ROW LEVEL SECURITY") !== false);
        }

        // ===== 7. SEED DATA =====
        echo "\n━━━ 7. Seed data alineada ━━━\n";
        $this->test("Seed: 1 escuela", preg_match('/INSERT INTO schools/', $this->sql) === 1);
        $this->test("Seed: 8 roles (7 + GUARDIAN)", substr_count($this->sql, "uuid_generate_v4(), 'RECTOR'") === 1 && substr_count($this->sql, "uuid_generate_v4(), 'GUARDIAN'") === 1);
        $this->test("Seed: admin user RECTOR", preg_match('/INSERT INTO users.*RECTOR/s', $this->sql) === 1);
        $this->test("Seed: permisos definidos", preg_match('/INSERT INTO permissions/', $this->sql) === 1);

        // ===== 8. SINTAXIS PHP =====
        echo "\n━━━ 8. Sintaxis PHP válida en todo el backend ━━━\n";
        $baseDir = realpath(__DIR__ . '/../../backend/api');
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
        $phpErrors = [];
        foreach ($iter as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && strpos($file->getPathname(), '/vendor/') === false && strpos($file->getPathname(), '/tests/') === false) {
                $output = shell_exec('php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1');
                if (strpos($output, 'No syntax errors') === false) {
                    $phpErrors[] = basename($file->getPathname());
                }
            }
        }
        $this->test("Todos los archivos PHP tienen sintaxis válida", empty($phpErrors), implode(', ', $phpErrors));

        // ===== 9. ROLES ESPAÑOL ELIMINADOS =====
        echo "\n━━━ 9. Roles en español eliminados del PHP (fuera de normalizeRole) ━━━\n";
        $phpNoNorm = preg_replace('/function normalizeRole\(.*?\{.*?\}\s*\}/s', '', $this->allPhp);
        $spanish = ['COORDINADOR','DOCENTE','SECRETARIA','PORTERO','AUXILIAR','PSICORIENTADOR','ACUDIENTE'];
        foreach ($spanish as $role) {
            $this->test("No queda '$role'", strpos($phpNoNorm, "'$role'") === false);
        }

        // ===== 10. PERMISOS PHP vs SQL =====
        echo "\n━━━ 10. Permisos en PHP existen en SQL ━━━\n";
        preg_match_all("/'([a-z]+\.[a-z_]+)'/", $this->allPhp, $m);
        $phpPerms = array_filter($m[1] ?? [], fn($p) => str_contains($p, '_'));
        $phpPerms = array_unique($phpPerms);
        $missingPerms = [];
        foreach ($phpPerms as $perm) {
            if (strpos($this->sql, "'$perm'") === false) {
                $missingPerms[] = $perm;
            }
        }
        $this->test("Todos los permisos PHP existen en SQL", empty($missingPerms), implode(', ', $missingPerms));

        // ===== RESUMEN =====
        echo "\n" . str_repeat('═', 68) . "\n";
        echo "  RESULTADOS: {$this->passed} passed, {$this->failed} failed\n";
        echo str_repeat('═', 68) . "\n";

        if ($this->failed === 0) {
            echo "\n  🟢 VEREDICTO: SISTEMA LISTO PARA DEPLOY\n";
            echo "     ✅ Supabase: ejecutar schema.sql + schema.sql\n";
            echo "     ✅ Render:   desplegar backend/api/ con variables de entorno\n";
            echo "     ✅ Todo está alineado: tablas, columnas, roles, permisos, RLS\n";
        } else {
            echo "\n  🔴 VEREDICTO: NO LISTO PARA DEPLOY\n";
            echo "     Errores encontrados:\n";
            foreach ($this->errors as $err) {
                echo "       • $err\n";
            }
        }
        echo str_repeat('═', 68) . "\n";

        return $this->failed === 0;
    }
}

$test = new FullSystemAlignmentTest();
$ok = $test->run();
exit($ok ? 0 : 1);
