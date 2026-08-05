<?php
/**
 * =============================================================================
 * routes/dashboard.php — Estadísticas y eventos del panel principal.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone tres endpoints para el dashboard:
 *   - GET /dashboard/stats              : conteos de presentes, ausentes, alertas
 *                                           y permisos de la escuela/grupo. Usa
 *                                           caché Redis agresiva (TTL 30s).
 *   - GET /dashboard/teacher-group-detail : desglose de estudiantes por estado
 *                                           dentro de los grupos del docente.
 *   - GET /dashboard/events             : eventos recientes de user_commands
 *                                           filtrados por rol.
 *
 * USO DE REDIS AQUÍ
 * -----------------
 * Redis actúa como caché de corta duración (TTL 30s) para GET /dashboard/stats.
 * La clave dashboard:stats:<schoolId>:<userRole>:<groupName> evita recalcular
 * conteos complejos en cada petición, reduciendo carga en PostgreSQL. Si Redis
 * falla, el endpoint calcula y responde directamente desde la base de datos.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación, roles, getRedisConnection.
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Frontend: Dashboard.jsx y componentes de inicio.
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

// ============================================================================
// GET /dashboard/stats — Conteos agregados para el dashboard con caché Redis.
// ============================================================================
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

    // Caché agresivo de toda la respuesta (TTL 30s) para evitar queries repetidas
    $cacheKey = "dashboard:stats:{$schoolId}:{$userRole}:{$groupName}";
    try {
        $redis = getRedisConnection();
        if ($redis) {
            $cached = $redis->get($cacheKey);
            if ($cached !== false) {
                header('X-Dashboard-Cache: HIT');
                echo $cached;
                exit;
            }
        }
    } catch (Exception $e) { /* Redis no disponible, continuar sin caché */ }

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // DEBUG: verificar contexto RLS al inicio del dashboard
        $debugStmt = $conn->prepare("SELECT current_setting('app.current_school_id', true) as school_id, current_setting('app.current_role', true) as role, count(*) as group_count FROM academic_groups WHERE school_id = current_setting('app.current_school_id', true)::uuid");
        $debugStmt->execute();
        $debugRow = $debugStmt->fetch(PDO::FETCH_ASSOC);
        $debugInfo = "school_id={$debugRow['school_id']} role={$debugRow['role']} group_count={$debugRow['group_count']} inTx=" . ($conn->inTransaction() ? '1' : '0');
        securityLog('DASHBOARD_RLS_DEBUG', $debugInfo);

        // DEBUG: también probar sin RLS
        $debugStmt2 = $conn->prepare("SELECT count(*) as total_groups FROM academic_groups WHERE school_id = ?");
        $debugStmt2->execute([$schoolId]);
        $debugRow2 = $debugStmt2->fetch(PDO::FETCH_ASSOC);
        $debugInfo .= " | direct_count={$debugRow2['total_groups']}";

        // Build group filter JOINs if group_name provided
        $groupFilter = '';
        $groupParams = [];
        if ($groupName) {
            $groupFilter = " AND student_id IN (
                SELECT sga.student_id
                FROM student_group_assignments sga
                JOIN academic_groups ag ON ag.group_id = sga.group_id
                WHERE ag.group_name = ? AND sga.active = TRUE
            )";
            $groupParams = [$groupName];
        }

        // CONSOLIDACIÓN: Una sola query con CTEs para todos los COUNTs (presentes, ausentes, alertas, permisos)
        // Esto reduce 4 round-trips a 1 solo round-trip a la DB
        // FIX: Si tiene global_view, usar query global (no teacher) aunque tenga teacher_view
        $isTeacher = in_array('dashboard.teacher_view', $authUser['permissions'] ?? [])
            && !in_array('dashboard.global_view', $authUser['permissions'] ?? []);
        
        if ($isTeacher) {
            // Para docentes: filtro por grupos asignados en schedules
            $statsSql = "
                WITH present_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM biometric_events
                    WHERE school_id = ?
                      AND event_timestamp >= CURRENT_DATE 
                      AND event_timestamp < (CURRENT_DATE + INTERVAL '1 day')
                      AND event_type LIKE 'INGRESO_%'
                      {$groupFilter}
                      AND student_id IN (
                          SELECT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN schedules sch ON sch.group_id = ag.group_id
                          WHERE sch.teacher_user_id = ? AND sga.active = TRUE
                      )
                ),
                absent_cte AS (
                    SELECT COUNT(*) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= CURRENT_DATE 
                      AND detected_at < (CURRENT_DATE + INTERVAL '1 day')
                      AND incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE')
                      {$groupFilter}
                      AND student_id IN (
                          SELECT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN schedules sch ON sch.group_id = ag.group_id
                          WHERE sch.teacher_user_id = ? AND sga.active = TRUE
                      )
                ),
                alerts_cte AS (
                    SELECT COUNT(*) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND (detected_at)::date = (CURRENT_TIMESTAMP)::date
                      AND (incident_type IN ('LATE_ARRIVAL', 'EARLY_EXIT', 'EVASION_INTERNA', 'LATE:ARRIVAL', 'EARLY:DEPARTURE', 'EARLY_DEPARTURE', 'UNAUTHORIZED_ABSENCE', 'UNAUTHORIZED:ABSENCE', 'BIOMETRIC_FAILURE', 'SPAM_BIOMETRIC') OR incident_type LIKE 'RISK_ALERT%')
                      AND student_id IN (
                          SELECT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN schedules sch ON sch.group_id = ag.group_id
                          WHERE sch.teacher_user_id = ? AND sga.active = TRUE
                          " . ($groupName ? " AND ag.group_name = ?" : "") . "
                      )
                ),
                perm_cte AS (
                    SELECT COUNT(*) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= CURRENT_DATE
                      AND detected_at < (CURRENT_DATE + INTERVAL '1 day')
                      AND incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')
                      {$groupFilter}
                      AND student_id IN (
                          SELECT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN schedules sch ON sch.group_id = ag.group_id
                          WHERE sch.teacher_user_id = ? AND sga.active = TRUE
                      )
                ),
                late_cte AS (
                    SELECT COUNT(*) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= CURRENT_DATE
                      AND detected_at < (CURRENT_DATE + INTERVAL '1 day')
                      AND incident_type = 'LATE_ARRIVAL'
                      {$groupFilter}
                      AND student_id IN (
                          SELECT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN schedules sch ON sch.group_id = ag.group_id
                          WHERE sch.teacher_user_id = ? AND sga.active = TRUE
                      )
                )
                SELECT
                    (SELECT cnt FROM present_cte) as present_count,
                    (SELECT cnt FROM absent_cte) as absent_count,
                    (SELECT cnt FROM alerts_cte) as alerts_count,
                    (SELECT cnt FROM perm_cte) as perm_count,
                    (SELECT cnt FROM late_cte) as late_count
            ";
            $statsParams = array_merge(
                $groupName ? [$schoolId, $groupName, $authUser['id']] : [$schoolId, $authUser['id']],
                $groupName ? [$schoolId, $groupName, $authUser['id']] : [$schoolId, $authUser['id']],
                $groupName ? [$schoolId, $authUser['id'], $groupName] : [$schoolId, $authUser['id']],
                $groupName ? [$schoolId, $groupName, $authUser['id']] : [$schoolId, $authUser['id']],
                $groupName ? [$schoolId, $groupName, $authUser['id']] : [$schoolId, $authUser['id']]
            );
        } else {
            // Para roles globales (RECTOR, ADMIN, etc.)
            $statsSql = "
                WITH present_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM biometric_events
                    WHERE school_id = ?
                      AND event_timestamp >= CURRENT_DATE 
                      AND event_timestamp < (CURRENT_DATE + INTERVAL '1 day')
                      AND event_type LIKE 'INGRESO_%'
                      {$groupFilter}
                ),
                absent_cte AS (
                    SELECT COUNT(*) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= CURRENT_DATE 
                      AND detected_at < (CURRENT_DATE + INTERVAL '1 day')
                      AND incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE')
                      {$groupFilter}
                ),
                alerts_cte AS (
                    SELECT 
                        (SELECT COUNT(*) FROM sos_alerts WHERE school_id = ? AND (emitted_at)::date = (CURRENT_TIMESTAMP)::date AND resolved = FALSE)
                        +
                        (SELECT COUNT(*) FROM attendance_incidents WHERE school_id = ? AND (detected_at)::date = (CURRENT_TIMESTAMP)::date AND (incident_type IN ('LATE_ARRIVAL', 'EARLY_EXIT', 'EVASION_INTERNA', 'LATE:ARRIVAL', 'EARLY:DEPARTURE', 'EARLY_DEPARTURE', 'UNAUTHORIZED_ABSENCE', 'UNAUTHORIZED:ABSENCE', 'BIOMETRIC_FAILURE', 'SPAM_BIOMETRIC') OR incident_type LIKE 'RISK_ALERT%') {$groupFilter})
                    as cnt
                ),
                perm_cte AS (
                    SELECT COUNT(*) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= CURRENT_DATE
                      AND detected_at < (CURRENT_DATE + INTERVAL '1 day')
                      AND incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')
                      {$groupFilter}
                ),
                late_cte AS (
                    SELECT COUNT(*) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= CURRENT_DATE
                      AND detected_at < (CURRENT_DATE + INTERVAL '1 day')
                      AND incident_type = 'LATE_ARRIVAL'
                      {$groupFilter}
                )
                SELECT
                    (SELECT cnt FROM present_cte) as present_count,
                    (SELECT cnt FROM absent_cte) as absent_count,
                    (SELECT cnt FROM alerts_cte) as alerts_count,
                    (SELECT cnt FROM perm_cte) as perm_count,
                    (SELECT cnt FROM late_cte) as late_count
            ";
            $statsParams = array_merge(
                $groupName ? [$schoolId, $groupName] : [$schoolId],
                $groupName ? [$schoolId, $groupName] : [$schoolId],
                $groupName ? [$schoolId, $schoolId, $groupName] : [$schoolId, $schoolId],
                $groupName ? [$schoolId, $groupName] : [$schoolId],
                $groupName ? [$schoolId, $groupName] : [$schoolId]
            );
        }

        $statsStmt = $conn->prepare($statsSql);
        $statsStmt->execute($statsParams);
        $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC);
        $debugInfo .= " | stats_ok present={$statsRow['present_count']} inTx2=" . ($conn->inTransaction() ? '1' : '0');

        $presentCount = (int)($statsRow['present_count'] ?? 0);
        $absentCount = (int)($statsRow['absent_count'] ?? 0);
        $alertsCount = (int)($statsRow['alerts_count'] ?? 0);
        $permCount = (int)($statsRow['perm_count'] ?? 0);
        $lateCount = (int)($statsRow['late_count'] ?? 0);

        // 4. Tareas pendientes (Reportes) (Bogotá TZ) — no filtrar por grupo
        try {
            $tasksStmt = $conn->prepare("
                SELECT report_export_id as id, report_type as title,
                TO_CHAR(generated_at, 'HH24:MI') as time
                FROM report_exports
                WHERE school_id = ?
                  AND generated_at >= CURRENT_DATE AND generated_at < (CURRENT_DATE + INTERVAL '1 day')
                LIMIT 5
            ");
            $tasksStmt->execute([$schoolId]);
            $pendingTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pendingTasks = [];
            securityLog('DASHBOARD_TASKS_ERROR', $e->getMessage());
        }

        // 5. Estudiantes por grupo (FIX: docentes solo ven grupos asignados via schedules)
        $teacherFilter = '';
        if ($isTeacher) {
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
        $debugInfo .= " | students=" . count($allStudents) . " inTx3=" . ($conn->inTransaction() ? '1' : '0');

        $studentsByGroup = [];
        foreach ($allStudents as $row) {
            $studentsByGroup[$row['group_name']][] = ['name' => $row['name']];
        }

        // 5b. Grupos asignados al docente (para el dropdown, independiente de estudiantes)
        $teacherGroups = [];
        try {
            if ($isTeacher) {
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
            $debugInfo .= " | tg=" . count($teacherGroups) . " inTx4=" . ($conn->inTransaction() ? '1' : '0');
        } catch (Exception $tgEx) {
            $debugInfo .= " | TG_ERROR: " . $tgEx->getMessage();
            $teacherGroups = [];
        }

        $response = json_encode([
            'status' => 'ok',
            'presentCount' => (int)$presentCount,
            'absentCount' => (int)$absentCount,
            'alertsCount' => (int)$alertsCount,
            'permCount' => (int)$permCount,
            'lateCount' => (int)$lateCount,
            'pendingTasks' => $pendingTasks,
            'studentsByGroup' => $studentsByGroup,
            '_debug' => $debugInfo ?? 'no-debug',
            'teacherGroups' => $teacherGroups,
            'groupStats' => [
                'present' => (int)$presentCount,
                'absent' => (int)$absentCount,
                'alerts' => (int)$alertsCount,
                'permisos' => (int)$permCount,
                'late' => (int)$lateCount
            ]
        ]);

        // Guardar en caché Redis por 30s
        try {
            $redis = getRedisConnection();
            if ($redis) {
                $redis->setex($cacheKey, 30, $response);
            }
        } catch (Exception $e) { /* Redis no disponible, omitir caché */ }

        echo $response;
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

    if (!in_array($category, ['present', 'absent', 'alert', 'permiso', 'late'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Category requerida']);
        exit;
    }

    try {
        // Verificar que el docente tenga este grupo asignado (via schedules)
        $validGroup = true;
        $isTeacher = in_array('dashboard.teacher_view', $authUser['permissions'] ?? [])
            && !in_array('dashboard.global_view', $authUser['permissions'] ?? []);
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
                        AND (be.event_timestamp)::date
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
                        AND (ai.detected_at)::date
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
                        AND (ai.detected_at)::date
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
                          AND (sa.emitted_at)::date BETWEEN ? AND ?
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
                        AND (ai.detected_at)::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? {$groupWhere}
                    ORDER BY permiso_at DESC
                ");
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'late':
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                           ag.group_name, ai.detected_at as late_at
                    FROM students s
                    {$groupJoin}
                    JOIN attendance_incidents ai ON ai.student_id = s.student_id
                        AND ai.incident_type = 'LATE_ARRIVAL'
                        AND (ai.detected_at)::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? {$groupWhere}
                    ORDER BY late_at DESC
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

// ============================================================================
// GET /dashboard/events
// Params: none (filtered by role automatically)
// ============================================================================
if ($cleanPath === '/dashboard/events') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];
    $userRole = strtoupper($authUser['role'] ?? '');

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        $isTeacher = in_array('dashboard.teacher_view', $authUser['permissions'] ?? [])
            && !in_array('dashboard.global_view', $authUser['permissions'] ?? []);
        $isGlobalAdmin = ($userRole === 'RECTOR' || $userRole === 'COORDINATOR');

        $events = [];
        $limit = 20;

        if ($isGlobalAdmin) {
            // RECTOR/COORDINADOR: ven SOLO eventos importantes (PERMISO, AUTORIZAR_SALIDA, HORARIO, INCIDENTE, DAÑO, SOS, PEDAGOGICA)
            // + TODOS los seguimientos (ejecutados por coordinadores o ellos mismos)
            // NO ven citaciones, inasistencias, solicitudes
            $stmt = $conn->prepare("
                SELECT uc.command_type, uc.executed_at, uc.command_payload,
                       u.first_name as issuer_first, u.last_name as issuer_last,
                       r.role_name as issuer_role
                FROM user_commands uc
                LEFT JOIN users u ON u.user_id = uc.executed_by_user_id
                LEFT JOIN roles r ON u.role_id = r.role_id
                WHERE uc.school_id = ?
                  AND uc.executed_at >= CURRENT_DATE
                  AND (
                    uc.command_type IN ('PERMISO', 'AUTORIZAR_SALIDA', 'HORARIO', 'INCIDENTE', 'DAÑO', 'SOS', 'PEDAGOGICA', 'SEGUIMIENTO', 'INASISTENCIA')
                  )
                ORDER BY uc.executed_at DESC
                LIMIT ?
            ");
            $stmt->execute([$schoolId, $limit]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($isTeacher) {
            // DOCENTE/PSICORIENTADOR: ven SUS comandos (incluyendo SUS citaciones) + comandos importantes de sus grupos
            // Simplificado para evitar errores 500
            $stmt = $conn->prepare("
                SELECT uc.command_type, uc.executed_at, uc.command_payload,
                       u.first_name as issuer_first, u.last_name as issuer_last,
                       r.role_name as issuer_role
                FROM user_commands uc
                LEFT JOIN users u ON u.user_id = uc.executed_by_user_id
                LEFT JOIN roles r ON u.role_id = r.role_id
                WHERE uc.school_id = ?
                  AND uc.executed_at >= CURRENT_DATE
                  AND uc.executed_by_user_id = ?
                ORDER BY uc.executed_at DESC
                LIMIT ?
            ");
            $stmt->execute([$schoolId, $userId, $limit]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // OTROS ROLES: solo ven sus propios comandos
            $stmt = $conn->prepare("
                SELECT uc.command_type, uc.executed_at, uc.command_payload,
                       u.first_name as issuer_first, u.last_name as issuer_last,
                       r.role_name as issuer_role
                FROM user_commands uc
                LEFT JOIN users u ON u.user_id = uc.executed_by_user_id
                LEFT JOIN roles r ON u.role_id = r.role_id
                WHERE uc.school_id = ?
                  AND uc.executed_by_user_id = ?
                  AND uc.executed_at >= CURRENT_DATE
                ORDER BY uc.executed_at DESC
                LIMIT ?
            ");
            $stmt->execute([$schoolId, $userId, $limit]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Formatear eventos para el frontend
        $formattedEvents = [];
        foreach ($events as $ev) {
            $payload = json_decode($ev['command_payload'], true);
            $reason = $payload['reason'] ?? $payload['message'] ?? $payload['description'] ?? '';
            $studentName = $payload['student_name'] ?? '';
            $issuerName = trim($ev['issuer_first'] . ' ' . $ev['issuer_last']);
            $issuerRole = $ev['issuer_role'] ?? '';

            $label = '';
            switch (strtoupper($ev['command_type'])) {
                case 'PERMISO':
                    $label = $studentName ? "Se registró un permiso para {$studentName}" : "Se registró un permiso";
                    break;
                case 'AUTORIZAR_SALIDA':
                    $label = $studentName ? "Se autorizó una salida para {$studentName}" : "Se autorizó una salida";
                    break;
                case 'SOS':
                    $label = "Se emitió una alerta SOS";
                    break;
                case 'CITACION':
                    $label = $studentName ? "Se envió una citación para {$studentName}" : "Se envió una citación";
                    break;
                case 'INASISTENCIA':
                    $label = $studentName ? "Se registró inasistencia de {$studentName}" : "Se registró una inasistencia";
                    break;
                case 'INCIDENTE':
                    $label = $reason ? "Se reportó un incidente: {$reason}" : "Se reportó un incidente";
                    break;
                case 'PEDAGOGICA':
                    $label = $reason ? "Se programó una salida pedagógica: {$reason}" : "Se programó una salida pedagógica";
                    break;
                case 'SEGUIMIENTO':
                    $label = $studentName ? "Se inició seguimiento para {$studentName}" : "Se inició un seguimiento";
                    break;
                case 'SOLICITUD':
                    $label = "Se envió una solicitud interna";
                    break;
                case 'DAÑO':
                    $label = $reason ? "Se reportó un daño: {$reason}" : "Se reportó un daño";
                    break;
                case 'HORARIO':
                    $label = "Se realizó un cambio de horario";
                    break;
                default:
                    $label = $ev['command_type'];
            }

            $formattedEvents[] = [
                'label' => $label,
                'time' => date('H:i', strtotime($ev['executed_at'])),
                'type' => in_array(strtoupper($ev['command_type']), ['SOS', 'INCIDENTE']) ? 'alert' : 'default',
                'issuer' => $issuerName,
                'issuer_role' => $issuerRole,
                'command_type' => $ev['command_type'],
            ];
        }

        echo json_encode(['status' => 'ok', 'data' => $formattedEvents]);
    } catch (Exception $e) {
        securityLog('DASHBOARD_EVENTS_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener eventos']);
    }
    exit;
}
?>
