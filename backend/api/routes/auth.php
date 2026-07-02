<?php
// routes/auth.php - Manejo de autenticación
global $cleanPath, $conn, $input, $method;

/**
 * @OA\Info(
 *     title="NEXO API",
 *     version="7.5.0",
 *     description="API REST para la plataforma educativa NEXO - Control de asistencia biométrica"
 * )
 * @OA\Server(url="https://nexo-production-dbe3.up.railway.app", description="Production")
 * @OA\Server(url="http://localhost:8080", description="Local Development")
 */

// ROLES y normalizeRole() ahora están en _auth_middleware.php para disponibilidad global
require_once __DIR__ . '/_auth_middleware.php';

function isLoginThrottled($email) {
    global $conn;
    $ip = getRealClientIp();
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
                   u.profile_photo_url, u.work_shift, u.phone, u.phone_verified,
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

            // 2FA opcional: si LOGIN_2FA_ENABLED=true y usuario tiene teléfono verificado
            $userPhone = isset($user['phone']) ? preg_replace('/[^0-9+]/', '', $user['phone']) : '';
            $twoFaEnabled = getenv('LOGIN_2FA_ENABLED') === 'true';
            if ($twoFaEnabled && $userPhone !== '' && !empty($user['phone_verified'])) {
                $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

                $ins = $conn->prepare("
                    INSERT INTO verification_codes (user_id, purpose, target_value, code, expires_at)
                    VALUES (?, 'login_2fa', ?, ?, ?)
                ");
                $ins->execute([$user['user_id'], $user['email'], $code, $expiresAt]);

                // Cambio: encolar OTP en Redis para envío asíncrono (evita bloqueo de 2s en login)
                try {
                    $redis = getRedisConnection();
                    if ($redis) {
                        $redis->select((int)(getenv('REDIS_DB') ?: 0));
                        $redis->rPush('queue:twilio', json_encode([
                            'to' => $userPhone,
                            'body' => "🔐 *NEXO — Código de verificación*\n\nTu código para *inicio de sesión* es:\n\n*{$code}*\n\nVálido por 5 minutos.",
                            'school_id' => $user['school_id'],
                            'student_id' => null,
                            'guardian_id' => null,
                            'sender_user_id' => $user['user_id'],
                            'type_code' => 'LOGIN_2FA',
                            'retries' => 0,
                            'created_at' => time()
                        ], JSON_UNESCAPED_UNICODE));
                    }
                } catch (Exception $e) {
                    securityLog('2FA_REDIS_ENQUEUE_FAILED', $e->getMessage());
                    // Si Redis falla, intentar envío directo con timeout reducido
                    $otpResult = sendTwilioDirect($userPhone, "🔐 *NEXO — Código de verificación*\n\nTu código para *inicio de sesión* es:\n\n*{$code}*\n\nVálido por 5 minutos.");
                    if (!$otpResult['ok']) {
                        http_response_code(503);
                        securityLog('2FA_DELIVERY_FAILED', "Cannot send 2FA to user {$user['user_id']}");
                        exit(json_encode(['status' => 'error', 'message' => 'Servicio de seguridad no disponible. Intente más tarde o contacte soporte.']));
                    }
                }

                // Responder inmediatamente sin esperar confirmación de Twilio
                http_response_code(202);
                echo json_encode([
                    'status' => '2fa_required',
                    'message' => 'Se envió un código de verificación a tu WhatsApp. Ingrésalo para continuar.',
                    'requires_2fa' => true
                ]);
                exit;
            }

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

// ============================================================================
// POST /auth/verify-2fa
// Body: { email: string, code: string }
// Completa el login después de 2FA enviado por WhatsApp
// ============================================================================
if ($cleanPath === '/auth/verify-2fa' && $method === 'POST') {
    try {
        $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $code  = trim((string)($input['code'] ?? ''));

        if (empty($email) || empty($code) || strlen($code) !== 6) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Email y código (6 dígitos) requeridos']));
        }

        if (isLoginThrottled($email)) {
            http_response_code(429);
            exit(json_encode(['status' => 'error', 'message' => 'Demasiados intentos. Intente más tarde.']));
        }

        $userStmt = $conn->prepare("
            SELECT u.user_id, u.email, u.first_name, u.last_name, u.active,
                   u.profile_photo_url, u.work_shift,
                   r.role_name, s.school_id, s.school_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.role_id
            INNER JOIN schools s ON u.school_id = s.school_id
            WHERE u.email = ?
            LIMIT 1
        ");
        $userStmt->execute([$email]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$user['active']) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Cuenta inactiva']));
        }

        $codeStmt = $conn->prepare("
            SELECT code_id, used, expires_at, attempts, max_attempts
            FROM verification_codes
            WHERE user_id = ? AND purpose = 'login_2fa' AND code = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $codeStmt->execute([$user['user_id'], $code]);
        $codeRow = $codeStmt->fetch(PDO::FETCH_ASSOC);

        if (!$codeRow) {
            // Increment attempts for the most recent code (even if it doesn't match)
            $incrStmt = $conn->prepare("
                UPDATE verification_codes
                SET attempts = attempts + 1
                WHERE code_id = (
                    SELECT code_id FROM verification_codes
                    WHERE user_id = ? AND purpose = 'login_2fa'
                    ORDER BY created_at DESC
                    LIMIT 1
                )
            ");
            $incrStmt->execute([$user['user_id']]);
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Código incorrecto']));
        }

        if ((int)$codeRow['attempts'] >= (int)$codeRow['max_attempts']) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Demasiados intentos. Solicita un nuevo código.']));
        }

        if ($codeRow['used']) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Código ya utilizado']));
        }
        if (strtotime($codeRow['expires_at']) < time()) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Código expirado']));
        }

        // Marcar código como usado
        $mark = $conn->prepare("UPDATE verification_codes SET used = TRUE WHERE code_id = ?");
        $mark->execute([$codeRow['code_id']]);

        $normalizedRole = normalizeRole($user['role_name']);
        $tokenTtlSeconds = (int)(getenv('JWT_ACCESS_TTL_SECONDS') ?: 86400);
        $token = issueJwtToken([
            'sub' => (string)$user['user_id'],
            'email' => $user['email'],
            'role' => $normalizedRole,
            'school_id' => $user['school_id'],
            'exp' => time() + $tokenTtlSeconds
        ]);

        $cookieOpts = [
            'expires' => time() + $tokenTtlSeconds,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ];
        setcookie('token', $token, $cookieOpts);

        securityLog('LOGIN_2FA_SUCCESS', "User authenticated via 2FA: " . $user['user_id']);
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
    } catch (Throwable $e) {
        error_log('[2FA EXCEPTION] ' . $e->getMessage());
        http_response_code(500);
        exit(json_encode(['status' => 'error', 'message' => 'Error de verificación']));
    }
    exit;
}

if ($cleanPath === '/auth/logout' && $method === 'POST') {
    // CSRF check: require X-Requested-With header
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Request forbidden']));
    }

    $token = extractBearerToken();
    if ($token) {
        try {
            $claims = verifyJwtToken($token);
            revokeJwt($claims['jti'], $claims['exp']);
        } catch (Exception $e) {
            securityLog('AUTH_LOGOUT_TOKEN_IGNORED', $e->getMessage());
        }
    }

    setcookie('token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);

    http_response_code(200);
    header('Content-Type: application/json');
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
