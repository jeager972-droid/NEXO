<?php
/**
 * =============================================================================
 * simulaciones/termico/ThermalSimulator.php — Curva térmica del SoC (F-06/F-12).
 * =============================================================================
 * Modela la temperatura del SoC del nodo (disipación pasiva, sin ventilador —
 * veredicto F-12). Solo observabilidad: el central alerta por umbral; el nodo
 * NO controla refrigeración. Escenarios: normal, carga térmica, sobrecalentado,
 * enfriamiento. produce `cpu_temp_c` para ctProcessTelemetry.
 * =============================================================================
 */

class ThermalSimulator
{
    private int $tempC;
    private int $ambientC;
    private int $loadHeatPerStep; // °C ganados por step bajo carga

    public function __construct(int $ambientC = 25, int $loadHeatPerStep = 3) {
        $this->ambientC = $ambientC;
        $this->tempC = $ambientC + 20; // SoC idle ~45°C en disipación pasiva
        $this->loadHeatPerStep = $loadHeatPerStep;
    }

    public function loadStep(): void   { $this->tempC += $this->loadHeatPerStep; }
    public function coolStep(): void   { $this->tempC = max($this->ambientC + 15, $this->tempC - 2); }
    public function setTemp(int $c): void { $this->tempC = $c; }
    public function tempC(): int { return $this->tempC; }

    public function telemetry(array $extra = []): array {
        return array_merge(['cpu_temp_c' => $this->tempC], $extra);
    }
}
