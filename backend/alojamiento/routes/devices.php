<?php
// routes/devices.php - Gestión de dispositivos EDGE
// TODO: El endpoint POST /devices genera un token raw que el edge debería
// usar para autenticarse (header X-Device-Signature o similar). Actualmente
// el firmware edge no implementa esta autenticación; se asume confianza
// por cifrado de payload. Implementar validación de token_hash en el
// endpoint EDGE cuando se añada el soporte en el edge.
global $cleanPath, $conn, $method, $input;
require_once __DIR__ . '/_auth_middleware.php';

if ($cleanPath === '/devices' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINADOR']);
    $stmt = $conn->prepare("SELECT device_id, name, location, active, last_ping, created_at FROM edge_devices WHERE school_id = ? ORDER BY created_at DESC");
    $stmt->execute([$authUser['school_id']]);
    echo json_encode(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($cleanPath === '/devices' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINADOR']);
    $name = trim($input['name'] ?? '');
    $location = trim($input['location'] ?? '');
    
    if (empty($name)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'El nombre es obligatorio']));
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = password_hash($rawToken, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("INSERT INTO edge_devices (school_id, name, location, token_hash) VALUES (?, ?, ?, ?) RETURNING device_id");
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
    $authUser = requireAuth(['RECTOR', 'COORDINADOR']);
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

// Enviar comando a un dispositivo edge (M2M)
if (preg_match('#^/devices/command/([0-9a-fA-F\-]+)$#', $cleanPath, $matches) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINADOR']);
    $deviceId = $matches[1];
    $command = trim($input['command'] ?? '');
    $payload = $input['payload'] ?? [];

    if (empty($command)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'command requerido']));
    }

    try {
        $redis = new Redis();
        $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', getenv('REDIS_PORT') ?: 6379);
        if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
        $redis->lPush("device:{$deviceId}:commands", json_encode([
            'command' => $command,
            'payload' => $payload,
            'issued_at' => time(),
            'issued_by' => $authUser['id']
        ], JSON_UNESCAPED_UNICODE));
        $redis->expire("device:{$deviceId}:commands", 86400);

        securityLog('DEVICE_COMMAND_ISSUED', "Device: $deviceId Command: $command", $authUser['id'], $authUser['school_id']);
        echo json_encode(['status' => 'ok', 'device_id' => $deviceId, 'command' => $command]);
    } catch (Exception $e) {
        securityLog('DEVICE_COMMAND_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al encolar comando']);
    }
    exit;
}

// Edge polling: recoger comandos pendientes
if ($cleanPath === '/devices/commands' && $method === 'GET') {
    $deviceId = $_GET['device_id'] ?? '';
    if (empty($deviceId)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'device_id requerido']));
    }

    // Validar token del dispositivo (bypass RLS temporal con SUPER_RECTOR)
    $deviceToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
    if (!empty($deviceToken)) {
        $conn->exec("SET app.current_role = 'SUPER_RECTOR'");
        $stmt = $conn->prepare("SELECT school_id, token_hash FROM edge_devices WHERE device_id = ? LIMIT 1");
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$device || !password_verify($deviceToken, $device['token_hash'])) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
        }
        // Activar contexto correcto para operaciones subsiguientes
        $conn->exec("SET app.current_school_id = " . (int)$device['school_id']);
        $conn->exec("SET app.current_role = 'EDGE_NODE'");
    }

    try {
        $redis = new Redis();
        $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', getenv('REDIS_PORT') ?: 6379);
        if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);

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

    // Validar token del dispositivo si está presente (bypass RLS temporal)
    $deviceToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
    if (!empty($deviceToken)) {
        $conn->exec("SET app.current_role = 'SUPER_RECTOR'");
        $stmt = $conn->prepare("SELECT school_id, token_hash FROM edge_devices WHERE device_id = ? LIMIT 1");
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$device || !password_verify($deviceToken, $device['token_hash'])) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'Token de dispositivo inválido']));
        }
        // Activar contexto correcto para operaciones subsiguientes
        $conn->exec("SET app.current_school_id = " . (int)$device['school_id']);
        $conn->exec("SET app.current_role = 'EDGE_NODE'");
    }

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
    $authUser = requireAuth(['SUPER_RECTOR']);
    $healthOnly = isset($_GET['health']) && $_GET['health'] === '1';

    try {
        if ($healthOnly) {
            // Dispositivos sin ping en los últimos 5 minutos
            $stmt = $conn->prepare("
                SELECT device_id, name, location, school_id, last_ping, status,
                       EXTRACT(EPOCH FROM (NOW() - last_ping))::INTEGER as seconds_since_ping
                FROM edge_devices
                WHERE last_ping < NOW() - INTERVAL '5 minutes'
                   OR last_ping IS NULL
                ORDER BY last_ping DESC NULLS LAST
            ");
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("
                SELECT device_id, name, location, school_id, last_ping, status,
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
