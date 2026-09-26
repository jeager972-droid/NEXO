<?php
/**
 * =============================================================================
 * OtaUpdateTest — validación bidireccional del OTA M2M (Bloque D).
 * =============================================================================
 * Verifica: firma/verificación del manifiesto (HMAC por dispositivo),
 * comparación semver, anti-rollback y el ciclo de estados del simulador.
 * =============================================================================
 */

require_once __DIR__ . '/../../backend/api/lib/ota.php';
require_once __DIR__ . '/../simulaciones/ota/OtaNodeSimulator.php';

use PHPUnit\Framework\TestCase;

class OtaUpdateTest extends TestCase
{
    public function testVersionCompare(): void {
        $this->assertSame(-1, otaVersionCompare('1.0.0', '1.0.1'));
        $this->assertSame(1,  otaVersionCompare('2.0.0', '1.9.9'));
        $this->assertSame(0,  otaVersionCompare('1.2.3', '1.2.3'));
        $this->assertSame(-1, otaVersionCompare('1.2', '1.2.1'));
        $this->assertSame(1,  otaVersionCompare('1.2.1', '1.2'));
    }

    public function testManifestSignVerify(): void {
        $key = bin2hex(random_bytes(32));
        $sig = otaSignManifest('2.1.0', str_repeat('ab', 32), 'https://x/f.bin', $key);
        $m = ['version' => '2.1.0', 'sha256' => str_repeat('ab', 32), 'url' => 'https://x/f.bin', 'signature' => $sig];
        $this->assertTrue(otaVerifyManifest($m, $key));
        // Manipulación detectada
        $m['url'] = 'https://evil/f.bin';
        $this->assertFalse(otaVerifyManifest($m, $key));
        // Clave de otro dispositivo no sirve
        $m['url'] = 'https://x/f.bin';
        $this->assertFalse(otaVerifyManifest($m, bin2hex(random_bytes(32))));
        // Sin firma
        unset($m['signature']);
        $this->assertFalse(otaVerifyManifest($m, $key));
    }

    public function testSimulatorPowerLossResume(): void {
        $sim = new OtaNodeSimulator('http://localhost:0', 'dev', 'tok', 'k');
        $payload = str_repeat('firmware-bytes-', 100);
        $sim->setExpectedPayload($payload);
        $sim->powerCutMidDownload();
        $part = sys_get_temp_dir() . '/ota_sim_' . getmypid() . '/payload.part';
        $this->assertFileExists($part);
        $this->assertLessThan(strlen($payload), filesize($part));
        $sim->powerRestore();
        // Reanudación en tick downloading → el .part se completa
        $ref = new ReflectionMethod($sim, 'simulateDownload');
        $ref->invoke($sim);
        $this->assertSame($payload, file_get_contents($part));
    }

    public function testSimulatorRollbackResetsState(): void {
        $sim = new OtaNodeSimulator('http://localhost:0', 'dev', 'tok', 'k');
        $sim->rollback();
        $this->assertSame('idle', $sim->getState());
    }
}
