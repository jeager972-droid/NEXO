<?php
/**
 * =============================================================================
 * routes/operations.php — Comandos operativos y acciones de usuario.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Recibe acciones (comandos) del frontend a través del endpoint
 * POST /operations/execute. Cada comando (sos, inasistencia, citacion, permiso,
 * autorizar_salida, pedagogica, seguimiento, incidente, solicitud, daño,
 * horario) ejecuta lógica específica: inserta registros en la base de datos,
 * notifica a acudientes/teachers/coordinadores vía Twilio, y deja trazabilidad
 * en user_commands. También incluye un endpoint para consultar estado de
 * mensajes Twilio y reenviar si es necesario.
 *
 * FLUJO GENERAL
 * -------------
 *   POST /operations/execute {action, ...payload}
 *        │
 *        ▼
 *   logUserCommand(...) ──► switch($action)
 *        │
 *        ├── sos            ──► INSERT sos_alerts + notificar
 *        ├── inasistencia   ──► INSERT attendance_incidents
 *        ├── citacion       ──► INSERT + WhatsApp a acudiente
 *        ├── permiso        ──► INSERT class_exit_authorizations
 *        ├── autorizar_salida/salida ──► INSERT school_exit_authorizations + WhatsApp
 *        ├── pedagogica     ──► INSERT + notificar
 *        ├── seguimiento    ──► INSERT student_tracking + notificación
 *        ├── incidente      ──► INSERT attendance_incidents
 *        ├── solicitud      ──► INSERT internal_messages
 *        ├── daño           ──► INSERT user_commands + notificar
 *        └── horario        ──► UPDATE schedules
 *        │
 *        ▼
 *   JSON {status:'ok', data}
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación, roles, getRedisConnection.
 *   - lib/twilio.php : normalizeWhatsAppPhone, sendTwilioDirect, logTwilioMessage.
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Frontend: formularios de comando (SOS, citaciones, permisos, etc.).
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';
require_once __DIR__ . '/../lib/twilio.php';

/**
 * Registra un comando ejecutado por un usuario en user_commands.
 *
 * @param PDO $conn Conexión PDO.
 * @param string $schoolId UUID de la escuela.
 * @param string $userId UUID del ejecutor.
 * @param string $action Tipo de comando.
 * @param array $payload Datos adicionales del comando.
 * @return void
 */
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

/**
 * Envía un mensaje WhatsApp de forma síncrona, con validación de teléfono.
 *
 * @param string $to Número destino (raw).
 * @param string $body Cuerpo del mensaje.
 * @param string $schoolId UUID de la escuela.
 * @param string|null $studentId UUID del estudiante relacionado.
 * @param string|null $guardianId UUID del acudiente relacionado.
 * @param string|null $senderUserId UUID del remitente.
 * @param string $typeCode Código de tipo (p. ej. 'CITACION').
 * @return array Resultado de la operación (ok, reason, sid, etc.).
 */
function sendTwilioNow($to, $body, $schoolId, $studentId = null, $guardianId = null, $senderUserId = null, $typeCode = 'OUTBOUND') {
    global $conn;
    $toNorm = normalizeWhatsAppPhone($to);
    if (empty($toNorm) || $toNorm === '+') {
        securityLog('TWILIO_SEND_SKIPPED', "Invalid destination phone: " . ($to ?? 'NULL'));
        return ['ok' => false, 'reason' => 'missing_or_invalid_phone', 'phone_raw' => $to, 'phone_norm' => $toNorm];
    }
    return enqueueTwilioJob($to, $body, $schoolId, $studentId, $guardianId, $senderUserId, $typeCode);
}

/**
 * Encola un trabajo de Twilio en Redis para worker_twilio.php, con fallback directo.
 *
 * @param string $to Número destino (raw).
 * @param string $body Cuerpo del mensaje.
 * @param string $schoolId UUID de la escuela.
 * @param string|null $studentId UUID del estudiante.
 * @param string|null $guardianId UUID del acudiente.
 * @param string|null $senderUserId UUID del remitente.
 * @param string $typeCode Código de tipo del mensaje.
 * @return array Resultado: {ok, reason, queue/message_id/sid}.
 *
 * Nota: Si Redis no está disponible, cae a sendTwilioDirect().
 */
function enqueueTwilioJob($to, $body, $schoolId, $studentId = null, $guardianId = null, $senderUserId = null, $typeCode = 'OUTBOUND') {
    global $conn;
    $toNorm = normalizeWhatsAppPhone($to);
    if (empty($toNorm) || $toNorm === '+') {
        securityLog('TWILIO_ENQUEUE_SKIPPED', "Invalid destination phone: " . ($to ?? 'NULL'));
        return ['ok' => false, 'reason' => 'missing_or_invalid_phone', 'phone_raw' => $to, 'phone_norm' => $toNorm];
    }
    $msgId = null;
    try {
        if ($conn) {
            $stmt  = $conn->query("SELECT uuid_generate_v4()");
            $msgId = $stmt->fetchColumn();
            $ins   = $conn->prepare("
                INSERT INTO twilio_messages (
                    twilio_message_id, school_id, student_id, guardian_id, sender_user_id,
                    type_code, direction, phone_number, message_content, delivery_status, sent_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'OUTBOUND', ?, ?, 'QUEUED', NOW())
            ");
            $ins->execute([$msgId, $schoolId, $studentId, $guardianId, $senderUserId, $typeCode, $toNorm, $body]);
        }
    } catch (Exception $e) {
        securityLog('TWILIO_PRE_INSERT_FAILED', $e->getMessage());
    }
    try {
        $redis = getRedisConnection();
        if (!$redis) {
            securityLog('TWILIO_REDIS_UNAVAILABLE', 'Redis unavailable for Twilio queue');
        } else {
            $redis->rPush('queue:twilio', json_encode([
                'message_id' => $msgId, 'to' => $toNorm, 'body' => $body,
                'school_id' => $schoolId, 'student_id' => $studentId,
                'guardian_id' => $guardianId, 'sender_user_id' => $senderUserId,
                'type_code' => $typeCode, 'retries' => 0, 'created_at' => time()
            ], JSON_UNESCAPED_UNICODE));
            return ['ok' => true, 'reason' => 'queued', 'queue' => 'queue:twilio', 'phone_norm' => $toNorm, 'message_id' => $msgId];
        }
    } catch (Exception $e) {
        securityLog('TWILIO_ENQUEUE_FAILED', $e->getMessage());
    }
    $result = sendTwilioDirect($to, $body);
    if ($result['ok']) {
        securityLog('TWILIO_DIRECT_SENT', "SID: {$result['sid']} To: $to");
        return ['ok' => true, 'reason' => 'direct', 'sid' => $result['sid'], 'phone_norm' => $toNorm];
    }
    securityLog('TWILIO_DIRECT_FAILED', "To: $to Error: {$result['error']}");
    return ['ok' => false, 'reason' => 'direct_failed', 'error' => $result['error'], 'phone_norm' => $toNorm];
}
// ============================================================================
// Rutas bajo /operations/*
//   - POST /operations/twilio-status : consulta masiva de estado de mensajes Twilio.
//   - POST /operations/<command>     : dispatcher a switch de comandos operativos.
// También acepta action=EXECUTE_COMMAND legacy por compatibilidad.
// ============================================================================
if (strpos($cleanPath, '/operations/') === 0 || (isset($input['action']) && $input['action'] === 'EXECUTE_COMMAND')) {
    
    // POST /operations/twilio-status — Estado de mensajes, fallback a API de Twilio.
    if ($cleanPath === '/operations/twilio-status' && $method === 'POST') {
        $msgIds = $input['message_ids'] ?? [];
        if (empty($msgIds) || !is_array($msgIds)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'message_ids is required']));
        }
        $in = str_repeat('?,', count($msgIds) - 1) . '?';
        $stmt = $conn->prepare("SELECT twilio_message_id, delivery_status, provider_message_sid FROM twilio_messages WHERE twilio_message_id IN ($in)");
        $stmt->execute($msgIds);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $needsTwilioCheck = [];
        foreach ($results as &$msg) {
            if ($msg['delivery_status'] === 'QUEUED' && !empty($msg['provider_message_sid'])) {
                $needsTwilioCheck[] = $msg['provider_message_sid'];
            }
        }
        
        if (!empty($needsTwilioCheck)) {
            $sid = getenv('TWILIO_ACCOUNT_SID');
            $token = getenv('TWILIO_AUTH_TOKEN');
            if ($sid && $token) {
                foreach ($needsTwilioCheck as $providerSid) {
                    try {
                        $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages/$providerSid.json";
                        $ch = curl_init($url);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_USERPWD => "$sid:$token",
                            CURLOPT_TIMEOUT => 3,
                        ]);
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        
                        if ($response && $httpCode === 200) {
                            $twilioData = json_decode($response, true);
                            $twilioStatus = strtoupper($twilioData['status'] ?? 'UNKNOWN');
                            
                            $updateStmt = $conn->prepare("UPDATE twilio_messages SET delivery_status = ? WHERE provider_message_sid = ?");
                            $updateStmt->execute([$twilioStatus, $providerSid]);
                            
                            foreach ($results as &$r) {
                                if ($r['provider_message_sid'] === $providerSid) {
                                    $r['delivery_status'] = $twilioStatus;
                                    $r['_source'] = 'twilio_api_fallback';
                                }
                            }
                            
                            error_log("[TWILIO_STATUS] Fallback API check: SID $providerSid Status $twilioStatus");
                        }
                    } catch (Exception $e) {
                        error_log("[TWILIO_STATUS] Fallback API error for $providerSid: " . $e->getMessage());
                    }
                }
            }
        }
        
        exit(json_encode(['status' => 'ok', 'data' => $results]));
    }

    $action = filter_var($input['command'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $pathMap = [
        '/operations/sos' => 'sos',
        '/operations/situacion_critica' => 'situacion_critica',
        '/operations/inasistencia' => 'inasistencia',
        '/operations/citacion' => 'citacion',
        '/operations/salida' => 'autorizar_salida',
        '/operations/permiso' => 'permiso',
        '/operations/solicitud' => 'solicitud',
        '/operations/daño' => 'daño',
        '/operations/pedagogica' => 'pedagogica',
        '/operations/horario' => 'horario',
        '/operations/incidente' => 'incidente',
        '/operations/seguimiento' => 'seguimiento',
    ];
    if (isset($pathMap[$cleanPath])) {
        $action = $pathMap[$cleanPath];
    }

    $authUser = requireAuth();
    $userId = $authUser['id'];
    $schoolId = $authUser['school_id'];
    $role = $authUser['role'];
    $params = $input['params'] ?? [];

    if (!in_array('operations.' . $action, $authUser['permissions'] ?? [])) {
        securityLog('UNAUTHORIZED_COMMAND_ATTEMPT', "User: $userId, Role: $role, Cmd: $action");
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Acceso restringido']));
    }

    // Dispatcher de comandos operativos. Cada case inserta/actualiza DB, notifica
    // por Twilio a acudientes/directivos y registra user_commands.
    // Acciones: sos, inasistencia, citacion, autorizar_salida, permiso, solicitud,
    // daño, pedagogica, horario, incidente, seguimiento.
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

                $reporterName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $role;
                $sosMsg = "🚨 *NEXO — ALERTA SOS*\n\nUbicación: {$location}\nMensaje: {$message}\nReportado por: {$reporterName} ({$role})\n\nVerifique la plataforma inmediatamente.";

                $notifyRoles = [];
                if ($role === 'RECTOR') {
                    $notifyRoles = ['COORDINATOR'];
                } else if ($role === 'COORDINATOR') {
                    $notifyRoles = ['RECTOR'];
                } else {
                    $notifyRoles = ['RECTOR', 'COORDINATOR'];
                }

                $placeholders = implode(',', array_fill(0, count($notifyRoles), '?'));
                $nStmt = $conn->prepare("
                    SELECT user_id, phone FROM users
                    WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($placeholders)) AND active = TRUE
                ");
                $nStmt->execute(array_merge([$schoolId], $notifyRoles));
                $recipients = $nStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($recipients)) {
                    foreach ($recipients as $r) {
                        if (!empty($r['phone'])) {
                            enqueueTwilioJob($r['phone'], $sosMsg, $schoolId, null, null, $userId, 'SOS_ALERT');
                        }
                    }

                    $rows = [];
                    $params = [];
                    $sosMeta = json_encode([
                        'location' => $location,
                        'message' => $message,
                        'reporter_name' => $reporterName,
                        'reporter_role' => $role,
                        'action' => 'sos',
                    ], JSON_UNESCAPED_UNICODE);
                    foreach ($recipients as $r) {
                        $rows[] = "(?, ?, 'Alerta SOS', ?, 'SOS', ?::jsonb, NOW())";
                        $params[] = $schoolId;
                        $params[] = $r['user_id'];
                        $params[] = "Se emitió una alerta SOS por {$reporterName}. Ver detalles.";
                        $params[] = $sosMeta;
                    }
                    $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                    try {
                        $conn->prepare($sql)->execute($params);
                    } catch (Throwable $e) {
                        error_log("[OPERATIONS] SOS notification batch insert error: " . $e->getMessage());
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode(['status' => 'ok', 'message' => 'Alerta SOS registrada y notificada a directivos']);
                break;

            case 'situacion_critica':
                $location = filter_var($params['location'] ?? 'Ubicación no definida', FILTER_SANITIZE_SPECIAL_CHARS);
                $message = filter_var($params['message'] ?? 'Situación crítica reportada', FILTER_SANITIZE_SPECIAL_CHARS);

                $reporterName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $role;
                $critMeta = json_encode([
                    'location' => $location,
                    'message' => $message,
                    'reporter_name' => $reporterName,
                    'reporter_role' => $role,
                    'action' => 'situacion_critica',
                ], JSON_UNESCAPED_UNICODE);

                // Insertar en attendance_incidents para trazabilidad
                $incStmt = $conn->prepare("
                    INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                    VALUES (uuid_generate_v4(), ?, NULL, 'SITUACION_CRITICA', NOW(), ?::jsonb)
                ");
                $incStmt->execute([$schoolId, $critMeta]);

                // Notificar a RECTOR y COORDINATOR
                $notifyRoles = ['RECTOR', 'COORDINATOR'];
                $placeholders = implode(',', array_fill(0, count($notifyRoles), '?'));
                $nStmt = $conn->prepare("
                    SELECT user_id, phone FROM users
                    WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($placeholders)) AND active = TRUE
                ");
                $nStmt->execute(array_merge([$schoolId], $notifyRoles));
                $recipients = $nStmt->fetchAll(PDO::FETCH_ASSOC);

                $critMsg = "🚨 *NEXO — SITUACIÓN CRÍTICA*\n\nUbicación: {$location}\nDetalle: {$message}\nReportado por: {$reporterName} ({$role})\n\nVerifique la plataforma inmediatamente.";

                if (!empty($recipients)) {
                    foreach ($recipients as $r) {
                        if (!empty($r['phone'])) {
                            enqueueTwilioJob($r['phone'], $critMsg, $schoolId, null, null, $userId, 'CRITICAL_SITUATION');
                        }
                    }

                    $rows = [];
                    $notifParams = [];
                    foreach ($recipients as $r) {
                        $rows[] = "(?, ?, 'Situación Crítica', ?, 'SOS', ?::jsonb, NOW())";
                        $notifParams[] = $schoolId;
                        $notifParams[] = $r['user_id'];
                        $notifParams[] = "Se reportó una situación crítica por {$reporterName}. Ver detalles.";
                        $notifParams[] = $critMeta;
                    }
                    $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                    try {
                        $conn->prepare($sql)->execute($notifParams);
                    } catch (Throwable $e) {
                        error_log("[OPERATIONS] Situación crítica notification batch insert error: " . $e->getMessage());
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode(['status' => 'ok', 'message' => 'Situación crítica registrada y notificada a directivos']);
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

                // Registrar la inasistencia en attendance_incidents para que el dashboard la cuente
                $incStmt = $conn->prepare("
                    INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at)
                    VALUES (uuid_generate_v4(), ?, ?, 'INASISTENCIA', NOW())
                ");
                $incStmt->execute([$schoolId, $studentId]);

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

                // Enriquecer params con student_name para el event feed
                $logParams = $params;
                if ($studentId) {
                    $logParams['student_name'] = $studentName;
                }
                logUserCommand($conn, $schoolId, $userId, $action, $logParams);
                echo json_encode(['status' => 'ok', 'message' => 'Inasistencia reportada al acudiente', 'delivery' => $deliveryResults]);
                break;

            case 'citacion':
                $studentId = $params['student'] ?? $params['student_id'] ?? null;
                if (!$studentId) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'student_id requerido para citación']);
                    break;
                }

                $isTeacher = in_array('dashboard.teacher_view', $authUser['permissions'] ?? []);
                if ($isTeacher) {
                    $valStmt = $conn->prepare("
                        SELECT 1 FROM student_group_assignments sga
                        JOIN schedules sch ON sch.group_id = sga.group_id
                        WHERE sga.student_id = ? AND sch.teacher_user_id = ? AND sga.active = TRUE
                    ");
                    $valStmt->execute([$studentId, $userId]);
                    if (!$valStmt->fetchColumn()) {
                        http_response_code(403);
                        echo json_encode(['status' => 'error', 'message' => 'No puedes citar a un estudiante que no pertenece a tus grupos.']);
                        break;
                    }
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
                $citTime = !empty($params['time']) ? trim((string)$params['time']) : '';
                $citReason = !empty($params['reason']) ? trim((string)$params['reason']) : (!empty($params['message']) ? trim((string)$params['message']) : '');

                $citMsg = "Citación para {$studentName}.";
                if ($citTime) $citMsg .= "\nHora: {$citTime}";
                if ($citReason) $citMsg .= "\nMotivo: {$citReason}";
                $citMsg .= "\n\n1 = Confirmo asistencia a la citación.\n2 = Solicito reagendar la citación.";
                $deliveryResults = [];
                $deliveryResults[] = enqueueTwilioJob($target['whatsapp_phone'], $citMsg, $schoolId, $studentId, $target['guardian_id'], $userId, 'CITACION');
                if (!empty($target['guardian_user_phone']) && $target['guardian_user_phone'] !== $target['whatsapp_phone']) {
                    $deliveryResults[] = enqueueTwilioJob($target['guardian_user_phone'], $citMsg, $schoolId, $studentId, $target['guardian_id'], $userId, 'CITACION');
                }

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

                try {
                    $redisConv = getRedisConnection();
                    if ($redisConv) {
                        $convPayload = json_encode(['student_id' => (string)$studentId, 'guardian_id' => (string)$target['guardian_id'], 'school_id' => (string)$schoolId, 'ts' => time()], JSON_UNESCAPED_UNICODE);
                        $redisConv->setex('conversation:' . preg_replace('/[^0-9+]/', '', $target['whatsapp_phone']), 172800, $convPayload);
                        if (!empty($target['guardian_user_phone']) && $target['guardian_user_phone'] !== $target['whatsapp_phone']) {
                            $redisConv->setex('conversation:' . preg_replace('/[^0-9+]/', '', $target['guardian_user_phone']), 172800, $convPayload);
                        }
                    }
                } catch (Exception $e) {
                    securityLog('TWILIO_CONV_REDIS_SKIP', $e->getMessage());
                }

                // Enriquecer params con student_name para el event feed
                $logParams = $params;
                if ($studentId && $target) {
                    $logParams['student_name'] = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));
                }
                logUserCommand($conn, $schoolId, $userId, $action, $logParams);
                $resp = json_encode(['status' => 'ok', 'message' => 'Citación encolada para envío. Puede tardar unos segundos.', 'delivery' => $deliveryResults]);
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

                    // FIX: Insertar en attendance_incidents para que aparezca en el dashboard
                    $incStmt = $conn->prepare("
                        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                        VALUES (uuid_generate_v4(), ?, ?, 'PERMISO', NOW(), ?::jsonb)
                    ");
                    $incStmt->execute([$schoolId, $studentId, $meta]);

                    // FIX: Batch INSERT notifications para coordinadores
                    $coordStmt = $conn->prepare("
                        SELECT user_id FROM users
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'COORDINATOR') AND active = TRUE
                    ");
                    $coordStmt->execute([$schoolId]);
                    $coords = $coordStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($coords)) {
                        $rows = [];
                        $params = [];
                        foreach ($coords as $c) {
                            $rows[] = "(?, ?, ?, ?, 'INFO', ?::jsonb, NOW())";
                            $params[] = $schoolId;
                            $params[] = $c['user_id'];
                            $params[] = 'Permiso';
                            $params[] = "Se registró un permiso" . ($studentName ? " para {$studentName}" : '') . ". Ver detalles.";
                            $params[] = $meta;
                        }
                        $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                        try {
                            $conn->prepare($sql)->execute($params);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Permiso notification batch insert error: " . $e->getMessage());
                        }
                    }
                }

                // Enriquecer params con student_name para el event feed
                $logParams = $params;
                if ($studentId && !empty($stuMeta)) {
                    $logParams['student_name'] = trim($studentName);
                    $logParams['group_name'] = $groupName;
                }
                logUserCommand($conn, $schoolId, $userId, $action, $logParams);
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

                    // FIX: Insertar en attendance_incidents para que aparezca en el dashboard
                    $incStmt = $conn->prepare("
                        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                        VALUES (uuid_generate_v4(), ?, ?, 'AUTORIZAR_SALIDA', NOW(), ?::jsonb)
                    ");
                    $incStmt->execute([$schoolId, $studentId, $meta]);

                    // FIX: Batch INSERT notifications para coordinadores
                    $coordStmt = $conn->prepare("
                        SELECT user_id FROM users
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'COORDINATOR') AND active = TRUE
                    ");
                    $coordStmt->execute([$schoolId]);
                    $coords = $coordStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($coords)) {
                        $rows = [];
                        $params = [];
                        foreach ($coords as $c) {
                            $rows[] = "(?, ?, ?, ?, 'INFO', ?::jsonb, NOW())";
                            $params[] = $schoolId;
                            $params[] = $c['user_id'];
                            $params[] = 'Salida autorizada';
                            $params[] = "Se autorizó una salida" . ($studentName ? " para {$studentName}" : '') . ". Ver detalles.";
                            $params[] = $meta;
                        }
                        $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                        try {
                            $conn->prepare($sql)->execute($params);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Autorizar salida notification batch insert error: " . $e->getMessage());
                        }
                    }

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

                            try {
                                $redisCtx = getRedisConnection();
                                if ($redisCtx) {
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
                                }
                            } catch (Throwable $e) {
                                securityLog('SALIDA_REDIS_CTX_ERROR', $e->getMessage());
                            }
                        }
                    }
                }

                // Enriquecer params con student_name para el event feed
                $logParams = $params;
                if ($studentId && !empty($stuMeta)) {
                    $logParams['student_name'] = trim($studentName);
                    $logParams['group_name'] = $groupName;
                }
                logUserCommand($conn, $schoolId, $userId, $action, $logParams);
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
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'COUNSELOR') AND active = TRUE
                    ");
                    $psicoStmt->execute([$schoolId]);
                    $psicos = $psicoStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($psicos)) {
                        $rows = [];
                        $params = [];
                        foreach ($psicos as $p) {
                            $rows[] = "(?, ?, 'Solicitud de Seguimiento', ?, 'INFO', ?::jsonb, NOW())";
                            $params[] = $schoolId;
                            $params[] = $p['user_id'];
                            $params[] = "Se inició un seguimiento" . ($studentName ? " para {$studentName}" : '') . " solicitado por {$senderName}. Ver detalles.";
                            $params[] = $meta;
                        }
                        $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                        try {
                            $conn->prepare($sql)->execute($params);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Seguimiento notification batch insert error: " . $e->getMessage());
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

                if ($action === 'horario') {
                    // Persistir configuración de jornada (ScheduleTask) en daily_schedule_config
                    $changes = $params['changes'] ?? [];
                    foreach ($changes as $change) {
                        $groupName = $change['group'] ?? '';
                        $noClasses = $change['no_classes'] ?? false;
                        $entryTime = $change['entry_time'] ?? null;
                        $exitTime = $change['exit_time'] ?? null;

                        // Buscar group_id por nombre
                        $grpStmt = $conn->prepare("SELECT group_id FROM academic_groups WHERE group_name = ? AND school_id = ? LIMIT 1");
                        $grpStmt->execute([$groupName, $schoolId]);
                        $groupId = $grpStmt->fetchColumn();
                        if (!$groupId) continue;

                        $dscStmt = $conn->prepare("
                            INSERT INTO daily_schedule_config (school_id, group_id, config_date, has_classes, expected_entry_time, expected_exit_time, created_by_user_id)
                            VALUES (?, ?, CURRENT_DATE, ?, ?, ?, ?)
                            ON CONFLICT (school_id, group_id, config_date)
                            DO UPDATE SET has_classes = EXCLUDED.has_classes, expected_entry_time = EXCLUDED.expected_entry_time, expected_exit_time = EXCLUDED.expected_exit_time
                        ");
                        $dscStmt->execute([$schoolId, $groupId, !$noClasses, $entryTime, $exitTime, $authUser['id']]);
                    }
                }

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
                            $solNotif->execute([$schoolId, $params['recipient_id'], "Se envió una solicitud interna de {$senderName}. Ver detalles.", $solMeta]);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Solicitud notification insert error: " . $e->getMessage());
                        }
                    }
                }

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
                            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'COORDINATOR')
                        ");
                        $cStmt->execute([$schoolId]);
                        while ($cRow = $cStmt->fetch(PDO::FETCH_ASSOC)) {
                            if (!empty($cRow['phone'])) {
                                $msg = "⚠️ *NEXO — Reporte de incidente*\n\nReportado por: {$role}\nDetalle: {$reason}";
                                enqueueTwilioJob($cRow['phone'], $msg, $schoolId, null, null, $userId, 'NOTIFY_ROLE');
                            }
                        }
                    }
                    if (empty($targets) && !$studentId) {
                        $targetRole = 'COORDINATOR';
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
                    if (in_array('coordinacion', $targets) || empty($targets)) $incNotifRoles[] = 'COORDINATOR';
                    if (in_array('rector', $targets)) $incNotifRoles[] = 'RECTOR';
                    if (!empty($incNotifRoles)) {
                        $placeholders = implode(',', array_fill(0, count($incNotifRoles), '?'));
                        $incStmt2 = $conn->prepare("
                            SELECT user_id FROM users
                            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($placeholders)) AND active = TRUE
                        ");
                        $incStmt2->execute(array_merge([$schoolId], $incNotifRoles));
                        $incRecipients = $incStmt2->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($incRecipients)) {
                            $rows = [];
                            $params = [];
                            foreach ($incRecipients as $r) {
                                $rows[] = "(?, ?, ?, ?, 'SOS', ?::jsonb, NOW())";
                                $params[] = $schoolId;
                                $params[] = $r['user_id'];
                                $params[] = 'Incidente';
                                $params[] = "Se reportó un incidente" . ($reason ? ": {$reason}" : '') . ". Ver detalles.";
                                $params[] = $incMeta;
                            }
                            $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                            try {
                                $conn->prepare($sql)->execute($params);
                            } catch (Throwable $e) {
                                error_log("[OPERATIONS] Incidente notification batch insert error: " . $e->getMessage());
                            }
                        }
                    }
                }

                if ($action === 'daño' && !$studentId) {
                    $targetRole   = 'COORDINATOR';
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
