<?php
/**
 * routes/users.php — Endpoints para gestión de usuarios, perfil, fotos y verificación OTP vía Twilio WhatsApp.
 */

global $cleanPath, $conn, $method, $input;
require_once __DIR__ . '/_auth_middleware.php';

if (strpos($cleanPath, '/users/') !== 0) {
    return;
}

$authUser = requireAuth();
$schoolId = $authUser['school_id'];
$userId   = $authUser['id']; // FIX: requireAuth retorna 'id', no 'user_id'

function usersJson($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function generateOtpCode() {
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function normalizePhone($value) {
    $value = trim((string)$value);
    $value = preg_replace('/^whatsapp:/i', '', $value);
    $digits = preg_replace('/[^0-9]/', '', $value);
    if ($digits === '') return '';

    // Already has country code (e.g. 573001234567 = 12 digits)
    if (strlen($digits) >= 11) {
        return '+' . $digits;
    }

    // Colombian mobile number: 10 digits starting with 3
    if (strlen($digits) === 10 && $digits[0] === '3') {
        return '+57' . $digits;
    }

    return '+' . $digits;
}

function sendTwilioWhatsAppOtp($to, $code, $purpose) {
    if (!function_exists('sendTwilioDirect')) {
        error_log('[OTP] sendTwilioDirect no está definida. Verifica que operations.php se incluya antes que users.php.');
        return ['ok' => false, 'error' => 'sendTwilioDirect no disponible. Contacta soporte.'];
    }

    $body = "🔐 *NEXO — Código de verificación*\n\nTu código es: *{$code}*\n\nVálido por 10 minutos. No lo compartas.";

    error_log('[OTP] Enviando código a ' . $to . ' purpose=' . $purpose);
    $result = sendTwilioDirect($to, $body);
    error_log('[OTP] Resultado Twilio: ' . json_encode($result));
    return $result;
}

// ============================================================================
// GET /users/by-role?role=COORDINADOR&same_shift=1
// ============================================================================
if ($cleanPath === '/users/by-role' && $method === 'GET') {
    try {
        $userRole = strtoupper($authUser['role'] ?? '');
        $allowedDirectoryRoles = ['SUPER_RECTOR', 'RECTOR', 'COORDINADOR', 'SECRETARIA', 'DOCENTE', 'PSICORIENTADOR'];
        if (!in_array($userRole, $allowedDirectoryRoles, true)) {
            usersJson(['status' => 'error', 'message' => 'No tienes permisos para consultar el directorio de personal'], 403);
        }

        $roleName = $_GET['role'] ?? '';
        $sameShift = ($_GET['same_shift'] ?? '0') === '1';

        if (!$roleName) {
            usersJson(['status' => 'error', 'message' => 'role param required'], 400);
        }

        $requesterShift = null;
        if ($sameShift) {
            $st = $conn->prepare("SELECT work_shift FROM users WHERE user_id = ?");
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $requesterShift = $row['work_shift'] ?? null;
        }

        $sql = "
            SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_photo_url,
                   r.role_name, u.work_shift
            FROM users u
            INNER JOIN roles r ON u.role_id = r.role_id
            WHERE u.school_id = ? AND r.role_name = ? AND u.active = TRUE
        ";
        $params = [$schoolId, $roleName];

        if ($sameShift && $requesterShift) {
            $sql .= " AND u.work_shift = ?";
            $params[] = $requesterShift;
        }

        $sql .= " ORDER BY u.last_name, u.first_name";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        usersJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// GET /users/me/extended
// Full profile with backup_email, verified flags, phone
// ============================================================================
if ($cleanPath === '/users/me/extended' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT user_id, first_name, last_name, email, phone, backup_email,
                   profile_photo_url, work_shift, email_verified, phone_verified,
                   document_number, active, created_at, updated_at
            FROM users WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            usersJson(['status' => 'error', 'message' => 'Usuario no encontrado'], 404);
        }
        usersJson(['status' => 'ok', 'data' => $user]);
    } catch (Throwable $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// POST /users/upload-photo
// Stores image as base64 data URL in DB (Railway-safe, no local files)
// ============================================================================
if ($cleanPath === '/users/upload-photo' && $method === 'POST') {
    try {
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            usersJson(['status' => 'error', 'message' => 'No photo uploaded or upload error'], 400);
        }

        $file = $_FILES['photo'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        if (!in_array($file['type'], $allowed)) {
            usersJson(['status' => 'error', 'message' => 'Solo JPG, PNG, WEBP permitidos'], 400);
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            usersJson(['status' => 'error', 'message' => 'Máximo 2MB'], 400);
        }

        $raw = file_get_contents($file['tmp_name']);
        if (!$raw) {
            usersJson(['status' => 'error', 'message' => 'No se pudo leer la imagen'], 500);
        }

        $mime = $file['type'];
        $base64 = 'data:' . $mime . ';base64,' . base64_encode($raw);

        $stmt = $conn->prepare("UPDATE users SET profile_photo_url = ? WHERE user_id = ?");
        $stmt->execute([$base64, $userId]);

        usersJson(['status' => 'ok', 'photo_url' => $base64]);
    } catch (Throwable $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// POST /users/send-verification
// Sends 6-digit OTP via Twilio WhatsApp
// Body: { purpose: 'email_change'|'phone_change'|'password_reset'|'backup_email', target: string }
// ============================================================================
if ($cleanPath === '/users/send-verification' && $method === 'POST') {
    try {
        $rawInput = file_get_contents('php://input');
        error_log("[OTP-RAW-BODY] " . $rawInput);
        $purpose = trim((string)($input['purpose'] ?? ''));
        $target  = trim((string)($input['target'] ?? ''));
        error_log("[OTP-START] user={$userId} purpose={$purpose} target={$target}");

        $validPurposes = ['email_change', 'phone_change', 'password_reset', 'password_change', 'backup_email', 'login_2fa'];
        if (!in_array($purpose, $validPurposes, true) || $target === '') {
            error_log("[OTP-VALIDATION-FAIL] purpose={$purpose} target empty=" . ($target === '' ? 'yes' : 'no'));
            usersJson(['status' => 'error', 'message' => 'purpose y target requeridos'], 400);
        }

        // Determine destination phone
        if ($purpose === 'phone_change') {
            // Phone verification: send OTP to the NEW number being verified
            $userPhone = normalizePhone($target);
            error_log("[OTP-PHONE-NEW] target={$target} normalized={$userPhone}");
            if ($userPhone === '') {
                usersJson(['status' => 'error', 'message' => 'Número de teléfono inválido. Ingresa un número de 10 dígitos.'], 400);
            }
        } else {
            // Email, backup_email, password, 2fa: need existing phone from profile
            $phoneStmt = $conn->prepare("SELECT phone FROM users WHERE user_id = ?");
            $phoneStmt->execute([$userId]);
            $uRow = $phoneStmt->fetch(PDO::FETCH_ASSOC);
            $rawPhone = $uRow['phone'] ?? '';
            $userPhone = normalizePhone($rawPhone);
            error_log("[OTP-PHONE-DB] raw={$rawPhone} normalized={$userPhone}");
            if ($userPhone === '') {
                usersJson(['status' => 'error', 'message' => 'Primero registra y verifica tu número de WhatsApp para recibir códigos OTP.'], 400);
            }
        }

        $code = generateOtpCode();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        error_log("[OTP-GENERATED] code={$code} expires={$expiresAt}");

        $ins = $conn->prepare("
            INSERT INTO verification_codes (user_id, purpose, target_value, code, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->execute([$userId, $purpose, $target, $code, $expiresAt]);
        error_log("[OTP-DB-INSERT] ok user={$userId}");

        error_log("[OTP-SEND] calling sendTwilioWhatsAppOtp to={$userPhone}");
        $twilioResult = sendTwilioWhatsAppOtp($userPhone, $code, $purpose);
        error_log("[OTP-SEND-RESULT] ok=" . ($twilioResult['ok'] ? 'true' : 'false') . " error=" . ($twilioResult['error'] ?? 'none'));

        if (!$twilioResult['ok']) {
            usersJson(['status' => 'error', 'message' => 'No se pudo enviar el código por WhatsApp: ' . $twilioResult['error']], 502);
        }

        error_log("[OTP-SUCCESS] user={$userId}");
        usersJson(['status' => 'ok', 'message' => 'Código enviado por WhatsApp', 'expires_in_minutes' => 10]);
    } catch (Throwable $e) {
        error_log("[OTP-EXCEPTION] " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// POST /users/verify-code
// Body: { purpose: string, code: string }
// ============================================================================
if ($cleanPath === '/users/verify-code' && $method === 'POST') {
    try {
        $purpose = trim((string)($input['purpose'] ?? ''));
        $code    = trim((string)($input['code'] ?? ''));

        if ($purpose === '' || $code === '' || strlen($code) !== 6) {
            usersJson(['status' => 'error', 'message' => 'purpose y code (6 dígitos) requeridos'], 400);
        }

        $stmt = $conn->prepare("
            SELECT code_id, code as db_code, target_value, attempts, max_attempts, used, expires_at
            FROM verification_codes
            WHERE user_id = ? AND purpose = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            usersJson(['status' => 'error', 'message' => 'Código no encontrado o incorrecto'], 400);
        }

        if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
            usersJson(['status' => 'error', 'message' => 'Demasiados intentos. Solicita un nuevo código.'], 400);
        }

        if ($code !== $row['db_code']) {
            $incrStmt = $conn->prepare(
                "UPDATE verification_codes
                 SET attempts = attempts + 1
                 WHERE code_id = :code_id"
            );
            $incrStmt->execute([':code_id' => $row['code_id']]);
            usersJson(['status' => 'error', 'message' => 'Código incorrecto'], 400);
        }

        if ($row['used']) {
            usersJson(['status' => 'error', 'message' => 'Código ya utilizado'], 400);
        }

        if (strtotime($row['expires_at']) < time()) {
            usersJson(['status' => 'error', 'message' => 'Código expirado'], 400);
        }

        // Mark as verified (but not used yet — usage happens in update-profile)
        $upd = $conn->prepare("
            UPDATE verification_codes
            SET verified_at = NOW()
            WHERE code_id = ?
        ");
        $upd->execute([$row['code_id']]);

        usersJson(['status' => 'ok', 'message' => 'Código verificado', 'target_value' => $row['target_value']]);
    } catch (Throwable $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// POST /users/update-profile
// Updates email, phone, backup_email after OTP verification
// Body: { purpose: 'email_change'|'phone_change'|'backup_email', value: string }
// ============================================================================
if ($cleanPath === '/users/update-profile' && $method === 'POST') {
    try {
        $purpose = trim((string)($input['purpose'] ?? ''));
        $value   = trim((string)($input['value'] ?? ''));

        $validPurposes = ['email_change', 'phone_change', 'backup_email'];
        if (!in_array($purpose, $validPurposes, true) || $value === '') {
            usersJson(['status' => 'error', 'message' => 'purpose y value requeridos'], 400);
        }

        $fieldMap = [
            'email_change' => 'email',
            'phone_change' => 'phone',
            'backup_email' => 'backup_email',
        ];
        $dbField = $fieldMap[$purpose];
        $verifiedField = ($purpose === 'email_change' || $purpose === 'backup_email') ? 'email_verified' : 'phone_verified';

        // Todos los cambios requieren OTP verification
        $stmt = $conn->prepare("
            SELECT code_id, target_value
            FROM verification_codes
            WHERE user_id = ? AND purpose = ? AND target_value = ? AND verified_at IS NOT NULL AND used = FALSE
            ORDER BY verified_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $purpose, $value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            usersJson(['status' => 'error', 'message' => 'Verificación requerida. Solicita y confirma un código primero.'], 403);
        }

        // Mark code as used
        $mark = $conn->prepare("UPDATE verification_codes SET used = TRUE WHERE code_id = ?");
        $mark->execute([$row['code_id']]);

        $upd = $conn->prepare("UPDATE users SET {$dbField} = ?, {$verifiedField} = TRUE, updated_at = NOW() WHERE user_id = ?");
        $upd->execute([$value, $userId]);

        // Si cambió teléfono, sincronizar también en guardians.whatsapp_phone
        if ($purpose === 'phone_change') {
            $normPhone = normalizePhone($value);
            $guardSync = $conn->prepare("
                UPDATE guardians
                SET whatsapp_phone = ?, whatsapp_phone_normalized = regexp_replace(?, '[^0-9+]', '', 'g')
                WHERE user_id = ?
            ");
            $guardSync->execute([$value, $normPhone, $userId]);
        }

        usersJson(['status' => 'ok', 'message' => 'Perfil actualizado correctamente']);
    } catch (Throwable $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// POST /users/delete-field
// Clears email, phone, or backup_email from profile
// Body: { field: 'email'|'phone'|'backup_email' }
// ============================================================================
if ($cleanPath === '/users/delete-field' && $method === 'POST') {
    try {
        $field = trim((string)($input['field'] ?? ''));
        $validFields = [
            'phone' => ['column' => 'phone', 'verified' => 'phone_verified'],
            'backup_email' => ['column' => 'backup_email', 'verified' => null],
        ];
        if (!isset($validFields[$field])) {
            usersJson(['status' => 'error', 'message' => 'field no válido'], 400);
        }

        $col = $validFields[$field]['column'];
        $verifiedCol = $validFields[$field]['verified'];
        $setVerified = $verifiedCol ? ", {$verifiedCol} = FALSE" : '';

        $stmt = $conn->prepare("UPDATE users SET {$col} = NULL{$setVerified}, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$userId]);

        usersJson(['status' => 'ok', 'message' => ucfirst(str_replace('_', ' ', $field)) . ' eliminado correctamente']);
    } catch (Throwable $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// POST /users/change-password
// Body: { current_password: string, new_password: string }
// ============================================================================
if ($cleanPath === '/users/change-password' && $method === 'POST') {
    try {
        $current = $input['current_password'] ?? '';
        $new     = $input['new_password'] ?? '';
        $code    = trim((string)($input['code'] ?? ''));

        if ($current === '' || $new === '' || strlen($new) < 8) {
            usersJson(['status' => 'error', 'message' => 'Contraseña actual requerida. Nueva contraseña: mínimo 8 caracteres.'], 400);
        }

        // Verificar que el usuario existe y obtener su teléfono
        $stmt = $conn->prepare("SELECT password_hash, phone, phone_verified FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($current, $row['password_hash'])) {
            usersJson(['status' => 'error', 'message' => 'Contraseña actual incorrecta'], 401);
        }

        // (bloque OTP eliminado)

        $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        $upd = $conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $upd->execute([$newHash, $userId]);

        usersJson(['status' => 'ok', 'message' => 'Contraseña actualizada correctamente']);
    } catch (Throwable $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// GET /users/me/photo (kept for compatibility)
// ============================================================================
if ($cleanPath === '/users/me/photo' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("SELECT profile_photo_url FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        usersJson(['status' => 'ok', 'photo_url' => $row['profile_photo_url'] ?? null]);
    } catch (Throwable $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}
