<?php
/**
 * =============================================================================
 * 16_TwilioWebhookAndPollingWorkerTest.php
 * =============================================================================
 * RESPONSABILIDAD:
 *   VF-028: Tests estáticos del webhook de Twilio (twilio_delivery.php).
 *   VF-029: Tests estáticos de workers polling (absence, evasion, permission).
 *
 * NOTA: test estático; no ejecuta los workers ni el webhook. Analiza el
 * código fuente para verificar patrones correctos.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class TwilioWebhookAndPollingWorkerTest extends PHPUnit\Framework\TestCase
{
    private string $webhookCode;
    private array $pollingWorkers = [];

    public function setUp(): void
    {
        $this->webhookCode = file_get_contents(__DIR__ . '/../../backend/api/routes/twilio_delivery.php');

        $workersDir = __DIR__ . '/../../backend/api/workers';
        $pollingWorkerNames = ['worker_absence_detector.php', 'worker_evasion_detector.php', 'worker_permission_status.php'];
        foreach ($pollingWorkerNames as $name) {
            $path = $workersDir . '/' . $name;
            if (file_exists($path)) {
                $this->pollingWorkers[$name] = file_get_contents($path);
            }
        }
    }

    // =========================================================================
    // VF-028: Webhook Twilio
    // =========================================================================

    public function testWebhookFileExists(): void
    {
        $this->assertNotEmpty($this->webhookCode, 'twilio_delivery.php debe existir');
    }

    public function testWebhookHasGetHealthCheck(): void
    {
        $this->assertStringContainsString("/webhooks/twilio/status", $this->webhookCode,
            'Webhook debe tener endpoint GET /webhooks/twilio/status para health check');
        $this->assertStringContainsString("'ok'", $this->webhookCode,
            'Health check debe retornar status ok');
    }

    public function testWebhookHasPostHandler(): void
    {
        $this->assertStringContainsString("'POST'", $this->webhookCode,
            'Webhook debe manejar POST para recibir callbacks de Twilio');
    }

    public function testWebhookValidatesSignature(): void
    {
        $this->assertStringContainsString('verifyTwilioSignature', $this->webhookCode,
            'Webhook debe validar firma de Twilio');
        $this->assertStringContainsString('403', $this->webhookCode,
            'Webhook debe retornar 403 si la firma es inválida');
    }

    public function testWebhookExtractsMessageSidAndStatus(): void
    {
        $this->assertStringContainsString('MessageSid', $this->webhookCode,
            'Webhook debe extraer MessageSid del POST');
        $this->assertStringContainsString('MessageStatus', $this->webhookCode,
            'Webhook debe extraer MessageStatus del POST');
    }

    public function testWebhookUsesPreparedStatements(): void
    {
        $this->assertMatchesRegularExpression('/prepare\s*\(/i', $this->webhookCode,
            'Webhook debe usar prepared statements para UPDATE');
    }

    public function testWebhookUpdatesDeliveryStatus(): void
    {
        $this->assertStringContainsString('delivery_status', $this->webhookCode,
            'Webhook debe actualizar delivery_status en twilio_messages');
        $this->assertStringContainsString('provider_message_sid', $this->webhookCode,
            'Webhook debe buscar por provider_message_sid');
    }

    public function testWebhookHandlesMissingFields(): void
    {
        $this->assertStringContainsString('Missing required fields', $this->webhookCode,
            'Webhook debe manejar el caso de campos faltantes');
    }

    public function testWebhookReturnsXmlResponse(): void
    {
        $this->assertStringContainsString('text/xml', $this->webhookCode,
            'Webhook debe retornar respuesta XML a Twilio');
        $this->assertStringContainsString('<Response>', $this->webhookCode,
            'Webhook debe retornar <Response> vacío');
    }

    public function testWebhookSetsSystemRoleForRlsBypass(): void
    {
        $this->assertStringContainsString("SYSTEM_WORKER", $this->webhookCode,
            'Webhook debe setear app.current_role=SYSTEM_WORKER para bypass RLS');
    }

    public function testWebhookLogsSecurityEvents(): void
    {
        $this->assertStringContainsString('securityLog', $this->webhookCode,
            'Webhook debe registrar eventos de seguridad');
        $this->assertStringContainsString('TWILIO_WEBHOOK_REJECTED', $this->webhookCode,
            'Webhook debe loggear rechazos de firma');
    }

    // =========================================================================
    // VF-029: Workers polling (absence, evasion, permission)
    // =========================================================================

    public function testPollingWorkersExist(): void
    {
        $this->assertGreaterThanOrEqual(3, count($this->pollingWorkers),
            'Debe haber al menos 3 workers polling (absence, evasion, permission)');
    }

    public function testPollingWorkersHaveDistributedLock(): void
    {
        foreach ($this->pollingWorkers as $name => $code) {
            $this->assertStringContainsString('lock:', $code,
                "Worker '$name' debe usar distributed lock (lock: key en Redis)");
            $this->assertStringContainsString("'nx'", $code,
                "Worker '$name' debe usar SET NX para lock atómico");
            $this->assertStringContainsString("'ex'", $code,
                "Worker '$name' debe usar SET EX para TTL del lock");
        }
    }

    public function testPollingWorkersReleaseLockInFinally(): void
    {
        foreach ($this->pollingWorkers as $name => $code) {
            $this->assertStringContainsString('finally', $code,
                "Worker '$name' debe liberar lock en bloque finally");
            $this->assertStringContainsString('->del(', $code,
                "Worker '$name' debe eliminar lock con redis->del()");
        }
    }

    public function testPollingWorkersUsePreparedStatements(): void
    {
        foreach ($this->pollingWorkers as $name => $code) {
            $this->assertMatchesRegularExpression('/prepare\s*\(/i', $code,
                "Worker '$name' debe usar prepared statements");
        }
    }

    public function testPollingWorkersSetSchoolContext(): void
    {
        foreach ($this->pollingWorkers as $name => $code) {
            $this->assertStringContainsString('current_school_id', $code,
                "Worker '$name' debe setear app.current_school_id para RLS");
        }
    }

    public function testPollingWorkersHaveCronAndDaemonModes(): void
    {
        foreach ($this->pollingWorkers as $name => $code) {
            $this->assertStringContainsString('cron', $code,
                "Worker '$name' debe soportar modo cron");
            $this->assertStringContainsString('daemon', $code,
                "Worker '$name' debe soportar modo daemon");
        }
    }

    public function testPollingWorkersQueryActiveSchools(): void
    {
        foreach ($this->pollingWorkers as $name => $code) {
            $this->assertStringContainsString('active = TRUE', $code,
                "Worker '$name' debe consultar solo escuelas activas");
        }
    }

    public function testPollingWorkersHaveShutdownSignal(): void
    {
        foreach ($this->pollingWorkers as $name => $code) {
            $this->assertStringContainsString('SIGTERM', $code,
                "Worker '$name' debe manejar SIGTERM para graceful shutdown");
        }
    }

    public function testPollingWorkersLogCompletion(): void
    {
        foreach ($this->pollingWorkers as $name => $code) {
            $this->assertTrue(
                strpos($code, 'CRON_DONE') !== false || strpos($code, 'DETECTED') !== false,
                "Worker '$name' debe loggear completación del ciclo"
            );
        }
    }

    // VF-022: Rate limiting distribuido en worker_twilio
    public function testTwilioWorkerUsesDistributedRateLimit(): void
    {
        $twilioCode = file_get_contents(__DIR__ . '/../../backend/api/workers/worker_twilio.php');
        $this->assertStringContainsString('twilio:sends:hour:', $twilioCode,
            'worker_twilio debe usar contador distribuido en Redis (twilio:sends:hour:)');
        $this->assertStringContainsString('$redis->incr(', $twilioCode,
            'worker_twilio debe usar incr atómico para contador distribuido');
        $this->assertStringContainsString('global_sends', $twilioCode,
            'worker_twilio debe verificar límite global, no por-instancia');
    }
}
