<?php
/**
 * =============================================================================
 * RiskEngineV3Test — Test unitario del motor de riesgo pedagógico v3.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica las funciones puras de RiskEngineV3:
 *   - compareLevels(): comparación de niveles de severidad.
 *   - maxLevel(): nivel más alto entre dos.
 *   - validateRuleRanges(): validación de rangos de reglas (via reflection).
 *
 * NOTA: no requiere PostgreSQL; testea lógica de comparación y validación.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';
require_once __DIR__ . '/../../backend/api/lib/RiskEngineV3.php';

use PHPUnit\Framework\TestCase;

class RiskEngineV3Test extends TestCase
{
    // ── compareLevels ──────────────────────────────────────────────────────

    public function testCompareLevelsEqual(): void
    {
        $this->assertEquals(0, RiskEngineV3::compareLevels('LEVE', 'LEVE'));
        $this->assertEquals(0, RiskEngineV3::compareLevels('ALTA', 'ALTA'));
    }

    public function testCompareLevelsLessThan(): void
    {
        $this->assertEquals(-1, RiskEngineV3::compareLevels('LEVE', 'MODERADA'));
        $this->assertEquals(-1, RiskEngineV3::compareLevels('MODERADA', 'ALTA'));
        $this->assertEquals(-1, RiskEngineV3::compareLevels('ALTA', 'MUY_ALTA'));
    }

    public function testCompareLevelsGreaterThan(): void
    {
        $this->assertEquals(1, RiskEngineV3::compareLevels('MODERADA', 'LEVE'));
        $this->assertEquals(1, RiskEngineV3::compareLevels('MUY_ALTA', 'ALTA'));
    }

    public function testCompareLevelsNoneAndSinImportancia(): void
    {
        // NONE y SIN_IMPORTANCIA tienen el mismo orden (0)
        $this->assertEquals(0, RiskEngineV3::compareLevels('NONE', 'SIN_IMPORTANCIA'));
        $this->assertEquals(-1, RiskEngineV3::compareLevels('NONE', 'LEVE'));
    }

    public function testCompareLevelsUnknownDefaultsToZero(): void
    {
        // Niveles desconocidos deben defaultear a 0
        $this->assertEquals(0, RiskEngineV3::compareLevels('UNKNOWN', 'NONE'));
        $this->assertEquals(-1, RiskEngineV3::compareLevels('UNKNOWN', 'LEVE'));
    }

    // ── maxLevel ───────────────────────────────────────────────────────────

    public function testMaxLevelReturnsHigher(): void
    {
        $this->assertEquals('ALTA', RiskEngineV3::maxLevel('LEVE', 'ALTA'));
        $this->assertEquals('MUY_ALTA', RiskEngineV3::maxLevel('ALTA', 'MUY_ALTA'));
    }

    public function testMaxLevelReturnsFirstWhenEqual(): void
    {
        $this->assertEquals('LEVE', RiskEngineV3::maxLevel('LEVE', 'LEVE'));
    }

    public function testMaxLevelWithNone(): void
    {
        $this->assertEquals('LEVE', RiskEngineV3::maxLevel('NONE', 'LEVE'));
        $this->assertEquals('MODERADA', RiskEngineV3::maxLevel('MODERADA', 'NONE'));
    }

    // ── validateRuleRanges (via reflection) ────────────────────────────────

    private function callValidateRuleRanges(array $rule): void
    {
        $method = new ReflectionMethod(RiskEngineV3::class, 'validateRuleRanges');
        $method->setAccessible(true);
        $method->invoke(null, $rule);
    }

    private function validRuleTemplate(): array
    {
        return [
            'risk_level'           => 'LEVE',
            'recurrence_count'     => 10,
            'min_recurrence'       => 3,
            'max_recurrence'       => 20,
            'window_days'          => 5,
            'min_window_days'      => 3,
            'max_window_days'      => 14,
            'single_occurrence'    => false,
        ];
    }

    public function testValidateRuleRangesValid(): void
    {
        // No debe lanzar excepción
        $this->callValidateRuleRanges($this->validRuleTemplate());
        $this->assertTrue(true); // Si llegó aquí, pasó
    }

    public function testValidateRuleRangesRecurrenceOutOfRange(): void
    {
        $rule = $this->validRuleTemplate();
        $rule['recurrence_count'] = 25; // max es 20
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reincidencias fuera de rango');
        $this->callValidateRuleRanges($rule);
    }

    public function testValidateRuleRangesWindowOutOfRange(): void
    {
        $rule = $this->validRuleTemplate();
        $rule['window_days'] = 30; // max es 14
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('plazo de dias fuera de rango');
        $this->callValidateRuleRanges($rule);
    }

    public function testValidateRuleRangesMuyAltaMustBeSingleOccurrence(): void
    {
        $rule = $this->validRuleTemplate();
        $rule['risk_level'] = 'MUY_ALTA';
        $rule['single_occurrence'] = false;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MUY_ALTA: debe ser siempre single_occurrence');
        $this->callValidateRuleRanges($rule);
    }

    public function testValidateRuleRangesMuyAltaWithSingleOccurrence(): void
    {
        $rule = $this->validRuleTemplate();
        $rule['risk_level'] = 'MUY_ALTA';
        $rule['single_occurrence'] = true;
        // No debe lanzar excepción
        $this->callValidateRuleRanges($rule);
        $this->assertTrue(true);
    }

    // ── Constantes ─────────────────────────────────────────────────────────

    public function testLevelConstantsExist(): void
    {
        $this->assertEquals('NONE', RiskEngineV3::LEVEL_NONE);
        $this->assertEquals('SIN_IMPORTANCIA', RiskEngineV3::LEVEL_SIN_IMPORTANCIA);
        $this->assertEquals('LEVE', RiskEngineV3::LEVEL_LEVE);
        $this->assertEquals('MODERADA', RiskEngineV3::LEVEL_MODERADA);
        $this->assertEquals('ALTA', RiskEngineV3::LEVEL_ALTA);
        $this->assertEquals('MUY_ALTA', RiskEngineV3::LEVEL_MUY_ALTA);
    }

    public function testStateConstantsExist(): void
    {
        $this->assertEquals('OBSERVACION', RiskEngineV3::STATE_OBSERVACION);
        $this->assertEquals('ALERTA_PEDAGOGICA', RiskEngineV3::STATE_ALERTA_PEDAGOGICA);
        $this->assertEquals('SEGUIMIENTO', RiskEngineV3::STATE_SEGUIMIENTO);
        $this->assertEquals('INTERVENCION_PRIORITARIA', RiskEngineV3::STATE_INTERVENCION_PRIORITARIA);
        $this->assertEquals('ATENCION_INMEDIATA', RiskEngineV3::STATE_ATENCION_INMEDIATA);
    }

    public function testAlertStateConstantsExist(): void
    {
        $this->assertEquals('abierta', RiskEngineV3::ALERT_ABIERTA);
        $this->assertEquals('en_seguimiento', RiskEngineV3::ALERT_EN_SEGUIMIENTO);
        $this->assertEquals('resuelta', RiskEngineV3::ALERT_RESUELTA);
        $this->assertEquals('descartada', RiskEngineV3::ALERT_DESCARTADA);
    }
}
