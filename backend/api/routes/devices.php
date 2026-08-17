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

            // Eliminar el dispositivo
            $conn->prepare("DELETE FROM edge_devices WHERE device_id = ? AND school_id = ?")
                ->execute([$devId, $schId]);

            // Marcar revocación como completada
            $conn->prepare("UPDATE sensor_revocation_requests SET completed = TRUE, completed_at = NOW() WHERE revocation_id = ?")
                ->execute([$revId]);

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
                    'revocation_id' => $revId,
                    'device_id' => $devId,
                    'device_name' => $devName,
                    'location' => $devLocation,
                    'auto_revoked' => true,
                ], JSON_UNESCAPED_UNICODE);

                $rows = [];
                $params = [];
                foreach ($recipients as $r) {
                    $rows[] = "(?, ?, 'Sensor revocado', ?, 'WARNING', ?::jsonb, NOW())";
                    $params[] = $schId;
                    $params[] = $r['user_id'];
                    $params[] = "El sensor '{$devName}' ha sido revocado automáticamente.";
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
    $stmt = $conn->prepare("
        SELECT ed.device_id, ed.device_name, ed.location, ed.active, ed.configured,
               ed.last_ping, ed.created_at, ed.group_id,
               ag.group_name, ag.grade_level
        FROM edge_devices ed
        LEFT JOIN academic_groups ag ON ed.group_id = ag.group_id
        WHERE ed.school_id = ?
        ORDER BY ed.configured ASC, ag.grade_level ASC, ag.group_name ASC, ed.created_at DESC
    ");
    $stmt->execute([$authUser['school_id']]);
    echo json_encode(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($cleanPath === '/devices' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $name = trim($input['name'] ?? '');
    $location = trim($input['location'] ?? '');
    $groupId = trim((string)($input['group_id'] ?? ''));

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

    $stmt = $conn->prepare("INSERT INTO edge_devices (school_id, device_name, location, token_hash, group_id) VALUES (?, ?, ?, ?, ?) RETURNING device_id");
    $stmt->execute([$authUser['school_id'], $name, $location, $tokenHash, $validGroupId]);
    $deviceId = $stmt->fetchColumn();

    // Pasando el ID del usuario y escuela para trazabilidad
    securityLog('EDGE_DEVICE_REGISTERED', "Device: $deviceId, Name: $name", $authUser['id'], $authUser['school_id']);

    echo json_encode([
        'status' => 'ok',
        'data' => ['device_id' => $deviceId, 'name' => $name, 'token' => $rawToken]
    ]);
    exit;
}

// ============================================================================
// POST /devices/{id}/configure — Marcar sensor como configurado
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
        $ownerStmt = $conn->prepare("SELECT 1 FROM edge_devices WHERE device_id = ? AND school_id = ?");
        $ownerStmt->execute([$deviceId, $authUser['school_id']]);
        if (!$ownerStmt->fetchColumn()) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Sensor no encontrado']));
        }

        $conn->prepare("UPDATE edge_devices SET configured = TRUE WHERE device_id = ? AND school_id = ?")
            ->execute([$deviceId, $authUser['school_id']]);

        securityLog('EDGE_DEVICE_CONFIGURED', "Device: $deviceId, By: {$authUser['id']}", $authUser['id'], $authUser['school_id']);

        echo json_encode(['status' => 'ok', 'message' => 'Sensor configurado correctamente', 'device_id' => $deviceId]);
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
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'location' => $location,
                'deleted_by' => $authUser['id'],
                'deleted_by_role' => $userRole,
            ], JSON_UNESCAPED_UNICODE);

            $rows = [];
            $params = [];
            $notifMessage = "El sensor '{$deviceName}' ({$location}) ha sido eliminado por {$userName}.";
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
                'revocation_id' => $revocationId,
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'requested_by' => $authUser['id'],
                'requested_by_name' => $userName,
                'executes_at' => $executesAt,
            ], JSON_UNESCAPED_UNICODE);

            $rows = [];
            $params = [];
            $notifMessage = "El sensor '{$deviceName}' será revocado en 1 hora. Puedes cancelar esta acción.";
            foreach ($recipients as $r) {
                $rows[] = "(?, ?, 'Revocación de sensor en proceso', ?, 'WARNING', ?::jsonb, NOW())";
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
        require_once __DIR__ . '/../mqtt_publisher.php';
        $mqttOk = publishDeviceCommand($deviceId, $cmdPayload);
    }

    // Fallback: Redis para compatibilidad V1
    try {
        $redis = getRedisConnection();
        if ($redis) {
            $redis->lPush("device:{$deviceId}:commands", json_encode($cmdPayload, JSON_UNESCAPED_UNICODE));
            $redis->expire("device:{$deviceId}:commands", 86400);
        }
    } catch (Exception $e) {
        if (!$mqttOk) {
            securityLog('DEVICE_COMMAND_ERROR', $e->getMessage());
            http_response_code(500);
            exit(json_encode(['status' => 'error', 'message' => 'Error al encolar comando']));
        }
    }

    $channel = $mqttOk ? 'MQTT' : 'REDIS';
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
    $conn->prepare("SELECT set_config('app.current_role', 'EDGE_NODE', true)")->execute();
    $stmt = $conn->prepare("SELECT school_id, token_hash FROM edge_devices WHERE device_id = ? LIMIT 1");
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device || !password_verify($deviceToken, $device['token_hash'])) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
    }
    $stmtConfig = $conn->prepare("SELECT set_config('app.current_school_id', ?, true), set_config('app.current_role', 'EDGE_NODE', true)");
    $stmtConfig->execute([(string)$device['school_id']]);

    try {
        $redis = getRedisConnection();
        $commands = [];
        if ($redis) {
            $queue = "device:{$deviceId}:commands";
            while (($item = $redis->rPop($queue)) !== false) {
                $cmd = json_decode($item, true);
                if ($cmd) $commands[] = $cmd;
            }
        }

        echo json_encode([
            'status' => 'ok',
            'data' => $commands,
            'meta' => ['count' => count($commands)]
        ]);
    } catch (Exception $e) {
        securityLog('DEVICE_COMMANDS_FETCH_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener comandos']);
    }
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
    $conn->prepare("SELECT set_config('app.current_role', 'EDGE_NODE', true)")->execute();
    $stmt = $conn->prepare("SELECT school_id, token_hash FROM edge_devices WHERE device_id = ? LIMIT 1");
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device || !password_verify($deviceToken, $device['token_hash'])) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
    }
    $stmtConfig = $conn->prepare("SELECT set_config('app.current_school_id', ?, true), set_config('app.current_role', 'EDGE_NODE', true)");
    $stmtConfig->execute([(string)$device['school_id']]);

    try {
        $stmt = $conn->prepare("
            UPDATE edge_devices
            SET last_ping = NOW(), status = ?, last_seen_timestamp = to_timestamp(?)
            WHERE device_id = ?
        ");
        $stmt->execute([$status, (int)$timestamp, $deviceId]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Dispositivo no encontrado']));
        }

        echo json_encode(['status' => 'ok', 'received_at' => time()]);
    } catch (Exception $e) {
        securityLog('HEARTBEAT_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al procesar heartbeat']);
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
