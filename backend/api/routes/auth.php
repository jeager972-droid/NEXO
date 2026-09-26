<?php
/**
 * =============================================================================
 * routes/auth.php — Autenticación de usuarios y gestión de sesiones JWT.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Gestiona el ciclo de vida de autenticación:
 *   - POST /auth/login  : valida credenciales, emite JWT (o solicita 2FA).
 *   - POST /auth/verify-2fa : valida código de doble factor y emite JWT.
 *   - POST /auth/logout : revoca el JWT actual y limpia la cookie.
 *   - GET  /auth/me     : retorna el usuario de la sesión activa.
 *
 * FLUJO GENERAL (login sin 2FA)
 * -----------------------------
 *   POST /auth/login
 *        │
 *        ▼
 *   isLoginThrottled() ──► carga usuario ──► verifyUserPassword()
 *        │
 *        ▼
 *   password_needs_rehash? ──► issueJwtToken() ──► setcookie('token')
 *        │
 *        ▼
 *   {status:'ok', user:{...}}
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : issueJwtToken, verifyJwtToken, extractBearerToken,
 *                            revokeJwt, normalizeRole, getRedisConnection.
 *   - lib/twilio.php : sendTwilioDirect (fallback 2FA).
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Frontend PWA: Login.jsx, App.jsx para validación de sesión.
 */

global $cleanPath, $conn, $input, $method;

require_once __DIR__ . '/_auth_middleware.php';

/**
 * Verifica si un intento de login debe ser bloqueado por exceso de intentos.
 *
 * @param string $email Correo del usuario que intenta iniciar sesión.
 * @return bool True si se superaron los intentos permitidos en la ventana.
 *
 * Efectos secundarios: inserta/actualiza la fila correspondiente en rate_limits.
 * Precondiciones: $conn debe estar disponible; si no, retorna false (fail-open).
 * Postcondiciones: retorna true cuando hits > 8 en ventana de 900s.
 */
function isLoginThrottled($email) {
    global $conn;
    $ip = getRealClientIp();
    $key = 'login:' . hash('sha256', strtolower(trim($email)) . '|' . $ip);
    $window = 900;
    $maxAttempts = 8;

    if (!$conn) {
        return false;
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

/**
 * Verifica una contraseña contra un hash usando password_verify.
 *
 * @param string $password Contraseña en texto plano.
 * @param string $hash Hash almacenado (bcrypt u otro soportado por PHP).
 * @return bool True si la contraseña coincide.
 */
function verifyUserPassword($password, $hash) {
    $password = (string)$password;
    $hash = (string)$hash;
    return password_verify($password, $hash);
}

// ============================================================================
// POST /auth/login — Autentica usuario y emite JWT.
// Si LOGIN_2FA_ENABLED=true y el teléfono está verificado, solicita 2FA vía WhatsApp.
// ============================================================================
if ($cleanPath === '/auth/login') {
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

        $checkUser = $conn->prepare("SELECT user_id, school_id, role_id, (deleted_at IS NULL) AS active FROM users WHERE email = :email");
        $checkUser->execute(['email' => $email]);
        $rawUser = $checkUser->fetch(PDO::FETCH_ASSOC);

        if (!$rawUser) {
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Credenciales incorrectas (U1)']));
        }

        $stmt = $conn->prepare("
            SELECT u.user_id, u.email, u.password_hash, u.first_name, u.last_name, (u.deleted_at IS NULL) AS active,
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

            if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
                try {
                    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    try {
                        $rehashStmt = $conn->prepare("UPDATE users SET password_hash = ?, password_salt = NULL WHERE user_id = ?");
                        $rehashStmt->execute([$newHash, $user['user_id']]);
                    } catch (PDOException $e) {
                        $rehashStmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                        $rehashStmt->execute([$newHash, $user['user_id']]);
                    }
                    securityLog('PASSWORD_REHASHED', 'User ' . $user['user_id'] . ' migrated to bcrypt cost=12');
                } catch (Throwable $e) {
                    securityLog('PASSWORD_REHASH_FAILED', $e->getMessage());
                }
            }

            $updateStmt = $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?");
            $updateStmt->execute([$user['user_id']]);

            $normalizedRole = normalizeRole($user['role_name']);
            // Access token TTL corto (15 min por defecto) + refresh token (7 días)
            $tokenTtlSeconds = (int)(getenv('JWT_ACCESS_TTL_SECONDS') ?: 900);
            $refreshTtlSeconds = (int)(getenv('JWT_REFRESH_TTL_SECONDS') ?: 604800); // 7 días

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

                try {
                    $redis = getRedisConnection();
                    if ($redis) {
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
                    $otpResult = sendTwilioDirect($userPhone, "🔐 *NEXO — Código de verificación*\n\nTu código para *inicio de sesión* es:\n\n*{$code}*\n\nVálido por 5 minutos.");
                    if (!$otpResult['ok']) {
                        http_response_code(503);
                        securityLog('2FA_DELIVERY_FAILED', "Cannot send 2FA to user {$user['user_id']}");
                        exit(json_encode(['status' => 'error', 'message' => 'Servicio de seguridad no disponible. Intente más tarde o contacte soporte.']));
                    }
                }

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

            // Generar refresh token y persistir su hash en user_sessions
            $refreshToken = bin2hex(random_bytes(32));
            $refreshTokenHash = hash('sha256', $refreshToken);
            $refreshExpiresAt = date('Y-m-d H:i:s', time() + $refreshTtlSeconds);
            $clientIp = getRealClientIp();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $sessionStmt = $conn->prepare("
                INSERT INTO user_sessions (user_id, refresh_token_hash, ip_address, user_agent, expires_at)
                VALUES (?::uuid, ?, ?::inet, ?, ?::timestamptz)
            ");
            $sessionStmt->execute([$user['user_id'], $refreshTokenHash, $clientIp, $userAgent, $refreshExpiresAt]);

            $cookieOpts = [
                'expires' => time() + $tokenTtlSeconds,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'None'
            ];
            setcookie('token', $token, $cookieOpts);

            // Refresh token cookie: HttpOnly, no accessible desde JS
            $refreshCookieOpts = [
                'expires' => time() + $refreshTtlSeconds,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'None'
            ];
            setcookie('refresh_token', $refreshToken, $refreshCookieOpts);

            echo json_encode([
                'status' => 'ok',
                // TEMPORAL ITP WORKAROUND: token en body para iOS/Safari donde ITP bloquea cookies cross-site.
                // La cookie HttpOnly sigue seteándose arriba para cuando frontend y backend estén en same-site.
                // TODO: Cuando se migre a same-site, eliminar 'token' del response y usar solo cookie HttpOnly.
                'token' => $token,
                'refresh_token' => $refreshToken,
                'token_expires_in' => $tokenTtlSeconds,
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
// POST /auth/verify-2fa — Valida código de doble factor y emite JWT.
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
            SELECT u.user_id, u.email, u.first_name, u.last_name, (u.deleted_at IS NULL) AS active,
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

        $mark = $conn->prepare("UPDATE verification_codes SET used = TRUE WHERE code_id = ?");
        $mark->execute([$codeRow['code_id']]);

        $normalizedRole = normalizeRole($user['role_name']);
        // Access token TTL corto + refresh token
        $tokenTtlSeconds = (int)(getenv('JWT_ACCESS_TTL_SECONDS') ?: 900);
        $refreshTtlSeconds = (int)(getenv('JWT_REFRESH_TTL_SECONDS') ?: 604800);
        $token = issueJwtToken([
            'sub' => (string)$user['user_id'],
            'email' => $user['email'],
            'role' => $normalizedRole,
            'school_id' => $user['school_id'],
            'exp' => time() + $tokenTtlSeconds
        ]);

        // Generar refresh token
        $refreshToken = bin2hex(random_bytes(32));
        $refreshTokenHash = hash('sha256', $refreshToken);
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + $refreshTtlSeconds);
        $clientIp = getRealClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $sessionStmt = $conn->prepare("
            INSERT INTO user_sessions (user_id, refresh_token_hash, ip_address, user_agent, expires_at)
            VALUES (?::uuid, ?, ?::inet, ?, ?::timestamptz)
        ");
        $sessionStmt->execute([$user['user_id'], $refreshTokenHash, $clientIp, $userAgent, $refreshExpiresAt]);

        $cookieOpts = [
            'expires' => time() + $tokenTtlSeconds,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ];
        setcookie('token', $token, $cookieOpts);
        $refreshCookieOpts = [
            'expires' => time() + $refreshTtlSeconds,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ];
        setcookie('refresh_token', $refreshToken, $refreshCookieOpts);

        securityLog('LOGIN_2FA_SUCCESS', "User authenticated via 2FA: " . $user['user_id']);
        echo json_encode([
            'status' => 'ok',
            // TEMPORAL ITP WORKAROUND: token en body para iOS/Safari donde ITP bloquea cookies cross-site.
            // La cookie HttpOnly sigue seteándose arriba para cuando frontend y backend estén en same-site.
            // TODO: Cuando se migre a same-site, eliminar 'token' del response y usar solo cookie HttpOnly.
            'token' => $token,
            'refresh_token' => $refreshToken,
            'token_expires_in' => $tokenTtlSeconds,
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

// ============================================================================
// POST /auth/logout — Revoca el JWT actual y elimina la cookie HttpOnly.
// ============================================================================
if ($cleanPath === '/auth/logout' && $method === 'POST') {
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

    // Revocar refresh token si está presente
    $refreshToken = $_COOKIE['refresh_token'] ?? '';
    if ($refreshToken !== '') {
        try {
            $refreshTokenHash = hash('sha256', $refreshToken);
            $revokeStmt = $conn->prepare("UPDATE user_sessions SET revoked = TRUE, revoked_at = NOW() WHERE refresh_token_hash = ? AND revoked = FALSE");
            $revokeStmt->execute([$refreshTokenHash]);
        } catch (Exception $e) {
            securityLog('AUTH_LOGOUT_REFRESH_IGNORED', $e->getMessage());
        }
    }

    setcookie('token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
    setcookie('refresh_token', '', [
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

// ============================================================================
// GET /auth/me — Retorna datos del usuario autenticado (validación de sesión).
// ============================================================================
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

// ============================================================================
// POST /auth/refresh — Renueva el access token usando el refresh token.
// Implementa rotación de refresh tokens (cada uso invalida el anterior).
// ============================================================================
if ($cleanPath === '/auth/refresh' && $method === 'POST') {
    // El refresh token puede venir del body (ITP workaround) o de la cookie HttpOnly
    $refreshToken = (string)($input['refresh_token'] ?? $_COOKIE['refresh_token'] ?? '');

    if ($refreshToken === '' || strlen($refreshToken) < 32) {
        http_response_code(401);
        exit(json_encode(['status' => 'error', 'message' => 'Refresh token no proporcionado']));
    }

    $refreshTokenHash = hash('sha256', $refreshToken);

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");
        
        // Buscar la sesión activa con este refresh token
        $stmt = $conn->prepare("
            SELECT us.session_id, us.user_id, us.expires_at, us.revoked,
                   u.email, r.role_name, u.school_id, u.first_name, u.last_name,
                   u.profile_photo_url, u.work_shift, u.active as user_active,
                   s.school_name
            FROM user_sessions us
            JOIN users u ON u.user_id = us.user_id
            INNER JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN schools s ON s.school_id = u.school_id
            WHERE us.refresh_token_hash = ?
              AND us.revoked = FALSE
              AND us.expires_at > NOW()
              AND u.active = TRUE
            LIMIT 1
        ");
        $stmt->execute([$refreshTokenHash]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            securityLog('REFRESH_TOKEN_INVALID', 'Refresh token not found, expired, or revoked');
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Refresh token inválido o expirado']));
        }

        // Rotar: revocar el refresh token actual
        $revokeStmt = $conn->prepare("UPDATE user_sessions SET revoked = TRUE, revoked_at = NOW() WHERE session_id = ?::uuid");
        $revokeStmt->execute([$session['session_id']]);

        // Emitir nuevo access token
        $normalizedRole = normalizeRole($session['role_name']);
        $tokenTtlSeconds = (int)(getenv('JWT_ACCESS_TTL_SECONDS') ?: 900);
        $refreshTtlSeconds = (int)(getenv('JWT_REFRESH_TTL_SECONDS') ?: 604800);

        $newToken = issueJwtToken([
            'sub' => (string)$session['user_id'],
            'email' => $session['email'],
            'role' => $normalizedRole,
            'school_id' => $session['school_id'],
            'exp' => time() + $tokenTtlSeconds
        ]);

        // Emitir nuevo refresh token (rotación)
        $newRefreshToken = bin2hex(random_bytes(32));
        $newRefreshTokenHash = hash('sha256', $newRefreshToken);
        $newRefreshExpiresAt = date('Y-m-d H:i:s', time() + $refreshTtlSeconds);
        $clientIp = getRealClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $newSessionStmt = $conn->prepare("
            INSERT INTO user_sessions (user_id, refresh_token_hash, ip_address, user_agent, expires_at)
            VALUES (?::uuid, ?, ?::inet, ?, ?::timestamptz)
        ");
        $newSessionStmt->execute([$session['user_id'], $newRefreshTokenHash, $clientIp, $userAgent, $newRefreshExpiresAt]);

        // Setear cookies
        $cookieOpts = [
            'expires' => time() + $tokenTtlSeconds,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ];
        setcookie('token', $newToken, $cookieOpts);
        $refreshCookieOpts = [
            'expires' => time() + $refreshTtlSeconds,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ];
        setcookie('refresh_token', $newRefreshToken, $refreshCookieOpts);

        echo json_encode([
            'status' => 'ok',
            'token' => $newToken,
            'refresh_token' => $newRefreshToken,
            'token_expires_in' => $tokenTtlSeconds,
            'user' => [
                'id' => $session['user_id'],
                'nombre' => $session['first_name'] . ' ' . $session['last_name'],
                'email' => $session['email'],
                'role' => $normalizedRole,
                'school_id' => $session['school_id'],
                'school_name' => $session['school_name'],
                'profile_photo_url' => $session['profile_photo_url'] ?? null,
                'work_shift' => $session['work_shift'] ?? null
            ]
        ]);
    } catch (Throwable $e) {
        securityLog('AUTH_REFRESH_DB_ERROR', $e->getMessage());
        http_response_code(503);
        // Exponential backoff frontend hint
        exit(json_encode(['status' => 'error', 'message' => 'Servicio temporalmente no disponible (BD).', 'retry_after' => 60]));
    }
    exit;
}
