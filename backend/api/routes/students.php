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
// GET /students/unassigned — Estudiantes sin grupo para el año actual
// ============================================================================
if ($cleanPath === '/students/unassigned' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR', 'SECRETARY']);
    $schoolId = $authUser['school_id'];
    $currentYear = (int)date('Y');

    try {
        $search = trim($_GET['search'] ?? '');
        $params = [$schoolId, $currentYear];

        $searchClause = '';
        if ($search !== '') {
            $searchClause = " AND (s.first_name ILIKE ? OR s.last_name ILIKE ? OR s.document_number ILIKE ?)";
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $stmt = $conn->prepare("
            SELECT s.student_id as id, s.first_name, s.last_name, s.document_number,
                   s.created_at,
                   COALESCE(ag_old.group_name, 'Sin grupo') as previous_group
            FROM students s
            LEFT JOIN student_group_assignments sga_old
              ON s.student_id = sga_old.student_id AND sga_old.active = TRUE
            LEFT JOIN academic_groups ag_old
              ON sga_old.group_id = ag_old.group_id
            WHERE s.school_id = ?
              AND s.deleted_at IS NULL
              AND s.student_id NOT IN (
                SELECT sga.student_id FROM student_group_assignments sga
                JOIN academic_groups ag ON sga.group_id = ag.group_id
                WHERE ag.school_id = ? AND ag.academic_year = ? AND sga.active = TRUE
              )
              {$searchClause}
            ORDER BY s.first_name, s.last_name
            LIMIT 100
        ");
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'ok', 'data' => $students]);
    } catch (Exception $e) {
        securityLog('STUDENTS_UNASSIGNED_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener estudiantes sin grupo', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// POST /students/{id}/assign-group — Asignar estudiante a un grupo
// ============================================================================
if (preg_match('#^/students/([0-9a-fA-F\-]+)/assign-group$#', $cleanPath, $matches) && $method === 'POST') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR', 'SECRETARY']);
    $schoolId = $authUser['school_id'];
    $studentId = $matches[1];
    $groupId = trim((string)($input['group_id'] ?? ''));

    if (!$groupId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'group_id es obligatorio']));
    }

    try {
        // Validar que el estudiante pertenece a la escuela
        $studentCheck = $conn->prepare("SELECT 1 FROM students WHERE student_id = ? AND school_id = ? AND deleted_at IS NULL");
        $studentCheck->execute([$studentId, $schoolId]);
        if (!$studentCheck->fetchColumn()) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']));
        }

        // Validar que el grupo pertenece a la escuela
        $groupCheck = $conn->prepare("SELECT group_name FROM academic_groups WHERE group_id = ? AND school_id = ?");
        $groupCheck->execute([$groupId, $schoolId]);
        $groupName = $groupCheck->fetchColumn();
        if (!$groupName) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Grupo no encontrado']));
        }

        // Desactivar asignaciones anteriores
        $conn->prepare("UPDATE student_group_assignments SET active = FALSE WHERE student_id = ?")
            ->execute([$studentId]);

        // Insertar nueva asignación
        $conn->prepare("
            INSERT INTO student_group_assignments (student_id, group_id, active, start_date)
            VALUES (?, ?, TRUE, CURRENT_DATE)
            ON CONFLICT (student_id, group_id) DO UPDATE SET active = TRUE, start_date = CURRENT_DATE
        ")->execute([$studentId, $groupId]);

        securityLog('STUDENT_ASSIGNED_GROUP', "Student: $studentId, Group: $groupName ($groupId)", $authUser['id'], $schoolId);

        echo json_encode(['status' => 'ok', 'message' => "Estudiante asignado a {$groupName}"]);
    } catch (Exception $e) {
        securityLog('STUDENT_ASSIGN_GROUP_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al asignar estudiante', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// GET /groups — Listar grupos del año actual con conteo de estudiantes
// ============================================================================
if ($cleanPath === '/groups' && $method === 'GET') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $currentYear = (int)date('Y');

    try {
        $stmt = $conn->prepare("
            SELECT ag.group_id, ag.group_name, ag.grade_level, ag.academic_year,
                   COUNT(sga.student_id) FILTER (WHERE sga.active = TRUE) as student_count
            FROM academic_groups ag
            LEFT JOIN student_group_assignments sga ON ag.group_id = sga.group_id AND sga.active = TRUE
            WHERE ag.school_id = ? AND ag.academic_year = ?
            GROUP BY ag.group_id, ag.group_name, ag.grade_level, ag.academic_year
            ORDER BY ag.grade_level::INT, ag.group_name
        ");
        $stmt->execute([$schoolId, $currentYear]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'ok',
            'message' => "Estudiantes migrados al siguiente grado",
            'students_assigned' => $assigned,
            'from_year' => $oldYear,
            'to_year' => $currentYear,
        ]);
    } catch (Exception $e) {
        securityLog('GROUPS_ROLLOVER_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al migrar estudiantes', 'debug' => $e->getMessage()]);
    }
    exit;
}
