<?php
/**
 * =============================================================================
 * test/simulaciones/m2m/CellularSimulator.php — Simulador del módem celular M2M (F-10).
 * =============================================================================
 * Modela el enlace M2M del nodo: registro en red, calidad de señal, interfaz
 * up/down y cambios de portador. produce el campo `cell` de la telemetría.
 * Escenarios: buena señal, fading, pérdida total, reconexión, interfaz caída.
 * =============================================================================
 */

class CellularSimulator
{
    private bool $ifaceUp = true;
    private bool $registered = true;
    private int $signal = 75;      // %
    private string $carrier = 'Claro';
    private string $tech = 'lte';

    public function interfaceDown(): void  { $this->ifaceUp = false; $this->registered = false; }
    public function interfaceUp(): void    { $this->ifaceUp = true; }
    public function deregister(): void     { $this->registered = false; }
    public function register(): void       { if ($this->ifaceUp) $this->registered = true; }
    public function setSignal(int $pct): void { $this->signal = max(0, min(100, $pct)); }
    public function fade(int $db): void    { $this->setSignal($this->signal - $db); }
    public function setCarrier(string $c, string $tech = 'lte'): void { $this->carrier = $c; $this->tech = $tech; }

    /** Estado como lo reporta el CellularManager del edge. */
    public function cell(): array {
        return [
            'interface_up' => $this->ifaceUp,
            'registered'   => $this->registered,
            'signal_pct'   => $this->ifaceUp ? $this->signal : -1,
            'carrier'      => $this->registered ? $this->carrier : '',
            'tech'         => $this->tech,
        ];
    }

    public function telemetry(array $extra = []): array {
        return array_merge(['cell' => $this->cell()], $extra);
    }

    // ── Escenarios de conveniencia ──
    public static function healthy(): self      { return new self(); }
    public static function weakSignal(): self   { $s = new self(); $s->setSignal(10); return $s; }
    public static function deadInterface(): self { $s = new self(); $s->interfaceDown(); return $s; }
}
