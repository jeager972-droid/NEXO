<?php
// routes/dashboard.php - Estadísticas del panel
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

if ($cleanPath === '/dashboard/stats') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userRole = strtoupper($authUser['role'] ?? '');
    $groupName = $_GET['group_name'] ?? '';
    securityLog('DASHBOARD_STATS_REQUEST', "School ID: " . ($schoolId ?? 'NULL') . " Group: " . ($groupName ?: 'ALL') . " Role: $userRole");

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

        // Build group filter JOINs if group_name provided
        $groupJoin = '';
        $groupParams = [];
        if ($groupName) {
            $groupJoin = "
                AND student_id IN (
                    SELECT sga.student_id
                    FROM student_group_assignments sga
                    JOIN academic_groups ag ON ag.group_id = sga.group_id
                    WHERE ag.group_name = ? AND sga.active = TRUE
                )";
            $groupParams = [$groupName];
        }

        // 1. Conteo de estudiantes presentes (Bogotá TZ) - FIX: LIKE 'INGRESO_%'
        if ($presentCount === null) {
            $presentSql = "
                SELECT COUNT(DISTINCT student_id)
                FROM biometric_events
                WHERE school_id = ?
                  AND event_timestamp >= CURRENT_DATE AT TIME ZONE 'America/Bogota' AND event_timestamp < (CURRENT_DATE + INTERVAL '1 day') AT TIME ZONE 'America/Bogota'
                  AND event_type LIKE 'INGRESO_%'
                  {$groupJoin}
            ";
            $presentStmt = $conn->prepare($presentSql);
            $presentStmt->execute(array_merge([$schoolId], $groupParams));
            $presentCount = $presentStmt->fetchColumn();

            // Guardar en caché para próximas consultas (TTL 5 minutos) — solo si no hay filtro de grupo
            if (!$groupName) {
                try {
                    $redis = new Redis();
                    $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
                    if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
                    $redis->setex("school:{$schoolId}:present:{$today}", 300, (int)$presentCount);
                } catch (Exception $e) { /* Redis no disponible, se omite caché */ }
            }
        }

        // 2. Conteo de inasistencias (Bogotá TZ)
        $absentSql = "
            SELECT COUNT(*) FROM attendance_incidents ai
            WHERE school_id = ?
              AND detected_at >= CURRENT_DATE AT TIME ZONE 'America/Bogota' AND detected_at < (CURRENT_DATE + INTERVAL '1 day') AT TIME ZONE 'America/Bogota'
              AND incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE')
              " . ($groupName ? " AND ai.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "") . "
        ";
        $absentStmt = $conn->prepare($absentSql);
        $absentStmt->execute($groupName ? [$schoolId, $groupName] : [$schoolId]);
        $absentCount = $absentStmt->fetchColumn();

        // 3. Alertas (SOS + Riesgos/Incidentes) (Bogotá TZ)
        $isTeacher = ($userRole === 'DOCENTE' || $userRole === 'PSICORIENTADOR');
        
        if ($isTeacher) {
            // Para docentes, no contar SOS (son globales, no del grupo) y asegurar filtro de grupo
            $alertsSql = "
                SELECT COUNT(*) FROM attendance_incidents ai
                WHERE ai.school_id = ?
                  AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
                  AND (ai.incident_type IN ('LATE_ARRIVAL', 'EARLY_EXIT', 'EVASION_INTERNA', 'LATE:ARRIVAL', 'EARLY:DEPARTURE', 'EARLY_DEPARTURE', 'UNAUTHORIZED_ABSENCE', 'UNAUTHORIZED:ABSENCE', 'BIOMETRIC_FAILURE', 'SPAM_BIOMETRIC') OR ai.incident_type LIKE 'RISK_ALERT%')
                  AND ai.student_id IN (
                      SELECT sga.student_id FROM student_group_assignments sga
                      JOIN academic_groups ag ON ag.group_id = sga.group_id
                      JOIN schedules sch ON sch.group_id = ag.group_id
                      WHERE sch.teacher_user_id = ? AND sga.active = TRUE
                      " . ($groupName ? " AND ag.group_name = ?" : "") . "
                  )
            ";
            $alertsStmt = $conn->prepare($alertsSql);
            if ($groupName) {
                $alertsStmt->execute([$schoolId, $authUser['id'], $groupName]);
            } else {
                $alertsStmt->execute([$schoolId, $authUser['id']]);
            }
            $alertsCount = $alertsStmt->fetchColumn();
        } else {
            // SOS alerts don't have student_id, so they're counted globally without group filter
            $alertsSql = "
                SELECT
                    (SELECT COUNT(*) FROM sos_alerts sa WHERE sa.school_id = ? AND (sa.emitted_at AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date AND sa.resolved = FALSE)
                    +
                    (SELECT COUNT(*) FROM attendance_incidents ai WHERE ai.school_id = ? AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date AND (ai.incident_type IN ('LATE_ARRIVAL', 'EARLY_EXIT', 'EVASION_INTERNA', 'LATE:ARRIVAL', 'EARLY:DEPARTURE', 'EARLY_DEPARTURE', 'UNAUTHORIZED_ABSENCE', 'UNAUTHORIZED:ABSENCE', 'BIOMETRIC_FAILURE', 'SPAM_BIOMETRIC') OR ai.incident_type LIKE 'RISK_ALERT%') " . ($groupName ? " AND ai.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "") . ")
                AS total_alerts
            ";
            $alertsStmt = $conn->prepare($alertsSql);
            $alertsParams = $groupName ? [$schoolId, $schoolId, $groupName] : [$schoolId, $schoolId];
            $alertsStmt->execute($alertsParams);
            $alertsCount = $alertsStmt->fetchColumn();
        }

        // 4. Tareas pendientes (Reportes) (Bogotá TZ) — no filtrar por grupo
        $tasksStmt = $conn->prepare("
            SELECT report_export_id as id, report_type as title,
            TO_CHAR(generated_at AT TIME ZONE 'America/Bogota', 'HH24:MI') as time
            FROM report_exports
            WHERE school_id = ?
              AND generated_at >= CURRENT_DATE AT TIME ZONE 'America/Bogota' AND generated_at < (CURRENT_DATE + INTERVAL '1 day') AT TIME ZONE 'America/Bogota'
            LIMIT 5
        ");
        $tasksStmt->execute([$schoolId]);
        $pendingTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Estudiantes por grupo (FIX: docentes solo ven grupos asignados via schedules)
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

        // 5b. Grupos asignados al docente (para el dropdown, independiente de estudiantes)
        $teacherGroups = [];
        if ($userRole === 'DOCENTE' || $userRole === 'PSICORIENTADOR') {
            $tgStmt = $conn->prepare("
                SELECT DISTINCT ag.group_name
                FROM schedules sch
                JOIN academic_groups ag ON ag.group_id = sch.group_id
                WHERE sch.teacher_user_id = ?
                ORDER BY ag.group_name
            ");
            $tgStmt->execute([$authUser['id']]);
            $teacherGroups = $tgStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Para otros roles, devolver todos los grupos de la institución
            $tgStmt = $conn->prepare("
                SELECT DISTINCT group_name
                FROM academic_groups
                WHERE school_id = ?
                ORDER BY group_name
            ");
            $tgStmt->execute([$schoolId]);
            $teacherGroups = $tgStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // 4b. Conteo de permisos hoy (para completar las 4 cards del docente)
        $permSql = "
            SELECT COUNT(*) FROM attendance_incidents ai
            WHERE school_id = ?
              AND detected_at >= CURRENT_DATE AT TIME ZONE 'America/Bogota' AND detected_at < (CURRENT_DATE + INTERVAL '1 day') AT TIME ZONE 'America/Bogota'
              AND incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')
              " . ($groupName ? " AND ai.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "") . "
        ";
        $permStmt = $conn->prepare($permSql);
        $permStmt->execute($groupName ? [$schoolId, $groupName] : [$schoolId]);
        $permCount = $permStmt->fetchColumn();

        echo json_encode([
            'status' => 'ok',
            'presentCount' => (int)$presentCount,
            'absentCount' => (int)$absentCount,
            'alertsCount' => (int)$alertsCount,
            'permCount' => (int)$permCount,
            'pendingTasks' => $pendingTasks,
            'studentsByGroup' => $studentsByGroup,
            'teacherGroups' => $teacherGroups,
            'groupStats' => [
                'present' => (int)$presentCount,
                'absent' => (int)$absentCount,
                'alerts' => (int)$alertsCount,
                'permisos' => (int)$permCount
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

    if (!in_array($category, ['present', 'absent', 'alert', 'permiso'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Category requerida']);
        exit;
    }

    try {
        // Verificar que el docente tenga este grupo asignado (via schedules)
        $validGroup = true;
        $isTeacher = ($userRole === 'DOCENTE' || $userRole === 'PSICORIENTADOR');
        if ($isTeacher) {
            if (!$groupName) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'group_name requerido para docentes']);
                exit;
            }
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
        $groupJoin = $groupName ? "JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE JOIN academic_groups ag ON ag.group_id = sga.group_id" : "LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id";
        $groupWhere = $groupName ? "AND ag.group_name = ?" : "";
        $params = [$fromDate, $toDate, $schoolId];
        if ($groupName) $params[] = $groupName;

        switch ($category) {
            case 'present':
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                           ag.group_name, MAX(be.event_timestamp) as last_entry
                    FROM students s
                    {$groupJoin}
                    LEFT JOIN biometric_events be ON be.student_id = s.student_id
                        AND be.event_type LIKE 'INGRESO_%'
                        AND (be.event_timestamp AT TIME ZONE 'America/Bogota')::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? {$groupWhere}
                    GROUP BY s.student_id, s.first_name, s.last_name, s.document_number, ag.group_name
                    HAVING MAX(be.event_timestamp) IS NOT NULL
                    ORDER BY last_entry DESC
                ");
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'absent':
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                           ag.group_name, ai.detected_at as absent_since
                    FROM students s
                    {$groupJoin}
                    JOIN attendance_incidents ai ON ai.student_id = s.student_id
                        AND ai.incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE')
                        AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? {$groupWhere}
                    ORDER BY absent_since DESC
                ");
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'alert':
                $stmt = $conn->prepare("
                    SELECT ai.incident_id, ai.incident_type as alert_type, ai.detected_at as alert_at, ai.student_id,
                           s.first_name, s.last_name, s.document_number,
                           STRING_AGG(DISTINCT ag.group_name, ', ') as group_name
                    FROM students s
                    {$groupJoin}
                    JOIN attendance_incidents ai ON ai.student_id = s.student_id
                        AND (ai.incident_type IN ('LATE_ARRIVAL', 'EARLY_EXIT', 'EVASION_INTERNA',
                                                  'LATE:ARRIVAL', 'EARLY:DEPARTURE', 'EARLY_DEPARTURE',
                                                  'UNAUTHORIZED_ABSENCE', 'UNAUTHORIZED:ABSENCE',
                                                  'BIOMETRIC_FAILURE', 'SPAM_BIOMETRIC', 'SOS')
                             OR ai.incident_type LIKE 'RISK_ALERT%')
                        AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? {$groupWhere}
                    GROUP BY ai.incident_id, ai.incident_type, ai.detected_at, ai.student_id, s.first_name, s.last_name, s.document_number
                    ORDER BY ai.detected_at DESC
                ");
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$isTeacher) {
                    $sosStmt = $conn->prepare("
                        SELECT sa.alert_id::text as incident_id,
                               'SOS_WEBAPP' as alert_type,
                               sa.emitted_at as alert_at,
                               NULL as student_id,
                               u.first_name, u.last_name,
                               '—' as document_number, 'Global' as group_name
                        FROM sos_alerts sa
                        JOIN users u ON u.user_id = sa.emitted_by_user_id
                        WHERE sa.school_id = ?
                          AND (sa.emitted_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
                        ORDER BY sa.emitted_at DESC
                    ");
                    $sosStmt->execute([$schoolId, $fromDate, $toDate]);
                    $sosRows = $sosStmt->fetchAll(PDO::FETCH_ASSOC);
                    $results = array_merge($results, $sosRows);
                    usort($results, fn($a, $b) => strtotime($b['alert_at']) - strtotime($a['alert_at']));
                }
                $data = $results;
                break;

            case 'permiso':
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                           ag.group_name, ai.incident_type as permiso_type,
                           ai.detected_at as permiso_at,
                           ai.metadata_json->>'reason' as reason
                    FROM students s
                    {$groupJoin}
                    JOIN attendance_incidents ai ON ai.student_id = s.student_id
                        AND ai.incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')
                        AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? {$groupWhere}
                    ORDER BY permiso_at DESC
                ");
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
        }

        $responsePayload = ['status' => 'ok', 'data' => $data];
        if ($category === 'alert' && empty($data)) {
            $responsePayload['meta'] = ['message' => '0 incidentes, sin registro hoy (las alertas globales no se muestran a nivel de grupo)'];
        }

        echo json_encode($responsePayload);
    } catch (Exception $e) {
        securityLog('TEACHER_GROUP_DETAIL_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener detalles del grupo']);
    }
    exit;
}
?>
