<?php
/**
 * =============================================================================
 * SchemaIntegrityTest.php — Test exhaustivo de integridad del esquema SQL.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Parsea sql/schema.sql y schema.sql estáticamente para
 *   validar integridad estructural sin necesidad de PostgreSQL corriendo.
 *   Cubre: tablas, columnas, tipos, primary keys, unique/check constraints,
 *   foreign keys, índices, triggers, funciones, roles, RLS policies,
 *   particiones y columnas agregadas por ALTER TABLE.
 *
 * NOTA: PHPUnit test case con parseo regex manual del SQL.
 */

require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class SchemaIntegrityTest extends PHPUnit\Framework\TestCase
{
    private string $migrationPath;
    private string $seedPath;
    private string $migrationSql;
    private string $seedSql;

    // Schema cache
    private array $tables = [];
    private array $foreignKeys = [];
    private array $indexes = [];
    private array $constraints = [];
    private array $triggers = [];
    private array $functions = [];
    private array $roles = [];
    private array $rlsPolicies = [];
    private array $alteredColumns = []; // columnas añadidas por ALTER TABLE

    public function setUp(): void
    {
        $this->migrationPath = __DIR__ . '/../../sql/schema.sql';
        $this->seedPath = __DIR__ . '/../../sql/schema.sql';

        $this->assertFileExists($this->migrationPath, 'Migration file must exist');
        $this->assertFileExists($this->seedPath, 'Seed file must exist');

        $this->migrationSql = file_get_contents($this->migrationPath);
        $this->seedSql = file_get_contents($this->seedPath);

        $this->parseSchema();
    }

    /**
     * Parsea todo el esquema SQL en estructuras de datos
     */
    private function parseSchema(): void
    {
        $sql = $this->migrationSql;

        // --- 0. Parsear ALTER TABLE ADD COLUMN para columnas dinámicas ---
        preg_match_all('/ALTER\s+TABLE\s+(\w+)\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+(\w+)\s+(\S+)/si', $sql, $alterMatches, PREG_SET_ORDER);
        foreach ($alterMatches as $m) {
            $this->alteredColumns[$m[1]][$m[2]] = $m[3];
        }
        // También sin IF NOT EXISTS
        preg_match_all('/ALTER\s+TABLE\s+(\w+)\s+ADD\s+COLUMN\s+(\w+)\s+(\S+)/si', $sql, $alterMatches2, PREG_SET_ORDER);
        foreach ($alterMatches2 as $m) {
            if (!isset($this->alteredColumns[$m[1]][$m[2]])) {
                $this->alteredColumns[$m[1]][$m[2]] = $m[3];
            }
        }

        // --- 1. Parsear tablas y columnas (incluyendo inline constraints) ---
        // Usar enfoque con conteo de paréntesis para manejar paréntesis anidados
        preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+(\w+)\s*\(/si', $sql, $tableHeaders, PREG_OFFSET_CAPTURE);
        for ($i = 0; $i < count($tableHeaders[0]); $i++) {
            $tableName = $tableHeaders[1][$i][0];
            $startPos = $tableHeaders[0][$i][1] + strlen($tableHeaders[0][$i][0]);
            
            // Encontrar el cierre del paréntesis que abre el CREATE TABLE
            $depth = 1;
            $endPos = $startPos;
            while ($depth > 0 && $endPos < strlen($sql)) {
                if ($sql[$endPos] === '(') $depth++;
                elseif ($sql[$endPos] === ')') $depth--;
                $endPos++;
            }
            $body = substr($sql, $startPos, $endPos - $startPos - 1);
            
            // Buscar PARTITION BY RANGE después del cierre
            $afterTable = substr($sql, $endPos, 200);
            $partitionCol = null;
            if (preg_match('/PARTITION\s+BY\s+RANGE\s*\(([^)]+)\)/i', $afterTable, $pm)) {
                $partitionCol = trim($pm[1]);
            }
            
            $this->tables[$tableName] = [
                'columns' => [],
                'primary_key' => null,
                'unique_constraints' => [],
                'check_constraints' => [],
                'partition_column' => $partitionCol,
            ];

            // Separar definiciones de columnas y constraints de tabla
            $lines = $this->splitTableBody($body);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                // Constraint de tabla: PRIMARY KEY, UNIQUE, CHECK, FOREIGN KEY
                if (preg_match('/^(?:CONSTRAINT\s+(\w+)\s+)?PRIMARY\s+KEY\s*\(([^)]+)\)/i', $line, $m)) {
                    $this->tables[$tableName]['primary_key'] = [
                        'name' => $m[1] ?? 'pk_' . $tableName,
                        'columns' => array_map('trim', explode(',', $m[2]))
                    ];
                } elseif (preg_match('/^CONSTRAINT\s+(\w+)\s+UNIQUE\s*\(([^)]+)\)/i', $line, $m)) {
                    $this->tables[$tableName]['unique_constraints'][] = [
                        'name' => $m[1],
                        'columns' => array_map('trim', explode(',', $m[2]))
                    ];
                } elseif (preg_match('/^CONSTRAINT\s+(\w+)\s+CHECK\s*\(([^)]+)\)/i', $line, $m)) {
                    $this->tables[$tableName]['check_constraints'][] = [
                        'name' => $m[1],
                        'expression' => $m[2]
                    ];
                } elseif (preg_match('/^CONSTRAINT\s+(\w+)\s+FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+(\w+)\s*\(([^)]+)\)/i', $line, $m)) {
                    $this->foreignKeys[] = [
                        'name' => $m[1],
                        'table' => $tableName,
                        'columns' => array_map('trim', explode(',', $m[2])),
                        'ref_table' => $m[3],
                        'ref_columns' => array_map('trim', explode(',', $m[4]))
                    ];
                } else {
                    // Definición de columna
                    $this->parseColumnDefinition($tableName, $line);
                }
            }
        }

        // --- 2. Parsear FKs inline que no están en CREATE TABLE (ej: ALTER TABLE) ---
        preg_match_all('/ALTER\s+TABLE\s+(\w+)\s+ADD\s+CONSTRAINT\s+(\w+)\s+FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+(\w+)\s*\(([^)]+)\)/si', $sql, $fkMatches, PREG_SET_ORDER);
        foreach ($fkMatches as $m) {
            $this->foreignKeys[] = [
                'name' => $m[2],
                'table' => $m[1],
                'columns' => array_map('trim', explode(',', $m[3])),
                'ref_table' => $m[4],
                'ref_columns' => array_map('trim', explode(',', $m[5]))
            ];
        }

        // --- 2b. Parsear constraints UNIQUE dentro de bloques DO $$ ---
        preg_match_all('/DO\s*\$\$\s*BEGIN\s+IF\s+NOT\s+EXISTS\s*\(SELECT\s+1\s+FROM\s+pg_constraint\s+WHERE\s+conname=\'([^\']+)\'\)\s+THEN\s+ALTER\s+TABLE\s+(\w+)\s+ADD\s+CONSTRAINT\s+\1\s+UNIQUE\s*\(([^)]+)\)/si', $sql, $doUniqueMatches, PREG_SET_ORDER);
        foreach ($doUniqueMatches as $m) {
            $this->tables[$m[2]]['unique_constraints'][] = [
                'name' => $m[1],
                'columns' => array_map('trim', explode(',', $m[3]))
            ];
        }

        // --- 3. Parsear índices ---
        // CREATE [UNIQUE] INDEX [IF NOT EXISTS] nombre ON tabla(columnas)
        preg_match_all('/CREATE\s+(UNIQUE\s+)?INDEX\s+(IF\s+NOT\s+EXISTS\s+)?(\w+)\s+ON\s+(\w+)\s*\(([^)]+)\)/si', $sql, $idxMatches, PREG_SET_ORDER);
        foreach ($idxMatches as $m) {
            $this->indexes[] = [
                'name' => $m[3],
                'table' => $m[4],
                'columns' => array_map('trim', explode(',', $m[5])),
                'unique' => !empty($m[1])
            ];
        }

        // --- 4. Parsear triggers ---
        preg_match_all('/CREATE\s+TRIGGER\s+(\w+)\s+(.*?)(?:FOR\s+EACH\s+ROW\s+)?EXECUTE\s+(?:FUNCTION|PROCEDURE)\s+(\w+)\s*\(\)/si', $sql, $trigMatches, PREG_SET_ORDER);
        foreach ($trigMatches as $m) {
            $this->triggers[] = [
                'name' => $m[1],
                'definition' => trim($m[2]),
                'function' => $m[3]
            ];
        }

        // --- 5. Parsear funciones ---
        preg_match_all('/CREATE\s+OR\s+REPLACE\s+FUNCTION\s+(\w+)\s*\(/si', $sql, $funcMatches, PREG_SET_ORDER);
        foreach ($funcMatches as $m) {
            $this->functions[] = $m[1];
        }

        // --- 6. Parsear roles ---
        preg_match_all('/CREATE\s+ROLE\s+(\w+)/si', $sql, $roleMatches, PREG_SET_ORDER);
        foreach ($roleMatches as $m) {
            $this->roles[] = $m[1];
        }

        // --- 7. Parsear RLS policies ---
        preg_match_all('/CREATE\s+POLICY\s+(\w+)\s+ON\s+(\w+)/si', $sql, $rlsMatches, PREG_SET_ORDER);
        foreach ($rlsMatches as $m) {
            $this->rlsPolicies[] = [
                'name' => $m[1],
                'table' => $m[2]
            ];
        }
    }

    /**
     * Separa el cuerpo de un CREATE TABLE en líneas individuales,
     * manejando paréntesis anidados en constraints.
     */
    private function splitTableBody(string $body): array
    {
        $lines = [];
        $current = '';
        $depth = 0;
        $chars = str_split($body);
        foreach ($chars as $char) {
            if ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $lines[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        if (trim($current) !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    /**
     * Parsea una definición de columna individual
     */
    private function parseColumnDefinition(string $tableName, string $line): void
    {
        // No es una columna si es un constraint de tabla sin nombre explícito
        if (preg_match('/^PRIMARY\s+KEY\s*\(/i', trim($line))) {
            return;
        }
        
        // Formato: nombre tipo [constraints...]
        // Ej: user_id UUID UNIQUE NOT NULL REFERENCES users(user_id)
        if (!preg_match('/^(\w+)\s+(\S+)(.*)$/s', $line, $m)) {
            return;
        }

        $colName = $m[1];
        $colType = $m[2];
        $rest = trim($m[3]);

        $column = [
            'name' => $colName,
            'type' => $colType,
            'nullable' => true,
            'unique' => false,
            'default' => null,
            'primary_key' => false,
            'references' => null,
        ];

        // PRIMARY KEY inline (solo si es para esta columna individual, no PK compuesta)
        if (preg_match('/^\w+\s+\S+.*PRIMARY\s+KEY/i', $line) && !preg_match('/PRIMARY\s+KEY\s*\(/i', $rest)) {
            $column['primary_key'] = true;
            $column['nullable'] = false;
        }

        // NOT NULL
        if (preg_match('/NOT\s+NULL/i', $rest)) {
            $column['nullable'] = false;
        }

        // UNIQUE
        if (preg_match('/\bUNIQUE\b/i', $rest)) {
            $column['unique'] = true;
        }

        // DEFAULT
        if (preg_match('/DEFAULT\s+([^\s,]+(?:\s*\([^)]*\))?)/i', $rest, $dm)) {
            $column['default'] = trim($dm[1]);
        }

        // REFERENCES inline: REFERENCES tabla(columna) [ON DELETE ...]
        if (preg_match('/REFERENCES\s+(\w+)\s*\(([^)]+)\)/i', $rest, $rm)) {
            $column['references'] = [
                'table' => $rm[1],
                'column' => trim($rm[2])
            ];
            $this->foreignKeys[] = [
                'name' => "fk_{$tableName}_{$colName}",
                'table' => $tableName,
                'columns' => [$colName],
                'ref_table' => $rm[1],
                'ref_columns' => [trim($rm[2])]
            ];
        }

        // CHECK inline
        if (preg_match('/CHECK\s*\(([^)]+)\)/i', $rest, $cm)) {
            $column['check'] = $cm[1];
        }

        $this->tables[$tableName]['columns'][$colName] = $column;
    }

    /**
     * Obtiene todas las columnas de una tabla (incluyendo ALTER TABLE)
     */
    private function getTableColumns(string $tableName): array
    {
        $cols = $this->tables[$tableName]['columns'] ?? [];
        if (isset($this->alteredColumns[$tableName])) {
            foreach ($this->alteredColumns[$tableName] as $colName => $colType) {
                if (!isset($cols[$colName])) {
                    $cols[$colName] = ['name' => $colName, 'type' => $colType, 'nullable' => true];
                }
            }
        }
        return $cols;
    }

    // ============================================================
    // TESTS
    // ============================================================

    /**
     * Verifica que todas las tablas esperadas existan
     */
    public function testRequiredTablesExist(): void
    {
        $requiredTables = [
            'schema_migrations',
            'departments',
            'municipalities',
            'schools',
            'roles',
            'permissions',
            'role_permissions',
            'users',
            'user_sessions',
            'staff_records',
            'guardians',
            'students',
            'guardian_student_relationships',
            'academic_groups',
            'student_group_assignments',
            'classrooms',
            'subjects',
            'schedules',
            'edge_devices',
            'biometric_events',
            'attendance_incidents',
            'notifications',
            'twilio_messages',
            'student_behavior_metrics',
            'student_tracking',
            'student_tracking_notes',
            'school_panic_events',
            'system_telemetry',
            'global_audit_logs',
            'contact_leads',
        ];

        foreach ($requiredTables as $table) {
            $this->assertArrayHasKey($table, $this->tables, "Tabla requerida '$table' no encontrada en el esquema");
        }
    }

    /**
     * Verifica que todas las Foreign Keys referencien tablas y columnas existentes
     */
    public function testForeignKeysReferenceValidTablesAndColumns(): void
    {
        $this->assertNotEmpty($this->foreignKeys, 'Debe haber al menos una Foreign Key definida');

        foreach ($this->foreignKeys as $fk) {
            $fkName = $fk['name'];
            $table = $fk['table'];
            $columns = $fk['columns'];
            $refTable = $fk['ref_table'];
            $refColumns = $fk['ref_columns'];

            $this->assertArrayHasKey($table, $this->tables,
                "FK '$fkName': tabla origen '$table' no existe");
            $this->assertArrayHasKey($refTable, $this->tables,
                "FK '$fkName': tabla referenciada '$refTable' no existe");

            foreach ($columns as $col) {
                $this->assertArrayHasKey($col, $this->getTableColumns($table),
                    "FK '$fkName': columna origen '$col' no existe en tabla '$table'");
            }

            foreach ($refColumns as $refCol) {
                $this->assertArrayHasKey($refCol, $this->getTableColumns($refTable),
                    "FK '$fkName': columna referenciada '$refCol' no existe en tabla '$refTable'");
            }

            foreach ($refColumns as $refCol) {
                $refColDef = $this->tables[$refTable]['columns'][$refCol] ?? null;
                if (!$refColDef) continue;
                $isPk = $refColDef['primary_key'] ?? false;
                $isUnique = $refColDef['unique'] ?? false;
                $isUniqueConstraint = false;
                foreach ($this->tables[$refTable]['unique_constraints'] ?? [] as $uc) {
                    if (in_array($refCol, $uc['columns'])) {
                        $isUniqueConstraint = true;
                        break;
                    }
                }
                $this->assertTrue($isPk || $isUnique || $isUniqueConstraint,
                    "FK '$fkName': columna referenciada '$refCol' en '$refTable' debe ser PK, UNIQUE, o tener UNIQUE constraint");
            }
        }
    }

    /**
     * Verifica que no haya ciclos de FKs (A→B→A)
     */
    public function testNoForeignKeyCycles(): void
    {
        $graph = [];
        foreach ($this->foreignKeys as $fk) {
            $from = $fk['table'];
            $to = $fk['ref_table'];
            if (!isset($graph[$from])) $graph[$from] = [];
            $graph[$from][] = $to;
        }

        $visited = [];
        $recStack = [];

        $hasCycle = function ($node, $path) use (&$graph, &$visited, &$recStack, &$hasCycle): bool {
            $visited[$node] = true;
            $recStack[$node] = true;

            foreach ($graph[$node] ?? [] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    if ($hasCycle($neighbor, $path)) return true;
                } elseif (isset($recStack[$neighbor]) && $recStack[$neighbor]) {
                    return true;
                }
            }

            $recStack[$node] = false;
            return false;
        };

        foreach (array_keys($graph) as $node) {
            if (!isset($visited[$node])) {
                $this->assertFalse($hasCycle($node, []),
                    "Ciclo de Foreign Keys detectado iniciando desde tabla '$node'");
            }
        }
    }

    /**
     * Verifica índices críticos para rendimiento
     */
    public function testCriticalIndexesExist(): void
    {
        $criticalIndexes = [
            'users' => ['school_id', 'role_id'],
            'students' => ['school_id'],
            'guardians' => ['user_id'],
            'guardian_student_relationships' => ['guardian_id', 'student_id'],
            'user_sessions' => ['user_id'],
            'staff_records' => ['school_id', 'user_id'],
            'role_permissions' => ['role_id', 'permission_id'],
            'schedules' => ['group_id', 'teacher_user_id'],
            'edge_devices' => ['school_id'],
            'notifications' => ['user_id'],
            'student_tracking' => ['school_id', 'status'],
            'student_tracking_notes' => ['tracking_id'],
            'school_panic_events' => ['school_id'],
            'biometric_events' => [],
            'attendance_incidents' => [],
            'twilio_messages' => [],
            'student_behavior_metrics' => ['student_id'],
            'global_audit_logs' => ['created_at'],
            'system_telemetry' => ['created_at', 'event_type', 'session_id'],
            'contact_leads' => ['email', 'created_at'],
        ];

        $indexMap = [];
        foreach ($this->indexes as $idx) {
            $table = $idx['table'];
            if (!isset($indexMap[$table])) $indexMap[$table] = [];
            foreach ($idx['columns'] as $col) {
                $col = preg_replace('/\s+(DESC|ASC)$/i', '', trim($col));
                $indexMap[$table][$col] = true;
            }
        }

        foreach ($this->tables as $tableName => $tableDef) {
            if (!isset($indexMap[$tableName])) $indexMap[$tableName] = [];
            if ($tableDef['primary_key']) {
                foreach ($tableDef['primary_key']['columns'] as $col) {
                    $indexMap[$tableName][trim($col)] = true;
                }
            }
            foreach ($tableDef['unique_constraints'] ?? [] as $uc) {
                foreach ($uc['columns'] as $col) {
                    $indexMap[$tableName][trim($col)] = true;
                }
            }
            foreach ($tableDef['columns'] ?? [] as $colName => $colDef) {
                if ($colDef['unique'] ?? false) {
                    $indexMap[$tableName][$colName] = true;
                }
            }
        }

        foreach ($criticalIndexes as $table => $columns) {
            foreach ($columns as $col) {
                $this->assertTrue(
                    isset($indexMap[$table][$col]),
                    "Índice crítico faltante: tabla '$table', columna '$col'"
                );
            }
        }
    }

    /**
     * Verifica que haya índices únicos donde se esperan
     */
    public function testUniqueConstraints(): void
    {
        $expectedUniques = [
            ['table' => 'users', 'columns' => ['school_id', 'document_number']],
            ['table' => 'students', 'columns' => ['school_id', 'document_number']],
            ['table' => 'guardian_student_relationships', 'columns' => ['guardian_id', 'student_id']],
            ['table' => 'guardians', 'columns' => ['user_id']],
        ];

        foreach ($expectedUniques as $expected) {
            $table = $expected['table'];
            $cols = $expected['columns'];
            $found = false;

            foreach ($this->tables[$table]['unique_constraints'] ?? [] as $uc) {
                $ucCols = array_map('trim', $uc['columns']);
                if ($ucCols === $cols) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                foreach ($this->tables[$table]['columns'] ?? [] as $colName => $colDef) {
                    if ($colDef['unique'] && in_array($colName, $cols)) {
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                foreach ($this->indexes as $idx) {
                    if ($idx['table'] === $table && $idx['unique']) {
                        $idxCols = array_map(function($c) {
                            return preg_replace('/\s+(DESC|ASC)$/i', '', trim($c));
                        }, $idx['columns']);
                        if ($idxCols === $cols) {
                            $found = true;
                            break;
                        }
                    }
                }
            }

            $this->assertTrue($found,
                "Constraint UNIQUE faltante en tabla '$table' para columnas: " . implode(', ', $cols));
        }
    }

    /**
     * Verifica NOT NULL en columnas críticas
     */
    public function testNotNullConstraintsOnCriticalColumns(): void
    {
        $requiredNotNull = [
            'users' => ['school_id', 'role_id', 'document_number', 'first_name', 'last_name', 'password_hash', 'password_salt'],
            'students' => ['school_id', 'document_number', 'first_name', 'last_name'],
            'guardians' => ['user_id', 'whatsapp_phone'],
            'guardian_student_relationships' => ['guardian_id', 'student_id'],
            'schools' => ['school_name'],
            'roles' => ['role_name'],
            'permissions' => ['permission_code'],
            'academic_groups' => ['school_id', 'group_name', 'academic_year'],
            'schedules' => ['group_id', 'classroom_id', 'teacher_user_id', 'subject_id', 'day_of_week', 'start_time', 'end_time'],
            'edge_devices' => ['school_id', 'device_name'],
            'notifications' => ['user_id', 'title', 'message'],
            'biometric_events' => ['device_id', 'event_type', 'event_timestamp'],
            'attendance_incidents' => ['student_id', 'incident_type', 'detected_at'],
            'student_behavior_metrics' => ['school_id', 'student_id'],
            'student_tracking' => ['school_id', 'student_id', 'status'],
            'student_tracking_notes' => ['tracking_id', 'user_id', 'note_text'],
            'school_panic_events' => ['school_id', 'triggered_by_user_id'],
            'global_audit_logs' => ['action_type', 'created_at'],
            'system_telemetry' => ['event_type', 'severity'],
            'contact_leads' => ['email', 'nombre'],
        ];

        foreach ($requiredNotNull as $table => $columns) {
            $this->assertArrayHasKey($table, $this->tables, "Tabla '$table' no existe");
            foreach ($columns as $col) {
                $cols = $this->getTableColumns($table);
                $this->assertArrayHasKey($col, $cols,
                    "Columna '$col' no existe en tabla '$table'");
                $isNullable = $cols[$col]['nullable'];
                $this->assertFalse($isNullable,
                    "Columna crítica '$table.$col' debe ser NOT NULL");
            }
        }
    }

    /**
     * Verifica que los triggers esperados existan
     */
    public function testRequiredTriggersExist(): void
    {
        $requiredTriggers = [
            'trg_guardians_normalize_phone',
            'trg_audit_chain',
        ];

        $triggerNames = array_column($this->triggers, 'name');

        foreach ($requiredTriggers as $trig) {
            $this->assertContains($trig, $triggerNames,
                "Trigger requerido '$trig' no encontrado");
        }
    }

    /**
     * Verifica que los triggers referencien funciones existentes
     */
    public function testTriggersReferenceValidFunctions(): void
    {
        foreach ($this->triggers as $trig) {
            $this->assertContains($trig['function'], $this->functions,
                "Trigger '{$trig['name']}' referencia función '{$trig['function']}' que no existe");
        }
    }

    /**
     * Verifica que las funciones esperadas existan
     */
    public function testRequiredFunctionsExist(): void
    {
        $requiredFunctions = [
            'fn_guardians_normalize_phone',
            'fn_audit_chain_trigger',
            'fn_calculate_audit_hash',
            'fn_validate_audit_chain',
            'fn_calculate_student_risk',
            'fn_recalculate_school_metrics',
            'get_current_school_id',
            'migration_was_executed',
            'register_migration',
        ];

        foreach ($requiredFunctions as $func) {
            $this->assertContains($func, $this->functions,
                "Función requerida '$func' no encontrada");
        }
    }

    /**
     * Verifica que las tablas con datos sensibles tengan RLS policies
     */
    public function testRlsPoliciesOnSensitiveTables(): void
    {
        $sensitiveTables = [
            'users',
            'students',
            'guardians',
            'guardian_student_relationships',
            'biometric_events',
            'attendance_incidents',
            'notifications',
            'twilio_messages',
            'student_behavior_metrics',
            'student_tracking',
            'student_tracking_notes',
            'school_panic_events',
            'global_audit_logs',
        ];

        $tablesWithPolicies = array_column($this->rlsPolicies, 'table');

        foreach ($sensitiveTables as $table) {
            $this->assertContains($table, $tablesWithPolicies,
                "Tabla sensible '$table' debe tener al menos una RLS policy");
        }
    }

    /**
     * Verifica que las tablas particionadas tengan la estructura correcta
     */
    public function testPartitionedTables(): void
    {
        $partitionedTables = [];
        foreach ($this->tables as $tableName => $tableDef) {
            if ($tableDef['partition_column'] !== null) {
                $partitionedTables[$tableName] = $tableDef['partition_column'];
            }
        }

        $expectedPartitioned = ['biometric_events', 'attendance_incidents', 'twilio_messages', 'global_audit_logs'];
        foreach ($expectedPartitioned as $table) {
            $this->assertArrayHasKey($table, $partitionedTables,
                "Tabla '$table' debería estar particionada por RANGE");
        }
    }

    /**
     * Verifica que no haya tablas duplicadas en el SQL
     */
    public function testNoDuplicateTables(): void
    {
        preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+(\w+)/i', $this->migrationSql, $matches);
        $tableNames = $matches[1];
        $duplicates = array_diff_assoc($tableNames, array_unique($tableNames));
        $this->assertEmpty($duplicates,
            'Tablas duplicadas en el SQL: ' . implode(', ', $duplicates));
    }

    /**
     * Verifica que no haya columnas duplicadas dentro de una tabla
     */
    public function testNoDuplicateColumnsInTables(): void
    {
        foreach ($this->tables as $tableName => $tableDef) {
            $colNames = array_keys($tableDef['columns']);
            $duplicates = array_diff_assoc($colNames, array_unique($colNames));
            $this->assertEmpty($duplicates,
                "Columnas duplicadas en tabla '$tableName': " . implode(', ', $duplicates));
        }
    }

    /**
     * Verifica que el seed SQL no tenga errores de sintaxis obvios
     */
    public function testSeedSqlSyntax(): void
    {
        preg_match_all('/INSERT\s+INTO\s+(\w+)/i', $this->seedSql, $insertMatches);
        $this->assertNotEmpty($insertMatches[1], 'Seed debe tener al menos un INSERT');

        preg_match_all('/INSERT\s+INTO\s+\w+\s*\([^)]+\)\s*;/i', $this->seedSql, $badInserts);
        foreach ($badInserts[0] ?? [] as $bad) {
            $this->fail("INSERT mal formado (sin VALUES): " . substr($bad, 0, 100));
        }

        preg_match_all('/VALUES\s*\((.*?)\);/si', $this->seedSql, $valueMatches);
        foreach ($valueMatches[1] ?? [] as $values) {
            $open = substr_count($values, '(');
            $close = substr_count($values, ')');
            $this->assertEquals($open, $close,
                "Paréntesis desbalanceados en VALUES: " . substr($values, 0, 100));
        }
    }

    /**
     * Verifica que el seed inserte los 8 roles requeridos
     */
    public function testSeedHasAllRoles(): void
    {
        $expectedRoles = ['RECTOR', 'COORDINATOR', 'TEACHER', 'SECRETARY', 'SECURITY', 'AUXILIARY', 'COUNSELOR', 'GUARDIAN'];
        foreach ($expectedRoles as $role) {
            $this->assertStringContainsString("'$role'", $this->seedSql,
                "Seed debe contener el rol '$role'");
        }
    }

    /**
     * Verifica que el seed tenga al menos un admin (RECTOR)
     */
    public function testSeedHasAdminUser(): void
    {
        $this->assertStringContainsString("RECTOR", $this->seedSql,
            'Seed debe contener al menos un usuario con rol RECTOR');
    }

    /**
     * Verifica que el seed tenga guardianes
     */
    public function testSeedHasGuardians(): void
    {
        $this->assertStringContainsString("INSERT INTO guardians", $this->seedSql,
            'Seed debe insertar datos en tabla guardians');
    }

    /**
     * Verifica que las columnas de auditoría existan donde se esperan
     */
    public function testAuditColumnsExist(): void
    {
        $excludedTables = ['schema_migrations', 'global_audit_logs', 'rate_limits', 'jwt_blocklist', 'verification_codes', 'internal_messages', 'twilio_message_types', 'twilio_messages', 'user_commands', 'sos_alerts', 'security_incidents', 'student_record_audit', 'report_exports', 'student_behavior_metrics'];
        foreach ($this->tables as $tableName => $tableDef) {
            if (in_array($tableName, $excludedTables)) continue;
            $this->assertArrayHasKey('created_at', $tableDef['columns'],
                "Tabla '$tableName' debería tener columna 'created_at'");
        }
    }

    /**
     * Verifica que las columnas de soft delete existan en tablas de negocio
     */
    public function testSoftDeleteColumns(): void
    {
        $tablesWithSoftDelete = ['users', 'students'];
        foreach ($tablesWithSoftDelete as $table) {
            $this->assertArrayHasKey('deleted_at', $this->tables[$table]['columns'],
                "Tabla '$table' debería tener columna 'deleted_at' para soft delete");
        }
    }

    /**
     * Verifica que no haya tipos de datos obsoletos o problemáticos
     */
    public function testNoProblematicDataTypes(): void
    {
        foreach ($this->tables as $tableName => $tableDef) {
            foreach ($tableDef['columns'] as $colName => $colDef) {
                $longTextCols = ['address', 'note_text', 'message', 'payload', 'metadata_json', 'biometric_hash', 'description', 'action_details', 'command_payload'];
                if ($colDef['type'] === 'TEXT' && !in_array($colName, $longTextCols)) {
                    if (in_array($colName, ['first_name', 'last_name', 'school_name', 'group_name', 'classroom_name', 'subject_name'])) {
                        $this->fail("Columna '$tableName.$colName' usa TEXT pero debería usar VARCHAR con límite");
                    }
                }
            }
        }
        $this->assertTrue(true);
    }

    /**
     * Verifica que las tablas tengan PRIMARY KEY
     */
    public function testAllTablesHavePrimaryKey(): void
    {
        foreach ($this->tables as $tableName => $tableDef) {
            $hasPk = $tableDef['primary_key'] !== null;
            foreach ($tableDef['columns'] as $colDef) {
                if ($colDef['primary_key']) {
                    $hasPk = true;
                    break;
                }
            }
            $this->assertTrue($hasPk, "Tabla '$tableName' debe tener PRIMARY KEY");
        }
    }

    /**
     * Verifica que las columnas UUID tengan DEFAULT uuid_generate_v4() o gen_random_uuid()
     */
    public function testUuidColumnsHaveDefault(): void
    {
        foreach ($this->tables as $tableName => $tableDef) {
            foreach ($tableDef['columns'] as $colName => $colDef) {
                if ($colDef['type'] === 'UUID' && $colDef['primary_key']) {
                    $this->assertNotNull($colDef['default'],
                        "Columna PK UUID '$tableName.$colName' debe tener DEFAULT");
                    $this->assertMatchesRegularExpression('/uuid_generate_v4|gen_random_uuid/i',
                        $colDef['default'] ?? '',
                        "DEFAULT de '$tableName.$colName' debe ser uuid_generate_v4() o gen_random_uuid()");
                }
            }
        }
    }

    /**
     * Verifica que no haya nombres de tablas/columnas reservados de PostgreSQL
     */
    public function testNoReservedKeywords(): void
    {
        $reserved = ['user', 'order', 'group', 'primary', 'foreign', 'references', 'index', 'table'];
        foreach ($this->tables as $tableName => $tableDef) {
            $this->assertNotContains($tableName, $reserved,
                "Nombre de tabla '$tableName' es palabra reservada de PostgreSQL");
            foreach (array_keys($tableDef['columns']) as $colName) {
                $this->assertNotContains($colName, $reserved,
                    "Nombre de columna '$tableName.$colName' es palabra reservada");
            }
        }
    }

    /**
     * Verifica que las tablas de particiones tengan índices en la columna de particionamiento
     */
    public function testPartitionColumnsHaveIndexes(): void
    {
        foreach ($this->tables as $tableName => $tableDef) {
            if ($tableDef['partition_column'] === null) continue;

            $partCol = $tableDef['partition_column'];
            $hasIndex = false;

            foreach ($this->indexes as $idx) {
                if ($idx['table'] === $tableName) {
                    foreach ($idx['columns'] as $col) {
                        if (strpos(trim($col), $partCol) === 0) {
                            $hasIndex = true;
                            break 2;
                        }
                    }
                }
            }

            // PK compuesta que incluye la columna de particionamiento cuenta como índice
            if (!$hasIndex && $tableDef['primary_key']) {
                foreach ($tableDef['primary_key']['columns'] as $pkCol) {
                    if (trim($pkCol) === $partCol) {
                        $hasIndex = true;
                        break;
                    }
                }
            }

            $this->assertTrue($hasIndex,
                "Tabla particionada '$tableName' debe tener índice en columna de particionamiento '$partCol'");
        }
    }

    /**
     * Verifica que el SQL no tenga sentencias peligrosas
     */
    public function testNoDangerousStatements(): void
    {
        $dangerous = ['DROP DATABASE', 'DROP SCHEMA', 'TRUNCATE TABLE'];
        foreach ($dangerous as $stmt) {
            $this->assertStringNotContainsStringIgnoringCase($stmt, $this->migrationSql,
                "SQL de migración no debe contener '$stmt'");
        }
    }

    /**
     * Verifica consistencia entre migración y seed
     */
    public function testSeedReferencesExistingTables(): void
    {
        preg_match_all('/INSERT\s+INTO\s+(\w+)/i', $this->seedSql, $matches);
        $seedTables = array_unique($matches[1]);

        foreach ($seedTables as $table) {
            $this->assertArrayHasKey($table, $this->tables,
                "Seed referencia tabla '$table' que no existe en la migración");
        }
    }

    /**
     * Verifica que no haya columnas en el seed que no existan en la migración
     */
    public function testSeedColumnsExistInMigration(): void
    {
        preg_match_all('/INSERT\s+INTO\s+(\w+)\s*\(([^)]+)\)/i', $this->seedSql, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $table = $m[1];
            $cols = array_map('trim', explode(',', $m[2]));

            $allCols = $this->getTableColumns($table);
            if (empty($allCols)) continue;

            foreach ($cols as $col) {
                $col = trim($col, " \t\n\r\0\x0B'\"");
                $this->assertArrayHasKey($col, $allCols,
                    "Seed inserta columna '$col' en tabla '$table' que no existe en migración");
            }
        }
    }

    /**
     * Verifica que el SQL tenga extensiones necesarias
     */
    public function testRequiredExtensions(): void
    {
        $required = ['uuid-ossp', 'pgcrypto'];
        foreach ($required as $ext) {
            $found = preg_match('/CREATE\s+EXTENSION\s+IF\s+NOT\s+EXISTS\s+["\']?' . preg_quote($ext, '/') . '["\']?/i', $this->migrationSql);
            $this->assertSame(1, $found, "Debe incluir extensión '$ext'");
        }
    }

    /**
     * Verifica que haya comentarios/documentación en tablas críticas
     */
    public function testDocumentationComments(): void
    {
        $criticalTables = ['users', 'students', 'guardians', 'biometric_events', 'school_panic_events'];
        foreach ($criticalTables as $table) {
            $pattern = '/COMMENT\s+ON\s+(TABLE|COLUMN)\s+' . preg_quote($table, '/') . '/i';
            $this->assertMatchesRegularExpression($pattern, $this->migrationSql,
                "Tabla crítica '$table' debería tener COMMENT ON para documentación");
        }
    }
}
