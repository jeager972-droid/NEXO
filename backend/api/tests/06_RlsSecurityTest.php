<?php
/**
 * 06_RlsSecurityTest.php
 * Verifica RLS policies en tablas sensibles.
 */
require_once __DIR__ . '/../vendor/autoload.php';

class RlsSecurityTest extends PHPUnit\Framework\TestCase
{
    private string $sql;
    private array $rlsTables = [];
    private array $policies = [];

    public function setUp(): void
    {
        $this->sql = file_get_contents(__DIR__ . '/../sql/nexo_full_migration.sql');
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
        foreach ($this->policies as $table => $pols) {
            $hasSchoolFilter = false;
            // Buscar en el SQL las policies de esta tabla
            preg_match_all('/CREATE\s+POLICY\s+\w+\s+ON\s+' . $table . '\s+.*?USING\s*\((.*?)\)(?:\s+WITH\s+CHECK\s*\((.*?)\))?/si', $this->sql, $pm, PREG_SET_ORDER);
            foreach ($pm as $p) {
                $expr = ($p[1] ?? '') . ($p[2] ?? '');
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
        // jwt_blocklist tiene policy abierta (true) que es intencional
        $allowedOpen = ['jwt_blocklist'];
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
