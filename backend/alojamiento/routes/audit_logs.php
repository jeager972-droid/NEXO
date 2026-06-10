<?php
global $cleanPath, $conn, $method;
require_once __DIR__ . '/_auth_middleware.php';

if ($cleanPath === '/audit/global' && $method === 'GET') {
    $authUser = requireAuth(['SUPER_RECTOR', 'RECTOR', 'COORDINADOR']);
    $schoolId = $authUser['school_id'];
    
    // Registrar el acceso a la tabla de auditoría
    securityLog('AUDIT_LOGS_VIEWED', 'Consultó registros globales de auditoría', $authUser['id'], $schoolId);
    
    try {
        $stmt = $conn->prepare("
            SELECT audit_id, event_type, description, ip_address, created_at,
                   u.first_name || ' ' || u.last_name AS actor_name
            FROM global_audit_logs a
            LEFT JOIN users u ON a.actor_id = u.user_id
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
        echo json_encode(['status' => 'error', 'message' => 'Error querying audit logs']);
    }
    exit;
}
