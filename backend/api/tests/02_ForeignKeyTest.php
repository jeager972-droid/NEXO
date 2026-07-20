<?php
/**
 * 02_ForeignKeyTest.php
 * Verifica integridad referencial exhaustiva de Foreign Keys.
 */
require_once __DIR__ . '/../vendor/autoload.php';

class ForeignKeyTest extends PHPUnit\Framework\TestCase
{
    private string $sql;
    private array $fks = [];
    private array $tables = [];

    public function setUp(): void
    {
        $this->sql = file_get_contents(__DIR__ . '/../sql/nexo_full_migration.sql');
        $this->parseFks();
        $this->parseTables();
    }

    private function parseTables(): void
    {
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
            preg_match_all('/^(\w+)/mi', $body, $cols);
            $this->tables[$table] = array_unique($cols[1]);
        }
    }

    private function parseFks(): void
    {
        // Inline REFERENCES
        preg_match_all('/(\w+)\s+\S+.*?REFERENCES\s+(\w+)\s*\((\w+)\)/si', $this->sql, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $this->fks[] = ['column' => $match[1], 'ref_table' => $match[2], 'ref_column' => $match[3]];
        }
        // ALTER TABLE FKs
        preg_match_all('/ALTER\s+TABLE\s+(\w+)\s+ADD\s+CONSTRAINT\s+\w+\s+FOREIGN\s+KEY\s*\((\w+)\)\s+REFERENCES\s+(\w+)\s*\((\w+)\)/si', $this->sql, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $this->fks[] = ['column' => $match[2], 'ref_table' => $match[3], 'ref_column' => $match[4]];
        }
    }

    public function testNoOrphanForeignKeys(): void
    {
        foreach ($this->fks as $fk) {
            $this->assertArrayHasKey($fk['ref_table'], $this->tables,
                "FK referencia tabla inexistente: {$fk['ref_table']}");
            $this->assertContains($fk['ref_column'], $this->tables[$fk['ref_table']],
                "FK referencia columna inexistente: {$fk['ref_table']}.{$fk['ref_column']}");
        }
    }

    public function testAllFkColumnsExist(): void
    {
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
            preg_match_all('/REFERENCES\s+(\w+)\s*\((\w+)\)/si', $body, $refs, PREG_SET_ORDER);
            foreach ($refs as $ref) {
                $this->assertArrayHasKey($ref[1], $this->tables,
                    "En tabla '$table': FK referencia tabla '{$ref[1]}' inexistente");
            }
        }
    }

    public function testRequiredForeignKeysExist(): void
    {
        $required = [
            ['users', 'school_id', 'schools'],
            ['users', 'role_id', 'roles'],
            ['students', 'school_id', 'schools'],
            ['guardians', 'user_id', 'users'],
            ['guardian_student_relationships', 'guardian_id', 'guardians'],
            ['guardian_student_relationships', 'student_id', 'students'],
            ['biometric_events', 'device_id', 'edge_devices'],
            ['schedules', 'teacher_user_id', 'users'],
        ];
        foreach ($required as $req) {
            [$table, $col, $refTable] = $req;
            $found = false;
            preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+' . $table . '\s*\(/si', $this->sql, $tm, PREG_OFFSET_CAPTURE);
            if (!empty($tm[0])) {
                $start = $tm[0][0][1] + strlen($tm[0][0][0]);
                $depth = 1; $end = $start;
                while ($depth > 0 && $end < strlen($this->sql)) {
                    if ($this->sql[$end] === '(') $depth++;
                    elseif ($this->sql[$end] === ')') $depth--;
                    $end++;
                }
                $body = substr($this->sql, $start, $end - $start - 1);
                if (preg_match('/' . $col . '\s+\S+.*?REFERENCES\s+' . $refTable . '/si', $body)) {
                    $found = true;
                }
            }
            // También buscar en ALTER TABLE
            if (!$found) {
                if (preg_match('/ALTER\s+TABLE\s+' . $table . '.*ADD.*FOREIGN\s+KEY\s*\(' . $col . '\)\s+REFERENCES\s+' . $refTable . '/si', $this->sql)) {
                    $found = true;
                }
            }
            $this->assertTrue($found, "FK requerida faltante: $table.$col -> $refTable");
        }
    }
}
