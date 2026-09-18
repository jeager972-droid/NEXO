<?php
/**
 * =============================================================================
 * routes/tracking.php — Gestión de seguimientos estudiantiles (casos).
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone endpoints CRUD para seguimientos de estudiantes:
 *   - POST /tracking/start   : inicia un seguimiento, opcionalmente con motivo
 *                              heredado de una notificación.
 *   - POST /tracking/notes   : agrega nota y actualiza estado del seguimiento.
 *   - GET  /tracking/details : detalle del seguimiento con notas.
 *   - GET  /tracking/active  : lista de seguimientos con estado 'en proceso'.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación y roles.
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Frontend: módulo de bienestar/psicoorientación.
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

/**
 * Valida que un string sea un UUID v4 canónico.
 *
 * @param mixed $value Valor a validar.
 * @return bool True si coincide con el patrón UUID v4.
 */
if (!function_exists('isValidUUID')) {
    function isValidUUID($value) {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $value
        );
    }
}

if (strpos($cleanPath, '/tracking') === 0) {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $role = strtoupper($authUser['role'] ?? '');

    if (!in_array($role, ['COORDINATOR', 'RECTOR', 'COUNSELOR'])) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Acceso restringido']));
    }

    if ($cleanPath === '/tracking/start' && $method === 'POST') {
        $studentId = $input['student_id'] ?? null;
        $reason = $input['reason'] ?? null;
        if ($studentId !== null && !isValidUUID($studentId)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'ID de estudiante con formato inválido.']);
            exit();
        }
        if (!$studentId) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'student_id es requerido']));
        }

        try {
            // Validar que el estudiante exista y pertenezca a la escuela
            $stuCheck = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND school_id = ? AND active = TRUE AND deleted_at IS NULL");
            $stuCheck->execute([$studentId, $schoolId]);
            if (!$stuCheck->fetchColumn()) {
                http_response_code(404);
                exit(json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado o inactivo']));
            }

            // Check if already in tracking
            $checkStmt = $conn->prepare("SELECT tracking_id FROM student_tracking WHERE student_id = ? AND school_id = ? AND status = 'en proceso'");
            $checkStmt->execute([$studentId, $schoolId]);
            $existing = $checkStmt->fetchColumn();

            if ($existing) {
                echo json_encode(['status' => 'ok', 'message' => 'Estudiante ya está en seguimiento', 'tracking_id' => $existing]);
                exit;
            }

            $dependency = $input['dependency'] ?? null;
            $assignedTo = $input['assigned_to_user_id'] ?? null;
            $stmt = $conn->prepare("
                INSERT INTO student_tracking (school_id, student_id, status, dependency, assigned_to_user_id, origin_type)
                VALUES (?, ?, 'en proceso', ?, ?, 'manual') RETURNING tracking_id
            ");
            $stmt->execute([$schoolId, $studentId, $dependency, $assignedTo]);
            $trackingId = $stmt->fetchColumn();

            // Si no se proporcionó motivo, buscarlo en la notificación
            if (!$reason) {
                $notifStmt = $conn->prepare("
                    SELECT metadata_json
                    FROM notifications
                    WHERE school_id = ?
                      AND metadata_json->>'student_id' = ?
                      AND metadata_json->>'action' = 'iniciar_seguimiento'
                    LIMIT 1
                ");
                $notifStmt->execute([$schoolId, $studentId]);
                $notif = $notifStmt->fetch(PDO::FETCH_ASSOC);
                if ($notif && $notif['metadata_json']) {
                    $meta = json_decode($notif['metadata_json'], true);
                    $reason = $meta['reason'] ?? '';
                }
            }

            // Agregar nota inicial con el motivo
            if ($reason) {
                $noteStmt = $conn->prepare("
                    INSERT INTO student_tracking_notes (tracking_id, user_id, note_text)
                    VALUES (?, ?, ?)
                ");
                $noteStmt->execute([$trackingId, $userId, "Motivo de solicitud: " . $reason]);
            }

            // Eliminar notificaciones de seguimiento para este estudiante
            $delNotifStmt = $conn->prepare("
                DELETE FROM notifications
                WHERE school_id = ?
                  AND metadata_json->>'student_id' = ?
                  AND metadata_json->>'action' = 'iniciar_seguimiento'
            ");
            $delNotifStmt->execute([$schoolId, $studentId]);

            echo json_encode(['status' => 'ok', 'message' => 'Seguimiento iniciado', 'tracking_id' => $trackingId]);
        } catch (Throwable $e) {
            http_response_code(500);
            $errDetail = $e->getMessage();
            $resp = json_encode(['status' => 'error', 'message' => 'Error al iniciar seguimiento', 'detail' => $errDetail]);
            if ($resp === false) {
                $resp = json_encode(['status' => 'error', 'message' => 'Error al iniciar seguimiento (Fallo de codificación JSON)', 'detail' => 'Error SQL o interno con caracteres no válidos.']);
            }
            echo $resp;
        }
        exit;
    }

    // V-151: derivar una alerta de riesgo o incidente a seguimiento formal,
    // asignando la dependencia responsable (coordinacion, psicoorientacion…)
    if ($cleanPath === '/tracking/derive' && $method === 'POST') {
        $studentId = $input['student_id'] ?? null;
        $alertId = $input['alert_id'] ?? null;
        $incidentId = $input['incident_id'] ?? null;
        $dependency = trim((string)($input['dependency'] ?? ''));
        $assignedTo = $input['assigned_to_user_id'] ?? null;
        $reason = trim((string)($input['reason'] ?? ''));

        if (!$studentId || !isValidUUID($studentId)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'student_id válido requerido']));
        }
        if (!$alertId && !$incidentId) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'alert_id o incident_id requerido']));
        }
        if ($dependency === '') {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'dependency requerida (coordinacion, psicoorientacion, rectoria…)']));
        }

        try {
            $stuCheck = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND school_id = ? AND active = TRUE AND deleted_at IS NULL");
            $stuCheck->execute([$studentId, $schoolId]);
            if (!$stuCheck->fetchColumn()) {
                http_response_code(404);
                exit(json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado o inactivo']));
            }

            // Verificar que el origen pertenece a la escuela
            if ($alertId) {
                $originCheck = $conn->prepare("SELECT 1 FROM risk_alerts WHERE alert_id = ? AND school_id = ?");
                $originCheck->execute([$alertId, $schoolId]);
                $originType = 'risk_alert';
                $originId = $alertId;
            } else {
                $originCheck = $conn->prepare("SELECT 1 FROM attendance_incidents WHERE incident_id = ? AND school_id = ?");
                $originCheck->execute([$incidentId, $schoolId]);
                $originType = 'incident';
                $originId = $incidentId;
            }
            if (!$originCheck->fetchColumn()) {
                http_response_code(404);
                exit(json_encode(['status' => 'error', 'message' => 'Origen no encontrado en esta escuela']));
            }

            $checkStmt = $conn->prepare("SELECT tracking_id FROM student_tracking WHERE student_id = ? AND school_id = ? AND status = 'en proceso'");
            $checkStmt->execute([$studentId, $schoolId]);
            $existing = $checkStmt->fetchColumn();
            if ($existing) {
                echo json_encode(['status' => 'ok', 'message' => 'Estudiante ya está en seguimiento', 'tracking_id' => $existing]);
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO student_tracking (school_id, student_id, status, dependency, assigned_to_user_id, origin_type, origin_id)
                VALUES (?, ?, 'en proceso', ?, ?, ?, ?) RETURNING tracking_id
            ");
            $stmt->execute([$schoolId, $studentId, $dependency, $assignedTo, $originType, $originId]);
            $trackingId = $stmt->fetchColumn();

            if ($reason) {
                $conn->prepare("INSERT INTO student_tracking_notes (tracking_id, user_id, note_text) VALUES (?, ?, ?)")
                    ->execute([$trackingId, $userId, "Derivado a {$dependency}: " . $reason]);
            }

            // Si vino de una alerta abierta, marcarla en seguimiento
            if ($alertId) {
                $conn->prepare("UPDATE risk_alerts SET status = 'en_seguimiento' WHERE alert_id = ? AND status = 'abierta'")
                    ->execute([$alertId]);
            }

            echo json_encode(['status' => 'ok', 'message' => 'Caso derivado a seguimiento', 'tracking_id' => $trackingId]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al derivar', 'detail' => $e->getMessage()]);
        }
        exit;
    }

    if ($cleanPath === '/tracking/notes' && $method === 'POST') {
        $trackingId = $input['tracking_id'] ?? null;
        if ($trackingId !== null && !isValidUUID($trackingId)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'ID de seguimiento con formato inválido.']);
            exit();
        }
        $noteText = trim($input['note_text'] ?? '');
        $status = $input['status'] ?? null;
        // V-150: status es un workflow cerrado, no texto libre
        if ($status !== null && !in_array($status, ['en proceso','resuelto','descartado','escalado'], true)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => "status debe ser 'en proceso'|'resuelto'|'descartado'|'escalado'"]));
        }

        if (!$trackingId || !$noteText) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Faltan parámetros']));
        }

        try {
            $ownerStmt = $conn->prepare("SELECT 1 FROM student_tracking WHERE tracking_id = ? AND school_id = ?");
            $ownerStmt->execute([$trackingId, $schoolId]);
            if (!$ownerStmt->fetchColumn()) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'No tienes acceso a este seguimiento.']));
            }

            $stmt = $conn->prepare("INSERT INTO student_tracking_notes (tracking_id, user_id, note_text) VALUES (?, ?, ?)");
            $stmt->execute([$trackingId, $userId, $noteText]);

            if ($status) {
                $updStmt = $conn->prepare("UPDATE student_tracking SET status = ?, updated_at = NOW() WHERE tracking_id = ?");
                $updStmt->execute([$status, $trackingId]);
            } else {
                $conn->prepare("UPDATE student_tracking SET updated_at = NOW() WHERE tracking_id = ?")->execute([$trackingId]);
            }

            echo json_encode(['status' => 'ok', 'message' => 'Nota agregada']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al agregar nota', 'detail' => $e->getMessage()]);
        }
        exit;
    }

    // POST /tracking/close — cierre formal del caso (V-150).
    // {tracking_id, outcome: resuelto|descartado|escalado, reason?}
    if ($cleanPath === '/tracking/close' && $method === 'POST') {
        $trackingId = $input['tracking_id'] ?? null;
        $outcome = $input['outcome'] ?? null;
        $reason = trim($input['reason'] ?? '');
        if ($trackingId !== null && !isValidUUID($trackingId)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'tracking_id inválido']));
        }
        if (!in_array($outcome, ['resuelto','descartado','escalado'], true)) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'outcome debe ser resuelto|descartado|escalado']));
        }
        try {
            $ownerStmt = $conn->prepare("SELECT student_id FROM student_tracking WHERE tracking_id = ? AND school_id = ? AND status = 'en proceso'");
            $ownerStmt->execute([$trackingId, $schoolId]);
            $trkStudent = $ownerStmt->fetchColumn();
            if (!$trkStudent) {
                http_response_code(404);
                exit(json_encode(['status' => 'error', 'message' => 'Seguimiento no encontrado o ya cerrado']));
            }
            $conn->prepare("UPDATE student_tracking SET status = ?, updated_at = NOW() WHERE tracking_id = ?")
                ->execute([$outcome, $trackingId]);
            $conn->prepare("INSERT INTO student_tracking_notes (tracking_id, user_id, note_text) VALUES (?, ?, ?)")
                ->execute([$trackingId, $userId, "Cierre de seguimiento — resultado: $outcome" . ($reason !== '' ? ". Motivo: $reason" : '')]);
            securityLog('TRACKING_CLOSED', "tracking=$trackingId outcome=$outcome student=$trkStudent", $userId, $schoolId);
            echo json_encode(['status' => 'ok', 'message' => 'Seguimiento cerrado', 'tracking_id' => $trackingId, 'outcome' => $outcome]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al cerrar seguimiento', 'detail' => $e->getMessage()]);
        }
        exit;
    }

    if ($cleanPath === '/tracking/details' && $method === 'GET') {
        $trackingId = $_GET['tracking_id'] ?? null;
        if ($trackingId !== null && !isValidUUID($trackingId)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'ID de seguimiento con formato inválido.']);
            exit();
        }
        if (!$trackingId) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'tracking_id es requerido']));
        }

        try {
            $stmt = $conn->prepare("SELECT tracking_id, student_id, status, dependency, assigned_to_user_id, origin_type, origin_id, created_at, updated_at FROM student_tracking WHERE tracking_id = ? AND school_id = ?");
            $stmt->execute([$trackingId, $schoolId]);
            $tracking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tracking) {
                http_response_code(404);
                exit(json_encode(['status' => 'error', 'message' => 'Seguimiento no encontrado']));
            }

            $notesStmt = $conn->prepare("
                SELECT n.note_id, n.note_text, n.created_at, u.first_name, u.last_name, r.role_name 
                FROM student_tracking_notes n
                JOIN users u ON n.user_id = u.user_id
                JOIN roles r ON u.role_id = r.role_id
                WHERE n.tracking_id = ?
                ORDER BY n.created_at DESC
            ");
            $notesStmt->execute([$trackingId]);
            $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Nombre del responsable asignado (si hay)
            $assignedName = null;
            if (!empty($tracking['assigned_to_user_id'])) {
                $uStmt = $conn->prepare("SELECT TRIM(first_name || ' ' || COALESCE(last_name,'')) FROM users WHERE user_id = ?");
                $uStmt->execute([$tracking['assigned_to_user_id']]);
                $assignedName = $uStmt->fetchColumn() ?: null;
            }

            // Respuestas del acudiente del mismo estudiante (últimos 30 días).
            // Viven en attendance_incidents.metadata_json->guardian_response —
            // no en las notas del seguimiento. Se muestran como cita real.
            $respStmt = $conn->prepare("
                SELECT ai.incident_type,
                       ai.detected_at,
                       ai.metadata_json->>'guardian_response'      AS guardian_response,
                       ai.metadata_json->>'guardian_response_at'   AS responded_at,
                       ai.metadata_json->>'guardian_name'          AS guardian_name
                FROM attendance_incidents ai
                WHERE ai.school_id = ? AND ai.student_id = ?
                  AND COALESCE(ai.metadata_json->>'guardian_response','') <> ''
                  AND ai.detected_at >= NOW() - INTERVAL '30 days'
                ORDER BY ai.detected_at DESC LIMIT 10
            ");
            $respStmt->execute([$schoolId, $tracking['student_id']]);
            $guardianResponses = $respStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'ok',
                'tracking' => array_merge($tracking, ['assigned_name' => $assignedName]),
                'notes' => $notes,
                'guardian_responses' => $guardianResponses,
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al obtener detalles', 'detail' => $e->getMessage()]);
        }
        exit;
    }

    if ($cleanPath === '/tracking/active' && $method === 'GET') {
        try {
            $stmt = $conn->prepare("
                SELECT st.tracking_id, st.student_id, st.status, st.dependency, st.origin_type, st.updated_at,
                       s.first_name, s.last_name, s.document_number,
                       ag.group_name
                FROM student_tracking st
                JOIN students s ON st.student_id = s.student_id
                LEFT JOIN student_group_assignments sga ON s.student_id = sga.student_id AND sga.active = TRUE
                LEFT JOIN academic_groups ag ON sga.group_id = ag.group_id
                WHERE st.school_id = ? AND st.status = 'en proceso'
                ORDER BY st.updated_at DESC
            ");
            $stmt->execute([$schoolId]);
            echo json_encode(['status' => 'ok', 'trackings' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al listar seguimientos', 'detail' => $e->getMessage()]);
        }
        exit;
    }
}
