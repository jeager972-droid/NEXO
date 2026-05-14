<?php
// routes/misc.php - Rutas misceláneas
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

function normalizeWhatsAppPhone($value) {
    $value = trim((string)$value);
    $value = preg_replace('/^whatsapp:/i', '', $value);
    if ($value === '') return '';
    if ($value[0] !== '+') $value = '+' . $value;
    return preg_replace('/[^0-9\+]/', '', $value);
}

function verifyTwilioSignature() {
    $authToken = getenv('TWILIO_AUTH_TOKEN') ?: '';
    $provided = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
    if ($authToken === '' || $provided === '') {
        return false;
    }

    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $scheme = $forwardedProto !== '' ? $forwardedProto : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $url = $scheme . '://' . $host . $uri;
    $fixedUrl = getenv('TWILIO_WEBHOOK_URL') ?: '';
    $params = $_POST ?: [];
    ksort($params);
    $compute = function ($baseUrl) use ($params, $authToken) {
        $data = $baseUrl;
        foreach ($params as $k => $v) {
            $data .= $k . $v;
        }
        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    };

    $expected1 = $compute($url);
    if (hash_equals($expected1, $provided)) {
        return true;
    }

    if ($fixedUrl !== '') {
        $expected2 = $compute($fixedUrl);
        if (hash_equals($expected2, $provided)) {
            return true;
        }
    }

    return false;
}

if ($cleanPath === '/audit/logs') {
    $authUser = requireAuth(['RECTOR']);
    echo json_encode([
        'status' => 'ok',
        'data' => [],
        'meta' => [
            'viewer_role' => $authUser['role']
        ]
    ]);
    exit;
}
if ($cleanPath === '/notifications') {
    $authUser = requireAuth();

    if ($method === 'POST') {
        $title = trim((string)($input['title'] ?? 'Notificación interna'));
        $desc = trim((string)($input['desc'] ?? $input['message'] ?? ''));
        $type = strtoupper(trim((string)($input['type'] ?? 'INFO')));
        if ($desc === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Mensaje de notificación requerido']);
            exit;
        }

        securityLog('INTERNAL_NOTIFICATION', "Role:{$authUser['role']} User:{$authUser['id']} Type:$type");
        echo json_encode([
            'status' => 'ok',
            'data' => [
                'id' => uniqid('notif_', true),
                'title' => $title,
                'desc' => $desc,
                'type' => $type,
                'sender' => $authUser['nombre'],
                'time' => gmdate('H:i')
            ]
        ]);
        exit;
    }

    try {
        $notifications = [];

        $sosStmt = $conn->prepare("
            SELECT alert_id AS id,
                   'SOS' AS type,
                   'Alerta SOS' AS title,
                   alert_description AS desc,
                   TO_CHAR(emitted_at, 'HH24:MI') AS time,
                   emitted_at AS occurred_at
            FROM sos_alerts
            WHERE school_id = ?
            ORDER BY emitted_at DESC
            LIMIT 15
        ");
        $sosStmt->execute([$authUser['school_id']]);
        $notifications = array_merge($notifications, $sosStmt->fetchAll(PDO::FETCH_ASSOC));

        $incStmt = $conn->prepare("
            SELECT incident_id AS id,
                   'INFO' AS type,
                   'Incidente de asistencia' AS title,
                   ('Tipo: ' || incident_type) AS desc,
                   TO_CHAR(detected_at, 'HH24:MI') AS time,
                   detected_at AS occurred_at
            FROM attendance_incidents
            WHERE school_id = ?
              AND (
                metadata_json IS NULL
                OR metadata_json->>'target_user_id' IS NULL
                OR metadata_json->>'target_user_id' = ?
              )
            ORDER BY detected_at DESC
            LIMIT 15
        ");
        $incStmt->execute([$authUser['school_id'], (string)$authUser['id']]);
        $notifications = array_merge($notifications, $incStmt->fetchAll(PDO::FETCH_ASSOC));

        usort($notifications, function ($a, $b) {
            return strcmp((string)($b['occurred_at'] ?? ''), (string)($a['occurred_at'] ?? ''));
        });

        $notifications = array_slice($notifications, 0, 20);
        foreach ($notifications as &$notification) {
            unset($notification['occurred_at']);
        }
        echo json_encode(['status' => 'ok', 'data' => $notifications]);
    } catch (Exception $e) {
        securityLog('NOTIFICATIONS_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener notificaciones']);
    }
    exit;
}

if ($cleanPath === '/consultation/search') {
    $authUser = requireAuth();
    $term = trim((string)($_GET['q'] ?? ''));
    if ($term === '') {
        echo json_encode(['status' => 'ok', 'data' => []]);
        exit;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                s.student_id AS id,
                (s.first_name || ' ' || s.last_name) AS name,
                s.document_number,
                COALESCE(ag.group_name, 'Sin grupo') AS group_name
            FROM students s
            LEFT JOIN student_group_assignments sga ON s.student_id = sga.student_id AND sga.active = TRUE
            LEFT JOIN academic_groups ag ON sga.group_id = ag.group_id
            WHERE s.school_id = ?
              AND (
                LOWER(s.first_name) LIKE LOWER(?)
                OR LOWER(s.last_name) LIKE LOWER(?)
                OR s.document_number LIKE ?
              )
            ORDER BY s.last_name, s.first_name
            LIMIT 25
        ");
        $like = '%' . $term . '%';
        $stmt->execute([$authUser['school_id'], $like, $like, $like]);
        echo json_encode(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        securityLog('CONSULTATION_SEARCH_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error en consulta']);
    }
    exit;
}

if ($cleanPath === '/reports/preview') {
    $authUser = requireAuth(['RECTOR', 'COORDINADOR']);
    try {
        $stmt = $conn->prepare("
            SELECT
                event_timestamp::date AS date,
                TO_CHAR(event_timestamp, 'HH24:MI') AS time,
                event_type,
                student_id
            FROM biometric_events
            WHERE school_id = ?
            ORDER BY event_timestamp DESC
            LIMIT 25
        ");
        $stmt->execute([$authUser['school_id']]);
        echo json_encode(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        securityLog('REPORTS_PREVIEW_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener vista previa de reportes']);
    }
    exit;
}

if ($cleanPath === '/webhooks/twilio/inbound') {
    header('Content-Type: text/xml; charset=utf-8');
    if ($method !== 'POST') {
        http_response_code(405);
        echo '<Response></Response>';
        exit;
    }

    if (!verifyTwilioSignature()) {
        securityLog('TWILIO_WEBHOOK_SIGNATURE_REJECTED', 'X-Twilio-Signature verification failed — request dropped');
        http_response_code(403);
        echo '<Response></Response>';
        exit;
    }

    $from = normalizeWhatsAppPhone($_POST['From'] ?? '');
    $body = trim((string)($_POST['Body'] ?? ''));
    $messageSid = trim((string)($_POST['MessageSid'] ?? ''));
    if ($from === '' || $body === '') {
        http_response_code(400);
        echo '<Response></Response>';
        exit;
    }

    try {
        $normalizedFrom = preg_replace('/[^0-9+]/', '', $from);
        $guardianStmt = $conn->prepare("
            SELECT g.guardian_id, u.school_id
            FROM guardians g
            JOIN users u ON u.user_id = g.user_id
            WHERE g.whatsapp_phone_normalized = ?
            LIMIT 1
        ");
        $guardianStmt->execute([$normalizedFrom]);
        $guardian = $guardianStmt->fetch(PDO::FETCH_ASSOC);

        if (!$guardian) {
            securityLog('TWILIO_INBOUND_UNKNOWN_GUARDIAN', "From:$from");
            echo '<Response></Response>';
            exit;
        }

        $schoolId = $guardian['school_id'];
        $guardianId = $guardian['guardian_id'];

        $logInbound = $conn->prepare("
            INSERT INTO twilio_messages (
                twilio_message_id, school_id, guardian_id, type_code, direction, phone_number,
                message_content, provider_message_sid, delivery_status, received_at, sent_at, metadata_json
            ) VALUES (
                uuid_generate_v4(), ?, ?, 'CITACION', 'INBOUND', ?, ?, ?, 'RECEIVED', NOW(), NOW(), ?::jsonb
            )
        ");
        $logInbound->execute([
            $schoolId,
            $guardianId,
            $from,
            $body,
            $messageSid !== '' ? $messageSid : null,
            json_encode(['source' => 'twilio-webhook'], JSON_UNESCAPED_UNICODE)
        ]);

        $trimBody = strtoupper(trim($body));
        // TODO: Si un acudiente tiene múltiples hijos, LIMIT 1 ORDER BY created_at DESC
        // asigna arbitrariamente al más reciente. Implementar: listar hijos y pedir
        // respuesta con el código del estudiante (ej. "1-Juan, 2-Maria").
        if ($trimBody === '1') {
            $replyMsg = "Gracias por confirmar asistencia a la citación.";
            $sendAck = sendTwilioWhatsAppDetailed($from, $replyMsg);
            $logAck = $conn->prepare("
                INSERT INTO twilio_messages (
                    twilio_message_id, school_id, guardian_id, type_code, direction, phone_number,
                    message_content, provider_message_sid, delivery_status, sent_at, metadata_json
                ) VALUES (
                    uuid_generate_v4(), ?, ?, 'CITACION', 'OUTBOUND', ?, ?, ?, ?, NOW(), ?::jsonb
                )
            ");
            $logAck->execute([
                $schoolId,
                $guardianId,
                $sendAck['to'] ?? $from,
                $replyMsg,
                $sendAck['sid'] ?? null,
                $sendAck['ok'] ? 'SENT' : 'FAILED',
                json_encode(['source' => 'twilio-webhook-reply1', 'error' => $sendAck['error'] ?? null], JSON_UNESCAPED_UNICODE)
            ]);
            $auditStmt = $conn->prepare("
                INSERT INTO attendance_incidents (
                    incident_id, school_id, student_id, incident_type, detected_at, metadata_json
                ) VALUES (
                    uuid_generate_v4(), ?, (
                        SELECT s.student_id
                        FROM guardian_student_relationships gsr
                        JOIN students s ON s.student_id = gsr.student_id
                        WHERE gsr.guardian_id = ?
                        ORDER BY gsr.created_at DESC
                        LIMIT 1
                    ), 'CITACION_CONFIRMADA', NOW(), ?::jsonb
                )
            ");
            $auditStmt->execute([
                $schoolId,
                $guardianId,
                json_encode(['response' => '1', 'guardian_phone' => $from], JSON_UNESCAPED_UNICODE)
            ]);
            securityLog('CITACION_CONFIRMADA', "Guardian:$guardianId School:$schoolId");
        } elseif ($trimBody === '2') {
            $replyMsg = "Solicitud de reagendamiento recibida. El profesor se comunicará con usted.";
            $sendAck = sendTwilioWhatsAppDetailed($from, $replyMsg);
            $logAck = $conn->prepare("
                INSERT INTO twilio_messages (
                    twilio_message_id, school_id, guardian_id, type_code, direction, phone_number,
                    message_content, provider_message_sid, delivery_status, sent_at, metadata_json
                ) VALUES (
                    uuid_generate_v4(), ?, ?, 'CITACION', 'OUTBOUND', ?, ?, ?, ?, NOW(), ?::jsonb
                )
            ");
            $logAck->execute([
                $schoolId,
                $guardianId,
                $sendAck['to'] ?? $from,
                $replyMsg,
                $sendAck['sid'] ?? null,
                $sendAck['ok'] ? 'SENT' : 'FAILED',
                json_encode(['source' => 'twilio-webhook-reply2-ack', 'error' => $sendAck['error'] ?? null], JSON_UNESCAPED_UNICODE)
            ]);

            // Notificar al profesor que emitió la citación más reciente para este acudiente.
            $teacherStmt = $conn->prepare("
                SELECT sender_user_id
                FROM twilio_messages
                WHERE school_id = ?
                  AND guardian_id = ?
                  AND type_code = 'CITACION'
                  AND direction = 'OUTBOUND'
                  AND sender_user_id IS NOT NULL
                ORDER BY sent_at DESC
                LIMIT 1
            ");
            $teacherStmt->execute([$schoolId, $guardianId]);
            $teacherRef = $teacherStmt->fetch(PDO::FETCH_ASSOC);

            if ($teacherRef && !empty($teacherRef['sender_user_id'])) {
                $teacherPhoneStmt = $conn->prepare("
                    SELECT phone FROM users
                    WHERE user_id = ? AND school_id = ? AND active = TRUE
                    LIMIT 1
                ");
                $teacherPhoneStmt->execute([$teacherRef['sender_user_id'], $schoolId]);
                $teacher = $teacherPhoneStmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $teacher = null;
                $teacherRef = null;
            }

            if ($teacher && !empty($teacher['phone'])) {
                $notifyMsg = "Reagendamiento solicitado por acudiente ({$from}) para citación. Respuesta: 2.";
                $send = sendTwilioWhatsAppDetailed($teacher['phone'], $notifyMsg);

                $logOut = $conn->prepare("
                    INSERT INTO twilio_messages (
                        twilio_message_id, school_id, guardian_id, type_code, direction, phone_number,
                        message_content, provider_message_sid, delivery_status, sent_at, metadata_json
                    ) VALUES (
                        uuid_generate_v4(), ?, ?, 'COORDINACION', 'OUTBOUND', ?, ?, ?, ?, NOW(), ?::jsonb
                    )
                ");
                $logOut->execute([
                    $schoolId,
                    $guardianId,
                    $send['to'] ?? $teacher['phone'],
                    $notifyMsg,
                    $send['sid'] ?? null,
                    $send['ok'] ? 'SENT' : 'FAILED',
                    json_encode([
                        'source' => 'twilio-webhook-reply2',
                        'error' => $send['error'] ?? null,
                        'notified_user_id' => $teacherRef['sender_user_id'] ?? null
                    ], JSON_UNESCAPED_UNICODE)
                ]);

                $panelStmt = $conn->prepare("
                    INSERT INTO attendance_incidents (
                        incident_id, school_id, student_id, incident_type, detected_at, metadata_json
                    ) VALUES (
                        uuid_generate_v4(), ?, (
                            SELECT s.student_id
                            FROM guardian_student_relationships gsr
                            JOIN students s ON s.student_id = gsr.student_id
                            WHERE gsr.guardian_id = ?
                            ORDER BY gsr.created_at DESC
                            LIMIT 1
                        ), 'CITACION_REAGENDADA', NOW(), ?::jsonb
                    )
                ");
                $panelStmt->execute([
                    $schoolId,
                    $guardianId,
                    json_encode([
                        'response' => '2',
                        'guardian_phone' => $from,
                        'target_user_id' => $teacherRef['sender_user_id'] ?? null
                    ], JSON_UNESCAPED_UNICODE)
                ]);
            }
            securityLog('CITACION_REAGENDAMIENTO_NOTIFICADO', "Guardian:$guardianId School:$schoolId");
        } else {
            securityLog('CITACION_RESPUESTA_NO_VALIDA', "Guardian:$guardianId Body:$body");
        }

        echo '<Response></Response>';
    } catch (Exception $e) {
        securityLog('TWILIO_WEBHOOK_ERROR', $e->getMessage());
        http_response_code(500);
        echo '<Response></Response>';
    }
    exit;
}
