<?php
/**
 * NEXO GLOBAL API v7.5 - SECURE AUDIT & EDGE READY
 */

// FIX: En producción, los notices/warnings de PHP NO deben ir a stdout (contaminan JSON)
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

/* ============================================================
   CORS HARDENING — ejecutado SIEMPRE antes de cualquier lógica
   ============================================================ */
require_once __DIR__ . '/routes/_cors_middleware.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
// CSP en API JSON: solo frame-ancestors es relevante (previene clickjacking).
// Las respuestas JSON no tienen DOM, un CSP con * no protege nada y es ruido.
header("Content-Security-Policy: frame-ancestors 'none';");

require_once __DIR__ . '/boot_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routes/_auth_middleware.php';

// FIX: Asignar $conn INMEDIATAMENTE después de db.php para que esté disponible en todas las rutas
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
        $redis = getRedisConnection();
        if ($redis) {
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
        }
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
        $redis = getRedisConnection();
        if (!$redis) return;
        $ip = getRealClientIp();
        $key = "rl:" . ($userId ? "u:{$userId}:" : "ip:") . md5($ip);
        $hits = $redis->incr($key);
        if ($hits === 1) $redis->expire($key, $window);
        if ($hits > $maxReqs) {
            http_response_code(429);
            securityLog('RATE_LIMIT_EXCEEDED', "Hits: $hits, IP: $ip");
            exit(json_encode(['status' => 'error', 'message' => 'Too many requests']));
        }
    } catch (Exception $e) {
        // Si Redis falla, loggear pero no bloquear (fallback a sin rate limit)
        securityLog('RATE_LIMIT_REDIS_DOWN', 'Redis unavailable, allowing request without rate limit');
    }
}

enforceRateLimitRedis();

header('Content-Type: application/json; charset=utf-8');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cleanPath = trim(urldecode(preg_replace('/^\/(v1|api\.php)/i', '', parse_url($uri, PHP_URL_PATH))), "/");
$cleanPath = '/' . $cleanPath;

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true) ?: [];

// operations.php se carga siempre porque exporta la función sendTwilioDirect (usada por auth.php y otros)
require_once __DIR__ . '/routes/operations.php';

$prefix = explode('/', trim($cleanPath, '/'))[0];
$routeMap = [
    'auth' => 'auth.php',
    'dashboard' => 'dashboard.php',
    'students' => 'students.php',
    'groups' => 'groups.php',
    'contacto' => 'misc.php',
    'notifications' => 'misc.php',
    'reports' => 'misc.php',
    'webhooks' => 'twilio_delivery.php',
    'operations' => 'operations.php',
    'devices' => 'devices.php',
    'audit' => ['audit_logs.php', 'audit_integrity.php', 'audit_full.php'],
    'security' => 'security_panic.php',
    'behavior' => 'behavior.php',
    'admin' => 'admin.php',
    'metrics' => 'metrics.php',
    'telemetry' => 'telemetry.php',
    'users' => 'users.php',
    'consultation' => 'consultations.php',
    'consultations' => 'consultations.php',
    'tracking' => 'tracking.php',
];

if (isset($routeMap[$prefix])) {
    $files = (array)$routeMap[$prefix];
    foreach ($files as $f) {
        // operations.php ya está incluido arriba
        if ($f !== 'operations.php') {
            require_once __DIR__ . '/routes/' . $f;
        }
    }
}

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

            // Buscar dispositivo por device_id (O(1) lookup, evita UUID vs int crash)
            $requestDeviceId = $data['device_id'] ?? null;

            // FAIL-FAST: Si no es un UUID válido, rechazar antes de golpear PDO
            if (!$requestDeviceId || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $requestDeviceId)) {
                securityLog('EDGE_INVALID_DEVICE_ID', 'Device ID malformado', null, null, $requestId);
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Formato de Device ID inválido']));
            }

            $stmt = $conn->prepare("SELECT device_id, school_id, active, token_hash FROM edge_devices WHERE device_id = ? AND active = TRUE");
            $stmt->execute([$requestDeviceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $validDevice = false;
            $realSchoolId = null;
            if ($row && password_verify($deviceToken, $row['token_hash'])) {
                $validDevice = true;
                $realSchoolId = $row['school_id'];
            }

            if (!$validDevice) {
                securityLog('EDGE_INVALID_DEVICE_TOKEN', 'Invalid or inactive device', null, null, $requestId);
                http_response_code(401);
                exit(json_encode(['status' => 'error', 'message' => 'Invalid device token']));
            }

            // Forzar el school_id real del dispositivo (ignorar el del payload)
            $instId = (string)$realSchoolId;
            $stmtConfig = $conn->prepare("SELECT set_config('app.current_school_id', ?, true), set_config('app.current_role', 'EDGE_NODE', true)");
            $stmtConfig->execute([$instId]);

            // FIX: Ampliar ventana a 7 días para permitir modo offline-first (fines de semana)
            $capturedAt = isset($data['captured_at']) ? (int)$data['captured_at'] : 0;
            if (abs(time() - $capturedAt) > 604800) {  // 604800 segundos = 7 días
                securityLog('EDGE_REPLAY_ATTACK_OR_SYNC_DELAY', 'Paquete demasiado viejo/futuro', null, null, $requestId);
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Timestamp invalid']));
            }

            // FIX: El nonce también debe expirar en 7 días para tolerar modo offline
            $nonce = $data['nonce'] ?? '';
            if (!empty($nonce)) {
                try {
                    $redis = getRedisConnection();
                    if ($redis && !$redis->set($nonce, '1', ['nx', 'ex' => 604800])) {
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
                $redisIngest = getRedisConnection();
                if (!$redisIngest) {
                    http_response_code(503);
                    exit(json_encode(['status' => 'error', 'message' => 'Redis unavailable for ingestion']));
                }

                $queuePayload = json_encode([
                    'action' => $action,
                    'data' => $data,
                    'school_id' => (string)$realSchoolId,
                    'device_id' => (string)$row['device_id'],
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
        $redisHealth = getRedisConnection();
        if (!$redisHealth) {
            $checks['redis'] = ['status' => 'unhealthy', 'message' => 'Redis connection failed'];
            $allHealthy = false;
        }

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

        // Biometric worker
        $bioHeartbeat = (int)$redisHealth->get('worker:biometric:last_heartbeat');
        $bioAge = time() - $bioHeartbeat;
        $checks['biometric_worker'] = [
            'last_heartbeat' => $bioHeartbeat,
            'seconds_ago' => $bioAge,
            'healthy' => $bioAge <= 300
        ];
        if ($bioAge > 300) $allHealthy = false;

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
