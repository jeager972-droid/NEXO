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
    // /risk/* NO se bloquea por onboarding — RECTOR/COORDINATOR necesitan
    // configurar el motor para completar risk_config_completed.

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
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener política']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
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
            $input['config'] ?? [], $authUser['role'] ?? 'COORDINATOR'
        );
        securityLog('RISK_POLICY_VERSION_CREATED', "New policy version: $policyId", $authUser['id'], $schoolId);
        // Marcar configuración del motor como completada (onboarding gate)
        try {
            $conn->prepare("UPDATE schools SET risk_config_completed = TRUE WHERE school_id = ?")
                 ->execute([$schoolId]);
        } catch (Throwable $e) { /* columna ausente pre-migración — no fatal */ }
        echo json_encode(['status' => 'ok', 'policy_id' => $policyId]);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Validación falló', 'details' => $e->getMessage()]);
    } catch (Throwable $e) {
        $debug = sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
        if ($e instanceof PDOException) {
            $debug .= ' | SQLSTATE: ' . ($e->errorInfo[0] ?? $e->getCode());
            if (isset($e->errorInfo[2])) $debug .= ' | PG: ' . $e->errorInfo[2];
        }
        securityLog('RISK_POLICY_CREATE_ERROR', $debug, $authUser['id'], $schoolId);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error interno al guardar la política']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
    }
    exit;
}

// ============================================================================
// POST /risk/incidents/{id}/resolve — Resuelve un incidente de EVASION_INTERNA
// Body: { "resolution": "justificada" | "injustificada", "notes": "..." }
// ============================================================================
if (preg_match('#^/risk/incidents/([a-f0-9-]+)/resolve$#', $cleanPath, $m) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $incidentId = $m[1];
    $resolution = trim($input['resolution'] ?? '');
    $notes = trim($input['notes'] ?? '');

    if (!in_array($resolution, ['justificada', 'injustificada'], true)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'resolution debe ser "justificada" o "injustificada"']));
    }

    try {
        // Cargar el incidente
        $loadStmt = $conn->prepare("
            SELECT incident_id, school_id, student_id, incident_type, metadata_json
            FROM attendance_incidents
            WHERE incident_id = ?::uuid
        ");
        $loadStmt->execute([$incidentId]);
        $incident = $loadStmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Incidente no encontrado']));
        }

        // Verificar que pertenece a la escuela del usuario
        if ($incident['school_id'] !== $authUser['school_id']) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'No autorizado']));
        }

        // Solo EVASION_INTERNA se resuelve con justificada/injustificada
        if ($incident['incident_type'] !== 'EVASION_INTERNA') {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Solo los incidentes de evasión se resuelven con justificada/injustificada']));
        }

        // Construir metadata de resolución
        $existingMeta = json_decode($incident['metadata_json'] ?? '{}', true);
        $existingMeta['resolution'] = $resolution;
        $existingMeta['resolution_notes'] = $notes;
        $existingMeta['resolved_by'] = $authUser['id'];
        $existingMeta['resolved_at'] = date('c');

        // Si es INJUSTIFICADA: marcar resolved=TRUE pero mantener el incidente
        // para que cuente en el análisis de riesgo y sea consultable.
        // Si es JUSTIFICADA: marcar resolved=TRUE y no cuenta para riesgo.
        $updateStmt = $conn->prepare("
            UPDATE attendance_incidents
            SET resolved = TRUE,
                metadata_json = ?::jsonb
            WHERE incident_id = ?::uuid
        ");
        $updateStmt->execute([json_encode($existingMeta, JSON_UNESCAPED_UNICODE), $incidentId]);

        // Audit trail
        try {
            $auditStmt = $conn->prepare(
                "INSERT INTO student_record_audit (audit_id, school_id, student_id, performed_by_user_id, action_type, previous_data, new_data, performed_at)
                 VALUES (uuid_generate_v4(), ?::uuid, ?::uuid, ?::uuid, ?, ?::jsonb, ?::jsonb, NOW())"
            );
            $auditStmt->execute([
                $incident['school_id'],
                $incident['student_id'],
                $authUser['id'],
                'EVASION_RESOLVED_' . strtoupper($resolution),
                $incident['metadata_json'],
                json_encode($existingMeta, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (Exception $ae) {
            securityLog('AUDIT_EVASION_RESOLVE_FAIL', $ae->getMessage());
        }

        securityLog('EVASION_RESOLVED', "Incident:$incidentId Resolution:$resolution User:{$authUser['id']}", $authUser['id'], $authUser['school_id']);
        echo json_encode(['status' => 'ok', 'resolution' => $resolution]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
    }
    exit;
}
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
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
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
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor']);
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
