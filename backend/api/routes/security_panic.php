<?php
/**
 * =============================================================================
 * routes/security_panic.php — Botón de pánico administrativo.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone POST /security/panic. Solo RECTOR o COORDINATOR pueden activarlo.
 * Al activarse:
 *   1. Desactiva todos los edge_devices de la escuela.
 *   2. Registra el evento en school_panic_events (invalida sesiones JWT).
 *   3. Cachea el timestamp en Redis "panic:school:<id>" para verificación rápida.
 *   4. Notifica a Rectores y Coordinadores mediante la tabla notifications.
 *
 * USO DE REDIS AQUÍ
 * -----------------
 * Redis almacena una clave de corta duración panic:school:<school_id> con el
 * timestamp del último evento de pánico. verifyJwtToken() consulta esta clave
 * junto con jwt:blocklist en un único MGET para invalidar tokens emitidos antes
 * del pánico sin consultar PostgreSQL en cada request.
 *
 * FLUJO GENERAL
 * -------------
 *   POST /security/panic
 *        │
 *        ▼
 *   Validar rol RECTOR/COORDINATOR
 *        │
 *        ▼
 *   UPDATE edge_devices SET active = FALSE
 *        │
 *        ▼
 *   INSERT school_panic_events + Redis cache
 *        │
 *        ▼
 *   Notificar a directivos
 *        │
 *        ▼
 *   {status:'ok', sessions_revoked:true, devices_deactivated:N}
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación, roles, getRedisConnection().
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Frontend: botón de emergencia en panel de seguridad.
 */

global $cleanPath, $conn, $method, $input;
require_once __DIR__ . '/_auth_middleware.php';

// ============================================================================
// POST /security/panic — Activa modo de emergencia para toda la escuela.
// ============================================================================
if ($cleanPath === '/security/panic' && $method === 'POST') {
    $authUser = requireAuth();
    
    // Validar rol (únicos con poder de pánico)
    $role = strtoupper($authUser['role'] ?? '');
    if ($role !== 'RECTOR' && $role !== 'COORDINATOR') {
        securityLog('SECURITY_PANIC_DENIED', 'Intento no autorizado', $authUser['id'] ?? null, $authUser['school_id'] ?? null);
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Acceso denegado. Se requiere rol RECTOR o COORDINADOR.']));
    }

    try {
        // 1. Desactivar los edge_devices del colegio del usuario autenticado
        $stmt = $conn->prepare("UPDATE edge_devices SET active = FALSE, last_ping = NOW() WHERE school_id = ?");
        $stmt->execute([$authUser['school_id']]);
        $affectedDevices = $stmt->rowCount();
        
        // 2. Registrar el evento de pánico en school_panic_events (esto revoca todas las sesiones)
        $panicTimestamp = time();
        $stmt = $conn->prepare("
            INSERT INTO school_panic_events (school_id, triggered_by_user_id, devices_deactivated, metadata_json)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $authUser['school_id'],
            $authUser['id'],
            $affectedDevices,
            json_encode(['user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'])
        ]);

        // 2.5. Cache panic event in Redis for fast JWT verification
        try {
            $redis = getRedisConnection();
            if ($redis) {
                $redis->setex("panic:school:" . $authUser['school_id'], 86400, (string)$panicTimestamp);
            }
        } catch (Exception $e) {
            securityLog('PANIC_REDIS_CACHE_ERROR', $e->getMessage());
        }
        
        // 3. Registrar el evento de pánico en auditoría
        securityLog(
            'SECURITY_PANIC_TRIGGERED',
            "Pánico activado. Dispositivos desactivados: {$affectedDevices}. Todas las sesiones invalidadas.",
            $authUser['id'],
            $authUser['school_id']
        );
        
        // 4. Notificar a Rectores y Coordinadores del mismo colegio
        $stmt = $conn->prepare("
            INSERT INTO notifications (school_id, user_id, title, message, type, created_at)
            SELECT school_id, user_id, 'ALERTA DE SEGURIDAD', 'Se activó el botón de pánico. Todas las sesiones y dispositivos han sido invalidados. Ver detalles.', 'CRITICAL', NOW()
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE r.role_name IN ('RECTOR', 'COORDINATOR') AND u.deleted_at IS NULL AND u.school_id = ?
        ");
        $stmt->execute([$authUser['school_id']]);
        
        echo json_encode([
            'status' => 'ok',
            'message' => 'Modo de emergencia activado',
            'sessions_revoked' => true,
            'devices_deactivated' => $affectedDevices,
            'panic_timestamp' => $panicTimestamp
        ]);
        
    } catch (Exception $e) {
        securityLog('SECURITY_PANIC_ERROR', $e->getMessage(), $authUser['id'], $authUser['school_id']);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error crítico al activar pánico', 'debug' => $e->getMessage()]);
    }
    
    exit;
}
