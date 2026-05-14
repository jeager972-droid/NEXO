<?php
// routes/admin.php - Endpoints administrativos protegidos
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

if ($cleanPath === 'admin/recalc-risk') {
    $authUser = requireAuth(['SUPER_RECTOR']);
    $schoolId = $input['school_id'] ?? null;

    try {
        $processed = 0;
        $errors = [];

        if ($schoolId) {
            // Recalcular una escuela específica
            $stmt = $conn->prepare("SELECT fn_recalculate_school_metrics(?)");
            $stmt->execute([(int)$schoolId]);
            $count = $stmt->fetchColumn();
            $processed = (int)$count;
        } else {
            // Recalcular todas las escuelas activas
            $stmt = $conn->query("SELECT school_id, school_name FROM schools WHERE active = TRUE");
            $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($schools as $school) {
                try {
                    $recalcStmt = $conn->prepare("SELECT fn_recalculate_school_metrics(?)");
                    $recalcStmt->execute([$school['school_id']]);
                    $count = $recalcStmt->fetchColumn();
                    $processed += (int)$count;
                } catch (Exception $e) {
                    $errors[] = [
                        'school_id' => $school['school_id'],
                        'school_name' => $school['school_name'],
                        'error' => $e->getMessage()
                    ];
                }
            }
        }

        securityLog('ADMIN_RECALC_RISK', "Processed: {$processed} students", $authUser['id'], $schoolId);

        echo json_encode([
            'status' => 'ok',
            'processed' => $processed,
            'errors' => $errors,
            'meta' => [
                'triggered_by' => $authUser['email'],
                'timestamp' => gmdate('c')
            ]
        ]);
    } catch (Exception $e) {
        securityLog('ADMIN_RECALC_RISK_ERROR', $e->getMessage(), $authUser['id'], $schoolId);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al recalcular métricas']);
    }
    exit;
}
