<?php
// Shared auth/RBAC helpers for all routes.
global $conn;

/**
 * UNIFICACIÓN GLOBAL DE ROLES (Fuente de Verdad Única)
 * Todos los roles se normalizan a estos identificadores constantes.
 */
if (!defined('ROLES')) {
    define('ROLES', [
        'RECTOR' => 'RECTOR',
        'COORDINATOR' => 'COORDINATOR',
        'TEACHER' => 'TEACHER',
        'SECRETARY' => 'SECRETARY',
        'SECURITY' => 'SECURITY',
        'AUXILIARY' => 'AUXILIARY',
        'COUNSELOR' => 'COUNSELOR',
        'GUARDIAN' => 'GUARDIAN'
    ]);
}

if (!function_exists('normalizeRole')) {
    /**
     * Normaliza nombres de rol legacy a canonical.
     * Mapea variantes antiguas a los roles oficiales en inglés.
     */
    function normalizeRole($rawRole) {
        $map = [
            'TEACHER' => 'TEACHER',
            'COORDINATOR' => 'COORDINATOR',
            'SECRETARY' => 'SECRETARY',
            'SECURITY' => 'SECURITY',
            'AUXILIARY' => 'AUXILIARY',
            'COUNSELOR' => 'COUNSELOR',
            'GUARDIAN' => 'GUARDIAN',
            'PRINCIPAL' => 'RECTOR',
            'PSYCHOLOGIST' => 'COUNSELOR',
        ];
        $upper = strtoupper(trim((string)$rawRole));
        return $map[$upper] ?? $upper;
    }
}



if (!function_exists('b64url_encode')) {
    function b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('b64url_decode')) {
    function b64url_decode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}

if (!function_exists('loadPemFromEnv')) {
    function loadPemFromEnv($keyName) {
        $raw = getenv($keyName) ?: '';
        if ($raw === '') {
            return '';
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && str_contains($decoded, 'BEGIN')) {
            return $decoded;
        }

        return str_replace('\n', "\n", $raw);
    }
}

if (!function_exists('issueJwtToken')) {
    function issueJwtToken($claims) {
        $privateKeyPem = loadPemFromEnv('JWT_PRIVATE_KEY');
        $hmacSecret = getenv('JWT_SECRET') ?: '';
        $kid = getenv('JWT_KEY_ID') ?: 'nexo-key-1';
        $now = time();
        $issuer = getenv('JWT_ISSUER') ?: 'nexo-api';
        $audience = getenv('JWT_AUDIENCE') ?: 'nexo-webapp';
        $tokenClaims = array_merge([
            'iss' => $issuer,
            'aud' => $audience,
            'iat' => $now,
            'nbf' => $now - 2,
            'jti' => bin2hex(random_bytes(16))
        ], $claims);

        $alg = 'HS256';
        $rsaKey = null;
        if ($privateKeyPem !== '') {
            $rsaKey = @openssl_pkey_get_private($privateKeyPem);
            if ($rsaKey) {
                $alg = 'RS256';
            } else {
                error_log('[JWT] JWT_PRIVATE_KEY set but not valid RSA PEM, falling back to HS256');
            }
        }

        $header = ['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid];
        $headerB64 = b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = b64url_encode(json_encode($tokenClaims, JSON_UNESCAPED_SLASHES));
        $signingInput = $headerB64 . '.' . $payloadB64;

        if ($alg === 'RS256') {
            $signature = '';
            $ok = openssl_sign($signingInput, $signature, $rsaKey, OPENSSL_ALGO_SHA256);
            if (PHP_VERSION_ID < 80000) @openssl_free_key($rsaKey);
            if (!$ok) {
                throw new Exception('Fallo al firmar JWT con RS256');
            }
        } else {
            if ($hmacSecret === '') {
                throw new Exception('JWT: no signing key. Configure JWT_PRIVATE_KEY (RSA PEM) or JWT_SECRET (HMAC)');
            }
            $signature = hash_hmac('sha256', $signingInput, $hmacSecret, true);
        }

        return $signingInput . '.' . b64url_encode($signature);
    }
}

if (!function_exists('verifyJwtToken')) {
    function verifyJwtToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new Exception('Formato JWT inválido');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $headerRaw = b64url_decode($headerB64);
        $payloadRaw = b64url_decode($payloadB64);
        $signature = b64url_decode($signatureB64);

        if ($headerRaw === false || $payloadRaw === false || $signature === false) {
            throw new Exception('JWT malformado');
        }

        $header = json_decode($headerRaw, true);
        $payload = json_decode($payloadRaw, true);
        if (!is_array($header) || !is_array($payload)) {
            throw new Exception('JWT inválido');
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        $alg = $header['alg'] ?? '';
        if ($alg === 'RS256') {
            $publicKeyPem = loadPemFromEnv('JWT_PUBLIC_KEY');
            if ($publicKeyPem === '') {
                throw new Exception('JWT_PUBLIC_KEY no configurada');
            }
            $publicKey = @openssl_pkey_get_public($publicKeyPem);
            if (!$publicKey) {
                throw new Exception('JWT_PUBLIC_KEY inválida');
            }
            $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
            if (PHP_VERSION_ID < 80000) @openssl_free_key($publicKey);
            if ($verified !== 1) {
                throw new Exception('Firma JWT inválida');
            }
        } elseif ($alg === 'HS256') {
            $hmacSecret = getenv('JWT_SECRET') ?: '';
            if ($hmacSecret === '') {
                throw new Exception('JWT_SECRET no configurada para verificar HS256');
            }
            $expected = hash_hmac('sha256', $signingInput, $hmacSecret, true);
            if (!hash_equals($expected, $signature)) {
                throw new Exception('Firma JWT inválida');
            }
        } else {
            throw new Exception('Algoritmo JWT no permitido: ' . $alg);
        }

        $now = time();
        $issuer = getenv('JWT_ISSUER') ?: 'nexo-api';
        $audience = getenv('JWT_AUDIENCE') ?: 'nexo-webapp';

        if (($payload['iss'] ?? '') !== $issuer || ($payload['aud'] ?? '') !== $audience) {
            throw new Exception('Issuer/Audience inválidos');
        }

        if (!isset($payload['exp']) || !is_numeric($payload['exp']) || (int)$payload['exp'] < $now) {
            throw new Exception('Token expirado');
        }
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int)$payload['nbf'] > ($now + 10)) {
            throw new Exception('Token aún no válido');
        }
        if (isset($payload['iat']) && is_numeric($payload['iat']) && (int)$payload['iat'] > ($now + 10)) {
            throw new Exception('Token emitido en el futuro');
        }

        if (!isset($payload['sub']) || !is_string($payload['sub'])) {
            throw new Exception('Token sin sujeto válido');
        }
        if (!isset($payload['jti']) || !is_string($payload['jti'])) {
            throw new Exception('Token sin jti');
        }
        if (isJwtRevoked($payload['jti'])) {
            throw new Exception('Token revocado');
        }

        if (isset($payload['school_id']) && isset($payload['iat'])) {
            if (isSchoolInPanicMode($payload['school_id'], (int)$payload['iat'])) {
                throw new Exception('Sesión invalidada por modo de emergencia');
            }
        }

        return $payload;
    }
}

if (!function_exists('getRedisConnection')) {
    function getRedisConnection() {
        static $redis = null;
        static $attempted = false;

        if ($redis !== null) {
            return $redis;
        }
        if ($attempted) {
            return null;
        }
        $attempted = true;

        try {
            if (!class_exists('Redis')) return null;
            $redis = new Redis();
            $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379, 0.1);
            if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
            return $redis;
        } catch (Exception $e) {
            $redis = null;
            return null;
        }
    }
}

if (!function_exists('isJwtRevoked')) {
    function isJwtRevoked($jti) {
        $redis = getRedisConnection();
        if ($redis) {
            try {
                return $redis->exists("jwt:blocklist:" . (string)$jti);
            } catch (Exception $e) {
                securityLog('JWT_BLOCKLIST_REDIS_ERROR', $e->getMessage());
            }
        }

        global $conn, $pdo;
        $db = $conn ?? $pdo ?? null;
        if (!$db) {
            securityLog('JWT_BLOCKLIST_DB_UNAVAILABLE', 'Cannot verify JTI ' . substr((string)$jti, 0, 8));
            return false;
        }
        try {
            $stmt = $db->prepare("SELECT 1 FROM jwt_blocklist WHERE jti = ? LIMIT 1");
            $stmt->execute([(string)$jti]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            securityLog('JWT_BLOCKLIST_DB_ERROR', $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('revokeJwt')) {
    function revokeJwt($jti, $exp) {
        $jtiStr = (string)$jti;

        $redis = getRedisConnection();
        if ($redis) {
            try {
                $ttl = max(1, (int)$exp - time());
                $redis->setex("jwt:blocklist:" . $jtiStr, $ttl, '1');
            } catch (Exception $e) {
                securityLog('JWT_REVOKE_REDIS_ERROR', $e->getMessage());
            }
        }

        global $conn, $pdo;
        $db = $conn ?? $pdo ?? null;
        if (!$db) {
            securityLog('JWT_REVOKE_DB_UNAVAILABLE', 'Cannot persist revocation of JTI ' . substr($jtiStr, 0, 8));
            return;
        }
        try {
            $stmt = $db->prepare("
                INSERT INTO jwt_blocklist (jti, revoked_at, expires_at)
                VALUES (?, NOW(), to_timestamp(?))
                ON CONFLICT (jti) DO UPDATE SET revoked_at = EXCLUDED.revoked_at
            ");
            $stmt->execute([$jtiStr, (int)$exp]);
        } catch (Throwable $e) {
            securityLog('JWT_REVOKE_DB_ERROR', $e->getMessage());
        }
    }
}

if (!function_exists('isSchoolInPanicMode')) {
    function isSchoolInPanicMode($schoolId, $tokenIat) {
        $redis = getRedisConnection();
        if ($redis) {
            try {
                $panicKey = "panic:school:" . (string)$schoolId;
                $panicTimestamp = $redis->get($panicKey);
                if ($panicTimestamp && (int)$panicTimestamp > $tokenIat) {
                    return true;
                }
            } catch (Exception $e) {
                securityLog('PANIC_CHECK_REDIS_ERROR', $e->getMessage());
            }
        }

        global $conn, $pdo;
        $db = $conn ?? $pdo ?? null;
        if (!$db) {
            return false;
        }
        try {
            $stmt = $db->prepare("
                SELECT 1 FROM school_panic_events
                WHERE school_id = ? AND triggered_at > to_timestamp(?)
                LIMIT 1
            ");
            $stmt->execute([(string)$schoolId, $tokenIat]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            securityLog('PANIC_CHECK_DB_ERROR', $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('extractBearerToken')) {
    function extractBearerToken() {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader === '') {
            if (function_exists('getallheaders')) {
                $headers = getallheaders();
                $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            } elseif (function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            }
        }
        if (preg_match('/Bearer\s+([A-Za-z0-9\-\._]+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        if (isset($_COOKIE['token']) && !empty($_COOKIE['token'])) {
            return $_COOKIE['token'];
        }

        return null;
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth($allowedRoles = null) {
        global $conn;

        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Request forbidden']));
            }
        }

        $token = extractBearerToken();
        if (!$token) {
            securityLog('AUTH_HEADER_MISSING', 'Authorization Bearer no recibido');
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Token no proporcionado']));
        }
        securityLog('AUTH_HEADER_PRESENT', 'Authorization Bearer recibido');

        try {
            $claims = verifyJwtToken($token);

            $stmt = $conn->prepare("
                WITH u AS (
                    SELECT u.user_id, u.email, u.first_name, u.last_name, (u.deleted_at IS NULL) AS active,
                           u.profile_photo_url, u.work_shift,
                           u.role_id, r.role_name, s.school_id, s.school_name
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.role_id
                    INNER JOIN schools s ON u.school_id = s.school_id
                    WHERE u.user_id = ? AND u.deleted_at IS NULL
                    LIMIT 1
                )
                SELECT u.*, set_config('app.current_school_id', u.school_id::text, true) AS _cfg1
                FROM u
            ");
            $stmt->execute([$claims['sub']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                http_response_code(401);
                exit(json_encode(['status' => 'error', 'message' => 'Usuario no encontrado o inactivo']));
            }

            $roleName = strtoupper(trim($user['role_name']));
            if (is_array($allowedRoles) && !in_array($roleName, $allowedRoles, true)) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Acceso restringido']));
            }

            // Fetch permissions
            $permsStmt = $conn->prepare("
                SELECT p.permission_code 
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.permission_id
                WHERE rp.role_id = ?
            ");
            $permsStmt->execute([$user['role_id']]);
            $permissions = $permsStmt->fetchAll(PDO::FETCH_COLUMN);

            $stmtConfig = $conn->prepare("SELECT set_config('app.current_role', ?, true)");
            $stmtConfig->execute([$roleName]);

            return [
                'id' => $user['user_id'],
                'email' => $user['email'],
                'nombre' => trim($user['first_name'] . ' ' . $user['last_name']),
                'role' => $roleName,
                'role_id' => $user['role_id'],
                'school_id' => $user['school_id'],
                'school_name' => $user['school_name'],
                'profile_photo_url' => $user['profile_photo_url'] ?? null,
                'work_shift' => $user['work_shift'] ?? null,
                'claims' => $claims,
                'permissions' => $permissions
            ];
        } catch (Exception $e) {
            securityLog('AUTH_REQUIRED_FAILED', $e->getMessage());
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Sesión inválida o expirada']));
        }
    }
}

// =============================================================================
// ALERTAS PROACTIVAS — Detectar condiciones críticas y notificar
// =============================================================================
if (!function_exists('checkCriticalAlerts')) {
    /**
     * Verifica condiciones críticas del sistema y emite alertas.
     * Llamar periódicamente (ej: cada minuto vía cron o worker).
     */
    function checkCriticalAlerts($conn, $redis) {
        $alerts = [];

        // 1. Worker down
        $workers = [
            'audit' => ['key' => 'worker:audit:last_heartbeat', 'max_age' => 300],
            'twilio' => ['key' => 'worker:twilio:last_heartbeat', 'max_age' => 300],
            'biometric' => ['key' => 'worker:biometric:last_heartbeat', 'max_age' => 300],
        ];
        foreach ($workers as $name => $cfg) {
            $hb = (int)$redis->get($cfg['key']);
            if ($hb === 0 || (time() - $hb) > $cfg['max_age']) {
                $alerts[] = "CRITICAL: Worker '$name' heartbeat missing (>{$cfg['max_age']}s)";
            }
        }

        // 2. Queue overflow
        $queues = [
            'queue:biometric_ingest' => 5000,
            'queue:twilio' => 2000,
            'queue:audit_logs' => 5000,
        ];
        foreach ($queues as $queue => $threshold) {
            $len = (int)$redis->lLen($queue);
            if ($len > $threshold) {
                $alerts[] = "WARNING: Queue '$queue' overflow: $len > $threshold";
            }
        }

        // 3. DB connection saturation
        try {
            $activeConns = $conn->query("SELECT count(*) FROM pg_stat_activity WHERE state = 'active'")->fetchColumn();
            if ($activeConns > 80) {
                $alerts[] = "WARNING: DB active connections high: $activeConns";
            }
        } catch (Exception $e) {
            $alerts[] = "CRITICAL: DB health check failed: " . $e->getMessage();
        }

        // 4. Recent panic events
        try {
            $panicCount = $conn->query("SELECT COUNT(*) FROM school_panic_events WHERE triggered_at >= NOW() - INTERVAL '1 hour'")->fetchColumn();
            if ($panicCount > 0) {
                $alerts[] = "CRITICAL: $panicCount panic event(s) in last hour";
            }
        } catch (Exception $e) {
            // ignore
        }

        // 5. Failed logins spike
        try {
            $failedLogins = $conn->query("SELECT COUNT(*) FROM rate_limits WHERE rl_key LIKE 'login:%' AND window_start >= NOW() - INTERVAL '5 minutes'")->fetchColumn();
            if ($failedLogins > 50) {
                $alerts[] = "WARNING: Failed login spike: $failedLogins in 5min";
            }
        } catch (Exception $e) {
            // ignore
        }

        // Log alerts
        foreach ($alerts as $alert) {
            securityLog('SYSTEM_ALERT', $alert);
            // Also push to Redis for real-time monitoring
            try {
                $redis->lPush('alerts:system', json_encode(['msg' => $alert, 'ts' => time()]));
                $redis->lTrim('alerts:system', 0, 99);
            } catch (Exception $e) {
                // Redis down, already logged via securityLog fallback
            }
        }

        return $alerts;
    }
}
