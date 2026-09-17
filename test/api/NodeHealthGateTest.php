<?php
/**
 * =============================================================================
 * NodeHealthGateTest.php — Validación bidireccional/multidimensional F-04/F-05.
 * =============================================================================
 *
 * Cubre la regla transversal para elementos con dependencia física: la lógica
 * de salud de nodos se valida en AMBOS sentidos del flujo (caída→supresión→
 * incidente | recuperación→resolución→restauración) y en todos los ejes que el
 * ítem exige (umbral temporal, ping nulo, cobertura múltiple, dispositivos
 * inactivos/sin asignar, flapping, cluster de anomalía, dedup de marcadores).
 *
 * Corre contra simulaciones/nodo/NodeSimulator.php — sin hardware ni BD real
 * (las helpers BD se ejercitan con un FakePDO scriptado).
 * =============================================================================
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

// Shim de constantes PDO para CLI sin extensión pdo (los dobles de prueba
// no ejecutan SQL real; solo necesitan que PDO::FETCH_* resuelva).
if (!class_exists('PDO')) {
    class PDO {
        const FETCH_ASSOC = 2;
        const FETCH_COLUMN = 7;
        const FETCH_OBJ = 5;
    }
}

require_once __DIR__ . '/../../backend/api/workers/contingency_lib.php';
require_once __DIR__ . '/../../simulaciones/nodo/NodeSimulator.php';

// ── Dobles de prueba mínimos (duck-typed: solo ->prepare()) ─────────────
// contingency_lib relaja el type-hint PDO precisamente para que estos dobles
// funcionen sin la extensión pdo cargada en el CLI.

class FakeStmt {
    /** @var array */ private $rows;
    /** @var array */ public $bound = [];
    /** @var int */ private $rowCount;
    public function __construct(array $rows = [], int $rowCount = 0) {
        $this->rows = $rows;
        $this->rowCount = $rowCount;
    }
    public static function make(array $rows = [], int $rowCount = 0): self {
        return new self($rows, $rowCount);
    }
    public function execute($params = null): bool {
        $this->bound = $params ?? [];
        return true;
    }
    public function fetchAll($mode = null, ...$args): array {
        if (defined('PDO::FETCH_COLUMN') && $mode === PDO::FETCH_COLUMN) {
            return array_map(fn($r) => is_array($r) ? (array_values($r)[0] ?? null) : $r, $this->rows);
        }
        return $this->rows;
    }
    public function fetchColumn($col = 0): mixed {
        if (empty($this->rows)) return false;
        $first = reset($this->rows);
        return is_array($first) ? (array_values($first)[$col] ?? false) : $first;
    }
    public function rowCount(): int { return $this->rowCount; }
}

class FakePDO {
    /** @var array<array{match:string, stmt:FakeStmt}> */ public array $script = [];
    /** @var array<array{sql:string, params:array}> */ public array $executed = [];
    public function prepare($query, $options = []) {
        foreach ($this->script as &$entry) {
            if (stripos($query, $entry['match']) !== false) {
                $inner = $entry['stmt'];
                return new class($inner, $query, $this) {
                    private $inner; private $sql; private $pdo;
                    public function __construct($inner, $sql, $pdo) { $this->inner = $inner; $this->sql = $sql; $this->pdo = $pdo; }
                    public function execute($params = null): bool {
                        $this->pdo->executed[] = ['sql' => $this->sql, 'params' => $params ?? []];
                        return $this->inner->execute($params);
                    }
                    public function fetchAll($mode = null, ...$a): array { return $this->inner->fetchAll($mode, ...$a); }
                    public function fetchColumn($c = 0): mixed { return $this->inner->fetchColumn($c); }
                    public function rowCount(): int { return $this->inner->rowCount(); }
                };
            }
        }
        return FakeStmt::make();
    }
}

// ─────────────────────────────── Tests ───────────────────────────────

class NodeHealthGateTest extends PHPUnit\Framework\TestCase
{
    private const OFFLINE_SEC = 600; // 10 min
    private int $now;

    protected function setUp(): void {
        $this->now = 1_800_000_000;
    }

    // ── BIDIRECCIONAL — bajada: nodo cae → grupo suprimido + transición ──

    public function testNodeDownSuppressesGroupAndEmitsTransition(): void {
        $sc = NodeSimulator::scenarioSingleNodeOutage($this->now, self::OFFLINE_SEC);
        $offline = ctOfflineGroupIds($sc['devices'], $this->now, self::OFFLINE_SEC);
        $this->assertArrayHasKey('grp-1', $offline, 'Grupo servido solo por nodo caído debe quedar gated');

        $trans = ctNodeTransitions($sc['devices'], $sc['open_incidents'], $this->now, self::OFFLINE_SEC);
        $this->assertSame(['dev-1'], array_column($trans['went_offline'], 'device_id'));
        $this->assertSame([], $trans['came_online']);
    }

    // ── BIDIRECCIONAL — subida: nodo vuelve → grupo restaurado + resolución ──

    public function testNodeRecoveryResolvesIncidentAndRestoresGroup(): void {
        $sc = NodeSimulator::scenarioRecovery($this->now, self::OFFLINE_SEC);
        $offline = ctOfflineGroupIds($sc['devices'], $this->now, self::OFFLINE_SEC);
        $this->assertSame([], $offline, 'Grupo con nodo recuperado no debe quedar gated');

        $trans = ctNodeTransitions($sc['devices'], $sc['open_incidents'], $this->now, self::OFFLINE_SEC);
        $this->assertSame([], $trans['went_offline']);
        $this->assertSame(['dev-1'], array_column($trans['came_online'], 'device_id'),
            'Nodo recuperado con incidente abierto debe emitir transición de recuperación');
    }

    // ── MULTIDIMENSIONAL — flapping: secuencia cae→persiste→vuelve→recae ──

    public function testFlappingNodeProducesCorrectTransitionSequence(): void {
        $sc = NodeSimulator::scenarioFlapping($this->now, self::OFFLINE_SEC);
        foreach ($sc['ticks'] as $i => $tick) {
            $trans = ctNodeTransitions($tick['devices'], $tick['open_incidents'], $this->now, self::OFFLINE_SEC);
            $this->assertSame($tick['expect']['went_offline'], array_column($trans['went_offline'], 'device_id'), "tick $i went_offline");
            $this->assertSame($tick['expect']['came_online'], array_column($trans['came_online'], 'device_id'), "tick $i came_online");
        }
    }

    // ── MULTIDIMENSIONAL — umbrales y estados límite ──

    public function testOfflineThresholdBoundary(): void {
        // ping exactamente en el umbral → ONLINE (<=), un segundo más → OFFLINE
        $atLimit = [NodeSimulator::device('d', 'g1', $this->now - self::OFFLINE_SEC)];
        $pastLimit = [NodeSimulator::device('d', 'g1', $this->now - self::OFFLINE_SEC - 1)];
        $this->assertSame([], ctOfflineGroupIds($atLimit, $this->now, self::OFFLINE_SEC));
        $this->assertArrayHasKey('g1', ctOfflineGroupIds($pastLimit, $this->now, self::OFFLINE_SEC));
    }

    public function testNeverReportedNodeCountsAsOffline(): void {
        $sc = NodeSimulator::scenarioUnmappedDevices($this->now, self::OFFLINE_SEC);
        // dispositivos sin grupo no gatean nada pero sí transicionan a offline
        $this->assertSame([], ctOfflineGroupIds($sc['devices'], $this->now, self::OFFLINE_SEC));
        $trans = ctNodeTransitions($sc['devices'], $sc['open_incidents'], $this->now, self::OFFLINE_SEC);
        $this->assertCount(2, $trans['went_offline'], 'Dispositivo que nunca reportó también es NODO_OFFLINE');
    }

    public function testPartialCoverageKeepsGroupAlive(): void {
        $sc = NodeSimulator::scenarioPartialCoverage($this->now, self::OFFLINE_SEC);
        $this->assertSame([], ctOfflineGroupIds($sc['devices'], $this->now, self::OFFLINE_SEC),
            'Grupo con un nodo vivo no se suprime aunque otro esté caído');
    }

    public function testInactiveDeviceIgnoredForCoverageAndIncidents(): void {
        $sc = NodeSimulator::scenarioInactiveDevice($this->now, self::OFFLINE_SEC);
        $this->assertSame([], ctOfflineGroupIds($sc['devices'], $this->now, self::OFFLINE_SEC));
        $trans = ctNodeTransitions($sc['devices'], $sc['open_incidents'], $this->now, self::OFFLINE_SEC);
        $this->assertSame([], $trans['went_offline'], 'Dispositivo inactivo no genera incidente ni cobertura');
    }

    public function testMixedSchoolOnlyGatesFullyOfflineGroups(): void {
        $sc = NodeSimulator::scenarioMixedSchool($this->now, self::OFFLINE_SEC);
        $offline = ctOfflineGroupIds($sc['devices'], $this->now, self::OFFLINE_SEC);
        $this->assertSame(['grp-down' => true], $offline,
            'Solo el grupo con todos sus nodos caídos queda gated; grp-up y grp-none siguen normal');
    }

    // ── MULTIDIMENSIONAL — helpers BD con FakePDO (dedup, marcado, fetch) ──

    public function testMarkNoNodeDataDeduplicatesPerGroup(): void {
        $pdo = new FakePDO();
        // 1ª llamada: no hay SIN_DATOS_NODO abierto → inserta
        $pdo->script[] = ['match' => 'SELECT 1 FROM security_incidents', 'stmt' => FakeStmt::make([])];
        $pdo->script[] = ['match' => 'INSERT INTO security_incidents', 'stmt' => FakeStmt::make([], 1)];
        $created = ctMarkNoNodeData($pdo, 'school-1', 'grp-1', 'Grupo 1', 'absence_gate');
        $this->assertTrue($created);

        // 2ª llamada: ya hay uno abierto → no duplica
        $pdo2 = new FakePDO();
        $pdo2->script[] = ['match' => 'SELECT 1 FROM security_incidents', 'stmt' => FakeStmt::make([['?column?' => 1]])];
        $created2 = ctMarkNoNodeData($pdo2, 'school-1', 'grp-1', 'Grupo 1', 'absence_gate');
        $this->assertFalse($created2, 'SIN_DATOS_NODO no debe duplicarse por grupo/día');
        $insertCount = count(array_filter($pdo2->executed, fn($e) => stripos($e['sql'], 'INSERT INTO security_incidents') !== false));
        $this->assertSame(0, $insertCount);
    }

    public function testFetchDeviceRowsNormalizesShape(): void {
        $pdo = new FakePDO();
        $pdo->script[] = ['match' => 'FROM edge_devices', 'stmt' => FakeStmt::make([
            ['device_id' => 'd1', 'device_name' => 'Nodo A', 'group_id' => 'g1', 'group_name' => '6A',
             'active' => true, 'last_ping' => '2027-01-15 07:00:00+00', 'location' => 'Aula'],
            ['device_id' => 'd2', 'device_name' => 'Nodo B', 'group_id' => null, 'group_name' => null,
             'active' => true, 'last_ping' => null, 'location' => null],
        ])];
        $rows = ctFetchDeviceRows($pdo, 'school-1');
        $this->assertCount(2, $rows);
        $this->assertSame(strtotime('2027-01-15 07:00:00+00'), $rows[0]['last_ping_ts']);
        $this->assertNull($rows[1]['last_ping_ts']);
        $this->assertSame('d1', $rows[0]['device_id']);
    }

    // ── F-05 — cluster de anomalía (multidimensional) ──

    public function testAnomalyClusterThresholds(): void {
        // min=5, frac=0.5
        $this->assertTrue(ctIsAnomalyCluster(5, 10, 5, 0.5),  'exactamente en umbral → anomalía');
        $this->assertTrue(ctIsAnomalyCluster(8, 10, 5, 0.5),  'sobre umbral → anomalía');
        $this->assertFalse(ctIsAnomalyCluster(4, 10, 5, 0.5), 'bajo mínimo absoluto → normal');
        $this->assertFalse(ctIsAnomalyCluster(5, 20, 5, 0.5), 'mínimo sí pero fracción no → normal');
        $this->assertFalse(ctIsAnomalyCluster(0, 0, 5, 0.5),  'grupo vacío → nunca anomalía');
        $this->assertFalse(ctIsAnomalyCluster(10, 0, 5, 0.5), 'groupSize 0 → div/0 protegido');
    }
}
