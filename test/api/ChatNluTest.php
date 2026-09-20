<?php
/**
 * ChatNluTest.php — Verificación estática del chatbot NLU.
 * Comprueba: endpoint registrado, auth obligatoria, RBAC por intent,
 * prepared statements, fallback <0.66, modelo exportado, sin SQL libre.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';
require_once __DIR__ . '/../../backend/api/lib/nexus_nlu.php';

class ChatNluTest extends PHPUnit\Framework\TestCase
{
    private string $route;
    private string $apiPhp;

    public function setUp(): void
    {
        $this->route  = file_get_contents(__DIR__ . '/../../backend/api/routes/chat.php');
        $this->apiPhp = file_get_contents(__DIR__ . '/../../backend/api/api.php');
    }

    public function testEndpointRegistrado(): void
    {
        $this->assertStringContainsString("'chat' => 'chat.php'", $this->apiPhp);
        $this->assertStringContainsString("/chat/message", $this->route);
        $this->assertStringContainsString("/chat/history", $this->route);
    }

    public function testAutenticacionObligatoria(): void
    {
        $this->assertStringContainsString('requireAuth()', $this->route);
    }

    public function testRbacPorIntent(): void
    {
        $this->assertStringContainsString('nxAllowed', $this->route);
        // docente NO puede auditoría ni dispositivos
        $this->assertFalse(nxAllowed('audit_query', 'TEACHER'));
        $this->assertFalse(nxAllowed('devices_status', 'TEACHER'));
        $this->assertFalse(nxAllowed('devices_status', 'SECRETARY'));
        // docente SÍ puede sus consultas
        $this->assertTrue(nxAllowed('count_events', 'TEACHER'));
        $this->assertTrue(nxAllowed('student_field', 'TEACHER'));
        $this->assertTrue(nxAllowed('greeting', 'TEACHER'));
        $this->assertTrue(nxAllowed('audit_query', 'RECTOR'));
        // portero/auxiliar: solo smalltalk
        $this->assertFalse(nxAllowed('count_events', 'SECURITY'));
        $this->assertTrue(nxAllowed('joke', 'AUXILIARY'));
    }

    public function testSinSqlLibreNiConcatenacion(): void
    {
        // todas las consultas parametrizadas
        $this->assertStringNotContainsString('mysql_query', $this->route);
        $this->assertStringNotContainsString('$conn->query(', $this->route);
        // input del usuario jamás interpolado en SQL
        $this->assertDoesNotMatchRegularExpression('/\$conn->prepare\([^)]*\{\$text\}/', $this->route);
        $this->assertStringContainsString('chatScope', $this->route); // scope docente
    }

    public function testFallbackPorConfianza(): void
    {
        $this->assertSame(0.65, NX_NLU_THRESHOLD);
        // frase absurda → out_of_scope con confianza baja o marcada
        $r = nxClassify('asdfgh qwerty zzz 12345');
        $this->assertContains($r['intent'], ['out_of_scope']);
    }

    public function testModeloPhpExportadoYClasifica(): void
    {
        $model = __DIR__ . '/../../backend/nlu/model/model_php.json';
        $this->assertFileExists($model);
        $r = nxClassifyLocal('cuéntame un chiste');
        $this->assertSame('joke', $r['intent']);
        $this->assertGreaterThan(0.66, $r['confidence']);
        $r = nxClassifyLocal('cuantas evasiones tuvo juan perez del 7a en 15 dias');
        $this->assertSame('count_events', $r['intent']);
        $this->assertSame('7A', $r['entities']['group']);
        $this->assertSame(15, $r['entities']['days']);
        $this->assertSame('juan perez', $r['entities']['student']);
    }

    public function testExtraccionDeEntidades(): void
    {
        $s = nxSlots('dame el celular de la acudiente de camila rojas');
        $this->assertSame('camila rojas', $s['student']);
        $this->assertSame('celular', $s['field']);
        $s = nxSlots('inasistencias del 9b esta semana');
        $this->assertSame('9B', $s['group']);
        $this->assertSame('INASISTENCIA', $s['module']);
    }

    public function testSmalltalkSinInyeccion(): void
    {
        // las respuestas smalltalk no interpolan input libre
        $r = nxSmalltalk('joke');
        $this->assertNotEmpty($r);
        $this->assertStringNotContainsString('<script', $r);
    }
}
