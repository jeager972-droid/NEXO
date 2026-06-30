<?php
// routes/groups.php - Gestión de grupos académicos
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

if ($cleanPath === '/groups') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $teacherOnly = filter_var($_GET['teacher_only'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    if (!$schoolId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID de institución requerido']));
    }

    try {
        $userRole = strtoupper($authUser['role'] ?? '');
        $isTeacher = in_array($userRole, ['DOCENTE', 'PSICORIENTADOR']);

        if ($teacherOnly && $isTeacher) {
            // FIX: intentar grupos via schedules; si no hay, fallback a todos los grupos de la institución
            $stmt = $conn->prepare("
                SELECT ag.group_id as id, ag.group_name as name, ag.grade_level
                FROM schedules sch
                JOIN academic_groups ag ON ag.group_id = sch.group_id
                WHERE sch.teacher_user_id = ?
                GROUP BY ag.group_id, ag.group_name, ag.grade_level
                ORDER BY ag.grade_level, ag.group_name
            ");
            $stmt->execute([$authUser['id']]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $conn->prepare("
                SELECT ag.group_id as id, ag.group_name as name, ag.grade_level, COUNT(sga.student_id) as student_count
                FROM academic_groups ag
                LEFT JOIN student_group_assignments sga ON ag.group_id = sga.group_id AND sga.active = TRUE
                WHERE ag.school_id = ?
                GROUP BY ag.group_id, ag.group_name, ag.grade_level
                ORDER BY COUNT(sga.student_id) DESC, ag.grade_level, ag.group_name
            ");
            $stmt->execute([$schoolId]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['status' => 'ok', 'data' => $groups]);
    } catch (Exception $e) {
        $details = $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine();
        securityLog('GROUPS_ERROR', $details);
        http_response_code(500);
        $isDev = (getenv('APP_ENV') ?: 'production') === 'development';
        echo json_encode([
            'status' => 'error',
            'message' => 'Error al obtener grupos',
            'detail' => $isDev ? $details : 'Revisa los logs del servidor para más información'
        ]);
    }
    exit;
}
