<?php
/**
 * =============================================================================
 * RegressionTest.php — Test de regresión.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica estáticamente que errores previos no vuelvan:
 *   - Eliminación completa de SUPER_RECTOR (SQL y PHP).
 *   - Preservación del rol GUARDIAN y tabla guardians.
 *   - Normalización de teléfono (whatsapp_phone_normalized y trigger).
 *   - Constraints UNIQUE de document_number por escuela.
 *   - Integridad de cadena de auditoría (chain_hash, prev_audit_id).
 *   - Policy abierta de jwt_blocklist para operaciones pre-auth.
 *
 * NOTA: test estático; no requiere PostgreSQL.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class RegressionTest extends PHPUnit\Framework\TestCase
{
    private string $migrationSql;
    private string $allPhp;

    public function setUp(): void
    {
        $this->migrationSql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
        $this->allPhp = '';
        foreach (glob(__DIR__ . '/../../backend/api/routes/*.php') as $f) $this->allPhp .= file_get_contents($f);
        foreach (glob(__DIR__ . '/../../backend/api/workers/*.php') as $f) $this->allPhp .= file_get_contents($f);
        foreach (glob(__DIR__ . '/../../backend/api/lib/*.php') as $f) $this->allPhp .= file_get_contents($f);
    }

    public function testSuperRectorRemoved(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('is_super_rector', $this->allPhp,
            'BUG-REGRESSION: is_super_rector() debe estar eliminado');
        $this->assertStringNotContainsStringIgnoringCase('SUPER_RECTOR', $this->allPhp,
            'BUG-REGRESSION: SUPER_RECTOR debe estar eliminado del PHP');
        // En SQL, permitir DROP POLICY IF EXISTS (limpieza de legacy)
        $sqlNoDrops = preg_replace('/DROP\s+POLICY\s+IF\s+EXISTS\s+\w*super_rector\w*/i', '', $this->migrationSql);
        $this->assertStringNotContainsStringIgnoringCase('SUPER_RECTOR', $sqlNoDrops,
            'BUG-REGRESSION: SUPER_RECTOR debe estar eliminado del SQL (excepto DROP POLICY de limpieza)');
    }

    public function testGuardianRoleRestored(): void
    {
        $this->assertStringContainsStringIgnoringCase('GUARDIAN', $this->migrationSql,
            'BUG-REGRESSION: Rol GUARDIAN debe existir en SQL');
        $this->assertStringContainsStringIgnoringCase('GUARDIAN', $this->allPhp,
            'BUG-REGRESSION: Rol GUARDIAN debe usarse en PHP');
        $this->assertStringContainsStringIgnoringCase('guardians', $this->migrationSql,
            'BUG-REGRESSION: Tabla guardians debe existir');
    }

    public function testGuardianPhoneNormalization(): void
    {
        $this->assertStringContainsString('whatsapp_phone_normalized', $this->migrationSql,
            'BUG-REGRESSION: Columna whatsapp_phone_normalized debe existir');
        $this->assertStringContainsString('trg_guardians_normalize_phone', $this->migrationSql,
            'BUG-REGRESSION: Trigger de normalización de teléfono debe existir');
    }

    public function testNoDuplicateDocumentNumbers(): void
    {
        // El UNIQUE compuesto (school_id, document_number) debe existir
        $this->assertStringContainsString('uq_users_school_document', $this->migrationSql,
            'BUG-REGRESSION: UNIQUE compuesto uq_users_school_document debe existir');
        $this->assertStringContainsString('uq_students_school_document', $this->migrationSql,
            'BUG-REGRESSION: UNIQUE compuesto uq_students_school_document debe existir');
    }

    public function testAuditChainIntegrity(): void
    {
        $this->assertStringContainsString('chain_hash', $this->migrationSql,
            'BUG-REGRESSION: chain_hash debe existir para integridad de auditoría');
        $this->assertStringContainsString('prev_audit_id', $this->migrationSql,
            'BUG-REGRESSION: prev_audit_id debe existir para cadena de auditoría');
    }

    public function testJwtBlocklistOpenPolicy(): void
    {
        $this->assertStringContainsString('jbl_select ON jwt_blocklist FOR SELECT USING(true)', $this->migrationSql,
            'BUG-REGRESSION: jwt_blocklist debe mantener policy abierta para operaciones previas a auth');
    }

    public function testStudentGroupAssignmentUniqueConstraint(): void
    {
        $this->assertStringContainsString('uq_sga_student_group', $this->migrationSql,
            'BUG-REGRESSION: UNIQUE constraint uq_sga_student_group debe existir');
    }
}
