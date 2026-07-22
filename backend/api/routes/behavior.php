<?php
/**
 * =============================================================================
 * routes/behavior.php — Métricas de comportamiento y riesgo estudiantil.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone GET /behavior/risk que retorna los estudiantes con nivel de riesgo
 * HIGH o CRITICAL previamente calculados por RiskScoreEngine. Si aún no existen
 * métricas para la escuela, informa al cliente y sugiere llamar /admin/recalc-risk.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación.
 *   - lib/RiskScoreEngine.php : motor de riesgo (solo como referencia; no lo invoca).
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Panel de comportamiento / riesgo del frontend.
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';
require_once __DIR__ . '/../lib/RiskScoreEngine.php';

// ============================================================================
// GET /behavior/risk — Lista de estudiantes con riesgo HIGH/CRITICAL.
// ============================================================================
if ($cleanPath === '/behavior/risk') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'] ?? null;

    if (!$schoolId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID de institución requerido']));
    }

    try {
        // Verificar si existen métricas calculadas para la escuela
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM student_behavior_metrics WHERE school_id = ? LIMIT 1");
        $checkStmt->execute([$schoolId]);
        $hasMetrics = (int)$checkStmt->fetchColumn() > 0;

        if (!$hasMetrics) {
            echo json_encode([
                'status' => 'ok',
                'data' => [],
                'meta' => [
                    'message' => 'Métricas aún no calculadas. Llama a /admin/recalc-risk para esta escuela.',
                    'school_id' => $schoolId
                ]
            ]);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT
                s.student_id,
                s.first_name,
                s.last_name,
                COALESCE(ag.group_name, 'Sin grupo') as group_name,
                sbm.risk_score,
                sbm.risk_level,
                sbm.late_count,
                sbm.absence_count
            FROM student_behavior_metrics sbm
            INNER JOIN students s ON s.student_id = sbm.student_id
            LEFT JOIN student_group_assignments sga
              ON s.student_id = sga.student_id AND sga.active = TRUE
            LEFT JOIN academic_groups ag
              ON sga.group_id = ag.group_id
            WHERE sbm.school_id = ?
              AND sbm.risk_level IN ('HIGH', 'CRITICAL')
            ORDER BY sbm.risk_score DESC
        ");
        $stmt->execute([$schoolId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'ok',
            'data' => $students,
            'meta' => [
                'count' => count($students),
                'school_id' => $schoolId
            ]
        ]);
    } catch (Exception $e) {
        securityLog('BEHAVIOR_RISK_ERROR', $e->getMessage(), $authUser['id'], $schoolId);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener métricas de riesgo']);
    }
    exit;
}
