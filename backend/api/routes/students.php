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
            echo json_encode(['status' => 'error', 'message' => 'Error al registrar estudiante', 'debug' => $e->getMessage()]);
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

        $grade = trim($_GET['grade'] ?? '');
        if ($grade !== '') {
            $whereClauses[] = "s.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.grade_level = ? AND sga.active = TRUE)";
            $params[] = $grade;
        }

        $lastId = trim($_GET['last_id'] ?? '');
        $lastCreatedAt = trim($_GET['last_created_at'] ?? '');
        if ($lastId !== '') {
            $whereClauses[] = 's.student_id < ?';
            $params[] = $lastId;
        } elseif ($lastCreatedAt !== '') {
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
                COALESCE(ag.group_name, 'Sin grupo') as group_name,
                (s.biometric_hash IS NOT NULL) as has_fingerprint
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
                'last_id'         => $lastRow ? $lastRow['id'] : null,
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

// ============================================================================
// POST /students/bulk-assign — Asignar múltiples estudiantes a un grupo
//
// Endpoint de recuperación post-onboarding: permite al RECTOR/SECRETARY
// reasignar estudiantes que quedaron sin grupo (o mal asignados) tras un
// onboarding. Útil cuando la migración 2026-36 no pudo inferir el grado
// (students.grade_level era NULL).
//
// Payload: { group_id, student_ids: [uuid,...] }
// ============================================================================
if ($cleanPath === '/students/bulk-assign' && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'SECRETARY']);
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];

    $groupId = trim((string)($input['group_id'] ?? ''));
    $studentIds = $input['student_ids'] ?? [];

    if ($groupId === '') {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'group_id es requerido']));
    }
    if (!is_array($studentIds) || empty($studentIds)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'student_ids debe ser un array no vacío']));
    }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $useSavepoint = $conn->inTransaction();
        $sp = 'sp_bulk_assign';
        if ($useSavepoint) {
            $conn->exec("SAVEPOINT $sp");
        } else {
            $conn->beginTransaction();
        }

        // Validar que el grupo pertenece a la escuela y obtener grade_level
        $groupStmt = $conn->prepare("SELECT group_id, grade_level FROM academic_groups WHERE group_id = ? AND school_id = ?");
        $groupStmt->execute([$groupId, $schoolId]);
        $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            if ($useSavepoint) { $conn->exec("ROLLBACK TO SAVEPOINT $sp"); } else { try { $conn->rollBack(); } catch (Exception $ignore) {} }
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Grupo no encontrado en esta institución']));
        }

        // Validar que los estudiantes pertenecen a la escuela
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $validStmt = $conn->prepare("SELECT student_id FROM students WHERE student_id IN ($placeholders) AND school_id = ? AND deleted_at IS NULL");
        $validStmt->execute(array_merge($studentIds, [$schoolId]));
        $validIds = $validStmt->fetchAll(PDO::FETCH_COLUMN);

        $assignStmt = $conn->prepare("
            INSERT INTO student_group_assignments (student_id, group_id, active, start_date)
            VALUES (?, ?, TRUE, CURRENT_DATE)
            ON CONFLICT (student_id, group_id) DO UPDATE SET active = TRUE, start_date = CURRENT_DATE
        ");
        $deactivateStmt = $conn->prepare("UPDATE student_group_assignments SET active = FALSE WHERE student_id = ? AND group_id != ?");
        $updateGradeStmt = $conn->prepare("UPDATE students SET grade_level = ? WHERE student_id = ?");

        $assigned = 0;
        foreach ($validIds as $sid) {
            $deactivateStmt->execute([$sid, $groupId]);
            $assignStmt->execute([$sid, $groupId]);
            if ($group['grade_level']) {
                $updateGradeStmt->execute([$group['grade_level'], $sid]);
            }
            $assigned++;
        }

        if ($useSavepoint) { $conn->exec("RELEASE SAVEPOINT $sp"); } else { $conn->commit(); }
        securityLog('STUDENTS_BULK_ASSIGN', "School: $schoolId, Group: $groupId, Students: $assigned, By: $userId", $userId, $schoolId);
        echo json_encode(['status' => 'ok', 'message' => 'Estudiantes asignados correctamente', 'assigned' => $assigned]);
    } catch (Exception $e) {
        if ($useSavepoint ?? false) {
            try { $conn->exec("ROLLBACK TO SAVEPOINT $sp"); } catch (Exception $ignore) {}
        } else {
            try { $conn->rollBack(); } catch (Exception $ignore) {}
        }
        securityLog('STUDENTS_BULK_ASSIGN_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al asignar estudiantes', 'debug' => $e->getMessage()]);
    }
    exit;
}

