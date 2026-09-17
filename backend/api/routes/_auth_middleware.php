<?php
/**
 * =============================================================================
 * _auth_middleware.php — Middleware central de autenticación, autorización (RBAC)
 *                         y utilidades criptográficas compartidas.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Provee a todos los endpoints de backend/api/routes un conjunto uniforme de
 * funciones para:
 *   1. Normalizar roles legacy a un catálogo canónico (única fuente de verdad).
 *   2. Emitir y verificar tokens JWT (RS256 preferido, HS256 fallback).
 *   3. Conectar y reutilizar una instancia singleton de Redis.
 *   4. Gestionar la revocación de JWT y el "modo pánico" por escuela.
 *   5. Extraer tokens Bearer de cabeceras, cabeceras Apache/redirect o cookies.
 *   6. Validar que un usuario autenticado tenga el rol requerido (requireAuth).
 *   7. Monitorizar la salud crítica de workers, colas, base de datos y eventos
 *      de seguridad (checkCriticalAlerts).
 *
 * USO DE REDIS AQUÍ
 * -----------------
 * Redis se usa exclusivamente para:
 *   - Verificar si un JTI está revocado (jwt:blocklist:<jti>).
 *   - Verificar si una escuela activó modo pánico después de emitir el token
 *     (panic:school:<school_id>).
 * Ambas validaciones se resuelen en un único comando MGET (checkJwtAndPanicState)
 * para reducir el tráfico a Upstash. Revocación y pánico mantienen fallback a
 * PostgreSQL si Redis no está disponible.
 *
 * Este archivo NO debe contener lógica de negocio; únicamente helpers de
 * seguridad y autenticación reutilizables.
 *
 * FLUJO GENERAL
 * -------------
 *   Request HTTP
 *        │
 *        ▼
 *   extractBearerToken() ──► verifyJwtToken() ──► isJwtRevoked() / isSchoolInPanicMode()
 *        │
 *        ▼
 *   requireAuth(['RECTOR','COORDINATOR'])
 *        │
 *        ▼
 *   Carga usuario + permisos desde PostgreSQL
 *        │
 *        ▼
 *   Retorna array con id, role, school_id, permissions, claims
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - $conn / $pdo : conexión PDO a PostgreSQL (definida en api.php / db.php).
 *   - Redis        : extensión php-redis; conecta a REDISHOST/REDISPORT/REDIS_PASSWORD.
 *   - openssl      : para firma/verificación RS256.
 *   - Variables de entorno: JWT_PRIVATE_KEY, JWT_PUBLIC_KEY, JWT_SECRET, JWT_KEY_ID,
 *     JWT_ISSUER, JWT_AUDIENCE, REDISHOST, REDISPORT, REDIS_PASSWORD.
 *
 * Es utilizado por:
 *   - Prácticamente todos los archivos en backend/api/routes/*.php mediante
 *     `require_once __DIR__ . '/_auth_middleware.php'`.
 *   - workers/worker_twilio.php y workers/worker_biometric.php que usan helpers
 *     como getRedisConnection() y set_config de PostgreSQL.
 *
 * POSIBLES EXCEPCIONES
 * --------------------
 *   - Exception : si falla la firma/verificación JWT o faltan claves.
 *   - PDOException : errores de conexión/consulta a PostgreSQL.
 *   - RedisException : errores de conexión a Redis (normalmente silenciados).
 */

global $conn;

require_once __DIR__ . '/../core/redis.php';

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
     * Normaliza nombres de rol legacy al catálogo canónico ROLES.
     *
     * @param mixed $rawRole Valor crudo del rol (p. ej. 'PRINCIPAL', 'PSYCHOLOGIST').
     * @return string Rol canónico en inglés o el valor original en mayúsculas.
     *
     * Efectos secundarios: ninguno.
     * Precondiciones: $rawRole debe ser convertible a string.
     * Postcondiciones: retorna siempre un string en mayúsculas.
     * Nota: 'PRINCIPAL' → 'RECTOR' y 'PSYCHOLOGIST' → 'COUNSELOR' son legados
     *       de versiones anteriores del modelo de roles.
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
    /**
     * Codifica datos binarios a Base64URL según RFC 4648 (sin padding, sin +/).
     *
     * @param string $data Datos a codificar.
     * @return string Cadena Base64URL.
     */
    function b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('b64url_decode')) {
    /**
     * Decodifica una cadena Base64URL a su representación binaria original.
     *
     * @param string $data Cadena Base64URL.
     * @return string|false Datos decodificados o false si son inválidos.
     */
    function b64url_decode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}

if (!function_exists('loadPemFromEnv')) {
    /**
     * Carga una clave PEM (o HMAC) desde una variable de entorno.
     *
     * Soporta tres formatos:
     *   1. Base64 de un PEM que contenga "BEGIN".
     *   2. PEM plano con \n escaped.
     *   3. Secreto HMAC plano.
     *
     * @param string $keyName Nombre de la variable de entorno.
     * @return string Clave lista para usar con openssl o hash_hmac.
     *
     * Efectos secundarios: ninguno.
     * Precondiciones: la variable de entorno debe existir o retornará ''.
     * Postcondiciones: retorna string; nunca null.
     */
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
    /**
     * Emite un token JWT firmado para un usuario autenticado.
     *
     * @param array $claims Claims adicionales (normalmente sub, role, school_id, exp).
     * @return string Token JWT completo (header.payload.signature).
     *
     * Efectos secundarios:
     *   - Llama a random_bytes() para generar el jti.
     *   - Puede escribir un error_log si la clave RSA no es válida.
     *   - En PHP < 8.0 libera la clave RSA con openssl_free_key.
     *
     * Precondiciones:
     *   - Debe existir JWT_PRIVATE_KEY (RS256) o JWT_SECRET (HS256).
     * Postcondiciones:
     *   - Retorna un JWT firmado listo para entregar al cliente.
     *
     * Posibles excepciones:
     *   - Exception: fallo al firmar con RS256 o clave HMAC ausente.
     *
     * Nota de seguridad: si existe JWT_PRIVATE_KEY válida se usa RS256; de lo
     * contrario se revierte a HS256 usando JWT_SECRET. El campo `nbf` se retrasa
     * 2 segundos para evitar rechazos por desfase de reloj.
     */
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
    /**
     * Verifica la firma, la estructura y la vigencia de un token JWT.
     *
     * @param string $token JWT recibido del cliente.
     * @return array Payload decodificado del JWT.
     *
     * Efectos secundarios:
     *   - Puede consultar Redis para verificar si el JTI está revocado.
     *   - Puede consultar Redis/DB para verificar modo pánico de la escuela.
     *   - En PHP < 8.0 libera la clave pública RSA con openssl_free_key.
     *
     * Precondiciones:
     *   - El token debe tener tres segmentos Base64URL separados por puntos.
     *   - Debe existir la clave pública (RS256) o secreto (HS256) correspondiente.
     * Postcondiciones:
     *   - Retorna el payload decodificado si todas las validaciones pasan.
     *
     * Posibles excepciones:
     *   - Exception: formato inválido, firma errónea, token expirado,
     *     issuer/audience incorrectos, JTI revocado o modo pánico activo.
     */
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
        $schoolId = $payload['school_id'] ?? null;
        $tokenIat = isset($payload['iat']) ? (int)$payload['iat'] : null;
        $state = checkJwtAndPanicState($payload['jti'], $schoolId, $tokenIat);
        if ($state['revoked']) {
            throw new Exception('Token revocado');
        }
        if ($state['panic']) {
            throw new Exception('Sesión invalidada por modo de emergencia');
        }

        return $payload;
    }
}


if (!function_exists('isJwtRevoked')) {
    /**
     * Indica si un JTI (JWT ID) ha sido revocado.
     *
     * @param string $jti Identificador único del JWT.
     * @return bool True si el token está en la blocklist.
     *
     * Efectos secundarios: consulta Redis y, como fallback, PostgreSQL.
     * Precondiciones: $jti debe ser string convertible.
     * Postcondiciones: retorna booleano. En fallo de servicios retorna false
     *                  (fail-open para no bloquear peticiones).
     */
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
    /**
     * Invalida un JWT agregando su JTI a la blocklist.
     *
     * @param string $jti Identificador del JWT a revocar.
     * @param int $exp Timestamp de expiración del token.
     * @return void
     *
     * Efectos secundarios:
     *   - Inserta/actualiza jwt_blocklist en PostgreSQL.
     *   - Crea clave expirable en Redis "jwt:blocklist:<jti>".
     * Precondiciones: $exp debe ser timestamp futuro para TTL correcto.
     * Postcondiciones: el token queda inválido en subsiguientes verificaciones.
     */
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
    /**
     * Determina si una escuela activó el modo pánico posterior a la emisión del token.
     *
     * @param string $schoolId UUID de la escuela.
     * @param int $tokenIat Timestamp 'iat' del JWT.
     * @return bool True si el modo pánico invalida la sesión.
     *
     * Efectos secundarios: consulta Redis y, como fallback, PostgreSQL.
     * Precondiciones: token debe contener school_id e iat.
     * Postcondiciones: retorna true si existe panic event con triggered_at > tokenIat.
     */
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

if (!function_exists('checkJwtAndPanicState')) {
    /**
     * Verifica en una sola ronda Redis si el JWT está revocado y si la escuela
     * activó modo pánico posterior a la emisión del token (MGET).
     *
     * @param string $jti Identificador único del JWT.
     * @param string|null $schoolId UUID de la escuela.
     * @param int|null $tokenIat Timestamp 'iat' del JWT.
     * @return array{revoked: bool, panic: bool}
     */
    function checkJwtAndPanicState($jti, $schoolId = null, $tokenIat = null) {
        $redis = getRedisConnection();
        if (!$redis) {
            return ['revoked' => false, 'panic' => false];
        }
        $keys = ["jwt:blocklist:" . (string)$jti];
        if ($schoolId !== null) {
            $keys[] = "panic:school:" . (string)$schoolId;
        }
        try {
            $values = $redis->mGet($keys);
            $revoked = !empty($values[0]);
            $panic = false;
            if ($schoolId !== null && $tokenIat !== null && isset($values[1]) && $values[1] !== false) {
                $panic = (int)$values[1] > $tokenIat;
            }
            return ['revoked' => $revoked, 'panic' => $panic];
        } catch (Exception $e) {
            securityLog('JWT_PANIC_CHECK_REDIS_ERROR', $e->getMessage());
            return ['revoked' => false, 'panic' => false];
        }
    }
}

if (!function_exists('extractBearerToken')) {
    /**
     * Extrae el token Bearer de la cabecera Authorization o de la cookie 'token'.
     *
     * @return string|null Token JWT sin el prefijo "Bearer " o null.
     *
     * Efectos secundarios: ninguno (solo lectura de superglobales).
     * Precondiciones: cliente debe enviar Authorization: Bearer <token> o cookie token.
     * Postcondiciones: retorna null si no se encuentra token.
     *
     * Nota: Soporta HTTP_AUTHORIZATION, REDIRECT_HTTP_AUTHORIZATION, getallheaders()
     *       y apache_request_headers() para compatibilidad con distintos hostings.
     */
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
    /**
     * Requiere autenticación JWT y opcionalmente un rol permitido.
     *
     * @param array|null $allowedRoles Roles permitidos (ej. ['RECTOR','COORDINATOR']).
     * @return array Datos del usuario autenticado.
     *
     * Efectos secundarios:
     *   - Establece app.current_school_id y app.current_role en PostgreSQL.
     *   - Puede finalizar la ejecución con HTTP 401/403.
     *   - Registra eventos de seguridad via securityLog().
     *
     * Precondiciones:
     *   - Debe existir $conn (PDO) global.
     *   - El cliente debe enviar token válido.
     * Postcondiciones:
     *   - Retorna array con id, email, role, school_id, permissions, claims.
     *
     * Posibles excepciones:
     *   - No lanza excepciones hacia el llamante: termina con exit(json_encode(...)).
     *
     * Nota de seguridad: también valida cabecera X-Requested-With: XMLHttpRequest
     * para métodos mutantes, mitigando CSRF en peticiones cross-origin.
     */
    function requireAuth($allowedRoles = null) {
        global $conn;

        if (!$conn) {
            http_response_code(503);
            exit(json_encode(['status' => 'error', 'message' => 'Servicio temporalmente no disponible (BD).']));
        }

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

        try {
            $claims = verifyJwtToken($token);

            // FIX (PgBouncer): En transaction-pool mode, set_config(..., false)
            // no persiste entre consultas. Usamos beginTransaction() + SET LOCAL
            // (equivalente a set_config(..., true)) para que RLS funcione.
            // SET LOCAL con exec() evita problemas con EMULATE_PREPARES.
            $startedTx = false;
            if (!$conn->inTransaction()) {
                $conn->beginTransaction();
                $startedTx = true;
            }

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
                SELECT u.* FROM u
            ");
            $stmt->execute([$claims['sub']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                if ($startedTx) { try { $conn->rollBack(); } catch (Exception $ignore) {} }
                http_response_code(401);
                exit(json_encode(['status' => 'error', 'message' => 'Usuario no encontrado o inactivo']));
            }

            $roleName = strtoupper(trim($user['role_name']));
            if (is_array($allowedRoles) && !in_array($roleName, $allowedRoles, true)) {
                if ($startedTx) { try { $conn->rollBack(); } catch (Exception $ignore) {} }
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Acceso restringido']));
            }

            // set_config transaction-level: persiste durante toda la transacción.
            // Usamos exec() con set_config() en lugar de SET LOCAL porque
            // current_role es palabra reservada de PostgreSQL.
            $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$user['school_id']) . ", true)");
            $conn->exec("SELECT set_config('app.current_role', " . $conn->quote($roleName) . ", true)");

            // Fetch permissions
            $permsStmt = $conn->prepare("
                SELECT p.permission_code
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.permission_id
                WHERE rp.role_id = ?
            ");
            $permsStmt->execute([$user['role_id']]);
            $permissions = $permsStmt->fetchAll(PDO::FETCH_COLUMN);

            // Commit automático al final de la request
            if ($startedTx) {
                register_shutdown_function(function() use ($conn) {
                    try {
                        if ($conn->inTransaction()) {
                            $conn->commit();
                        }
                    } catch (Exception $e) { /* silenciar en shutdown */ }
                });
            }

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

if (!function_exists('requireSchoolOnboarding')) {
    /**
     * Gate de onboarding institucional — bloquea la operación hasta que la
     * escuela completó la configuración obligatoria (horarios + grupos).
     *
     * REGLA (según documento, §10.5): el sistema no interpreta acontecimientos
     * sin estructura configurada — sin horarios no hay "tardanza", sin grupos
     * no hay "ausencia". Operar sin onboarding produce falsos positivos.
     *
     * Comportamiento:
     *   - Onboarding completo → return sin efecto.
     *   - Roles de configuración (RECTOR, COORDINATOR, TEACHER) → 428 con
     *     payload detallado {onboarding_required, missing:[...]}.
     *   - Resto de roles → 428 con mensaje genérico.
     */
    function requireSchoolOnboarding($conn, string $schoolId, string $role): void {
        static $cache = [];
        if (isset($cache[$schoolId])) { $s = $cache[$schoolId]; }
        else {
            $stmt = $conn->prepare(
                "SELECT onboarding_completed, groups_onboarding_completed, risk_config_completed
                   FROM schools WHERE school_id = ?"
            );
            $stmt->execute([$schoolId]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $cache[$schoolId] = $s;
        }
        $missing = [];
        if (empty($s['onboarding_completed']))        $missing[] = 'schedule';
        if (empty($s['groups_onboarding_completed'])) $missing[] = 'groups';
        if (empty($s['risk_config_completed']))       $missing[] = 'risk_config';
        if (!$missing) return;

        http_response_code(428);
        if (in_array(strtoupper($role), ['RECTOR', 'COORDINATOR', 'TEACHER'], true)) {
            exit(json_encode([
                'status'  => 'onboarding_required',
                'message' => 'La institución no ha completado la configuración inicial.',
                'missing' => $missing,
            ]));
        }
        exit(json_encode([
            'status'  => 'error',
            'message' => 'La configuración del sistema para su institución no ha sido completada.',
        ]));
    }
}

// =============================================================================
// ALERTAS PROACTIVAS — Detectar condiciones críticas y notificar
// =============================================================================
if (!function_exists('checkCriticalAlerts')) {
    /**
     * Verifica condiciones críticas del sistema y emite alertas.
     *
     * @param PDO $conn Conexión PDO a PostgreSQL.
     * @param Redis $redis Conexión Redis activa.
     * @return array Lista de mensajes de alerta generados.
     *
     * Efectos secundarios:
     *   - Escribe logs de seguridad via securityLog() para cada alerta.
     *   - Empuja alertas a la lista 'alerts:system' en Redis (limitada a 100).
     *
     * Precondiciones: $conn y $redis deben estar disponibles.
     * Postcondiciones: retorna array de strings (puede estar vacío).
     *
     * Monitorea:
     *   1. Heartbeats de workers audit/twilio/biometric (>300s sin señal).
     *   2. Longitud de colas Redis (biometric_ingest >5000, twilio >2000, audit_logs >5000).
     *   3. Conexiones activas de PostgreSQL (>80).
     *   4. Eventos de pánico en la última hora.
     *   5. Picos de intentos fallidos de login (>50 en 5 minutos).
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

        // 6. Redis Circuit Breaker State
        if (file_exists('/tmp/redis_circuit_open') && (time() - filemtime('/tmp/redis_circuit_open')) < 60) {
            $alerts[] = "CRITICAL: Redis Circuit Breaker is OPEN";
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
