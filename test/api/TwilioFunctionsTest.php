<?php
/**
 * =============================================================================
 * TwilioFunctionsTest — Test unitario de funciones de Twilio.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica funciones puras de lib/twilio.php:
 *   - normalizeWhatsAppPhone(): normalización de números de teléfono.
 *   - getTwilioStatusCallbackUrl(): construcción de URL de webhook.
 *
 * NOTA: no requiere PostgreSQL ni Redis; son funciones de string/env.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';
require_once __DIR__ . '/../../backend/api/lib/twilio.php';

use PHPUnit\Framework\TestCase;

class TwilioFunctionsTest extends TestCase
{
    // ── normalizeWhatsAppPhone ─────────────────────────────────────────────

    public function testNormalizeSimpleNumber(): void
    {
        $this->assertEquals('+573001234567', normalizeWhatsAppPhone('573001234567'));
    }

    public function testNormalizeWithPlus(): void
    {
        $this->assertEquals('+573001234567', normalizeWhatsAppPhone('+573001234567'));
    }

    public function testNormalizeWithWhatsappPrefix(): void
    {
        $this->assertEquals('+573001234567', normalizeWhatsAppPhone('whatsapp:+573001234567'));
    }

    public function testNormalizeWithWhatsappPrefixLowercase(): void
    {
        $this->assertEquals('+573001234567', normalizeWhatsAppPhone('whatsapp:573001234567'));
    }

    public function testNormalizeWithSpaces(): void
    {
        $this->assertEquals('+573001234567', normalizeWhatsAppPhone('  57 300 123 4567  '));
    }

    public function testNormalizeWithDashes(): void
    {
        $this->assertEquals('+573001234567', normalizeWhatsAppPhone('57-300-123-4567'));
    }

    public function testNormalizeWithParentheses(): void
    {
        $this->assertEquals('+573001234567', normalizeWhatsAppPhone('(57) 300-123-4567'));
    }

    public function testNormalizeEmptyString(): void
    {
        $this->assertEquals('', normalizeWhatsAppPhone(''));
    }

    public function testNormalizeOnlySpaces(): void
    {
        $this->assertEquals('', normalizeWhatsAppPhone('   '));
    }

    public function testNormalizeOnlyWhatsappPrefix(): void
    {
        $this->assertEquals('', normalizeWhatsAppPhone('whatsapp:'));
    }

    public function testNormalizeWithLetters(): void
    {
        // Las letras deben eliminarse, dejando solo números y +
        $this->assertEquals('+57300', normalizeWhatsAppPhone('abc57300def'));
    }

    public function testNormalizeAlreadyNormalized(): void
    {
        $this->assertEquals('+573001234567', normalizeWhatsAppPhone('+573001234567'));
    }

    // ── getTwilioStatusCallbackUrl ─────────────────────────────────────────

    protected function tearDown(): void
    {
        putenv('TWILIO_WEBHOOK_URL_BASE');
        putenv('APP_URL');
    }

    public function testStatusCallbackUrlFromTwilioEnv(): void
    {
        putenv('TWILIO_WEBHOOK_URL_BASE=https://api.nexo.edu');
        $url = getTwilioStatusCallbackUrl();
        $this->assertEquals('https://api.nexo.edu/v1/webhooks/twilio/status', $url);
    }

    public function testStatusCallbackUrlFromAppUrl(): void
    {
        putenv('APP_URL=https://api.nexo.edu');
        $url = getTwilioStatusCallbackUrl();
        $this->assertEquals('https://api.nexo.edu/v1/webhooks/twilio/status', $url);
    }

    public function testStatusCallbackUrlTrimsTrailingSlash(): void
    {
        putenv('TWILIO_WEBHOOK_URL_BASE=https://api.nexo.edu/');
        $url = getTwilioStatusCallbackUrl();
        $this->assertEquals('https://api.nexo.edu/v1/webhooks/twilio/status', $url);
    }

    public function testStatusCallbackUrlNoEnvReturnsNull(): void
    {
        putenv('TWILIO_WEBHOOK_URL_BASE=');
        putenv('APP_URL=');
        $url = getTwilioStatusCallbackUrl();
        $this->assertNull($url);
    }

    public function testStatusCallbackUrlPrefersTwilioEnv(): void
    {
        putenv('TWILIO_WEBHOOK_URL_BASE=https://twilio.nexo.edu');
        putenv('APP_URL=https://app.nexo.edu');
        $url = getTwilioStatusCallbackUrl();
        $this->assertEquals('https://twilio.nexo.edu/v1/webhooks/twilio/status', $url);
    }
}
