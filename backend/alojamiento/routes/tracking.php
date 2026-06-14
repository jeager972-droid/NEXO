<?php
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

function isValidUUID($value) {
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        (string) $value
    );
}

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
            // Auto-create tracking tables if they don't exist
            try {
                $conn->exec('CREATE EXTENSION IF NOT EXISTS "uuid-ossp";');
                $conn->exec("
                    CREATE TABLE IF NOT EXISTS student_tracking (
                        tracking_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                        school_id UUID NOT NULL REFERENCES schools(school_id) ON DELETE CASCADE,
                        student_id UUID NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
                        status VARCHAR(50) NOT NULL DEFAULT 'en proceso',
                        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
                    );

                    CREATE TABLE IF NOT EXISTS student_tracking_notes (
                        note_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                        tracking_id UUID NOT NULL REFERENCES student_tracking(tracking_id) ON DELETE CASCADE,
                        user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
                        note_text TEXT NOT NULL,
                        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
                    );
                ");
            } catch (Throwable $setupError) {
                // Ignore setup errors (e.g. lack of permissions for extensions)
                error_log('Tracking setup error: ' . $setupError->getMessage());
            }

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
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al obtener detalles', 'detail' => $e->getMessage()]);
        }
        exit;
    }

    if ($cleanPath === '/tracking/active' && $method === 'GET') {
        try {
            $stmt = $conn->prepare("
                SELECT st.tracking_id, st.student_id, st.status, st.updated_at,
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
