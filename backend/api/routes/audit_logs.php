<?php
/**
 * =============================================================================
 * routes/audit_logs.php — Consulta de registros globales de auditoría.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone GET /audit/global para que RECTOR o COORDINADOR consulten los últimos
 * 100 registros de global_audit_logs de su escuela, enriquecidos con el nombre
 * del actor.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación y roles.
 *   - $conn : conexión PDO global.
 *
 * Es utilizado por:
 *   - backend/api/api.php (routing) y panel de auditoría del frontend.
 */

global $cleanPath, $conn, $method;
require_once __DIR__ . '/_auth_middleware.php';

// ============================================================================
// GET /audit/global — Últimos 100 registros de auditoría de la escuela.
// ============================================================================
if ($cleanPath === '/audit/global' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $schoolId = $authUser['school_id'];
    
    // Registrar el acceso a la tabla de auditoría
    securityLog('AUDIT_LOGS_VIEWED', 'Consultó registros globales de auditoría', $authUser['id'], $schoolId);
    
    try {
        $stmt = $conn->prepare("
            SELECT log_id, action_type AS event_type, description, ip_address, created_at,
                   u.first_name || ' ' || u.last_name AS actor_name
            FROM global_audit_logs a
            LEFT JOIN users u ON a.performed_by_user_id = u.user_id
            WHERE a.school_id = ?
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        
        echo json_encode([
            'status' => 'ok',
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (Exception $e) {
        securityLog('AUDIT_LOGS_DB_ERROR', $e->getMessage(), $authUser['id'], $schoolId);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error querying audit logs', 'debug' => $e->getMessage()]);
    }
    exit;
}
