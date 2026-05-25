<?php
/**
 * NEXO GLOBAL API v7.5 - SECURE AUDIT & EDGE READY
 */

/* ============================================================
   CORS HARDENING — ejecutado SIEMPRE antes de cualquier lógica
   ============================================================ */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isAllowed = false;

if ($origin !== '') {
    // PROD: CORS_ALLOW_ORIGINS debe ser una lista exacta sin wildcards.
    // Ejemplo: https://nexo-production-13c0.up.railway.app,https://nexo.edu.co
    $envOrigins = getenv('CORS_ALLOW_ORIGINS') ?: 'https://nexo-bay-mu.vercel.app';
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $envOrigins))));

    $isAllowed = in_array($origin, $allowedOrigins, true);

    if ($isAllowed) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization, Accept, X-NEXO-TOKEN, X-Device-Token, X-Request-ID, X-Device-Signature');
        header('Access-Control-Max-Age: 86400');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(200);
    exit();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
header("Content-Security-Policy: default-src 'self'; connect-src 'self' http://localhost:5173; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; frame-ancestors 'none';");

require_once __DIR__ . '/boot_check.php';
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
$cleanPath = '/' . $cleanPath;

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
require_once __DIR__ . '/routes/telemetry.php';

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
                } catch (Exception $e) {
                    securityLog('EDGE_REPLAY_NONCE_REDIS_DOWN', 'Nonce validation unavailable: ' . $e->getMessage(), null, null, $requestId);
                    http_response_code(503);
                    exit(json_encode(['status' => 'error', 'message' => 'Nonce validation unavailable']));
                }
            }
            // V2: 100% Async Ingestion — No tocar PostgreSQL en el request path
            $action = $data['action'] ?? 'UNKNOWN';
            try {
                $redisIngest = new Redis();
                $redisIngest->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
                if ($pass = getenv('REDIS_PASSWORD')) $redisIngest->auth($pass);

                $queuePayload = json_encode([
                    'action' => $action,
                    'data' => $data,
                    'school_id' => $instId,
                    'request_id' => $requestId,
                    'received_at' => time()
                ], JSON_UNESCAPED_UNICODE);

                $redisIngest->rPush('queue:biometric_ingest', $queuePayload);
                $redisIngest->expire('queue:biometric_ingest', 86400);

                // Contador diario en Redis para dashboard (no bloquea)
                if ($action === 'SYNC_ATTENDANCE') {
                    $today = gmdate('Y-m-d');
                    $redisIngest->incr("school:{$instId}:present:{$today}");
                    $redisIngest->expire("school:{$instId}:present:{$today}", 86400);
                }

                http_response_code(202);
                echo json_encode(['status' => 'accepted', 'action' => $action, 'request_id' => $requestId]);
                exit;
            } catch (Exception $e) {
                securityLog('EDGE_INGESTION_REDIS_FAIL', $e->getMessage(), null, null, $requestId);
                http_response_code(503);
                exit(json_encode(['status'=>'error','message'=>'Ingestion queue unavailable']));
            }
        }
    }
    http_response_code(401);
    exit(json_encode(['status'=>'error','message'=>'Integrity fail']));
}

// ============================================================
// HEALTH CHECK: Estado de workers
// ============================================================
if ($cleanPath === '/health/workers') {
    header('Content-Type: application/json; charset=utf-8');
    $checks = [];
    $allHealthy = true;
    try {
        $redisHealth = new Redis();
        $redisHealth->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
        if ($pass = getenv('REDIS_PASSWORD')) $redisHealth->auth($pass);

        // Audit worker
        $auditHeartbeat = (int)$redisHealth->get('worker:audit:last_heartbeat');
        $auditAge = time() - $auditHeartbeat;
        $checks['audit_worker'] = [
            'last_heartbeat' => $auditHeartbeat,
            'seconds_ago' => $auditAge,
            'healthy' => $auditAge <= 300
        ];
        if ($auditAge > 300) $allHealthy = false;

        // Twilio worker
        $twilioHeartbeat = (int)$redisHealth->get('worker:twilio:last_heartbeat');
        $twilioAge = time() - $twilioHeartbeat;
        $checks['twilio_worker'] = [
            'last_heartbeat' => $twilioHeartbeat,
            'seconds_ago' => $twilioAge,
            'healthy' => $twilioAge <= 300
        ];
        if ($twilioAge > 300) $allHealthy = false;

        http_response_code($allHealthy ? 200 : 503);
        echo json_encode(['status' => $allHealthy ? 'ok' : 'degraded', 'checks' => $checks]);
    } catch (Exception $e) {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'Health check unavailable: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Recurso no encontrado o ruta no manejado']);
