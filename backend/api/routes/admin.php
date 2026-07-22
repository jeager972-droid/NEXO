<?php
/**
 * =============================================================================
 * routes/admin.php — Endpoints administrativos de gestión institucional.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone operaciones administrativas restringidas a roles de alto privilegio
 * (RECTOR / COORDINATOR). Actualmente contiene el endpoint para recalcular las
 * métricas de riesgo estudiantil usando RiskScoreEngine.
 *
 * FLUJO GENERAL
 * -------------
 *   POST /admin/recalc-risk
 *        │
 *        ▼
 *   requireAuth(['RECTOR','COORDINATOR'])
 *        │
 *        ▼
 *   ¿school_id presente?
 *        ├── SI ──► RiskScoreEngine::recalculateSchool($conn, $schoolId)
 *        └── NO ──► Iterar todas las escuelas activas y recalcular cada una
 *        │
 *        ▼
 *   Registrar auditoría y retornar {processed, errors, meta}
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación y control de roles.
 *   - lib/RiskScoreEngine.php : motor de cálculo de riesgo.
 *   - $conn : conexión PDO global a PostgreSQL.
 *   - $input : payload JSON decodificado (definido en api.php).
 *
 * Es utilizado por:
 *   - backend/api/api.php : lo incluye por routing basado en URI.
 *   - Frontend React: panel administrativo (Admin.jsx).
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';
require_once __DIR__ . '/../lib/RiskScoreEngine.php';

// ============================================================================
// POST /admin/recalc-risk
// Recalcula métricas de riesgo para una escuela específica o todas las activas.
// ============================================================================
if ($cleanPath === '/admin/recalc-risk') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $schoolId = $input['school_id'] ?? null;

    try {
        $processed = 0;
        $errors    = [];

        if ($schoolId) {
            // Recalcular una escuela específica
            $processed = RiskScoreEngine::recalculateSchool($conn, $schoolId);
        } else {
            // Recalcular todas las escuelas activas
            $stmt    = $conn->query("SELECT school_id, school_name FROM schools WHERE active = TRUE");
            $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($schools as $school) {
                try {
                    $count     = RiskScoreEngine::recalculateSchool($conn, $school['school_id']);
                    $processed += $count;
                } catch (Exception $e) {
                    $errors[] = [
                        'school_id'   => $school['school_id'],
                        'school_name' => $school['school_name'],
                        'error'       => $e->getMessage()
                    ];
                }
            }
        }

        securityLog('ADMIN_RECALC_RISK', "Processed: {$processed} students", $authUser['id'], $schoolId);

        echo json_encode([
            'status'    => 'ok',
            'processed' => $processed,
            'errors'    => $errors,
            'meta'      => [
                'triggered_by' => $authUser['email'],
                'timestamp'    => gmdate('c'),
                'engine'       => 'RiskScoreEngine v2.0',
            ]
        ]);
    } catch (Exception $e) {
        securityLog('ADMIN_RECALC_RISK_ERROR', $e->getMessage(), $authUser['id'], $schoolId);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al recalcular métricas']);
    }
    exit;
}
