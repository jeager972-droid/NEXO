<?php
/**
 * =============================================================================
 * routes/school_config.php — Configuración de horarios institucionales.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone endpoints para el onboarding obligatorio de horarios y la edición
 * posterior de la configuración institucional. Soporta múltiples jornadas
 * (mañana, tarde, noche, etc.) por institución, cada una con sus propios
 * horarios, recesos y bloques.
 *
 *   - GET  /school/config            : Retorna la configuración actual (array
 *                                      de jornadas) + estado de onboarding.
 *   - POST /school/onboarding        : Crea/actualiza la configuración de
 *                                      horarios para todas las jornadas y marca
 *                                      onboarding_completed=TRUE.
 *                                      Solo RECTOR y COORDINATOR.
 *   - PUT  /school/config            : Actualiza la configuración (post-onboarding).
 *   - GET  /school/time-blocks       : Lista los bloques horarios (todas las jornadas).
 *   - POST /school/time-blocks       : Reemplaza los bloques horarios (bulk).
 *
 * DEPENDENCIAS
 * ------------
 *   - _auth_middleware.php : autenticación, roles, permisos.
 *   - $conn : conexión PDO.
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

// ============================================================================
// GET /school/config — Estado de configuración y onboarding
// ============================================================================
if ($cleanPath === '/school/config' && $method === 'GET') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];

    if (!$schoolId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID de institución requerido']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Configuración por jornada (puede haber múltiples filas)
        $stmt = $conn->prepare("SELECT * FROM school_schedule_config WHERE school_id = ? ORDER BY work_shift");
        $stmt->execute([$schoolId]);
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Bloques horarios (todas las jornadas)
        $blocksStmt = $conn->prepare("
            SELECT work_shift, block_number, block_name, start_time, end_time
            FROM school_time_blocks
            WHERE school_id = ?
            ORDER BY work_shift, block_number
        ");
        $blocksStmt->execute([$schoolId]);
        $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

        // Agrupar bloques por work_shift
        $blocksByShift = [];
        foreach ($blocks as $b) {
            $shift = $b['work_shift'];
            if (!isset($blocksByShift[$shift])) $blocksByShift[$shift] = [];
            $blocksByShift[$shift][] = [
                'block_number' => (int)$b['block_number'],
                'block_name' => $b['block_name'],
                'start_time' => substr($b['start_time'], 0, 5),
                'end_time' => substr($b['end_time'], 0, 5),
            ];
        }

        // Formatear configs
        $formattedConfigs = array_map(function($c) use ($blocksByShift) {
            return [
                'work_shift' => $c['work_shift'],
                'rotates_classrooms' => (bool)$c['rotates_classrooms'],
                'entry_time' => $c['entry_time'] ? substr($c['entry_time'], 0, 5) : null,
                'exit_time' => $c['exit_time'] ? substr($c['exit_time'], 0, 5) : null,
                'recess_start_time' => $c['recess_start_time'] ? substr($c['recess_start_time'], 0, 5) : null,
                'recess_end_time' => $c['recess_end_time'] ? substr($c['recess_end_time'], 0, 5) : null,
                'time_blocks' => $blocksByShift[$c['work_shift']] ?? [],
            ];
        }, $configs);

        $response = [
            'status' => 'ok',
            'onboarding_completed' => !empty($configs) && (bool)$configs[0]['onboarding_completed'],
            'risk_config_completed' => false, // se actualiza abajo
            'configs' => $formattedConfigs,
            'config' => $formattedConfigs[0] ?? null, // retrocompatibilidad
            'time_blocks' => $blocksByShift,
        ];

        // Incluir estado de configuración de riesgo
        try {
            $riskStmt = $conn->prepare("SELECT risk_config_completed FROM schools WHERE school_id = ?");
            $riskStmt->execute([$schoolId]);
            $riskRow = $riskStmt->fetch(PDO::FETCH_ASSOC);
            $response['risk_config_completed'] = $riskRow ? (bool)$riskRow['risk_config_completed'] : false;
        } catch (Exception $riskEx) {
            // Columna puede no existir en BDs no migradas — default false
        }

        echo json_encode($response);
    } catch (Exception $e) {
        securityLog('SCHOOL_CONFIG_GET_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener configuración', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /school/onboarding — Onboarding obligatorio de horarios (multi-jornada)
// ============================================================================
if ($cleanPath === '/school/onboarding' && $method === 'POST') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $role = strtoupper($authUser['role'] ?? '');

    // Solo RECTOR y COORDINATOR pueden completar el onboarding
    if (!in_array($role, ['RECTOR', 'COORDINATOR'])) {
        securityLog('ONBOARDING_UNAUTHORIZED', "User: $userId, Role: $role");
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Solo rector y coordinador pueden configurar los horarios']));
    }

    // Aceptar formato nuevo (jornadas array) o formato antiguo (campos planos)
    $jornadas = $input['jornadas'] ?? null;

    if (!$jornadas) {
        // Formato legacy: convertir a array de una sola jornada
        $workShift = trim((string)($input['work_shift'] ?? 'mañana'));
        $jornadas = [[
            'work_shift' => $workShift,
            'rotates_classrooms' => (bool)($input['rotates_classrooms'] ?? false),
            'entry_time' => trim((string)($input['entry_time'] ?? '')),
            'exit_time' => trim((string)($input['exit_time'] ?? '')),
            'recess_start_time' => trim((string)($input['recess_start_time'] ?? '')),
            'recess_end_time' => trim((string)($input['recess_end_time'] ?? '')),
            'time_blocks' => $input['time_blocks'] ?? [],
        ]];
    }

    if (empty($jornadas) || !is_array($jornadas)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Debe enviar al menos una jornada']));
    }

    $validShifts = ['mañana', 'tarde', 'noche', 'completa'];

    // Validar cada jornada
    foreach ($jornadas as $i => $j) {
        $shift = trim((string)($j['work_shift'] ?? ''));
        $entryTime = trim((string)($j['entry_time'] ?? ''));
        $exitTime = trim((string)($j['exit_time'] ?? ''));
        $recessStart = trim((string)($j['recess_start_time'] ?? ''));
        $recessEnd = trim((string)($j['recess_end_time'] ?? ''));
        $rotates = (bool)($j['rotates_classrooms'] ?? false);
        $blocks = $j['time_blocks'] ?? [];

        if (!in_array($shift, $validShifts)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => "Jornada inválida en posición $i: $shift. Debe ser: " . implode(', ', $validShifts)]));
        }
        if (empty($entryTime) || empty($exitTime)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => "Hora de entrada y salida son obligatorias para jornada: $shift"]));
        }
        foreach ([$entryTime, $exitTime] as $t) {
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => "Formato de hora inválido en jornada $shift. Use HH:MM"]));
            }
        }
        if (($recessStart && !$recessEnd) || (!$recessStart && $recessEnd)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => "Debe especificar inicio y fin del receso, o ninguno, en jornada: $shift"]));
        }
        if ($rotates) {
            if (empty($blocks) || !is_array($blocks)) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => "Si la jornada $shift rota de salones, debe definir los bloques horarios"]));
            }
            foreach ($blocks as $b) {
                if (empty($b['start_time']) || empty($b['end_time'])) {
                    http_response_code(400);
                    exit(json_encode(['status' => 'error', 'message' => "Cada bloque horario en jornada $shift debe tener hora de inicio y fin"]));
                }
            }
        }
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $conn->exec("BEGIN");
        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
        $conn->exec("SELECT set_config('app.current_role', " . $conn->quote($role) . ", true)");

        // Limpiar configuración y bloques existentes
        $conn->prepare("DELETE FROM school_time_blocks WHERE school_id = ?")->execute([$schoolId]);
        $conn->prepare("DELETE FROM school_schedule_config WHERE school_id = ?")->execute([$schoolId]);

        // Insertar cada jornada
        // Nota: rotates_classrooms se pasa como literal SQL (TRUE/FALSE) porque
        // PDO_PGSQL convierte false de PHP a string vacío, y ''::boolean falla.
        $blockStmt = $conn->prepare("
            INSERT INTO school_time_blocks (school_id, work_shift, block_number, block_name, start_time, end_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($jornadas as $j) {
            $shift = trim((string)$j['work_shift']);
            $rotates = (bool)($j['rotates_classrooms'] ?? false);
            $entryTime = trim((string)$j['entry_time']);
            $exitTime = trim((string)$j['exit_time']);
            $recessStart = trim((string)($j['recess_start_time'] ?? ''));
            $recessEnd = trim((string)($j['recess_end_time'] ?? ''));
            $blocks = $j['time_blocks'] ?? [];

            $upsertStmt = $conn->prepare("
                INSERT INTO school_schedule_config
                    (school_id, rotates_classrooms, work_shift, entry_time, exit_time,
                     recess_start_time, recess_end_time, onboarding_completed,
                     onboarding_completed_by, onboarding_completed_at, updated_at)
                VALUES (?, " . ($rotates ? 'TRUE' : 'FALSE') . ", ?, ?, ?, ?, ?, TRUE, ?, NOW(), NOW())
            ");
            $upsertStmt->execute([
                $schoolId, $shift, $entryTime, $exitTime,
                $recessStart ?: null, $recessEnd ?: null,
                $userId
            ]);

            if ($rotates && !empty($blocks)) {
                foreach ($blocks as $i => $b) {
                    $blockNum = (int)($b['block_number'] ?? ($i + 1));
                    $blockStmt->execute([
                        $schoolId,
                        $shift,
                        $blockNum,
                        $b['block_name'] ?? null,
                        $b['start_time'],
                        $b['end_time']
                    ]);
                }
            }
        }

        // Marcar onboarding completado en schools
        $schoolStmt = $conn->prepare("UPDATE schools SET onboarding_completed = TRUE WHERE school_id = ?");
        $schoolStmt->execute([$schoolId]);

        $conn->exec("COMMIT");

        $shiftNames = array_map(fn($j) => $j['work_shift'], $jornadas);
        securityLog('ONBOARDING_COMPLETED', "School: $schoolId, By: $userId ($role), Jornadas: " . implode(', ', $shiftNames));

        echo json_encode([
            'status' => 'ok',
            'message' => 'Configuración de horarios guardada correctamente',
            'onboarding_completed' => true,
            'jornadas' => $shiftNames,
        ]);
    } catch (Exception $e) {
        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
        securityLog('ONBOARDING_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar la configuración. Contacte al administrador.', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// PUT /school/config — Actualizar configuración (post-onboarding, multi-jornada)
// ============================================================================
if ($cleanPath === '/school/config' && $method === 'PUT') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $role = strtoupper($authUser['role'] ?? '');

    if (!in_array($role, ['RECTOR', 'COORDINATOR'])) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Solo rector y coordinador pueden editar la configuración']));
    }

    // Aceptar formato nuevo (jornadas array) o campos individuales
    $jornadas = $input['jornadas'] ?? null;

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $conn->exec("BEGIN");
        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
        $conn->exec("SELECT set_config('app.current_role', " . $conn->quote($role) . ", true)");

        if ($jornadas && is_array($jornadas)) {
            // Reemplazar todas las jornadas
            $conn->prepare("DELETE FROM school_time_blocks WHERE school_id = ?")->execute([$schoolId]);
            $conn->prepare("DELETE FROM school_schedule_config WHERE school_id = ?")->execute([$schoolId]);

            $blockStmt = $conn->prepare("
                INSERT INTO school_time_blocks (school_id, work_shift, block_number, block_name, start_time, end_time)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($jornadas as $j) {
                $shift = trim((string)$j['work_shift']);
                $rotates = (bool)($j['rotates_classrooms'] ?? false);
                $upsertStmt = $conn->prepare("
                    INSERT INTO school_schedule_config
                        (school_id, rotates_classrooms, work_shift, entry_time, exit_time,
                         recess_start_time, recess_end_time, onboarding_completed,
                         onboarding_completed_by, onboarding_completed_at, updated_at)
                    VALUES (?, " . ($rotates ? 'TRUE' : 'FALSE') . ", ?, ?, ?, ?, ?, TRUE, ?, NOW(), NOW())
                ");
                $upsertStmt->execute([
                    $schoolId,
                    $shift,
                    trim((string)$j['entry_time']),
                    trim((string)$j['exit_time']),
                    !empty($j['recess_start_time']) ? trim((string)$j['recess_start_time']) : null,
                    !empty($j['recess_end_time']) ? trim((string)$j['recess_end_time']) : null,
                    $userId
                ]);

                if (!empty($j['rotates_classrooms']) && !empty($j['time_blocks'])) {
                    foreach ($j['time_blocks'] as $i => $b) {
                        $blockStmt->execute([
                            $schoolId,
                            $shift,
                            (int)($b['block_number'] ?? ($i + 1)),
                            $b['block_name'] ?? null,
                            $b['start_time'],
                            $b['end_time']
                        ]);
                    }
                }
            }
        } else {
            // Formato legacy: actualizar campos individuales de la primera jornada
            $sets = ['updated_at = NOW()'];
            $params = [];

            $fieldMap = [
                'entry_time' => isset($input['entry_time']) ? trim((string)$input['entry_time']) : null,
                'exit_time' => isset($input['exit_time']) ? trim((string)$input['exit_time']) : null,
                'recess_start_time' => isset($input['recess_start_time']) ? trim((string)$input['recess_start_time']) : null,
                'recess_end_time' => isset($input['recess_end_time']) ? trim((string)$input['recess_end_time']) : null,
            ];

            // rotates_classrooms se maneja aparte: literal SQL TRUE/FALSE
            // (PDO convierte false de PHP a string vacío, que falla como boolean)
            if (isset($input['rotates_classrooms'])) {
                $sets[] = "rotates_classrooms = " . ((bool)$input['rotates_classrooms'] ? 'TRUE' : 'FALSE');
            }

            foreach ($fieldMap as $col => $val) {
                if ($val !== null) {
                    $sets[] = "$col = ?";
                    $params[] = $val;
                }
            }

            $params[] = $schoolId;
            $updateStmt = $conn->prepare("UPDATE school_schedule_config SET " . implode(', ', $sets) . " WHERE school_id = ?");
            $updateStmt->execute($params);
        }

        $conn->exec("COMMIT");

        echo json_encode(['status' => 'ok', 'message' => 'Configuración actualizada correctamente']);
    } catch (Exception $e) {
        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
        securityLog('SCHOOL_CONFIG_UPDATE_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al actualizar configuración', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// GET /school/time-blocks — Lista los bloques horarios (todas las jornadas)
// ============================================================================
if ($cleanPath === '/school/time-blocks' && $method === 'GET') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $stmt = $conn->prepare("
            SELECT work_shift, block_number, block_name, start_time, end_time
            FROM school_time_blocks
            WHERE school_id = ?
            ORDER BY work_shift, block_number
        ");
        $stmt->execute([$schoolId]);
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($b) {
            return [
                'work_shift' => $b['work_shift'],
                'block_number' => (int)$b['block_number'],
                'block_name' => $b['block_name'],
                'start_time' => substr($b['start_time'], 0, 5),
                'end_time' => substr($b['end_time'], 0, 5),
            ];
        }, $blocks);

        echo json_encode(['status' => 'ok', 'time_blocks' => $formatted]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener bloques horarios', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /school/time-blocks — Reemplazar bloques horarios (bulk, multi-jornada)
// ============================================================================
if ($cleanPath === '/school/time-blocks' && $method === 'POST') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $role = strtoupper($authUser['role'] ?? '');

    if (!in_array($role, ['RECTOR', 'COORDINATOR'])) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Solo rector y coordinador pueden editar bloques horarios']));
    }

    $timeBlocks = $input['time_blocks'] ?? [];
    if (empty($timeBlocks) || !is_array($timeBlocks)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Debe enviar al menos un bloque horario']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $conn->exec("BEGIN");
        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
        $conn->exec("SELECT set_config('app.current_role', " . $conn->quote($role) . ", true)");

        // Si viene work_shift en el payload, solo limpiar esa jornada
        $shiftFilter = isset($input['work_shift']) ? trim((string)$input['work_shift']) : null;

        if ($shiftFilter) {
            $delStmt = $conn->prepare("DELETE FROM school_time_blocks WHERE school_id = ? AND work_shift = ?");
            $delStmt->execute([$schoolId, $shiftFilter]);
        } else {
            $delStmt = $conn->prepare("DELETE FROM school_time_blocks WHERE school_id = ?");
            $delStmt->execute([$schoolId]);
        }

        $blockStmt = $conn->prepare("
            INSERT INTO school_time_blocks (school_id, work_shift, block_number, block_name, start_time, end_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($timeBlocks as $i => $b) {
            $blockNum = (int)($b['block_number'] ?? ($i + 1));
            $shift = isset($b['work_shift']) ? trim((string)$b['work_shift']) : ($shiftFilter ?? 'mañana');
            $blockStmt->execute([
                $schoolId,
                $shift,
                $blockNum,
                $b['block_name'] ?? null,
                $b['start_time'],
                $b['end_time']
            ]);
        }

        $conn->exec("COMMIT");

        echo json_encode(['status' => 'ok', 'message' => 'Bloques horarios guardados correctamente']);
    } catch (Exception $e) {
        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
        securityLog('TIME_BLOCKS_UPDATE_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar bloques horarios', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// GET /school/groups-onboarding — Estado del onboarding de grupos académicos
// ============================================================================
if ($cleanPath === '/school/groups-onboarding' && $method === 'GET') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];

    if (!$schoolId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID de institución requerido']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Verificar si las columnas existen antes de consultarlas
        $colCheck = $conn->prepare("
            SELECT column_name FROM information_schema.columns
            WHERE table_name = 'schools' AND column_name IN ('groups_onboarding_completed', 'groups_onboarding_year')
        ");
        $colCheck->execute();
        $existingCols = $colCheck->fetchAll(PDO::FETCH_COLUMN);

        if (count($existingCols) < 2) {
            // Las columnas no existen — el onboarding de grupos no se ha aplicado
            // Devolver needs_onboarding=true para forzar el flujo
            $currentYear = (int)date('Y');
            echo json_encode([
                'status' => 'ok',
                'onboarding_completed' => false,
                'onboarding_year' => null,
                'current_year' => $currentYear,
                'needs_onboarding' => true,
            ]);
            exit;
        }

        $stmt = $conn->prepare("SELECT groups_onboarding_completed, groups_onboarding_year FROM schools WHERE school_id = ?");
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$school) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Institución no encontrada']));
        }

        $onboardingCompleted = (bool)$school['groups_onboarding_completed'];
        $onboardingYear = $school['groups_onboarding_year'] ? (int)$school['groups_onboarding_year'] : null;
        $currentYear = (int)date('Y');

        $needsOnboarding = !$onboardingCompleted || $onboardingYear !== $currentYear;

        // Grupos existentes del año actual (con work_shift y docentes asignados)
        // para que el frontend pueda precargar el wizard al re-hacer onboarding.
        $groups = [];
        $groupsStmt = $conn->prepare("
            SELECT ag.group_id, ag.group_name, ag.grade_level, ag.work_shift
            FROM academic_groups ag
            WHERE ag.school_id = ? AND ag.academic_year = ?
            ORDER BY ag.grade_level::INT, ag.group_name
        ");
        $groupsStmt->execute([$schoolId, $currentYear]);
        $groupRows = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Docentes asignados por grupo (teacher_group_access)
        $tgaByGroup = [];
        $tgaStmt = $conn->prepare("
            SELECT tga.group_id, tga.teacher_user_id, u.first_name, u.last_name, u.email
            FROM teacher_group_access tga
            JOIN users u ON u.user_id = tga.teacher_user_id
            WHERE tga.school_id = ? AND tga.academic_year = ? AND u.deleted_at IS NULL
        ");
        $tgaStmt->execute([$schoolId, $currentYear]);
        foreach ($tgaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tgaByGroup[$row['group_id']][] = [
                'user_id'    => $row['teacher_user_id'],
                'name'       => trim($row['first_name'] . ' ' . $row['last_name']),
                'email'      => $row['email'],
            ];
        }

        foreach ($groupRows as $row) {
            $groups[] = [
                'group_id'   => $row['group_id'],
                'group_name' => $row['group_name'],
                'grade_level' => $row['grade_level'],
                'work_shift' => $row['work_shift'],
                'teachers'   => $tgaByGroup[$row['group_id']] ?? [],
            ];
        }

        // Lista de usuarios TEACHER/COUNSELOR de la escuela (para el paso 4)
        $teachersStmt = $conn->prepare("
            SELECT u.user_id, u.first_name, u.last_name, u.email, u.work_shift
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.school_id = ? AND u.deleted_at IS NULL AND u.active = TRUE
              AND UPPER(r.role_name) IN ('TEACHER', 'COUNSELOR')
            ORDER BY u.last_name, u.first_name
        ");
        $teachersStmt->execute([$schoolId]);
        $teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'ok',
            'onboarding_completed' => $onboardingCompleted,
            'onboarding_year' => $onboardingYear,
            'current_year' => $currentYear,
            'needs_onboarding' => $needsOnboarding,
            'groups' => $groups,
            'teachers' => $teachers,
        ]);
    } catch (Exception $e) {
        securityLog('GROUPS_ONBOARDING_GET_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener el estado de configuración de grupos', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /school/groups-onboarding — Onboarding de grupos académicos (solo RECTOR)
//
// FIX bugs #1/#2/#3/#4 (2026-35):
//   - Usa SAVEPOINT sobre la transacción que requireAuth() ya abrió (no exec BEGIN/COMMIT).
//   - Captura (student_id, grade_level) ANTES de borrar para repoblar
//     student_group_assignments por grade_level.
//   - Acepta grade_shifts (jornada por grado) y teacher_assignments (docentes por grupo).
//   - Inserta grupos con work_shift.
//   - Inserta teacher_group_access (acceso docente→grupo, independiente de schedules).
//   - Valida que cada grupo tenga ≥1 docente asignado (obligatorio).
// ============================================================================
if ($cleanPath === '/school/groups-onboarding' && $method === 'POST') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $role = strtoupper($authUser['role'] ?? '');

    if ($role !== 'RECTOR') {
        securityLog('GROUPS_ONBOARDING_UNAUTHORIZED', "User: $userId, Role: $role");
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Solo el rector puede configurar los grupos académicos']));
    }

    $grades = $input['grades'] ?? [];
    $nomenclature = trim((string)($input['nomenclature'] ?? ''));
    $nomenclatureSeparator = trim((string)($input['nomenclature_separator'] ?? '-'));
    $groupsPerGrade = $input['groups_per_grade'] ?? [];
    $gradeShifts = $input['grade_shifts'] ?? [];        // { "6": "mañana", "7": "tarde", ... }
    $teacherAssignments = $input['teacher_assignments'] ?? []; // { "6A": ["user_id1","user_id2"], ... }

    if (empty($grades) || !is_array($grades)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Debe especificar al menos un grado']));
    }

    $validNomenclatures = ['alphabetic', 'numeric', 'other'];
    if (!in_array($nomenclature, $validNomenclatures)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Nomenclatura inválida. Debe ser: ' . implode(', ', $validNomenclatures)]));
    }

    $validShifts = ['mañana', 'tarde', 'noche', 'completa'];
    foreach ($grades as $grade) {
        $grade = trim((string)$grade);
        if ($grade === '') continue;
        $shift = trim((string)($gradeShifts[$grade] ?? ''));
        if ($shift === '' || !in_array($shift, $validShifts, true)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => "Debe asignar una jornada válida al grado $grade. Opciones: " . implode(', ', $validShifts)]));
        }
    }

    $currentYear = (int)date('Y');

    // Generar nombres de grupos según nomenclatura
    $groupsToInsert = [];
    foreach ($grades as $grade) {
        $grade = trim((string)$grade);
        if ($grade === '') continue;

        $count = (int)($groupsPerGrade[$grade] ?? 0);
        if ($count <= 0) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => "Debe especificar el número de grupos para el grado $grade"]));
        }

        $shift = trim((string)$gradeShifts[$grade]);

        for ($i = 0; $i < $count; $i++) {
            if ($nomenclature === 'alphabetic') {
                $letter = chr(65 + $i); // A=65
                if ($i > 25) {
                    http_response_code(400);
                    exit(json_encode(['status' => 'error', 'message' => "La nomenclatura alfabética soporta máximo 26 grupos por grado. El grado $grade tiene $count."]));
                }
                $groupName = $grade . $letter;
            } elseif ($nomenclature === 'numeric') {
                $groupName = $grade . '-' . ($i + 1);
            } else { // other
                $groupName = $grade . $nomenclatureSeparator . ($i + 1);
            }

            $groupsToInsert[] = [
                'grade_level' => $grade,
                'group_name'  => $groupName,
                'work_shift'  => $shift,
            ];
        }
    }

    if (empty($groupsToInsert)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'No se generaron grupos con la configuración proporcionada']));
    }

    // Validar que cada grupo tenga al menos un docente asignado (obligatorio)
    $missingTeachers = [];
    foreach ($groupsToInsert as $g) {
        $assigned = $teacherAssignments[$g['group_name']] ?? null;
        if (!is_array($assigned) || count($assigned) === 0) {
            $missingTeachers[] = $g['group_name'];
        }
    }
    if (!empty($missingTeachers)) {
        http_response_code(400);
        exit(json_encode([
            'status' => 'error',
            'message' => 'Cada grupo debe tener al menos un docente asignado. Faltan docentes en: ' . implode(', ', $missingTeachers),
            'missing_teachers' => $missingTeachers,
        ]));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // FIX Bug #4: requireAuth() ya abrió beginTransaction() y seteó RLS context.
        // Usamos SAVEPOINT para rollback parcial sin cerrar la transacción del middleware.
        $useSavepoint = $conn->inTransaction();
        $sp = 'sp_groups_onboarding';
        if ($useSavepoint) {
            $conn->exec("SAVEPOINT $sp");
        } else {
            $conn->beginTransaction();
            $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
            $conn->exec("SELECT set_config('app.current_role', " . $conn->quote($role) . ", true)");
        }

        // ──────────────────────────────────────────────────────────────────
        // 1. Capturar (student_id, grade_level) ANTES de borrar para repoblar.
        //    Si students.grade_level está vacío, inferirlo de la asignación
        //    actual vía academic_groups.grade_level.
        // ──────────────────────────────────────────────────────────────────
        $oldGroupsStmt = $conn->prepare("SELECT group_id FROM academic_groups WHERE school_id = ? AND academic_year = ?");
        $oldGroupsStmt->execute([$schoolId, $currentYear]);
        $oldGroupIds = $oldGroupsStmt->fetchAll(PDO::FETCH_COLUMN);

        // Snapshot de grados actuales de estudiantes (student_id => grade_level)
        $studentGrades = [];
        if (!empty($oldGroupIds)) {
            $placeholders = implode(',', array_fill(0, count($oldGroupIds), '?'));
            $snapStmt = $conn->prepare("
                SELECT sga.student_id, COALESCE(s.grade_level, ag.grade_level) AS grade_level
                FROM student_group_assignments sga
                JOIN academic_groups ag ON ag.group_id = sga.group_id
                JOIN students s ON s.student_id = sga.student_id
                WHERE sga.group_id IN ($placeholders) AND sga.active = TRUE AND s.deleted_at IS NULL
            ");
            $snapStmt->execute($oldGroupIds);
            foreach ($snapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $studentGrades[$row['student_id']] = $row['grade_level'];
            }
        }

        // Helper: ejecutar sentencia opcional con SAVEPOINT anidado
        $execOptional = function($sql, $params) use ($conn) {
            $conn->exec("SAVEPOINT cleanup_op");
            try {
                $conn->prepare($sql)->execute($params);
            } catch (Exception $e) {
                $conn->exec("ROLLBACK TO SAVEPOINT cleanup_op");
            }
        };

        if (!empty($oldGroupIds)) {
            $placeholders = implode(',', array_fill(0, count($oldGroupIds), '?'));

            // Borrar dependencias en orden (FK constraints)
            // 1. teacher_group_access (acceso docente→grupo del año actual)
            $execOptional("DELETE FROM teacher_group_access WHERE group_id IN ($placeholders)", $oldGroupIds);

            // 2. student_group_assignments
            $execOptional("DELETE FROM student_group_assignments WHERE group_id IN ($placeholders)", $oldGroupIds);

            // 3. schedules
            $execOptional("DELETE FROM schedules WHERE group_id IN ($placeholders)", $oldGroupIds);

            // 4. daily_schedule_config
            $execOptional("DELETE FROM daily_schedule_config WHERE group_id IN ($placeholders)", $oldGroupIds);

            // 5. edge_devices: desvincular group_id (no borrar el device)
            $execOptional("UPDATE edge_devices SET group_id = NULL WHERE group_id IN ($placeholders)", $oldGroupIds);

            // 6. attendance_incidents: desvincular group_id (no borrar incidentes)
            $execOptional("UPDATE attendance_incidents SET group_id = NULL WHERE group_id IN ($placeholders)", $oldGroupIds);
        }

        // Ahora sí borrar los grupos del año actual
        $conn->prepare("DELETE FROM academic_groups WHERE school_id = ? AND academic_year = ?")
            ->execute([$schoolId, $currentYear]);

        // ──────────────────────────────────────────────────────────────────
        // 2. Insertar nuevos grupos con work_shift
        // ──────────────────────────────────────────────────────────────────
        $insertStmt = $conn->prepare("
            INSERT INTO academic_groups (school_id, group_name, grade_level, academic_year, work_shift)
            VALUES (?, ?, ?, ?, ?)
        ");
        $groupIdByName = [];
        foreach ($groupsToInsert as $g) {
            $insertStmt->execute([$schoolId, $g['group_name'], $g['grade_level'], $currentYear, $g['work_shift']]);
            $gidStmt = $conn->prepare("SELECT group_id FROM academic_groups WHERE school_id = ? AND group_name = ? AND academic_year = ?");
            $gidStmt->execute([$schoolId, $g['group_name'], $currentYear]);
            $groupIdByName[$g['group_name']] = $gidStmt->fetchColumn();
        }

        // ──────────────────────────────────────────────────────────────────
        // 3. Repoblar student_group_assignments por grade_level.
        //    Para cada estudiante del snapshot, asignarlo al primer grupo nuevo
        //    del mismo grado (si hay varios, al primero; el rector puede
        //    reasignar manualmente después). También sincroniza students.grade_level.
        // ──────────────────────────────────────────────────────────────────
        if (!empty($studentGrades)) {
            // Mapa grade_level => [group_ids nuevos]
            $groupsByGrade = [];
            foreach ($groupsToInsert as $g) {
                $gid = $groupIdByName[$g['group_name']] ?? null;
                if ($gid) {
                    $groupsByGrade[$g['grade_level']][] = $gid;
                }
            }

            $assignStmt = $conn->prepare("
                INSERT INTO student_group_assignments (student_id, group_id, active, start_date)
                VALUES (?, ?, TRUE, CURRENT_DATE)
                ON CONFLICT (student_id, group_id) DO UPDATE SET active = TRUE, start_date = CURRENT_DATE
            ");
            $updateGradeStmt = $conn->prepare("UPDATE students SET grade_level = ? WHERE student_id = ?");

            foreach ($studentGrades as $studentId => $gradeLevel) {
                if (!isset($groupsByGrade[$gradeLevel]) || empty($groupsByGrade[$gradeLevel])) continue;
                $targetGroupId = $groupsByGrade[$gradeLevel][0];
                $assignStmt->execute([$studentId, $targetGroupId]);
                $updateGradeStmt->execute([$gradeLevel, $studentId]);
            }
        }

        // ──────────────────────────────────────────────────────────────────
        // 4. Insertar teacher_group_access (acceso docente→grupo)
        // ──────────────────────────────────────────────────────────────────
        $tgaStmt = $conn->prepare("
            INSERT INTO teacher_group_access (school_id, group_id, teacher_user_id, work_shift, academic_year)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (teacher_user_id, group_id, academic_year) DO UPDATE SET work_shift = EXCLUDED.work_shift
        ");
        foreach ($groupsToInsert as $g) {
            $gid = $groupIdByName[$g['group_name']] ?? null;
            if (!$gid) continue;
            $assigned = $teacherAssignments[$g['group_name']] ?? [];
            foreach ($assigned as $teacherUserId) {
                $teacherUserId = trim((string)$teacherUserId);
                if ($teacherUserId === '') continue;
                $tgaStmt->execute([$schoolId, $gid, $teacherUserId, $g['work_shift'], $currentYear]);
            }
        }

        // Marcar onboarding completado
        $conn->prepare("UPDATE schools SET groups_onboarding_completed = TRUE, groups_onboarding_year = ? WHERE school_id = ?")
            ->execute([$currentYear, $schoolId]);

        // ──────────────────────────────────────────────────────────────────
        // 5. Sensores (igual que antes)
        // ──────────────────────────────────────────────────────────────────
        // Borrar sensores viejos (de grupos de años anteriores o genéricos)
        // Solo borrar los que NO están configurados (los configurados se conservan)
        // Primero borrar TODAS las revocaciones de esos sensores (pendientes, completadas, canceladas)
        $conn->prepare("
            DELETE FROM sensor_revocation_requests
            WHERE school_id = ?
              AND device_id IN (
                SELECT device_id FROM edge_devices
                WHERE school_id = ?
                  AND configured = FALSE
                  AND (
                    group_id IS NULL
                    OR group_id NOT IN (SELECT group_id FROM academic_groups WHERE school_id = ? AND academic_year = ?)
                  )
              )
        ")->execute([$schoolId, $schoolId, $schoolId, $currentYear]);

        // Ahora sí borrar los sensores viejos
        $conn->prepare("
            DELETE FROM edge_devices
            WHERE school_id = ?
              AND configured = FALSE
              AND (
                group_id IS NULL
                OR group_id NOT IN (SELECT group_id FROM academic_groups WHERE school_id = ? AND academic_year = ?)
              )
        ")->execute([$schoolId, $schoolId, $currentYear]);

        // Auto-crear sensores: 1 por grupo + secretaria + coordinación
        $sensorStmt = $conn->prepare("
            INSERT INTO edge_devices (school_id, device_name, location, group_id, configured, active, token_hash)
            VALUES (?, ?, ?, ?, FALSE, TRUE, NULL)
            ON CONFLICT DO NOTHING
        ");

        foreach ($groupsToInsert as $g) {
            $gid = $groupIdByName[$g['group_name']] ?? null;
            if ($gid) {
                $existsStmt = $conn->prepare("SELECT 1 FROM edge_devices WHERE school_id = ? AND group_id = ? AND active = TRUE");
                $existsStmt->execute([$schoolId, $gid]);
                if (!$existsStmt->fetchColumn()) {
                    $sensorStmt->execute([
                        $schoolId,
                        'Sensor ' . $g['group_name'],
                        'Aula ' . $g['group_name'],
                        $gid,
                    ]);
                }
            }
        }

        // Sensor de secretaría
        $secExists = $conn->prepare("SELECT 1 FROM edge_devices WHERE school_id = ? AND device_name = 'Sensor Secretaría' AND active = TRUE");
        $secExists->execute([$schoolId]);
        if (!$secExists->fetchColumn()) {
            $sensorStmt->execute([$schoolId, 'Sensor Secretaría', 'Secretaría', null]);
        }

        // Sensor de coordinación
        $coordExists = $conn->prepare("SELECT 1 FROM edge_devices WHERE school_id = ? AND device_name = 'Sensor Coordinación' AND active = TRUE");
        $coordExists->execute([$schoolId]);
        if (!$coordExists->fetchColumn()) {
            $sensorStmt->execute([$schoolId, 'Sensor Coordinación', 'Coordinación', null]);
        }

        // FIX Bug #4: no hacer exec("COMMIT"); la transacción la cierra el middleware en shutdown.
        if ($useSavepoint) {
            $conn->exec("RELEASE SAVEPOINT $sp");
        } else {
            $conn->commit();
        }

        $sensorsCreated = count($groupsToInsert) + 2;
        $teachersAssigned = 0;
        foreach ($teacherAssignments as $assigned) {
            if (is_array($assigned)) $teachersAssigned += count($assigned);
        }
        $studentsReassigned = count($studentGrades);

        securityLog('GROUPS_ONBOARDING_COMPLETED', "School: $schoolId, By: $userId ($role), Grupos: " . count($groupsToInsert) . ", Sensores: $sensorsCreated, Docentes: $teachersAssigned, Estudiantes reasignados: $studentsReassigned", $userId, $schoolId);

        echo json_encode([
            'status' => 'ok',
            'message' => 'Grupos académicos configurados correctamente',
            'groups_created' => count($groupsToInsert),
            'sensors_created' => $sensorsCreated,
            'teachers_assigned' => $teachersAssigned,
            'students_reassigned' => $studentsReassigned,
        ]);
    } catch (Exception $e) {
        if ($useSavepoint ?? false) {
            try { $conn->exec("ROLLBACK TO SAVEPOINT $sp"); } catch (Exception $ignore) {}
        } else {
            try { $conn->rollBack(); } catch (Exception $ignore) {}
        }
        securityLog('GROUPS_ONBOARDING_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar la configuración de grupos. Contacte al administrador.', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /school/assign-teacher — Asignar docente(s) a un grupo (post-onboarding)
// DELETE /school/assign-teacher — Desasignar docente de un grupo
//
// Permite al RECTOR gestionar teacher_group_access después del onboarding sin
// tener que re-correr todo el wizard. Útil para añadir/quitar docentes, cubrir
// licencias, etc.
//
// Payload POST: { group_id, teacher_user_ids: [uuid,...] }
// Payload DELETE: { group_id, teacher_user_id }
// ============================================================================
if ($cleanPath === '/school/assign-teacher' && in_array($method, ['POST', 'DELETE'], true)) {
    $authUser = requireAuth(['RECTOR']);
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $role = strtoupper($authUser['role'] ?? '');

    $groupId = trim((string)($input['group_id'] ?? ''));
    if ($groupId === '') {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'group_id es requerido']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $useSavepoint = $conn->inTransaction();
        $sp = 'sp_assign_teacher';
        if ($useSavepoint) {
            $conn->exec("SAVEPOINT $sp");
        } else {
            $conn->beginTransaction();
        }

        // Validar que el grupo pertenece a la escuela y obtener work_shift + año
        $groupStmt = $conn->prepare("SELECT group_id, work_shift, academic_year FROM academic_groups WHERE group_id = ? AND school_id = ?");
        $groupStmt->execute([$groupId, $schoolId]);
        $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            if ($useSavepoint) { $conn->exec("ROLLBACK TO SAVEPOINT $sp"); } else { try { $conn->rollBack(); } catch (Exception $ignore) {} }
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Grupo no encontrado en esta institución']));
        }

        if ($method === 'POST') {
            $teacherIds = $input['teacher_user_ids'] ?? [];
            if (!is_array($teacherIds) || empty($teacherIds)) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'teacher_user_ids debe ser un array no vacío']));
            }

            // Validar que los docentes pertenecen a la escuela y son TEACHER/COUNSELOR
            $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
            $validStmt = $conn->prepare("
                SELECT u.user_id FROM users u
                JOIN roles r ON u.role_id = r.role_id
                WHERE u.user_id IN ($placeholders) AND u.school_id = ? AND u.deleted_at IS NULL AND u.active = TRUE
                  AND UPPER(r.role_name) IN ('TEACHER', 'COUNSELOR')
            ");
            $params = array_merge($teacherIds, [$schoolId]);
            $validStmt->execute($params);
            $validIds = $validStmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($validIds) !== count($teacherIds)) {
                if ($useSavepoint) { $conn->exec("ROLLBACK TO SAVEPOINT $sp"); } else { try { $conn->rollBack(); } catch (Exception $ignore) {} }
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Uno o más docentes no son válidos o no pertenecen a esta institución']));
            }

            $tgaStmt = $conn->prepare("
                INSERT INTO teacher_group_access (school_id, group_id, teacher_user_id, work_shift, academic_year)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (teacher_user_id, group_id, academic_year) DO UPDATE SET work_shift = EXCLUDED.work_shift
            ");
            foreach ($validIds as $tid) {
                $tgaStmt->execute([$schoolId, $groupId, $tid, $group['work_shift'] ?? 'mañana', $group['academic_year']]);
            }

            if ($useSavepoint) { $conn->exec("RELEASE SAVEPOINT $sp"); } else { $conn->commit(); }
            securityLog('TEACHER_ASSIGNED', "School: $schoolId, Group: $groupId, Teachers: " . count($validIds) . ", By: $userId", $userId, $schoolId);
            echo json_encode(['status' => 'ok', 'message' => 'Docente(s) asignado(s) correctamente', 'assigned' => count($validIds)]);
        } else { // DELETE
            $teacherUserId = trim((string)($input['teacher_user_id'] ?? ''));
            if ($teacherUserId === '') {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'teacher_user_id es requerido']));
            }
            $delStmt = $conn->prepare("DELETE FROM teacher_group_access WHERE group_id = ? AND teacher_user_id = ? AND school_id = ?");
            $delStmt->execute([$groupId, $teacherUserId, $schoolId]);
            $deleted = $delStmt->rowCount();
            if ($useSavepoint) { $conn->exec("RELEASE SAVEPOINT $sp"); } else { $conn->commit(); }
            securityLog('TEACHER_UNASSIGNED', "School: $schoolId, Group: $groupId, Teacher: $teacherUserId, By: $userId, Rows: $deleted", $userId, $schoolId);
            echo json_encode(['status' => 'ok', 'message' => 'Docente desasignado', 'deleted' => $deleted]);
        }
    } catch (Exception $e) {
        if ($useSavepoint ?? false) {
            try { $conn->exec("ROLLBACK TO SAVEPOINT $sp"); } catch (Exception $ignore) {}
        } else {
            try { $conn->rollBack(); } catch (Exception $ignore) {}
        }
        securityLog('ASSIGN_TEACHER_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al gestionar asignación de docente', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// GET /school/teachers — Lista de docentes de la escuela (para selectores UI)
// ============================================================================
if ($cleanPath === '/school/teachers' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $schoolId = $authUser['school_id'];
    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");
        $shift = trim((string)($_GET['work_shift'] ?? ''));
        $sql = "
            SELECT u.user_id, u.first_name, u.last_name, u.email, u.work_shift
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.school_id = ? AND u.deleted_at IS NULL AND u.active = TRUE
              AND UPPER(r.role_name) IN ('TEACHER', 'COUNSELOR')
        ";
        $params = [$schoolId];
        if ($shift !== '') {
            $sql .= " AND (u.work_shift = ? OR u.work_shift IS NULL OR u.work_shift = 'completa')";
            $params[] = $shift;
        }
        $sql .= " ORDER BY u.last_name, u.first_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'data' => $teachers]);
    } catch (Exception $e) {
        securityLog('SCHOOL_TEACHERS_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener docentes', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /school/sensor-master-key — Configurar llave maestra de sensores
// ============================================================================
if ($cleanPath === '/school/sensor-master-key' && $method === 'POST') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $role = strtoupper($authUser['role'] ?? '');

    if ($role !== 'RECTOR') {
        securityLog('SENSOR_MASTER_KEY_UNAUTHORIZED', "User: $userId, Role: $role");
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Solo el rector puede configurar la llave maestra']));
    }

    $masterKey = trim((string)($input['master_key'] ?? ''));

    if (strlen($masterKey) < 12) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'La llave maestra debe tener al menos 12 caracteres']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Verificar si ya existe un hash configurado
        $checkStmt = $conn->prepare("SELECT sensor_master_key_hash FROM schools WHERE school_id = ?");
        $checkStmt->execute([$schoolId]);
        $existingHash = $checkStmt->fetchColumn();

        if ($existingHash) {
            // Si ya existe, pedir password del rector para sobrescribir
            $currentPassword = trim((string)($input['current_password'] ?? ''));
            if (empty($currentPassword)) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Debe proporcionar su contraseña para sobrescribir la llave maestra']));
            }

            $userStmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ? AND deleted_at IS NULL");
            $userStmt->execute([$userId]);
            $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$userRow || !password_verify($currentPassword, $userRow['password_hash'])) {
                securityLog('SENSOR_MASTER_KEY_PASSWORD_FAIL', "User: $userId", $userId, $schoolId);
                http_response_code(401);
                exit(json_encode(['status' => 'error', 'message' => 'Contraseña incorrecta']));
            }
        }

        $keyHash = password_hash($masterKey, PASSWORD_BCRYPT);

        $conn->prepare("UPDATE schools SET sensor_master_key_hash = ? WHERE school_id = ?")
            ->execute([$keyHash, $schoolId]);

        securityLog('SENSOR_MASTER_KEY_SET', "School: $schoolId, By: $userId ($role)", $userId, $schoolId);

        echo json_encode(['status' => 'ok', 'message' => 'Llave maestra configurada']);
    } catch (Exception $e) {
        securityLog('SENSOR_MASTER_KEY_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al configurar la llave maestra', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// GET /school/risk-config — Estado del onboarding de configuración de riesgo
// ============================================================================
if ($cleanPath === '/school/risk-config' && $method === 'GET') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];

    if (!$schoolId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID de institución requerido']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Resiliente: si la columna risk_config_completed no existe aun (pre-migracion),
        // devolver needs_onboarding=false para no bloquear el sistema.
        try {
            $stmt = $conn->prepare("SELECT risk_config_completed FROM schools WHERE school_id = ?");
            $stmt->execute([$schoolId]);
            $school = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $colEx) {
            $school = ['risk_config_completed' => true];
        }

        if (!$school) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Institución no encontrada']));
        }

        echo json_encode([
            'status' => 'ok',
            'risk_config_completed' => (bool)$school['risk_config_completed'],
            'needs_onboarding' => !$school['risk_config_completed'],
        ]);
    } catch (Exception $e) {
        securityLog('RISK_CONFIG_GET_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener estado de configuración de riesgo']);
    }
    exit;
}

// ============================================================================
// POST /school/risk-config — Marcar configuración de riesgo como completada
// ============================================================================
if ($cleanPath === '/school/risk-config' && $method === 'POST') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $role = strtoupper($authUser['role'] ?? '');

    // Solo RECTOR puede completar la configuración de riesgo
    if ($role !== 'RECTOR') {
        securityLog('RISK_CONFIG_UNAUTHORIZED', "User: $userId, Role: $role");
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Solo el rector puede completar la configuración de riesgo']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Resilient: the column may not exist yet on pre-migration databases.
        try {
            $conn->prepare("UPDATE schools SET risk_config_completed = TRUE WHERE school_id = ?")
                ->execute([$schoolId]);
        } catch (PDOException $colEx) {
            // Columna no existe aun. No bloquear.
        }

        securityLog('RISK_CONFIG_COMPLETED', "School: $schoolId, By: $userId ($role)", $userId, $schoolId);

        echo json_encode([
            'status' => 'ok',
            'message' => 'Configuración de riesgo completada',
            'risk_config_completed' => true,
        ]);
    } catch (Exception $e) {
        securityLog('RISK_CONFIG_SET_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar configuración de riesgo']);
    }
    exit;
}

// ============================================================================
// GET /school/technical-modality — Obtener configuración de modalidad técnica
// ============================================================================
if ($cleanPath === '/school/technical-modality' && $method === 'GET') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $currentYear = (int)date('Y');

    try {
        $stmt = $conn->prepare("
            SELECT config_id, grade_level, work_shift, enabled, uses_blocks,
                   days_of_week, entry_time, exit_time, academic_year
            FROM technical_modality_config
            WHERE school_id = ? AND academic_year = ?
            ORDER BY grade_level::INT, work_shift
        ");
        $stmt->execute([$schoolId, $currentYear]);
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'ok',
            'configs' => $configs,
            'has_technical_modality' => count($configs) > 0,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /school/technical-modality — Guardar configuración de modalidad técnica
// Body: { configs: [{ grade_level, work_shift, uses_blocks, days_of_week, entry_time, exit_time }] }
// ============================================================================
if ($cleanPath === '/school/technical-modality' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $schoolId = $authUser['school_id'];
    $currentYear = (int)date('Y');
    $configs = $input['configs'] ?? [];

    if (!is_array($configs)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'configs debe ser un array']));
    }

    try {
        // Eliminar configuración existente del año actual
        $conn->prepare("DELETE FROM technical_modality_config WHERE school_id = ? AND academic_year = ?")
            ->execute([$schoolId, $currentYear]);

        // Insertar nueva configuración
        foreach ($configs as $cfg) {
            $grade = trim((string)($cfg['grade_level'] ?? ''));
            $shift = trim((string)($cfg['work_shift'] ?? 'mañana'));
            $usesBlocks = (bool)($cfg['uses_blocks'] ?? false);
            $days = $cfg['days_of_week'] ?? [];
            $entryTime = $cfg['entry_time'] ?? null;
            $exitTime = $cfg['exit_time'] ?? null;

            if ($grade === '') continue;
            if (!is_array($days)) $days = [];
            // Filtrar días válidos (1-7)
            $days = array_values(array_filter($days, fn($d) => $d >= 1 && $d <= 7));

            $conn->prepare("
                INSERT INTO technical_modality_config
                    (school_id, grade_level, work_shift, enabled, uses_blocks, days_of_week, entry_time, exit_time, academic_year)
                VALUES (?, ?, ?, TRUE, ?, ?::jsonb, ?, ?, ?)
                ON CONFLICT (school_id, grade_level, work_shift, academic_year)
                DO UPDATE SET enabled = TRUE, uses_blocks = EXCLUDED.uses_blocks,
                    days_of_week = EXCLUDED.days_of_week, entry_time = EXCLUDED.entry_time,
                    exit_time = EXCLUDED.exit_time, updated_at = NOW()
            ")->execute([
                $schoolId, $grade, $shift, $usesBlocks,
                json_encode($days, JSON_UNESCAPED_UNICODE),
                $entryTime ?: null, $exitTime ?: null,
                $currentYear,
            ]);
        }

        securityLog('TECHNICAL_MODALITY_SAVED', "School: $schoolId Configs: " . count($configs), $authUser['id'], $schoolId);
        echo json_encode(['status' => 'ok', 'message' => 'Configuración de modalidad técnica guardada', 'count' => count($configs)]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
