<?php
/**
 * =============================================================================
 * routes/misc.php — Utilidades, webhooks, notificaciones y búsquedas.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Agrupa endpoints auxiliares y transversales:
 *   - POST /contacto             : formulario de contacto con rate limiting.
 *   - GET/POST /notifications    : crear y listar notificaciones internas.
 *   - POST /notifications/clear  : limpiar notificaciones del usuario.
 *   - GET  /consultation/search  : buscar estudiantes por nombre/documento.
 *   - POST /reports/preview      : previsualización de eventos biométricos.
 *   - POST /webhooks/twilio/inbound : procesar mensajes entrantes de WhatsApp.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación, getRedisConnection.
 *   - lib/twilio.php : normalizeWhatsAppPhone, sendTwilioDirect, logTwilioMessage.
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Landing page (formulario de contacto) y frontend (notificaciones).
 *   - Twilio (webhook inbound de WhatsApp).
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';
require_once __DIR__ . '/../lib/twilio.php';

/**
 * Verifica la firma HMAC-SHA1 de un webhook entrante de Twilio.
 *
 * @return bool True si la firma es válida.
 *
 * Efectos secundarios: ninguno (solo lectura de superglobales y variables).
 * Precondiciones: TWILIO_AUTH_TOKEN y TWILIO_WEBHOOK_URL_BASE deben estar configuradas.
 * Postcondiciones: retorna false si falta configuración o la firma no coincide.
 */
function verifyTwilioSignature() {
    $authToken = getenv('TWILIO_AUTH_TOKEN') ?: '';
    $provided = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

    if ($authToken === '' || $provided === '') {
        return false;
    }

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


// POST /contacto — Formulario de contacto desde la landing page con rate limiting Redis.
if ($cleanPath === '/contacto' && $method === 'POST') {
    $contactIp = md5(getRealClientIp());
    try {
        $rl = getRedisConnection();
        if (!$rl) {
            // VF-023: Fail-closed — si Redis cae, no permitir spam
            http_response_code(503);
            echo json_encode(['status' => 'error', 'message' => 'Servicio temporalmente no disponible. Inténtalo más tarde.']);
            exit;
        }
        $key = "rl:contacto:{$contactIp}";
        $hits = $rl->incr($key);
        if ($hits === 1) $rl->expire($key, 3600);
        if ($hits > 5) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Demasiadas solicitudes. Inténtalo más tarde.']);
            exit;
        }
    } catch (Throwable $e) {
        // VF-023: Fail-closed también en excepciones
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'Servicio temporalmente no disponible. Inténtalo más tarde.']);
        exit;
    }

    $nombre      = trim((string)($input['name'] ?? ''));
    $cargo       = trim((string)($input['position'] ?? ''));
    $institucion = trim((string)($input['institution'] ?? ''));
    $municipio   = trim((string)($input['city'] ?? ''));
    $email       = trim((string)($input['email'] ?? ''));
    $whatsapp    = trim((string)($input['whatsapp'] ?? ''));
    $mensaje     = trim((string)($input['message'] ?? ''));

    if ($nombre === '' || $cargo === '' || $institucion === '' || $municipio === '' || $email === '' || $whatsapp === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Todos los campos obligatorios deben completarse.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Correo electrónico inválido.']);
        exit;
    }
    $digits = preg_replace('/\D/', '', $whatsapp);
    if (strlen($digits) < 10) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Número de WhatsApp inválido.']);
        exit;
    }

    try {
        $ins = $conn->prepare("
            INSERT INTO contact_leads (nombre, cargo, institucion, municipio, email, whatsapp, mensaje, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$nombre, $cargo, $institucion, $municipio, $email, $whatsapp, $mensaje ?: null, getRealClientIp()]);
    } catch (Throwable $e) {
        securityLog('CONTACT_LEAD_INSERT_ERROR', $e->getMessage());
    }

    $ownerPhone = getenv('NEXO_OWNER_WHATSAPP') ?: getenv('TWILIO_ADMIN_PHONE') ?: '';
    if ($ownerPhone !== '') {
        try {
            $redis = getRedisConnection();
            if ($redis) {
                $cargoLabel = mb_convert_case($cargo, MB_CASE_TITLE, 'UTF-8');
                $notifMsg = "📥 *NEXO — Nueva solicitud de contacto*\n\n"
                    . "Nombre: *{$nombre}* ({$cargoLabel})\n"
                    . "Institución: {$institucion}\n"
                    . "Municipio: {$municipio}\n"
                    . "Email: {$email}\n"
                    . "WhatsApp: {$whatsapp}"
                    . ($mensaje !== '' ? "\nMensaje: {$mensaje}" : '');
                $redis->rPush('queue:twilio', json_encode([
                    'to' => $ownerPhone,
                    'body' => $notifMsg,
                    'school_id' => null,
                    'student_id' => null,
                    'guardian_id' => null,
                    'sender_user_id' => null,
                    'type_code' => 'CONTACT_LEAD',
                    'retries' => 0,
                    'created_at' => time()
                ], JSON_UNESCAPED_UNICODE));
            }
        } catch (Exception $e) {
        }
    }

    securityLog('CONTACT_LEAD_RECEIVED', "Email:{$email} Cargo:{$cargo} Inst:{$institucion}");
    echo json_encode(['status' => 'ok', 'message' => 'Solicitud recibida. Nos comunicaremos contigo pronto.']);
    exit;
}

// GET/POST /notifications — Crear y listar notificaciones internas del usuario autenticado.
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
                       (n.read_at IS NOT NULL) AS read,
                       TO_CHAR(n.created_at, 'DD/MM HH24:MI') AS time,
                       n.created_at AS occurred_at
                FROM notifications n
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $colErr) {
            $stmt = $conn->prepare("
                SELECT n.notification_id AS id,
                       n.type,
                       n.title,
                       n.message AS desc,
                       false AS read,
                       TO_CHAR(n.created_at, 'DD/MM HH24:MI') AS time,
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
            $notification['read'] = filter_var($notification['read'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        echo json_encode(['status' => 'ok', 'data' => $notifications]);
    } catch (Throwable $e) {
        securityLog('NOTIFICATIONS_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener notificaciones']);
    }
    exit;
}

// POST /notifications/{id}/read — Marca una notificación como leída (persistente).
if (preg_match('#^/notifications/([0-9a-fA-F-]{36})/read$#', $cleanPath, $readMatches) && $method === 'POST') {
    $authUser = requireAuth();
    try {
        $stmt = $conn->prepare("UPDATE notifications SET read_at = NOW() WHERE notification_id = ? AND user_id = ? AND read_at IS NULL");
        $stmt->execute([$readMatches[1], $authUser['id']]);
        echo json_encode(['status' => 'ok']);
    } catch (Throwable $e) {
        securityLog('NOTIFICATIONS_READ_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al marcar notificación']);
    }
    exit;
}

// POST /notifications/read-all — Marca todas las notificaciones del usuario como leídas.
if ($cleanPath === '/notifications/read-all' && $method === 'POST') {
    $authUser = requireAuth();
    try {
        $stmt = $conn->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
        $stmt->execute([$authUser['id']]);
        echo json_encode(['status' => 'ok']);
    } catch (Throwable $e) {
        securityLog('NOTIFICATIONS_READ_ALL_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al marcar notificaciones']);
    }
    exit;
}

// POST /notifications/clear — Elimina todas las notificaciones del usuario autenticado.
if ($cleanPath === '/notifications/clear' && $method === 'POST') {
    $authUser = requireAuth();
    try {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$authUser['id']]);
        securityLog('NOTIFICATIONS_CLEARED', "User:{$authUser['id']}");
        echo json_encode(['status' => 'ok', 'message' => 'Notificaciones eliminadas']);
    } catch (Throwable $e) {
        securityLog('NOTIFICATIONS_CLEAR_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al vaciar notificaciones']);
    }
    exit;
}

// POST /notifications/{id}/action — Procesar acción sobre una notificación (justify/no_justify).
if (preg_match('#^/notifications/([0-9a-fA-F-]{36})/action$#', $cleanPath, $notifMatches) && $method === 'POST') {
    $authUser = requireAuth();
    $notificationId = $notifMatches[1];
    $action = trim((string)($input['action'] ?? ''));

    if (!in_array($action, ['justify', 'no_justify'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida. Use justify o no_justify.']);
        exit;
    }

    try {
        // Obtener la notificación y su metadata
        $stmt = $conn->prepare("
            SELECT notification_id, user_id, metadata_json
            FROM notifications
            WHERE notification_id = ? AND user_id = ?
        ");
        $stmt->execute([$notificationId, $authUser['id']]);
        $notif = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$notif) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Notificación no encontrada']);
            exit;
        }

        $meta = json_decode($notif['metadata_json'] ?? '{}', true);
        $incidentId = $meta['incident_id'] ?? null;

        if ($action === 'justify' && $incidentId) {
            // VF-015: Soft-delete + audit trail — marcar como resolved en lugar de hard delete
            $resolveIncident = $conn->prepare(
                "UPDATE attendance_incidents
                 SET resolved = TRUE, metadata_json = COALESCE(metadata_json, '{}'::jsonb) || ?::jsonb
                 WHERE incident_id = ?::uuid AND incident_type = 'LATE_ARRIVAL'
                 RETURNING student_id, school_id"
            );
            $auditMeta = json_encode(['justified_by' => $authUser['id'], 'justified_at' => date('c'), 'action' => 'justify'], JSON_UNESCAPED_UNICODE);
            $resolveIncident->execute([$auditMeta, $incidentId]);
            $incidentRow = $resolveIncident->fetch(PDO::FETCH_ASSOC);

            // Crear audit trail en student_record_audit
            if ($incidentRow) {
                try {
                    $auditStmt = $conn->prepare(
                        "INSERT INTO student_record_audit (audit_id, school_id, student_id, performed_by_user_id, action_type, previous_data, new_data, performed_at)
                         VALUES (uuid_generate_v4(), ?::uuid, ?::uuid, ?::uuid, 'LATE_ARRIVAL_JUSTIFIED', ?::jsonb, ?::jsonb, NOW())"
                    );
                    $auditStmt->execute([
                        $incidentRow['school_id'],
                        $incidentRow['student_id'],
                        $authUser['id'],
                        json_encode(['incident_id' => $incidentId, 'resolved' => false]),
                        json_encode(['incident_id' => $incidentId, 'resolved' => true, 'justified_by' => $authUser['id']])
                    ]);
                } catch (Exception $ae) {
                    securityLog('AUDIT_JUSTIFY_FAIL', $ae->getMessage());
                }
            }
        }

        // En ambos casos (justify y no_justify), eliminar la notificación
        $delNotif = $conn->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
        $delNotif->execute([$notificationId, $authUser['id']]);

        securityLog('NOTIFICATION_ACTION', "User:{$authUser['id']} Action:$action Notif:$notificationId");
        echo json_encode(['status' => 'ok', 'message' => 'Acción procesada correctamente']);
    } catch (Throwable $e) {
        securityLog('NOTIFICATION_ACTION_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al procesar la acción']);
    }
    exit;
}

// GET /consultation/search — Búsqueda de estudiantes por nombre o documento.
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

// GET /reports/preview — Previsualización de eventos biométricos filtrados por fecha.
if ($cleanPath === '/reports/preview') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    // BUG-05 FIX (backend): leer parámetros de fecha desde la query string
    $from = trim((string)($_GET['from'] ?? ''));
    $to   = trim((string)($_GET['to']   ?? ''));

    try {
        // BUG-06 FIX (backend): JOIN con students y academic_groups para retornar
        // student_name y group_name — el frontend ya no muestra student_id desnudo
        $params = [$authUser['school_id']];
        $dateFilter = '';
        if ($from !== '') {
            $dateFilter .= ' AND be.event_timestamp::date >= ?';
            $params[] = $from;
        }
        if ($to !== '') {
            $dateFilter .= ' AND be.event_timestamp::date <= ?';
            $params[] = $to;
        }

        $stmt = $conn->prepare("
            SELECT
                be.event_timestamp::date AS date,
                TO_CHAR(be.event_timestamp, 'HH24:MI') AS time,
                be.event_type,
                (s.last_name || ' ' || s.first_name) AS student_name,
                COALESCE(ag.group_name, '—') AS group_name
            FROM biometric_events be
            LEFT JOIN students s ON s.student_id = be.student_id
            LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
            LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
            WHERE be.school_id = ?
            $dateFilter
            ORDER BY be.event_timestamp DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        echo json_encode(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        securityLog('REPORTS_PREVIEW_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener vista previa de reportes']);
    }
    exit;
}

// POST /webhooks/twilio/inbound — Recibe mensajes entrantes de WhatsApp y genera notificación/interno.
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
            WHERE regexp_replace(
                    CASE 
                      WHEN g.whatsapp_phone_normalized LIKE '+%' THEN g.whatsapp_phone_normalized
                      WHEN g.whatsapp_phone_normalized LIKE '57%' THEN '+' || g.whatsapp_phone_normalized
                      ELSE '+57' || regexp_replace(g.whatsapp_phone_normalized, '[^0-9]', '', 'g')
                    END,
                    '[^0-9+]', '', 'g'
                  ) = ?
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
            $redisConv = getRedisConnection();
            if (!$redisConv) {
            }

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
            }
        } catch (Throwable $e) {
            securityLog('TWILIO_CONV_REDIS_FALLBACK', $e->getMessage());
        }

        $teacherRef = null;
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
        
        securityLog('CITACION_TEACHER_LOOKUP', "Guardian:$guardianId School:$schoolId TeacherRef:" . ($teacherRef ? json_encode($teacherRef) : 'NULL'));

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

        if ($reagendarState && $trimBody !== '1' && $trimBody !== '2') {
            $motivo = $body;
            $replyMsg = "Gracias. Hemos registrado su mensaje y se lo haremos llegar al profesor y pronto le informaremos la nueva fecha.";
            $sendAck = sendTwilioDirect($from, $replyMsg);

            $resolvedTeacherId = $reagendarState['teacher_user_id'] ?? ($teacherRef['sender_user_id'] ?? null);

            // Notificar al profesor con motivo incluido
            if ($resolvedTeacherId) {
                try {
                    $meta = json_encode([
                        'student_name' => $studentName ?: ($reagendarState['student_name'] ?? 'Estudiante'),
                        'action' => 'reagendar_motivo',
                        'guardian_phone' => $from,
                        'motivo' => $motivo,
                    ], JSON_UNESCAPED_UNICODE);
                    securityLog('CITACION_NOTIF_ATTEMPT', "Type:reagendar_motivo Teacher:$resolvedTeacherId Student:$studentName");
                    $notifStmt = $conn->prepare("
                        INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                        VALUES (?, ?, 'Reagendamiento', ?, 'INFO', ?::jsonb, NOW())
                    ");
                    $notifStmt->execute([
                        $schoolId,
                        $resolvedTeacherId,
                        "Se recibió el motivo de reagendamiento del acudiente de " . ($studentName ?: 'Estudiante') . ". Ver detalles.",
                        $meta
                    ]);
                    securityLog('CITACION_NOTIF_SUCCESS', "Type:reagendar_motivo Teacher:$resolvedTeacherId");
                } catch (Throwable $e) {
                    securityLog('CITACION_NOTIF_ERROR', "Type:reagendar_motivo Error:" . $e->getMessage());
                }
            } else {
                securityLog('CITACION_NOTIF_SKIP', "Type:reagendar_motivo Reason:NoTeacherId TeacherRef:" . json_encode($teacherRef));
            }

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

            if ($teacherRef && !empty($teacherRef['sender_user_id'])) {
                try {
                    $meta = json_encode([
                        'student_name' => $studentName ?: 'Estudiante',
                        'action' => 'citacion_confirmada',
                        'guardian_phone' => $from,
                    ], JSON_UNESCAPED_UNICODE);
                    securityLog('CITACION_NOTIF_ATTEMPT', "Type:confirmada Teacher:{$teacherRef['sender_user_id']} Student:$studentName");
                    $notifStmt = $conn->prepare("
                        INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                        VALUES (?, ?, 'Citación confirmada', ?, 'SUCCESS', ?::jsonb, NOW())
                    ");
                    $notifStmt->execute([
                        $schoolId,
                        $teacherRef['sender_user_id'],
                        "Se confirmó la asistencia del acudiente de " . ($studentName ?: 'Estudiante') . " a la citación.",
                        $meta
                    ]);
                    securityLog('CITACION_NOTIF_SUCCESS', "Type:confirmada Teacher:{$teacherRef['sender_user_id']}");
                } catch (Throwable $e) {
                    securityLog('CITACION_NOTIF_ERROR', "Type:confirmada Error:" . $e->getMessage());
                }
            } else {
                securityLog('CITACION_NOTIF_SKIP', "Type:confirmada Reason:NoTeacherRef TeacherRef:" . json_encode($teacherRef));
            }

            securityLog('CITACION_CONFIRMADA', "Guardian:$guardianId School:$schoolId Student:" . ($resolvedStudentId ?? 'fallback'));
        } elseif ($trimBody === '2') {
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

            $replyMsg = "Por favor, escriba brevemente qué fecha y hora le quedan más fáciles, o el motivo del reagendamiento:";
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

            $studentIdStmt = $conn->prepare("
                SELECT s.student_id
                FROM guardian_student_relationships gsr
                JOIN students s ON s.student_id = gsr.student_id
                WHERE gsr.guardian_id = ?
                ORDER BY gsr.created_at DESC
                LIMIT 1
            ");
            $studentIdStmt->execute([$guardianId]);
            $resolvedStudentIdForPanel = $studentIdStmt->fetchColumn();

            if ($resolvedStudentIdForPanel) {
                $panelStmt = $conn->prepare("
                    INSERT INTO attendance_incidents (
                        incident_id, school_id, student_id, incident_type, detected_at, metadata_json
                    ) VALUES (
                        uuid_generate_v4(), ?, ?, 'CITACION_REAGENDADA', NOW(), ?::jsonb
                    )
                ");
                $panelStmt->execute([
                    $schoolId,
                    $resolvedStudentIdForPanel,
                    json_encode([
                        'response' => '2',
                        'guardian_phone' => $from,
                        'target_user_id' => $teacherRef['sender_user_id'] ?? null
                    ], JSON_UNESCAPED_UNICODE)
                ]);
            } else {
                securityLog('CITACION_REAGENDADA_NO_STUDENT', "Guardian:$guardianId has no active students - skipping attendance_incidents insert");
            }

            securityLog('CITACION_REAGENDAMIENTO_NOTIFICADO', "Guardian:$guardianId School:$schoolId");
        } elseif ($trimBody === '9') {
            $salidaCtxRaw = null;
            try {
                if ($redisConv) {
                    $salidaCtxRaw = $redisConv->get('salida_context:' . $normalizedFrom);
                }
            } catch (Throwable $e) {}

            if ($salidaCtxRaw) {
                $salidaCtx = json_decode($salidaCtxRaw, true);
                $issuerUserId = $salidaCtx['issuer_user_id'] ?? null;
                $sName = $salidaCtx['student_name'] ?? 'Estudiante';
                $sId   = $salidaCtx['student_id']   ?? null;

                // Responder al acudiente
                $ackMsg = "Hemos recibido su reporte. Notificaremos a la institución de inmediato.";
                sendTwilioDirect($from, $ackMsg);

                if ($issuerUserId) {
                    try {
                        $salMeta = json_encode([
                            'student_name'  => $sName,
                            'student_id'    => $sId,
                            'action'        => 'salida_no_autorizada',
                            'guardian_phone'=> $from,
                        ], JSON_UNESCAPED_UNICODE);
                        $salNotif = $conn->prepare("
                            INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                            VALUES (?, ?, 'Salida no autorizada', ?, 'ALERT', ?::jsonb, NOW())
                        ");
                        $salNotif->execute([
                            $schoolId,
                            $issuerUserId,
                            "Se marcó como error la salida autorizada de {$sName}. Verificar de inmediato.",
                            $salMeta
                        ]);
                    } catch (Throwable $e) {
                        securityLog('SALIDA_NOAUTH_NOTIF_ERROR', $e->getMessage());
                    }
                }

                try {
                    if ($redisConv) $redisConv->del('salida_context:' . $normalizedFrom);
                } catch (Throwable $e) {}

                securityLog('SALIDA_REPORTADA_NO_AUTORIZADA', "Guardian:$guardianId Student:$sId");
            } else {
                securityLog('TWILIO_INBOUND_9_NO_CONTEXT', "Guardian:$guardianId From:$from");
            }

            echo '<Response></Response>';
            exit;
        }

        // ── Inasistencia: 1 = justificada, 2 = no está al tanto ──
        // Bloque C: aceptar dígitos Y lenguaje natural (el acudiente no siempre
        // responde con el número del menú).
        $inasistenciaCtxRaw = null;
        try {
            if ($redisConv) {
                $inasistenciaCtxRaw = $redisConv->get('inasistencia_context:' . $normalizedFrom);
            }
        } catch (Throwable $e) {}

        $menuChoice = null;
        if ($inasistenciaCtxRaw) {
            if ($trimBody === '1') $menuChoice = '1';
            elseif ($trimBody === '2') $menuChoice = '2';
            else {
                $lower = function_exists('mb_strtolower') ? mb_strtolower($trimBody) : strtolower($trimBody);
                // "no sabía / no estaba al tanto / no me avisaron / desconozco"
                if (preg_match('/no sab|no estaba|no me (enter|avis|dij)|desconoz|no ten|ignorab|no sabia/u', $lower)) {
                    $menuChoice = '2';
                // "justificada / está enfermo / cita médica / tiene permiso"
                } elseif (preg_match('/justific|enferm|cita|medic|permiso|calamidad|domest|si sab|lo se\b/u', $lower)) {
                    $menuChoice = '1';
                } else {
                    // Contexto existe pero respuesta irreconocible → recordar menú
                    $inaCtxTmp = json_decode($inasistenciaCtxRaw, true);
                    $nameTmp = $inaCtxTmp['student_name'] ?? 'su estudiante';
                    sendTwilioDirect($from, "Para registrar su respuesta sobre la inasistencia de {$nameTmp}, responda:\n1 — La inasistencia está justificada\n2 — No estaba al tanto");
                    echo '<Response></Response>';
                    exit;
                }
            }
        }

        if ($inasistenciaCtxRaw && $menuChoice) {
            $inaCtx = json_decode($inasistenciaCtxRaw, true);
            $inaStudentId = $inaCtx['student_id'] ?? null;
            $inaStudentName = $inaCtx['student_name'] ?? 'Estudiante';
            $inaIncidentId = $inaCtx['incident_id'] ?? null;
            $inaSchoolId = $inaCtx['school_id'] ?? $schoolId;

            if ($menuChoice === '1') {
                // Justificada: pedir motivo
                try {
                    if ($redisConv) {
                        $redisConv->setex('inasistencia_motivo:' . $normalizedFrom, 3600, json_encode([
                            'student_id' => $inaStudentId,
                            'student_name' => $inaStudentName,
                            'school_id' => $inaSchoolId,
                            'incident_id' => $inaIncidentId,
                            'ts' => time(),
                        ], JSON_UNESCAPED_UNICODE));
                    }
                } catch (Throwable $e) {}

                // Documento: al justificar se pide la excusa/soporte y se indica
                // que el estudiante debe ponerse al día en actividades.
                $replyMsg = "Por favor, escriba brevemente el motivo de la inasistencia de {$inaStudentName}.\n\nAl reintegrarse, el estudiante debe adjuntar la excusa o soporte correspondiente y ponerse al día en las actividades pendientes.";
                sendTwilioDirect($from, $replyMsg);

                // Log del mensaje saliente
                $logAck = $conn->prepare("
                    INSERT INTO twilio_messages (
                        twilio_message_id, school_id, guardian_id, type_code, direction, phone_number,
                        message_content, provider_message_sid, delivery_status, sent_at, metadata_json
                    ) VALUES (
                        uuid_generate_v4(), ?, ?, 'INASISTENCIA', 'OUTBOUND', ?, ?, ?, ?, NOW(), ?::jsonb
                    )
                ");
                $sendAck = ['sid' => null, 'ok' => true];
                $logAck->execute([
                    $inaSchoolId, $guardianId, $from, $replyMsg, null, 'SENT',
                    json_encode(['source' => 'twilio-webhook-inasistencia-justificada'], JSON_UNESCAPED_UNICODE)
                ]);

                securityLog('INASISTENCIA_JUSTIFICADA_PIDIENDO_MOTIVO', "Guardian:$guardianId Student:$inaStudentId");
            } elseif ($menuChoice === '2') {
                // No está al tanto: alerta MUY_ALTA + notificar coordinación
                // 1. Marcar el incidente original como no justificada
                if ($inaIncidentId) {
                    $updateIncident = $conn->prepare("
                        UPDATE attendance_incidents
                        SET metadata_json = COALESCE(metadata_json, '{}'::jsonb) || ?::jsonb
                        WHERE incident_id = ?::uuid AND school_id = ?
                    ");
                    $updateMeta = json_encode([
                        'justificada' => false,
                        'guardian_response' => 'no_al_tanto',
                        'guardian_phone' => $from,
                        'responded_at' => date('c'),
                    ], JSON_UNESCAPED_UNICODE);
                    $updateIncident->execute([$updateMeta, $inaIncidentId, $inaSchoolId]);
                }

                // 2. Crear incidente de inasistencia no justificada
                $noJustStmt = $conn->prepare("
                    INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                    VALUES (uuid_generate_v4(), ?, ?, 'INASISTENCIA_NO_JUSTIFICADA', NOW(), ?::jsonb)
                ");
                $noJustMeta = json_encode([
                    'guardian_response' => 'no_al_tanto',
                    'guardian_phone' => $from,
                    'original_incident_id' => $inaIncidentId,
                    'alert_level' => 'MUY_ALTA',
                ], JSON_UNESCAPED_UNICODE);
                $noJustStmt->execute([$inaSchoolId, $inaStudentId, $noJustMeta]);

                // 3. Notificar a los destinos configurados (default COORDINATOR+RECTOR)
                $coordStmt = $conn->prepare("
                    SELECT DISTINCT u.user_id FROM users u
                    JOIN roles r ON u.role_id = r.role_id
                    WHERE u.school_id = ? AND UPPER(r.role_name) IN (
                        SELECT UPPER(target_role) FROM school_notification_routes
                        WHERE school_id = ? AND event_kind = 'ABSENCE_RESPONSE' AND enabled = TRUE
                        UNION ALL
                        SELECT 'COORDINATOR' WHERE NOT EXISTS (
                            SELECT 1 FROM school_notification_routes
                            WHERE school_id = ? AND event_kind = 'ABSENCE_RESPONSE' AND enabled = TRUE)
                        UNION ALL
                        SELECT 'RECTOR' WHERE NOT EXISTS (
                            SELECT 1 FROM school_notification_routes
                            WHERE school_id = ? AND event_kind = 'ABSENCE_RESPONSE' AND enabled = TRUE)
                    ) AND u.active = TRUE
                ");
                $coordStmt->execute([$inaSchoolId, $inaSchoolId, $inaSchoolId, $inaSchoolId]);
                $coords = $coordStmt->fetchAll(PDO::FETCH_ASSOC);

                $alertMeta = json_encode([
                    'student_id' => $inaStudentId,
                    'student_name' => $inaStudentName,
                    'action' => 'inasistencia_no_justificada',
                    'guardian_phone' => $from,
                    'alert_level' => 'MUY_ALTA',
                ], JSON_UNESCAPED_UNICODE);

                foreach ($coords as $c) {
                    $notifStmt = $conn->prepare("
                        INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                        VALUES (?, ?, ?, ?, 'ALERT', ?::jsonb, NOW())
                    ");
                    $notifStmt->execute([
                        $inaSchoolId, $c['user_id'],
                        'Inasistencia no justificada',
                        "El acudiente de {$inaStudentName} reportó NO estar al tanto de la inasistencia. Verificar de inmediato.",
                        $alertMeta,
                    ]);
                }

                // 4. Responder al acudiente
                $ackMsg = "Hemos registrado su reporte. La institución se ha notificado de inmediato y se comunicarán con usted pronto.";
                sendTwilioDirect($from, $ackMsg);

                // Log del mensaje saliente
                $logAck = $conn->prepare("
                    INSERT INTO twilio_messages (
                        twilio_message_id, school_id, guardian_id, type_code, direction, phone_number,
                        message_content, provider_message_sid, delivery_status, sent_at, metadata_json
                    ) VALUES (
                        uuid_generate_v4(), ?, ?, 'INASISTENCIA', 'OUTBOUND', ?, ?, ?, ?, NOW(), ?::jsonb
                    )
                ");
                $logAck->execute([
                    $inaSchoolId, $guardianId, $from, $ackMsg, null, 'SENT',
                    json_encode(['source' => 'twilio-webhook-inasistencia-no-justificada'], JSON_UNESCAPED_UNICODE)
                ]);

                // Limpiar contexto
                try {
                    if ($redisConv) $redisConv->del('inasistencia_context:' . $normalizedFrom);
                } catch (Throwable $e) {}

                securityLog('INASISTENCIA_NO_JUSTIFICADA', "Guardian:$guardianId Student:$inaStudentId School:$inaSchoolId");
            }

            echo '<Response></Response>';
            exit;
        }

        // ── Inasistencia: recibir motivo después de responder 1 ──
        $inasistenciaMotivoRaw = null;
        try {
            if ($redisConv) {
                $inasistenciaMotivoRaw = $redisConv->get('inasistencia_motivo:' . $normalizedFrom);
            }
        } catch (Throwable $e) {}

        if ($inasistenciaMotivoRaw && $trimBody !== '1' && $trimBody !== '2' && $trimBody !== '9') {
            $motivoCtx = json_decode($inasistenciaMotivoRaw, true);
            $motStudentId = $motivoCtx['student_id'] ?? null;
            $motStudentName = $motivoCtx['student_name'] ?? 'Estudiante';
            $motIncidentId = $motivoCtx['incident_id'] ?? null;
            $motSchoolId = $motivoCtx['school_id'] ?? $schoolId;
            $motivo = $body;

            // 1. Marcar el incidente original como justificada
            if ($motIncidentId) {
                $updateIncident = $conn->prepare("
                    UPDATE attendance_incidents
                    SET metadata_json = COALESCE(metadata_json, '{}'::jsonb) || ?::jsonb
                    WHERE incident_id = ?::uuid AND school_id = ?
                ");
                $updateMeta = json_encode([
                    'justificada' => true,
                    'motivo' => $motivo,
                    'guardian_phone' => $from,
                    'responded_at' => date('c'),
                ], JSON_UNESCAPED_UNICODE);
                $updateIncident->execute([$updateMeta, $motIncidentId, $motSchoolId]);
            }

            // 2. Crear incidente de inasistencia justificada
            $justStmt = $conn->prepare("
                INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                VALUES (uuid_generate_v4(), ?, ?, 'INASISTENCIA_JUSTIFICADA', NOW(), ?::jsonb)
            ");
            $justMeta = json_encode([
                'motivo' => $motivo,
                'guardian_phone' => $from,
                'original_incident_id' => $motIncidentId,
                'justified_by' => 'guardian_whatsapp',
            ], JSON_UNESCAPED_UNICODE);
            $justStmt->execute([$motSchoolId, $motStudentId, $justMeta]);

            // 3. Notificar a los destinos configurados (default COORDINATOR+RECTOR)
            $coordStmt = $conn->prepare("
                SELECT DISTINCT u.user_id FROM users u
                JOIN roles r ON u.role_id = r.role_id
                WHERE u.school_id = ? AND UPPER(r.role_name) IN (
                    SELECT UPPER(target_role) FROM school_notification_routes
                    WHERE school_id = ? AND event_kind = 'ABSENCE_RESPONSE' AND enabled = TRUE
                    UNION ALL
                    SELECT 'COORDINATOR' WHERE NOT EXISTS (
                        SELECT 1 FROM school_notification_routes
                        WHERE school_id = ? AND event_kind = 'ABSENCE_RESPONSE' AND enabled = TRUE)
                    UNION ALL
                    SELECT 'RECTOR' WHERE NOT EXISTS (
                        SELECT 1 FROM school_notification_routes
                        WHERE school_id = ? AND event_kind = 'ABSENCE_RESPONSE' AND enabled = TRUE)
                ) AND u.active = TRUE
            ");
            $coordStmt->execute([$motSchoolId, $motSchoolId, $motSchoolId, $motSchoolId]);
            $coords = $coordStmt->fetchAll(PDO::FETCH_ASSOC);

            $justNotifMeta = json_encode([
                'student_id' => $motStudentId,
                'student_name' => $motStudentName,
                'action' => 'inasistencia_justificada',
                'motivo' => $motivo,
                'guardian_phone' => $from,
            ], JSON_UNESCAPED_UNICODE);

            foreach ($coords as $c) {
                $notifStmt = $conn->prepare("
                    INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                    VALUES (?, ?, ?, ?, 'INFO', ?::jsonb, NOW())
                ");
                $notifStmt->execute([
                    $motSchoolId, $c['user_id'],
                    'Inasistencia justificada',
                    "El acudiente de {$motStudentName} justificó la inasistencia. Motivo: {$motivo}",
                    $justNotifMeta,
                ]);
            }

            // 4. Responder al acudiente — excusa pendiente + ponerse al día
            $ackMsg = "Gracias. Hemos registrado la justificación de la inasistencia de {$motStudentName}. Recuerde adjuntar la excusa o soporte al reintegrarse; el estudiante debe ponerse al día en las actividades pendientes.";
            sendTwilioDirect($from, $ackMsg);

            // Log del mensaje saliente
            $logAck = $conn->prepare("
                INSERT INTO twilio_messages (
                    twilio_message_id, school_id, guardian_id, type_code, direction, phone_number,
                    message_content, provider_message_sid, delivery_status, sent_at, metadata_json
                ) VALUES (
                    uuid_generate_v4(), ?, ?, 'INASISTENCIA', 'OUTBOUND', ?, ?, ?, ?, NOW(), ?::jsonb
                )
            ");
            $logAck->execute([
                $motSchoolId, $guardianId, $from, $ackMsg, null, 'SENT',
                json_encode(['source' => 'twilio-webhook-inasistencia-motivo'], JSON_UNESCAPED_UNICODE)
            ]);

            // Limpiar contexto
            try {
                if ($redisConv) {
                    $redisConv->del('inasistencia_motivo:' . $normalizedFrom);
                    $redisConv->del('inasistencia_context:' . $normalizedFrom);
                }
            } catch (Throwable $e) {}

            securityLog('INASISTENCIA_JUSTIFICADA_MOTIVO', "Guardian:$guardianId Student:$motStudentId Motivo:$motivo");
            echo '<Response></Response>';
            exit;
        }

        echo '<Response></Response>';
    } catch (Exception $e) {
        securityLog('TWILIO_WEBHOOK_ERROR', $e->getMessage());
        http_response_code(500);
        echo '<Response></Response>';
    }
    exit;
}
