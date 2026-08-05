<?php
/**
 * =============================================================================
 * routes/school_config.php — Configuración de horarios institucionales.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone endpoints para el onboarding obligatorio de horarios y la edición
 * posterior de la configuración institucional:
 *
 *   - GET  /school/config            : Retorna la configuración actual + estado
 *                                      de onboarding. Si no existe, retorna
 *                                      onboarding_completed=FALSE.
 *   - POST /school/onboarding        : Crea/actualiza la configuración de
 *                                      horarios y marca onboarding_completed=TRUE.
 *                                      Solo RECTOR y COORDINATOR.
 *   - PUT  /school/config            : Actualiza la configuración (post-onboarding).
 *                                      Solo RECTOR y COORDINATOR.
 *   - GET  /school/time-blocks       : Lista los bloques horarios.
 *   - POST /school/time-blocks       : Reemplaza los bloques horarios (bulk).
 *                                      Solo RECTOR y COORDINATOR.
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

        // Configuración principal
        $stmt = $conn->prepare("SELECT * FROM school_schedule_config WHERE school_id = ?");
        $stmt->execute([$schoolId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        // Bloques horarios
        $blocksStmt = $conn->prepare("
            SELECT block_number, block_name, start_time, end_time
            FROM school_time_blocks
            WHERE school_id = ?
            ORDER BY block_number
        ");
        $blocksStmt->execute([$schoolId]);
        $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

        // Formatear bloques (TIME a string HH:MM)
        $formattedBlocks = array_map(function($b) {
            return [
                'block_number' => (int)$b['block_number'],
                'block_name' => $b['block_name'],
                'start_time' => substr($b['start_time'], 0, 5),
                'end_time' => substr($b['end_time'], 0, 5),
            ];
        }, $blocks);

        $response = [
            'status' => 'ok',
            'onboarding_completed' => (bool)($config['onboarding_completed'] ?? false),
            'config' => $config ? [
                'rotates_classrooms' => (bool)$config['rotates_classrooms'],
                'work_shift' => $config['work_shift'],
                'entry_time' => $config['entry_time'] ? substr($config['entry_time'], 0, 5) : null,
                'exit_time' => $config['exit_time'] ? substr($config['exit_time'], 0, 5) : null,
                'recess_start_time' => $config['recess_start_time'] ? substr($config['recess_start_time'], 0, 5) : null,
                'recess_end_time' => $config['recess_end_time'] ? substr($config['recess_end_time'], 0, 5) : null,
            ] : null,
            'time_blocks' => $formattedBlocks,
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
// POST /school/onboarding — Onboarding obligatorio de horarios
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

    $rotatesClassrooms = (bool)($input['rotates_classrooms'] ?? false);
    $workShift = trim((string)($input['work_shift'] ?? 'mañana'));
    $entryTime = trim((string)($input['entry_time'] ?? ''));
    $exitTime = trim((string)($input['exit_time'] ?? ''));
    $recessStartTime = trim((string)($input['recess_start_time'] ?? ''));
    $recessEndTime = trim((string)($input['recess_end_time'] ?? ''));
    $timeBlocks = $input['time_blocks'] ?? [];

    // Validaciones
    if (!in_array($workShift, ['mañana', 'tarde', 'completa'])) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Jornada inválida. Debe ser: mañana, tarde o completa']));
    }
    if (empty($entryTime) || empty($exitTime)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Hora de entrada y salida son obligatorias']));
    }
    foreach ([$entryTime, $exitTime] as $t) {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Formato de hora inválido. Use HH:MM']));
        }
    }
    // Recess opcional pero si viene uno, debe venir el otro
    if (($recessStartTime && !$recessEndTime) || (!$recessStartTime && $recessEndTime)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Debe especificar inicio y fin del receso, o ninguno']));
    }
    // Si rota salones, debe enviar bloques horarios
    if ($rotatesClassrooms) {
        if (empty($timeBlocks) || !is_array($timeBlocks)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Si el colegio rota de salones, debe definir los bloques horarios']));
        }
        foreach ($timeBlocks as $b) {
            if (empty($b['start_time']) || empty($b['end_time'])) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Cada bloque horario debe tener hora de inicio y fin']));
            }
        }
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $conn->exec("BEGIN");
        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
        $conn->exec("SELECT set_config('app.current_role', " . $conn->quote($role) . ", true)");

        // Upsert configuración
        $upsertStmt = $conn->prepare("
            INSERT INTO school_schedule_config
                (school_id, rotates_classrooms, work_shift, entry_time, exit_time,
                 recess_start_time, recess_end_time, onboarding_completed,
                 onboarding_completed_by, onboarding_completed_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, ?, NOW(), NOW())
            ON CONFLICT (school_id)
            DO UPDATE SET
                rotates_classrooms = EXCLUDED.rotates_classrooms,
                work_shift = EXCLUDED.work_shift,
                entry_time = EXCLUDED.entry_time,
                exit_time = EXCLUDED.exit_time,
                recess_start_time = EXCLUDED.recess_start_time,
                recess_end_time = EXCLUDED.recess_end_time,
                onboarding_completed = TRUE,
                onboarding_completed_by = EXCLUDED.onboarding_completed_by,
                onboarding_completed_at = NOW(),
                updated_at = NOW()
        ");
        $upsertStmt->execute([
            $schoolId, $rotatesClassrooms, $workShift, $entryTime, $exitTime,
            $recessStartTime ?: null, $recessEndTime ?: null,
            $userId
        ]);

        // Actualizar schools.onboarding_completed
        $schoolStmt = $conn->prepare("UPDATE schools SET onboarding_completed = TRUE WHERE school_id = ?");
        $schoolStmt->execute([$schoolId]);

        // Si rota salones, reemplazar bloques horarios
        if ($rotatesClassrooms && !empty($timeBlocks)) {
            // Limpiar bloques existentes
            $delStmt = $conn->prepare("DELETE FROM school_time_blocks WHERE school_id = ?");
            $delStmt->execute([$schoolId]);

            // Insertar nuevos bloques
            $blockStmt = $conn->prepare("
                INSERT INTO school_time_blocks (school_id, block_number, block_name, start_time, end_time)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($timeBlocks as $i => $b) {
                $blockNum = (int)($b['block_number'] ?? ($i + 1));
                $blockStmt->execute([
                    $schoolId,
                    $blockNum,
                    $b['block_name'] ?? null,
                    $b['start_time'],
                    $b['end_time']
                ]);
            }
        }

        $conn->exec("COMMIT");

        securityLog('ONBOARDING_COMPLETED', "School: $schoolId, By: $userId ($role), Rotates: " . ($rotatesClassrooms ? 'YES' : 'NO'));

        echo json_encode([
            'status' => 'ok',
            'message' => 'Configuración de horarios guardada correctamente',
            'onboarding_completed' => true,
        ]);
    } catch (Exception $e) {
        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
        securityLog('ONBOARDING_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar configuración: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// PUT /school/config — Actualizar configuración (post-onboarding)
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

    $rotatesClassrooms = isset($input['rotates_classrooms']) ? (bool)$input['rotates_classrooms'] : null;
    $workShift = isset($input['work_shift']) ? trim((string)$input['work_shift']) : null;
    $entryTime = isset($input['entry_time']) ? trim((string)$input['entry_time']) : null;
    $exitTime = isset($input['exit_time']) ? trim((string)$input['exit_time']) : null;
    $recessStartTime = isset($input['recess_start_time']) ? trim((string)$input['recess_start_time']) : null;
    $recessEndTime = isset($input['recess_end_time']) ? trim((string)$input['recess_end_time']) : null;

    if ($workShift !== null && !in_array($workShift, ['mañana', 'tarde', 'completa'])) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Jornada inválida']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $conn->exec("BEGIN");
        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
        $conn->exec("SELECT set_config('app.current_role', " . $conn->quote($role) . ", true)");

        // Construir SET dinámico solo con campos proporcionados
        $sets = ['updated_at = NOW()'];
        $params = [];

        $fieldMap = [
            'rotates_classrooms' => $rotatesClassrooms,
            'work_shift' => $workShift,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'recess_start_time' => $recessStartTime,
            'recess_end_time' => $recessEndTime,
        ];

        foreach ($fieldMap as $col => $val) {
            if ($val !== null) {
                $sets[] = "$col = ?";
                $params[] = $val;
            }
        }

        $params[] = $schoolId;
        $updateStmt = $conn->prepare("UPDATE school_schedule_config SET " . implode(', ', $sets) . " WHERE school_id = ?");
        $updateStmt->execute($params);

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
// POST /school/time-blocks — Reemplazar bloques horarios (bulk)
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

        // Limpiar existentes
        $delStmt = $conn->prepare("DELETE FROM school_time_blocks WHERE school_id = ?");
        $delStmt->execute([$schoolId]);

        // Insertar nuevos
        $blockStmt = $conn->prepare("
            INSERT INTO school_time_blocks (school_id, block_number, block_name, start_time, end_time)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($timeBlocks as $i => $b) {
            $blockNum = (int)($b['block_number'] ?? ($i + 1));
            $blockStmt->execute([
                $schoolId,
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
