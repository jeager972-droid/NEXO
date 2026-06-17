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

function getTwilioStatusCallbackUrl() {
    $base = getenv('TWILIO_WEBHOOK_URL_BASE') ?: getenv('APP_URL') ?: '';
    if ($base === '') return null;
    return rtrim($base, '/') . '/v1/webhooks/twilio/status';
}

function sendTwilioDirect($to, $body) {
    $sid   = getenv('TWILIO_ACCOUNT_SID');
    $token = getenv('TWILIO_AUTH_TOKEN');
    $from  = getenv('TWILIO_WHATSAPP_FROM') ?: getenv('TWILIO_FROM_NUMBER');
    if (!$sid || !$token || !$from) {
        $missing = [];
        if (!$sid)   $missing[] = 'TWILIO_ACCOUNT_SID';
        if (!$token) $missing[] = 'TWILIO_AUTH_TOKEN';
        if (!$from)  $missing[] = 'TWILIO_WHATSAPP_FROM (or TWILIO_FROM_NUMBER)';
        $err = 'Missing Twilio credentials: ' . implode(', ', $missing);
        securityLog('TWILIO_DIRECT_CREDENTIALS_MISSING', $err);
        error_log("[TWILIO] CREDENTIALS MISSING: " . implode(', ', $missing));
        return ['ok' => false, 'error' => $err];
    }
    $toNorm = preg_replace('/^whatsapp:/i', '', trim((string)$to));
    if ($toNorm !== '' && $toNorm[0] !== '+') $toNorm = '+' . $toNorm;
    $toNorm = preg_replace('/[^0-9\+]/', '', $toNorm);

    $fromNorm = preg_replace('/^whatsapp:/i', '', trim((string)$from));
    if ($fromNorm !== '' && $fromNorm[0] !== '+') $fromNorm = '+' . $fromNorm;
    $fromNorm = preg_replace('/[^0-9\+]/', '', $fromNorm);

    error_log("[TWILIO] sendTwilioDirect from={$fromNorm} to={$toNorm} sid_prefix=" . substr($sid, 0, 6));

    // 1) Intentar mensaje de sesión
    $url     = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $payload = [
        'From' => "whatsapp:$fromNorm",
        'To'   => "whatsapp:$toNorm",
        'Body' => $body
    ];
    $statusCallback = getTwilioStatusCallbackUrl();
    if ($statusCallback) {
        $payload['StatusCallback'] = $statusCallback;
    }

    error_log("[TWILIO] curl init url={$url} to={$toNorm}");
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    error_log("[TWILIO] curl result httpCode={$httpCode} curl_err=" . ($err ?: 'none') . " response_len=" . ($response === false ? 'false' : strlen($response)));

    // 2) Detectar 63016/63015 y reintentar con template
    if ($response !== false && $httpCode >= 400) {
        $json = json_decode($response, true);
        $twilioCode = $json['code'] ?? $json['error_code'] ?? $httpCode;
        error_log("[TWILIO] Twilio error code={$twilioCode} msg=" . ($json['message'] ?? 'n/a'));
        if (in_array($twilioCode, [63016, 63015])) {
            $templateSid = getenv('TWILIO_WHATSAPP_TEMPLATE_SID');
            error_log("[TWILIO] Template fallback templateSid=" . ($templateSid ?: 'NOT_SET'));
            if ($templateSid) {
                $templatePayload = [
                    'From' => "whatsapp:$fromNorm",
                    'To'   => "whatsapp:$toNorm",
                    'ContentSid' => $templateSid,
                    'ContentVariables' => json_encode(['1' => $body], JSON_UNESCAPED_UNICODE)
                ];
                if ($statusCallback) {
                    $templatePayload['StatusCallback'] = $statusCallback;
                }
                $ch2 = curl_init($url);
                curl_setopt($ch2, CURLOPT_POST, true);
                curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query($templatePayload));
                curl_setopt($ch2, CURLOPT_USERPWD, "$sid:$token");
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, true);
                $response2 = curl_exec($ch2);
                $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                error_log("[TWILIO] Template fallback httpCode={$httpCode2}");
                if ($response2 !== false && $httpCode2 < 400) {
                    $json2 = json_decode($response2, true);
                    return ['ok' => true, 'error' => null, 'sid' => $json2['sid'] ?? null];
                }
                return ['ok' => false, 'error' => "[$twilioCode] Template fallback falló", 'sid' => null];
            }
            return ['ok' => false, 'error' => "[$twilioCode] Fuera de ventana de 24h. Configura TWILIO_WHATSAPP_TEMPLATE_SID.", 'sid' => null];
        }
    }

    if ($response === false || $httpCode >= 400) {
        $errorDetail = $err ?: "HTTP $httpCode";
        if ($response) {
            $errorDetail .= " | Response: " . substr($response, 0, 500);
        }
        securityLog('TWILIO_DIRECT_ERROR', "To:$toNorm HTTP:$httpCode Error:$errorDetail");
        error_log("[TWILIO] FAILED: {$errorDetail}");
        return ['ok' => false, 'error' => $errorDetail, 'sid' => null];
    }
    $json = json_decode($response, true);
    $sidStr = isset($json['sid']) ? $json['sid'] : 'N/A';
    securityLog('TWILIO_DIRECT_OK', "SID:{$sidStr} To:$toNorm");
    error_log("[TWILIO] SUCCESS sid={$sidStr}");
    return ['ok' => true, 'error' => null, 'sid' => $json['sid'] ?? null];
}

function sendTwilioNow($to, $body, $schoolId, $studentId = null, $guardianId = null, $senderUserId = null, $typeCode = 'OUTBOUND') {
    global $conn;
    $toNorm = preg_replace('/^whatsapp:/i', '', trim((string)$to));
    if ($toNorm !== '' && $toNorm[0] !== '+') $toNorm = '+' . $toNorm;
    $toNorm = preg_replace('/[^0-9\+]/', '', $toNorm);

    if (empty($toNorm) || $toNorm === '+') {
        securityLog('TWILIO_SEND_SKIPPED', "Invalid destination phone: " . ($to ?? 'NULL'));
        return ['ok' => false, 'reason' => 'missing_or_invalid_phone', 'phone_raw' => $to, 'phone_norm' => $toNorm];
    }

    $result = sendTwilioDirect($to, $body);

    if ($result['ok']) {
        logTwilioMessageSafe($conn, $schoolId, $typeCode, 'OUTBOUND', $toNorm, $body,
            ['action' => 'api_direct_sent', 'source' => 'sendTwilioNow'],
            $studentId, $guardianId, $senderUserId, $result['sid'], 'SENT');
        return ['ok' => true, 'reason' => 'sent', 'sid' => $result['sid'], 'phone_norm' => $toNorm];
    }

    logTwilioMessageSafe($conn, $schoolId, $typeCode, 'OUTBOUND', $toNorm, $body,
        ['action' => 'api_direct_failed', 'error' => $result['error'], 'source' => 'sendTwilioNow'],
        $studentId, $guardianId, $senderUserId, null, 'FAILED');
    return ['ok' => false, 'reason' => 'twilio_api_error', 'error' => $result['error'], 'phone_norm' => $toNorm];
}

function enqueueTwilioJob($to, $body, $schoolId, $studentId = null, $guardianId = null, $senderUserId = null, $typeCode = 'OUTBOUND') {
    $enqueued = false;
    $toNorm = preg_replace('/^whatsapp:/i', '', trim((string)$to));
    if ($toNorm !== '' && $toNorm[0] !== '+') $toNorm = '+' . $toNorm;
    $toNorm = preg_replace('/[^0-9\+]/', '', $toNorm);

    if (empty($toNorm) || $toNorm === '+') {
        securityLog('TWILIO_ENQUEUE_SKIPPED', "Invalid destination phone: " . ($to ?? 'NULL'));
        return ['ok' => false, 'reason' => 'missing_or_invalid_phone', 'phone_raw' => $to, 'phone_norm' => $toNorm];
    }

    try {
        $redis = new Redis();
        $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
        if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
        $redis->select((int)(getenv('REDIS_DB') ?: 0));

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
        return ['ok' => true, 'reason' => 'queued', 'queue' => 'queue:twilio', 'phone_norm' => $toNorm];
    } catch (Exception $e) {
        securityLog('TWILIO_ENQUEUE_FAILED', $e->getMessage());
    }

    // Fallback: si Redis no está disponible, enviar directamente
    if (!$enqueued) {
        $result = sendTwilioDirect($to, $body);
        if ($result['ok']) {
            securityLog('TWILIO_DIRECT_SENT', "SID: {$result['sid']} To: $to");
            return ['ok' => true, 'reason' => 'direct', 'sid' => $result['sid'], 'phone_norm' => $toNorm];
        } else {
            securityLog('TWILIO_DIRECT_FAILED', "To: $to Error: {$result['error']}");
            return ['ok' => false, 'reason' => 'direct_failed', 'error' => $result['error'], 'phone_norm' => $toNorm];
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
        'sos' => ['SUPER_RECTOR', 'RECTOR', 'COORDINADOR', 'DOCENTE', 'SECRETARIA', 'PORTERO', 'AUXILIAR', 'PSICORIENTADOR'],
        'citacion' => ['COORDINADOR', 'DOCENTE', 'PSICORIENTADOR'],
        'inasistencia' => ['DOCENTE', 'COORDINADOR', 'RECTOR', 'SUPER_RECTOR', 'AUXILIAR', 'PORTERO'],
        'autorizar_salida' => ['COORDINADOR', 'RECTOR', 'SUPER_RECTOR'],
        'permiso' => ['DOCENTE', 'COORDINADOR', 'RECTOR', 'SUPER_RECTOR', 'PSICORIENTADOR'],
        'incidente' => ['DOCENTE', 'PSICORIENTADOR'],
        'solicitud' => ['SUPER_RECTOR', 'RECTOR', 'COORDINADOR', 'DOCENTE', 'SECRETARIA', 'PORTERO', 'AUXILIAR', 'PSICORIENTADOR'],
        'daño' => ['AUXILIAR', 'PORTERO'],
        'pedagogica' => ['COORDINADOR', 'RECTOR', 'SUPER_RECTOR'],
        'horario' => ['COORDINADOR', 'RECTOR', 'SUPER_RECTOR']
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

                // Notificar Coordinación y Rectoría por WhatsApp y notificaciones internas
                $reporterName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $role;
                $sosMsg = "🚨 *NEXO — ALERTA SOS*\n\nUbicación: {$location}\nMensaje: {$message}\nReportado por: {$reporterName} ({$role})\n\nVerifique la plataforma inmediatamente.";

                $notifyRoles = [];
                if ($role === 'RECTOR' || $role === 'SUPER_RECTOR') {
                    $notifyRoles = ['COORDINADOR'];
                } else if ($role === 'COORDINADOR') {
                    $notifyRoles = ['RECTOR', 'SUPER_RECTOR'];
                } else {
                    $notifyRoles = ['RECTOR', 'SUPER_RECTOR', 'COORDINADOR'];
                }

                foreach ($notifyRoles as $nr) {
                    $nStmt = $conn->prepare("
                        SELECT user_id, phone FROM users
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = ?) AND active = TRUE
                    ");
                    $nStmt->execute([$schoolId, $nr]);
                    while ($nRow = $nStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($nRow['phone'])) {
                            enqueueTwilioJob($nRow['phone'], $sosMsg, $schoolId, null, null, $userId, 'SOS_ALERT');
                        }
                        // Insertar notificación interna real con metadata
                        try {
                            $sosMeta = json_encode([
                                'location' => $location,
                                'message' => $message,
                                'reporter_name' => $reporterName,
                                'reporter_role' => $role,
                                'action' => 'sos',
                            ], JSON_UNESCAPED_UNICODE);
                            $notifStmt = $conn->prepare("
                                INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                                VALUES (?, ?, 'Alerta SOS', ?, 'SOS', ?::jsonb, NOW())
                            ");
                            $notifStmt->execute([$schoolId, $nRow['user_id'], "{$reporterName} envió una alerta SOS. Ver detalles.", $sosMeta]);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] SOS notification insert error: " . $e->getMessage());
                        }
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode(['status' => 'ok', 'message' => 'Alerta SOS registrada y notificada a directivos']);
                break;

            case 'inasistencia':
                $studentId = $params['student'] ?? $params['student_id'] ?? null;
                if (!$studentId) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'student_id requerido para inasistencia']);
                    break;
                }

                $studentStmt = $conn->prepare("
                    SELECT s.student_id, s.first_name, s.last_name, g.guardian_id, g.whatsapp_phone, u.phone AS guardian_user_phone
                    FROM students s
                    JOIN guardian_student_relationships gsr ON gsr.student_id = s.student_id AND gsr.primary_guardian = TRUE
                    JOIN guardians g ON g.guardian_id = gsr.guardian_id
                    LEFT JOIN users u ON u.user_id = g.user_id
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
                $reason = !empty($params['reason']) ? filter_var($params['reason'], FILTER_SANITIZE_SPECIAL_CHARS) : 'Inasistencia reportada';

                $inasistMsg = "📋 *NEXO — Reporte de Inasistencia*\n\nEstudiante: {$studentName}\nMotivo: {$reason}\n\nSi tiene alguna duda o justificación, por favor contáctese con la institución.";
                $deliveryResults = [];
                $deliveryResults[] = enqueueTwilioJob($target['whatsapp_phone'], $inasistMsg, $schoolId, $studentId, $target['guardian_id'], $userId, 'INASISTENCIA');
                if (!empty($target['guardian_user_phone']) && $target['guardian_user_phone'] !== $target['whatsapp_phone']) {
                    $deliveryResults[] = enqueueTwilioJob($target['guardian_user_phone'], $inasistMsg, $schoolId, $studentId, $target['guardian_id'], $userId, 'INASISTENCIA');
                }

                $anyOk = false;
                $allMissingPhone = true;
                foreach ($deliveryResults as $dr) {
                    if ($dr['ok']) $anyOk = true;
                    if (($dr['reason'] ?? '') !== 'missing_or_invalid_phone') $allMissingPhone = false;
                }

                if (!$anyOk) {
                    if ($allMissingPhone) {
                        securityLog('INASISTENCIA_NO_PHONE', "Student:$studentId Guardian:{$target['guardian_id']} has no whatsapp_phone");
                        http_response_code(422);
                        echo json_encode(['status' => 'error', 'message' => 'El acudiente principal no tiene número de WhatsApp configurado. Actualice los datos del acudiente.']);
                    } else {
                        securityLog('INASISTENCIA_DELIVERY_FAILED', "Student:$studentId Results:" . json_encode($deliveryResults));
                        http_response_code(500);
                        echo json_encode(['status' => 'error', 'message' => 'No se pudo enviar el mensaje. Verifique las credenciales de Twilio.']);
                    }
                    break;
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode(['status' => 'ok', 'message' => 'Inasistencia reportada al acudiente', 'delivery' => $deliveryResults]);
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
                    LEFT JOIN users u ON u.user_id = g.user_id
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
                $citTime = !empty($params['time']) ? filter_var($params['time'], FILTER_SANITIZE_SPECIAL_CHARS) : '';
                $citReason = !empty($params['reason']) ? filter_var($params['reason'], FILTER_SANITIZE_SPECIAL_CHARS) : (!empty($params['message']) ? filter_var($params['message'], FILTER_SANITIZE_SPECIAL_CHARS) : '');

                $citMsg = "Citación para {$studentName}.";
                if ($citTime) $citMsg .= "\nHora: {$citTime}";
                if ($citReason) $citMsg .= "\nMotivo: {$citReason}";
                $citMsg .= "\n\n1 = Confirmo asistencia a la citación.\n2 = Solicito reagendar la citación.";
                $deliveryResults = [];
                $deliveryResults[] = enqueueTwilioJob($target['whatsapp_phone'], $citMsg, $schoolId, $studentId, $target['guardian_id'], $userId, 'CITACION');
                if (!empty($target['guardian_user_phone']) && $target['guardian_user_phone'] !== $target['whatsapp_phone']) {
                    $deliveryResults[] = enqueueTwilioJob($target['guardian_user_phone'], $citMsg, $schoolId, $studentId, $target['guardian_id'], $userId, 'CITACION');
                }

                // Validar que al menos un mensaje fue enviado
                $anyOk = false;
                $allMissingPhone = true;
                foreach ($deliveryResults as $dr) {
                    if ($dr['ok']) $anyOk = true;
                    if (($dr['reason'] ?? '') !== 'missing_or_invalid_phone') $allMissingPhone = false;
                }

                if (!$anyOk) {
                    if ($allMissingPhone) {
                        securityLog('CITACION_NO_PHONE', "Student:$studentId Guardian:{$target['guardian_id']} has no whatsapp_phone");
                        http_response_code(422);
                        $resp = json_encode(['status' => 'error', 'message' => 'El acudiente principal no tiene número de WhatsApp configurado. Actualice los datos del acudiente.']);
                        securityLog('CITACION_RESPONSE', "HTTP 422 | $resp");
                        echo $resp;
                    } else {
                        securityLog('CITACION_DELIVERY_FAILED', "Student:$studentId Results:" . json_encode($deliveryResults));
                        http_response_code(500);
                        $resp = json_encode(['status' => 'error', 'message' => 'No se pudo enviar el mensaje. Verifique las credenciales de Twilio.']);
                        securityLog('CITACION_RESPONSE', "HTTP 500 | $resp");
                        echo $resp;
                    }
                    break;
                }

                // FIX: Persistir estado de conversación en Redis para que el webhook inbound
                // resuelva el student_id exacto en lugar de usar LIMIT 1 arbitrario.
                try {
                    $redisConv = new Redis();
                    $redisConv->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
                    if ($pass = getenv('REDIS_PASSWORD')) $redisConv->auth($pass);
                    $redisConv->select((int)(getenv('REDIS_DB') ?: 0));
                    $convPayload = json_encode(['student_id' => (string)$studentId, 'guardian_id' => (string)$target['guardian_id'], 'school_id' => (string)$schoolId, 'ts' => time()], JSON_UNESCAPED_UNICODE);
                    $redisConv->setex('conversation:' . preg_replace('/[^0-9+]/', '', $target['whatsapp_phone']), 172800, $convPayload);
                    if (!empty($target['guardian_user_phone']) && $target['guardian_user_phone'] !== $target['whatsapp_phone']) {
                        $redisConv->setex('conversation:' . preg_replace('/[^0-9+]/', '', $target['guardian_user_phone']), 172800, $convPayload);
                    }
                } catch (Exception $e) {
                    securityLog('TWILIO_CONV_REDIS_SKIP', $e->getMessage());
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                $resp = json_encode(['status' => 'ok', 'message' => 'Citación enviada al acudiente', 'delivery' => $deliveryResults]);
                securityLog('CITACION_RESPONSE', "HTTP 200 | $resp");
                echo $resp;
                break;

            case 'permiso':
                $studentId = $params['student'] ?? $params['student_id'] ?? null;
                $reason = trim((string)($params['reason'] ?? $params['message'] ?? $params['description'] ?? ''));
                if ($reason === '') {
                    $reason = 'PERMISO - generado por sistema NEXO';
                }

                if ($studentId) {
                    $stmt = $conn->prepare("
                        INSERT INTO class_exit_authorizations (school_id, student_id, authorized_by_user_id, authorization_reason, exit_time, return_time)
                        VALUES (?, ?, ?, ?, NOW(), NOW() + INTERVAL '1 hour')
                    ");
                    $stmt->execute([$schoolId, $studentId, $userId, $reason]);

                    // Notificar COORDINADOR vía notificaciones internas
                    $stuMetaStmt = $conn->prepare("
                        SELECT s.first_name, s.last_name, ag.group_name
                        FROM students s
                        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
                        WHERE s.student_id = ?
                        LIMIT 1
                    ");
                    $stuMetaStmt->execute([$studentId]);
                    $stuMeta = $stuMetaStmt->fetch(PDO::FETCH_ASSOC);
                    $studentName = ($stuMeta['first_name'] ?? '') . ' ' . ($stuMeta['last_name'] ?? '');
                    $groupName = $stuMeta['group_name'] ?? 'Sin grupo';
                    $teacherName = ($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '');

                    $meta = json_encode([
                        'student_id' => $studentId,
                        'student_name' => trim($studentName),
                        'group_name' => $groupName,
                        'teacher_name' => trim($teacherName) ?: $role,
                        'reason' => $reason,
                        'time_start' => $params['timeStart'] ?? null,
                        'time_end' => $params['timeEnd'] ?? null,
                        'action' => 'permiso',
                    ], JSON_UNESCAPED_UNICODE);

                    $coordStmt = $conn->prepare("
                        SELECT user_id FROM users
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'COORDINADOR') AND active = TRUE
                    ");
                    $coordStmt->execute([$schoolId]);
                    while ($cRow = $coordStmt->fetch(PDO::FETCH_ASSOC)) {
                        try {
                            $notifStmt = $conn->prepare("
                                INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                                VALUES (?, ?, ?, ?, 'INFO', ?::jsonb, NOW())
                            ");
                            $notifStmt->execute([$schoolId, $cRow['user_id'], 'Permiso', "Nuevo permiso registrado. Ver detalles.", $meta]);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Permiso notification insert error: " . $e->getMessage());
                        }
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode([
                    'status'  => 'ok',
                    'message' => 'Permiso generado correctamente',
                    'data'    => ['action' => $action, 'student_id' => $studentId]
                ]);
                break;

            case 'autorizar_salida':
                $studentId = $params['student'] ?? $params['student_id'] ?? null;
                $reason = trim((string)($params['reason'] ?? $params['message'] ?? $params['description'] ?? ''));
                if ($reason === '') {
                    $reason = 'AUTORIZAR_SALIDA - generado por sistema NEXO';
                }

                if ($studentId) {
                    $stmt = $conn->prepare("
                        INSERT INTO school_exit_authorizations (school_id, student_id, authorized_by_user_id, authorization_reason, exit_time, status)
                        VALUES (?, ?, ?, ?, NOW(), 'APPROVED')
                    ");
                    $stmt->execute([$schoolId, $studentId, $userId, $reason]);

                    // Notificar COORDINADOR vía notificaciones internas
                    $stuMetaStmt = $conn->prepare("
                        SELECT s.first_name, s.last_name, ag.group_name
                        FROM students s
                        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
                        WHERE s.student_id = ?
                        LIMIT 1
                    ");
                    $stuMetaStmt->execute([$studentId]);
                    $stuMeta = $stuMetaStmt->fetch(PDO::FETCH_ASSOC);
                    $studentName = ($stuMeta['first_name'] ?? '') . ' ' . ($stuMeta['last_name'] ?? '');
                    $groupName = $stuMeta['group_name'] ?? 'Sin grupo';
                    $teacherName = ($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '');

                    $meta = json_encode([
                        'student_id' => $studentId,
                        'student_name' => trim($studentName),
                        'group_name' => $groupName,
                        'teacher_name' => trim($teacherName) ?: $role,
                        'reason' => $reason,
                        'time_start' => $params['timeStart'] ?? null,
                        'time_end' => $params['timeEnd'] ?? null,
                        'action' => 'autorizar_salida',
                    ], JSON_UNESCAPED_UNICODE);

                    $coordStmt = $conn->prepare("
                        SELECT user_id FROM users
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'COORDINADOR') AND active = TRUE
                    ");
                    $coordStmt->execute([$schoolId]);
                    while ($cRow = $coordStmt->fetch(PDO::FETCH_ASSOC)) {
                        try {
                            $notifStmt = $conn->prepare("
                                INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                                VALUES (?, ?, ?, ?, 'INFO', ?::jsonb, NOW())
                            ");
                            $notifStmt->execute([$schoolId, $cRow['user_id'], 'Salida autorizada', "Nueva salida autorizada registrada. Ver detalles.", $meta]);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Autorizar salida notification insert error: " . $e->getMessage());
                        }
                    }

                    // Enviar WhatsApp al acudiente avisándole
                    if (!empty($stuMeta)) {
                        $guardsStmt = $conn->prepare("
                            SELECT g.guardian_id, g.whatsapp_phone, u.phone AS guardian_user_phone
                            FROM guardians g
                            JOIN guardian_student_relationships gsr ON gsr.guardian_id = g.guardian_id AND gsr.student_id = ? AND gsr.primary_guardian = TRUE
                            LEFT JOIN users u ON u.user_id = g.user_id
                            WHERE u.school_id = ?
                            LIMIT 1
                        ");
                        $guardsStmt->execute([$studentId, $schoolId]);
                        $gRow = $guardsStmt->fetch(PDO::FETCH_ASSOC);
                        if ($gRow && !empty($gRow['whatsapp_phone'])) {
                            $sName = trim($studentName);
                            $issuerName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? ''));
                            $salidaMsg = "\xF0\x9F\x9F\xA2 *NEXO — Salida autorizada*\n\nSe ha permitido la salida de *{$sName}* del colegio.\n\nSi usted no autorizó esto o fue un error, responda *9* a este mensaje y le notificaremos a la institución inmediatamente.";
                            $sendResult = enqueueTwilioJob($gRow['whatsapp_phone'], $salidaMsg, $schoolId, $studentId, $gRow['guardian_id'], $userId, 'AUTORIZAR_SALIDA');

                            // Guardar contexto en Redis para manejar respuesta '9'
                            try {
                                $redisCtx = new Redis();
                                $redisCtx->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
                                if ($pass = getenv('REDIS_PASSWORD')) $redisCtx->auth($pass);
                                $redisCtx->select((int)(getenv('REDIS_DB') ?: 0));
                                $normalizedPhone = preg_replace('/[^0-9+]/', '', $gRow['whatsapp_phone']);
                                $ctxPayload = json_encode([
                                    'action' => 'autorizar_salida',
                                    'student_id' => $studentId,
                                    'student_name' => $sName,
                                    'issuer_user_id' => $userId,
                                    'school_id' => $schoolId,
                                    'ts' => time(),
                                ], JSON_UNESCAPED_UNICODE);
                                $redisCtx->setex('salida_context:' . $normalizedPhone, 86400, $ctxPayload);
                            } catch (Throwable $e) {
                                securityLog('SALIDA_REDIS_CTX_ERROR', $e->getMessage());
                            }
                        }
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode([
                    'status'  => 'ok',
                    'message' => 'Salida autorizada correctamente',
                    'data'    => ['action' => $action, 'student_id' => $studentId]
                ]);
                break;

            case 'pedagogica':
                $groupName   = trim((string)($params['group'] ?? ''));
                $purpose     = trim((string)($params['reason'] ?? $params['message'] ?? $params['description'] ?? ''));
                $destination = trim((string)($params['destination'] ?? $purpose));

                if ($groupName) {
                    // Notificar a todos los acudientes del grupo vía WhatsApp
                    $guardStmt = $conn->prepare("
                        SELECT DISTINCT g.whatsapp_phone, g.guardian_id
                        FROM guardians g
                        JOIN guardian_student_relationships gsr ON g.guardian_id = gsr.guardian_id
                        JOIN student_group_assignments sga ON gsr.student_id = sga.student_id AND sga.active = TRUE
                        JOIN academic_groups ag ON sga.group_id = ag.group_id
                        WHERE ag.group_name = ? AND ag.school_id = ?
                    ");
                    $guardStmt->execute([$groupName, $schoolId]);
                    $msg = "🚌 *NEXO — Salida pedagógica*\n\nGrupo: *{$groupName}*\nMotivo: {$purpose}\n\nMantente informado sobre el regreso de tu estudiante.";
                    while ($gRow = $guardStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($gRow['whatsapp_phone'])) {
                            enqueueTwilioJob($gRow['whatsapp_phone'], $msg, $schoolId, null, $gRow['guardian_id'], $userId, 'PEDAGOGICA');
                        }
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode([
                    'status'  => 'ok',
                    'message' => 'Salida pedagógica registrada y acudientes notificados',
                ]);
                break;

            case 'seguimiento':
                $studentId = $params['student'] ?? $params['student_id'] ?? null;
                $reason = trim((string)($params['reason'] ?? $params['message'] ?? $params['description'] ?? ''));
                
                if ($studentId) {
                    $stuMetaStmt = $conn->prepare("
                        SELECT s.first_name, s.last_name, ag.group_name
                        FROM students s
                        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
                        WHERE s.student_id = ?
                        LIMIT 1
                    ");
                    $stuMetaStmt->execute([$studentId]);
                    $stuMeta = $stuMetaStmt->fetch(PDO::FETCH_ASSOC);
                    $studentName = ($stuMeta['first_name'] ?? '') . ' ' . ($stuMeta['last_name'] ?? '');
                    $groupName = $stuMeta['group_name'] ?? 'Sin grupo';
                    $senderName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $role;

                    $meta = json_encode([
                        'student_id' => $studentId,
                        'student_name' => trim($studentName),
                        'group_name' => $groupName,
                        'sender_name' => $senderName,
                        'reason' => $reason,
                        'action' => 'iniciar_seguimiento',
                    ], JSON_UNESCAPED_UNICODE);

                    $psicoStmt = $conn->prepare("
                        SELECT user_id FROM users
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'PSICORIENTADOR') AND active = TRUE
                    ");
                    $psicoStmt->execute([$schoolId]);
                    while ($pRow = $psicoStmt->fetch(PDO::FETCH_ASSOC)) {
                        try {
                            $notifStmt = $conn->prepare("
                                INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                                VALUES (?, ?, 'Solicitud de Seguimiento', ?, 'INFO', ?::jsonb, NOW())
                            ");
                            $notifStmt->execute([$schoolId, $pRow['user_id'], "{$senderName} solicita iniciar seguimiento. Ver detalles.", $meta]);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Seguimiento notification insert error: " . $e->getMessage());
                        }
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode([
                    'status'  => 'ok',
                    'message' => 'Solicitud de seguimiento enviada a psicorientación',
                ]);
                break;

            case 'incidente':
            case 'solicitud':
            case 'daño':
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

                // Notificación grupal para cambio de horario (NO para pedagógica — sin aviso a acudientes)
                if ($action === 'horario' && !empty($params['group'])) {
                    $groupName = filter_var($params['group'], FILTER_SANITIZE_SPECIAL_CHARS);
                    $groupStmt = $conn->prepare("
                        SELECT DISTINCT g.whatsapp_phone
                        FROM guardians g
                        JOIN guardian_student_relationships gsr ON g.guardian_id = gsr.guardian_id AND gsr.primary_guardian = TRUE
                        JOIN student_group_assignments sga ON gsr.student_id = sga.student_id AND sga.active = TRUE
                        JOIN academic_groups ag ON sga.group_id = ag.group_id AND ag.group_name = ? AND ag.school_id = ?
                    ");
                    $groupStmt->execute([$groupName, $schoolId]);
                    $msg = "\xF0\x9F\x93\xA2 *NEXO*\n\nHubo un *cambio de horario* para el grupo *" . $groupName . "*.\n\nPor favor esté atento a la hora de llegada de su estudiante. Detalle: {$reason}";
                    while ($gRow = $groupStmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($gRow['whatsapp_phone'])) {
                            enqueueTwilioJob($gRow['whatsapp_phone'], $msg, $schoolId, null, null, $userId, 'HORARIO');
                        }
                    }
                }

                // Solicitud interna: guardar mensaje interno + WhatsApp si tiene teléfono + notificación interna
                if ($action === 'solicitud' && !empty($params['recipient_id'])) {
                    $recStmt = $conn->prepare("SELECT phone, first_name, last_name FROM users WHERE user_id = ? AND school_id = ?");
                    $recStmt->execute([$params['recipient_id'], $schoolId]);
                    $recRow = $recStmt->fetch(PDO::FETCH_ASSOC);
                    $senderName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $role;
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
                            $solMsg = "📨 *NEXO — Solicitud interna*\n\nDe: *{$senderName}* ({$role})\nMensaje: {$reason}\n\nResponde por la plataforma.";
                            enqueueTwilioJob($recRow['phone'], $solMsg, $schoolId, null, null, $userId, 'SOLICITUD');
                        }

                        // Notificación interna con metadata
                        try {
                            $solMeta = json_encode([
                                'sender_name' => $senderName,
                                'sender_role' => $role,
                                'reason' => $reason,
                                'action' => 'solicitud',
                            ], JSON_UNESCAPED_UNICODE);
                            $solNotif = $conn->prepare("
                                INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                                VALUES (?, ?, 'Solicitud', ?, 'INFO', ?::jsonb, NOW())
                            ");
                            $solNotif->execute([$schoolId, $params['recipient_id'], "{$senderName} te envió una solicitud. Ver detalles.", $solMeta]);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Solicitud notification insert error: " . $e->getMessage());
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
                    // Fallback: sin targets ni estudiante → notificar coordinación vía WhatsApp
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

                    // Internal notifications (DB) for COORDINADOR and RECTOR
                    $reporterName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $role;
                    $incMeta = json_encode([
                        'reporter_name' => $reporterName,
                        'reporter_role' => $role,
                        'location' => $params['location'] ?? 'No especificada',
                        'reason' => $reason,
                        'targets' => $targets,
                        'action' => 'incidente',
                    ], JSON_UNESCAPED_UNICODE);

                    $incNotifRoles = [];
                    if (in_array('coordinacion', $targets) || empty($targets)) $incNotifRoles[] = 'COORDINADOR';
                    if (in_array('rector', $targets)) $incNotifRoles[] = 'RECTOR';
                    foreach (array_unique($incNotifRoles) as $incRole) {
                        $incStmt2 = $conn->prepare("
                            SELECT user_id FROM users
                            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = ?) AND active = TRUE
                        ");
                        $incStmt2->execute([$schoolId, $incRole]);
                        while ($incRow = $incStmt2->fetch(PDO::FETCH_ASSOC)) {
                            try {
                                $incNotif = $conn->prepare("
                                    INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                                    VALUES (?, ?, ?, ?, 'SOS', ?::jsonb, NOW())
                                ");
                                $incNotif->execute([$schoolId, $incRow['user_id'], 'Incidente', "Nuevo incidente reportado. Ver detalles.", $incMeta]);
                            } catch (Throwable $e) {
                                error_log("[OPERATIONS] Incidente notification insert error: " . $e->getMessage());
                            }
                        }
                    }
                }

                // Daño sin estudiante: notificar coordinación
                if ($action === 'daño' && !$studentId) {
                    $targetRole   = 'COORDINADOR';
                    $locationDaño = trim((string)($params['location'] ?? 'No especificada'));
                    $msg = "⚠️ *NEXO — Alerta institucional*\n\nTipo: *DAÑO*\nUbicación: {$locationDaño}\nReportado por: {$role}\nDetalle: {$reason}";
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
