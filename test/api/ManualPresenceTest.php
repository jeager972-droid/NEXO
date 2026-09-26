<?php
/**
 * =============================================================================
 * ManualPresenceTest.php — Validación F-02 (presencia manual + exención).
 * =============================================================================
 *
 * Bidireccional: (a) el registro manual cuenta como presencia real en todas
 * las consultas de presencia; (b) la exención biométrica suprime incidentes
 * automáticos sin dejar al estudiante invisible.
 * Multidimensional: escenarios normal / manual / exento / exento+manual /
 * duplicado, más los contratos de schema, permiso y detectores.
 *
 * Corre sin BD real: FakePDO scriptado + aserciones estáticas de contrato.
 * =============================================================================
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

if (!class_exists('PDO')) {
    class PDO {
        const FETCH_ASSOC = 2;
        const FETCH_COLUMN = 7;
        const FETCH_OBJ = 5;
    }
}

require_once __DIR__ . '/../../backend/api/workers/contingency_lib.php';
require_once __DIR__ . '/../simulaciones/biometria/ManualPresenceSimulator.php';
require_once __DIR__ . '/NodeHealthGateTest.php'; // FakePDO / FakeStmt

class ManualPresenceTest extends PHPUnit\Framework\TestCase
{
    private string $opsSrc;
    private string $schemaSrc;
    private string $absSrc;
    private string $evaSrc;
    private string $stuSrc;

    protected function setUp(): void {
        $root = __DIR__ . '/../../';
        $this->opsSrc    = file_get_contents($root . 'backend/api/routes/operations.php');
        $this->schemaSrc = file_get_contents($root . 'sql/schema.sql');
        $this->absSrc    = file_get_contents($root . 'backend/api/workers/worker_absence_detector.php');
        $this->evaSrc    = file_get_contents($root . 'backend/api/workers/worker_evasion_detector.php');
        $this->stuSrc    = file_get_contents($root . 'backend/api/routes/students.php');
    }

    // ── Bidireccional A: INGRESO_MANUAL cuenta como presencia ──

    public function testManualEventCountsAsPresence(): void {
        // Contrato: presence = event_type LIKE 'INGRESO_%' en
        // is_student_present_today, absence detector, registro_manual dedup.
        $this->assertTrue(ManualPresenceSimulator::countsAsPresence('INGRESO_MANUAL'));
        $this->assertTrue(ManualPresenceSimulator::countsAsPresence('INGRESO_MAÑANA'));
        $this->assertFalse(ManualPresenceSimulator::countsAsPresence('SALIDA_AUTORIZADA'));
        $this->assertFalse(ManualPresenceSimulator::countsAsPresence('EVASION'));
    }

    public function testManualPresenceInsertWritesEventAndIncident(): void {
        $pdo = new FakePDO();
        $pdo->script[] = ['match' => 'INSERT INTO biometric_events', 'stmt' => FakeStmt::make([], 1)];
        $pdo->script[] = ['match' => 'INSERT INTO attendance_incidents', 'stmt' => FakeStmt::make([], 1)];

        ctRegisterManualPresence($pdo, 'school-1', 'stu-1', 'dev-1', '{"source":"manual"}');

        $evInserts = array_filter($pdo->executed, fn($e) => stripos($e['sql'], 'biometric_events') !== false);
        $incInserts = array_filter($pdo->executed, fn($e) => stripos($e['sql'], 'attendance_incidents') !== false);
        $this->assertCount(1, $evInserts, 'Debe insertar el evento biométrico INGRESO_MANUAL');
        $this->assertCount(1, $incInserts, 'Debe insertar el incidente REGISTRO_MANUAL');
        $evSql = array_values($evInserts)[0]['sql'];
        $this->assertStringContainsString('INGRESO_MANUAL', $evSql);
        $incSql = array_values($incInserts)[0]['sql'];
        $this->assertStringContainsString('REGISTRO_MANUAL', $incSql);
    }

    // ── Bidireccional B: exención suprime incidentes automáticos ──

    public function testExemptStudentSkippedByAbsenceDetector(): void {
        $sc = ManualPresenceSimulator::scenarioExemptNoEvent();
        // Regla: biometric_exempt=TRUE → la ausencia de huella no genera incidente.
        $this->assertTrue($sc['student']['biometric_exempt']);
        $this->assertFalse($sc['expect']['absence_incident']);
        // Contrato en el detector: selecciona biometric_exempt y hace skip
        $this->assertStringContainsString('biometric_exempt', $this->absSrc);
        $this->assertMatchesRegularExpression(
            "/biometric_exempt'\)\)\s*continue|biometric_exempt.*continue/",
            $this->absSrc,
            'worker_absence_detector debe omitir estudiantes exentos'
        );
    }

    public function testExemptStudentSkippedByEvasionDetector(): void {
        $this->assertMatchesRegularExpression(
            "/biometric_exempt.*EVASION_EXEMPT|EVASION_EXEMPT/",
            $this->evaSrc,
            'worker_evasion_detector debe omitir estudiantes exentos de biometría'
        );
    }

    // ── Multidimensional: escenarios del simulador ──

    public function testNormalBiometricStillWorks(): void {
        $sc = ManualPresenceSimulator::scenarioNormal();
        $present = array_reduce($sc['events'],
            fn($p, $e) => $p || ManualPresenceSimulator::countsAsPresence($e['event_type']), false);
        $this->assertTrue($present);
    }

    public function testManualFallbackYieldsPresence(): void {
        $sc = ManualPresenceSimulator::scenarioManualFallback();
        $present = array_reduce($sc['events'],
            fn($p, $e) => $p || ManualPresenceSimulator::countsAsPresence($e['event_type']), false);
        $this->assertTrue($present, 'Un evento manual debe contar como presencia');
    }

    public function testExemptWithManualEventIsPresent(): void {
        $sc = ManualPresenceSimulator::scenarioExemptWithManual();
        $present = array_reduce($sc['events'],
            fn($p, $e) => $p || ManualPresenceSimulator::countsAsPresence($e['event_type']), false);
        $this->assertTrue($present);
        $this->assertFalse($sc['expect']['absence_incident']);
    }

    // ── Contratos estáticos: endpoint, permiso, schema ──

    public function testRegistroManualEndpointContract(): void {
        $this->assertStringContainsString("'/operations/registro_manual' => 'registro_manual'", $this->opsSrc);
        $this->assertStringContainsString("case 'registro_manual'", $this->opsSrc);
        $this->assertStringContainsString('El motivo del registro manual es obligatorio', $this->opsSrc,
            'El motivo debe ser obligatorio');
        $this->assertStringContainsString('ya tiene un ingreso registrado hoy', $this->opsSrc,
            'Debe bloquear doble registro el mismo día (conflicto)');
        $this->assertStringContainsString('assigned_user_id', $this->opsSrc,
            'Debe resolver el nodo asignado al actor');
        $this->assertStringContainsString('ctRegisterManualPresence', $this->opsSrc);
    }

    public function testSchemaHasExemptionAndPermission(): void {
        $this->assertStringContainsString('biometric_exempt BOOLEAN NOT NULL DEFAULT FALSE', $this->schemaSrc);
        $this->assertStringContainsString('exemption_reason TEXT', $this->schemaSrc);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS biometric_exempt', $this->schemaSrc,
            'Migración idempotente requerida para bases existentes');
        $this->assertStringContainsString("'operations.registro_manual'", $this->schemaSrc);
        foreach (['RECTOR', 'COORDINATOR', 'TEACHER', 'SECRETARY', 'SECURITY'] as $role) {
            $this->assertStringContainsString(
                "assign_permission_to_role('$role', 'operations.registro_manual')",
                $this->schemaSrc,
                "Permiso registro_manual debe otorgarse a $role"
            );
        }
    }

    public function testStudentsApiAcceptsAndReturnsExemption(): void {
        $this->assertStringContainsString('biometric_exempt', $this->stuSrc);
        $this->assertStringContainsString('exemption_reason', $this->stuSrc);
        $this->assertStringContainsString('La exención biométrica requiere un motivo', $this->stuSrc,
            'El backend debe exigir motivo cuando biometric_exempt=TRUE');
    }
}
