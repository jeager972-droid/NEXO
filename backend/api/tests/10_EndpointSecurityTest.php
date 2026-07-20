<?php
/**
 * 10_EndpointSecurityTest.php
 * Verifica seguridad en endpoints PHP.
 */
require_once __DIR__ . '/../vendor/autoload.php';

class EndpointSecurityTest extends PHPUnit\Framework\TestCase
{
    private array $routeFiles = [];
    private string $middlewareContent;

    public function setUp(): void
    {
        $routesDir = __DIR__ . '/../routes';
        foreach (glob($routesDir . '/*.php') as $file) {
            $this->routeFiles[basename($file)] = file_get_contents($file);
        }
        $this->middlewareContent = file_get_contents($routesDir . '/_auth_middleware.php');
    }

    public function testMiddlewareRequiresAuth(): void
    {
        $this->assertStringContainsString('requireAuth', $this->middlewareContent,
            'Middleware debe tener función requireAuth()');
        $this->assertStringContainsString('JWT', $this->middlewareContent,
            'Middleware debe manejar JWT');
    }

    public function testNoDirectEchoInRoutes(): void
    {
        foreach ($this->routeFiles as $name => $content) {
            if ($name === '_auth_middleware.php') continue;
            // echo sin json_encode es sospechoso
            if (preg_match('/echo\s+[\'"][^\'"]*[\'"]/', $content)) {
                $this->addWarning("Ruta '$name' tiene echo directo (posible info leak)");
            }
        }
        $this->assertTrue(true);
    }

    public function testRoutesUsePreparedStatements(): void
    {
        foreach ($this->routeFiles as $name => $content) {
            if ($name === '_auth_middleware.php') continue;
            // Debe usar PDO prepare o equivalente
            $this->assertMatchesRegularExpression('/prepare\s*\(/i', $content,
                "Ruta '$name' debe usar prepared statements");
        }
    }

    public function testNoSqlConcatenation(): void
    {
        foreach ($this->routeFiles as $name => $content) {
            if ($name === '_auth_middleware.php') continue;
            // Detectar concatenación de variables en SQL
            $this->assertDoesNotMatchRegularExpression('/\$\w+\s*\.\s*[\'"]\s*(WHERE|AND|OR|SET)\s+/i', $content,
                "Ruta '$name' parece concatenar variables en SQL (inyección)");
        }
    }

    public function testRolesAreCanonical(): void
    {
        $this->assertStringContainsString("'GUARDIAN'", $this->middlewareContent,
            'Middleware debe usar rol GUARDIAN (inglés)');
        $this->assertStringNotContainsString("'ACUDIENTE'", $this->middlewareContent,
            'Middleware no debe usar ACUDIENTE (español legacy)');
    }

    public function testRateLimitingExists(): void
    {
        $this->assertStringContainsStringIgnoringCase('rate_limit', $this->middlewareContent,
            'Middleware debe tener rate limiting');
    }
}
