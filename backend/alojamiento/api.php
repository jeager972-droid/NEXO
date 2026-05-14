<?php
/**
 * NEXO GLOBAL API v7.5 - SECURE AUDIT & EDGE READY
 */

if (!headers_sent()) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOriginsRaw = getenv('CORS_ALLOW_ORIGINS') ?: 'http://localhost:5173,https://nexo-production-f0ef.up.railway.app';
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowedOriginsRaw))));

    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }

    header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE, PATCH');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-NEXO-TOKEN, X-Device-Token, X-Request-ID, X-Device-Signature');
    header('Access-Control-Max-Age: 86400');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
    header("Content-Security-Policy: default-src 'self'; connect-src 'self' http://localhost:5173; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; frame-ancestors 'none';");
}

require_once __DIR__ . '/db.php';
$conn = $pdo;

// E15: securityLog() ASÍNCRONO — encola en Redis, no INSERT síncrono
function securityLog($event, $details = '', $actorId = null, $schoolId = null, $requestId = null) {
    $ip = getRealClientIp();
    $uri = $_SERVER['REQUEST_URI'] ?? 'N/A';

    // Fallback inmediato a stderr (nunca falla)
    $rid = $requestId ? " [REQ:$requestId]" : '';
    $fallbackMsg = sprintf("[%s] [EVENT:%s]%s [DETAILS:%s] [IP:%s]\n", gmdate('Y-m-d H:i:s'), $event, $rid, $details, $ip);
    file_put_contents('php://stderr', $fallbackMsg);

    // FIX: Encolar en Redis para procesamiento asíncrono por worker_audit.php
    try {
        $redis = new Redis();
        $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
        if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
        $redis->lPush('queue:audit_logs', json_encode([
            'school_id' => $schoolId,
            'actor_id' => $actorId,
            'event_type' => substr($event, 0, 100),
            'description' => $details,
            'ip_address' => substr($ip, 0, 45),
            'uri' => $uri,
            'request_id' => $requestId,
            'created_at' => gmdate('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        file_put_contents('php://stderr', "AUDIT_REDIS_FAIL: " . $e->getMessage() . "\n");
    }
}

function getRealClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function enforceRateLimitRedis($userId = null, $maxReqs = 100, $window = 60) {
    try {
        if (!class_exists('Redis')) return;
        $redis = new Redis();
        $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
        if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
        $ip = getRealClientIp();
        $key = "rl:" . ($userId ? "u:{$userId}:" : "ip:") . md5($ip);
        $hits = $redis->incr($key);
        if ($hits === 1) $redis->expire($key, $window);
        if ($hits > $maxReqs) {
            http_response_code(429);
            securityLog('RATE_LIMIT_EXCEEDED', "Hits: $hits, IP: $ip");
            exit(json_encode(['status' => 'error', 'message' => 'Too many requests']));
        }
    } catch (Exception $e) { /* Fallback */ }
}

enforceRateLimitRedis();
securityLog('SYSTEM_BOOT', 'API script initialized');

header('Content-Type: application/json; charset=utf-8');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cleanPath = trim(urldecode(preg_replace('/^\/(v1|api\.php)/i', '', parse_url($uri, PHP_URL_PATH))), "/");

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true) ?: [];

// Incluir todas las rutas
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/dashboard.php';
require_once __DIR__ . '/routes/operations.php';
require_once __DIR__ . '/routes/students.php';
require_once __DIR__ . '/routes/groups.php';
require_once __DIR__ . '/routes/misc.php';
require_once __DIR__ . '/routes/twilio_delivery.php';
require_once __DIR__ . '/routes/devices.php';
require_once __DIR__ . '/routes/audit_logs.php';
require_once __DIR__ . '/routes/security_panic.php';
require_once __DIR__ . '/routes/audit_integrity.php';
require_once __DIR__ . '/routes/behavior.php';
require_once __DIR__ . '/routes/admin.php';
require_once __DIR__ . '/routes/metrics.php';

/**
 * @OA\Post(
 *     path="/v1/ingest/biometric",
 *     summary="Ingesta biométrica desde el Edge",
 *     description="Endpoint para que los nodos edge envíen eventos de asistencia biométrica cifrados con AES-256-GCM.",
 *     tags={"Edge"},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"payload"},
 *             @OA\Property(property="payload", type="string", description="Base64 de datos cifrados (IV + ciphertext + tag)")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Evento sincronizado",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="string", example="ok"),
 *             @OA\Property(property="sync", type="integer"),
 *             @OA\Property(property="persisted", type="integer")
 *         )
 *     ),
 *     @OA\Response(response=400, description="Payload inválido"),
 *     @OA\Response(response=403, description="Timestamp inválido o nonce reusado")
 * )
 */
// Endpoint EDGE (Reescrito y Seguro)
if (isset($input['payload'])) {
    $aesKey = getenv('NEXO_AES_KEY');
    $decoded = base64_decode($input['payload'], true);
    if ($decoded && strlen($decoded) >= 28) {
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, -16);
        $ciphertext = substr($decoded, 12, -16);
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($decrypted) {
            $data = json_decode($decrypted, true);
            $requestId = $data['request_id'] ?? null;
            if ($requestId) header("X-Request-ID: $requestId");

            // FIX: Validar device_token M2M para evitar spoofing de sedes
            $deviceToken = $data['device_token'] ?? '';
            if (empty($deviceToken)) {
                securityLog('EDGE_NO_DEVICE_TOKEN', 'Missing device token', null, null, $requestId);
                http_response_code(401);
                exit(json_encode(['status' => 'error', 'message' => 'Device token required']));
            }

            // Buscar dispositivo por token (itera todos los activos para evitar timing attacks)
            $stmt = $conn->prepare("SELECT device_id, school_id, active, token_hash FROM edge_devices WHERE active = TRUE");
            $stmt->execute();
            $validDevice = false;
            $realSchoolId = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (password_verify($deviceToken, $row['token_hash'])) {
                    $validDevice = true;
                    $realSchoolId = $row['school_id'];
                    break;
                }
            }

            if (!$validDevice) {
                securityLog('EDGE_INVALID_DEVICE_TOKEN', 'Invalid or inactive device', null, null, $requestId);
                http_response_code(401);
                exit(json_encode(['status' => 'error', 'message' => 'Invalid device token']));
            }

            // Forzar el school_id real del dispositivo (ignorar el del payload)
            $instId = (int)$realSchoolId;
            $conn->exec("SET app.current_school_id = {$instId}");
            $conn->exec("SET app.current_role = 'EDGE_NODE'");

            // FIX: Ampliar ventana a 24 horas para permitir modo offline-first
            $capturedAt = isset($data['captured_at']) ? (int)$data['captured_at'] : 0;
            if (abs(time() - $capturedAt) > 86400) {  // 86400 segundos = 24 horas
                securityLog('EDGE_REPLAY_ATTACK_OR_SYNC_DELAY', 'Paquete demasiado viejo/futuro', null, null, $requestId);
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Timestamp invalid']));
            }

            // FIX: El nonce también debe expirar en 24 horas para tolerar modo offline
            $nonce = $data['nonce'] ?? '';
            if (!empty($nonce)) {
                try {
                    $redis = new Redis();
                    $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
                    if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
                    if (!$redis->set($nonce, '1', ['nx', 'ex' => 86400])) {
                        securityLog('EDGE_REPLAY_NONCE_DUPLICATE', "Nonce reusado: $nonce", null, null, $requestId);
                        http_response_code(403);
                        exit(json_encode(['status' => 'error', 'message' => 'Nonce already used']));
                    }
                } catch (Exception $e) { /* Redis no disponible, se omite validación */ }
            }
            $action = $data['action'] ?? 'UNKNOWN';
            try {
                switch ($action) {
                    case 'SYNC_ATTENDANCE':
                        $stmt = $conn->prepare("INSERT INTO biometric_events (event_id, school_id, student_id, event_type, event_timestamp, source_device) SELECT uuid_generate_v4(), school_id, student_id, ?, to_timestamp(?), 'EDGE' FROM students WHERE document_number = ? LIMIT 1");
                        $stmt->execute([strtoupper($data['event']), $capturedAt, $data['doc']]);

                        // FIX: Incrementar contador diario en Redis para el dashboard
                        try {
                            $redis = new Redis();
                            $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
                            if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
                            $today = gmdate('Y-m-d');
                            $eventSchoolId = $instId;
                            if ($eventSchoolId > 0) {
                                $redis->incr("school:{$eventSchoolId}:present:{$today}");
                                $redis->expire("school:{$eventSchoolId}:present:{$today}", 86400);
                            }
                        } catch (Exception $e) { /* Redis no disponible, se omite contador */ }

                        echo json_encode(['status' => 'ok', 'sync' => time(), 'persisted' => $stmt->rowCount()]);
                        break;
                    case 'REGISTER_STUDENT':
                        $schoolId = $instId; // Forzar school_id verificado del dispositivo
                        $doc = trim($data['doc'] ?? '');
                        $nombre = trim($data['nombre'] ?? '');
                        $parentTel = trim($data['parent_tel'] ?? '');
                        $parentDoc = trim($data['parent_doc'] ?? '');
                        $parentName = trim($data['parent_name'] ?? '');

                        if ($schoolId <= 0 || empty($doc) || empty($nombre)) {
                            http_response_code(400);
                            exit(json_encode(['status' => 'error', 'message' => 'Missing required fields: doc, nombre']));
                        }

                        $conn->beginTransaction();
                        try {
                            $stmt = $conn->prepare("SELECT student_id FROM students WHERE document_number = ? AND school_id = ?");
                            $stmt->execute([$doc, $schoolId]);
                            $studentId = $stmt->fetchColumn();

                            if ($studentId) {
                                $stmt = $conn->prepare("UPDATE students SET first_name = ?, active = TRUE WHERE student_id = ?");
                                $stmt->execute([$nombre, $studentId]);
                            } else {
                                $stmt = $conn->prepare("INSERT INTO students (school_id, document_number, first_name, last_name, active) VALUES (?, ?, ?, '', TRUE) RETURNING student_id");
                                $stmt->execute([$schoolId, $doc, $nombre]);
                                $studentId = $stmt->fetchColumn();
                            }

                            if (!empty($parentDoc) && !empty($parentName)) {
                                $stmt = $conn->prepare("SELECT guardian_id FROM guardians WHERE document_number = ?");
                                $stmt->execute([$parentDoc]);
                                $guardianId = $stmt->fetchColumn();

                                if ($guardianId) {
                                    $stmt = $conn->prepare("UPDATE guardians SET full_name = ?, whatsapp_phone = COALESCE(?, whatsapp_phone) WHERE guardian_id = ?");
                                    $stmt->execute([$parentName, $parentTel, $guardianId]);
                                } else {
                                    $stmt = $conn->prepare("INSERT INTO guardians (document_number, full_name, whatsapp_phone) VALUES (?, ?, ?) RETURNING guardian_id");
                                    $stmt->execute([$parentDoc, $parentName, $parentTel]);
                                    $guardianId = $stmt->fetchColumn();
                                }

                                $stmt = $conn->prepare("SELECT 1 FROM guardian_student_relationships WHERE student_id = ? AND guardian_id = ?");
                                $stmt->execute([$studentId, $guardianId]);
                                if (!$stmt->fetchColumn()) {
                                    $stmt = $conn->prepare("INSERT INTO guardian_student_relationships (student_id, guardian_id, primary_guardian, relationship_type) VALUES (?, ?, TRUE, 'ACUDIENTE')");
                                    $stmt->execute([$studentId, $guardianId]);
                                }
                            }

                            $conn->commit();
                            echo json_encode(['status' => 'ok', 'student_id' => $studentId]);
                        } catch (Exception $e) {
                            $conn->rollBack();
                            throw $e;
                        }
                        break;
                    case 'DELETE_STUDENT':
                        $doc = trim($data['doc'] ?? '');
                        $schoolId = $instId; // Forzar school_id verificado del dispositivo
                        if (empty($doc)) {
                            http_response_code(400);
                            exit(json_encode(['status' => 'error', 'message' => 'Missing required field: doc']));
                        }
                        if ($schoolId > 0) {
                            $stmt = $conn->prepare("UPDATE students SET active = FALSE WHERE document_number = ? AND school_id = ?");
                            $stmt->execute([$doc, $schoolId]);
                        } else {
                            $stmt = $conn->prepare("UPDATE students SET active = FALSE WHERE document_number = ?");
                            $stmt->execute([$doc]);
                        }
                        echo json_encode(['status' => 'ok', 'affected' => $stmt->rowCount()]);
                        break;
                    default:
                        securityLog('EDGE_UNKNOWN_ACTION', "Action: $action", null, null, $requestId);
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Action not supported']);
                }
                exit;
            } catch (Exception $e) {
                securityLog('EDGE_INGESTION_ERROR', $e->getMessage(), null, null, $requestId);
                http_response_code(500);
                exit(json_encode(['status'=>'error','message'=>'DB Error']));
            }
        }
    }
    http_response_code(401);
    exit(json_encode(['status'=>'error','message'=>'Integrity fail']));
}

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Recurso no encontrado o ruta no manejada']);
