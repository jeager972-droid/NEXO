<?php
// routes/groups.php - Gestión de grupos académicos
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

if ($cleanPath === '/groups') {
    $authUser = requireAuth();
    $schoolId = $_GET['school_id'] ?? $input['school_id'] ?? $authUser['school_id'];
    if (!$schoolId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID de institución requerido']));
    }

    try {
        $stmt = $conn->prepare("
            SELECT group_id as id, group_name as name, grade_level 
            FROM academic_groups 
            WHERE school_id = ? 
            ORDER BY grade_level, group_name
        ");
        $stmt->execute([$schoolId]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'ok', 'data' => $groups]);
    } catch (Exception $e) {
        securityLog('GROUPS_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener grupos']);
    }
    exit;
}
