<?php
// routes/students.php - Gestión de estudiantes
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

/**
 * @OA\Get(
 *     path="/students",
 *     summary="Listar estudiantes",
 *     description="Obtiene la lista paginada de estudiantes de la escuela autenticada. Soporta búsqueda por nombre, apellido o documento.",
 *     tags={"Estudiantes"},
 *     security={{"cookieAuth":{}}},
 *     @OA\Parameter(name="last_id", in="query", description="Último student_id recibido (cursor)", @OA\Schema(type="integer", default=0)),
 *     @OA\Parameter(name="limit", in="query", description="Resultados por página (max 100)", @OA\Schema(type="integer", default=50)),
 *     @OA\Parameter(name="search", in="query", description="Término de búsqueda", @OA\Schema(type="string")),
 *     @OA\Response(
 *         response=200,
 *         description="Lista de estudiantes",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="string", example="ok"),
 *             @OA\Property(property="data", type="array", @OA\Items(
 *                 @OA\Property(property="id", type="integer"),
 *                 @OA\Property(property="first_name", type="string"),
 *                 @OA\Property(property="last_name", type="string"),
 *                 @OA\Property(property="document_number", type="string"),
 *                 @OA\Property(property="active", type="boolean"),
 *                 @OA\Property(property="group_name", type="string")
 *             )),
 *             @OA\Property(property="meta", type="object",
 *                 @OA\Property(property="limit", type="integer"),
 *                 @OA\Property(property="last_id", type="integer"),
 *                 @OA\Property(property="has_more", type="boolean")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=400, description="ID de institución requerido"),
 *     @OA\Response(response=401, description="No autenticado")
 * )
 */
if ($cleanPath === '/students') {
    $authUser = requireAuth();
    $schoolId = $_GET['school_id'] ?? $input['school_id'] ?? $authUser['school_id'];
    if (!$schoolId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID de institución requerido']));
    }

    try {
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $lastId = trim($_GET['last_id'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $params = [$schoolId];
        $whereClauses = ['s.school_id = ?'];

        if ($lastId !== '') {
            $whereClauses[] = 's.student_id > ?';
            $params[] = $lastId;
        }

        if ($search !== '') {
            $whereClauses[] = "(s.first_name ILIKE ? OR s.last_name ILIKE ? OR s.document_number ILIKE ?)";
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $whereClauses);

        // FIX: Cursor pagination (keyset) — O(1) rendimiento en cualquier página
        $params[] = $limit;
        $stmt = $conn->prepare("
            SELECT
                s.student_id as id,
                s.first_name,
                s.last_name,
                s.document_number,
                s.active,
                COALESCE(ag.group_name, 'Sin grupo') as group_name
            FROM students s
            LEFT JOIN student_group_assignments sga
              ON s.student_id = sga.student_id AND sga.active = TRUE
            LEFT JOIN academic_groups ag
              ON sga.group_id = ag.group_id
            WHERE {$whereSql}
            ORDER BY s.student_id
            LIMIT ?
        ");
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nextLastId = count($students) > 0 ? $students[count($students) - 1]['id'] : $lastId;

        echo json_encode([
            'status' => 'ok',
            'data' => $students,
            'meta' => [
                'limit' => $limit,
                'last_id' => $nextLastId,
                'has_more' => count($students) === $limit
            ]
        ]);
    } catch (Exception $e) {
        securityLog('STUDENTS_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener estudiantes']);
    }
    exit;
}
