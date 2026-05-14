<?php
global $cleanPath, $conn, $method;
require_once __DIR__ . '/_auth_middleware.php';

/**
 * T4: Validación de Integridad de la Cadena de Auditoría
 * GET /audit/integrity
 * Requiere rol RECTOR o SUPER_RECTOR
 */
if ($cleanPath === '/audit/integrity' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINADOR', 'SUPER_RECTOR']);
    
    try {
        // Usar la función de validación de cadena en PostgreSQL
        $schoolId = $authUser['school_id'];
        $stmt = $conn->prepare("SELECT fn_validate_audit_chain(?) AS result");
        $stmt->execute([$schoolId]);
        $result = $stmt->fetchColumn();
        
        $data = json_decode($result, true);
        
        // Registrar quién consultó la integridad
        securityLog('AUDIT_INTEGRITY_CHECKED', 'Validación de cadena de hashes ejecutada', $authUser['id'], $schoolId);
        
        if ($data['status'] === 'ok') {
            echo json_encode([
                'status' => 'ok',
                'integrity' => 'valid',
                'total_records' => $data['total_records'] ?? 0,
                'verified_at' => gmdate('Y-m-d\TH:i:s\Z')
            ]);
        } else {
            // Compromised
            echo json_encode([
                'status' => 'compromised',
                'integrity' => 'broken',
                'broken_at_audit_id' => $data['broken_at_audit_id'] ?? null,
                'total_checked' => $data['total_checked'] ?? 0,
                'valid_up_to' => $data['valid_up_to'] ?? 0,
                'verified_at' => gmdate('Y-m-d\TH:i:s\Z')
            ]);
        }
        
    } catch (Exception $e) {
        securityLog('AUDIT_INTEGRITY_ERROR', $e->getMessage(), $authUser['id'], $authUser['school_id'] ?? null);
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Error validando integridad de auditoría'
        ]);
    }
    
    exit;
}
