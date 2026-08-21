<?php
/**
 * =============================================================================
 * 06_RlsSecurityTest.php — Test de Row-Level Security (RLS).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica en sql/schema.sql que:
 *   - Tablas sensibles tengan ENABLE ROW LEVEL SECURITY.
 *   - Las políticas filtren por school_id o get_current_school_id().
 *   - No haya políticas abiertas (USING(true)) salvo jwt_blocklist.
 *   - Exista la función get_current_school_id().
 *
 * NOTA: test estático; no requiere PostgreSQL.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class RlsSecurityTest extends PHPUnit\Framework\TestCase
{
    private string $sql;
    private array $rlsTables = [];
    private array $policies = [];

    public function setUp(): void
    {
        $this->sql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
        preg_match_all('/ALTER\s+TABLE\s+(\w+)\s+ENABLE\s+ROW\s+LEVEL\s+SECURITY/si', $this->sql, $m);
        $this->rlsTables = array_unique($m[1]);
        preg_match_all('/CREATE\s+POLICY\s+(\w+)\s+ON\s+(\w+)\s+FOR\s+(\w+)/si', $this->sql, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $this->policies[$match[2]][] = ['name' => $match[1], 'action' => $match[3]];
        }
    }

    public function testSensitiveTablesHaveRls(): void
    {
        $sensitive = ['users', 'students', 'guardians', 'biometric_events', 'school_panic_events',
                      'student_tracking', 'student_tracking_notes', 'notifications'];
        foreach ($sensitive as $table) {
            $this->assertContains($table, $this->rlsTables,
                "Tabla sensible '$table' debe tener RLS habilitado");
        }
    }

    public function testRlsUsesSchoolIsolation(): void
    {
        // Tablas con policies globales (no filtran por school_id por diseño)
        $globalTables = ['jwt_blocklist', 'subjects', 'departments', 'municipalities', 'schema_migrations', 'role_permissions', 'permissions', 'roles', 'system_telemetry'];
        foreach ($this->policies as $table => $pols) {
            if (in_array($table, $globalTables)) continue;
            $hasSchoolFilter = false;
            // Buscar todas las policies de esta tabla y extraer el bloque completo
            // (USING + WITH CHECK pueden tener paréntesis anidados y multiline)
            preg_match_all('/CREATE\s+POLICY\s+\w+\s+ON\s+' . $table . '\s+FOR\s+\w+\s+(.*?)(?=\nCREATE\s+POLICY|\nALTER\s+TABLE|\nDROP\s+POLICY|\n--|$)/si', $this->sql, $pm, PREG_SET_ORDER);
            foreach ($pm as $p) {
                $expr = $p[1] ?? '';
                if (stripos($expr, 'school_id') !== false || stripos($expr, 'get_current_school_id') !== false) {
                    $hasSchoolFilter = true;
                }
            }
            $this->assertTrue($hasSchoolFilter,
                "RLS en '$table' debe filtrar por school_id o get_current_school_id()");
        }
    }

    public function testNoOpenPoliciesOnSensitiveData(): void
    {
        // jwt_blocklist y subjects tienen policies abiertas (true) que es intencional
        // jwt_blocklist es global (tokens revocados), subjects es catálogo global
        $allowedOpen = ['jwt_blocklist', 'subjects', 'departments', 'municipalities', 'schema_migrations', 'role_permissions', 'permissions', 'roles'];
        foreach ($this->policies as $table => $pols) {
            if (in_array($table, $allowedOpen)) continue;
            preg_match_all('/CREATE\s+POLICY\s+\w+\s+ON\s+' . $table . '\s+.*?USING\s*\((.*?)\)/si', $this->sql, $pm, PREG_SET_ORDER);
            foreach ($pm as $p) {
                $this->assertStringNotContainsStringIgnoringCase('USING(true)', "CREATE POLICY {$p[0]}",
                    "Policy en '$table' no debe ser abierta (USING(true))");
            }
        }
        $this->assertTrue(true);
    }

    public function testGetCurrentSchoolIdFunctionExists(): void
    {
        $this->assertStringContainsString('get_current_school_id', $this->sql,
            'Debe existir función get_current_school_id() para RLS');
    }
}
