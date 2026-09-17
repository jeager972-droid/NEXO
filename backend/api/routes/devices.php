<?php
/**
 * =============================================================================
 * routes/devices.php — Gestión de dispositivos EDGE (biométricos/M2M).
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone endpoints para administrar dispositivos edge, enviarles comandos y
 * recibir heartbeats/polling:
 *   - GET  /devices          : listar dispositivos de la escuela.
 *   - POST /devices          : registrar dispositivo y generar token raw.
 *   - DELETE /devices/{id}   : revocar dispositivo.
 *   - POST /devices/command/{id} : enviar comando vía MQTT (fallback Redis).
 *   - GET  /devices/commands : edge polling de comandos pendientes.
 *   - POST /devices/ping     : heartbeat del edge.
 *   - GET  /admin/devices    : health check global (RECTOR).
 *
 * NOTA IMPORTANTE
 * ---------------
 * El token raw generado en POST /devices se entrega al administrador para
 * configurar el edge. El edge usa X-Device-Token para autenticar /devices/commands
 * y /devices/ping. La validación se realiza con password_verify contra token_hash.
 *
 * USO DE REDIS AQUÍ
 * -----------------
 * Redis actúa como fallback para encolar comandos a dispositivos edge cuando
 * MQTT no está disponible (device:{id}:commands). En el flujo normal el comando
 * se envía por MQTT; solo si falla se encola en Redis para que el edge lo recoja
 * mediante /devices/commands (rPop). Esto minimiza el uso de Redis al ser una
 * vía de contingencia, no el camino principal.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación, roles, getRedisConnection.
 *   - mqtt_publisher.php (opcional) : publishDeviceCommand si existe.
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Frontend: panel de dispositivos.
 *   - Edge devices: endpoints /devices/commands y /devices/ping.
 */

global $cleanPath, $conn, $method, $input;
require_once __DIR__ . '/_auth_middleware.php';
require_once __DIR__ . '/../workers/contingency_lib.php';

if (!function_exists('logUserCommand')) {
    /** Registra un comando administrativo en user_commands (auditoría). */
    function logUserCommand($conn, $schoolId, $userId, $action, $payload = []) {
        try {
            $conn->prepare("
                INSERT INTO user_commands (command_id, school_id, executed_by_user_id, command_type, command_payload, executed_at, metadata_json)
                VALUES (uuid_generate_v4(), ?, ?, ?, ?::jsonb, NOW(), ?::jsonb)
            ")->execute([
                $schoolId, $userId, strtoupper($action),
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                json_encode(['source' => 'webapp'], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Exception $e) {
            securityLog('USER_COMMAND_LOG_ERROR', $e->getMessage());
        }
    }
}

// ============================================================================
// WORKER DE REVOCACIÓN AUTOMÁTICA (lazy execution)
// Se ejecuta en cada petición a /devices/* sin necesidad de cron.
// Procesa revocaciones pendientes cuyo executes_at ya venció.
// ============================================================================
if ($conn && strpos($cleanPath, '/devices') === 0) {
    try {
        $pendingStmt = $conn->query("
            SELECT r.revocation_id, r.device_id, r.school_id, r.requested_by
            FROM sensor_revocation_requests r
            WHERE r.completed = FALSE AND r.cancelled = FALSE AND r.executes_at <= NOW()
            LIMIT 5
        ");
        $pendingRevocations = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pendingRevocations as $rev) {
            $revId = $rev['revocation_id'];
            $devId = $rev['device_id'];
            $schId = $rev['school_id'];

            // Obtener info del device antes de eliminar
            $devStmt = $conn->prepare("SELECT device_name, location FROM edge_devices WHERE device_id = ?");
            $devStmt->execute([$devId]);
            $devInfo = $devStmt->fetch(PDO::FETCH_ASSOC);
            $devName = $devInfo ? $devInfo['device_name'] : 'Sensor';
            $devLocation = $devInfo ? $devInfo['location'] : '';

            // FIX: Eliminar la revocación ANTES del device para evitar FK violation (23503).
            // Sin ON DELETE CASCADE, no se puede borrar edge_devices mientras sensor_revocation_requests lo referencia.
            $conn->prepare("DELETE FROM sensor_revocation_requests WHERE revocation_id = ?")
                ->execute([$revId]);

            // Eliminar el dispositivo
            $conn->prepare("DELETE FROM edge_devices WHERE device_id = ? AND school_id = ?")
                ->execute([$devId, $schId]);

            // Notificar a RECTOR y COORDINATOR
            $notifyRoles = ['RECTOR', 'COORDINATOR'];
            $placeholders = implode(',', array_fill(0, count($notifyRoles), '?'));
            $nStmt = $conn->prepare("
                SELECT user_id FROM users
                WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($placeholders)) AND active = TRUE
            ");
            $nStmt->execute(array_merge([$schId], $notifyRoles));
            $recipients = $nStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($recipients)) {
                $meta = json_encode([
                    'action' => 'sensor_eliminado',
                    'device_id' => $devId,
                    'device_name' => $devName,
                    'location' => $devLocation,
                    'auto_revoked' => true,
                ], JSON_UNESCAPED_UNICODE);

                $rows = [];
                $params = [];
                $notifMessage = "Se eliminó el sensor \"{$devName}\"" . ($devLocation ? " ({$devLocation})" : "") . " tras completarse el tiempo de espera.";
                foreach ($recipients as $r) {
                    $rows[] = "(?, ?, 'Sensor eliminado', ?, 'WARNING', ?::jsonb, NOW())";
                    $params[] = $schId;
                    $params[] = $r['user_id'];
                    $params[] = $notifMessage;
                    $params[] = $meta;
                }
                $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
                try {
                    $conn->prepare($sql)->execute($params);
                } catch (Throwable $e) {
                    error_log("[DEVICES] Auto-revocation notification error: " . $e->getMessage());
                }
            }

            securityLog('SENSOR_AUTO_REVOKED', "Device: $devId, School: $schId, Revocation: $revId");
        }
    } catch (Throwable $e) {
        error_log("[DEVICES] Revocation worker error: " . $e->getMessage());
    }
}

if ($cleanPath === '/devices' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $currentYear = (int)date('Y');
    try {
        // Mostrar todos los sensores activos de la escuela (incluye manuales y auto-creados)
        $stmt = $conn->prepare("
            SELECT ed.device_id, ed.device_name, ed.location, ed.active, ed.configured,
                   ed.last_ping, ed.created_at, ed.group_id, ed.assigned_user_id,
                   ed.app_version,
                   ag.group_name, ag.grade_level,
                   u.first_name AS assigned_user_first_name,
                   u.last_name AS assigned_user_last_name,
                   r.role_name AS assigned_user_role
            FROM edge_devices ed
            LEFT JOIN academic_groups ag ON ed.group_id = ag.group_id
            LEFT JOIN users u ON ed.assigned_user_id = u.user_id
            LEFT JOIN roles r ON u.role_id = r.role_id
            WHERE ed.school_id = ?
              AND ed.active = TRUE
            ORDER BY ed.configured ASC,
                     CASE WHEN ag.grade_level IS NULL THEN 99 ELSE ag.grade_level::INT END ASC,
                     ag.group_name ASC, ed.created_at DESC
        ");
        $stmt->execute([$authUser['school_id']]);
        echo json_encode(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        error_log("[DEVICES] GET /devices error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al cargar los sensores. Verifica que la base de datos esté actualizada.']);
    }
    exit;
}

if ($cleanPath === '/devices' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $name = trim($input['name'] ?? '');
    $location = trim($input['location'] ?? '');
    $groupId = trim((string)($input['group_id'] ?? ''));
    $assignedUserId = trim((string)($input['assigned_user_id'] ?? ''));

    try {
        $validAssignedUserId = null;
        if ($assignedUserId !== '') {
            if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $assignedUserId)) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Formato de ID de usuario inválido']));
            }
            $userStmt = $conn->prepare("SELECT 1 FROM users WHERE user_id = ? AND school_id = ? AND deleted_at IS NULL");
            $userStmt->execute([$assignedUserId, $authUser['school_id']]);
            if (!$userStmt->fetchColumn()) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'El usuario no pertenece a tu institución']));
            }
            $validAssignedUserId = $assignedUserId;
        }

        if (empty($name)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'El nombre es obligatorio']));
        }

        // Validar group_id si viene en el body
        $validGroupId = null;
        if ($groupId !== '') {
            if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $groupId)) {
                http_response_code(400);
                exit(json_encode(['status' => 'error', 'message' => 'Formato de ID de grupo inválido']));
            }
            $groupStmt = $conn->prepare("SELECT 1 FROM academic_groups WHERE group_id = ? AND school_id = ?");
            $groupStmt->execute([$groupId, $authUser['school_id']]);
            if (!$groupStmt->fetchColumn()) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'El grupo no pertenece a tu institución']));
            }
            $validGroupId = $groupId;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_BCRYPT);
        $otaKey   = bin2hex(random_bytes(32)); // clave OTA por-dispositivo (Bloque D)

        // Sensores registrados manualmente nacen configurados (tienen token).
        // Solo los sensores auto-creados por grupos nacen sin configurar.
        $stmt = $conn->prepare("INSERT INTO edge_devices (school_id, device_name, location, token_hash, ota_key, group_id, assigned_user_id, configured) VALUES (?, ?, ?, ?, ?, ?, ?, TRUE) RETURNING device_id");
        $stmt->execute([$authUser['school_id'], $name, $location, $tokenHash, $otaKey, $validGroupId, $validAssignedUserId]);
        $deviceId = $stmt->fetchColumn();

        // Pasando el ID del usuario y escuela para trazabilidad
        securityLog('EDGE_DEVICE_REGISTERED', "Device: $deviceId, Name: $name", $authUser['id'], $authUser['school_id']);

        // Notificar a RECTOR y COORDINATOR
        $notifyRoles = ['RECTOR', 'COORDINATOR'];
        $placeholders = implode(',', array_fill(0, count($notifyRoles), '?'));
        $nStmt = $conn->prepare("
            SELECT user_id FROM users
            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($placeholders)) AND active = TRUE
        ");
        $nStmt->execute(array_merge([$authUser['school_id']], $notifyRoles));
        $recipients = $nStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($recipients)) {
            $userName = $authUser['nombre'] ?? $authUser['email'];
            $meta = json_encode([
                'action' => 'sensor_configurado',
                'device_id' => $deviceId,
                'device_name' => $name,
                'location' => $location,
                'configured_by' => $authUser['id'],
                'configured_by_name' => $userName,
            ], JSON_UNESCAPED_UNICODE);

            $rows = [];
            $params = [];
            $notifMessage = "Se configuró el sensor \"{$name}\"" . ($location ? " en {$location}" : "") . ".";
            foreach ($recipients as $r) {
                $rows[] = "(?, ?, 'Sensor configurado', ?, 'INFO', ?::jsonb, NOW())";
                $params[] = $authUser['school_id'];
                $params[] = $r['user_id'];
                $params[] = $notifMessage;
                $params[] = $meta;
            }
            $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
            try {
                $conn->prepare($sql)->execute($params);
            } catch (Throwable $e) {
                error_log("[DEVICES] Register notification error: " . $e->getMessage());
            }
        }

        echo json_encode([
            'status' => 'ok',
            'data' => ['device_id' => $deviceId, 'name' => $name, 'token' => $rawToken, 'ota_key' => $otaKey]
        ]);
    } catch (PDOException $e) {
        error_log("[DEVICES] POST /devices error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al registrar el sensor. Verifica que la base de datos esté actualizada.']);
    }
    exit;
}

// ============================================================================
// POST /devices/{id}/configure — Marcar sensor como configurado y generar token
// ============================================================================
if (preg_match('#^/devices/([0-9a-fA-F\-]+)/configure$#', $cleanPath, $matches) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $deviceId = $matches[1];

    if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $deviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Formato de ID de dispositivo inválido']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Verificar que el device pertenece a la escuela
        $ownerStmt = $conn->prepare("SELECT token_hash FROM edge_devices WHERE device_id = ? AND school_id = ?");
        $ownerStmt->execute([$deviceId, $authUser['school_id']]);
        $existingTokenHash = $ownerStmt->fetchColumn();
        if ($existingTokenHash === false) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Sensor no encontrado']));
        }

        // Generar token si no tiene uno (sensores auto-creados tienen token_hash = NULL)
        $rawToken = null;
        if (empty($existingTokenHash)) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = password_hash($rawToken, PASSWORD_BCRYPT);
            $newOtaKey = bin2hex(random_bytes(32));
            $conn->prepare("UPDATE edge_devices SET configured = TRUE, token_hash = ?, ota_key = ? WHERE device_id = ? AND school_id = ?")
                ->execute([$tokenHash, $newOtaKey, $deviceId, $authUser['school_id']]);
        } else {
            // Ya tiene token (registrado manualmente), solo marcar como configurado
            $conn->prepare("UPDATE edge_devices SET configured = TRUE WHERE device_id = ? AND school_id = ?")
                ->execute([$deviceId, $authUser['school_id']]);
        }

        securityLog('EDGE_DEVICE_CONFIGURED', "Device: $deviceId, By: {$authUser['id']}", $authUser['id'], $authUser['school_id']);

        // Notificar a RECTOR y COORDINATOR
        $devNameStmt = $conn->prepare("SELECT device_name, location FROM edge_devices WHERE device_id = ?");
        $devNameStmt->execute([$deviceId]);
        $devNameRow = $devNameStmt->fetch(PDO::FETCH_ASSOC);
        $devName = $devNameRow ? $devNameRow['device_name'] : 'Sensor';
        $devLoc = $devNameRow ? ($devNameRow['location'] ?? '') : '';

        $notifyRoles = ['RECTOR', 'COORDINATOR'];
        $placeholders = implode(',', array_fill(0, count($notifyRoles), '?'));
        $nStmt = $conn->prepare("
            SELECT user_id FROM users
            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($placeholders)) AND active = TRUE
        ");
        $nStmt->execute(array_merge([$authUser['school_id']], $notifyRoles));
        $recipients = $nStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($recipients)) {
            $userName = $authUser['nombre'] ?? $authUser['email'];
            $meta = json_encode([
                'action' => 'sensor_configurado',
                'device_id' => $deviceId,
                'device_name' => $devName,
                'location' => $devLoc,
                'configured_by' => $authUser['id'],
                'configured_by_name' => $userName,
            ], JSON_UNESCAPED_UNICODE);

            $rows = [];
            $params = [];
            $notifMessage = "Se configuró el sensor \"{$devName}\"" . ($devLoc ? " en {$devLoc}" : "") . ".";
            foreach ($recipients as $r) {
                $rows[] = "(?, ?, 'Sensor configurado', ?, 'INFO', ?::jsonb, NOW())";
                $params[] = $authUser['school_id'];
                $params[] = $r['user_id'];
                $params[] = $notifMessage;
                $params[] = $meta;
            }
            $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
            try {
                $conn->prepare($sql)->execute($params);
            } catch (Throwable $e) {
                error_log("[DEVICES] Configure notification error: " . $e->getMessage());
            }
        }

        echo json_encode([
            'status' => 'ok',
            'message' => 'Sensor configurado correctamente',
            'device_id' => $deviceId,
            'token' => $rawToken, // null si ya tenía token, string si se generó uno nuevo
            'ota_key' => $newOtaKey ?? null, // null si ya tenía token (se genera al provisionar)
        ]);
    } catch (Exception $e) {
        securityLog('EDGE_DEVICE_CONFIGURE_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al configurar el sensor']);
    }
    exit;
}

if (preg_match('#^/devices/([0-9a-fA-F\-]+)$#', $cleanPath, $matches) && $method === 'DELETE') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $deviceId = $matches[1];

    // Validación estricta de UUID para prevenir errores en PostgreSQL
    if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $deviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Formato de ID de dispositivo inválido']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Obtener info del device antes de eliminar
        $devStmt = $conn->prepare("SELECT device_name, location FROM edge_devices WHERE device_id = ? AND school_id = ?");
        $devStmt->execute([$deviceId, $authUser['school_id']]);
        $devInfo = $devStmt->fetch(PDO::FETCH_ASSOC);

        if (!$devInfo) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Sensor no encontrado']));
        }

        $deviceName = $devInfo['device_name'];
        $location = $devInfo['location'] ?? '';
        $userName = $authUser['nombre'] ?? $authUser['email'];
        $userRole = strtoupper($authUser['role'] ?? '');

        // FIX: Eliminar revocaciones pendientes antes del device para evitar FK violation (23503)
        $conn->prepare("DELETE FROM sensor_revocation_requests WHERE device_id = ? AND school_id = ?")
            ->execute([$deviceId, $authUser['school_id']]);

        // Hard delete
        $conn->prepare("DELETE FROM edge_devices WHERE device_id = ? AND school_id = ?")
            ->execute([$deviceId, $authUser['school_id']]);

        // Notificar a todos los RECTOR y COORDINATOR de la escuela
        $notifyRoles = ['RECTOR', 'COORDINATOR'];
        $placeholders = implode(',', array_fill(0, count($notifyRoles), '?'));
        $nStmt = $conn->prepare("
            SELECT user_id FROM users
            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($placeholders)) AND active = TRUE
        ");
        $nStmt->execute(array_merge([$authUser['school_id']], $notifyRoles));
        $recipients = $nStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($recipients)) {
            $meta = json_encode([
                'action' => 'sensor_eliminado',
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'location' => $location,
                'deleted_by' => $authUser['id'],
                'deleted_by_name' => $userName,
                'deleted_by_role' => $userRole,
            ], JSON_UNESCAPED_UNICODE);

            $rows = [];
            $params = [];
            $notifMessage = "Se eliminó el sensor \"{$deviceName}\"" . ($location ? " ({$location})" : "") . ".";
            foreach ($recipients as $r) {
                $rows[] = "(?, ?, 'Sensor eliminado', ?, 'WARNING', ?::jsonb, NOW())";
                $params[] = $authUser['school_id'];
                $params[] = $r['user_id'];
                $params[] = $notifMessage;
                $params[] = $meta;
            }
            $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
            try {
                $conn->prepare($sql)->execute($params);
            } catch (Throwable $e) {
                error_log("[DEVICES] Delete notification error: " . $e->getMessage());
            }
        }

        securityLog('EDGE_DEVICE_DELETED', "Device: $deviceId, Name: $deviceName, By: {$authUser['id']}", $authUser['id'], $authUser['school_id']);

        echo json_encode(['status' => 'ok', 'message' => 'Sensor eliminado']);
    } catch (Exception $e) {
        securityLog('EDGE_DEVICE_DELETE_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al eliminar el sensor']);
    }
    exit;
}

// ============================================================================
// POST /devices/{id}/revocation — Iniciar revocación con countdown de 1 hora
// ============================================================================
if (preg_match('#^/devices/([0-9a-fA-F\-]+)/revocation$#', $cleanPath, $matches) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $deviceId = $matches[1];

    if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $deviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Formato de ID de dispositivo inválido']));
    }

    $password = trim((string)($input['password'] ?? ''));
    if (empty($password)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Debe proporcionar su contraseña']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Validar password del usuario
        $userStmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ? AND deleted_at IS NULL");
        $userStmt->execute([$authUser['id']]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$userRow || !password_verify($password, $userRow['password_hash'])) {
            securityLog('REVOCATION_PASSWORD_FAIL', "User: {$authUser['id']}, Device: $deviceId", $authUser['id'], $authUser['school_id']);
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Contraseña incorrecta']));
        }

        // Verificar que el device pertenece a la escuela
        $devStmt = $conn->prepare("SELECT device_name, location FROM edge_devices WHERE device_id = ? AND school_id = ?");
        $devStmt->execute([$deviceId, $authUser['school_id']]);
        $devInfo = $devStmt->fetch(PDO::FETCH_ASSOC);

        if (!$devInfo) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Sensor no encontrado']));
        }

        $deviceName = $devInfo['device_name'];
        $userName = $authUser['nombre'] ?? $authUser['email'];

        // Verificar si ya hay una revocación pendiente para este dispositivo
        $pendingStmt = $conn->prepare("
            SELECT r.revocation_id, r.executes_at
            FROM sensor_revocation_requests r
            WHERE r.device_id = ? AND r.school_id = ?
              AND r.completed = FALSE AND r.cancelled = FALSE
              AND r.executes_at > NOW()
            LIMIT 1
        ");
        $pendingStmt->execute([$deviceId, $authUser['school_id']]);
        $existingRevocation = $pendingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingRevocation) {
            http_response_code(409);
            exit(json_encode([
                'status' => 'error',
                'message' => 'Ya hay una revocación en proceso para este sensor',
                'revocation_id' => $existingRevocation['revocation_id'],
                'executes_at' => $existingRevocation['executes_at'],
            ]));
        }

        // Crear revocación con executes_at = NOW() + 1 hour
        $revStmt = $conn->prepare("
            INSERT INTO sensor_revocation_requests (revocation_id, device_id, school_id, requested_by, requested_at, executes_at)
            VALUES (uuid_generate_v4(), ?, ?, ?, NOW(), NOW() + INTERVAL '1 hour')
            RETURNING revocation_id, executes_at
        ");
        $revStmt->execute([$deviceId, $authUser['school_id'], $authUser['id']]);
        $revRow = $revStmt->fetch(PDO::FETCH_ASSOC);
        $revocationId = $revRow['revocation_id'];
        $executesAt = $revRow['executes_at'];

        // Notificar a todos los COORDINATOR y RECTOR
        $notifyRoles = ['RECTOR', 'COORDINATOR'];
        $placeholders = implode(',', array_fill(0, count($notifyRoles), '?'));
        $nStmt = $conn->prepare("
            SELECT user_id FROM users
            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($placeholders)) AND active = TRUE
        ");
        $nStmt->execute(array_merge([$authUser['school_id']], $notifyRoles));
        $recipients = $nStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($recipients)) {
            $meta = json_encode([
                'action' => 'sensor_revocacion_iniciada',
                'revocation_id' => $revocationId,
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'requested_by' => $authUser['id'],
                'requested_by_name' => $userName,
                'executes_at' => $executesAt,
            ], JSON_UNESCAPED_UNICODE);

            $rows = [];
            $params = [];
            $notifMessage = "Se inició la eliminación del sensor \"{$deviceName}\". Se completará en 1 hora. Puedes cancelar si fue un error.";
            foreach ($recipients as $r) {
                $rows[] = "(?, ?, 'Sensor en proceso de eliminación', ?, 'WARNING', ?::jsonb, NOW())";
                $params[] = $authUser['school_id'];
                $params[] = $r['user_id'];
                $params[] = $notifMessage;
                $params[] = $meta;
            }
            $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
            try {
                $conn->prepare($sql)->execute($params);
            } catch (Throwable $e) {
                error_log("[DEVICES] Revocation notification error: " . $e->getMessage());
            }
        }

        securityLog('SENSOR_REVOCATION_STARTED', "Device: $deviceId, Revocation: $revocationId, By: {$authUser['id']}", $authUser['id'], $authUser['school_id']);

        echo json_encode([
            'status' => 'ok',
            'revocation_id' => $revocationId,
            'executes_at' => $executesAt,
        ]);
    } catch (Exception $e) {
        securityLog('SENSOR_REVOCATION_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al iniciar la revocación del sensor']);
    }
    exit;
}

// ============================================================================
// POST /devices/{id}/revocation/cancel — Cancelar revocación pendiente
// ============================================================================
if (preg_match('#^/devices/([0-9a-fA-F\-]+)/revocation/cancel$#', $cleanPath, $matches) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $deviceId = $matches[1];

    if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $deviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Formato de ID de dispositivo inválido']));
    }

    $password = trim((string)($input['password'] ?? ''));
    if (empty($password)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Debe proporcionar su contraseña']));
    }

    $revocationId = trim((string)($input['revocation_id'] ?? ''));
    if (empty($revocationId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'revocation_id requerido']));
    }
    if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $revocationId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Formato de revocation_id inválido']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Validar password del usuario
        $userStmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ? AND deleted_at IS NULL");
        $userStmt->execute([$authUser['id']]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$userRow || !password_verify($password, $userRow['password_hash'])) {
            securityLog('REVOCATION_CANCEL_PASSWORD_FAIL', "User: {$authUser['id']}, Revocation: $revocationId", $authUser['id'], $authUser['school_id']);
            http_response_code(401);
            exit(json_encode(['status' => 'error', 'message' => 'Contraseña incorrecta']));
        }

        // Obtener info del device para la notificación
        $devStmt = $conn->prepare("SELECT device_name FROM edge_devices WHERE device_id = ? AND school_id = ?");
        $devStmt->execute([$deviceId, $authUser['school_id']]);
        $devInfo = $devStmt->fetch(PDO::FETCH_ASSOC);
        $deviceName = $devInfo ? $devInfo['device_name'] : 'Sensor';
        $userName = $authUser['nombre'] ?? $authUser['email'];

        // Cancelar la revocación
        $cancelStmt = $conn->prepare("
            UPDATE sensor_revocation_requests
            SET cancelled = TRUE, cancelled_by = ?, cancelled_at = NOW()
            WHERE revocation_id = ? AND completed = FALSE AND cancelled = FALSE
        ");
        $cancelStmt->execute([$authUser['id'], $revocationId]);

        if ($cancelStmt->rowCount() === 0) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Revocación no encontrada, ya completada o ya cancelada']));
        }

        // Notificar cancelación
        $notifyRoles = ['RECTOR', 'COORDINATOR'];
        $placeholders = implode(',', array_fill(0, count($notifyRoles), '?'));
        $nStmt = $conn->prepare("
            SELECT user_id FROM users
            WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) IN ($placeholders)) AND active = TRUE
        ");
        $nStmt->execute(array_merge([$authUser['school_id']], $notifyRoles));
        $recipients = $nStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($recipients)) {
            $meta = json_encode([
                'revocation_id' => $revocationId,
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'cancelled_by' => $authUser['id'],
                'cancelled_by_name' => $userName,
            ], JSON_UNESCAPED_UNICODE);

            $rows = [];
            $params = [];
            $notifMessage = "La revocación del sensor '{$deviceName}' fue cancelada por {$userName}.";
            foreach ($recipients as $r) {
                $rows[] = "(?, ?, 'Revocación cancelada', ?, 'INFO', ?::jsonb, NOW())";
                $params[] = $authUser['school_id'];
                $params[] = $r['user_id'];
                $params[] = $notifMessage;
                $params[] = $meta;
            }
            $sql = "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at) VALUES " . implode(',', $rows);
            try {
                $conn->prepare($sql)->execute($params);
            } catch (Throwable $e) {
                error_log("[DEVICES] Revocation cancel notification error: " . $e->getMessage());
            }
        }

        securityLog('SENSOR_REVOCATION_CANCELLED', "Revocation: $revocationId, By: {$authUser['id']}", $authUser['id'], $authUser['school_id']);

        echo json_encode(['status' => 'ok', 'message' => 'Revocación cancelada']);
    } catch (Exception $e) {
        securityLog('SENSOR_REVOCATION_CANCEL_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al cancelar la revocación']);
    }
    exit;
}

// ============================================================================
// GET /devices/revocations/pending — Listar revocaciones pendientes
// ============================================================================
if ($cleanPath === '/devices/revocations/pending' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $stmt = $conn->prepare("
            SELECT r.revocation_id, r.device_id, r.requested_by, r.requested_at, r.executes_at,
                   d.device_name, d.location,
                   EXTRACT(EPOCH FROM (r.executes_at - NOW()))::INTEGER as countdown_seconds,
                   u.first_name, u.last_name
            FROM sensor_revocation_requests r
            INNER JOIN edge_devices d ON r.device_id = d.device_id
            INNER JOIN users u ON r.requested_by = u.user_id
            WHERE r.school_id = ? AND r.completed = FALSE AND r.cancelled = FALSE AND r.executes_at > NOW()
            ORDER BY r.executes_at ASC
        ");
        $stmt->execute([$authUser['school_id']]);
        $revocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($r) {
            return [
                'revocation_id' => $r['revocation_id'],
                'device_id' => $r['device_id'],
                'device_name' => $r['device_name'],
                'location' => $r['location'],
                'requested_by' => $r['requested_by'],
                'requested_by_name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'requested_at' => $r['requested_at'],
                'executes_at' => $r['executes_at'],
                'countdown_seconds' => (int)$r['countdown_seconds'],
            ];
        }, $revocations);

        echo json_encode(['status' => 'ok', 'data' => $formatted]);
    } catch (Exception $e) {
        securityLog('REVOCATIONS_PENDING_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener revocaciones pendientes']);
    }
    exit;
}

// ============================================================================
// GET /devices/by-role — Obtener sensor asignado al usuario actual
// ============================================================================
// Devuelve el sensor cuyo assigned_user_id coincide con el ID del usuario
// autenticado. NO hay fallback: si el usuario no tiene sensor asignado,
// devuelve data: null para que el frontend muestre el mensaje correspondiente.
if ($cleanPath === '/devices/by-role' && $method === 'GET') {
    $authUser = requireAuth();

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $stmt = $conn->prepare("
            SELECT ed.device_id, ed.device_name, ed.location, ed.active, ed.configured,
                   ed.last_ping, ed.created_at, ed.group_id, ed.assigned_user_id,
                   ag.group_name, ag.grade_level,
                   (ed.last_ping IS NOT NULL AND ed.last_ping > NOW() - INTERVAL '2 minutes') as is_online
            FROM edge_devices ed
            LEFT JOIN academic_groups ag ON ed.group_id = ag.group_id
            WHERE ed.school_id = ?
              AND ed.active = TRUE
              AND ed.assigned_user_id = ?
            ORDER BY ed.configured DESC, ed.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$authUser['school_id'], $authUser['id']]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        // FIX: is_online viene como string de PostgreSQL, convertir a bool
        if ($device) {
            $device['is_online'] = filter_var($device['is_online'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        echo json_encode(['status' => 'ok', 'data' => $device ?: null]);
    } catch (Exception $e) {
        securityLog('DEVICE_BY_ROLE_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener sensor asignado']);
    }
    exit;
}

// Enviar comando a un dispositivo edge (M2M) — V2: MQTT Pub/Sub con Redis fallback
// SECRETARY: necesita enviar ENROLL_REQUEST para registrar huellas de estudiantes
if (preg_match('#^/devices/command/([0-9a-fA-F\-]+)$#', $cleanPath, $matches) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR', 'SECRETARY']);
    $deviceId = $matches[1];

    $ownerStmt = $conn->prepare("SELECT 1 FROM edge_devices WHERE device_id = ? AND school_id = ?");
    $ownerStmt->execute([$deviceId, $authUser['school_id']]);
    if (!$ownerStmt->fetchColumn()) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Dispositivo no pertenece a tu institución.']));
    }
    $command = trim($input['command'] ?? '');
    $payload = $input['payload'] ?? [];

    if (empty($command)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'command requerido']));
    }

    $cmdPayload = [
        'command' => $command,
        'payload' => $payload,
        'issued_at' => time(),
        'issued_by' => $authUser['id']
    ];

    // V2: Intentar MQTT primero (Pub/Sub baja latencia)
    $mqttOk = false;
    if (file_exists(__DIR__ . '/../mqtt_publisher.php')) {
        require_once __DIR__ . '/../core/mqtt_publisher.php';
        $mqttOk = publishDeviceCommand($deviceId, $cmdPayload);
    }

    // SIEMPRE encolar en Redis también (el edge usa polling HTTP, no MQTT)
    $redisOk = false;
    try {
        $redis = getRedisConnection();
        if ($redis) {
            $redis->lPush("device:{$deviceId}:commands", json_encode($cmdPayload, JSON_UNESCAPED_UNICODE));
            $redis->expire("device:{$deviceId}:commands", 86400);
            $redisOk = true;
        } else {
            securityLog('DEVICE_COMMAND_REDIS_NULL', "Redis connection returned null for device: $deviceId");
        }
    } catch (Exception $e) {
        securityLog('DEVICE_COMMAND_REDIS_ERROR', "Redis failed for device: $deviceId | MQTT was " . ($mqttOk ? 'OK' : 'FAIL') . " | Error: " . $e->getMessage());
    }

    if (!$mqttOk && !$redisOk) {
        // FALLBACK: Guardar comando en PostgreSQL si Redis y MQTT no están disponibles
        try {
            $conn->prepare("
                INSERT INTO device_commands (device_id, command, payload, issued_at, issued_by)
                VALUES (?, ?, ?::jsonb, ?, ?)
            ")->execute([
                $deviceId,
                $command,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                time(),
                $authUser['id']
            ]);
            securityLog('DEVICE_COMMAND_PG_FALLBACK', "Device: $deviceId Command: $command saved to PG (Redis+MQTT unavailable)");
            echo json_encode(['status' => 'ok', 'device_id' => $deviceId, 'command' => $command, 'channel' => 'PG_FALLBACK']);
            exit;
        } catch (Exception $pgErr) {
            securityLog('DEVICE_COMMAND_PG_FALLBACK_ERROR', "PG fallback failed: " . $pgErr->getMessage());
            http_response_code(500);
            exit(json_encode(['status' => 'error', 'message' => 'No se pudo encolar el comando (MQTT, Redis y PG fallback no disponibles)']));
        }
    }

    $channel = $mqttOk ? ($redisOk ? 'MQTT+REDIS' : 'MQTT') : 'REDIS';
    securityLog('DEVICE_COMMAND_ISSUED', "Device: $deviceId Command: $command Channel: $channel", $authUser['id'], $authUser['school_id']);
    echo json_encode(['status' => 'ok', 'device_id' => $deviceId, 'command' => $command, 'channel' => $channel]);
    exit;
}

// Edge polling: recoger comandos pendientes
if ($cleanPath === '/devices/commands' && $method === 'GET') {
    $deviceId = $_GET['device_id'] ?? '';
    if (empty($deviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'device_id requerido']));
    }

    // Validar token del dispositivo (obligatorio)
    $deviceToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
    if (empty($deviceToken)) {
        http_response_code(401);
        exit(json_encode(['status' => 'error', 'message' => 'X-Device-Token requerido']));
    }

    // FIX (PgBouncer): Supabase pierde conexiones del pool intermitentemente.
    // Retry hasta 3 veces para manejar "AUTH failed while reconnecting".
    $maxRetries = 3;
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            // Rollback si quedó una transacción abortada de un intento anterior
            if ($conn->inTransaction()) {
                try { $conn->rollBack(); } catch (Exception $ignore) {}
            }

            $conn->beginTransaction();

            $conn->exec("SELECT set_config('app.current_role', 'EDGE_NODE', true)");
            $stmt = $conn->prepare("SELECT school_id, token_hash FROM edge_devices WHERE device_id = ? LIMIT 1");
            $stmt->execute([$deviceId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$device) {
                $conn->rollBack();
                securityLog('DEVICE_LOOKUP_FAILED', "Device not found: $deviceId (attempt $attempt)");
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
            }
            if (!password_verify($deviceToken, $device['token_hash'])) {
                $conn->rollBack();
                securityLog('DEVICE_TOKEN_MISMATCH', "Token mismatch for device: $deviceId (attempt $attempt)");
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
            }

            $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$device['school_id']) . ", true), set_config('app.current_role', 'EDGE_NODE', true)");

            // Actualizar last_ping al recibir poll de comandos
            $conn->prepare("UPDATE edge_devices SET last_ping = NOW() WHERE device_id = ?")
                ->execute([$deviceId]);

            $conn->commit();

            // Redis best-effort: si falla, usar fallback de PostgreSQL
            $commands = [];
            try {
                $redis = getRedisConnection();
                if ($redis) {
                    $queue = "device:{$deviceId}:commands";
                    while (($item = $redis->rPop($queue)) !== false) {
                        $cmd = json_decode($item, true);
                        if ($cmd) $commands[] = $cmd;
                    }
                }
            } catch (Exception $redisErr) {
                securityLog('DEVICE_COMMANDS_REDIS_DOWN', "Redis unavailable, trying PG fallback: " . $redisErr->getMessage());
            }

            // FALLBACK: Leer comandos pendientes de PostgreSQL si Redis no los entregó
            if (empty($commands)) {
                try {
                    $pgStmt = $conn->prepare("
                        SELECT command_id, command, payload, issued_at, issued_by
                        FROM device_commands
                        WHERE device_id = ? AND delivered_at IS NULL
                        ORDER BY command_id ASC
                        LIMIT 10
                    ");
                    $pgStmt->execute([$deviceId]);
                    $pgCommands = $pgStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($pgCommands)) {
                        $deliveredIds = [];
                        foreach ($pgCommands as $pgCmd) {
                            $commands[] = [
                                'command' => $pgCmd['command'],
                                'payload' => json_decode($pgCmd['payload'], true) ?? [],
                                'issued_at' => (int)$pgCmd['issued_at'],
                                'issued_by' => $pgCmd['issued_by'],
                            ];
                            $deliveredIds[] = $pgCmd['command_id'];
                        }
                        // Marcar como entregados
                        $deliveredPlaceholders = implode(',', array_fill(0, count($deliveredIds), '?'));
                        $conn->prepare("UPDATE device_commands SET delivered_at = NOW() WHERE command_id IN ($deliveredPlaceholders)")
                            ->execute($deliveredIds);
                        securityLog('DEVICE_COMMANDS_PG_DELIVERED', "Delivered " . count($commands) . " commands from PG fallback to device: $deviceId");
                    }
                } catch (Exception $pgErr) {
                    securityLog('DEVICE_COMMANDS_PG_FALLBACK_ERROR', "PG fallback read failed: " . $pgErr->getMessage());
                }
            }

            echo json_encode([
                'status' => 'ok',
                'data' => $commands,
                'meta' => ['count' => count($commands)]
            ]);
            exit;

        } catch (Exception $e) {
            $lastError = $e->getMessage();
            try { if ($conn->inTransaction()) $conn->rollBack(); } catch (Exception $ignore) {}
            securityLog('DEVICE_COMMANDS_RETRY', "Attempt $attempt failed: $lastError | DeviceID: $deviceId");
            if ($attempt < $maxRetries) {
                usleep(200000); // 200ms entre retries
            }
        }
    }

    securityLog('DEVICE_COMMANDS_FETCH_ERROR', "All $maxRetries attempts failed: $lastError | DeviceID: $deviceId");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error al obtener comandos', 'debug' => $lastError]);
    exit;
}

// Heartbeat endpoint para el edge (autenticado por X-Device-Token)
if ($cleanPath === '/devices/ping' && $method === 'POST') {
    $deviceId = $input['device_id'] ?? '';
    $timestamp = $input['timestamp'] ?? 0;
    $status = $input['status'] ?? 'unknown';

    if (empty($deviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'device_id requerido']));
    }

    // Validar token del dispositivo (obligatorio)
    $deviceToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
    if (empty($deviceToken)) {
        http_response_code(401);
        exit(json_encode(['status' => 'error', 'message' => 'X-Device-Token requerido']));
    }

    // FIX (PgBouncer): Retry hasta 3 veces para manejar "AUTH failed while reconnecting"
    $maxRetries = 3;
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            if ($conn->inTransaction()) {
                try { $conn->rollBack(); } catch (Exception $ignore) {}
            }

            $conn->beginTransaction();

            $conn->exec("SELECT set_config('app.current_role', 'EDGE_NODE', true)");
            $stmt = $conn->prepare("SELECT school_id, token_hash FROM edge_devices WHERE device_id = ? LIMIT 1");
            $stmt->execute([$deviceId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$device) {
                $conn->rollBack();
                securityLog('DEVICE_PING_LOOKUP_FAILED', "Device not found: $deviceId (attempt $attempt)");
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
            }
            if (!password_verify($deviceToken, $device['token_hash'])) {
                $conn->rollBack();
                securityLog('DEVICE_PING_TOKEN_MISMATCH', "Token mismatch for device: $deviceId (attempt $attempt)");
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
            }

            $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$device['school_id']) . ", true), set_config('app.current_role', 'EDGE_NODE', true)");

            $stmt = $conn->prepare("UPDATE edge_devices SET last_ping = NOW() WHERE device_id = ?");
            $stmt->execute([$deviceId]);

            if ($stmt->rowCount() === 0) {
                $conn->rollBack();
                securityLog('DEVICE_PING_UPDATE_FAILED', "UPDATE 0 rows: $deviceId (attempt $attempt)");
                http_response_code(404);
                exit(json_encode(['status' => 'error', 'message' => 'Dispositivo no encontrado']));
            }

            // F-06/F-09/F-10/F-13: telemetría del nodo — persistir + evaluar umbrales.
            // Nunca bloquea el ping: un error de telemetría no debe tumbar el heartbeat.
            $telemetry = $input['telemetry'] ?? null;
            if (is_array($telemetry) && !empty($telemetry)) {
                try {
                    ctProcessTelemetry($conn, (string)$device['school_id'], $deviceId, $telemetry);
                } catch (Exception $e) {
                    securityLog('DEVICE_TELEMETRY_ERROR', "Device $deviceId: " . $e->getMessage());
                }
            }

            // V-493/V-494/V-495: comparar el reloj reportado por el nodo con la
            // hora del servidor y ordenar resincronización si excede el umbral.
            $nowTs = time();
            $drift = abs($nowTs - (int)$timestamp);
            if (is_array($telemetry) && isset($telemetry['clock_drift_s'])) {
                $drift = max($drift, abs((int)$telemetry['clock_drift_s']));
            }
            $resyncThreshold = (int)(getenv('CLOCK_RESYNC_THRESHOLD_S') ?: 300);
            $resp = ['status' => 'ok', 'received_at' => $nowTs, 'server_time' => $nowTs];
            if ($drift > $resyncThreshold) {
                $resp['resync_required'] = true;
                $resp['clock_drift_s'] = $drift;
                securityLog('DEVICE_CLOCK_DRIFT', "Device $deviceId drift={$drift}s > {$resyncThreshold}s — resync ordenado");
            }

            // V-183/V-196: publicar las franjas horarias de la jornada del nodo
            // para que el edge clasifique PUNTUAL/MANANA/TARDE con la config
            // real del colegio, no con constantes.
            try {
                $schedStmt = $conn->prepare("
                    SELECT ssc.entry_time, ssc.exit_time
                    FROM edge_devices ed
                    LEFT JOIN academic_groups ag ON ag.group_id = ed.group_id
                    LEFT JOIN school_schedule_config ssc
                      ON ssc.school_id = ed.school_id
                     AND ssc.work_shift = COALESCE(ag.work_shift, 'mañana')
                    WHERE ed.device_id = ?
                    LIMIT 1
                ");
                $schedStmt->execute([$deviceId]);
                $sc = $schedStmt->fetch(PDO::FETCH_ASSOC);
                if ($sc && !empty($sc['entry_time'])) {
                    $toMin = fn($t) => ((int)substr($t, 0, 2)) * 60 + (int)substr($t, 3, 2);
                    $entryMin = $toMin($sc['entry_time']);
                    $exitMin = !empty($sc['exit_time']) ? $toMin($sc['exit_time']) : 960;
                    $resp['schedule'] = [
                        'sched_punctual_start'  => max(0, $entryMin - 20),
                        'sched_punctual_end'    => $entryMin,
                        'sched_morning_end'     => 660,
                        'sched_afternoon_start' => 690,
                        'sched_afternoon_end'   => $exitMin,
                    ];
                }
            } catch (Exception $e) {
                securityLog('DEVICE_SCHEDULE_PUSH_ERROR', "Device $deviceId: " . $e->getMessage());
            }

            $conn->commit();

            echo json_encode($resp);
            exit;

        } catch (Exception $e) {
            $lastError = $e->getMessage();
            try { if ($conn->inTransaction()) $conn->rollBack(); } catch (Exception $ignore) {}
            securityLog('DEVICE_PING_RETRY', "Attempt $attempt failed: $lastError | DeviceID: $deviceId");
            if ($attempt < $maxRetries) {
                usleep(200000);
            }
        }
    }

    securityLog('HEARTBEAT_ERROR', "All $maxRetries attempts failed: $lastError | DeviceID: $deviceId");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error al procesar heartbeat', 'debug' => $lastError]);
    exit;
}

// ============================================================================
// POST /devices/enroll-confirm — Confirmar enrolamiento de huella SIN AES.
// El edge envía JSON plano con X-Device-Token header para autenticar.
// Esto evita el problema de "Integrity fail" cuando la clave AES no coincide.
// ============================================================================
if ($cleanPath === '/devices/enroll-confirm' && $method === 'POST') {
    $deviceToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
    if (empty($deviceToken)) {
        http_response_code(401);
        exit(json_encode(['status' => 'error', 'message' => 'X-Device-Token requerido']));
    }

    $requestDeviceId = trim($input['device_id'] ?? '');
    $doc = trim($input['doc'] ?? '');
    $nombre = trim($input['nombre'] ?? '');
    $huellaId = isset($input['huella_id']) ? (int)$input['huella_id'] : null;
    // F-03: dedo enrolado (1=principal, 2=respaldo). Default 1 para compat.
    $fingerSlot = isset($input['finger_slot']) ? (int)$input['finger_slot'] : 1;
    if (!in_array($fingerSlot, [1, 2], true)) $fingerSlot = 1;

    if (empty($requestDeviceId) || empty($doc)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'device_id y doc son requeridos']));
    }

    try {
        // Validar device token
        $stmt = $conn->prepare("SELECT school_id, token_hash FROM edge_devices WHERE device_id = ? AND active = TRUE");
        $stmt->execute([$requestDeviceId]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$device || !password_verify($deviceToken, $device['token_hash'])) {
            securityLog('ENROLL_CONFIRM_INVALID_TOKEN', "Device: $requestDeviceId");
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
        }

        $schoolId = (string)$device['school_id'];
        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
        $conn->exec("SELECT set_config('app.current_role', 'EDGE_NODE', true)");

        // Upsert student y marcar biometric_hash.
        // FIX: No sobreescribir first_name/last_name si el estudiante ya existe
        // (fue creado via POST /students con nombre split correcto).
        // Solo setear biometric_hash y reactivar.
        $biometricHash = $huellaId !== null ? 'fp_' . $huellaId : 'fp_local';
        $upStmt = $conn->prepare("
            INSERT INTO students (school_id, document_number, first_name, last_name, active, biometric_hash)
            VALUES (?, ?, ?, '', TRUE, ?)
            ON CONFLICT (school_id, document_number)
            DO UPDATE SET active = TRUE, biometric_hash = EXCLUDED.biometric_hash
            RETURNING student_id
        ");
        $upStmt->execute([$schoolId, $doc, $nombre, $biometricHash]);
        $studentId = $upStmt->fetchColumn();

        // F-03: registrar el slot de dedo en la tabla normalizada
        $fpStmt = $conn->prepare("
            INSERT INTO student_fingerprints (student_id, school_id, finger_slot, edge_huella_id, device_id)
            VALUES (?, ?, ?, ?, ?::uuid)
            ON CONFLICT (student_id, finger_slot)
            DO UPDATE SET edge_huella_id = EXCLUDED.edge_huella_id, device_id = EXCLUDED.device_id
        ");
        $fpStmt->execute([$studentId, $schoolId, $fingerSlot, $huellaId, $requestDeviceId]);

        securityLog('EDGE_ENROLL_CONFIRMED', "doc=$doc huella_id=$huellaId slot=$fingerSlot student=$studentId school=$schoolId", null, $schoolId);

        http_response_code(200);
        echo json_encode([
            'status' => 'ok',
            'student_id' => $studentId,
            'doc' => $doc,
            'has_fingerprint' => true,
        ]);
    } catch (Exception $e) {
        securityLog('EDGE_ENROLL_CONFIRM_FAIL', $e->getMessage(), null, $device['school_id'] ?? null);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al confirmar enrolamiento']);
    }
    exit;
}

// Admin: listar dispositivos con health check
if ($cleanPath === '/admin/devices' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR']);
    $healthOnly = isset($_GET['health']) && $_GET['health'] === '1';

    try {
        if ($healthOnly) {
            // Dispositivos sin ping en los últimos 5 minutos
            $stmt = $conn->prepare("
                SELECT device_id, device_name, location, school_id, last_ping, status,
                       EXTRACT(EPOCH FROM (NOW() - last_ping))::INTEGER as seconds_since_ping
                FROM edge_devices
                WHERE last_ping < NOW() - INTERVAL '5 minutes'
                   OR last_ping IS NULL
                ORDER BY last_ping DESC NULLS LAST
            ");
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("
                SELECT device_id, device_name, location, school_id, last_ping, status,
                       EXTRACT(EPOCH FROM (NOW() - last_ping))::INTEGER as seconds_since_ping
                FROM edge_devices
                ORDER BY last_ping DESC NULLS LAST
            ");
            $stmt->execute();
        }

        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'status' => 'ok',
            'data' => $devices,
            'meta' => [
                'unhealthy_count' => count(array_filter($devices, fn($d) => ($d['seconds_since_ping'] ?? 0) > 300))
            ]
        ]);
    } catch (Exception $e) {
        securityLog('ADMIN_DEVICES_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener dispositivos']);
    }
    exit;
}

// ============================================================================
// POST /devices/{id}/reconfigure — Reconfigurar sensor eliminado
// ============================================================================
if (preg_match('#^/devices/([0-9a-fA-F\-]+)/reconfigure$#', $cleanPath, $matches) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $oldDeviceId = $matches[1];

    if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $oldDeviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Formato de ID de dispositivo inválido']));
    }

    $masterKey = trim((string)($input['master_key'] ?? ''));
    $newDeviceId = trim((string)($input['device_id'] ?? ''));
    $token = trim((string)($input['token'] ?? ''));

    if (empty($masterKey) || empty($newDeviceId) || empty($token)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'master_key, device_id y token son obligatorios']));
    }

    if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $newDeviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Formato de device_id inválido']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Validar master_key
        $schoolStmt = $conn->prepare("SELECT sensor_master_key_hash FROM schools WHERE school_id = ?");
        $schoolStmt->execute([$authUser['school_id']]);
        $schoolRow = $schoolStmt->fetch(PDO::FETCH_ASSOC);

        if (!$schoolRow || empty($schoolRow['sensor_master_key_hash'])) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Llave maestra incorrecta']));
        }

        if (!password_verify($masterKey, $schoolRow['sensor_master_key_hash'])) {
            securityLog('RECONFIGURE_MASTER_KEY_FAIL', "User: {$authUser['id']}, OldDevice: $oldDeviceId", $authUser['id'], $authUser['school_id']);
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Llave maestra incorrecta']));
        }

        // Verificar que el nuevo device_id no exista ya
        $existStmt = $conn->prepare("SELECT 1 FROM edge_devices WHERE device_id = ?");
        $existStmt->execute([$newDeviceId]);
        if ($existStmt->fetchColumn()) {
            http_response_code(409);
            exit(json_encode(['status' => 'error', 'message' => 'El sensor ya está registrado']));
        }

        // Obtener info del device original (si aún existe en logs) para nombre/location
        // Como fue eliminado, usamos valores por defecto
        $deviceName = trim((string)($input['device_name'] ?? 'Sensor reconfigurado'));
        $location = trim((string)($input['location'] ?? ''));

        $tokenHash = password_hash($token, PASSWORD_BCRYPT);

        $conn->prepare("
            INSERT INTO edge_devices (device_id, school_id, device_name, location, token_hash, configured, active)
            VALUES (?, ?, ?, ?, ?, TRUE, TRUE)
        ")->execute([$newDeviceId, $authUser['school_id'], $deviceName, $location, $tokenHash]);

        securityLog('EDGE_DEVICE_RECONFIGURED', "OldDevice: $oldDeviceId, NewDevice: $newDeviceId, By: {$authUser['id']}", $authUser['id'], $authUser['school_id']);

        echo json_encode(['status' => 'ok', 'message' => 'Sensor reconfigurado']);
    } catch (Exception $e) {
        securityLog('EDGE_DEVICE_RECONFIGURE_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al reconfigurar el sensor']);
    }
    exit;
}

// ============================================================================
// OTA M2M (Bloque D) — Actualización remota firmada por dispositivo.
// ============================================================================
require_once __DIR__ . '/../lib/ota.php';

/** Autentica dispositivo por X-Device-Token + ?device_id= ; retorna la fila. */
function otaDeviceAuth(PDO $conn): array {
    $deviceToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
    $deviceId    = $_GET['device_id'] ?? ($GLOBALS['input']['device_id'] ?? '');
    if (!$deviceToken || !$deviceId) {
        http_response_code(401);
        exit(json_encode(['status' => 'error', 'message' => 'X-Device-Token y device_id requeridos']));
    }
    $stmt = $conn->prepare("SELECT device_id, school_id, token_hash, ota_key, app_version, active FROM edge_devices WHERE device_id = ?::uuid LIMIT 1");
    $stmt->execute([$deviceId]);
    $dev = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dev || !$dev['token_hash'] || !password_verify($deviceToken, $dev['token_hash']) || !$dev['active']) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
    }
    return $dev;
}

// ── GET /devices/ota/check — el nodo pregunta por actualización ────────────
if ($cleanPath === '/devices/ota/check' && $method === 'GET') {
    $dev = otaDeviceAuth($conn);
    $current = trim((string)($_GET['version'] ?? $dev['app_version'] ?? '0.0.0'));

    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($dev['school_id']) . ", true), set_config('app.current_role', 'EDGE_NODE', true)");

    // Última actualización activa aplicable (global o de su escuela)
    $uStmt = $conn->prepare("
        SELECT update_id, version, payload_url, payload_sha256, min_version
        FROM ota_updates
        WHERE active = TRUE AND (school_id IS NULL OR school_id = ?)
        ORDER BY created_at DESC
    ");
    $uStmt->execute([$dev['school_id']]);
    $offer = null;
    while ($u = $uStmt->fetch(PDO::FETCH_ASSOC)) {
        // anti-rollback: nueva > actual, y actual >= min_version si se exige
        if (otaVersionCompare($u['version'], $current) <= 0) continue;
        if (!empty($u['min_version']) && otaVersionCompare($current, $u['min_version']) < 0) continue;
        $offer = $u;
        break;
    }

    if (!$offer) { echo json_encode(['status' => 'ok', 'update' => null]); exit; }

    $manifest = [
        'update_id' => $offer['update_id'],
        'version'   => $offer['version'],
        'url'       => $offer['payload_url'],
        'sha256'    => $offer['payload_sha256'],
    ];
    $manifest['signature'] = $dev['ota_key']
        ? otaSignManifest($manifest['version'], $manifest['sha256'], $manifest['url'], $dev['ota_key'])
        : null;

    // Registrar oferta (auditoría de despliegue)
    $conn->prepare("
        INSERT INTO ota_deployments (update_id, device_id, school_id, status, detail)
        VALUES (?, ?, ?, 'OFFERED', ?)
        ON CONFLICT (update_id, device_id) DO UPDATE SET status = 'OFFERED', updated_at = NOW()
    ")->execute([$offer['update_id'], $dev['device_id'], $dev['school_id'], "ofertada a v$current"]);

    echo json_encode(['status' => 'ok', 'update' => $manifest]);
    exit;
}

// ── POST /devices/ota/report — el nodo reporta estado del despliegue ───────
if ($cleanPath === '/devices/ota/report' && $method === 'POST') {
    $dev = otaDeviceAuth($conn);
    $updateId = $input['update_id'] ?? null;
    $status   = strtoupper(trim((string)($input['status'] ?? '')));
    $detail   = substr((string)($input['detail'] ?? ''), 0, 500);
    $allowed  = ['DOWNLOADING','STAGED','APPLYING','APPLIED','FAILED','ROLLED_BACK'];
    if (!$updateId || !in_array($status, $allowed, true)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'update_id y status válidos requeridos (' . implode(',', $allowed) . ')']));
    }

    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($dev['school_id']) . ", true), set_config('app.current_role', 'EDGE_NODE', true)");

    $conn->prepare("
        INSERT INTO ota_deployments (update_id, device_id, school_id, status, detail)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (update_id, device_id) DO UPDATE SET status = EXCLUDED.status, detail = EXCLUDED.detail, updated_at = NOW()
    ")->execute([$updateId, $dev['device_id'], $dev['school_id'], $status, $detail]);

    if ($status === 'APPLIED') {
        $vStmt = $conn->prepare("SELECT version FROM ota_updates WHERE update_id = ? LIMIT 1");
        $vStmt->execute([$updateId]);
        if ($v = $vStmt->fetchColumn()) {
            $conn->prepare("UPDATE edge_devices SET app_version = ? WHERE device_id = ?")
                 ->execute([$v, $dev['device_id']]);
        }
    }
    if (in_array($status, ['FAILED', 'ROLLED_BACK'], true)) {
        securityLog('OTA_DEPLOY_' . $status, "Device: {$dev['device_id']} Update: $updateId — $detail");
    }

    echo json_encode(['status' => 'ok']);
    exit;
}

// ── POST /devices/ota/publish — publicar nueva versión (RECTOR/COORDINATOR) ─
if ($cleanPath === '/devices/ota/publish' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    requireSchoolOnboarding($conn, $authUser['school_id'], $authUser['role'] ?? '');

    $version  = trim((string)($input['version'] ?? ''));
    $url      = trim((string)($input['payload_url'] ?? ''));
    $sha256   = strtolower(trim((string)($input['payload_sha256'] ?? '')));
    $notes    = substr((string)($input['notes'] ?? ''), 0, 500);
    $minVer   = trim((string)($input['min_version'] ?? ''));
    $global   = !empty($input['global']); // true → todas las escuelas del tenant admin? solo SUPER_ADMIN

    if (!$version || !$url || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'version, payload_url y payload_sha256 (hex 64) requeridos']));
    }
    if (otaVersionCompare($version, '0.0.0') <= 0) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'version debe ser semver positiva']));
    }
    $targetSchool = ($global && ($authUser['role'] ?? '') === 'SUPER_ADMIN') ? null : $authUser['school_id'];

    $stmt = $conn->prepare("
        INSERT INTO ota_updates (school_id, version, payload_url, payload_sha256, min_version, notes, created_by)
        VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?)
        ON CONFLICT (school_id, version) DO UPDATE
          SET payload_url = EXCLUDED.payload_url, payload_sha256 = EXCLUDED.payload_sha256,
              min_version = EXCLUDED.min_version, notes = EXCLUDED.notes, active = TRUE
        RETURNING update_id
    ");
    $stmt->execute([$targetSchool, $version, $url, $sha256, $minVer, $notes, $authUser['id']]);
    $updateId = $stmt->fetchColumn();

    securityLog('OTA_PUBLISHED', "v$version update=$updateId school=" . ($targetSchool ?? 'global'), $authUser['id'], $authUser['school_id']);
    echo json_encode(['status' => 'ok', 'update_id' => $updateId]);
    exit;
}

// ── POST /devices/ota/revoke — desactivar una versión publicada ────────────
if ($cleanPath === '/devices/ota/revoke' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $updateId = $input['update_id'] ?? null;
    if (!$updateId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'update_id requerido']));
    }
    $conn->prepare("UPDATE ota_updates SET active = FALSE WHERE update_id = ? AND (school_id = ? OR school_id IS NULL)")
         ->execute([$updateId, $authUser['school_id']]);
    securityLog('OTA_REVOKED', "update=$updateId", $authUser['id'], $authUser['school_id']);
    echo json_encode(['status' => 'ok']);
    exit;
}

// ── POST /devices/reassign — V-523/V-526/V-527: reubicación de nodo ─────────
// Cuando un nodo se reubica físicamente (otra aula, otro grupo), el contexto
// espacial debe actualizarse para que los eventos se interpreten correctamente.
if ($cleanPath === '/devices/reassign' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $deviceId = $input['device_id'] ?? null;
    $groupId = $input['group_id'] ?? null;        // null = desasignar grupo
    $classroomId = $input['classroom_id'] ?? null; // null = desasignar aula
    $reason = trim((string)($input['reason'] ?? 'Reubicación de nodo'));

    if (!$deviceId || !preg_match('/^[0-9a-f-]{36}$/i', (string)$deviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'device_id requerido']));
    }

    try {
        $devStmt = $conn->prepare("SELECT device_id, group_id, classroom_id FROM edge_devices WHERE device_id = ? AND school_id = ?");
        $devStmt->execute([$deviceId, $authUser['school_id']]);
        $dev = $devStmt->fetch(PDO::FETCH_ASSOC);
        if (!$dev) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Dispositivo no encontrado']));
        }
        if ($groupId) {
            $chk = $conn->prepare("SELECT 1 FROM academic_groups WHERE group_id = ? AND school_id = ?");
            $chk->execute([$groupId, $authUser['school_id']]);
            if (!$chk->fetchColumn()) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'El grupo no pertenece a tu institución']));
            }
        }
        if ($classroomId) {
            $chk = $conn->prepare("SELECT 1 FROM classrooms WHERE classroom_id = ? AND school_id = ?");
            $chk->execute([$classroomId, $authUser['school_id']]);
            if (!$chk->fetchColumn()) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'El aula no pertenece a tu institución']));
            }
        }

        $conn->prepare("UPDATE edge_devices SET group_id = ?, classroom_id = ? WHERE device_id = ?")
             ->execute([$groupId, $classroomId, $deviceId]);
        logUserCommand($conn, $authUser['school_id'], $authUser['id'], 'device_reassign', [
            'device_id' => $deviceId, 'from_group' => $dev['group_id'], 'to_group' => $groupId,
            'from_classroom' => $dev['classroom_id'], 'to_classroom' => $classroomId, 'reason' => $reason,
        ]);
        securityLog('DEVICE_REASSIGNED', "device=$deviceId group=$groupId classroom=$classroomId", $authUser['id'], $authUser['school_id']);
        echo json_encode(['status' => 'ok', 'message' => 'Nodo reubicado']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al reubicar el nodo']);
    }
    exit;
}

// ── POST /devices/reprovision — V-534/V-535: recuperación de nodo averiado ──
// Marca el nodo averiado como inactivo y rota su token para el reemplazo.
// Devuelve el nuevo token una sola vez; el operador lo instala en el nodo nuevo.
if ($cleanPath === '/devices/reprovision' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $deviceId = $input['device_id'] ?? null;
    $reason = trim((string)($input['reason'] ?? 'Reprovisión por falla de hardware'));

    if (!$deviceId || !preg_match('/^[0-9a-f-]{36}$/i', (string)$deviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'device_id requerido']));
    }

    try {
        $devStmt = $conn->prepare("SELECT device_id, active FROM edge_devices WHERE device_id = ? AND school_id = ?");
        $devStmt->execute([$deviceId, $authUser['school_id']]);
        $dev = $devStmt->fetch(PDO::FETCH_ASSOC);
        if (!$dev) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Dispositivo no encontrado']));
        }

        // Rotar credenciales: nuevo token + nueva clave OTA. El token viejo
        // queda invalidado (un nodo clonado/averiado ya no puede autenticarse).
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_BCRYPT);
        $otaKey = bin2hex(random_bytes(32));
        $conn->prepare("UPDATE edge_devices SET token_hash = ?, ota_key = ?, configured = FALSE, status = 'reprovisioned', last_ping = NULL WHERE device_id = ?")
             ->execute([$tokenHash, $otaKey, $deviceId]);

        logUserCommand($conn, $authUser['school_id'], $authUser['id'], 'device_reprovision', [
            'device_id' => $deviceId, 'reason' => $reason,
        ]);
        securityLog('DEVICE_REPROVISIONED', "device=$deviceId", $authUser['id'], $authUser['school_id']);
        echo json_encode(['status' => 'ok', 'device_id' => $deviceId, 'device_token' => $rawToken]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al reprovisionar el nodo']);
    }
    exit;
}
