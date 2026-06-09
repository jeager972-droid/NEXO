<?php
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

if (strpos($cleanPath, '/tracking') === 0) {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $role = strtoupper($authUser['role'] ?? '');

    if (!in_array($role, ['COORDINADOR', 'RECTOR', 'SUPER_RECTOR'])) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Acceso restringido']));
    }

    if ($cleanPath === '/tracking/start' && $method === 'POST') {
        $studentId = $input['student_id'] ?? null;
        if (!$studentId) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'student_id es requerido']));
        }

        try {
            // Check if already in tracking
            $checkStmt = $conn->prepare("SELECT tracking_id FROM student_tracking WHERE student_id = ? AND school_id = ? AND status = 'en proceso'");
            $checkStmt->execute([$studentId, $schoolId]);
            $existing = $checkStmt->fetchColumn();

            if ($existing) {
                echo json_encode(['status' => 'ok', 'message' => 'Estudiante ya está en seguimiento', 'tracking_id' => $existing]);
                exit;
            }

            $stmt = $conn->prepare("INSERT INTO student_tracking (school_id, student_id, status) VALUES (?, ?, 'en proceso') RETURNING tracking_id");
            $stmt->execute([$schoolId, $studentId]);
            $trackingId = $stmt->fetchColumn();

            echo json_encode(['status' => 'ok', 'message' => 'Seguimiento iniciado', 'tracking_id' => $trackingId]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al iniciar seguimiento', 'detail' => $e->getMessage()]);
        }
        exit;
    }

    if ($cleanPath === '/tracking/notes' && $method === 'POST') {
        $trackingId = $input['tracking_id'] ?? null;
        $noteText = trim($input['note_text'] ?? '');
        $status = $input['status'] ?? null;

        if (!$trackingId || !$noteText) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Faltan parámetros']));
        }

        try {
            $stmt = $conn->prepare("INSERT INTO student_tracking_notes (tracking_id, user_id, note_text) VALUES (?, ?, ?)");
            $stmt->execute([$trackingId, $userId, $noteText]);

            if ($status) {
                $updStmt = $conn->prepare("UPDATE student_tracking SET status = ?, updated_at = NOW() WHERE tracking_id = ?");
                $updStmt->execute([$status, $trackingId]);
            } else {
                $conn->prepare("UPDATE student_tracking SET updated_at = NOW() WHERE tracking_id = ?")->execute([$trackingId]);
            }

            echo json_encode(['status' => 'ok', 'message' => 'Nota agregada']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al agregar nota', 'detail' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($cleanPath === '/tracking/details' && $method === 'GET') {
        $trackingId = $_GET['tracking_id'] ?? null;
        if (!$trackingId) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'tracking_id es requerido']));
        }

        try {
            $stmt = $conn->prepare("SELECT tracking_id, student_id, status, created_at, updated_at FROM student_tracking WHERE tracking_id = ? AND school_id = ?");
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

            echo json_encode(['status' => 'ok', 'tracking' => $tracking, 'notes' => $notes]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al obtener detalles', 'detail' => $e->getMessage()]);
        }
        exit;
    }
}
