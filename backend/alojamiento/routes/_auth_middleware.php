<?php
// Shared auth/RBAC helpers for all routes.
global $conn;

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
        $kid = getenv('JWT_KEY_ID') ?: 'nexo-rs256-key-1';
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid];
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

        $headerB64 = b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = b64url_encode(json_encode($tokenClaims, JSON_UNESCAPED_SLASHES));
        $signingInput = $headerB64 . '.' . $payloadB64;

        if ($privateKeyPem === '') {
            throw new Exception('JWT_PRIVATE_KEY no configurada');
        }
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            throw new Exception('JWT_PRIVATE_KEY inválida');
        }
        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKey);
        if (!$ok) {
            throw new Exception('Fallo al firmar JWT con RS256');
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
            $publicKey = openssl_pkey_get_public($publicKeyPem);
            if (!$publicKey) {
                throw new Exception('JWT_PUBLIC_KEY inválida');
            }
            $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
            openssl_free_key($publicKey);
            if ($verified !== 1) {
                throw new Exception('Firma JWT inválida');
            }
        } else {
            throw new Exception('Algoritmo JWT no permitido');
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

        return $payload;
    }
}

if (!function_exists('getRedisConnection')) {
    function getRedisConnection() {
        try {
            if (!class_exists('Redis')) return null;
            $redis = new Redis();
            $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
            if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
            return $redis;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('isJwtRevoked')) {
    function isJwtRevoked($jti) {
        // FIX: Intentar Redis primero para evitar lecturas masivas a PostgreSQL
        $redis = getRedisConnection();
        if ($redis) {
            try {
                return $redis->exists("jwt:blocklist:" . (string)$jti);
            } catch (Exception $e) {
                securityLog('JWT_BLOCKLIST_REDIS_ERROR', $e->getMessage());
            }
        }

        // Fallback a PostgreSQL
        global $conn, $pdo;
        $db = $conn ?? $pdo ?? null;
        if (!$db) {
            securityLog('JWT_BLOCKLIST_DB_UNAVAILABLE', 'Cannot verify JTI ' . substr((string)$jti, 0, 8));
            return false;
        }
        try {
            // FIX: Limpieza probabilística (1%) para evitar ataque DoS sobre el WAL
            if (random_int(1, 100) === 1) {
                $db->exec("DELETE FROM jwt_blocklist WHERE expires_at < NOW()");
            }
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

        // FIX: Guardar en Redis con TTL para consultas rápidas
        $redis = getRedisConnection();
        if ($redis) {
            try {
                $ttl = max(1, (int)$exp - time());
                $redis->setex("jwt:blocklist:" . $jtiStr, $ttl, '1');
            } catch (Exception $e) {
                securityLog('JWT_REVOKE_REDIS_ERROR', $e->getMessage());
            }
        }

        // También persistir en PostgreSQL como respaldo permanente
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

if (!function_exists('extractBearerToken')) {
    function extractBearerToken() {
        // 1. Intentar desde header Authorization (compatibilidad con clientes antiguos)
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

        // 2. Si no hay header, intentar desde cookie (nuevo flujo con HttpOnly)
        if (isset($_COOKIE['token']) && !empty($_COOKIE['token'])) {
            return $_COOKIE['token'];
        }

        return null;
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth($allowedRoles = null) {
        global $conn;

        // FIX: CSRF mitigación — exigir X-Requested-With en peticiones que modifican estado
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
                SELECT u.user_id, u.email, u.first_name, u.last_name, u.active,
                       r.role_name, s.school_id, s.school_name
                FROM users u
                INNER JOIN roles r ON u.role_id = r.role_id
                INNER JOIN schools s ON u.school_id = s.school_id
                WHERE u.user_id = ? AND u.active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$claims['sub']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                http_response_code(401);
                exit(json_encode(['status' => 'error', 'message' => 'Usuario no encontrado o inactivo']));
            }

            $normalizedRole = normalizeRole($user['role_name']);
            if (is_array($allowedRoles) && !in_array($normalizedRole, $allowedRoles, true)) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Acceso restringido']));
            }

            // FIX: Configurar el contexto de PostgreSQL para Row-Level Security (RLS) usando set_config
            $stmtConfig = $conn->prepare("SELECT set_config('app.current_school_id', ?, false), set_config('app.current_role', ?, false)");
            $stmtConfig->execute([(string)$user['school_id'], $normalizedRole]);

            return [
                'id' => $user['user_id'],
                'email' => $user['email'],
                'nombre' => trim($user['first_name'] . ' ' . $user['last_name']),
                'role' => $normalizedRole,
                'school_id' => $user['school_id'],
                'school_name' => $user['school_name'],
                'claims' => $claims
            ];
        } catch (Exception $e) {
            securityLog('AUTH_REQUIRED_FAILED', $e->getMessage());
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Sesión inválida o expirada']));
        }
    }
}
?>
