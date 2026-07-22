<?php
/**
 * =============================================================================
 * routes/audit_integrity.php — Validación de integridad de cadena de auditoría.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone un endpoint GET para que RECTOR o COORDINATOR verifiquen que la cadena
 * de hashes de auditoría de su escuela no ha sido alterada. Delega la validación
 * criptográfica a la función PostgreSQL fn_validate_audit_chain.
 *
 * FLUJO GENERAL
 * -------------
 *   GET /audit/integrity
 *        │
 *        ▼
 *   requireAuth(['RECTOR','COORDINATOR'])
 *        │
 *        ▼
 *   SELECT fn_validate_audit_chain($schoolId)
 *        │
 *        ▼
 *   Decodifica JSON de resultado
 *        │
 *   ├── status='ok'    ──► {integrity:'valid', total_records}
 *   └── status!='ok'   ──► {integrity:'broken', broken_at_audit_id, ...}
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación y autorización.
 *   - $conn : conexión PDO.
 *   - Función PostgreSQL fn_validate_audit_chain.
 *
 * Es utilizado por:
 *   - backend/api/api.php y frontend panel de auditoría.
 */

global $cleanPath, $conn, $method;
require_once __DIR__ . '/_auth_middleware.php';

// ============================================================================
// GET /audit/integrity — Verifica la cadena de hashes de global_audit_logs.
// Requiere rol RECTOR o COORDINATOR.
// ============================================================================
if ($cleanPath === '/audit/integrity' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    
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
