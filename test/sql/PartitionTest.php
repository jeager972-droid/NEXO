<?php
/**
 * =============================================================================
 * PartitionTest.php — Test de particionamiento por rango.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica en sql/schema.sql que:
 *   - Tablas de alto volumen estén particionadas por RANGE.
 *   - Existan particiones DEFAULT para datos sin rango.
 *   - Las columnas de particionamiento sean NOT NULL.
 *
 * NOTA: test estático; no requiere PostgreSQL.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class PartitionTest extends PHPUnit\Framework\TestCase
{
    private string $sql;

    public function setUp(): void
    {
        $this->sql = file_get_contents(__DIR__ . '/../../sql/schema.sql');
    }

    public function testPartitionedTablesExist(): void
    {
        $expected = ['biometric_events', 'attendance_incidents', 'twilio_messages',
                     'global_audit_logs', 'user_commands', 'sos_alerts',
                     'internal_messages', 'student_record_audit'];
        foreach ($expected as $table) {
            $this->assertMatchesRegularExpression("/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+$table\s*\(.*PARTITION\s+BY\s+RANGE/si", $this->sql,
                "Tabla '$table' debe estar particionada por RANGE");
        }
    }

    public function testDefaultPartitionsExist(): void
    {
        $defaults = ['biometric_events_default', 'attendance_incidents_default',
                     'twilio_messages_default', 'global_audit_logs_default'];
        foreach ($defaults as $part) {
            $this->assertStringContainsStringIgnoringCase($part, $this->sql,
                "Partición DEFAULT '$part' no encontrada");
        }
    }

    public function testPartitionColumnIsNotNull(): void
    {
        // Las columnas de particionamiento deben ser NOT NULL
        preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+(\w+)\s*\(/si', $this->sql, $m, PREG_OFFSET_CAPTURE);
        for ($i = 0; $i < count($m[0]); $i++) {
            $table = $m[1][$i][0];
            $start = $m[0][$i][1] + strlen($m[0][$i][0]);
            $depth = 1; $end = $start;
            while ($depth > 0 && $end < strlen($this->sql)) {
                if ($this->sql[$end] === '(') $depth++;
                elseif ($this->sql[$end] === ')') $depth--;
                $end++;
            }
            $body = substr($this->sql, $start, $end - $start - 1);
            $after = substr($this->sql, $end, 100);
            if (preg_match('/PARTITION\s+BY\s+RANGE\s*\((\w+)\)/si', $after, $pm)) {
                $partCol = $pm[1];
                $this->assertMatchesRegularExpression("/$partCol\s+\S+\s+NOT\s+NULL/si", $body,
                    "Columna de particionamiento '$partCol' en '$table' debe ser NOT NULL");
            }
        }
    }
}
