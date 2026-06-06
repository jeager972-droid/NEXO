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

    // FIX (SRE-4): Reconstrucción de URL basada EXCLUSIVAMENTE en la variable de entorno
    // inmutable TWILIO_WEBHOOK_URL_BASE (ej: https://nexo.railway.app).
    // NUNCA usar X-Forwarded-Proto ni HTTP_HOST porque un atacante puede spoofearlos
    // y forzar una URL que coincida con su propia firma HMAC, bypasenado la validación.
    $baseUrl = getenv('TWILIO_WEBHOOK_URL_BASE') ?: '';
    if ($baseUrl === '') {
        error_log('TWILIO_WEBHOOK_URL_BASE no definida. Rechazando webhook Twilio.');
        return false;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $url = rtrim($baseUrl, '/') . $uri;

    $params = $_POST ?: [];
    ksort($params);

    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k . $v;
    }
    $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

    return hash_equals($expected, $provided);
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
        $meta = $input['metadata'] ?? $input['metadata_json'] ?? null;
        if ($desc === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Mensaje de notificación requerido']);
            exit;
        }

        try {
            $notifStmt = $conn->prepare("
                INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?::jsonb, NOW())
                RETURNING notification_id AS id, type, title, message AS desc, metadata_json,
                          TO_CHAR(created_at, 'HH24:MI') AS time, created_at AS occurred_at
            ");
            $notifStmt->execute([$authUser['school_id'], $authUser['id'], $title, $desc, $type, $meta ? json_encode($meta) : '{}']);
            $row = $notifStmt->fetch(PDO::FETCH_ASSOC);
            unset($row['occurred_at']);
            securityLog('INTERNAL_NOTIFICATION', "Role:{$authUser['role']} User:{$authUser['id']} Type:$type");
            echo json_encode(['status' => 'ok', 'data' => $row]);
        } catch (Throwable $e) {
            securityLog('NOTIFICATIONS_INSERT_ERROR', $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al crear notificación']);
        }
        exit;
    }

    try {
        $userId = (string)$authUser['id'];

        // ── Solo notificaciones reales de la tabla notifications ──
        try {
            $stmt = $conn->prepare("
                SELECT n.notification_id AS id,
                       n.type,
                       n.title,
                       n.message AS desc,
                       n.metadata_json,
                       TO_CHAR(n.created_at, 'HH24:MI') AS time,
                       n.created_at AS occurred_at
                FROM notifications n
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $colErr) {
            // Fallback si metadata_json no existe todavía
            $stmt = $conn->prepare("
                SELECT n.notification_id AS id,
                       n.type,
                       n.title,
                       n.message AS desc,
                       TO_CHAR(n.created_at, 'HH24:MI') AS time,
                       n.created_at AS occurred_at
                FROM notifications n
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($notifications as &$notification) {
            unset($notification['occurred_at']);
        }
        echo json_encode(['status' => 'ok', 'data' => $notifications]);
    } catch (Throwable $e) {
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

        // ── Redis: leer conversación y estado de reagendamiento ──
        $resolvedStudentId = null;
        $reagendarState = null;
        $redisConv = null;
        try {
            $redisConv = new Redis();
            $redisConv->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
            if ($pass = getenv('REDIS_PASSWORD')) $redisConv->auth($pass);

            // Estado de reagendamiento pendiente
            $reagRaw = $redisConv->get('reagendar:' . $normalizedFrom);
            if ($reagRaw) {
                $reagendarState = json_decode($reagRaw, true);
            }

            $convRaw = $redisConv->get('conversation:' . $normalizedFrom);
            if ($convRaw) {
                $conv = json_decode($convRaw, true);
                if (!empty($conv['student_id'])) {
                    $resolvedStudentId = $conv['student_id'];
                }
                // Para respuesta 1 consumimos; para 2 la reemplazamos por reagendar
            }
        } catch (Throwable $e) {
            securityLog('TWILIO_CONV_REDIS_FALLBACK', $e->getMessage());
        }

        // Resolver profesor que emitió la citación más reciente
        $teacherRef = null;
        $teacher = null;
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

        // Resolver nombre del estudiante (por student_id o guardian fallback)
        $studentName = '';
        if ($resolvedStudentId) {
            $sNameStmt = $conn->prepare("SELECT first_name, last_name FROM students WHERE student_id = ?");
            $sNameStmt->execute([$resolvedStudentId]);
            $sNameRow = $sNameStmt->fetch(PDO::FETCH_ASSOC);
            $studentName = trim(($sNameRow['first_name'] ?? '') . ' ' . ($sNameRow['last_name'] ?? ''));
        }
        if ($studentName === '') {
            $sNameStmt = $conn->prepare("
                SELECT s.first_name, s.last_name
                FROM students s
                JOIN guardian_student_relationships gsr ON gsr.student_id = s.student_id
                WHERE gsr.guardian_id = ?
                ORDER BY gsr.created_at DESC
                LIMIT 1
            ");
            $sNameStmt->execute([$guardianId]);
            $sNameRow = $sNameStmt->fetch(PDO::FETCH_ASSOC);
            $studentName = trim(($sNameRow['first_name'] ?? '') . ' ' . ($sNameRow['last_name'] ?? ''));
        }

        // ── Caso A: Acudiente envió motivo de reagendamiento ──
        if ($reagendarState && $trimBody !== '1' && $trimBody !== '2') {
            $motivo = $body;
            $replyMsg = "Gracias. Hemos registrado su mensaje y se lo haremos llegar al profesor.";
            $sendAck = sendTwilioDirect($from, $replyMsg);

            // Notificar al profesor con motivo incluido
            if ($teacherRef && !empty($teacherRef['sender_user_id'])) {
                try {
                    $meta = json_encode([
                        'student_name' => $studentName ?: 'Estudiante',
                        'action' => 'reagendar_motivo',
                        'guardian_phone' => $from,
                        'motivo' => $motivo,
                    ], JSON_UNESCAPED_UNICODE);
                    $notifStmt = $conn->prepare("
                        INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                        VALUES (?, ?, 'Reagendamiento — motivo recibido', ?, 'INFO', ?::jsonb, NOW())
                    ");
                    $notifStmt->execute([
                        $schoolId,
                        $teacherRef['sender_user_id'],
                        "El acudiente de \"" . ($studentName ?: 'Estudiante') . "\" envió el motivo de reagendamiento. Ver detalles.",
                        $meta
                    ]);
                } catch (Throwable $e) {
                    securityLog('CITACION_NOTIF_ERROR', $e->getMessage());
                }
            }

            // Limpiar estado
            try {
                if ($redisConv) {
                    $redisConv->del('reagendar:' . $normalizedFrom);
                    $redisConv->del('conversation:' . $normalizedFrom);
                }
            } catch (Throwable $e) {
                securityLog('REAGENDAR_REDIS_DEL_ERROR', $e->getMessage());
            }

            securityLog('CITACION_REAGENDAR_MOTIVO', "Guardian:$guardianId StudentName:$studentName Motivo:$motivo");
            echo '<Response></Response>';
            exit;
        }

        if ($trimBody === '1') {
            // Limpiar conversación
            try {
                if ($redisConv) {
                    $redisConv->del('conversation:' . $normalizedFrom);
                    $redisConv->del('reagendar:' . $normalizedFrom);
                }
            } catch (Throwable $e) {}

            $replyMsg = "Gracias por confirmar asistencia a la citación.";
            $sendAck = sendTwilioDirect($from, $replyMsg);
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

            if ($resolvedStudentId) {
                $auditStmt = $conn->prepare("
                    INSERT INTO attendance_incidents (
                        incident_id, school_id, student_id, incident_type, detected_at, metadata_json
                    ) VALUES (
                        uuid_generate_v4(), ?, ?, 'CITACION_CONFIRMADA', NOW(), ?::jsonb
                    )
                ");
                $auditStmt->execute([
                    $schoolId,
                    $resolvedStudentId,
                    json_encode(['response' => '1', 'guardian_phone' => $from, 'source' => 'redis_conversation'], JSON_UNESCAPED_UNICODE)
                ]);
            } else {
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
                    json_encode(['response' => '1', 'guardian_phone' => $from, 'source' => 'fallback_limit1'], JSON_UNESCAPED_UNICODE)
                ]);
            }

            // Notificación interna al profesor con nombre del estudiante
            if ($teacherRef && !empty($teacherRef['sender_user_id'])) {
                try {
                    $meta = json_encode([
                        'student_name' => $studentName ?: 'Estudiante',
                        'action' => 'citacion_confirmada',
                        'guardian_phone' => $from,
                    ], JSON_UNESCAPED_UNICODE);
                    $notifStmt = $conn->prepare("
                        INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                        VALUES (?, ?, 'Citación confirmada', ?, 'SUCCESS', ?::jsonb, NOW())
                    ");
                    $notifStmt->execute([
                        $schoolId,
                        $teacherRef['sender_user_id'],
                        "El acudiente de \"" . ($studentName ?: 'Estudiante') . "\" confirmó asistencia a la citación.",
                        $meta
                    ]);
                } catch (Throwable $e) {
                    securityLog('CITACION_NOTIF_ERROR', $e->getMessage());
                }
            }

            securityLog('CITACION_CONFIRMADA', "Guardian:$guardianId School:$schoolId Student:" . ($resolvedStudentId ?? 'fallback'));
        } elseif ($trimBody === '2') {
            // Guardar estado de reagendamiento en Redis (no consumir todavía)
            try {
                if ($redisConv) {
                    $redisConv->del('conversation:' . $normalizedFrom);
                    $redisConv->setex('reagendar:' . $normalizedFrom, 172800, json_encode([
                        'guardian_id' => $guardianId,
                        'school_id' => $schoolId,
                        'student_id' => $resolvedStudentId,
                        'student_name' => $studentName,
                        'teacher_user_id' => $teacherRef['sender_user_id'] ?? null,
                        'ts' => time()
                    ], JSON_UNESCAPED_UNICODE));
                }
            } catch (Throwable $e) {
                securityLog('REAGENDAR_REDIS_SET_ERROR', $e->getMessage());
            }

            $replyMsg = "Solicitud de reagendamiento recibida. Pronto se le enviará una nueva fecha. Por favor, escriba brevemente qué fecha y hora le quedan más fáciles, o el motivo del reagendamiento:";
            $sendAck = sendTwilioDirect($from, $replyMsg);
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

            // Notificación interna al profesor: reagendamiento solicitado (sin motivo todavía)
            if ($teacherRef && !empty($teacherRef['sender_user_id'])) {
                try {
                    $meta = json_encode([
                        'student_name' => $studentName ?: 'Estudiante',
                        'action' => 'reagendar_solicitado',
                        'guardian_phone' => $from,
                    ], JSON_UNESCAPED_UNICODE);
                    $notifStmt = $conn->prepare("
                        INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                        VALUES (?, ?, 'Reagendamiento solicitado', ?, 'INFO', ?::jsonb, NOW())
                    ");
                    $notifStmt->execute([
                        $schoolId,
                        $teacherRef['sender_user_id'],
                        "El acudiente de \"" . ($studentName ?: 'Estudiante') . "\" solicitó reagendar la citación. Esperando motivo.",
                        $meta
                    ]);
                } catch (Throwable $e) {
                    securityLog('CITACION_NOTIF_ERROR', $e->getMessage());
                }
            }

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
