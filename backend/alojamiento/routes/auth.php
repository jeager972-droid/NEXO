<?php
// routes/auth.php - Manejo de autenticación
global $cleanPath, $conn, $input, $method;

/**
 * @OA\Info(
 *     title="NEXO API",
 *     version="7.5.0",
 *     description="API REST para la plataforma educativa NEXO - Control de asistencia biométrica"
 * )
 * @OA\Server(url="https://nexo-production-13c0.up.railway.app", description="Production")
 * @OA\Server(url="http://localhost:8080", description="Local Development")
 */

// ROLES y normalizeRole() ahora están en _auth_middleware.php para disponibilidad global
require_once __DIR__ . '/_auth_middleware.php';

function isLoginThrottled($email) {
    global $conn;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login:' . hash('sha256', strtolower(trim($email)) . '|' . $ip);
    $window = 900;
    $maxAttempts = 8;

    if (!$conn) {
        return false; // Sin DB no podemos verificar — log en enforceRateLimit ya cubre
    }
    try {
        $sql = "
            INSERT INTO rate_limits (rl_key, window_start, hits)
            VALUES (:k, NOW(), 1)
            ON CONFLICT (rl_key) DO UPDATE SET
                window_start = CASE
                    WHEN EXTRACT(EPOCH FROM (NOW() - rate_limits.window_start)) > :w
                    THEN NOW()
                    ELSE rate_limits.window_start
                END,
                hits = CASE
                    WHEN EXTRACT(EPOCH FROM (NOW() - rate_limits.window_start)) > :w
                    THEN 1
                    ELSE rate_limits.hits + 1
                END
            RETURNING hits
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':k', $key, PDO::PARAM_STR);
        $stmt->bindValue(':w', $window, PDO::PARAM_INT);
        $stmt->execute();
        $hits = (int)$stmt->fetchColumn();
        return $hits > $maxAttempts;
    } catch (Throwable $e) {
        return false;
    }
}

function verifyUserPassword($password, $hash) {
    $password = (string)$password;
    $hash = (string)$hash;
    return password_verify($password, $hash);
}

/**
 * @OA\Post(
 *     path="/auth/login",
 *     summary="Iniciar sesión",
 *     description="Autentica un usuario y devuelve un JWT en cookie HttpOnly",
 *     tags={"Autenticación"},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"email", "password"},
 *             @OA\Property(property="email", type="string", format="email", example="rector@colegio.edu"),
 *             @OA\Property(property="password", type="string", format="password", example="SecurePass123!")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Login exitoso",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="string", example="ok"),
 *             @OA\Property(property="user", type="object",
 *                 @OA\Property(property="id", type="integer"),
 *                 @OA\Property(property="email", type="string"),
 *                 @OA\Property(property="role", type="string", enum={"RECTOR","COORDINADOR","DOCENTE","SECRETARIA","PORTERO","AUXILIAR","PSICORIENTADOR"}),
 *                 @OA\Property(property="school_id", type="integer"),
 *                 @OA\Property(property="school_name", type="string")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=400, description="Email o contraseña faltantes"),
 *     @OA\Response(response=401, description="Credenciales inválidas"),
 *     @OA\Response(response=429, description="Demasiados intentos (rate limited)")
 * )
 */
if ($cleanPath === '/auth/login' || (isset($input['action']) && $input['action'] === 'LOGIN')) {
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $input['password'] ?? '';
    error_log('[LOGIN] Input: ' . json_encode($input));
    error_log('[LOGIN] EMAIL=' . $email);
    error_log('[LOGIN] $conn available: ' . ($conn ? 'YES' : 'NO'));

    if (empty($email) || empty($password)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Email y contraseña requeridos']));
    }
    if (isLoginThrottled($email)) {
        http_response_code(429);
        exit(json_encode(['status' => 'error', 'message' => 'Demasiados intentos de acceso. Intente más tarde.']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $checkUser = $conn->prepare("SELECT user_id, school_id, role_id, active FROM users WHERE email = :email");
        $checkUser->execute(['email' => $email]);
        $rawUser = $checkUser->fetch(PDO::FETCH_ASSOC);

        if (!$rawUser) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Credenciales incorrectas (U1)']));
        }

        $stmt = $conn->prepare("
            SELECT u.user_id, u.email, u.password_hash, u.first_name, u.last_name, u.active,
                   u.profile_photo_url, u.work_shift,
                   r.role_name, s.school_id, s.school_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.role_id
            INNER JOIN schools s ON u.school_id = s.school_id
            WHERE u.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$rawUser['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$user['active']) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Cuenta inactiva o inexistente']));
        }

        if (verifyUserPassword($password, $user['password_hash'])) {
            securityLog('LOGIN_SUCCESS', "User authenticated: " . $user['user_id']);

            // Re-hash si el hash actual usa crypt() legacy o necesita upgrade
            if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
                try {
                    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $rehashStmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                    $rehashStmt->execute([$newHash, $user['user_id']]);
                    securityLog('PASSWORD_REHASHED', 'User ' . $user['user_id'] . ' migrated to bcrypt cost=12');
                } catch (Throwable $e) {
                    securityLog('PASSWORD_REHASH_FAILED', $e->getMessage());
                }
            }

            $updateStmt = $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?");
            $updateStmt->execute([$user['user_id']]);

            $normalizedRole = normalizeRole($user['role_name']);
            $tokenTtlSeconds = (int)(getenv('JWT_ACCESS_TTL_SECONDS') ?: 86400);
            $token = issueJwtToken([
                'sub' => (string)$user['user_id'],
                'email' => $user['email'],
                'role' => $normalizedRole,
                'school_id' => $user['school_id'],
                'exp' => time() + $tokenTtlSeconds
            ]);
            
            // PILAR 2.2: Cookie HttpOnly, Secure, SameSite=None (cross-domain)
            $cookieOpts = [
                'expires' => time() + $tokenTtlSeconds,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'None'
            ];
            setcookie('token', $token, $cookieOpts);
            
            echo json_encode([
                'status' => 'ok',
                'user' => [
                    'id' => $user['user_id'],
                    'nombre' => $user['first_name'] . ' ' . $user['last_name'],
                    'email' => $user['email'],
                    'role' => $normalizedRole,
                    'school_id' => $user['school_id'],
                    'school_name' => $user['school_name'],
                    'profile_photo_url' => $user['profile_photo_url'] ?? null,
                    'work_shift' => $user['work_shift'] ?? null
                ]
            ]);
        } else {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Credenciales incorrectas (P)']));
        }
    } catch (Throwable $e) {
        $errorDetails = sprintf(
            '[LOGIN EXCEPTION] %s in %s:%d | Trace: %s',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        error_log($errorDetails);
        securityLog('AUTH_CRITICAL_ERROR', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        exit(json_encode(['status' => 'error', 'message' => 'Error de autenticación', 'debug' => getenv('APP_ENV') === 'development' ? $e->getMessage() : null]));
    }
    exit;
}

if ($cleanPath === '/auth/logout') {
    $token = extractBearerToken();
    if ($token) {
        try {
            $claims = verifyJwtToken($token);
            revokeJwt($claims['jti'], $claims['exp']);
        } catch (Exception $e) {
            securityLog('AUTH_LOGOUT_TOKEN_IGNORED', $e->getMessage());
        }
    }
    echo json_encode(['status' => 'ok', 'message' => 'Sesión cerrada']);
    exit;
}

if ($cleanPath === '/auth/me') {
    securityLog('AUTH_ME_REQUEST', 'Validating session from JWT');
    $authUser = requireAuth();
    echo json_encode([
        'status' => 'ok',
        'user' => [
            'id' => $authUser['id'],
            'nombre' => $authUser['nombre'],
            'email' => $authUser['email'],
            'role' => $authUser['role'],
            'school_id' => $authUser['school_id'],
            'school_name' => $authUser['school_name'],
            'profile_photo_url' => $authUser['profile_photo_url'] ?? null,
            'work_shift' => $authUser['work_shift'] ?? null
        ]
    ]);
    exit;
}
