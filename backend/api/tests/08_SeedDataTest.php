<?php
/**
 * =============================================================================
 * 08_SeedDataTest.php — Test de consistencia del seed data.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica en sql/nexo_seed.sql que:
 *   - Existan todos los roles esperados y un usuario admin RECTOR.
 *   - Existan guardians, relaciones, escuela, ubicación, mensajes Twilio.
 *   - Las tablas referenciadas existan en nexo_full_migration.sql.
 *   - Se usen uuid_generate_v4() y ON CONFLICT para idempotencia.
 *
 * NOTA: test estático; no requiere PostgreSQL.
 */
require_once __DIR__ . '/../vendor/autoload.php';

class SeedDataTest extends PHPUnit\Framework\TestCase
{
    private string $seedSql;
    private string $migrationSql;

    public function setUp(): void
    {
        $this->seedSql = file_get_contents(__DIR__ . '/../sql/nexo_seed.sql');
        $this->migrationSql = file_get_contents(__DIR__ . '/../sql/nexo_full_migration.sql');
    }

    public function testSeedHasAllRoles(): void
    {
        $roles = ['RECTOR', 'COORDINATOR', 'TEACHER', 'SECRETARY', 'SECURITY', 'AUXILIARY', 'COUNSELOR', 'GUARDIAN'];
        foreach ($roles as $role) {
            $this->assertStringContainsString("'$role'", $this->seedSql, "Seed debe contener rol '$role'");
        }
    }

    public function testSeedHasAdminUser(): void
    {
        $this->assertStringContainsString("RECTOR", $this->seedSql, 'Seed debe tener admin RECTOR');
        $this->assertStringContainsString("password_hash", $this->seedSql, 'Admin debe tener password_hash');
    }

    public function testSeedHasGuardians(): void
    {
        $this->assertStringContainsString("INSERT INTO guardians", $this->seedSql);
        $this->assertStringContainsString("INSERT INTO guardian_student_relationships", $this->seedSql);
    }

    public function testSeedHasSchoolAndLocation(): void
    {
        $this->assertStringContainsString("INSERT INTO schools", $this->seedSql);
        $this->assertStringContainsString("INSERT INTO departments", $this->seedSql);
        $this->assertStringContainsString("INSERT INTO municipalities", $this->seedSql);
    }

    public function testSeedHasTwilioMessages(): void
    {
        $this->assertStringContainsString("INSERT INTO twilio_messages", $this->seedSql);
    }

    public function testSeedReferencesValidTables(): void
    {
        preg_match_all('/INSERT\s+INTO\s+(\w+)/i', $this->seedSql, $m);
        $tables = array_unique($m[1]);
        preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+(\w+)/si', $this->migrationSql, $mm);
        $migrationTables = $mm[1];
        foreach ($tables as $table) {
            $this->assertContains($table, $migrationTables,
                "Seed referencia tabla '$table' no definida en migración");
        }
    }

    public function testSeedUsesUuidGenerateV4(): void
    {
        $this->assertStringContainsStringIgnoringCase('uuid_generate_v4()', $this->seedSql,
            'Seed debe usar uuid_generate_v4() para UUIDs');
    }

    public function testSeedHasOnConflict(): void
    {
        $this->assertStringContainsStringIgnoringCase('ON CONFLICT', $this->seedSql,
            'Seed debe usar ON CONFLICT para idempotencia');
    }
}
