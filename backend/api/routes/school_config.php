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
            'configs' => $formattedConfigs,
            'config' => $formattedConfigs[0] ?? null, // retrocompatibilidad
            'time_blocks' => $blocksByShift,
        ];

        echo json_encode($response);
    } catch (Exception $e) {
        securityLog('SCHOOL_CONFIG_GET_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener configuración']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar la configuración. Contacte al administrador.']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error al actualizar configuración']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener bloques horarios']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar bloques horarios']);
    }
    exit;
}
