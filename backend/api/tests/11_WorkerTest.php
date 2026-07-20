<?php
/**
 * 11_WorkerTest.php
 * Verifica workers (biométrico, Twilio, etc.).
 */
require_once __DIR__ . '/../vendor/autoload.php';

class WorkerTest extends PHPUnit\Framework\TestCase
{
    private array $workers = [];

    public function setUp(): void
    {
        $workersDir = __DIR__ . '/../workers';
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
