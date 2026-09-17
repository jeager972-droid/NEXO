<?php
/**
 * =============================================================================
 * NodeTelemetryTest.php — Validación Lote 5 (F-06 telemetría, F-09 UPS,
 * F-10 M2M, F-13 DLQ) del lado central.
 * =============================================================================
 *
 * Bidireccional: (a) telemetría sana → sin incidentes; (b) cada violación de
 * umbral → incidente tipado + dedup; (c) recuperación → sin nuevas alarmas.
 * Multidimensional: térmico, disco, reloj, DLQ, energía (4 estados), celular
 * (interfaz caída / señal débil / sana), combinaciones, sensores ausentes.
 *
 * Simuladores: simulaciones/{termico,energia,m2m,almacenamiento}/.
 * Edge C++ cubierto por tests/test_node_monitor.cpp (tag [power]/[cellular]/
 * [telemetry]/[dlq]) — mismos escenarios, capa física.
 * =============================================================================
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';
require_once __DIR__ . '/../../backend/api/workers/contingency_lib.php';
require_once __DIR__ . '/NodeHealthGateTest.php'; // FakePDO / FakeStmt / PDO shim
require_once __DIR__ . '/../../simulaciones/energia/UpsSimulator.php';
require_once __DIR__ . '/../../simulaciones/m2m/CellularSimulator.php';
require_once __DIR__ . '/../../simulaciones/termico/ThermalSimulator.php';
require_once __DIR__ . '/../../simulaciones/almacenamiento/StorageSimulator.php';

class NodeTelemetryTest extends PHPUnit\Framework\TestCase
{
    private array $th;

    protected function setUp(): void {
        $this->th = ctTelemetryThresholds();
    }

    private function types(array $t): array {
        return array_map(fn($v) => $v[0], ctTelemetryViolations($t, $this->th));
    }

    // ── Decisión pura por dimensión ──

    public function testHealthyTelemetryNoViolations(): void {
        $t = array_merge(
            ThermalSimulator::class ? [] : [], // placeholder
        );
        $thermal = new ThermalSimulator();
        $ups = new UpsSimulator();
        $cell = CellularSimulator::healthy();
        $store = new StorageSimulator();
        $t = array_merge($thermal->telemetry(), $ups->telemetry(), $cell->telemetry(), $store->telemetry());
        $this->assertSame([], $this->types($t), 'Telemetría sana no debe generar violaciones');
    }

    public function testThermalThresholds(): void {
        $th = new ThermalSimulator();
        $th->setTemp(81);
        $this->assertContains('TEMP_ALTA', $this->types($th->telemetry()));
        $th->setTemp(92);
        $this->assertContains('TEMP_CRITICA', $this->types($th->telemetry()));
        $th->setTemp(50);
        $this->assertSame([], $this->types($th->telemetry()));
    }

    public function testDiskThresholds(): void {
        $s = new StorageSimulator();
        $s->setFreeMb(400);
        $this->assertContains('DISCO_BAJO', $this->types($s->telemetry()));
        $s->setFreeMb(100);
        $this->assertContains('DISCO_CRITICO', $this->types($s->telemetry()));
        $s->setFreeMb(-1); // sensor ausente
        $this->assertNotContains('DISCO_BAJO', $this->types($s->telemetry()));
        $this->assertNotContains('DISCO_CRITICO', $this->types($s->telemetry()));
    }

    public function testClockDriftAndDlq(): void {
        $s = new StorageSimulator();
        $s->setClockDrift(400);
        $this->assertContains('RELOJ_DESVIADO', $this->types($s->telemetry()));
        $s->setClockDrift(10);
        $s->syncFail(30);
        $this->assertContains('DLQ_BACKLOG', $this->types($s->telemetry()));
    }

    public function testUpsPowerStates(): void {
        $ups = new UpsSimulator();
        $this->assertSame([], $this->types($ups->telemetry())); // MAINS

        $ups->powerCut();
        $this->assertContains('ENERGIA_RESPALDO', $this->types($ups->telemetry())); // BATTERY

        $ups->drain(80); // ~20%
        $this->assertContains('ENERGIA_RESPALDO', $this->types($ups->telemetry())); // LOW_BATTERY (HIGH sev)

        $ups->drain(20); // ~0% → CRITICAL
        $this->assertContains('ENERGIA_CRITICA', $this->types($ups->telemetry()));
        $this->assertTrue($ups->shouldShutdown());

        $ups->powerRestore(); $ups->charge(50);
        $this->assertSame([], $this->types($ups->telemetry())); // restauración limpia
    }

    public function testCellularStates(): void {
        $cell = CellularSimulator::weakSignal();
        $this->assertContains('SENAL_BAJA', $this->types($cell->telemetry()));

        $dead = CellularSimulator::deadInterface();
        $this->assertContains('SENAL_PERDIDA', $this->types($dead->telemetry()));

        $ok = CellularSimulator::healthy();
        $this->assertSame([], $this->types($ok->telemetry()));
    }

    public function testAbsentSensorsNeverAlert(): void {
        // Sin datos de sensor (edge viejo sin telemetría extendida): cero falsos positivos
        $this->assertSame([], $this->types([]));
        $this->assertSame([], $this->types(['cpu_temp_c' => null, 'disk_free_mb' => -1, 'power_state' => 'UNKNOWN']));
    }

    // ── Persistencia + dedup + notificación (ctProcessTelemetry con FakePDO) ──

    private function pdoFor(array $dupExists = []): FakePDO {
        $pdo = new FakePDO();
        $pdo->script[] = ['match' => 'UPDATE edge_devices SET telemetry_json', 'stmt' => FakeStmt::make([], 1)];
        $pdo->script[] = ['match' => 'SELECT 1 FROM security_incidents', 'stmt' => FakeStmt::make($dupExists)];
        $pdo->script[] = ['match' => 'INSERT INTO security_incidents', 'stmt' => FakeStmt::make([], 1)];
        $pdo->script[] = ['match' => 'FROM users', 'stmt' => FakeStmt::make([['user_id' => 'coord-1']])];
        $pdo->script[] = ['match' => 'INSERT INTO notifications', 'stmt' => FakeStmt::make([], 1)];
        return $pdo;
    }

    public function testProcessTelemetryPersistsAndCreatesIncident(): void {
        $pdo = $this->pdoFor();
        $t = (new ThermalSimulator())->telemetry(['disk_free_mb' => 100]);
        $th = new ThermalSimulator(); $th->setTemp(95);
        $t = array_merge($t, $th->telemetry());
        $created = ctProcessTelemetry($pdo, 'school-1', 'dev-1', $t);
        $this->assertContains('TEMP_CRITICA', $created);
        $this->assertContains('DISCO_CRITICO', $created);
        $updates = array_filter($pdo->executed, fn($e) => stripos($e['sql'], 'telemetry_json') !== false);
        $this->assertCount(1, $updates, 'La telemetría debe persistirse en edge_devices.telemetry_json');
    }

    public function testProcessTelemetryDedupSkipsRepeatSameDay(): void {
        $pdo = $this->pdoFor([['1' => 1]]); // dedup siempre devuelve fila
        $th = new ThermalSimulator(); $th->setTemp(95);
        $created = ctProcessTelemetry($pdo, 'school-1', 'dev-1', $th->telemetry());
        $this->assertSame([], $created, 'Incidente duplicado el mismo día no debe recrearse');
    }

    public function testHighSeverityNotifiesCoordinators(): void {
        $pdo = $this->pdoFor();
        $ups = new UpsSimulator(); $ups->powerCut(); $ups->drain(95);
        $created = ctProcessTelemetry($pdo, 'school-1', 'dev-1', $ups->telemetry());
        $this->assertContains('ENERGIA_CRITICA', $created);
        $notifs = array_filter($pdo->executed, fn($e) => stripos($e['sql'], 'INSERT INTO notifications') !== false);
        $this->assertNotEmpty($notifs, 'Severidad HIGH debe notificar a coordinación');
    }

    public function testPingEndpointPassesTelemetry(): void {
        $src = file_get_contents(__DIR__ . '/../../backend/api/routes/devices.php');
        $this->assertStringContainsString('$input[\'telemetry\']', $src);
        $this->assertStringContainsString('ctProcessTelemetry', $src);
        $this->assertMatchesRegularExpression('/catch \(Exception \$e\).*DEVICE_TELEMETRY_ERROR/s', $src,
            'La telemetría no debe tumbar el heartbeat');
    }

    public function testSchemaHasTelemetryColumns(): void {
        $schema = file_get_contents(__DIR__ . '/../../sql/schema.sql');
        $this->assertStringContainsString('telemetry_json', $schema);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS telemetry_json', $schema);
    }

    public function testEdgeReportsTelemetryContract(): void {
        $src = file_get_contents(__DIR__ . '/../../backend/edge/src/main.cpp');
        foreach (['clock_drift_s', 'disk_free_mb', 'pending_events', 'dlq_count', 'power_state'] as $k) {
            $this->assertStringContainsString($k, $src, "Edge debe reportar $k en el ping");
        }
        $this->assertStringContainsString('node_monitor.h', $src);
        // F-13: DLQ retry + F-09 power transitions en main loop
        $this->assertStringContainsString('requeueDlqItems', $src);
        $this->assertStringContainsString('POWER_BACKUP', $src);
        $this->assertStringContainsString('POWER_RESTORED', $src);
        $this->assertStringContainsString('shouldShutdown', $src);
    }
}
