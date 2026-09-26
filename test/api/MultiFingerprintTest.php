<?php
/**
 * =============================================================================
 * MultiFingerprintTest.php — Validación F-03 (dos huellas por estudiante).
 * =============================================================================
 *
 * Bidireccional: (a) enrolar cualquiera de los 2 dedos → ambos identifican al
 * mismo estudiante; (b) revocar → ninguno identifica.
 * Multidimensional: dedo 1, dedo 2, dedo desconocido, slot duplicado
 * (re-enrol), slot inválido, revocación parcial/total, cola offline→sync.
 *
 * Simulador: test/simulaciones/biometria/FingerprintSimulator.php (en memoria).
 * Edge C++: backend/edge/tests/test_multi_finger.cpp cubre la persistencia
 * real (nexo-tests, tag [multifinger]).
 * =============================================================================
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';
require_once __DIR__ . '/../simulaciones/biometria/FingerprintSimulator.php';

class MultiFingerprintTest extends PHPUnit\Framework\TestCase
{
    private FingerprintSimulator $sim;
    private string $schemaSrc;
    private string $devicesSrc;
    private string $workerSrc;
    private string $mainSrc;

    protected function setUp(): void {
        $this->sim = new FingerprintSimulator();
        $root = __DIR__ . '/../../';
        $this->schemaSrc  = file_get_contents($root . 'sql/schema.sql');
        $this->devicesSrc = file_get_contents($root . 'backend/api/routes/devices.php');
        $this->workerSrc  = file_get_contents($root . 'backend/api/workers/worker_biometric.php');
        $this->mainSrc    = file_get_contents($root . 'backend/edge/src/main.cpp');
    }

    // ── Bidireccional A: cualquier dedo identifica al mismo estudiante ──

    public function testEitherFingerIdentifiesSameStudent(): void {
        $r1 = $this->sim->enroll('DOC1', 1);
        $r2 = $this->sim->enroll('DOC1', 2);
        $this->assertTrue($r1['ok']);
        $this->assertTrue($r2['ok']);
        $this->assertNotSame($r1['huella_id'], $r2['huella_id'], 'Cada dedo = huella_id distinto');

        $m1 = $this->sim->identify($r1['huella_id']);
        $m2 = $this->sim->identify($r2['huella_id']);
        $this->assertTrue($m1['matched']);
        $this->assertTrue($m2['matched']);
        $this->assertSame('DOC1', $m1['doc']);
        $this->assertSame('DOC1', $m2['doc']);
        $this->assertSame(1, $m1['slot']);
        $this->assertSame(2, $m2['slot']);
    }

    // ── Bidireccional B: revocación elimina todas las identificaciones ──

    public function testRevocationPurgesAllFingers(): void {
        $r1 = $this->sim->enroll('DOC1', 1);
        $r2 = $this->sim->enroll('DOC1', 2);
        $this->assertTrue($this->sim->revoke('DOC1'));
        $this->assertFalse($this->sim->identify($r1['huella_id'])['matched']);
        $this->assertFalse($this->sim->identify($r2['huella_id'])['matched']);
        $this->assertSame(0, $this->sim->fingerCount('DOC1'));
    }

    // ── Multidimensional ──

    public function testUnknownFingerDoesNotMatch(): void {
        $this->assertFalse($this->sim->identify(9999)['matched']);
    }

    public function testDuplicateSlotReplacesOldHuella(): void {
        $r1 = $this->sim->enroll('DOC1', 1);
        $r2 = $this->sim->enroll('DOC1', 1); // re-enrol mismo dedo
        $this->assertFalse($this->sim->identify($r1['huella_id'])['matched'],
            'El huella_id anterior del slot debe quedar invalidado');
        $this->assertTrue($this->sim->identify($r2['huella_id'])['matched']);
        $this->assertSame(1, $this->sim->fingerCount('DOC1'));
    }

    public function testInvalidSlotRejected(): void {
        $this->assertFalse($this->sim->enroll('DOC1', 0)['ok']);
        $this->assertFalse($this->sim->enroll('DOC1', 3)['ok']);
    }

    public function testOfflineEnrollmentQueuesSync(): void {
        $this->sim->setCentralOnline(false);
        $r = $this->sim->enroll('DOC1', 1);
        $this->assertTrue($r['ok'], 'El enrolamiento local funciona sin central');
        $this->assertSame(1, $this->sim->pendingSyncCount(), 'El enrolamiento queda en cola offline');
        $this->assertSame(1, $this->sim->flushSyncQueue(), 'Al reconectar, la cola se sincroniza');
        $this->assertSame(0, $this->sim->pendingSyncCount());
    }

    public function testOfflineRevocationQueued(): void {
        $r = $this->sim->enroll('DOC1', 1);
        $this->sim->setCentralOnline(false);
        $this->assertTrue($this->sim->revoke('DOC1'));
        $this->assertSame(1, $this->sim->pendingSyncCount());
    }

    // ── Contratos estáticos: schema, endpoints, edge ──

    public function testSchemaHasStudentFingerprints(): void {
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS student_fingerprints', $this->schemaSrc);
        $this->assertStringContainsString('finger_slot', $this->schemaSrc);
        $this->assertStringContainsString("CHECK(finger_slot IN (1,2))", $this->schemaSrc);
        $this->assertStringContainsString('UNIQUE (student_id, finger_slot)', $this->schemaSrc);
    }

    public function testEnrollConfirmAcceptsFingerSlot(): void {
        $this->assertStringContainsString("finger_slot", $this->devicesSrc);
        $this->assertStringContainsString('student_fingerprints', $this->devicesSrc);
    }

    public function testWorkerUpsertsFingerprintSlot(): void {
        $this->assertStringContainsString('student_fingerprints', $this->workerSrc);
        $this->assertStringContainsString('finger_slot', $this->workerSrc);
    }

    public function testEdgeEnrollRequestHandlesFingerSlot(): void {
        $this->assertStringContainsString('finger_slot', $this->mainSrc);
        $this->assertStringContainsString('estudiante_huellas', file_get_contents(__DIR__ . '/../../backend/edge/src/base_de_datos/sqlite_manager.cpp'));
    }
}
