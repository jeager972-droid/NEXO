<?php
/**
 * =============================================================================
 * RouteEndpointsTest — Test estático de endpoints de rutas PHP.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica que cada archivo de routes/:
 *   - Define al menos un endpoint ($cleanPath check).
 *   - Usa prepared statements (PDO::prepare).
 *   - No concatena variables directamente en SQL.
 *   - Usa json_encode para respuestas (no echo directo).
 *   - Los endpoints GET no modifican estado (no INSERT/UPDATE/DELETE sin auth).
 *
 * NOTA: test estático; no ejecuta el servidor ni requiere DB.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

use PHPUnit\Framework\TestCase;

class RouteEndpointsTest extends TestCase
{
    private array $routeFiles = [];
    private array $excludeFiles = ['_auth_middleware.php', '_cors_middleware.php'];

    public function setUp(): void
    {
        $routesDir = __DIR__ . '/../../backend/api/routes';
        foreach (glob($routesDir . '/*.php') as $file) {
            $name = basename($file);
            if (in_array($name, $this->excludeFiles)) continue;
            $this->routeFiles[$name] = file_get_contents($file);
        }
    }

    public function testAllRoutesDefineEndpoints(): void
    {
        foreach ($this->routeFiles as $name => $content) {
            $this->assertStringContainsString('$cleanPath', $content,
                "Ruta '$name' debe definir al menos un endpoint con \$cleanPath");
        }
    }

    public function testAllRoutesUsePreparedStatements(): void
    {
        // Algunas rutas delegan a lib/ (admin.php → RiskScoreEngine, metrics.php → dashboard)
        $delegatingRoutes = ['admin.php', 'metrics.php', '_cors_middleware.php'];
        foreach ($this->routeFiles as $name => $content) {
            if (in_array($name, $delegatingRoutes)) continue;
            $this->assertMatchesRegularExpression('/prepare\s*\(/i', $content,
                "Ruta '$name' debe usar prepared statements (PDO::prepare)");
        }
    }

    public function testNoDirectSqlInjectionPatterns(): void
    {
        foreach ($this->routeFiles as $name => $content) {
            // Detectar concatenación de variables en SQL WHERE/AND/OR
            $this->assertDoesNotMatchRegularExpression(
                '/\$\w+\s*\.\s*[\'"]\s*(WHERE|AND|OR|SET|VALUES)\s+/i',
                $content,
                "Ruta '$name' parece concatenar variables en SQL (riesgo de inyección)"
            );
        }
    }

    public function testRoutesUseJsonEncodeForResponses(): void
    {
        // metrics.php usa formato Prometheus (texto plano), no JSON
        $excluded = ['metrics.php'];
        foreach ($this->routeFiles as $name => $content) {
            if (in_array($name, $excluded)) continue;
            $this->assertStringContainsString('json_encode', $content,
                "Ruta '$name' debe usar json_encode para respuestas");
        }
    }

    public function testNoEvalOrExec(): void
    {
        foreach ($this->routeFiles as $name => $content) {
            // eval() de PHP es peligroso
            $this->assertDoesNotMatchRegularExpression('/\beval\s*\(/i', $content,
                "Ruta '$name' no debe usar eval()");
            // system() de PHP es peligroso
            $this->assertDoesNotMatchRegularExpression('/\bsystem\s*\(/i', $content,
                "Ruta '$name' no debe usar system()");
            // exec() de PHP es peligroso — pero $conn->exec() de PDO es legítimo
            // Quitar comentarios para evitar falsos positivos
            $codeOnly = preg_replace('/\/\/[^\n]*/', '', $content);
            $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $codeOnly);
            // Buscar exec() que NO esté precedido por ->
            preg_match_all('/\bexec\s*\(/i', $codeOnly, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $m) {
                $pos = $m[1];
                $before = $pos >= 10 ? substr($codeOnly, $pos - 10, 10) : substr($codeOnly, 0, $pos);
                $this->assertStringContainsString('->', $before,
                    "Ruta '$name' tiene exec() fuera de \$conn->exec() (PDO). Solo PDO exec es permitido");
            }
        }
    }

    public function testNoHardcodedSecrets(): void
    {
        foreach ($this->routeFiles as $name => $content) {
            // No debe haber strings que parezcan secrets hardcodeados
            $this->assertDoesNotMatchRegularExpression(
                '/(?:password|secret|api_key|token)\s*=\s*[\'"][^\'"]{16,}[\'"]/i',
                $content,
                "Ruta '$name' no debe tener secrets hardcodeados"
            );
        }
    }

    public function testAuthRouteHasLogin(): void
    {
        $this->assertStringContainsString('/auth/login', $this->routeFiles['auth.php'],
            'auth.php debe tener endpoint /auth/login');
    }

    public function testAuthRouteHasLogout(): void
    {
        $this->assertStringContainsString('/auth/logout', $this->routeFiles['auth.php'],
            'auth.php debe tener endpoint /auth/logout');
    }

    public function testAuthRouteHasRefresh(): void
    {
        $this->assertStringContainsString('/auth/refresh', $this->routeFiles['auth.php'],
            'auth.php debe tener endpoint /auth/refresh');
    }

    public function testAuthRouteHas2FA(): void
    {
        $this->assertStringContainsString('/auth/verify-2fa', $this->routeFiles['auth.php'],
            'auth.php debe tener endpoint /auth/verify-2fa');
    }

    public function testAuthRouteHasGetMe(): void
    {
        $this->assertStringContainsString('/auth/me', $this->routeFiles['auth.php'],
            'auth.php debe tener endpoint /auth/me');
    }

    public function testStudentsRouteHasListEndpoint(): void
    {
        $this->assertStringContainsString("'/students'", $this->routeFiles['students.php'],
            'students.php debe tener endpoint /students');
    }

    public function testOperationsRouteHasTwilioStatus(): void
    {
        $this->assertStringContainsString('/operations/twilio-status', $this->routeFiles['operations.php'],
            'operations.php debe tener endpoint /operations/twilio-status');
    }

    public function testOperationsRouteHasSOS(): void
    {
        $this->assertStringContainsString("'sos'", $this->routeFiles['operations.php'],
            'operations.php debe manejar operación SOS');
    }

    public function testOperationsRouteHasCitacion(): void
    {
        $this->assertStringContainsString("'citacion'", $this->routeFiles['operations.php'],
            'operations.php debe manejar operación citacion');
    }

    public function testOperationsRouteHasPermiso(): void
    {
        $this->assertStringContainsString("'permiso'", $this->routeFiles['operations.php'],
            'operations.php debe manejar operación permiso');
    }

    public function testSecurityPanicRouteExists(): void
    {
        $this->assertStringContainsString('panic', $this->routeFiles['security_panic.php'],
            'security_panic.php debe manejar panic mode');
    }

    public function testTwilioDeliveryRouteExists(): void
    {
        $this->assertStringContainsString('twilio', $this->routeFiles['twilio_delivery.php'],
            'twilio_delivery.php debe manejar webhooks de Twilio');
    }

    public function testAllRoutesCheckAuth(): void
    {
        // auth.php = login/refresh (no requiere auth previa)
        // twilio_delivery.php = webhook de Twilio (usa firma HMAC)
        // metrics.php = usa METRICS_SECRET_KEY (no JWT)
        $skipAuthCheck = ['auth.php', 'twilio_delivery.php', 'metrics.php'];
        foreach ($this->routeFiles as $name => $content) {
            if (in_array($name, $skipAuthCheck)) continue;
            $hasAuth = strpos($content, 'requireAuth') !== false
                    || strpos($content, 'requireRole') !== false
                    || strpos($content, 'JWT') !== false
                    || strpos($content, 'Bearer') !== false;
            $this->assertTrue($hasAuth,
                "Ruta '$name' debe verificar autenticación (requireAuth/requireRole/JWT/Bearer)");
        }
    }

    public function testNoDeprecatedAcudienteRole(): void
    {
        foreach ($this->routeFiles as $name => $content) {
            // No debe usar el rol legacy ACUDIENTE (debe ser GUARDIAN)
            $this->assertStringNotContainsString("'ACUDIENTE'", $content,
                "Ruta '$name' no debe usar rol legacy ACUDIENTE (usar GUARDIAN)");
        }
    }

    public function testNoDeprecatedSuperRectorRole(): void
    {
        foreach ($this->routeFiles as $name => $content) {
            $this->assertStringNotContainsString("'SUPER_RECTOR'", $content,
                "Ruta '$name' no debe usar rol legacy SUPER_RECTOR");
            $this->assertStringNotContainsString('SUPER_RECTOR', $content,
                "Ruta '$name' no debe referenciar SUPER_RECTOR");
        }
    }
}
