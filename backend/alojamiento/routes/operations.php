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

function sendTwilioDirect($to, $body) {
    $sid   = getenv('TWILIO_ACCOUNT_SID');
    $token = getenv('TWILIO_AUTH_TOKEN');
    $from  = getenv('TWILIO_FROM_NUMBER');
    if (!$sid || !$token || !$from) {
        return ['ok' => false, 'error' => 'Missing Twilio credentials'];
    }
    $toNorm = preg_replace('/^whatsapp:/i', '', trim((string)$to));
    if ($toNorm !== '' && $toNorm[0] !== '+') $toNorm = '+' . $toNorm;
    $toNorm = preg_replace('/[^0-9\+]/', '', $toNorm);

    $url     = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $payload = http_build_query([
        'From' => "whatsapp:$from",
        'To'   => "whatsapp:$toNorm",
        'Body' => $body
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        return ['ok' => false, 'error' => ($err ?: "HTTP $httpCode"), 'sid' => null];
    }
    $json = json_decode($response, true);
    return ['ok' => true, 'error' => null, 'sid' => $json['sid'] ?? null];
}

function enqueueTwilioJob($to, $body, $schoolId, $studentId = null, $guardianId = null, $senderUserId = null, $typeCode = 'OUTBOUND') {
    $enqueued = false;
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
        $enqueued = true;
    } catch (Exception $e) {
        securityLog('TWILIO_ENQUEUE_FAILED', $e->getMessage());
    }

    // Fallback: si Redis no está disponible, enviar directamente
    if (!$enqueued) {
        $result = sendTwilioDirect($to, $body);
        if ($result['ok']) {
            securityLog('TWILIO_DIRECT_SENT', "SID: {$result['sid']} To: $to");
        } else {
            securityLog('TWILIO_DIRECT_FAILED', "To: $to Error: {$result['error']}");
        }
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
    $pathMap = [
        '/operations/sos' => 'sos',
        '/operations/inasistencia' => 'inasistencia',
        '/operations/citacion' => 'citacion',
        '/operations/salida' => 'autorizar_salida',
        '/operations/permiso' => 'permiso',
        '/operations/solicitud' => 'solicitud',
        '/operations/daño' => 'daño',
        '/operations/pedagogica' => 'pedagogica',
        '/operations/horario' => 'horario',
        '/operations/incidente' => 'incidente',
    ];
    if (isset($pathMap[$cleanPath])) {
        $action = $pathMap[$cleanPath];
    }

    $authUser = requireAuth();
    $userId = $authUser['id'];
    $schoolId = $authUser['school_id'];
    $role = $authUser['role'];
    $params = $input['params'] ?? [];

    $rolePermissions = [
        'sos' => ['RECTOR', 'COORDINADOR', 'DOCENTE', 'SECRETARIA', 'PORTERO', 'AUXILIAR', 'PSICORIENTADOR'],
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

                // Notificar Coordinación y Rectoría por WhatsApp
                $sosMsg = "🚨 *NEXO — ALERTA SOS*\n\nUbicación: {$location}\nMensaje: {$message}\nReportado por: {$authUser['nombre']} ({$role})\n\nVerifique la plataforma inmediatamente.";
                $notifyRoles = ['RECTOR', 'COORDINADOR'];
                foreach ($notifyRoles as $nr) {
                    $nStmt = $conn->prepare("
                        SELECT phone FROM users
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = ?) AND active = TRUE
                    ");
                    $nStmt->execute([$schoolId, $nr]);
                    while ($nRow = $nStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($nRow['phone'])) {
                            enqueueTwilioJob($nRow['phone'], $sosMsg, $schoolId, null, null, $userId, 'SOS_ALERT');
                        }
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode(['status' => 'ok', 'message' => 'Alerta SOS registrada y notificada a directivos']);
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

                    // Notificar acudiente para autorizar_salida y permiso
                    if (in_array($action, ['autorizar_salida', 'permiso'])) {
                        $guardStmt = $conn->prepare("
                            SELECT s.first_name, s.last_name, g.whatsapp_phone
                            FROM students s
                            JOIN guardian_student_relationships gsr ON s.student_id = gsr.student_id AND gsr.primary_guardian = TRUE
                            JOIN guardians g ON gsr.guardian_id = g.guardian_id
                            WHERE s.student_id = ? AND s.school_id = ?
                        ");
                        $guardStmt->execute([$studentId, $schoolId]);
                        $guardData = $guardStmt->fetch(PDO::FETCH_ASSOC);
                        if ($guardData && !empty($guardData['whatsapp_phone'])) {
                            $actLabel = $action === 'autorizar_salida' ? 'AUTORIZACIÓN DE SALIDA' : 'PERMISO';
                            $msg = "📢 *NEXO*\n\nSu hijo(a) *" . $guardData['first_name'] . ' ' . $guardData['last_name'] . "* tiene registrada una *" . $actLabel . "* en el sistema.\n\nDetalle: {$reason}\n\nComuníquese con la institución si tiene dudas.";
                            enqueueTwilioJob($guardData['whatsapp_phone'], $msg, $schoolId, $studentId, null, $userId, strtoupper($action));
                        }
                    }
                }

                // Notificación grupal para salida pedagógica o cambio de horario
                if (in_array($action, ['pedagogica', 'horario']) && !empty($params['group'])) {
                    $groupName = filter_var($params['group'], FILTER_SANITIZE_SPECIAL_CHARS);
                    $groupStmt = $conn->prepare("
                        SELECT DISTINCT g.whatsapp_phone
                        FROM guardians g
                        JOIN guardian_student_relationships gsr ON g.guardian_id = gsr.guardian_id AND gsr.primary_guardian = TRUE
                        JOIN student_group_assignments sga ON gsr.student_id = sga.student_id AND sga.active = TRUE
                        JOIN academic_groups ag ON sga.group_id = ag.group_id AND ag.group_name = ? AND ag.school_id = ?
                    ");
                    $groupStmt->execute([$groupName, $schoolId]);
                    $actLabel = $action === 'horario' ? 'CAMBIO DE HORARIO' : 'SALIDA PEDAGÓGICA';
                    $msg = "📢 *NEXO*\n\nSe ha registrado una *" . $actLabel . "* para el grupo *" . $groupName . "*.\n\nDetalle: {$reason}\n\nPor favor revise la plataforma para más información.";
                    while ($gRow = $groupStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($gRow['whatsapp_phone'])) {
                            enqueueTwilioJob($gRow['whatsapp_phone'], $msg, $schoolId, null, null, $userId, strtoupper($action));
                        }
                    }
                }

                // Solicitud interna: guardar mensaje interno + WhatsApp si tiene teléfono
                if ($action === 'solicitud' && !empty($params['recipient_id'])) {
                    $recStmt = $conn->prepare("SELECT phone, first_name, last_name FROM users WHERE user_id = ? AND school_id = ?");
                    $recStmt->execute([$params['recipient_id'], $schoolId]);
                    $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
                    if ($recRow) {
                        try {
                            $msgStmt = $conn->prepare("
                                INSERT INTO internal_messages (message_id, school_id, sender_user_id, receiver_user_id, subject, message_content, sent_at)
                                VALUES (uuid_generate_v4(), ?, ?, ?, 'Solicitud interna', ?, NOW())
                            ");
                            $msgStmt->execute([$schoolId, $userId, $params['recipient_id'], $reason]);
                        } catch (Exception $e) {
                            securityLog('SOLICITUD_MSG_ERROR', $e->getMessage());
                        }
                        if (!empty($recRow['phone'])) {
                            $solMsg = "📨 *NEXO — Solicitud interna*\n\nDe: *{$authUser['nombre']}* ({$role})\nMensaje: {$reason}\n\nResponde por la plataforma.";
                            enqueueTwilioJob($recRow['phone'], $solMsg, $schoolId, null, null, $userId, 'SOLICITUD');
                        }
                    }
                }

                // Incidente: respetar targets seleccionados
                if ($action === 'incidente') {
                    $targets = $params['targets'] ?? [];
                    if ($studentId && in_array('padre', $targets)) {
                        $guardStmt = $conn->prepare("
                            SELECT s.first_name, s.last_name, g.whatsapp_phone
                            FROM students s
                            JOIN guardian_student_relationships gsr ON s.student_id = gsr.student_id AND gsr.primary_guardian = TRUE
                            JOIN guardians g ON gsr.guardian_id = g.guardian_id
                            WHERE s.student_id = ? AND s.school_id = ?
                        ");
                        $guardStmt->execute([$studentId, $schoolId]);
                        $guardData = $guardStmt->fetch(PDO::FETCH_ASSOC);
                        if ($guardData && !empty($guardData['whatsapp_phone'])) {
                            $msg = "⚠️ *NEXO — Incidente*\n\nEstudiante: *" . $guardData['first_name'] . ' ' . $guardData['last_name'] . "*\nDetalle: {$reason}\n\nComuníquese con la institución.";
                            enqueueTwilioJob($guardData['whatsapp_phone'], $msg, $schoolId, $studentId, null, $userId, 'INCIDENTE');
                        }
                    }
                    if (in_array('rector', $targets)) {
                        $rStmt = $conn->prepare("
                            SELECT phone FROM users
                            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'RECTOR')
                        ");
                        $rStmt->execute([$schoolId]);
                        while ($rRow = $rStmt->fetch(PDO::FETCH_ASSOC)) {
                            if (!empty($rRow['phone'])) {
                                $msg = "⚠️ *NEXO — Reporte de incidente*\n\nReportado por: {$role}\nDetalle: {$reason}";
                                enqueueTwilioJob($rRow['phone'], $msg, $schoolId, null, null, $userId, 'NOTIFY_ROLE');
                            }
                        }
                    }
                    if (in_array('coordinacion', $targets)) {
                        $cStmt = $conn->prepare("
                            SELECT phone FROM users
                            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'COORDINADOR')
                        ");
                        $cStmt->execute([$schoolId]);
                        while ($cRow = $cStmt->fetch(PDO::FETCH_ASSOC)) {
                            if (!empty($cRow['phone'])) {
                                $msg = "⚠️ *NEXO — Reporte de incidente*\n\nReportado por: {$role}\nDetalle: {$reason}";
                                enqueueTwilioJob($cRow['phone'], $msg, $schoolId, null, null, $userId, 'NOTIFY_ROLE');
                            }
                        }
                    }
                    // Fallback: sin targets ni estudiante → notificar coordinación
                    if (empty($targets) && !$studentId) {
                        $targetRole = 'COORDINADOR';
                        $msg = "⚠️ *NEXO — Alerta institucional*\n\nTipo: *INCIDENTE*\nReportado por: {$role}\nDetalle: {$reason}";
                        $fStmt = $conn->prepare("
                            SELECT phone FROM users
                            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = ?)
                        ");
                        $fStmt->execute([$schoolId, $targetRole]);
                        while ($fRow = $fStmt->fetch(PDO::FETCH_ASSOC)) {
                            if (!empty($fRow['phone'])) {
                                enqueueTwilioJob($fRow['phone'], $msg, $schoolId, null, null, $userId, 'NOTIFY_ROLE');
                            }
                        }
                    }
                }

                // Daño sin estudiante: notificar coordinación
                if ($action === 'daño' && !$studentId) {
                    $targetRole = 'COORDINADOR';
                    $msg = "⚠️ *NEXO — Alerta institucional*\n\nTipo: *DAÑO*\nReportado por: {$role}\nDetalle: {$reason}";
                    $dStmt = $conn->prepare("
                        SELECT phone FROM users
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = ?)
                    ");
                    $dStmt->execute([$schoolId, $targetRole]);
                    while ($dRow = $dStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($dRow['phone'])) {
                            enqueueTwilioJob($dRow['phone'], $msg, $schoolId, null, null, $userId, 'NOTIFY_ROLE');
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
