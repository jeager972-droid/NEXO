<?php
/**
 * =============================================================================
 * test/simulaciones/almacenamiento/StorageSimulator.php — Disco + cola/DLQ (F-06/F-13).
 * =============================================================================
 * Modela el almacenamiento del nodo edge: espacio libre que decrece, profundidad
 * de cola de eventos pendientes y backlog de DLQ. Escenarios: sano, llenándose,
 * lleno, DLQ creciendo por fallos de sync. produce disk_free_mb/pending_events/
 * dlq_count/clock_drift_s para ctProcessTelemetry.
 * =============================================================================
 */

class StorageSimulator
{
    private int $diskFreeMb;
    private int $pending = 0;
    private int $dlq = 0;
    private int $clockDrift = 0;

    public function __construct(int $diskFreeMb = 8192) { $this->diskFreeMb = $diskFreeMb; }

    public function consumeMb(int $mb): void  { $this->diskFreeMb = max(0, $this->diskFreeMb - $mb); }
    public function setFreeMb(int $mb): void  { $this->diskFreeMb = $mb; }
    public function enqueue(int $n): void     { $this->pending += $n; }
    public function flushPending(int $n = -1): void {
        $n = $n < 0 ? $this->pending : min($n, $this->pending);
        $this->pending -= $n;
    }
    public function syncFail(int $n): void    { $this->pending = max(0, $this->pending - $n); $this->dlq += $n; }
    public function retryDlq(int $n = -1): void { // edge requeueDlqItems
        $n = $n < 0 ? $this->dlq : min($n, $this->dlq);
        $this->dlq -= $n; $this->pending += $n;
    }
    public function setClockDrift(int $s): void { $this->clockDrift = $s; }

    public function telemetry(array $extra = []): array {
        return array_merge([
            'disk_free_mb'   => $this->diskFreeMb,
            'pending_events' => $this->pending,
            'dlq_count'      => $this->dlq,
            'clock_drift_s'  => $this->clockDrift,
        ], $extra);
    }
}
