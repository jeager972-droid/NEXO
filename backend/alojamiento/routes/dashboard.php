<?php
// routes/dashboard.php - Estadísticas del panel
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

if ($cleanPath === '/dashboard/stats') {
    $authUser = requireAuth();
    $schoolId = $_GET['school_id'] ?? $input['school_id'] ?? $authUser['school_id'];
    securityLog('DASHBOARD_STATS_REQUEST', "School ID: " . ($schoolId ?? 'NULL'));
    
    if (!$schoolId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID de institución requerido']));
    }
    
    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // FIX: Intentar leer contador de presentes desde Redis primero (cache)
        $presentCount = null;
        $today = gmdate('Y-m-d');
        try {
            $redis = new Redis();
            $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
            if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
            $cached = $redis->get("school:{$schoolId}:present:{$today}");
            if ($cached !== false) {
                $presentCount = (int)$cached;
            }
        } catch (Exception $e) { /* Redis no disponible, fallback a DB */ }

        // 1. Conteo de estudiantes presentes (Bogotá TZ) - FIX: LIKE 'INGRESO_%'
        if ($presentCount === null) {
            $presentStmt = $conn->prepare("
                SELECT COUNT(DISTINCT student_id)
                FROM biometric_events
                WHERE school_id = ?
                  AND (event_timestamp AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
                  AND event_type LIKE 'INGRESO_%'
            ");
            $presentStmt->execute([$schoolId]);
            $presentCount = $presentStmt->fetchColumn();

            // Guardar en caché para próximas consultas (TTL 5 minutos)
            try {
                $redis = new Redis();
                $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
                if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
                $redis->setex("school:{$schoolId}:present:{$today}", 300, (int)$presentCount);
            } catch (Exception $e) { /* Redis no disponible, se omite caché */ }
        }

        // 2. Conteo de inasistencias (Bogotá TZ)
        $absentStmt = $conn->prepare("
            SELECT COUNT(*) FROM attendance_incidents 
            WHERE school_id = ?
              AND (detected_at AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
              AND incident_type = 'INASISTENCIA'
        ");
        $absentStmt->execute([$schoolId]);
        $absentCount = $absentStmt->fetchColumn();

        // 3. Alertas SOS (Bogotá TZ)
        $alertsStmt = $conn->prepare("
            SELECT COUNT(*) FROM sos_alerts 
            WHERE school_id = ?
              AND (emitted_at AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
              AND resolved = FALSE
        ");
        $alertsStmt->execute([$schoolId]);
        $alertsCount = $alertsStmt->fetchColumn();

        // 4. Tareas pendientes (Reportes) (Bogotá TZ)
        $tasksStmt = $conn->prepare("
            SELECT report_export_id as id, report_type as title, 
            TO_CHAR(generated_at AT TIME ZONE 'America/Bogota', 'HH24:MI') as time 
            FROM report_exports 
            WHERE school_id = ?
              AND (generated_at AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
            LIMIT 5
        ");
        $tasksStmt->execute([$schoolId]);
        $pendingTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Estudiantes por grupo (FIX: docentes solo ven grupos asignados via schedules)
        $userRole = strtoupper($authUser['role'] ?? '');
        $teacherFilter = '';
        if ($userRole === 'DOCENTE' || $userRole === 'PSICORIENTADOR') {
            $teacherFilter = " AND ag.group_id IN (
                SELECT sch.group_id FROM schedules sch
                WHERE sch.teacher_user_id = ?
            )";
        }

        $groupsSql = "
            SELECT ag.group_name, s.first_name || ' ' || s.last_name as name
            FROM students s
            JOIN student_group_assignments sga ON s.student_id = sga.student_id AND sga.active = TRUE
            JOIN academic_groups ag ON sga.group_id = ag.group_id
            WHERE s.school_id = ? {$teacherFilter}
        ";
        $groupsStmt = $conn->prepare($groupsSql);
        if ($teacherFilter) {
            $groupsStmt->execute([$schoolId, $authUser['id']]);
        } else {
            $groupsStmt->execute([$schoolId]);
        }
        $allStudents = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $studentsByGroup = [];
        foreach ($allStudents as $row) {
            $studentsByGroup[$row['group_name']][] = ['name' => $row['name']];
        }

        echo json_encode([
            'status' => 'ok',
            'presentCount' => (int)$presentCount,
            'absentCount' => (int)$absentCount,
            'alertsCount' => (int)$alertsCount,
            'pendingTasks' => $pendingTasks,
            'studentsByGroup' => $studentsByGroup,
            'groupStats' => [
                'present' => (int)$presentCount,
                'absent' => (int)$absentCount,
                'alerts' => (int)$alertsCount,
                'outside' => 0
            ]
        ]);
    } catch (Exception $e) {
        securityLog('DASHBOARD_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener estadísticas']);
    }
    exit;
}

// ============================================================================
// GET /dashboard/teacher-group-detail
// Params: group_name, category=(present|absent|alert|permiso), from_date, to_date
// ============================================================================
if ($cleanPath === '/dashboard/teacher-group-detail') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $userRole = strtoupper($authUser['role'] ?? '');

    $groupName = $_GET['group_name'] ?? '';
    $category = $_GET['category'] ?? '';
    $fromDate = $_GET['from_date'] ?? gmdate('Y-m-d');
    $toDate = $_GET['to_date'] ?? gmdate('Y-m-d');

    if (!$groupName || !in_array($category, ['present', 'absent', 'alert', 'permiso'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'group_name y category requeridos']);
        exit;
    }

    try {
        // Verificar que el docente tenga este grupo asignado (via schedules)
        $validGroup = true;
        if ($userRole === 'DOCENTE' || $userRole === 'PSICORIENTADOR') {
            $checkStmt = $conn->prepare("
                SELECT 1 FROM schedules sch
                JOIN academic_groups ag ON ag.group_id = sch.group_id
                WHERE sch.teacher_user_id = ? AND ag.group_name = ?
                LIMIT 1
            ");
            $checkStmt->execute([$userId, $groupName]);
            $validGroup = (bool)$checkStmt->fetchColumn();
        }

        if (!$validGroup) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Grupo no asignado a este docente']);
            exit;
        }

        $data = [];

        switch ($category) {
            case 'present':
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                           ag.group_name, MAX(be.event_timestamp) as last_entry
                    FROM students s
                    JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                    JOIN academic_groups ag ON ag.group_id = sga.group_id
                    LEFT JOIN biometric_events be ON be.student_id = s.student_id
                        AND be.event_type LIKE 'INGRESO_%'
                        AND (be.event_timestamp AT TIME ZONE 'America/Bogota')::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? AND ag.group_name = ?
                    GROUP BY s.student_id, s.first_name, s.last_name, s.document_number, ag.group_name
                    HAVING MAX(be.event_timestamp) IS NOT NULL
                    ORDER BY last_entry DESC
                ");
                $stmt->execute([$fromDate, $toDate, $schoolId, $groupName]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'absent':
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                           ag.group_name, ai.detected_at as absent_since
                    FROM students s
                    JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                    JOIN academic_groups ag ON ag.group_id = sga.group_id
                    JOIN attendance_incidents ai ON ai.student_id = s.student_id
                        AND ai.incident_type = 'INASISTENCIA'
                        AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? AND ag.group_name = ?
                    ORDER BY absent_since DESC
                ");
                $stmt->execute([$fromDate, $toDate, $schoolId, $groupName]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'alert':
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                           ag.group_name, ai.incident_type as alert_type, ai.detected_at as alert_at
                    FROM students s
                    JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                    JOIN academic_groups ag ON ag.group_id = sga.group_id
                    JOIN attendance_incidents ai ON ai.student_id = s.student_id
                        AND ai.incident_type IN ('LATE_ARRIVAL', 'EARLY_EXIT', 'EVASION_INTERNA',
                                                  'LATE:ARRIVAL', 'EARLY:DEPARTURE', 'EARLY_DEPARTURE',
                                                  'UNAUTHORIZED_ABSENCE', 'UNAUTHORIZED:ABSENCE',
                                                  'BIOMETRIC_FAILURE', 'SPAM_BIOMETRIC')
                        AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? AND ag.group_name = ?
                    ORDER BY alert_at DESC
                ");
                $stmt->execute([$fromDate, $toDate, $schoolId, $groupName]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'permiso':
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                           ag.group_name, ai.incident_type as permiso_type,
                           ai.detected_at as permiso_at,
                           ai.metadata_json->>'reason' as reason
                    FROM students s
                    JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                    JOIN academic_groups ag ON ag.group_id = sga.group_id
                    JOIN attendance_incidents ai ON ai.student_id = s.student_id
                        AND ai.incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')
                        AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? AND ag.group_name = ?
                    ORDER BY permiso_at DESC
                ");
                $stmt->execute([$fromDate, $toDate, $schoolId, $groupName]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
        }

        echo json_encode(['status' => 'ok', 'data' => $data]);
    } catch (Exception $e) {
        securityLog('TEACHER_GROUP_DETAIL_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener detalles del grupo']);
    }
    exit;
}
?>
