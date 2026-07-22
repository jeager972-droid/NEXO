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

if ($cleanPath === '/devices' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $stmt = $conn->prepare("SELECT device_id, device_name, location, active, last_ping, created_at FROM edge_devices WHERE school_id = ? ORDER BY created_at DESC");
    $stmt->execute([$authUser['school_id']]);
    echo json_encode(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($cleanPath === '/devices' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $name = trim($input['name'] ?? '');
    $location = trim($input['location'] ?? '');
    
    if (empty($name)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'El nombre es obligatorio']));
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = password_hash($rawToken, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("INSERT INTO edge_devices (school_id, device_name, location, token_hash) VALUES (?, ?, ?, ?) RETURNING device_id");
    $stmt->execute([$authUser['school_id'], $name, $location, $tokenHash]);
    $deviceId = $stmt->fetchColumn();

    // Pasando el ID del usuario y escuela para trazabilidad
    securityLog('EDGE_DEVICE_REGISTERED', "Device: $deviceId, Name: $name", $authUser['id'], $authUser['school_id']);
    
    echo json_encode([
        'status' => 'ok', 
        'data' => ['device_id' => $deviceId, 'name' => $name, 'token' => $rawToken]
    ]);
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

    $stmt = $conn->prepare("DELETE FROM edge_devices WHERE device_id = ? AND school_id = ?");
    $stmt->execute([$deviceId, $authUser['school_id']]);

    securityLog('EDGE_DEVICE_REVOKED', "Device: $deviceId", $authUser['id'], $authUser['school_id']);
    
    echo json_encode(['status' => 'ok', 'message' => 'Dispositivo revocado']);
    exit;
}

// Enviar comando a un dispositivo edge (M2M) — V2: MQTT Pub/Sub con Redis fallback
if (preg_match('#^/devices/command/([0-9a-fA-F\-]+)$#', $cleanPath, $matches) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
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
        if (!$redis) {
            $commands = [];
        }

        $commands = [];
        $queue = "device:{$deviceId}:commands";
        while (($item = $redis->rPop($queue)) !== false) {
            $cmd = json_decode($item, true);
            if ($cmd) $commands[] = $cmd;
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
