<?php
/**
 * =============================================================================
 * routes/risk.php — API del Motor de Análisis de Riesgo Pedagógico v3.0
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET  /risk/policy              — Obtiene política activa + configuración
 *   GET  /risk/policy/history      — Historial de versiones de política
 *   POST /risk/policy              — Crea nueva versión de política
 *   GET  /risk/event-types         — Catálogo de tipos de evento disponibles
 *   GET  /risk/alerts              — Alertas activas de la escuela
 *   GET  /risk/alerts/{id}         — Detalle de una alerta
 *   POST /risk/alerts/{id}/resolve — Resuelve una alerta (acción humana)
 *   POST /risk/alerts/{id}/escalate — Cambia estado de escalamiento
 *   GET  /risk/student/{id}        — Perfil de riesgo de un estudiante
 *   POST /risk/justify             — Justifica un evento (filtro de contexto)
 *   POST /risk/recalculate         — Recalcula riesgo de toda la escuela
 *   GET  /risk/anomaly/{studentId} — z-score de anomalía estadística (Capa 8)
 *
 * DEPENDENCIAS:
 *   - lib/RiskEngineV3.php
 *   - _auth_middleware.php
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';
require_once __DIR__ . '/../lib/RiskEngineV3.php';

// ============================================================================
// GET /risk/policy — Política activa + configuración completa
// ============================================================================
if ($cleanPath === '/risk/policy' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $schoolId = $authUser['school_id'];

    try {
        $policy = RiskEngineV3::getActivePolicy($conn, $schoolId);
        if (!$policy) {
            // Auto-crear política default si no existe
            $policyId = fn_seed_default_risk_policy_php($conn, $schoolId, $authUser['id']);
            $policy = RiskEngineV3::getActivePolicy($conn, $schoolId);
        }

        $config = RiskEngineV3::getPolicyConfig($conn, $policy['policy_id']);

        echo json_encode([
            'status' => 'ok',
            'data' => [
                'policy' => $policy,
                'config' => $config,
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener política', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// GET /risk/policy/history — Historial de versiones
// ============================================================================
if ($cleanPath === '/risk/policy/history' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $schoolId = $authUser['school_id'];

    try {
        $history = RiskEngineV3::getPolicyHistory($conn, $schoolId);
        echo json_encode(['status' => 'ok', 'data' => $history]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /risk/policy — Crea nueva versión de política
// ============================================================================
if ($cleanPath === '/risk/policy' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $schoolId = $authUser['school_id'];

    $reason = trim($input['change_reason'] ?? '');
    if (strlen($reason) < 10) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'El motivo del cambio es obligatorio (mín. 10 caracteres)']));
    }

    try {
        $policyId = RiskEngineV3::createPolicyVersion(
            $conn, $schoolId, $authUser['id'], $reason,
            $input['config'] ?? []
        );
        securityLog('RISK_POLICY_VERSION_CREATED', "New policy version: $policyId", $authUser['id'], $schoolId);
        echo json_encode(['status' => 'ok', 'policy_id' => $policyId]);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Validación falló', 'details' => $e->getMessage()]);
    } catch (Throwable $e) {
        // DEBUG (temporal): incluir traza completa para diagnosticar el 500.
        // Se reducirá a mensaje genérico cuando el guardado funcione.
        $debug = sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
        if ($e instanceof PDOException) {
            $debug .= ' | SQLSTATE: ' . ($e->errorInfo[0] ?? $e->getCode());
            if (isset($e->errorInfo[2])) $debug .= ' | PG: ' . $e->errorInfo[2];
        }
        securityLog('RISK_POLICY_CREATE_ERROR', $debug, $authUser['id'], $schoolId);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'debug' => $debug]);
    }
    exit;
}

// ============================================================================
// GET /risk/event-types — Catálogo de tipos de evento
// ============================================================================
if ($cleanPath === '/risk/event-types' && $method === 'GET') {
    requireAuth(['RECTOR', 'COORDINATOR', 'TEACHER']);
    try {
        $types = RiskEngineV3::getEventTypes($conn);
        echo json_encode(['status' => 'ok', 'data' => $types]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// GET /risk/alerts — Alertas activas
// ============================================================================
if ($cleanPath === '/risk/alerts' && $method === 'GET') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $level = $_GET['level'] ?? null;

    try {
        $alerts = RiskEngineV3::getActiveAlerts($conn, $schoolId, $level);
        echo json_encode([
            'status' => 'ok',
            'data' => $alerts,
            'meta' => ['count' => count($alerts)]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /risk/alerts/{id}/resolve — Resuelve una alerta
// ============================================================================
if (preg_match('#^/risk/alerts/([a-f0-9-]+)/resolve$#', $cleanPath, $m) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $alertId = $m[1];
    $notes = trim($input['resolution_notes'] ?? '');
    if (strlen($notes) < 5) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Las notas de resolución son obligatorias']));
    }
    try {
        $ok = RiskEngineV3::resolveAlert($conn, $alertId, $authUser['id'], $notes);
        if ($ok) {
            securityLog('RISK_ALERT_RESOLVED', "Alert $alertId resolved", $authUser['id'], $authUser['school_id']);
            echo json_encode(['status' => 'ok']);
        } else {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Alerta no encontrada o ya resuelta']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /risk/alerts/{id}/escalate — Cambia estado de escalamiento
// ============================================================================
if (preg_match('#^/risk/alerts/([a-f0-9-]+)/escalate$#', $cleanPath, $m) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $alertId = $m[1];
    $newState = $input['escalation_state'] ?? '';
    $reason = trim($input['reason'] ?? '');
    if (strlen($reason) < 5) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'El motivo es obligatorio']));
    }
    try {
        $ok = RiskEngineV3::changeEscalationState($conn, $alertId, $newState, $authUser['id'], $reason);
        echo json_encode(['status' => $ok ? 'ok' : 'error']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// GET /risk/student/{id} — Perfil de riesgo de un estudiante
// ============================================================================
if (preg_match('#^/risk/student/([a-f0-9-]+)$#', $cleanPath, $m) && $method === 'GET') {
    $authUser = requireAuth();
    $studentId = $m[1];
    try {
        $profile = RiskEngineV3::getStudentRiskProfile($conn, $studentId, $authUser['school_id']);
        echo json_encode(['status' => 'ok', 'data' => $profile]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /risk/justify — Justifica un evento
// ============================================================================
if ($cleanPath === '/risk/justify' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR', 'TEACHER']);
    $required = ['student_id', 'incident_type', 'incident_date', 'justification_type', 'reason'];
    foreach ($required as $f) {
        if (empty($input[$f])) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => "Campo requerido: $f"]));
        }
    }
    try {
        $justId = RiskEngineV3::justifyEvent(
            $conn,
            $authUser['school_id'],
            $input['student_id'],
            $input['incident_type'],
            $input['incident_date'],
            $authUser['id'],
            $input['justification_type'],
            $input['reason']
        );
        securityLog('RISK_EVENT_JUSTIFIED', "Event justified: $justId", $authUser['id'], $authUser['school_id']);
        echo json_encode(['status' => 'ok', 'justification_id' => $justId]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /risk/recalculate — Recalcula riesgo de toda la escuela
// ============================================================================
if ($cleanPath === '/risk/recalculate' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    try {
        $count = RiskEngineV3::recalculateSchool($conn, $authUser['school_id']);
        securityLog('RISK_RECALCULATE', "Recalculated $count students", $authUser['id'], $authUser['school_id']);
        echo json_encode(['status' => 'ok', 'processed' => $count]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// GET /risk/anomaly/{studentId} — z-score de anomalía estadística (Capa 8)
// ============================================================================
if (preg_match('#^/risk/anomaly/([a-f0-9-]+)$#', $cleanPath, $m) && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $studentId = $m[1];
    $category = $_GET['category'] ?? 'asistencia';
    try {
        $anomaly = RiskEngineV3::calculateAnomalyScore($conn, $studentId, $authUser['school_id'], $category);
        echo json_encode(['status' => 'ok', 'data' => $anomaly]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// Helper: seed de política default desde PHP (si no existe)
// ============================================================================
function fn_seed_default_risk_policy_php(PDO $conn, string $schoolId, string $userId): string {
    $stmt = $conn->prepare("SELECT fn_seed_default_risk_policy(?, ?)");
    $stmt->execute([$schoolId, $userId]);
    return $stmt->fetchColumn();
}
