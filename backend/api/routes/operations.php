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
require_once __DIR__ . '/../workers/contingency_lib.php';

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
    // Las condiciones de disparo son configurables por escuela —
    // school_action_policies 'WHATSAPP_<TYPE>' enabled=false omite el envío.
    if ($conn && function_exists('nexoPolicyEnabled')
        && !nexoPolicyEnabled($conn, (string)$schoolId, 'WHATSAPP_' . strtoupper($typeCode))) {
        return ['ok' => true, 'reason' => 'policy_disabled', 'phone_norm' => $toNorm];
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
            // Redis no disponible: el mensaje ya está en twilio_messages con
            // delivery_status='QUEUED'. El worker_twilio.php en modo PG fallback
            // lo procesará haciendo polling.
            securityLog('TWILIO_REDIS_UNAVAILABLE', 'Redis unavailable — message stays QUEUED in PG for worker polling');
            return ['ok' => true, 'reason' => 'queued_pg_fallback', 'phone_norm' => $toNorm, 'message_id' => $msgId];
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
        securityLog('TWILIO_ENQUEUE_FAILED', $e->getMessage() . ' — message stays QUEUED in PG for worker polling');
        return ['ok' => true, 'reason' => 'queued_pg_fallback', 'phone_norm' => $toNorm, 'message_id' => $msgId];
    }
}
// ============================================================================
// Rutas bajo /operations/*
//   - POST /operations/twilio-status : consulta masiva de estado de mensajes Twilio.
//   - POST /operations/<command>     : dispatcher a switch de comandos operativos.
// También acepta action=EXECUTE_COMMAND legacy por compatibilidad.
// ============================================================================
if (strpos($cleanPath ?? '', '/operations/') === 0 || (isset($input['action']) && $input['action'] === 'EXECUTE_COMMAND')) {
    
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
        '/operations/fusionar_bloque' => 'fusionar_bloque',
        '/operations/extender_bloque' => 'extender_bloque',
        '/operations/registro_manual' => 'registro_manual',
        '/operations/registro_manual_pendiente' => 'registro_manual_pendiente',
    ];
    if (isset($pathMap[$cleanPath])) {
        $action = $pathMap[$cleanPath];
    }

    $authUser = requireAuth();
    $userId = $authUser['id'];
    $schoolId = $authUser['school_id'];
    $role = $authUser['role'];
    requireSchoolOnboarding($conn, (string)$schoolId, $role);
    $params = $input['params'] ?? [];

    if (!in_array('operations.' . $action, $authUser['permissions'] ?? [])) {
        securityLog('UNAUTHORIZED_COMMAND_ATTEMPT', "User: $userId, Role: $role, Cmd: $action");
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Acceso restringido']));
    }

    // Validación de presencia del estudiante para operaciones que lo requieren.
    // Estas operaciones no tienen sentido si el estudiante está inasistente:
    //   - permiso: salida al baño (requiere estar en clase)
    //   - autorizar_salida: salida de la institución (requiere estar dentro)
    // 'horario' no aplica aquí: es una operación de GRUPO (daily_schedule_config);
    // un cambio de jornada no depende de la presencia de un estudiante individual.
    // Excepciones permitidas para ausentes: consultas, casos activos (sos,
    // situacion_critica, solicitud, daño), citacion, incidente, seguimiento,
    // pedagogica — estas operaciones pueden hacerse sobre estudiantes ausentes.
    $presenceRequiredActions = ['permiso', 'autorizar_salida'];
    if (in_array($action, $presenceRequiredActions)) {
        $presenceStudentId = $params['student'] ?? $params['student_id'] ?? null;
        if ($presenceStudentId) {
            // Verificar si tiene incidente de INASISTENCIA hoy
            $absentStmt = $conn->prepare("
                SELECT 1 FROM attendance_incidents
                WHERE school_id = ? AND student_id = ?
                  AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                  AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                  AND incident_type = 'INASISTENCIA'
                  AND resolved = FALSE
                LIMIT 1
            ");
            $absentStmt->execute([$schoolId, $presenceStudentId]);
            $isAbsent = (bool)$absentStmt->fetchColumn();

            if ($isAbsent) {
                http_response_code(422);
                exit(json_encode([
                    'status' => 'error',
                    'message' => 'El estudiante está marcado como inasistente hoy. No se pueden realizar operaciones que requieran su presencia.',
                ]));
            }

            $presentStmt = $conn->prepare("SELECT is_student_present_today(?, ?)");
            $presentStmt->execute([$schoolId, $presenceStudentId]);
            $isPresent = (bool)$presentStmt->fetchColumn();
            if (!$isPresent) {
                http_response_code(422);
                exit(json_encode([
                    'status' => 'error',
                    'message' => 'El estudiante no está presente en la institución. Esta operación requiere que el estudiante haya registrado ingreso biométrico hoy.',
                ]));
            }
        }
    }

    // Idempotency-Key — prevenir duplicados por reintentos del cliente
    $idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
    $idemRedis = getRedisConnection();
    $idemRedisKey = null;
    if ($idempotencyKey !== '' && $idemRedis) {
        $idemRedisKey = "idem:ops:{$userId}:{$action}:" . hash('sha256', $idempotencyKey);
        $cached = $idemRedis->get($idemRedisKey);
        if ($cached !== false) {
            // Retornar respuesta cacheada del primer procesamiento
            $cachedData = json_decode($cached, true);
            http_response_code($cachedData['code'] ?? 200);
            exit(json_encode($cachedData['body'] ?? ['status' => 'ok']));
        }
        // Marcar como en proceso (TTL corto para auto-recovery si el proceso muere)
        $idemRedis->setex($idemRedisKey . ':lock', 30, '1');
        // Capturar output para cachear la respuesta
        ob_start();
        register_shutdown_function(function() use ($idemRedis, $idemRedisKey) {
            $output = ob_get_clean();
            if ($output !== false && $output !== '') {
                $code = http_response_code();
                $decoded = json_decode($output, true);
                $cacheBody = $decoded !== null ? $decoded : ['status' => 'ok', 'raw' => $output];
                $idemRedis->setex($idemRedisKey, 86400, json_encode(['code' => $code, 'body' => $cacheBody]));
                $idemRedis->del($idemRedisKey . ':lock');
            }
            echo $output;
        });
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

                $isTeacher = in_array('dashboard.teacher_view', $authUser['permissions'] ?? [])
                    && !in_array('dashboard.global_view', $authUser['permissions'] ?? []);
                if ($isTeacher) {
                    $valStmt = $conn->prepare("
                        SELECT 1 FROM student_group_assignments sga
                        JOIN teacher_group_access tga ON tga.group_id = sga.group_id
                        WHERE sga.student_id = ? AND tga.teacher_user_id = ? AND sga.active = TRUE
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

                // Incluir rol y nombre del remitente en el mensaje WhatsApp
                $senderName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $role;
                $senderRoleDisplay = $role === 'RECTOR' ? 'Rector' : ($role === 'COORDINATOR' ? 'Coordinador' : ($role === 'TEACHER' ? 'Docente' : $role));

                $citMsg = "Citación para {$studentName}.";
                if ($citTime) $citMsg .= "\nHora: {$citTime}";
                if ($citReason) $citMsg .= "\nMotivo: {$citReason}";
                $citMsg .= "\nEnviado por: {$senderRoleDisplay} {$senderName}";
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
                if (!$studentId) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Estudiante requerido para permiso']);
                    break;
                }
                $reason = trim((string)($params['reason'] ?? $params['message'] ?? $params['description'] ?? ''));
                if ($reason === '') {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Motivo requerido para permiso']);
                    break;
                }
                $timeStart = trim((string)($params['timeStart'] ?? ''));
                $timeEnd = trim((string)($params['timeEnd'] ?? ''));
                if (empty($timeStart) || empty($timeEnd)) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Hora de inicio y hora de fin son obligatorias para generar un permiso']);
                    break;
                }
                // Validar formato de hora (HH:MM o HH:MM:SS)
                foreach ([$timeStart, $timeEnd] as $t) {
                    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Formato de hora inválido. Use HH:MM']);
                        break 2;
                    }
                }
                // Validar que el estudiante exista y pertenezca a la escuela
                $stuCheck = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND school_id = ? AND active = TRUE AND deleted_at IS NULL");
                $stuCheck->execute([$studentId, $schoolId]);
                if (!$stuCheck->fetchColumn()) {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado o inactivo']);
                    break;
                }
                // Construir timestamps con zona Bogotá
                $bogotaToday = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
                try {
                    $exitTs = new DateTime("$bogotaToday $timeStart", new DateTimeZone('America/Bogota'));
                    $returnTs = new DateTime("$bogotaToday $timeEnd", new DateTimeZone('America/Bogota'));
                } catch (Exception $e) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Hora inválida']);
                    break;
                }
                // Validar que returnTs sea futuro
                $nowBogota = new DateTime('now', new DateTimeZone('America/Bogota'));
                if ($returnTs <= $nowBogota) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'La hora de retorno debe ser una hora futura']);
                    break;
                }
                // Resolver el bloque/espacio esperado del estudiante al momento del permiso
                $schStmt = $conn->prepare("
                    SELECT sch.schedule_id
                    FROM schedules sch
                    JOIN student_group_assignments sga ON sga.group_id = sch.group_id AND sga.active = TRUE
                    WHERE sga.student_id = ? AND sch.school_id = ?
                      AND sch.day_of_week = EXTRACT(ISODOW FROM (NOW() AT TIME ZONE 'America/Bogota'))
                    ORDER BY sch.start_time
                    LIMIT 1
                ");
                $schStmt->execute([$studentId, $schoolId]);
                $permScheduleId = $schStmt->fetchColumn() ?: null;

                // Insertar permiso con horas reales y contexto espacial
                $stmt = $conn->prepare("
                    INSERT INTO class_exit_authorizations (school_id, student_id, authorized_by_user_id, authorization_reason, exit_time, return_time, schedule_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$schoolId, $studentId, $userId, $reason, $exitTs->format('Y-m-d H:i:s'), $returnTs->format('Y-m-d H:i:s'), $permScheduleId]);

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

                // Insertar en attendance_incidents para que aparezca en el dashboard
                $incStmt = $conn->prepare("
                    INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                    VALUES (uuid_generate_v4(), ?, ?, 'PERMISO', NOW(), ?::jsonb)
                ");
                $incStmt->execute([$schoolId, $studentId, $meta]);

                // Batch INSERT notifications — destinatarios vía
                // school_notification_routes (event_kind PERMISO; default COORDINATOR)
                $coords = array_map(fn($uid) => ['user_id' => $uid],
                    nexoRouteUserIds($conn, (string)$schoolId, 'PERMISO', ['COORDINATOR']));
                if (!empty($coords)) {
                    $rows = [];
                    $notifParams = [];
                    foreach ($coords as $c) {
                        $rows[] = "(?, ?, ?, ?, 'INFO', ?::jsonb, NOW())";
                        $notifParams[] = $schoolId;
                        $notifParams[] = $c['user_id'];
                        $notifParams[] = 'Permiso';
                        $notifParams[] = "Se registró un permiso" . ($studentName ? " para {$studentName}" : '') . ". Ver detalles.";
                        $notifParams[] = $meta;
                    }
                    $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                    try {
                        $conn->prepare($sql)->execute($notifParams);
                    } catch (Throwable $e) {
                        error_log("[OPERATIONS] Permiso notification batch insert error: " . $e->getMessage());
                    }
                }

                // Notificar también al TEACHER que generó el permiso
                try {
                    $teacherNotif = $conn->prepare("
                        INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                        VALUES (?, ?, 'Permiso', ?, 'INFO', ?::jsonb, NOW())
                    ");
                    $teacherNotifMsg = "Se registró un permiso" . ($studentName ? " para {$studentName}" : '') . ". Ver detalles.";
                    $teacherNotif->execute([$schoolId, $userId, $teacherNotifMsg, $meta]);
                } catch (Throwable $e) {
                    error_log("[OPERATIONS] Permiso teacher notification insert error: " . $e->getMessage());
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
                    // Obtener documento del estudiante para el comando al edge
                    $docStmt = $conn->prepare("SELECT document_number, first_name, last_name FROM students WHERE student_id = ? AND school_id = ?");
                    $docStmt->execute([$studentId, $schoolId]);
                    $stuDoc = $docStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$stuDoc) {
                        http_response_code(404);
                        exit(json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']));
                    }
                    $studentDoc = $stuDoc['document_number'];
                    $studentName = trim($stuDoc['first_name'] . ' ' . $stuDoc['last_name']);

                    // Crear autorización con status PENDING_FINGERPRINT — el estudiante
                    // debe poner su huella en el sensor de coordinación para completar la salida.
                    $stmt = $conn->prepare("
                        INSERT INTO school_exit_authorizations (school_id, student_id, authorized_by_user_id, authorization_reason, exit_time, status)
                        VALUES (?, ?, ?, ?, NOW(), 'PENDING_FINGERPRINT')
                    ");
                    $stmt->execute([$schoolId, $studentId, $userId, $reason]);

                    // Buscar el dispositivo edge asignado al coordinador (el que ejecuta la acción)
                    $deviceStmt = $conn->prepare("
                        SELECT device_id FROM edge_devices
                        WHERE school_id = ? AND assigned_user_id = ? AND active = TRUE
                        ORDER BY last_ping DESC NULLS LAST LIMIT 1
                    ");
                    $deviceStmt->execute([$schoolId, $userId]);
                    $deviceId = $deviceStmt->fetchColumn();

                    // Información adicional del estudiante
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
                    $groupName = $stuMeta['group_name'] ?? 'Sin grupo';
                    $teacherName = ($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '');

                    $meta = json_encode([
                        'student_id' => $studentId,
                        'student_name' => trim($studentName),
                        'student_doc' => $studentDoc,
                        'group_name' => $groupName,
                        'teacher_name' => trim($teacherName) ?: $role,
                        'reason' => $reason,
                        'time_start' => $params['timeStart'] ?? null,
                        'time_end' => $params['timeEnd'] ?? null,
                        'action' => 'autorizar_salida',
                        'status' => 'PENDING_FINGERPRINT',
                    ], JSON_UNESCAPED_UNICODE);

                    // Insertar en attendance_incidents para que aparezca en el dashboard
                    $incStmt = $conn->prepare("
                        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                        VALUES (uuid_generate_v4(), ?, ?, 'AUTORIZAR_SALIDA', NOW(), ?::jsonb)
                    ");
                    $incStmt->execute([$schoolId, $studentId, $meta]);

                    if ($deviceId) {
                        // Enviar comando al edge: WAIT_EXIT_FINGERPRINT con el doc del estudiante
                        $cmdPayload = [
                            'command' => 'WAIT_EXIT_FINGERPRINT',
                            'payload' => [
                                'doc' => $studentDoc,
                                'student_name' => $studentName,
                            ],
                            'issued_at' => time(),
                            'issued_by' => $userId,
                        ];

                        // MQTT + Redis (igual que /devices/command)
                        $mqttOk = false;
                        if (file_exists(__DIR__ . '/../core/mqtt_publisher.php')) {
                            require_once __DIR__ . '/../core/mqtt_publisher.php';
                            $mqttOk = publishDeviceCommand($deviceId, $cmdPayload);
                        }
                        try {
                            $redis = getRedisConnection();
                            if ($redis) {
                                $redis->lPush("device:{$deviceId}:commands", json_encode($cmdPayload, JSON_UNESCAPED_UNICODE));
                                $redis->expire("device:{$deviceId}:commands", 86400);
                            }
                        } catch (Exception $e) {
                            securityLog('SALIDA_CMD_REDIS_ERROR', $e->getMessage());
                        }

                        securityLog('SALIDA_FINGERPRINT_CMD', "Device:$deviceId Student:$studentId Doc:$studentDoc MQTT:" . ($mqttOk ? 'OK' : 'FAIL'), $userId, $schoolId);
                    } else {
                        // No hay dispositivo asignado al coordinador — registrar warning
                        securityLog('SALIDA_NO_DEVICE', "No edge device assigned to user $userId for school $schoolId", $userId, $schoolId);
                    }

                    // Notificar coordinadores — routing configurable (event_kind SALIDA)
                    $coords = array_map(fn($uid) => ['user_id' => $uid],
                        nexoRouteUserIds($conn, (string)$schoolId, 'SALIDA', ['COORDINATOR']));
                    if (!empty($coords)) {
                        $rows = [];
                        $nParams = [];
                        foreach ($coords as $c) {
                            $rows[] = "(?, ?, ?, ?, 'INFO', ?::jsonb, NOW())";
                            $nParams[] = $schoolId;
                            $nParams[] = $c['user_id'];
                            $nParams[] = 'Salida autorizada (pendiente huella)';
                            $nParams[] = "Se autorizó la salida de {$studentName}. El estudiante debe poner su huella en el sensor de coordinación para completar la salida.";
                            $nParams[] = $meta;
                        }
                        $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                        try {
                            $conn->prepare($sql)->execute($nParams);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Autorizar salida notification batch insert error: " . $e->getMessage());
                        }
                    }

                    // Notificar al RECTOR — routing configurable (event_kind SALIDA,
                    // solo si la ruta incluye RECTOR; default: sí)
                    $rectIds = nexoRouteUserIds($conn, (string)$schoolId, 'SALIDA_RECTOR', ['RECTOR']);
                    $rects = array_map(fn($uid) => ['user_id' => $uid], $rectIds);
                    if (!empty($rects)) {
                        $rows = [];
                        $rParams = [];
                        foreach ($rects as $r) {
                            $rows[] = "(?, ?, ?, ?, 'INFO', ?::jsonb, NOW())";
                            $rParams[] = $schoolId;
                            $rParams[] = $r['user_id'];
                            $rParams[] = 'Salida autorizada (pendiente huella)';
                            $rParams[] = "Se autorizó la salida de {$studentName}. Pendiente verificación biométrica.";
                            $rParams[] = $meta;
                        }
                        $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                        try {
                            $conn->prepare($sql)->execute($rParams);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Autorizar salida rector notification batch insert error: " . $e->getMessage());
                        }
                    }

                    // Notificar al acudiente (la salida ya está autorizada, solo falta huella)
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
                    'message' => $deviceId
                        ? 'Salida autorizada. El estudiante debe poner su huella en el sensor de coordinación para completar la salida.'
                        : 'Salida autorizada (sin sensor asignado). Configure un sensor en coordinación para verificación biométrica.',
                    'data'    => ['action' => $action, 'student_id' => $studentId, 'pending_fingerprint' => true]
                ]);
                break;

            // Registro manual de presencia — contingencia cuando la
            // biometría falla o el estudiante está exento. Genera un evento
            // INGRESO_MANUAL (cuenta como presencia: matchea 'INGRESO_%') y un
            // incidente REGISTRO_MANUAL con trazabilidad del actor + motivo.
            case 'registro_manual':
                $studentId = $params['student'] ?? $params['student_id'] ?? null;
                $reason    = trim((string)($params['reason'] ?? $params['message'] ?? ''));
                if (!$studentId) {
                    http_response_code(400);
                    exit(json_encode(['status' => 'error', 'message' => 'Debe seleccionar un estudiante']));
                }
                if ($reason === '') {
                    http_response_code(400);
                    exit(json_encode(['status' => 'error', 'message' => 'El motivo del registro manual es obligatorio']));
                }

                $stuStmt = $conn->prepare("
                    SELECT first_name, last_name, document_number, biometric_exempt
                    FROM students
                    WHERE student_id = ? AND school_id = ? AND active = TRUE AND deleted_at IS NULL
                ");
                $stuStmt->execute([$studentId, $schoolId]);
                $stuRow = $stuStmt->fetch(PDO::FETCH_ASSOC);
                if (!$stuRow) {
                    http_response_code(404);
                    exit(json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']));
                }
                $studentName = trim($stuRow['first_name'] . ' ' . $stuRow['last_name']);

                // Evitar doble registro: si ya tiene cualquier INGRESO_% hoy, ya está presente
                $dupStmt = $conn->prepare("
                    SELECT 1 FROM biometric_events
                    WHERE school_id = ? AND student_id = ?
                      AND event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND event_timestamp < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND event_type LIKE 'INGRESO_%'
                    LIMIT 1
                ");
                $dupStmt->execute([$schoolId, $studentId]);
                if ($dupStmt->fetchColumn()) {
                    http_response_code(409);
                    exit(json_encode(['status' => 'error', 'message' => "{$studentName} ya tiene un ingreso registrado hoy"]));
                }

                // Punto de registro: dispositivo asignado al actor; si no tiene,
                // el nodo activo más reciente de la escuela. device_id es NOT NULL.
                $deviceStmt = $conn->prepare("
                    SELECT device_id, device_name FROM edge_devices
                    WHERE school_id = ? AND active = TRUE
                    ORDER BY (assigned_user_id = ?) DESC, last_ping DESC NULLS LAST
                    LIMIT 1
                ");
                $deviceStmt->execute([$schoolId, $userId]);
                $devRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);
                if (!$devRow) {
                    http_response_code(422);
                    exit(json_encode(['status' => 'error', 'message' => 'No hay ningún nodo activo en la institución para asociar el registro manual']));
                }

                $meta = json_encode([
                    'source'       => 'manual',
                    'reason'       => $reason,
                    'registered_by' => $userId,
                    'registered_by_name' => trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')),
                    'student_name' => $studentName,
                    'biometric_exempt' => (bool)($stuRow['biometric_exempt'] ?? false),
                ], JSON_UNESCAPED_UNICODE);

                ctRegisterManualPresence($conn, $schoolId, $studentId, $devRow['device_id'], $meta);
                // Limpiar estado de registro manual pendiente (doc §9.6)
                $conn->prepare("UPDATE students SET manual_pending_until = NULL WHERE student_id = ? AND school_id = ?")
                     ->execute([$studentId, $schoolId]);

                logUserCommand($conn, $schoolId, $userId, $action, [
                    'student_id' => $studentId, 'student_name' => $studentName, 'reason' => $reason,
                ]);
                echo json_encode([
                    'status'  => 'ok',
                    'message' => "Presencia manual registrada para {$studentName} (nodo: {$devRow['device_name']})",
                    'data'    => ['action' => $action, 'student_id' => $studentId],
                ]);
                break;

            // Doc §9.6: marca al estudiante como "registro manual pendiente" —
            // suspende inasistencia/evasión mientras se gestiona el registro.
            case 'registro_manual_pendiente':
                $studentId = $params['student'] ?? $params['student_id'] ?? null;
                $minutes   = max(5, min(480, (int)($params['minutes'] ?? 60)));
                $reason    = trim((string)($params['reason'] ?? ''));
                if (!$studentId) {
                    http_response_code(400);
                    exit(json_encode(['status' => 'error', 'message' => 'Debe seleccionar un estudiante']));
                }
                $mpStmt = $conn->prepare("
                    UPDATE students
                    SET manual_pending_until = NOW() + make_interval(mins => ?),
                        updated_at = NOW()
                    WHERE student_id = ? AND school_id = ? AND active = TRUE AND deleted_at IS NULL
                ");
                $mpStmt->execute([$minutes, $studentId, $schoolId]);
                if ($mpStmt->rowCount() === 0) {
                    http_response_code(404);
                    exit(json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']));
                }
                logUserCommand($conn, $schoolId, $userId, $action, [
                    'student_id' => $studentId, 'minutes' => $minutes, 'reason' => $reason,
                ]);
                echo json_encode([
                    'status'  => 'ok',
                    'message' => "Registro manual pendiente marcado por {$minutes} minutos",
                    'data'    => ['action' => $action, 'student_id' => $studentId, 'pending_minutes' => $minutes],
                ]);
                break;

            case 'pedagogica':
                $groupName   = trim((string)($params['group'] ?? ''));
                $purpose     = trim((string)($params['reason'] ?? $params['message'] ?? $params['description'] ?? ''));
                $destination = trim((string)($params['destination'] ?? $purpose));
                $tripReturn  = trim((string)($params['return_time'] ?? $params['end_time'] ?? ''));

                // Persistir la autorización para que los detectores excluyan a
                // estos estudiantes (ausencia/evasión) durante la salida; sin
                // esto se marcaría inasistencia a todo el grupo en salida
                // pedagógica.
                if ($groupName) {
                    $tripReturnExpr = $tripReturn !== ''
                        ? "?::timestamptz"
                        : "((NOW() AT TIME ZONE 'America/Bogota')::date + ssc.exit_time)::timestamptz";
                    $authIns = $conn->prepare("
                        INSERT INTO pedagogical_trip_authorizations
                            (school_id, student_id, authorized_by_user_id, destination, departure_time, return_time, purpose, metadata_json)
                        SELECT ag.school_id, sga.student_id, ?, ?, NOW(), {$tripReturnExpr}, ?, ?::jsonb
                        FROM student_group_assignments sga
                        JOIN academic_groups ag ON ag.group_id = sga.group_id
                        LEFT JOIN school_schedule_config ssc ON ssc.school_id = ag.school_id AND ssc.work_shift = ag.work_shift
                        WHERE ag.group_name = ? AND ag.school_id = ? AND sga.active = TRUE
                        ON CONFLICT DO NOTHING
                    ");
                    $pa = [$userId, $destination];
                    if ($tripReturn !== '') $pa[] = $tripReturn;
                    $pa[] = $purpose;
                    $pa[] = json_encode(['group_name' => $groupName, 'action' => 'pedagogica'], JSON_UNESCAPED_UNICODE);
                    $pa[] = $groupName;
                    $pa[] = $schoolId;
                    $authIns->execute($pa);
                }

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

                // Notificar al COORDINADOR que ejecutó y al RECTOR
                $pedagSenderName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $role;
                $pedagMeta = json_encode([
                    'group_name' => $groupName,
                    'reason' => $purpose,
                    'destination' => $destination,
                    'sender_name' => $pedagSenderName,
                    'sender_role' => $role,
                    'action' => 'pedagogica',
                ], JSON_UNESCAPED_UNICODE);

                $pedagNotifyUsers = [$userId]; // El coordinador que la ejecutó
                // routing configurable (event_kind PEDAGOGICA; default RECTOR)
                foreach (nexoRouteUserIds($conn, (string)$schoolId, 'PEDAGOGICA', ['RECTOR']) as $rid) {
                    $pedagNotifyUsers[] = $rid;
                }
                $pedagMsg = "Se programó una salida pedagógica" . ($groupName ? " para el grupo {$groupName}" : '') . ". Ver detalles.";
                $rows = [];
                $pedagParams = [];
                foreach ($pedagNotifyUsers as $uid) {
                    $rows[] = "(?, ?, 'Salida pedagógica', ?, 'INFO', ?::jsonb, NOW())";
                    $pedagParams[] = $schoolId;
                    $pedagParams[] = $uid;
                    $pedagParams[] = $pedagMsg;
                    $pedagParams[] = $pedagMeta;
                }
                $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                try {
                    $conn->prepare($sql)->execute($pedagParams);
                } catch (Throwable $e) {
                    error_log("[OPERATIONS] Pedagogica notification batch insert error: " . $e->getMessage());
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
                    $senderRoleDisplay = $role === 'RECTOR' ? 'Rector' : ($role === 'COORDINATOR' ? 'Coordinador' : ($role === 'TEACHER' ? 'Docente' : $role));

                    $meta = json_encode([
                        'student_id' => $studentId,
                        'student_name' => trim($studentName),
                        'group_name' => $groupName,
                        'sender_name' => $senderName,
                        'sender_role' => $senderRoleDisplay,
                        'reason' => $reason,
                        'action' => 'iniciar_seguimiento',
                    ], JSON_UNESCAPED_UNICODE);

                    $psicos = array_map(fn($uid) => ['user_id' => $uid],
                        nexoRouteUserIds($conn, (string)$schoolId, 'SEGUIMIENTO', ['COUNSELOR']));
                    if (!empty($psicos)) {
                        $rows = [];
                        $notifParams = [];
                        foreach ($psicos as $p) {
                            $rows[] = "(?, ?, 'Solicitud de Seguimiento', ?, 'INFO', ?::jsonb, NOW())";
                            $notifParams[] = $schoolId;
                            $notifParams[] = $p['user_id'];
                            $notifParams[] = "Se inició un seguimiento" . ($studentName ? " para {$studentName}" : '') . " solicitado por {$senderRoleDisplay} {$senderName}. Ver detalles.";
                            $notifParams[] = $meta;
                        }
                        $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                        try {
                            $conn->prepare($sql)->execute($notifParams);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Seguimiento notification batch insert error: " . $e->getMessage());
                        }
                    }

                    // Notificar también al RECTOR y al usuario que solicitó el seguimiento
                    $segNotifyUsers = [$userId]; // El coordinador que lo pidió
                    if ($role !== 'RECTOR') {
                        foreach (nexoRouteUserIds($conn, (string)$schoolId, 'SEGUIMIENTO_RECTOR', ['RECTOR']) as $rid) {
                            $segNotifyUsers[] = $rid;
                        }
                    }
                    $segMsg = "Se inició un seguimiento" . ($studentName ? " para {$studentName}" : '') . " solicitado por {$senderRoleDisplay} {$senderName}. Ver detalles.";
                    $rows = [];
                    $segParams = [];
                    foreach ($segNotifyUsers as $uid) {
                        $rows[] = "(?, ?, 'Solicitud de Seguimiento', ?, 'INFO', ?::jsonb, NOW())";
                        $segParams[] = $schoolId;
                        $segParams[] = $uid;
                        $segParams[] = $segMsg;
                        $segParams[] = $meta;
                    }
                    $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                    try {
                        $conn->prepare($sql)->execute($segParams);
                    } catch (Throwable $e) {
                        error_log("[OPERATIONS] Seguimiento rector/coordinator notification batch insert error: " . $e->getMessage());
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                echo json_encode([
                    'status'  => 'ok',
                    'message' => 'Solicitud de seguimiento enviada a psicorientación',
                ]);
                break;

            case 'fusionar_bloque':
                $groupName = trim((string)($params['group'] ?? $params['group_name'] ?? ''));
                $reason = trim((string)($params['reason'] ?? 'Fusión de bloque de clases'));

                if ($groupName === '') {
                    http_response_code(400);
                    exit(json_encode(['status' => 'error', 'message' => 'El grupo es obligatorio para fusionar bloque']));
                }

                // Buscar group_id por nombre
                $grpStmt = $conn->prepare("SELECT group_id FROM academic_groups WHERE group_name = ? AND school_id = ? LIMIT 1");
                $grpStmt->execute([$groupName, $schoolId]);
                $groupId = $grpStmt->fetchColumn();

                if (!$groupId) {
                    http_response_code(404);
                    exit(json_encode(['status' => 'error', 'message' => 'Grupo no encontrado']));
                }

                // Obtener horario esperado del grupo para hoy (schedules)
                $schedStmt = $conn->prepare("
                    SELECT MIN(start_time) AS entry_time, MAX(end_time) AS exit_time
                    FROM schedules
                    WHERE group_id = ? AND school_id = ?
                      AND day_of_week = EXTRACT(ISODOW FROM (NOW() AT TIME ZONE 'America/Bogota'))
                ");
                $schedStmt->execute([$groupId, $schoolId]);
                $schedRow = $schedStmt->fetch(PDO::FETCH_ASSOC);
                $expectedEntry = $schedRow['entry_time'] ?? null;
                $expectedExit = $schedRow['exit_time'] ?? null;

                // Si schedules está vacío (NULL), usar school_schedule_config
                // por work_shift del grupo como fallback
                if ($expectedEntry === null || $expectedExit === null) {
                    $sscStmt = $conn->prepare("
                        SELECT ssc.entry_time, ssc.exit_time
                        FROM school_schedule_config ssc
                        JOIN academic_groups ag ON ag.school_id = ssc.school_id
                            AND ag.work_shift = ssc.work_shift
                        WHERE ag.group_id = ? AND ssc.school_id = ?
                        ORDER BY ssc.entry_time ASC
                        LIMIT 1
                    ");
                    $sscStmt->execute([$groupId, $schoolId]);
                    $sscRow = $sscStmt->fetch(PDO::FETCH_ASSOC);
                    if ($sscRow) {
                        $expectedEntry = $expectedEntry ?? $sscRow['entry_time'];
                        $expectedExit = $expectedExit ?? $sscRow['exit_time'];
                    }
                }

                // UPSERT en daily_schedule_config: marcar como fusionado
                $dscMeta = json_encode([
                    'action' => 'fusionar_bloque',
                    'reason' => $reason,
                    'merged' => true,
                ], JSON_UNESCAPED_UNICODE);
                $dscStmt = $conn->prepare("
                    INSERT INTO daily_schedule_config (school_id, group_id, config_date, has_classes, expected_entry_time, expected_exit_time, created_by_user_id, metadata_json)
                    VALUES (?, ?, (NOW() AT TIME ZONE 'America/Bogota')::date, TRUE, ?, ?, ?, ?::jsonb)
                    ON CONFLICT (school_id, group_id, config_date)
                    DO UPDATE SET has_classes = TRUE, expected_entry_time = EXCLUDED.expected_entry_time, expected_exit_time = EXCLUDED.expected_exit_time, metadata_json = EXCLUDED.metadata_json
                ");
                $dscStmt->execute([$schoolId, $groupId, $expectedEntry, $expectedExit, $authUser['id'], $dscMeta]);

                // Obtener nombre del estudiante si se proporcionó
                $studentNameForLog = '';
                $studentIdForLog = $params['student'] ?? $params['student_id'] ?? null;
                if ($studentIdForLog) {
                    $snStmt = $conn->prepare("SELECT first_name, last_name FROM students WHERE student_id = ? AND school_id = ? LIMIT 1");
                    $snStmt->execute([$studentIdForLog, $schoolId]);
                    $snRow = $snStmt->fetch(PDO::FETCH_ASSOC);
                    if ($snRow) {
                        $studentNameForLog = trim($snRow['first_name'] . ' ' . $snRow['last_name']);
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, array_merge($params, [
                    'metadata_json' => json_encode([
                        'action' => 'fusionar_bloque',
                        'reason' => $reason,
                        'merged' => true,
                        'student_name' => $studentNameForLog,
                    ], JSON_UNESCAPED_UNICODE),
                ]));
                securityLog('OPERATION_EXECUTED', "User:$userId Role:$role Action:$action Group:$groupName");
                echo json_encode([
                    'status'  => 'ok',
                    'message' => 'Bloque de clases fusionado correctamente',
                    'data' => [
                        'action' => $action,
                        'group' => $groupName,
                        'merged' => true,
                    ],
                ]);
                break;

            case 'extender_bloque':
                $newExitTime = trim((string)($params['time'] ?? ''));
                if ($newExitTime === '') {
                    http_response_code(400);
                    exit(json_encode(['status' => 'error', 'message' => 'La nueva hora de fin es obligatoria']));
                }

                // Soportar filtrado por group_name (grupo específico a extender)
                $groupName = trim((string)($params['group_name'] ?? $params['group'] ?? ''));
                $todayDate = "(NOW() AT TIME ZONE 'America/Bogota')::date";

                // Verificar si ya existen configuraciones para hoy
                $checkStmt = $conn->prepare("
                    SELECT COUNT(*) FROM daily_schedule_config dsc
                    WHERE school_id = ? AND config_date = {$todayDate}
                ");
                $checkStmt->execute([$schoolId]);
                $existingCount = (int)$checkStmt->fetchColumn();

                // extender_bloque también debe suprimir las transiciones de
                // bloque dentro de la ventana extendida (igual que
                // fusionar_bloque → metadata merged=true + entry_time). Sin
                // esto, el grupo queda marcado como evasión/ausente al no
                // pasar al siguiente salón aunque el docente lo retuvo
                // legítimamente (documento §4.5).
                $extMeta = json_encode(['action' => 'extender_bloque', 'merged' => true], JSON_UNESCAPED_UNICODE);
                if ($existingCount > 0) {
                    // UPDATE existing configs for today
                    if ($groupName !== '') {
                        // Filtrar por group_name si se especifica
                        $updStmt = $conn->prepare("
                            UPDATE daily_schedule_config dsc
                            SET expected_exit_time = ?::time,
                                expected_entry_time = COALESCE(dsc.expected_entry_time,
                                    (SELECT MIN(start_time) FROM schedules sch
                                      WHERE sch.group_id = dsc.group_id AND sch.school_id = dsc.school_id
                                        AND sch.day_of_week = EXTRACT(ISODOW FROM (NOW() AT TIME ZONE 'America/Bogota')))),
                                metadata_json = COALESCE(dsc.metadata_json, '{}'::jsonb) || ?::jsonb
                            WHERE dsc.school_id = ? AND dsc.config_date = {$todayDate}
                              AND dsc.group_id IN (
                                  SELECT group_id FROM academic_groups
                                  WHERE school_id = ? AND group_name = ?
                              )
                        ");
                        $updStmt->execute([$newExitTime, $extMeta, $schoolId, $schoolId, $groupName]);
                    } else {
                        $updStmt = $conn->prepare("
                            UPDATE daily_schedule_config dsc
                            SET expected_exit_time = ?::time,
                                expected_entry_time = COALESCE(dsc.expected_entry_time,
                                    (SELECT MIN(start_time) FROM schedules sch
                                      WHERE sch.group_id = dsc.group_id AND sch.school_id = dsc.school_id
                                        AND sch.day_of_week = EXTRACT(ISODOW FROM (NOW() AT TIME ZONE 'America/Bogota')))),
                                metadata_json = COALESCE(dsc.metadata_json, '{}'::jsonb) || ?::jsonb
                            WHERE dsc.school_id = ? AND dsc.config_date = {$todayDate}
                        ");
                        $updStmt->execute([$newExitTime, $extMeta, $schoolId]);
                    }
                } else {
                    // INSERT para todos los grupos activos de la institución —
                    // entry_time = primer bloque del día para cubrir la ventana
                    if ($groupName !== '') {
                        $insStmt = $conn->prepare("
                            INSERT INTO daily_schedule_config (school_id, group_id, config_date, has_classes, expected_entry_time, expected_exit_time, created_by_user_id, metadata_json)
                            SELECT ?, ag.group_id, {$todayDate}, TRUE,
                                   (SELECT MIN(start_time) FROM schedules sch WHERE sch.group_id = ag.group_id AND sch.school_id = ag.school_id
                                     AND sch.day_of_week = EXTRACT(ISODOW FROM (NOW() AT TIME ZONE 'America/Bogota'))),
                                   ?::time, ?, ?::jsonb
                            FROM academic_groups ag
                            WHERE ag.school_id = ?
                              AND ag.group_name = ?
                              AND EXISTS (
                                  SELECT 1 FROM student_group_assignments sga
                                  WHERE sga.group_id = ag.group_id AND sga.active = TRUE
                              )
                        ");
                        $insStmt->execute([$schoolId, $newExitTime, $authUser['id'], $extMeta, $schoolId, $groupName]);
                    } else {
                        $insStmt = $conn->prepare("
                            INSERT INTO daily_schedule_config (school_id, group_id, config_date, has_classes, expected_entry_time, expected_exit_time, created_by_user_id, metadata_json)
                            SELECT ?, ag.group_id, {$todayDate}, TRUE,
                                   (SELECT MIN(start_time) FROM schedules sch WHERE sch.group_id = ag.group_id AND sch.school_id = ag.school_id
                                     AND sch.day_of_week = EXTRACT(ISODOW FROM (NOW() AT TIME ZONE 'America/Bogota'))),
                                   ?::time, ?, ?::jsonb
                            FROM academic_groups ag
                            WHERE ag.school_id = ?
                              AND EXISTS (
                                  SELECT 1 FROM student_group_assignments sga
                                  WHERE sga.group_id = ag.group_id AND sga.active = TRUE
                              )
                        ");
                        $insStmt->execute([$schoolId, $newExitTime, $authUser['id'], $extMeta, $schoolId]);
                    }
                }

                logUserCommand($conn, $schoolId, $userId, $action, $params);
                securityLog('OPERATION_EXECUTED', "User:$userId Role:$role Action:$action NewExitTime:$newExitTime");
                echo json_encode([
                    'status'  => 'ok',
                    'message' => 'Bloque extendido correctamente',
                    'data' => [
                        'action' => $action,
                        'new_exit_time' => $newExitTime,
                    ],
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
                    // Cada grupo afectado se comunica a sus acudientes.
                    $changes = $params['changes'] ?? [];
                    // Camino simple (Operation.jsx): un solo grupo + hora de salida opcional
                    if (empty($changes) && !empty($params['group'])) {
                        $changes = [[
                            'group'      => $params['group'],
                            'no_classes' => !empty($params['no_classes']),
                            'entry_time' => $params['entry_time'] ?? null,
                            'exit_time'  => $params['exit_time'] ?? $params['time'] ?? null,
                        ]];
                    }
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
                            VALUES (?, ?, (NOW() AT TIME ZONE 'America/Bogota')::date, ?, ?, ?, ?)
                            ON CONFLICT (school_id, group_id, config_date)
                            DO UPDATE SET has_classes = EXCLUDED.has_classes, expected_entry_time = EXCLUDED.expected_entry_time, expected_exit_time = EXCLUDED.expected_exit_time
                        ");
                        $dscStmt->execute([$schoolId, $groupId, !$noClasses, $entryTime, $exitTime, $authUser['id']]);

                        // Comunicar la modificación de jornada a los acudientes del grupo
                        $guardStmt = $conn->prepare("
                            SELECT DISTINCT g.whatsapp_phone, g.guardian_id
                            FROM guardians g
                            JOIN guardian_student_relationships gsr ON g.guardian_id = gsr.guardian_id
                            JOIN student_group_assignments sga ON gsr.student_id = sga.student_id AND sga.active = TRUE
                            WHERE sga.group_id = ?
                        ");
                        $guardStmt->execute([$groupId]);
                        if ($noClasses) {
                            $horarioMsg = "📅 *NEXO — Cambio de jornada*\n\nGrupo: *{$groupName}*\nHoy *no habrá clases* para este grupo.\nMotivo: {$reason}";
                        } else {
                            $detalle = [];
                            if ($entryTime) $detalle[] = "entrada {$entryTime}";
                            if ($exitTime)  $detalle[] = "salida {$exitTime}";
                            $horarioMsg = "📅 *NEXO — Cambio de jornada*\n\nGrupo: *{$groupName}*\n" . ($detalle ? "Nuevo horario de hoy: " . implode(' · ', $detalle) . "\n" : '') . "Motivo: {$reason}";
                        }
                        while ($gRow = $guardStmt->fetch(PDO::FETCH_ASSOC)) {
                            if (!empty($gRow['whatsapp_phone'])) {
                                enqueueTwilioJob($gRow['whatsapp_phone'], $horarioMsg, $schoolId, null, $gRow['guardian_id'], $userId, 'HORARIO');
                            }
                        }
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
                        $msg = "⚠️ *NEXO — Alerta institucional*\n\nTipo: *INCIDENTE*\nReportado por: {$role}\nDetalle: {$reason}";
                        // routing configurable (event_kind INCIDENTE; default COORDINATOR)
                        $incidentUids = nexoRouteUserIds($conn, (string)$schoolId, 'INCIDENTE', ['COORDINATOR']);
                        if (!empty($incidentUids)) {
                            $ph = implode(',', array_fill(0, count($incidentUids), '?'));
                            $fStmt = $conn->prepare("SELECT phone FROM users WHERE user_id IN ($ph)");
                            $fStmt->execute($incidentUids);
                            while ($fRow = $fStmt->fetch(PDO::FETCH_ASSOC)) {
                                if (!empty($fRow['phone'])) {
                                    enqueueTwilioJob($fRow['phone'], $msg, $schoolId, null, null, $userId, 'NOTIFY_ROLE');
                                }
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

                    // Notificar internamente a COORDINADOR y RECTOR
                    $dañoReporterName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: $role;
                    $dañoMeta = json_encode([
                        'location' => $locationDaño,
                        'reason' => $reason,
                        'reporter_name' => $dañoReporterName,
                        'reporter_role' => $role,
                        'action' => 'daño',
                    ], JSON_UNESCAPED_UNICODE);

                    $dañoNotifyRoles = ['COORDINATOR', 'RECTOR'];
                    $dañoPlaceholders = implode(',', array_fill(0, count($dañoNotifyRoles), '?'));
                    $dañoStmt = $conn->prepare("
                        SELECT user_id FROM users
                        WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($dañoPlaceholders)) AND active = TRUE
                    ");
                    $dañoStmt->execute(array_merge([$schoolId], $dañoNotifyRoles));
                    $dañoRecipients = $dañoStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($dañoRecipients)) {
                        $rows = [];
                        $dañoParams = [];
                        foreach ($dañoRecipients as $r) {
                            $rows[] = "(?, ?, 'Daño reportado', ?, 'ALERT', ?::jsonb, NOW())";
                            $dañoParams[] = $schoolId;
                            $dañoParams[] = $r['user_id'];
                            $dañoParams[] = "Se reportó un daño" . ($reason ? ": {$reason}" : '') . ". Ver detalles.";
                            $dañoParams[] = $dañoMeta;
                        }
                        $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                        try {
                            $conn->prepare($sql)->execute($dañoParams);
                        } catch (Throwable $e) {
                            error_log("[OPERATIONS] Daño notification batch insert error: " . $e->getMessage());
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
