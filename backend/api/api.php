<?php
/**
 * =============================================================================
 * api.php — Punto de entrada único (front controller) de la API REST de NEXO.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * - Aplicar headers de seguridad y CORS.
 * - Validar variables críticas (boot_check.php) y conectar a BD (db.php).
 * - Instaurar rate limiting global vía Redis.
 * - Enrutar peticiones a los archivos correspondientes en routes/.
 * - Recibir y validar payloads cifrados de dispositivos EDGE (AES-256-GCM).
 * - Proveer endpoint /health para verificar BD, Redis, workers, colas y disco.
 *
 * FLUJO GENERAL
 * -------------
 *   security headers → boot_check → db.php → rate limit → parse URI/body
 *        │
 *        ├── Si hay payload cifrado: descifrar, validar device token/nonce,
 *        │   encolar en queue:biometric_ingest y responder 202 Accepted.
 *        │
 *        ├── Si /health: ejecutar health checks.
 *        │
 *        └── Si no: buscar $prefix en $routeMap y require_once routes/<file>.php
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - routes/_cors_middleware.php : CORS.
 *   - boot_check.php : validación de env críticos.
 *   - db.php : conexión PDO ($pdo).
 *   - routes/_auth_middleware.php : getRedisConnection, helpers JWT.
 *   - routes/operations.php : siempre incluido por compatibilidad de helpers.
 *
 * Es utilizado por:
 *   - Toda petición HTTP al backend (Railway/Docker).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

require_once __DIR__ . '/routes/_cors_middleware.php';

// Headers de seguridad HTTP básicos.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
header("Content-Security-Policy: frame-ancestors 'none';");

require_once __DIR__ . '/boot_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routes/_auth_middleware.php';

$conn = $pdo;


/**
 * Registra un evento de seguridad en stderr y en la cola Redis audit_logs.
 *
 * @param string $event Código del evento.
 * @param string $details Descripción adicional.
 * @param string|null $actorId UUID del usuario.
 * @param string|null $schoolId UUID de la escuela.
 * @param string|null $requestId ID de trazabilidad de la petición.
 * @return void
 */
function securityLog($event, $details = '', $actorId = null, $schoolId = null, $requestId = null) {
    $ip = getRealClientIp();
    $uri = $_SERVER['REQUEST_URI'] ?? 'N/A';


    $rid = $requestId ? " [REQ:$requestId]" : '';
    $fallbackMsg = sprintf("[%s] [EVENT:%s]%s [DETAILS:%s] [IP:%s]\n", gmdate('Y-m-d H:i:s'), $event, $rid, $details, $ip);
    file_put_contents('php://stderr', $fallbackMsg);


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

/**
 * Obtiene la IP real del cliente respetando proxies (Cloudflare, X-Forwarded-For).
 *
 * @return string Dirección IP validada o 0.0.0.0.
 */
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

/**
 * Aplica rate limiting global con Redis (sliding window por IP o usuario).
 *
 * @param string|null $userId UUID del usuario autenticado (opcional).
 * @param int $maxReqs Máximo de peticiones en la ventana.
 * @param int $window Ventana en segundos.
 * @return void
 */
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

        securityLog('RATE_LIMIT_REDIS_DOWN', 'Redis unavailable, allowing request without rate limit');
    }
}

// Aplicar rate limiting global antes de procesar la petición.
enforceRateLimitRedis();

header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// Parseo de URI y body. $cleanPath se normaliza para soportar /v1/ y /api.php.
// ============================================================================
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cleanPath = trim(urldecode(preg_replace('/^\/(v1|api\.php)/i', '', parse_url($uri, PHP_URL_PATH))), "/");
$cleanPath = '/' . $cleanPath;

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true) ?: [];

// operations.php se carga siempre para exponer helpers Twilio a otras rutas.
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
    'webhooks' => ['twilio_delivery.php', 'misc.php'],
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

// ============================================================================
// Routing: según el primer segmento se incluyen uno o varios archivos de routes/.
// ============================================================================
if (isset($routeMap[$prefix])) {
    $files = (array)$routeMap[$prefix];
    foreach ($files as $f) {

        if ($f !== 'operations.php') {
            require_once __DIR__ . '/routes/' . $f;
        }
    }
}

// ============================================================================
// Endpoint de ingesta EDGE (cifrado AES-256-GCM).
// El payload puede venir en cualquier path; se detecta por la clave 'payload'.
// ============================================================================
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


            $deviceToken = $data['device_token'] ?? '';
            if (empty($deviceToken)) {
                securityLog('EDGE_NO_DEVICE_TOKEN', 'Missing device token', null, null, $requestId);
                http_response_code(401);
                exit(json_encode(['status' => 'error', 'message' => 'Device token required']));
            }

            $requestDeviceId = $data['device_id'] ?? null;

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


            $instId = (string)$realSchoolId;
            $stmtConfig = $conn->prepare("SELECT set_config('app.current_school_id', ?, true), set_config('app.current_role', 'EDGE_NODE', true)");
            $stmtConfig->execute([$instId]);


            $capturedAt = isset($data['captured_at']) ? (int)$data['captured_at'] : 0;
            if (abs(time() - $capturedAt) > 604800) {
                securityLog('EDGE_REPLAY_ATTACK_OR_SYNC_DELAY', 'Paquete demasiado viejo/futuro', null, null, $requestId);
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Timestamp invalid']));
            }


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


// ============================================================================
// GET /health / /health/workers — Verificación de salud de BD, Redis,
// workers, colas y espacio en disco.
// ============================================================================
if ($cleanPath === '/health' || $cleanPath === '/health/workers') {
    header('Content-Type: application/json; charset=utf-8');
    $checks = [];
    $allHealthy = true;
    $startTime = microtime(true);

    // 1. Database health
    try {
        global $conn;
        if ($conn) {
            $conn->query("SELECT 1");
            $checks['database'] = ['status' => 'healthy', 'latency_ms' => round((microtime(true) - $startTime) * 1000, 2)];
        } else {
            $checks['database'] = ['status' => 'unhealthy', 'message' => 'Connection not available'];
            $allHealthy = false;
        }
    } catch (Exception $e) {
        $checks['database'] = ['status' => 'unhealthy', 'message' => $e->getMessage()];
        $allHealthy = false;
    }

    // 2. Redis health
    try {
        $redisHealth = getRedisConnection();
        if ($redisHealth) {
            $redisHealth->ping();
            $checks['redis'] = ['status' => 'healthy'];
        } else {
            $checks['redis'] = ['status' => 'unhealthy', 'message' => 'Connection failed'];
            $allHealthy = false;
        }
    } catch (Exception $e) {
        $checks['redis'] = ['status' => 'unhealthy', 'message' => $e->getMessage()];
        $allHealthy = false;
    }

    // 3. Worker heartbeats (only if Redis is up)
    if (isset($checks['redis']['status']) && $checks['redis']['status'] === 'healthy') {
        $workers = [
            'audit_worker' => 'worker:audit:last_heartbeat',
            'twilio_worker' => 'worker:twilio:last_heartbeat',
            'biometric_worker' => 'worker:biometric:last_heartbeat',
        ];
        foreach ($workers as $name => $key) {
            $heartbeat = (int)$redisHealth->get($key);
            $age = time() - $heartbeat;
            $healthy = $heartbeat > 0 && $age <= 300;
            $checks[$name] = [
                'last_heartbeat' => $heartbeat,
                'seconds_ago' => $age,
                'healthy' => $healthy
            ];
            if (!$healthy) $allHealthy = false;
        }

        // 4. Queue depths (alert thresholds)
        $queues = [
            'queue:biometric_ingest' => 1000,
            'queue:twilio' => 500,
            'queue:audit_logs' => 1000,
        ];
        foreach ($queues as $queue => $threshold) {
            try {
                $len = $redisHealth->lLen($queue);
                $checks['queue_' . basename($queue)] = [
                    'length' => (int)$len,
                    'threshold' => $threshold,
                    'healthy' => $len < $threshold
                ];
                if ($len >= $threshold) $allHealthy = false;
            } catch (Exception $e) {
                $checks['queue_' . basename($queue)] = ['status' => 'unknown', 'error' => $e->getMessage()];
            }
        }
    }

    // 5. Disk space (basic)
    $freeSpace = disk_free_space('.');
    $totalSpace = disk_total_space('.');
    $diskPercent = $totalSpace > 0 ? round((1 - $freeSpace / $totalSpace) * 100, 1) : 0;
    $checks['disk'] = [
        'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
        'used_percent' => $diskPercent,
        'healthy' => $diskPercent < 90
    ];
    if ($diskPercent >= 90) $allHealthy = false;

    http_response_code($allHealthy ? 200 : 503);
    echo json_encode([
        'status' => $allHealthy ? 'ok' : 'degraded',
        'timestamp' => gmdate('c'),
        'checks' => $checks
    ], JSON_PRETTY_PRINT);
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Recurso no encontrado o ruta no manejado']);
