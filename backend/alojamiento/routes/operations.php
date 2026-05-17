<?php
// routes/operations.php - Manejo de comandos (SOS, Inasistencia)
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

function logUserCommand($conn, $schoolId, $userId, $action, $payload = []) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO user_commands (
                command_id, school_id, executed_by_user_id, command_type, command_payload, executed_at, metadata_json
            ) VALUES (
                uuid_generate_v4(), ?, ?, ?, ?::jsonb, NOW(), ?::jsonb
            )
        ");
        $stmt->execute([
            $schoolId,
            $userId,
            strtoupper($action),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            json_encode(['source' => 'webapp'], JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $e) {
        securityLog('USER_COMMAND_LOG_ERROR', $e->getMessage());
    }
}

function enqueueTwilioJob($to, $body, $schoolId, $studentId = null, $guardianId = null, $senderUserId = null, $typeCode = 'OUTBOUND') {
    try {
        $redis = new Redis();
        $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
        if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
        $toNorm = preg_replace('/^whatsapp:/i', '', trim((string)$to));
        if ($toNorm !== '' && $toNorm[0] !== '+') $toNorm = '+' . $toNorm;
        $toNorm = preg_replace('/[^0-9\+]/', '', $toNorm);
        $payload = json_encode([
            'to' => $toNorm,
            'body' => $body,
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'guardian_id' => $guardianId,
            'sender_user_id' => $senderUserId,
            'type_code' => $typeCode,
            'retries' => 0,
            'created_at' => time()
        ], JSON_UNESCAPED_UNICODE);
        $redis->rPush('queue:twilio', $payload);
    } catch (Exception $e) {
        securityLog('TWILIO_ENQUEUE_FAILED', $e->getMessage());
    }
}

function logTwilioMessageSafe($conn, $schoolId, $typeCode, $direction, $phone, $content, $meta = [], $studentId = null, $guardianId = null, $senderUserId = null, $providerSid = null, $deliveryStatus = null) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO twilio_messages (
                twilio_message_id, school_id, student_id, guardian_id, sender_user_id,
                type_code, direction, phone_number, message_content, provider_message_sid,
                delivery_status, sent_at, metadata_json
            ) VALUES (
                uuid_generate_v4(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?::jsonb
            )
        ");
        $stmt->execute([
            $schoolId,
            $studentId,
            $guardianId,
            $senderUserId,
            $typeCode,
            $direction,
            $phone,
            $content,
            $providerSid,
            $deliveryStatus,
            json_encode($meta, JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $e) {
        securityLog('TWILIO_LOG_ERROR', $e->getMessage());
    }
}

/**
 * @OA\Post(
 *     path="/operations/sos",
 *     summary="Registrar alerta SOS",
 *     description="Registra una alerta de emergencia SOS en el sistema. Notifica a las autoridades del colegio.",
 *     tags={"Operaciones"},
 *     security={{"cookieAuth":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"params"},
 *             @OA\Property(property="params", type="object",
 *                 @OA\Property(property="location", type="string", example="Patio central"),
 *                 @OA\Property(property="message", type="string", example="Estudiante desmayado")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=200, description="Alerta registrada"),
 *     @OA\Response(response=403, description="Rol no autorizado"),
 *     @OA\Response(response=401, description="No autenticado")
 * )
 */
if (strpos($cleanPath, '/operations/') === 0 || (isset($input['action']) && $input['action'] === 'EXECUTE_COMMAND')) {
    $action = filter_var($input['command'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($cleanPath === '/operations/sos') $action = 'sos';
    if ($cleanPath === '/operations/inasistencia') $action = 'inasistencia';

    $authUser = requireAuth();
    $userId = $authUser['id'];
    $schoolId = $authUser['school_id'];
    $role = $authUser['role'];
    $params = $input['params'] ?? [];

    $rolePermissions = [
        'sos' => ['RECTOR', 'COORDINADOR', 'DOCENTE', 'SECRETARIA', 'PORTERO', 'AUXILIAR', 'PSICORIENTADOR'],
        'inasistencia' => ['RECTOR', 'COORDINADOR', 'DOCENTE'],
        'citacion' => ['COORDINADOR', 'DOCENTE', 'PSICORIENTADOR'],
        'autorizar_salida' => ['COORDINADOR', 'RECTOR'],
        'permiso' => ['DOCENTE', 'COORDINADOR', 'RECTOR', 'PSICORIENTADOR'],
        'incidente' => ['DOCENTE', 'PSICORIENTADOR'],
        'solicitud' => ['RECTOR', 'COORDINADOR', 'DOCENTE', 'SECRETARIA', 'PORTERO', 'AUXILIAR', 'PSICORIENTADOR'],
        'daño' => ['AUXILIAR', 'PORTERO'],
        'pedagogica' => ['COORDINADOR', 'RECTOR'],
        'horario' => ['COORDINADOR', 'RECTOR']
    ];

    if (!isset($rolePermissions[$action]) || !in_array($role, $rolePermissions[$action])) {
        securityLog('UNAUTHORIZED_COMMAND_ATTEMPT', "User: $userId, Role: $role, Cmd: $action");
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Acceso restringido']));
    }

    try {
        switch ($action) {
            case 'sos':
                $location = filter_var($params['location'] ?? 'Ubicación no definida', FILTER_SANITIZE_SPECIAL_CHARS);
                $message = filter_var($params['message'] ?? 'Alerta SOS', FILTER_SANITIZE_SPECIAL_CHARS);
                
                $sosStmt = $conn->prepare("
                    INSERT INTO sos_alerts (alert_id, school_id, emitted_by_user_id, alert_type, alert_description, emitted_at)
                    VALUES (uuid_generate_v4(), ?, ?, 'SOS_WEBAPP', ?, NOW())
                ");
                $sosStmt->execute([$schoolId, $userId, $message]);
                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode(['status' => 'ok', 'message' => 'Alerta SOS registrada correctamente']);
                break;

            case 'inasistencia':
                $studentId = $params['student_id'] ?? null;
                
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, g.whatsapp_phone 
                    FROM students s
                    JOIN guardian_student_relationships gsr ON s.student_id = gsr.student_id
                    JOIN guardians g ON gsr.guardian_id = g.guardian_id
                    WHERE s.student_id = ? AND s.school_id = ? AND gsr.primary_guardian = TRUE
                ");
                $stmt->execute([$studentId, $schoolId]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($data) {
                    $incStmt = $conn->prepare("
                        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at)
                        VALUES (uuid_generate_v4(), ?, ?, 'INASISTENCIA', NOW())
                    ");
                    $incStmt->execute([$schoolId, $studentId]);
                    
                    $msg = "🔔 *NEXO INFORMA*\nEl estudiante *" . $data['first_name'] . " " . $data['last_name'] . "* no se ha reportado hoy.";
                    enqueueTwilioJob($data['whatsapp_phone'], $msg, $schoolId, $studentId, null, $userId, 'INASISTENCIA');
                    logUserCommand($conn, $schoolId, $userId, $action, $params);
                    echo json_encode(['status' => 'ok', 'message' => 'Inasistencia reportada y acudiente notificado']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Estudiante o acudiente no encontrado']);
                }
                break;

            case 'citacion':
                $studentId = $params['student'] ?? $params['student_id'] ?? null;
                if (!$studentId) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'student_id requerido para citación']);
                    break;
                }

                $studentStmt = $conn->prepare("
                    SELECT s.student_id, s.first_name, s.last_name, g.guardian_id, g.whatsapp_phone, u.phone AS guardian_user_phone
                    FROM students s
                    JOIN guardian_student_relationships gsr ON gsr.student_id = s.student_id AND gsr.primary_guardian = TRUE
                    JOIN guardians g ON g.guardian_id = gsr.guardian_id
                    JOIN users u ON u.user_id = g.user_id
                    WHERE s.school_id = ? AND s.student_id = ?
                    LIMIT 1
                ");
                $studentStmt->execute([$schoolId, $studentId]);
                $target = $studentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$target) {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'message' => 'No se encontró acudiente principal para el estudiante']);
                    break;
                }

                $studentName = trim($target['first_name'] . ' ' . $target['last_name']);
                $citMsg = "Citación para {$studentName}.\n1 = Confirmo asistencia a la citación.\n2 = Solicito reagendar la citación.";
                enqueueTwilioJob($target['whatsapp_phone'], $citMsg, $schoolId, $studentId, $target['guardian_id'], $userId, 'CITACION');
                if (!empty($target['guardian_user_phone']) && $target['guardian_user_phone'] !== $target['whatsapp_phone']) {
                    enqueueTwilioJob($target['guardian_user_phone'], $citMsg, $schoolId, $studentId, $target['guardian_id'], $userId, 'CITACION');
                }

                // FIX: Persistir estado de conversación en Redis para que el webhook inbound
                // resuelva el student_id exacto en lugar de usar LIMIT 1 arbitrario.
                try {
                    $redisConv = new Redis();
                    $redisConv->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
                    if ($pass = getenv('REDIS_PASSWORD')) $redisConv->auth($pass);
                    $convPayload = json_encode(['student_id' => (string)$studentId, 'guardian_id' => (string)$target['guardian_id'], 'school_id' => (string)$schoolId, 'ts' => time()], JSON_UNESCAPED_UNICODE);
                    $redisConv->setex('conversation:' . preg_replace('/[^0-9+]/', '', $target['whatsapp_phone']), 172800, $convPayload);
                    if (!empty($target['guardian_user_phone']) && $target['guardian_user_phone'] !== $target['whatsapp_phone']) {
                        $redisConv->setex('conversation:' . preg_replace('/[^0-9+]/', '', $target['guardian_user_phone']), 172800, $convPayload);
                    }
                } catch (Exception $e) {
                    securityLog('TWILIO_CONV_REDIS_SKIP', $e->getMessage());
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode(['status' => 'ok', 'message' => 'Citación encolada para envío al acudiente']);
                break;

            case 'permiso':
            case 'autorizar_salida':
            case 'incidente':
            case 'solicitud':
            case 'daño':
            case 'pedagogica':
            case 'horario':
                $studentId = $params['student'] ?? $params['student_id'] ?? null;
                $reason = trim((string)($params['reason'] ?? $params['message'] ?? $params['description'] ?? ''));
                if ($reason === '') {
                    $reason = strtoupper($action) . ' - generado por sistema NEXO';
                }

                if ($studentId) {
                    $incStmt = $conn->prepare("
                        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at)
                        VALUES (uuid_generate_v4(), ?, ?, ?, NOW())
                    ");
                    $incStmt->execute([$schoolId, $studentId, strtoupper($action)]);
                }

                if ($action === 'citacion') {
                    $targetRole = strtoupper((string)($params['targetRole'] ?? ''));
                    $msg = "NEXO: solicitud interna [$action] desde $role. Detalle: $reason";
                    $notifyStmt = $conn->prepare("
                        SELECT phone FROM users
                        WHERE school_id = ?
                          AND role_id IN (
                              SELECT role_id FROM roles WHERE UPPER(role_name) = ?
                          )
                    ");
                    $notifyStmt->execute([$schoolId, $targetRole !== '' ? $targetRole : 'COORDINADOR']);
                    while ($notifyRow = $notifyStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($notifyRow['phone'])) {
                            enqueueTwilioJob($notifyRow['phone'], $msg, $schoolId, null, null, $userId, 'NOTIFY_ROLE');
                        }
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                securityLog('OPERATION_EXECUTED', "User:$userId Role:$role Action:$action");
                echo json_encode([
                    'status' => 'ok',
                    'message' => 'Operación procesada correctamente',
                    'data' => [
                        'action' => $action,
                        'executed_by' => $userId,
                        'school_id' => $schoolId
                    ]
                ]);
                break;
        }
    } catch (Exception $e) {
        securityLog('OPERATION_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error en la operación']);
    }
    exit;
}
