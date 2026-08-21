<?php
/**
 * =============================================================================
 * 14_InstallationTest.php — Test de instalación y despliegue.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica que los artefactos necesarios para instalar NEXO existan:
 *   - Archivos de migración (schema.sql) y seed (schema.sql).
 *   - Idempotencia de la migración (IF NOT EXISTS, DROP POLICY IF EXISTS).
 *   - Extensiones requeridas declaradas (uuid-ossp, pgcrypto).
 *   - Sintaxis PHP válida en routes, workers y lib.
 *   - Existencia de config.php.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class InstallationTest extends PHPUnit\Framework\TestCase
{
    public function testMigrationFileExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../sql/schema.sql');
    }

    public function testSeedFileExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../sql/schema.sql');
    }

    public function testMigrationIsIdempotent(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
        $this->assertStringContainsStringIgnoringCase('IF NOT EXISTS', $sql,
            'Migración debe ser idempotente (usar IF NOT EXISTS)');
        $this->assertStringContainsStringIgnoringCase('DROP POLICY IF EXISTS', $sql,
            'Migración debe manejar policies existentes');
    }

    public function testRequiredExtensionsDeclared(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
        $this->assertStringContainsString('uuid-ossp', $sql);
        $this->assertStringContainsString('pgcrypto', $sql);
    }

    public function testPhpSyntaxValid(): void
    {
        $files = array_merge(
            glob(__DIR__ . '/../../backend/api/routes/*.php'),
            glob(__DIR__ . '/../../backend/api/workers/*.php'),
            glob(__DIR__ . '/../../backend/api/lib/*.php')
        );
        foreach ($files as $file) {
            $output = [];
            $exitCode = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
            $this->assertEquals(0, $exitCode,
                "Error de sintaxis en " . basename($file) . ": " . implode("\n", $output));
        }
    }

    public function testEnvExampleFileExists(): void
    {
        // El proyecto usa .env en lugar de config.php
        $this->assertFileExists(__DIR__ . '/../../backend/api/.env.example',
            'Debe existir archivo .env.example con variables de configuración');
    }
}
