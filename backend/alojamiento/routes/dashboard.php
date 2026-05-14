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
            $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', getenv('REDIS_PORT') ?: 6379);
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
                $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', getenv('REDIS_PORT') ?: 6379);
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

        // 5. Estudiantes por grupo
        $groupsStmt = $conn->prepare("
            SELECT ag.group_name, s.first_name || ' ' || s.last_name as name
            FROM students s
            JOIN student_group_assignments sga ON s.student_id = sga.student_id
            JOIN academic_groups ag ON sga.group_id = ag.group_id
            WHERE s.school_id = ? AND sga.active = TRUE
        ");
        $groupsStmt->execute([$schoolId]);
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
?>
