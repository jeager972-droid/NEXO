<?php
/**
 * =============================================================================
 * 03_ConstraintTest.php — Test de constraints del esquema SQL.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica en sql/schema.sql la existencia de:
 *   - Constraints CHECK para enums (risk_level, platform, event_type, severity).
 *   - Constraints UNIQUE requeridos (users, students, guardian_student, etc.).
 *   - NOT NULL en columnas críticas (password_hash, document_number, etc.).
 *   - DEFAULT NOW() para timestamps.
 *
 * NOTA: test estático; no requiere PostgreSQL.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class ConstraintTest extends PHPUnit\Framework\TestCase
{
    private string $sql;

    public function setUp(): void
    {
        $this->sql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
    }

    public function testCheckConstraintsExist(): void
    {
        $checks = [
            'risk_level' => "CHECK(risk_level IN('LOW','MEDIUM','HIGH','CRITICAL'))",
            'platform' => "CHECK (platform IN ('web', 'desktop', 'android', 'ios'))",
            'event_type' => "CHECK (event_type IN ('JS_ERROR', 'API_LATENCY', 'BIOMETRIC_LATENCY', 'APP_PING', 'RENDER_SLOW'))",
            'severity' => "CHECK (severity IN ('debug', 'info', 'warn', 'error'))",
        ];
        foreach ($checks as $col => $pattern) {
            $this->assertStringContainsStringIgnoringCase($pattern, $this->sql,
                "Constraint CHECK faltante para columna '$col'");
        }
    }

    public function testUniqueConstraintsExist(): void
    {
        $uniques = [
            'uq_users_school_document',
            'uq_students_school_document',
            'uq_guardian_student_relationship',
            'uq_role_permissions_role_permission',
            'uq_academic_group_school_year_name',
            'uq_sga_student_group',
            'uq_behavior_student_window',
        ];
        foreach ($uniques as $name) {
            $this->assertStringContainsStringIgnoringCase($name, $this->sql,
                "Constraint UNIQUE faltante: '$name'");
        }
    }

    public function testNotNullOnCriticalColumns(): void
    {
        $critical = [
            'users' => ['password_hash', 'password_salt', 'document_number'],
            'students' => ['document_number', 'first_name', 'last_name'],
            'guardians' => ['whatsapp_phone'],
            'schools' => ['school_name'],
        ];
        foreach ($critical as $table => $cols) {
            preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+' . $table . '\s*\(/si', $this->sql, $m, PREG_OFFSET_CAPTURE);
            if (empty($m[0])) continue;
            $start = $m[0][0][1] + strlen($m[0][0][0]);
            $depth = 1; $end = $start;
            while ($depth > 0 && $end < strlen($this->sql)) {
                if ($this->sql[$end] === '(') $depth++;
                elseif ($this->sql[$end] === ')') $depth--;
                $end++;
            }
            $body = substr($this->sql, $start, $end - $start - 1);
            foreach ($cols as $col) {
                $this->assertMatchesRegularExpression('/' . $col . '\s+\S+\s+NOT\s+NULL/si', $body,
                    "Columna '$table.$col' debe ser NOT NULL");
            }
        }
    }

    public function testDefaultValuesForTimestamps(): void
    {
        $this->assertStringContainsStringIgnoringCase("DEFAULT NOW()", $this->sql,
            'Debe haber DEFAULT NOW() para timestamps');
    }
}
