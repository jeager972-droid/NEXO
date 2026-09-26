<?php
/**
 * =============================================================================
 * TriggerTest.php — Test de triggers y funciones SQL.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica en sql/schema.sql la existencia de:
 *   - Trigger y función de normalización de teléfono (guardians).
 *   - Trigger y funciones de cadena de auditoría (global_audit_logs).
 *   - Las funciones asociadas estén definidas.
 *
 * NOTA: test estático; no requiere PostgreSQL.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class TriggerTest extends PHPUnit\Framework\TestCase
{
    private string $sql;

    public function setUp(): void
    {
        $this->sql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
    }

    public function testPhoneNormalizationTrigger(): void
    {
        $this->assertStringContainsString('trg_guardians_normalize_phone', $this->sql);
        $this->assertStringContainsString('fn_guardians_normalize_phone', $this->sql);
        $this->assertStringContainsString('whatsapp_phone_normalized', $this->sql);
    }

    public function testAuditChainTrigger(): void
    {
        $this->assertStringContainsString('trg_audit_chain', $this->sql);
        $this->assertStringContainsString('fn_audit_chain_trigger', $this->sql);
        $this->assertStringContainsString('fn_calculate_audit_hash', $this->sql);
        $this->assertStringContainsString('fn_validate_audit_chain', $this->sql);
        $this->assertStringContainsString('chain_hash', $this->sql);
    }

    public function testTriggerFunctionsExist(): void
    {
        $funcs = ['fn_guardians_normalize_phone', 'fn_audit_chain_trigger', 'fn_calculate_audit_hash', 'fn_validate_audit_chain'];
        foreach ($funcs as $f) {
            $this->assertStringContainsString("FUNCTION $f", $this->sql,
                "Función de trigger '$f' no encontrada");
        }
    }

    public function testTriggerTargetsCorrectTable(): void
    {
        $this->assertMatchesRegularExpression('/CREATE\s+TRIGGER\s+trg_guardians_normalize_phone.*ON\s+guardians/si', $this->sql,
            'Trigger de normalización debe estar en tabla guardians');
        $this->assertMatchesRegularExpression('/CREATE\s+TRIGGER\s+trg_audit_chain.*ON\s+global_audit_logs/si', $this->sql,
            'Trigger de audit chain debe estar en global_audit_logs');
    }
}
