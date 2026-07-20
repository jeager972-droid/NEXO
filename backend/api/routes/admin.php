<?php
// routes/admin.php - Endpoints administrativos protegidos
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';
require_once __DIR__ . '/../lib/RiskScoreEngine.php';

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
