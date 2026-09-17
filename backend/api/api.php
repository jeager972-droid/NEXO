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
 * - Instaurar rate limiting vía Redis solo en métodos mutantes (POST/PUT/DELETE/PATCH).
 * - Enrutar peticiones a los archivos correspondientes en routes/.
 * - Recibir y validar payloads cifrados de dispositivos EDGE (AES-256-GCM).
 * - Proveer endpoint /health para verificar BD, Redis, workers y disco.
 *
 * USO DE REDIS EN NEXO
 * --------------------
 * Redis/Upstash actúa como broker de colas y caché de corta duración; NO es un
 * paso obligatorio de cada petición. Se utiliza exclusivamente para:
 *   - Colas de workers: queue:biometric_ingest, queue:twilio, device:{id}:commands.
 *   - Cache de seguridad: jwt:blocklist:<jti>, panic:school:<id>, nonces EDGE.
 *   - Rate limiting de operaciones de escritura (POST/PUT/DELETE/PATCH).
 *   - Caché de respuestas costosas (dashboard stats).
 * Los logs de auditoría ya no circulan por Redis de forma rutinaria; se escriben
 * a stderr. Para reactivar la cola de auditoría: AUDIT_WORKER_ENABLED=1.
 *
 * FLUJO GENERAL
 * -------------
 *   security headers → boot_check → db.php → rate limit (mutantes) → parse URI/body
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
 *   - Toda petición HTTP al backend (Render/Docker).
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

require_once __DIR__ . '/core/boot_check.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/lib/notify_routing.php';
require_once __DIR__ . '/lib/attendance_reconcile.php';
require_once __DIR__ . '/routes/_auth_middleware.php';

$conn = $pdo;


/**
 * Registra un evento de seguridad en stderr. Solo encola en Redis cuando
 * AUDIT_WORKER_ENABLED=1, evitando un LPUSH por evento en el uso por defecto.
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

    // Redis audit_logs solo si el worker de auditoría está activo.
    if (getenv('AUDIT_WORKER_ENABLED') !== '1') {
        return;
    }

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
 * Aplica rate limiting con Redis solo para métodos mutantes (POST/PUT/DELETE/PATCH).
 * Las lecturas (GET/HEAD/OPTIONS) no generan comandos Redis aquí, reduciendo el
 * costo del polling y navegación sin afectar la protección contra abuso de escritura.
 *
 * @param string|null $userId UUID del usuario autenticado (opcional).
 * @param int $maxReqs Máximo de peticiones en la ventana.
 * @param int $window Ventana en segundos.
 * @return void
 */
function enforceRateLimitRedis($userId = null, $maxReqs = 100, $window = 60) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

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
// RATE_LIMIT_MAX / RATE_LIMIT_WINDOW permiten ajustar la protección por
// entorno (test, despliegue con múltiples nodos detrás de NAT, etc.).
enforceRateLimitRedis(null,
    (int)(getenv('RATE_LIMIT_MAX') ?: 100),
    (int)(getenv('RATE_LIMIT_WINDOW') ?: 60));

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

// ============================================================================
// Rate limiting específico para endpoints sensibles.
// ============================================================================
$sensitiveRateLimits = [
    '/auth/verify-2fa'         => ['max' => 10, 'window' => 300,  'scope' => 'ip'],
    '/users/send-verification' => ['max' => 5,  'window' => 600,  'scope' => 'user'],
    '/contacto'                => ['max' => 3,  'window' => 3600, 'scope' => 'ip'],
];
if (isset($sensitiveRateLimits[$cleanPath])) {
    $rlConfig = $sensitiveRateLimits[$cleanPath];
    try {
        $redis = getRedisConnection();
        if ($redis) {
            $ip = getRealClientIp();
            $rlKey = "rl:sensitive:" . md5($cleanPath) . ":";
            if ($rlConfig['scope'] === 'user') {
                $bearer = extractBearerToken();
                $userId = null;
                if ($bearer) {
                    try {
                        $claims = verifyJwtToken($bearer);
                        $userId = $claims['sub'] ?? null;
                    } catch (Exception $e) {}
                }
                $rlKey .= $userId ? "u:{$userId}" : "ip:" . md5($ip);
            } else {
                $rlKey .= "ip:" . md5($ip);
            }
            $hits = $redis->incr($rlKey);
            if ($hits === 1) $redis->expire($rlKey, $rlConfig['window']);
            if ($hits > $rlConfig['max']) {
                http_response_code(429);
                securityLog('RATE_LIMIT_EXCEEDED', "Sensitive endpoint: $cleanPath Hits: $hits IP: $ip");
                exit(json_encode(['status' => 'error', 'message' => 'Too many requests']));
            }
        }
    } catch (Exception $e) {
        securityLog('RATE_LIMIT_REDIS_DOWN', 'Sensitive endpoint Redis unavailable, allowing request');
    }
}

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
    'audit' => 'audit_full.php',
    'security' => 'security_panic.php',
    'behavior' => 'behavior.php',
    'risk' => 'risk.php',
    'admin' => 'admin.php',
    'metrics' => 'metrics.php',
    'telemetry' => 'telemetry.php',
    'users' => 'users.php',
    'consultation' => ['consultations.php', 'misc.php'],
    'consultations' => 'consultations.php',
    'tracking' => 'tracking.php',
    'school' => 'school_config.php',
    'teacher' => 'teacher_alerts.php',
    'events' => 'events.php',
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
                    // Si $redis es null, fail-open: aceptar el nonce sin validar
                    // (Redis caído — ver tener_en_cuenta.md). El timestamp check
                    // ya protege contra replay de eventos viejos.
                } catch (Exception $e) {
                    // FAIL-OPEN: Redis caído no debe bloquear el ingest del edge.
                    // Loggear pero continuar procesando.
                    securityLog('EDGE_REPLAY_NONCE_REDIS_DOWN', 'Nonce validation skipped (Redis down, fail-open): ' . $e->getMessage(), null, null, $requestId);
                }
            }

            $action = $data['action'] ?? 'UNKNOWN';

            // ====================================================================
            // FAST PATH: REGISTER_STUDENT con has_fingerprint — procesar directo en
            // PostgreSQL sin pasar por Redis/worker. Esto permite que el polling
            // de la WebApp vea has_fingerprint=true inmediatamente después del
            // enrolamiento, incluso si Redis está caído.
            // ====================================================================
            if ($action === 'REGISTER_STUDENT' && !empty($data['has_fingerprint'])) {
                try {
                    $pgSchoolId = (string)$realSchoolId;
                    $pgDoc = trim($data['doc'] ?? '');
                    $pgNombre = trim($data['nombre'] ?? '');
                    $pgHuellaId = isset($data['huella_id']) ? (int)$data['huella_id'] : null;
                    if (empty($pgDoc) || empty($pgNombre)) {
                        http_response_code(400);
                        exit(json_encode(['status' => 'error', 'message' => 'doc and nombre required for REGISTER_STUDENT']));
                    }

                    $conn->exec("BEGIN");
                    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($pgSchoolId) . ", true)");
                    $conn->exec("SELECT set_config('app.current_role', 'EDGE_NODE', true)");

                    // Upsert student y marcar biometric_hash con el huella_id del edge
                    $biometricHash = $pgHuellaId !== null ? 'fp_' . $pgHuellaId : 'fp_local';
                    $upStmt = $conn->prepare("
                        INSERT INTO students (school_id, document_number, first_name, last_name, active, biometric_hash)
                        VALUES (?, ?, ?, '', TRUE, ?)
                        ON CONFLICT (school_id, document_number)
                        DO UPDATE SET first_name = EXCLUDED.first_name, active = TRUE, biometric_hash = EXCLUDED.biometric_hash
                        RETURNING student_id
                    ");
                    $upStmt->execute([$pgSchoolId, $pgDoc, $pgNombre, $biometricHash]);
                    $conn->exec("COMMIT");

                    securityLog('EDGE_ENROLL_DIRECT', "doc=$pgDoc huella_id=$pgHuellaId school=$pgSchoolId", null, $pgSchoolId, $requestId);
                    http_response_code(200);
                    exit(json_encode(['status' => 'ok', 'action' => 'REGISTER_STUDENT', 'student_doc' => $pgDoc, 'has_fingerprint' => true, 'request_id' => $requestId]));
                } catch (Exception $directEx) {
                    try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                    securityLog('EDGE_ENROLL_DIRECT_FAIL', $directEx->getMessage(), null, $realSchoolId, $requestId);
                    // Caer al flujo normal de Redis como fallback
                }
            }

            try {
                $redisIngest = getRedisConnection();
                if (!$redisIngest) {
                    // FALLBACK: Redis caído — procesar SYNC_ATTENDANCE directo en PostgreSQL
                    // (ver tener_en_cuenta.md — el sistema debe funcionar sin Redis)
                    if ($action === 'SYNC_ATTENDANCE') {
                        try {
                            $conn->exec("BEGIN");
                            $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$realSchoolId) . ", true)");
                            $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

                            $attDoc = trim($data['doc'] ?? '');
                            $attEvt = strtoupper($data['event'] ?? '');
                            $attTs  = $data['captured_at'] ?? time();

                            if ($attDoc && $attEvt) {
                                $fingerprint = hash('sha256', implode(':', [
                                    (string)$realSchoolId, $attDoc, $attEvt, (string)$attTs
                                ]));

                                $stmt = $conn->prepare(
                                    "INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,event_timestamp,event_fingerprint)
                                     SELECT uuid_generate_v4(),school_id,student_id,
                                            ?, ?, 'PROCESSED', to_timestamp(?), ?
                                     FROM students WHERE document_number = ? AND school_id = ?
                                     ON CONFLICT (event_fingerprint, event_timestamp) WHERE event_fingerprint IS NOT NULL DO NOTHING"
                                );
                                $stmt->execute([$row['device_id'], $attEvt, $attTs, $fingerprint, $attDoc, $realSchoolId]);
                                // V-530/531/574: reconciliar INASISTENCIA abierta si el
                                // ingreso llega tarde (también en el camino Redis-down)
                                if ($stmt->rowCount() > 0) {
                                    $sidStmt = $conn->prepare("SELECT student_id FROM students WHERE document_number = ? AND school_id = ? LIMIT 1");
                                    $sidStmt->execute([$attDoc, $realSchoolId]);
                                    nexoReconcileAbsence($conn, (string)$realSchoolId, $sidStmt->fetchColumn() ?: null, $attEvt);
                                }
                            }
                            $conn->exec("COMMIT");
                            securityLog('EDGE_ATTENDANCE_DIRECT_PG', "doc=$attDoc evt=$attEvt (Redis down, direct PG)", null, $realSchoolId, $requestId);
                            http_response_code(200);
                            exit(json_encode(['status' => 'ok', 'action' => $action, 'request_id' => $requestId, 'fallback' => 'direct_pg']));
                        } catch (Exception $pgEx) {
                            try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                            securityLog('EDGE_ATTENDANCE_DIRECT_PG_FAIL', $pgEx->getMessage(), null, $realSchoolId, $requestId);
                            http_response_code(500);
                            exit(json_encode(['status'=>'error','message'=>'Attendance processing failed (Redis down, PG fallback failed)']));
                        }
                    }

                    // Para otros actions, responder 202 aceptado sin encolar
                    // (el edge reintentará si es necesario, pero no bloquear)
                    securityLog('EDGE_INGEST_REDIS_DOWN_ACCEPT', "action=$action accepted without queue (Redis down)", null, $realSchoolId, $requestId);
                    http_response_code(202);
                    exit(json_encode(['status' => 'accepted', 'action' => $action, 'request_id' => $requestId, 'warning' => 'redis_down']));
                }

                $queuePayload = json_encode([
                    'action' => $action,
                    'data' => $data,
                    'school_id' => (string)$realSchoolId,
                    'device_id' => (string)$row['device_id'],
                    'request_id' => $requestId,
                    'received_at' => time()
                ], JSON_UNESCAPED_UNICODE);

                // FIX: SYNC_ATTENDANCE siempre se procesa directo en PG además de encolar
                // en Redis. Esto garantiza que el evento llegue a biometric_events incluso
                // si Redis está intermitente y el worker no puede procesar la cola.
                if ($action === 'SYNC_ATTENDANCE') {
                    try {
                        $conn->exec("BEGIN");
                        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$realSchoolId) . ", true)");
                        $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
                        $attDoc = trim($data['doc'] ?? '');
                        $attEvt = strtoupper($data['event'] ?? '');
                        $attTs  = $data['captured_at'] ?? time();
                        if ($attDoc && $attEvt) {
                            $fingerprint = hash('sha256', implode(':', [(string)$realSchoolId, $attDoc, $attEvt, (string)$attTs]));
                            $stmt = $conn->prepare(
                                "INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,event_timestamp,event_fingerprint)
                                 SELECT uuid_generate_v4(),school_id,student_id,
                                        ?, ?, 'PROCESSED', to_timestamp(?), ?
                                 FROM students WHERE document_number = ? AND school_id = ?
                                 ON CONFLICT (event_fingerprint, event_timestamp) WHERE event_fingerprint IS NOT NULL DO NOTHING"
                            );
                            $stmt->execute([$row['device_id'], $attEvt, $attTs, $fingerprint, $attDoc, $realSchoolId]);
                            // V-530/531/574: reconciliar INASISTENCIA abierta si el
                            // ingreso llega tarde — el ingest síncrono es el camino
                            // principal; el worker re-procesa idempotentemente.
                            if ($stmt->rowCount() > 0) {
                                $sidStmt = $conn->prepare("SELECT student_id FROM students WHERE document_number = ? AND school_id = ? LIMIT 1");
                                $sidStmt->execute([$attDoc, $realSchoolId]);
                                nexoReconcileAbsence($conn, (string)$realSchoolId, $sidStmt->fetchColumn() ?: null, $attEvt);
                            }
                        }
                        $conn->exec("COMMIT");
                    } catch (Exception $pgEx3) {
                        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                        securityLog('EDGE_ATTENDANCE_PG_INLINE_FAIL', $pgEx3->getMessage(), null, $realSchoolId, $requestId);
                    }
                }

                // También encolar en Redis si está disponible (para que el worker
                // procese LATE_ARRIVAL, notificaciones, etc.)
                try {
                    $redisIngest->rPush('queue:biometric_ingest', $queuePayload);
                    $redisIngest->expire('queue:biometric_ingest', 86400);
                    if ($action === 'SYNC_ATTENDANCE') {
                        $today = gmdate('Y-m-d');
                        $redisIngest->incr("school:{$instId}:present:{$today}");
                        $redisIngest->expire("school:{$instId}:present:{$today}", 86400);
                    }
                } catch (Exception $redisPushEx) {
                    securityLog('EDGE_REDIS_PUSH_FAIL', $redisPushEx->getMessage(), null, $realSchoolId, $requestId);
                    // No importa — SYNC_ATTENDANCE ya se procesó en PG arriba
                }

                http_response_code($action === 'SYNC_ATTENDANCE' ? 200 : 202);
                echo json_encode(['status' => $action === 'SYNC_ATTENDANCE' ? 'ok' : 'accepted', 'action' => $action, 'request_id' => $requestId]);
                exit;
            } catch (Exception $e) {
                securityLog('EDGE_INGESTION_REDIS_FAIL', $e->getMessage(), null, null, $requestId);
                // FAIL-OPEN: Redis falló en el catch. Si es SYNC_ATTENDANCE, intentar PG.
                if ($action === 'SYNC_ATTENDANCE') {
                    try {
                        $conn->exec("BEGIN");
                        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$realSchoolId) . ", true)");
                        $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
                        $attDoc = trim($data['doc'] ?? '');
                        $attEvt = strtoupper($data['event'] ?? '');
                        $attTs  = $data['captured_at'] ?? time();
                        if ($attDoc && $attEvt) {
                            $fingerprint = hash('sha256', implode(':', [(string)$realSchoolId, $attDoc, $attEvt, (string)$attTs]));
                            $stmt = $conn->prepare(
                                "INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,event_timestamp,event_fingerprint)
                                 SELECT uuid_generate_v4(),school_id,student_id,
                                        ?, ?, 'PROCESSED', to_timestamp(?), ?
                                 FROM students WHERE document_number = ? AND school_id = ?
                                 ON CONFLICT (event_fingerprint, event_timestamp) WHERE event_fingerprint IS NOT NULL DO NOTHING"
                            );
                            $stmt->execute([$row['device_id'], $attEvt, $attTs, $fingerprint, $attDoc, $realSchoolId]);
                        }
                        $conn->exec("COMMIT");
                        securityLog('EDGE_ATTENDANCE_PG_CATCH', "doc=$attDoc evt=$attEvt (Redis exception, PG fallback)", null, $realSchoolId, $requestId);
                        http_response_code(200);
                        exit(json_encode(['status' => 'ok', 'action' => $action, 'request_id' => $requestId, 'fallback' => 'pg_catch']));
                    } catch (Exception $pgEx2) {
                        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                        securityLog('EDGE_ATTENDANCE_PG_CATCH_FAIL', $pgEx2->getMessage(), null, $realSchoolId, $requestId);
                    }
                }
                http_response_code(202);
                exit(json_encode(['status'=>'accepted','action'=>$action,'request_id'=>$requestId,'warning'=>'redis_catch_fail']));
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
    // Se usa MGET para leer todos los heartbeats en un solo comando Redis.
    if (isset($checks['redis']['status']) && $checks['redis']['status'] === 'healthy') {
        $workers = [
            'twilio_worker' => 'worker:twilio:last_heartbeat',
            'biometric_worker' => 'worker:biometric:last_heartbeat',
            'absence_detector_worker' => 'worker:absence_detector:last_heartbeat',
            'evasion_detector_worker' => 'worker:evasion_detector:last_heartbeat',
            'permission_status_worker' => 'worker:permission_status:last_heartbeat',
            'device_health_worker' => 'worker:device_health:last_heartbeat',
        ];
        if (getenv('AUDIT_WORKER_ENABLED') === '1') {
            $workers['audit_worker'] = 'worker:audit:last_heartbeat';
        }
        try {
            $keys = array_values($workers);
            $values = $redisHealth->mGet($keys);
            $idx = 0;
            foreach ($workers as $name => $key) {
                $heartbeat = (int)($values[$idx] ?? 0);
                $age = time() - $heartbeat;
                $healthy = $heartbeat > 0 && $age <= 300;
                $checks[$name] = [
                    'last_heartbeat' => $heartbeat,
                    'seconds_ago' => $age,
                    'healthy' => $healthy
                ];
                if (!$healthy) $allHealthy = false;
                $idx++;
            }
        } catch (Exception $e) {
            foreach ($workers as $name => $key) {
                $checks[$name] = ['status' => 'unknown', 'error' => $e->getMessage()];
                $allHealthy = false;
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
