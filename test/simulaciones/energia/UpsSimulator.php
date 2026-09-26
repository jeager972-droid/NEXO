<?php
/**
 * =============================================================================
 * test/simulaciones/energia/UpsSimulator.php — Simulador de UPS/energía (F-09).
 * =============================================================================
 * Modela la máquina de estados eléctrica del nodo edge con UPS real:
 *   MAINS → (corte) BATTERY → (drenaje) LOW_BATTERY → CRITICAL → apagado
 *   BATTERY → (restauración) MAINS
 *
 * Cada step() devuelve el valor `power_state` que el PowerMonitor del edge
 * reportaría en telemetría; los tests lo alimentan a ctProcessTelemetry.
 * =============================================================================
 */

class UpsSimulator
{
    private int $capacity;      // % batería
    private bool $mains;        // energía de red presente
    private bool $drained;      // batería agotada → nodo apagado

    public function __construct(int $capacity = 100, bool $mains = true) {
        $this->capacity = max(0, min(100, $capacity));
        $this->mains = $mains;
        $this->drained = false;
    }

    public function powerCut(): void   { $this->mains = false; }
    public function powerRestore(): void { $this->mains = true; $this->drained = false; } // restauración reenciende el nodo
    public function drain(int $pct): void {
        if (!$this->mains) {
            $this->capacity = max(0, $this->capacity - $pct);
            if ($this->capacity <= 0) $this->drained = true;
        }
    }
    public function charge(int $pct): void {
        if ($this->mains) $this->capacity = min(100, $this->capacity + $pct);
    }

    public function isOff(): bool { return $this->drained; }

    /** power_state como lo reporta el PowerMonitor del edge. */
    public function state(): string {
        if ($this->drained) return 'CRITICAL'; // último estado antes de apagarse
        if ($this->mains)   return 'MAINS';
        if ($this->capacity <= 8)  return 'CRITICAL';
        if ($this->capacity <= 25) return 'LOW_BATTERY';
        return 'BATTERY';
    }

    /** ¿El nodo debería iniciar shutdown ordenado? (espejo de shouldShutdown). */
    public function shouldShutdown(): bool {
        return !$this->mains && $this->capacity <= 8;
    }

    /** Telemetría lista para ctProcessTelemetry. */
    public function telemetry(array $extra = []): array {
        return array_merge(['power_state' => $this->state()], $extra);
    }
}
