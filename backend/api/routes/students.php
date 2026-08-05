<?php
/**
 * =============================================================================
 * routes/students.php — Gestión de estudiantes.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone endpoints para crear y listar estudiantes de una escuela:
 *   - POST /students : crea/actualiza un estudiante (upsert por school_id + document_number)
 *                      y opcionalmente lo asigna a un grupo.
 *   - GET  /students : lista paginada con filtros por búsqueda, grupo y cursor
 *                      (last_created_at). Requiere autenticación.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación y roles.
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Frontend: módulo de estudiantes, matrícula, selectores de estudiante.
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

// ============================================================================
// POST /students — Crear o actualizar estudiante y asignar grupo.
// GET  /students — Listado paginado y filtrado de estudiantes.
// ============================================================================
if ($cleanPath === '/students') {
    if ($method === 'POST') {
        $authUser = requireAuth(['SECRETARY', 'RECTOR', 'COORDINATOR']);
        $schoolId = $authUser['school_id'];

        $firstName  = trim($input['first_name'] ?? '');
        $lastName   = trim($input['last_name']  ?? '');
        $document   = trim($input['document']   ?? '');
        $groupName  = trim($input['grade']      ?? '');
        $workShift  = trim($input['work_shift'] ?? 'mañana');

        if (!$firstName || !$lastName || !$document) {
            http_response_code(400);
            exit(json_encode(['status' => 'error', 'message' => 'Nombre, apellido y documento son obligatorios']));
        }

        try {
            // FIX (PgBouncer): requireAuth() ya inició una transacción.
            // Usamos savepoint para rollback parcial sin romper la transacción principal.
            $useSavepoint = $conn->inTransaction();
            $sp = 'sp_student_' . uniqid();
            if ($useSavepoint) {
                $conn->exec("SAVEPOINT $sp");
            } else {
                $conn->beginTransaction();
            }

            $stmt = $conn->prepare("
                INSERT INTO students (school_id, first_name, last_name, document_number, work_shift)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (school_id, document_number) DO UPDATE
                  SET first_name = EXCLUDED.first_name,
                      last_name  = EXCLUDED.last_name,
                      work_shift = EXCLUDED.work_shift,
                      deleted_at = NULL
                RETURNING student_id
            ");
            $stmt->execute([$schoolId, $firstName, $lastName, $document, $workShift]);
            $studentId = $stmt->fetchColumn();

            if ($groupName) {
                $groupStmt = $conn->prepare("SELECT group_id FROM academic_groups WHERE group_name = ? AND school_id = ? LIMIT 1");
                $groupStmt->execute([$groupName, $schoolId]);
                $groupId = $groupStmt->fetchColumn();
                if ($groupId) {
                    $deactivateStmt = $conn->prepare(
                        "UPDATE student_group_assignments SET active = FALSE 
                         WHERE student_id = ? AND group_id != ?"
                    );
                    $deactivateStmt->execute([$studentId, $groupId]);
                    
                    $assignStmt = $conn->prepare("
                        INSERT INTO student_group_assignments (student_id, group_id, active)
                        VALUES (?, ?, TRUE)
                        ON CONFLICT (student_id, group_id) DO UPDATE SET active = TRUE
                    ");
                    $assignStmt->execute([$studentId, $groupId]);
                }
            }

            if ($useSavepoint) {
                $conn->exec("RELEASE SAVEPOINT $sp");
            } else {
                $conn->commit();
            }
            securityLog('STUDENT_CREATED', "ID:$studentId Doc:$document", $authUser['id'], $schoolId);
            http_response_code(201);
            echo json_encode(['status' => 'ok', 'student_id' => $studentId]);
        } catch (Throwable $e) {
            if ($useSavepoint ?? false) {
                $conn->exec("ROLLBACK TO SAVEPOINT $sp");
            } else {
                try { $conn->rollBack(); } catch (Exception $ignore) {}
            }
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al registrar estudiante']);
        }
        exit;
    }

    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    if (!$schoolId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID de institución requerido']));
    }

    try {
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $search = trim($_GET['search'] ?? '');

        $params = [$schoolId];
        $whereClauses = ['s.school_id = ?'];

        $groupName = trim($_GET['group_name'] ?? '');
        if ($groupName !== '') {
            $whereClauses[] = "s.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)";
            $params[] = $groupName;
        }

        $lastCreatedAt = trim($_GET['last_created_at'] ?? '');
        if ($lastCreatedAt !== '') {
            $whereClauses[] = 's.created_at < ?';
            $params[] = $lastCreatedAt;
        }

        if ($search !== '') {
            $whereClauses[] = "(s.first_name ILIKE ? OR s.last_name ILIKE ? OR s.document_number ILIKE ?)";
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $whereClauses);

        $params[] = $limit;
        $stmt = $conn->prepare("
            SELECT
                s.student_id as id,
                s.first_name,
                s.last_name,
                s.document_number,
                (s.deleted_at IS NULL) as active,
                s.created_at,
                COALESCE(ag.group_name, 'Sin grupo') as group_name
            FROM students s
            LEFT JOIN student_group_assignments sga
              ON s.student_id = sga.student_id AND sga.active = TRUE
            LEFT JOIN academic_groups ag
              ON sga.group_id = ag.group_id
            WHERE {$whereSql}
            ORDER BY s.created_at DESC, s.student_id DESC
            LIMIT ?
        ");
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lastRow = count($students) > 0 ? $students[count($students) - 1] : null;

        echo json_encode([
            'status' => 'ok',
            'data'   => $students,
            'meta'   => [
                'limit'           => $limit,
                'last_created_at' => $lastRow ? $lastRow['created_at'] : null,
                'has_more'        => count($students) === $limit,
            ]
        ]);
    } catch (Exception $e) {
        $details = $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine();
        securityLog('STUDENTS_ERROR', $details);
        http_response_code(500);
        $isDev = (getenv('APP_ENV') ?: 'production') === 'development';
        echo json_encode([
            'status' => 'error',
            'message' => 'Error al obtener estudiantes',
            'detail' => $isDev ? $details : 'Revisa los logs del servidor para más información'
        ]);
    }
    exit;
}
