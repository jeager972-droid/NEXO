<?php
/**
 * =============================================================================
 * WorkerTest.php — Test de workers de background.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Examina archivos de backend/api/workers/ para verificar:
 *   - Existe al menos un worker biométrico, uno de Twilio/WhatsApp.
 *   - worker_biometric maneja guardianes y tabla guardians.
 *   - Todos los workers usan prepared statements para acceso a BD.
 *
 * NOTA: test estático; no ejecuta los workers.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class WorkerTest extends PHPUnit\Framework\TestCase
{
    private array $workers = [];

    public function setUp(): void
    {
        $workersDir = __DIR__ . '/../../backend/api/workers';
        foreach (glob($workersDir . '/*.php') as $file) {
            $this->workers[basename($file)] = file_get_contents($file);
        }
    }

    public function testWorkersExist(): void
    {
        $this->assertNotEmpty($this->workers, 'Debe haber al menos un worker');
    }

    public function testBiometricWorkerHandlesGuardian(): void
    {
        $found = false;
        foreach ($this->workers as $name => $content) {
            if (stripos($name, 'biometric') !== false) {
                $found = true;
                $this->assertStringContainsStringIgnoringCase('GUARDIAN', $content,
                    'Worker biométrico debe manejar rol GUARDIAN');
                $this->assertStringContainsStringIgnoringCase('guardian', $content,
                    'Worker biométrico debe interactuar con tabla guardians');
            }
        }
        $this->assertTrue($found, 'Debe existir worker biométrico');
    }

    public function testWorkersUsePreparedStatements(): void
    {
        foreach ($this->workers as $name => $content) {
            $this->assertMatchesRegularExpression('/prepare\s*\(/i', $content,
                "Worker '$name' debe usar prepared statements");
        }
    }

    public function testTwilioWorkerExists(): void
    {
        $found = false;
        foreach ($this->workers as $name => $content) {
            if (stripos($name, 'twilio') !== false || stripos($name, 'sms') !== false || stripos($name, 'whatsapp') !== false) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Debe existir worker de mensajería (Twilio/WhatsApp)');
    }
}
