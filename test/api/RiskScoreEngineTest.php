<?php
/**
 * =============================================================================
 * RiskScoreEngineTest — Test unitario del motor de scoring de riesgo.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica las funciones puras de RiskScoreEngine:
 *   - computeScore(): cálculo de puntaje con pesos, baseline y techo.
 *   - scoreToLevel(): traducción de puntaje a nivel categórico.
 *   - shouldAlert(): umbral de alerta.
 *
 * NOTA: no requiere PostgreSQL; son funciones matemáticas puras.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';
require_once __DIR__ . '/../../backend/api/lib/RiskScoreEngine.php';

use PHPUnit\Framework\TestCase;

class RiskScoreEngineTest extends TestCase
{
    public function testComputeScoreZeroEvents(): void
    {
        $score = RiskScoreEngine::computeScore(0, 0, 0);
        $this->assertEquals(0.0, $score);
    }

    public function testComputeScoreLateOnly(): void
    {
        // 5 tardanzas × WEIGHT_LATE(5.0) = 25
        $score = RiskScoreEngine::computeScore(5, 0, 5);
        $this->assertEquals(25.0, $score);
    }

    public function testComputeScoreAbsenceOnly(): void
    {
        // 3 inasistencias × WEIGHT_ABSENCE(15.0) = 45
        $score = RiskScoreEngine::computeScore(0, 3, 3);
        $this->assertEquals(45.0, $score);
    }

    public function testComputeScoreBathroomBaseline(): void
    {
        // 3 salidas baño = baseline, sin penalización
        $score = RiskScoreEngine::computeScore(0, 0, 3, 3);
        $this->assertEquals(0.0, $score);
    }

    public function testComputeScoreBathroomOverflow(): void
    {
        // 5 salidas baño - baseline(3) = 2 × WEIGHT_BATHROOM(2.0) = 4
        $score = RiskScoreEngine::computeScore(0, 0, 5, 5);
        $this->assertEquals(4.0, $score);
    }

    public function testComputeScoreOverflowEvents(): void
    {
        // 25 eventos - BASELINE(20) = 5 × WEIGHT_OVERFLOW(0.5) = 2.5
        $score = RiskScoreEngine::computeScore(0, 0, 25);
        $this->assertEquals(2.5, $score);
    }

    public function testComputeScorePatternPenalty(): void
    {
        // 0 eventos + patternPenalty = 10
        $score = RiskScoreEngine::computeScore(0, 0, 0, 0, 10.0);
        $this->assertEquals(10.0, $score);
    }

    public function testComputeScoreCappedAtMax(): void
    {
        // 100 inasistencias × 15 = 1500 → capped at MAX_SCORE(100)
        $score = RiskScoreEngine::computeScore(0, 100, 100);
        $this->assertEquals(100.0, $score);
    }

    public function testComputeScoreCombined(): void
    {
        // 2 tardanzas(10) + 1 ausencia(15) + 5 baño-3baseline=2×2=4 + overflow 0 + pattern 0 = 29
        $score = RiskScoreEngine::computeScore(2, 1, 8, 5, 0.0);
        $expected = 2 * 5.0 + 1 * 15.0 + (5 - 3) * 2.0 + max(0, 8 - 20) * 0.5;
        $this->assertEquals($expected, $score);
    }

    public function testScoreToLevelLow(): void
    {
        $this->assertEquals('LOW', RiskScoreEngine::scoreToLevel(0.0));
        $this->assertEquals('LOW', RiskScoreEngine::scoreToLevel(29.9));
    }

    public function testScoreToLevelMedium(): void
    {
        $this->assertEquals('MEDIUM', RiskScoreEngine::scoreToLevel(30.0));
        $this->assertEquals('MEDIUM', RiskScoreEngine::scoreToLevel(59.9));
    }

    public function testScoreToLevelHigh(): void
    {
        $this->assertEquals('HIGH', RiskScoreEngine::scoreToLevel(60.0));
        $this->assertEquals('HIGH', RiskScoreEngine::scoreToLevel(79.9));
    }

    public function testScoreToLevelCritical(): void
    {
        $this->assertEquals('CRITICAL', RiskScoreEngine::scoreToLevel(80.0));
        $this->assertEquals('CRITICAL', RiskScoreEngine::scoreToLevel(100.0));
    }

    public function testShouldAlertBelowThreshold(): void
    {
        $this->assertFalse(RiskScoreEngine::shouldAlert(69.9));
        $this->assertFalse(RiskScoreEngine::shouldAlert(0.0));
    }

    public function testShouldAlertAtThreshold(): void
    {
        $this->assertTrue(RiskScoreEngine::shouldAlert(70.0));
    }

    public function testShouldAlertAboveThreshold(): void
    {
        $this->assertTrue(RiskScoreEngine::shouldAlert(95.0));
    }

    public function testConstantsAreConsistent(): void
    {
        // Umbrales ordenados de menor a mayor: MEDIUM(30) < HIGH(60) < CRITICAL(80) <= MAX(100)
        $this->assertGreaterThan(RiskScoreEngine::THRESHOLD_HIGH, RiskScoreEngine::THRESHOLD_CRITICAL);
        $this->assertGreaterThan(RiskScoreEngine::THRESHOLD_MEDIUM, RiskScoreEngine::THRESHOLD_HIGH);
        $this->assertLessThanOrEqual(RiskScoreEngine::MAX_SCORE, RiskScoreEngine::THRESHOLD_CRITICAL);
        // ALERT_THRESHOLD(70) debe estar entre HIGH(60) y CRITICAL(80)
        $this->assertGreaterThanOrEqual(RiskScoreEngine::THRESHOLD_HIGH, RiskScoreEngine::ALERT_THRESHOLD);
        $this->assertLessThanOrEqual(RiskScoreEngine::THRESHOLD_CRITICAL, RiskScoreEngine::ALERT_THRESHOLD);
    }
}
