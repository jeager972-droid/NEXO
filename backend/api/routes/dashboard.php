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
 *   - GET /dashboard/events             : novedades recientes de notifications
 *                                           del usuario autenticado (6 acciones).
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
    requireSchoolOnboarding($conn, (string)$schoolId, $userRole);
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

        // VF-020: Debug queries gateadas tras APP_ENV=development
        $isDev = getenv('APP_ENV') === 'development';
        $debugInfo = '';
        if ($isDev) {
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
        }

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

        // Coordinador (vista global): restringir a estudiantes de grupos de su
        // jornada. Se combina con groupFilter en la rama global (else).
        // Los docentes no llegan aquí (van por la rama isTeacher con tga).
        $isCoordinator = $userRole === 'COORDINATOR';
        $coordShift = trim((string)($authUser['work_shift'] ?? ''));
        $shiftFilter = '';
        $shiftParams = [];
        if ($isCoordinator && $coordShift !== '' && $coordShift !== 'completa') {
            $shiftFilter = " AND student_id IN (
                SELECT sga.student_id
                FROM student_group_assignments sga
                JOIN academic_groups ag ON ag.group_id = sga.group_id
                WHERE ag.work_shift = ? AND sga.active = TRUE
            )";
            $shiftParams = [$coordShift];
        }

        // ──────────────────────────────────────────────────────────────
        // Lógica de salida final (colegios que NO rotan salones):
        // Un estudiante que tiene un evento SALIDA_% después de su último
        // INGRESO_% no debe contar como presente. Si el colegio tiene
        // school_schedule_config con exit_time, solo se considera "salida
        // final" si la SALIDA_% ocurre después de (exit_time - 5 min).
        // Salidas temporales (antes de exit_time - 5 min) no restan del
        // conteo porque el estudiante puede regresar.
        // Si no hay exit_time, cualquier SALIDA_% después del último
        // INGRESO_% significa que no está presente.
        // MULTI-JORNADA: cada estudiante usa el exit_time de su propia
        // jornada (work_shift) vía subquery correlacionada.
        // ──────────────────────────────────────────────────────────────
        // Subquery correlacionada: obtiene exit_time según work_shift del estudiante
        $exitTimeCondition = " AND be2.event_timestamp >= (
            (NOW() AT TIME ZONE 'America/Bogota')::date +
            COALESCE(
                (SELECT dsc.expected_exit_time FROM daily_schedule_config dsc
                 WHERE dsc.school_id = biometric_events.school_id
                   AND dsc.group_id = (
                       SELECT sga.group_id FROM student_group_assignments sga
                       WHERE sga.student_id = biometric_events.student_id AND sga.active = TRUE LIMIT 1
                   )
                   AND dsc.config_date = (NOW() AT TIME ZONE 'America/Bogota')::date
                   AND dsc.expected_exit_time IS NOT NULL
                 LIMIT 1),
                (SELECT ssc.exit_time FROM school_schedule_config ssc
                 WHERE ssc.school_id = biometric_events.school_id
                   AND ssc.work_shift = (SELECT s.work_shift FROM students s WHERE s.student_id = biometric_events.student_id)
                   AND ssc.exit_time IS NOT NULL
                 LIMIT 1),
                '23:59:59'::time
            ) - INTERVAL '5 minutes'
        )";
        $exitTimeParams = [];

        // CONSOLIDACIÓN: Una sola query con CTEs para todos los COUNTs (presentes, ausentes, alertas, permisos)
        // Esto reduce 4 round-trips a 1 solo round-trip a la DB
        // FIX: Si tiene global_view, usar query global (no teacher) aunque tenga teacher_view
        $isTeacher = in_array('dashboard.teacher_view', $authUser['permissions'] ?? [])
            && !in_array('dashboard.global_view', $authUser['permissions'] ?? []);
        
        if ($isTeacher) {
            // Para docentes: filtro por grupos asignados en teacher_group_access
            $statsSql = "
                WITH present_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM biometric_events
                    WHERE school_id = ?
                      AND event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND event_timestamp < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND event_type LIKE 'INGRESO_%'
                      {$groupFilter}
                      AND student_id IN (
                          SELECT DISTINCT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN teacher_group_access tga ON tga.group_id = ag.group_id
                          WHERE tga.teacher_user_id = ? AND sga.active = TRUE
                          AND ag.work_shift = (SELECT work_shift FROM users WHERE user_id = tga.teacher_user_id)
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM biometric_events be2
                          WHERE be2.student_id = biometric_events.student_id
                            AND be2.school_id = biometric_events.school_id
                            AND be2.event_type LIKE 'SALIDA_%'
                            AND be2.event_timestamp > biometric_events.event_timestamp
                            AND be2.event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota')::date
                            AND be2.event_timestamp < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                            {$exitTimeCondition}
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM attendance_incidents ai
                          WHERE ai.student_id = biometric_events.student_id
                            AND ai.school_id = biometric_events.school_id
                            AND ai.detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                            AND ai.detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                            AND ai.incident_type = 'EVASION_INTERNA'
                            AND (ai.metadata_json->>'returned_to_class' IS DISTINCT FROM 'true')
                      )
                ),
                absent_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE')
                      {$groupFilter}
                      AND student_id IN (
                          SELECT DISTINCT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN teacher_group_access tga ON tga.group_id = ag.group_id
                          WHERE tga.teacher_user_id = ? AND sga.active = TRUE
                          AND ag.work_shift = (SELECT work_shift FROM users WHERE user_id = tga.teacher_user_id)
                      )
                ),
                alerts_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND (incident_type IN ('EVASION_INTERNA') OR incident_type LIKE 'RISK_ALERT%')
                      AND (metadata_json->>'returned_to_class' IS DISTINCT FROM 'true')
                      AND student_id IN (
                          SELECT DISTINCT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN teacher_group_access tga ON tga.group_id = ag.group_id
                          WHERE tga.teacher_user_id = ? AND sga.active = TRUE
                          AND ag.work_shift = (SELECT work_shift FROM users WHERE user_id = tga.teacher_user_id)
                          " . ($groupName ? " AND ag.group_name = ?" : "") . "
                      )
                ),
                perm_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')
                      {$groupFilter}
                      AND student_id IN (
                          SELECT DISTINCT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN teacher_group_access tga ON tga.group_id = ag.group_id
                          WHERE tga.teacher_user_id = ? AND sga.active = TRUE
                          AND ag.work_shift = (SELECT work_shift FROM users WHERE user_id = tga.teacher_user_id)
                      )
                ),
                late_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND incident_type = 'LATE_ARRIVAL'
                      {$groupFilter}
                      AND student_id IN (
                          SELECT DISTINCT sga.student_id FROM student_group_assignments sga
                          JOIN academic_groups ag ON ag.group_id = sga.group_id
                          JOIN teacher_group_access tga ON tga.group_id = ag.group_id
                          WHERE tga.teacher_user_id = ? AND sga.active = TRUE
                          AND ag.work_shift = (SELECT work_shift FROM users WHERE user_id = tga.teacher_user_id)
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
                $groupName ? array_merge([$schoolId, $groupName, $authUser['id']], $exitTimeParams) : array_merge([$schoolId, $authUser['id']], $exitTimeParams),
                $groupName ? [$schoolId, $groupName, $authUser['id']] : [$schoolId, $authUser['id']],
                $groupName ? [$schoolId, $authUser['id'], $groupName] : [$schoolId, $authUser['id']],
                $groupName ? [$schoolId, $groupName, $authUser['id']] : [$schoolId, $authUser['id']],
                $groupName ? [$schoolId, $groupName, $authUser['id']] : [$schoolId, $authUser['id']]
            );
        } else {
            // Para roles globales (RECTOR, ADMIN, COORDINATOR, etc.)
            // Coordinador: shiftFilter restringe a estudiantes de su jornada.
            $statsSql = "
                WITH present_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM biometric_events
                    WHERE school_id = ?
                      AND event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND event_timestamp < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND event_type LIKE 'INGRESO_%'
                      {$groupFilter}{$shiftFilter}
                      AND NOT EXISTS (
                          SELECT 1 FROM biometric_events be2
                          WHERE be2.student_id = biometric_events.student_id
                            AND be2.school_id = biometric_events.school_id
                            AND be2.event_type LIKE 'SALIDA_%'
                            AND be2.event_timestamp > biometric_events.event_timestamp
                            AND be2.event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota')::date
                            AND be2.event_timestamp < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                            {$exitTimeCondition}
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM attendance_incidents ai
                          WHERE ai.student_id = biometric_events.student_id
                            AND ai.school_id = biometric_events.school_id
                            AND ai.detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                            AND ai.detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                            AND ai.incident_type = 'EVASION_INTERNA'
                            AND (ai.metadata_json->>'returned_to_class' IS DISTINCT FROM 'true')
                      )
                ),
                absent_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND incident_type IN ('INASISTENCIA', 'UNAUTHORIZED_ABSENCE')
                      {$groupFilter}{$shiftFilter}
                ),
                alerts_cte AS (
                    SELECT
                        (SELECT COUNT(*) FROM sos_alerts WHERE school_id = ? AND emitted_at >= (NOW() AT TIME ZONE 'America/Bogota')::date AND emitted_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day') AND resolved = FALSE)
                        +
                        (SELECT COUNT(DISTINCT student_id) FROM attendance_incidents WHERE school_id = ? AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day') AND (incident_type IN ('EVASION_INTERNA') OR incident_type LIKE 'RISK_ALERT%') AND (metadata_json->>'returned_to_class' IS DISTINCT FROM 'true') {$groupFilter}{$shiftFilter})
                    as cnt
                ),
                perm_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')
                      {$groupFilter}{$shiftFilter}
                ),
                late_cte AS (
                    SELECT COUNT(DISTINCT student_id) as cnt
                    FROM attendance_incidents
                    WHERE school_id = ?
                      AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                      AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                      AND incident_type = 'LATE_ARRIVAL'
                      {$groupFilter}{$shiftFilter}
                )
                SELECT
                    (SELECT cnt FROM present_cte) as present_count,
                    (SELECT cnt FROM absent_cte) as absent_count,
                    (SELECT cnt FROM alerts_cte) as alerts_count,
                    (SELECT cnt FROM perm_cte) as perm_count,
                    (SELECT cnt FROM late_cte) as late_count
            ";
            // Parámetros: cada CTE lleva groupParams + shiftParams (excepto alerts_cte
            // que lleva schoolId, schoolId + groupParams + shiftParams).
            // present_cte: schoolId + groupParams + shiftParams + exitTimeParams
            // absent_cte: schoolId + groupParams + shiftParams
            // alerts_cte: schoolId, schoolId + groupParams + shiftParams
            // perm_cte: schoolId + groupParams + shiftParams
            // late_cte: schoolId + groupParams + shiftParams
            $presentParams = array_merge([$schoolId], $groupParams, $shiftParams, $exitTimeParams);
            $absentParams  = array_merge([$schoolId], $groupParams, $shiftParams);
            $alertsParams  = array_merge([$schoolId, $schoolId], $groupParams, $shiftParams);
            $permParams    = array_merge([$schoolId], $groupParams, $shiftParams);
            $lateParams    = array_merge([$schoolId], $groupParams, $shiftParams);
            $statsParams = array_merge($presentParams, $absentParams, $alertsParams, $permParams, $lateParams);
        }

        $statsStmt = $conn->prepare($statsSql);
        $statsStmt->execute($statsParams);
        $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC);
        if ($isDev) $debugInfo .= " | stats_ok present={$statsRow['present_count']} inTx2=" . ($conn->inTransaction() ? '1' : '0');

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
                  AND generated_at >= (NOW() AT TIME ZONE 'America/Bogota')::date AND generated_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                LIMIT 5
            ");
            $tasksStmt->execute([$schoolId]);
            $pendingTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pendingTasks = [];
            securityLog('DASHBOARD_TASKS_ERROR', $e->getMessage());
        }

        // 5. Estudiantes por grupo (FIX: docentes solo ven grupos asignados via teacher_group_access)
        $teacherFilter = '';
        $teacherFilterParams = [];
        if ($isTeacher) {
            $teacherFilter = " AND ag.group_id IN (
                SELECT tga.group_id FROM teacher_group_access tga
                WHERE tga.teacher_user_id = ?
            )";
            $teacherFilterParams = [$authUser['id']];
        } elseif ($isCoordinator && $coordShift !== '' && $coordShift !== 'completa') {
            $teacherFilter = " AND ag.work_shift = ?";
            $teacherFilterParams = [$coordShift];
        }

        $groupsSql = "
            SELECT ag.group_name, s.first_name || ' ' || s.last_name as name
            FROM students s
            JOIN student_group_assignments sga ON s.student_id = sga.student_id AND sga.active = TRUE
            JOIN academic_groups ag ON sga.group_id = ag.group_id
            WHERE s.school_id = ? {$teacherFilter}
        ";
        $groupsStmt = $conn->prepare($groupsSql);
        $groupsStmt->execute(array_merge([$schoolId], $teacherFilterParams));
        $allStudents = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($isDev) $debugInfo .= " | students=" . count($allStudents) . " inTx3=" . ($conn->inTransaction() ? '1' : '0');

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
                    FROM teacher_group_access tga
                    JOIN academic_groups ag ON ag.group_id = tga.group_id
                    WHERE tga.teacher_user_id = ?
                    ORDER BY ag.group_name
                ");
                $tgStmt->execute([$authUser['id']]);
                $teacherGroups = $tgStmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                // Para otros roles, devolver grupos de la institución.
                // Coordinador: solo grupos de su jornada.
                if ($isCoordinator && $coordShift !== '' && $coordShift !== 'completa') {
                    $tgStmt = $conn->prepare("
                        SELECT DISTINCT group_name
                        FROM academic_groups
                        WHERE school_id = ? AND work_shift = ?
                        ORDER BY group_name
                    ");
                    $tgStmt->execute([$schoolId, $coordShift]);
                } else {
                    $tgStmt = $conn->prepare("
                        SELECT DISTINCT group_name
                        FROM academic_groups
                        WHERE school_id = ?
                        ORDER BY group_name
                    ");
                    $tgStmt->execute([$schoolId]);
                }
                $teacherGroups = $tgStmt->fetchAll(PDO::FETCH_COLUMN);
            }
            if ($isDev) $debugInfo .= " | tg=" . count($teacherGroups) . " inTx4=" . ($conn->inTransaction() ? '1' : '0');
        } catch (Exception $tgEx) {
            if ($isDev) $debugInfo .= " | TG_ERROR: " . $tgEx->getMessage();
            $teacherGroups = [];
        }

        $responseData = [
            'status' => 'ok',
            'presentCount' => (int)$presentCount,
            'absentCount' => (int)$absentCount,
            'alertsCount' => (int)$alertsCount,
            'permCount' => (int)$permCount,
            'lateCount' => (int)$lateCount,
            'pendingTasks' => $pendingTasks,
            'studentsByGroup' => $studentsByGroup,
            'teacherGroups' => $teacherGroups,
            'groupStats' => [
                'present' => (int)$presentCount,
                'absent' => (int)$absentCount,
                'alerts' => (int)$alertsCount,
                'permisos' => (int)$permCount,
                'late' => (int)$lateCount
            ]
        ];
        // VF-020: Solo incluir _debug en desarrollo
        if ($isDev) {
            $responseData['_debug'] = $debugInfo ?? 'no-debug';
        }
        $response = json_encode($responseData);

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
    $bogotaToday = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $fromDate = $_GET['from_date'] ?? $bogotaToday;
    $toDate = $_GET['to_date'] ?? $bogotaToday;

    if (!in_array($category, ['present', 'absent', 'alert', 'permiso', 'late'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Category requerida']);
        exit;
    }

    try {
        // Verificar que el docente tenga este grupo asignado (via teacher_group_access)
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
                SELECT 1 FROM teacher_group_access tga
                JOIN academic_groups ag ON ag.group_id = tga.group_id
                WHERE tga.teacher_user_id = ? AND ag.group_name = ?
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

        // Lógica de salida final: exit_time por work_shift del estudiante (multi-jornada)
        $detailExitCondition = " AND be_sal.event_timestamp >= (
            ?::date +
            COALESCE(
                (SELECT ssc.exit_time FROM school_schedule_config ssc
                 WHERE ssc.school_id = s.school_id
                   AND ssc.work_shift = s.work_shift
                   AND ssc.exit_time IS NOT NULL
                 LIMIT 1),
                '23:59:59'::time
            ) - INTERVAL '5 minutes'
        )";
        $detailExitParams = [$fromDate];

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
                    AND NOT EXISTS (
                        SELECT 1 FROM biometric_events be_sal
                        WHERE be_sal.student_id = s.student_id
                          AND be_sal.school_id = s.school_id
                          AND be_sal.event_type LIKE 'SALIDA_%'
                          AND be_sal.event_timestamp > (
                              SELECT MAX(be_ing.event_timestamp)
                              FROM biometric_events be_ing
                              WHERE be_ing.student_id = s.student_id
                                AND be_ing.event_type LIKE 'INGRESO_%'
                                AND (be_ing.event_timestamp)::date BETWEEN ? AND ?
                          )
                          AND (be_sal.event_timestamp)::date BETWEEN ? AND ?
                          {$detailExitCondition}
                    )
                    GROUP BY s.student_id, s.first_name, s.last_name, s.document_number, ag.group_name
                    HAVING MAX(be.event_timestamp) IS NOT NULL
                    ORDER BY last_entry DESC
                ");
                $presentParams = [$fromDate, $toDate, $schoolId];
                if ($groupName) $presentParams[] = $groupName;
                $presentParams[] = $fromDate;
                $presentParams[] = $toDate;
                $presentParams[] = $fromDate;
                $presentParams[] = $toDate;
                $presentParams = array_merge($presentParams, $detailExitParams);
                $stmt->execute($presentParams);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'absent':
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                           ag.group_name, ai.detected_at as absent_since,
                           COALESCE(ai.metadata_json->>'pending_context', 'false') as pending_context
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
                           STRING_AGG(DISTINCT ag.group_name, ', ') as group_name,
                           ai.metadata_json->>'classroom' as classroom,
                           ai.metadata_json->>'detected_by' as detected_by
                    FROM students s
                    {$groupJoin}
                    JOIN attendance_incidents ai ON ai.student_id = s.student_id
                        AND (ai.incident_type IN ('EVASION_INTERNA', 'SOS')
                             OR ai.incident_type LIKE 'RISK_ALERT%')
                        AND (ai.detected_at)::date
                            BETWEEN ? AND ?
                    WHERE s.school_id = ? {$groupWhere}
                    GROUP BY ai.incident_id, ai.incident_type, ai.detected_at, ai.student_id, s.first_name, s.last_name, s.document_number, ai.metadata_json
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
// Params: none (filtered by user's notifications automatically)
// ============================================================================
if ($cleanPath === '/dashboard/events') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId = $authUser['id'];

    try {
        if (!$conn) throw new Exception("Conexión a BD no disponible");

        // Las novedades se obtienen de la tabla notifications del usuario actual.
        // Solo se muestran las 6 acciones configurables:
        // situacion_critica, permiso, autorizar_salida, pedagogica, iniciar_seguimiento, daño
        // Cada usuario ve las notificaciones que le fueron enviadas.
        // Deduplicación: si hay múltiples notificaciones del mismo action+student_id hoy,
        // solo se muestra la más reciente.
        $stmt = $conn->prepare("
            SELECT * FROM (
                SELECT DISTINCT ON (n.metadata_json->>'action', n.metadata_json->>'student_id')
                       n.notification_id,
                       n.title,
                       n.message,
                       n.type,
                       n.metadata_json,
                       n.created_at,
                       TO_CHAR(n.created_at, 'HH24:MI') AS time
                FROM notifications n
                WHERE n.user_id = ?
                  AND n.school_id = ?
                  AND n.created_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                  AND n.metadata_json->>'action' IN (
                      'situacion_critica', 'permiso', 'autorizar_salida',
                      'pedagogica', 'iniciar_seguimiento', 'daño'
                  )
                ORDER BY n.metadata_json->>'action', n.metadata_json->>'student_id', n.created_at DESC
            ) AS dedup
            ORDER BY dedup.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$userId, $schoolId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formatear eventos para el frontend
        $formattedEvents = [];
        foreach ($events as $ev) {
            $payload = json_decode($ev['metadata_json'], true);
            $studentName = $payload['student_name'] ?? '';
            $reason = $payload['reason'] ?? $payload['message'] ?? '';
            $groupName = $payload['group_name'] ?? '';
            $reporterName = $payload['reporter_name'] ?? $payload['sender_name'] ?? '';
            $location = $payload['location'] ?? '';
            $action = $payload['action'] ?? '';

            $label = '';
            switch ($action) {
                case 'situacion_critica':
                    $label = "Se reportó una situación crítica";
                    if ($reporterName) $label .= " por {$reporterName}";
                    if ($location && $location !== 'No especificada' && $location !== 'Ubicación no definida') $label .= ". Ubicación: {$location}";
                    break;
                case 'permiso':
                    $label = "Se registró un permiso";
                    if ($studentName) $label .= " para {$studentName}";
                    break;
                case 'autorizar_salida':
                    $label = "Se autorizó una salida";
                    if ($studentName) $label .= " para {$studentName}";
                    break;
                case 'pedagogica':
                    $label = "Se programó una salida pedagógica";
                    if ($groupName) $label .= " para el grupo {$groupName}";
                    break;
                case 'iniciar_seguimiento':
                    $label = "Se inició un seguimiento";
                    if ($studentName) $label .= " para {$studentName}";
                    break;
                case 'daño':
                    $label = "Se reportó un daño";
                    if ($reason) $label .= ": {$reason}";
                    break;
                default:
                    $label = $ev['title'] ?? $action;
            }

            $isAlert = in_array($action, ['situacion_critica', 'daño']);

            $formattedEvents[] = [
                'id' => $ev['notification_id'],
                'label' => $label,
                'time' => $ev['time'],
                'type' => $isAlert ? 'alert' : 'default',
                'action' => $action,
                'has_details' => true,
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

// ============================================================================
// GET /dashboard/insights — Lectura inteligente de la jornada (Nexus)
// ============================================================================
// Motor matemático (lib/insights.php): z-score vs línea base móvil,
// ventana modal de clusters horarios, regresión por mínimos cuadrados,
// score de prioridad. No hay textos fijos: cada tarjeta sale de funciones
// sobre los datos reales de la escuela.
// Docente: insights acotados a sus grupos asignados.
// ============================================================================
if ($cleanPath === '/dashboard/insights') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userRole = strtoupper($authUser['role'] ?? '');
    requireSchoolOnboarding($conn, (string)$schoolId, $userRole);

    try {
        require_once __DIR__ . '/../lib/insights.php';

        // Caché 60s por escuela+rol+usuario (los datos cambian por minutos,
        // no por segundos; evita recomputar las regresiones por request)
        $cacheKey = "dashboard:insights:{$schoolId}:{$userRole}:{$authUser['id']}";
        try {
            $redis = getRedisConnection();
            if ($redis) {
                $cached = $redis->get($cacheKey);
                if ($cached !== false) { header('X-Insights-Cache: HIT'); echo $cached; exit; }
            }
        } catch (Throwable $e) { /* sin caché */ }

        // Docente: restringir a sus grupos asignados
        $groupIds = [];
        if ($userRole === 'TEACHER') {
            $g = $conn->prepare("SELECT group_id FROM teacher_group_access WHERE teacher_user_id = ?");
            $g->execute([$authUser['id']]);
            $groupIds = array_column($g->fetchAll(PDO::FETCH_ASSOC), 'group_id');
        }

        $insights = nx_compute_insights($conn, (string)$schoolId, $userRole, $groupIds);
        $payload = json_encode([
            'status' => 'ok',
            'data' => [
                'insights' => $insights,
                'computed_at' => gmdate('c'),
                'role' => $userRole,
            ],
        ]);
        try { if ($redis) $redis->setex($cacheKey, 60, $payload); } catch (Throwable $e) { /* sin caché */ }
        echo $payload;
    } catch (Throwable $e) {
        securityLog('DASHBOARD_INSIGHTS_ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al calcular insights']);
    }
    exit;
}
?>
