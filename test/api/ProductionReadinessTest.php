<?php
/**
 * =============================================================================
 * 15_ProductionReadinessTest.php — Test de preparación para producción.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica características críticas para producción:
 *   - No hay secretos ni contraseñas hardcodeadas en PHP.
 *   - RLS habilitado en al menos 10 tablas sensibles.
 *   - Más de 20 índices para rendimiento.
 *   - Al menos 5 tablas particionadas para grandes volúmenes.
 *   - Auditoría (global_audit_logs, trigger fn_audit_chain_trigger).
 *   - Rate limiting (rate_limits), backup_email, email_verified,
 *     phone_verified y soft delete (deleted_at).
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class ProductionReadinessTest extends PHPUnit\Framework\TestCase
{
    private string $sql;
    private string $allPhp;

    public function setUp(): void
    {
        $this->sql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
        $this->allPhp = '';
        foreach (glob(__DIR__ . '/../../backend/api/routes/*.php') as $f) $this->allPhp .= file_get_contents($f);
        foreach (glob(__DIR__ . '/../../backend/api/workers/*.php') as $f) $this->allPhp .= file_get_contents($f);
    }

    public function testNoHardcodedSecrets(): void
    {
        $this->assertStringNotContainsString("password = '", $this->allPhp,
            'No debe haber contraseñas hardcodeadas');
        $this->assertStringNotContainsString("api_key = '", $this->allPhp,
            'No debe haber API keys hardcodeadas');
    }

    public function testRlsEnabledOnAllSensitiveTables(): void
    {
        preg_match_all('/ALTER\s+TABLE\s+(\w+)\s+ENABLE\s+ROW\s+LEVEL\s+SECURITY/si', $this->sql, $m);
        $rlsTables = $m[1];
        $this->assertGreaterThanOrEqual(10, count($rlsTables),
            'Debe haber al menos 10 tablas con RLS en producción');
    }

    public function testIndexesExistForPerformance(): void
    {
        preg_match_all('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/si', $this->sql, $m);
        $this->assertGreaterThanOrEqual(20, count($m[1]),
            'Debe haber al menos 20 índices para rendimiento en producción');
    }

    public function testPartitioningForLargeTables(): void
    {
        preg_match_all('/PARTITION\s+BY\s+RANGE/si', $this->sql, $m);
        $this->assertGreaterThanOrEqual(5, count($m[0]),
            'Debe haber al menos 5 tablas particionadas para grandes volúmenes');
    }

    public function testAuditLoggingEnabled(): void
    {
        $this->assertStringContainsString('global_audit_logs', $this->sql,
            'Debe existir tabla de auditoría global');
        $this->assertStringContainsString('fn_audit_chain_trigger', $this->sql,
            'Debe existir trigger de cadena de auditoría');
    }

    public function testRateLimitingConfigured(): void
    {
        $this->assertStringContainsString('rate_limits', $this->sql,
            'Debe existir tabla de rate limiting');
    }

    public function testBackupEmailColumnExists(): void
    {
        $this->assertStringContainsString('backup_email', $this->sql,
            'Debe existir columna backup_email para recuperación');
    }

    public function testEmailVerificationColumnExists(): void
    {
        $this->assertStringContainsString('email_verified', $this->sql,
            'Debe existir columna email_verified');
    }

    public function testPhoneVerificationColumnExists(): void
    {
        $this->assertStringContainsString('phone_verified', $this->sql,
            'Debe existir columna phone_verified');
    }

    public function testSoftDeleteImplemented(): void
    {
        $this->assertStringContainsString('deleted_at', $this->sql,
            'Debe existir soft delete (deleted_at)');
    }
}
