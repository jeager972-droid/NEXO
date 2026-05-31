<?php
/**
 * routes/audit_full.php — Endpoints completos para el módulo de Auditoría.
 * Cada subdivisión del grid de Audit.jsx tiene su propio endpoint.
 */
global $cleanPath, $conn, $method, $input;
require_once __DIR__ . '/_auth_middleware.php';

if (strpos($cleanPath, '/audit/') !== 0) {
    return; // No es ruta de auditoría, salir silenciosamente
}

$authUser = requireAuth(['RECTOR', 'COORDINADOR', 'SUPER_RECTOR']);
$schoolId = $authUser['school_id'];

function auditJson($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function auditError($msg, $code = 500) {
    auditJson(['status' => 'error', 'message' => $msg], $code);
}

// ============================================================================
// 1. ASISTENCIA
// ============================================================================

// Reporte general
if ($cleanPath === '/audit/attendance/general' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) FILTER (WHERE event_type LIKE 'INGRESO_%') AS total_entries,
                COUNT(*) FILTER (WHERE event_type LIKE 'INGRESO_TARDE%') AS late_count,
                COUNT(*) FILTER (WHERE event_type LIKE 'INASISTENCIA%') AS absence_count
            FROM biometric_events
            WHERE school_id = ?
              AND (event_timestamp AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
        ");
        $stmt->execute([$schoolId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt2 = $conn->prepare("
            SELECT be.event_id, be.event_type, be.event_timestamp, s.first_name, s.last_name, s.document_number
            FROM biometric_events be
            LEFT JOIN students s ON be.student_id = s.student_id
            WHERE be.school_id = ?
              AND (be.event_timestamp AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
            ORDER BY be.event_timestamp DESC
            LIMIT 100
        ");
        $stmt2->execute([$schoolId]);

        auditJson(['status' => 'ok', 'stats' => $stats, 'data' => $stmt2->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Inasistencias
if ($cleanPath === '/audit/attendance/absences' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT ai.incident_id, ai.incident_type, ai.detected_at, ai.resolved,
                   s.first_name, s.last_name, s.document_number
            FROM attendance_incidents ai
            LEFT JOIN students s ON ai.student_id = s.student_id
            WHERE ai.school_id = ? AND ai.incident_type = 'INASISTENCIA'
            ORDER BY ai.detected_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Llegadas tarde
if ($cleanPath === '/audit/attendance/lates' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT be.event_id, be.event_type, be.event_timestamp,
                   s.first_name, s.last_name, s.document_number
            FROM biometric_events be
            LEFT JOIN students s ON be.student_id = s.student_id
            WHERE be.school_id = ? AND be.event_type LIKE 'INGRESO_TARDE%'
            ORDER BY be.event_timestamp DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Evasión interna
if ($cleanPath === '/audit/attendance/evasion' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT ai.incident_id, ai.incident_type, ai.detected_at, ai.resolved,
                   s.first_name, s.last_name, s.document_number
            FROM attendance_incidents ai
            LEFT JOIN students s ON ai.student_id = s.student_id
            WHERE ai.school_id = ? AND ai.incident_type = 'EVASION_INTERNA'
            ORDER BY ai.detected_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Por grupo
if ($cleanPath === '/audit/attendance/by-group' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT ag.group_name,
                   COUNT(DISTINCT be.student_id) AS student_count,
                   COUNT(*) FILTER (WHERE be.event_type LIKE 'INGRESO_%') AS entries,
                   COUNT(*) FILTER (WHERE be.event_type LIKE 'INGRESO_TARDE%') AS lates
            FROM academic_groups ag
            LEFT JOIN student_group_assignments sga ON ag.group_id = sga.group_id AND sga.active = TRUE
            LEFT JOIN biometric_events be ON sga.student_id = be.student_id
                AND be.school_id = ?
                AND (be.event_timestamp AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
            WHERE ag.school_id = ?
            GROUP BY ag.group_id, ag.group_name
            ORDER BY ag.group_name
        ");
        $stmt->execute([$schoolId, $schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Por estudiante
if ($cleanPath === '/audit/attendance/by-student' && $method === 'GET') {
    try {
        $q = $_GET['q'] ?? '';
        $sql = "
            SELECT s.student_id, s.first_name, s.last_name, s.document_number,
                   COUNT(*) AS total_events,
                   COUNT(*) FILTER (WHERE be.event_type LIKE 'INGRESO_%') AS entries,
                   COUNT(*) FILTER (WHERE be.event_type LIKE 'INGRESO_TARDE%') AS lates,
                   COUNT(*) FILTER (WHERE be.event_type LIKE 'INASISTENCIA%') AS absences
            FROM students s
            LEFT JOIN biometric_events be ON s.student_id = be.student_id
            WHERE s.school_id = ? AND s.active = TRUE
        ";
        $params = [$schoolId];
        if ($q !== '') {
            $sql .= " AND (s.first_name ILIKE ? OR s.last_name ILIKE ? OR s.document_number ILIKE ?)";
            $like = "%{$q}%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $sql .= " GROUP BY s.student_id, s.first_name, s.last_name, s.document_number ORDER BY s.last_name, s.first_name LIMIT 200";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// ============================================================================
// 2. DISCIPLINA
// ============================================================================

// Incidentes
if ($cleanPath === '/audit/discipline/incidents' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT si.incident_id, si.incident_type, si.severity_level, si.description, si.detected_at, si.resolved,
                   s.first_name, s.last_name, s.document_number
            FROM security_incidents si
            LEFT JOIN students s ON si.related_student_id = s.student_id
            WHERE si.school_id = ?
            ORDER BY si.detected_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Vulneraciones
if ($cleanPath === '/audit/discipline/violations' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT si.incident_id, si.incident_type, si.severity_level, si.description, si.detected_at, si.resolved,
                   s.first_name, s.last_name
            FROM security_incidents si
            LEFT JOIN students s ON si.related_student_id = s.student_id
            WHERE si.school_id = ? AND si.severity_level IN ('HIGH','CRITICAL')
            ORDER BY si.detected_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Intentos salón incorrecto
if ($cleanPath === '/audit/discipline/wrong-classroom' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT be.event_id, be.event_type, be.event_result, be.event_timestamp, be.confidence_score,
                   s.first_name, s.last_name, c.classroom_name
            FROM biometric_events be
            LEFT JOIN students s ON be.student_id = s.student_id
            LEFT JOIN classrooms c ON be.classroom_id = c.classroom_id
            WHERE be.school_id = ? AND be.event_result = 'WRONG_CLASSROOM'
            ORDER BY be.event_timestamp DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Spam biométrico
if ($cleanPath === '/audit/discipline/biometric-spam' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT student_id, COUNT(*) AS attempt_count, MIN(event_timestamp) AS first_attempt, MAX(event_timestamp) AS last_attempt
            FROM biometric_events
            WHERE school_id = ?
              AND event_timestamp >= NOW() - INTERVAL '1 hour'
            GROUP BY student_id
            HAVING COUNT(*) > 5
            ORDER BY attempt_count DESC
            LIMIT 50
        ");
        $stmt->execute([$schoolId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Enriquecer con nombres
        foreach ($rows as &$row) {
            $st = $conn->prepare("SELECT first_name, last_name FROM students WHERE student_id = ?");
            $st->execute([$row['student_id']]);
            $n = $st->fetch(PDO::FETCH_ASSOC);
            $row['first_name'] = $n['first_name'] ?? null;
            $row['last_name'] = $n['last_name'] ?? null;
        }
        auditJson(['status' => 'ok', 'data' => $rows]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Reporte disciplinario
if ($cleanPath === '/audit/discipline/reports' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT si.incident_id, si.incident_type, si.severity_level, si.description, si.detected_at, si.resolved,
                   s.first_name, s.last_name, s.document_number
            FROM security_incidents si
            LEFT JOIN students s ON si.related_student_id = s.student_id
            WHERE si.school_id = ?
            ORDER BY si.detected_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Historial estudiante
if ($cleanPath === '/audit/discipline/student-history' && $method === 'GET') {
    try {
        $studentId = $_GET['student_id'] ?? null;
        if (!$studentId) auditError('student_id requerido', 400);

        $stmt = $conn->prepare("
            SELECT calculated_at, late_count, absence_count, total_events, risk_score, risk_level
            FROM student_behavior_metrics
            WHERE school_id = ? AND student_id = ?
            ORDER BY calculated_at DESC
            LIMIT 30
        ");
        $stmt->execute([$schoolId, $studentId]);
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $conn->prepare("
            SELECT si.incident_id, si.incident_type, si.severity_level, si.description, si.detected_at, si.resolved
            FROM security_incidents si
            WHERE si.school_id = ? AND si.related_student_id = ?
            ORDER BY si.detected_at DESC
            LIMIT 50
        ");
        $stmt2->execute([$schoolId, $studentId]);
        $incidents = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        auditJson(['status' => 'ok', 'metrics' => $metrics, 'incidents' => $incidents]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// ============================================================================
// 3. PERMISOS Y SALIDAS
// ============================================================================

// Salidas clase
if ($cleanPath === '/audit/permissions/class-exits' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT cea.authorization_id, cea.exit_time, cea.return_time, cea.authorization_reason,
                   s.first_name, s.last_name, u.first_name AS authorized_by_first, u.last_name AS authorized_by_last
            FROM class_exit_authorizations cea
            LEFT JOIN students s ON cea.student_id = s.student_id
            LEFT JOIN users u ON cea.authorized_by_user_id = u.user_id
            WHERE cea.school_id = ?
            ORDER BY cea.exit_time DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Salidas colegio
if ($cleanPath === '/audit/permissions/school-exits' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT sea.authorization_id, sea.exit_time, sea.expected_return_time, sea.actual_return_time,
                   sea.status, sea.authorization_reason,
                   s.first_name, s.last_name, u.first_name AS authorized_by_first, u.last_name AS authorized_by_last
            FROM school_exit_authorizations sea
            LEFT JOIN students s ON sea.student_id = s.student_id
            LEFT JOIN users u ON sea.authorized_by_user_id = u.user_id
            WHERE sea.school_id = ?
            ORDER BY sea.exit_time DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Salidas pedagógicas
if ($cleanPath === '/audit/permissions/pedagogical' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT pta.authorization_id, pta.destination, pta.departure_time, pta.return_time, pta.purpose,
                   s.first_name, s.last_name, u.first_name AS authorized_by_first, u.last_name AS authorized_by_last
            FROM pedagogical_trip_authorizations pta
            LEFT JOIN students s ON pta.student_id = s.student_id
            LEFT JOIN users u ON pta.authorized_by_user_id = u.user_id
            WHERE pta.school_id = ?
            ORDER BY pta.departure_time DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Retornos pendientes
if ($cleanPath === '/audit/permissions/pending-returns' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT sea.authorization_id, sea.exit_time, sea.expected_return_time, sea.status,
                   s.first_name, s.last_name, u.first_name AS authorized_by_first, u.last_name AS authorized_by_last
            FROM school_exit_authorizations sea
            LEFT JOIN students s ON sea.student_id = s.student_id
            LEFT JOIN users u ON sea.authorized_by_user_id = u.user_id
            WHERE sea.school_id = ? AND sea.actual_return_time IS NULL AND sea.status IN ('APPROVED','PENDING')
            ORDER BY sea.exit_time DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// Historial permisos
if ($cleanPath === '/audit/permissions/history' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            (SELECT 'class' AS type, authorization_id, exit_time AS event_time, authorization_reason AS reason,
                    s.first_name, s.last_name
             FROM class_exit_authorizations
             LEFT JOIN students s ON class_exit_authorizations.student_id = s.student_id
             WHERE school_id = ? AND exit_time BETWEEN ? AND ?)
            UNION ALL
            (SELECT 'school' AS type, authorization_id, exit_time AS event_time, authorization_reason AS reason,
                    s.first_name, s.last_name
             FROM school_exit_authorizations
             LEFT JOIN students s ON school_exit_authorizations.student_id = s.student_id
             WHERE school_id = ? AND exit_time BETWEEN ? AND ?)
            UNION ALL
            (SELECT 'trip' AS type, authorization_id, departure_time AS event_time, purpose AS reason,
                    s.first_name, s.last_name
             FROM pedagogical_trip_authorizations
             LEFT JOIN students s ON pedagogical_trip_authorizations.student_id = s.student_id
             WHERE school_id = ? AND departure_time BETWEEN ? AND ?)
            ORDER BY event_time DESC
            LIMIT 200
        ");
        $stmt->execute([$schoolId, $from, $to, $schoolId, $from, $to, $schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}


// ============================================================================
// 4. MENSAJERÍA
// ============================================================================

if ($cleanPath === '/audit/messaging/whatsapp-sent' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT tm.twilio_message_id, tm.phone_number, tm.message_content, tm.delivery_status,
                   tm.sent_at, tm.type_code, s.first_name, s.last_name
            FROM twilio_messages tm
            LEFT JOIN students s ON tm.student_id = s.student_id
            WHERE tm.school_id = ? AND tm.direction = 'OUTBOUND'
            ORDER BY tm.sent_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/messaging/guardian-replies' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT tm.twilio_message_id, tm.phone_number, tm.message_content, tm.sent_at,
                   s.first_name, s.last_name
            FROM twilio_messages tm
            LEFT JOIN students s ON tm.student_id = s.student_id
            WHERE tm.school_id = ? AND tm.direction = 'INBOUND'
            ORDER BY tm.sent_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/messaging/failed' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT tm.twilio_message_id, tm.phone_number, tm.message_content, tm.delivery_status,
                   tm.sent_at, tm.type_code, s.first_name, s.last_name
            FROM twilio_messages tm
            LEFT JOIN students s ON tm.student_id = s.student_id
            WHERE tm.school_id = ? AND tm.delivery_status NOT IN ('delivered','read','sent')
            ORDER BY tm.sent_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/messaging/citations' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT tm.twilio_message_id, tm.phone_number, tm.message_content, tm.delivery_status,
                   tm.sent_at, s.first_name, s.last_name
            FROM twilio_messages tm
            LEFT JOIN students s ON tm.student_id = s.student_id
            WHERE tm.school_id = ? AND tm.type_code = 'CITACION'
            ORDER BY tm.sent_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/messaging/internal' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT im.message_id, im.subject, im.message_content, im.sent_at, im.read_at,
                   us.first_name AS sender_first, us.last_name AS sender_last,
                   ur.first_name AS receiver_first, ur.last_name AS receiver_last
            FROM internal_messages im
            LEFT JOIN users us ON im.sender_user_id = us.user_id
            LEFT JOIN users ur ON im.receiver_user_id = ur.user_id
            WHERE im.school_id = ?
            ORDER BY im.sent_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/messaging/conversations' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT tm.phone_number, COUNT(*) AS message_count,
                   MIN(tm.sent_at) AS first_message, MAX(tm.sent_at) AS last_message,
                   s.first_name, s.last_name
            FROM twilio_messages tm
            LEFT JOIN students s ON tm.student_id = s.student_id
            WHERE tm.school_id = ?
            GROUP BY tm.phone_number, s.first_name, s.last_name
            ORDER BY last_message DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}


// ============================================================================
// 5. ACTIVIDAD DOCENTE
// ============================================================================

if ($cleanPath === '/audit/teacher/activity' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT uc.command_id, uc.command_type, uc.executed_at, uc.command_payload,
                   u.first_name, u.last_name, u.email
            FROM user_commands uc
            LEFT JOIN users u ON uc.executed_by_user_id = u.user_id
            WHERE uc.school_id = ?
              AND uc.executed_by_user_id IN (
                  SELECT user_id FROM users WHERE school_id = ? AND role_id IN (
                      SELECT role_id FROM roles WHERE role_name = 'TEACHER'
                  )
              )
            ORDER BY uc.executed_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId, $schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/teacher/classes' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT sch.schedule_id, sch.day_of_week, sch.block_number, sch.start_time, sch.end_time,
                   ag.group_name, sub.subject_name, c.classroom_name,
                   u.first_name AS teacher_first, u.last_name AS teacher_last,
                   COUNT(DISTINCT be.student_id) AS attendance_count
            FROM schedules sch
            LEFT JOIN academic_groups ag ON sch.group_id = ag.group_id
            LEFT JOIN subjects sub ON sch.subject_id = sub.subject_id
            LEFT JOIN classrooms c ON sch.classroom_id = c.classroom_id
            LEFT JOIN users u ON sch.teacher_user_id = u.user_id
            LEFT JOIN biometric_events be ON be.schedule_id = sch.schedule_id
                AND be.event_type LIKE 'INGRESO_%'
                AND (be.event_timestamp AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
            WHERE sch.teacher_user_id IN (
                SELECT user_id FROM users WHERE school_id = ? AND role_id IN (
                    SELECT role_id FROM roles WHERE role_name = 'TEACHER'
                )
            )
            GROUP BY sch.schedule_id, ag.group_name, sub.subject_name, c.classroom_name,
                     u.first_name, u.last_name, sch.day_of_week, sch.block_number, sch.start_time, sch.end_time
            ORDER BY sch.day_of_week, sch.block_number
            LIMIT 200
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/teacher/permissions' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT uc.command_id, uc.command_type, uc.executed_at, uc.command_payload,
                   u.first_name, u.last_name
            FROM user_commands uc
            LEFT JOIN users u ON uc.executed_by_user_id = u.user_id
            WHERE uc.school_id = ? AND uc.command_type LIKE 'PERMISO%'
            ORDER BY uc.executed_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/teacher/incidents' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT si.incident_id, si.incident_type, si.severity_level, si.description, si.detected_at, si.resolved,
                   u.first_name AS teacher_first, u.last_name AS teacher_last,
                   s.first_name AS student_first, s.last_name AS student_last
            FROM security_incidents si
            LEFT JOIN users u ON si.related_user_id = u.user_id
            LEFT JOIN students s ON si.related_student_id = s.student_id
            WHERE si.school_id = ? AND si.related_user_id IN (
                SELECT user_id FROM users WHERE school_id = ? AND role_id IN (
                    SELECT role_id FROM roles WHERE role_name = 'TEACHER'
                )
            )
            ORDER BY si.detected_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId, $schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/teacher/system-activity' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT gal.log_id, gal.action_type, gal.action_details, gal.created_at, gal.ip_address,
                   u.first_name, u.last_name
            FROM global_audit_logs gal
            LEFT JOIN users u ON gal.performed_by_user_id = u.user_id
            WHERE gal.school_id = ? AND gal.performed_by_user_id IN (
                SELECT user_id FROM users WHERE school_id = ? AND role_id IN (
                    SELECT role_id FROM roles WHERE role_name = 'TEACHER'
                )
            )
            ORDER BY gal.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId, $schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}


// ============================================================================
// 6. SEGURIDAD
// ============================================================================

if ($cleanPath === '/audit/security/global' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT gal.log_id, gal.action_type, gal.action_details, gal.created_at, gal.ip_address,
                   u.first_name, u.last_name
            FROM global_audit_logs gal
            LEFT JOIN users u ON gal.performed_by_user_id = u.user_id
            WHERE gal.school_id = ?
            ORDER BY gal.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/security/accesses' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT us.session_id, us.ip_address, us.user_agent, us.created_at, us.revoked, us.revoked_at,
                   u.first_name, u.last_name, u.email
            FROM user_sessions us
            LEFT JOIN users u ON us.user_id = u.user_id
            WHERE u.school_id = ?
            ORDER BY us.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/security/sessions' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT us.session_id, us.ip_address, us.user_agent, us.created_at, us.expires_at, us.revoked,
                   u.first_name, u.last_name
            FROM user_sessions us
            LEFT JOIN users u ON us.user_id = u.user_id
            WHERE u.school_id = ?
            ORDER BY us.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/security/commands' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT uc.command_id, uc.command_type, uc.executed_at, uc.command_payload,
                   u.first_name, u.last_name
            FROM user_commands uc
            LEFT JOIN users u ON uc.executed_by_user_id = u.user_id
            WHERE uc.school_id = ?
            ORDER BY uc.executed_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/security/admin-activity' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT gal.log_id, gal.action_type, gal.action_details, gal.created_at, gal.ip_address,
                   u.first_name, u.last_name
            FROM global_audit_logs gal
            LEFT JOIN users u ON gal.performed_by_user_id = u.user_id
            WHERE gal.school_id = ? AND gal.performed_by_user_id IN (
                SELECT user_id FROM users WHERE school_id = ? AND role_id IN (
                    SELECT role_id FROM roles WHERE role_name IN ('RECTOR','SUPER_RECTOR','COORDINADOR')
                )
            )
            ORDER BY gal.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId, $schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/security/failed-attempts' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT gal.log_id, gal.action_type, gal.action_details, gal.created_at, gal.ip_address,
                   u.first_name, u.last_name
            FROM global_audit_logs gal
            LEFT JOIN users u ON gal.performed_by_user_id = u.user_id
            WHERE gal.school_id = ? AND gal.action_type LIKE 'FAILED%'
            ORDER BY gal.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

// ============================================================================
// 7. ALERTAS SOS
// ============================================================================

if ($cleanPath === '/audit/sos/alerts' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT sa.alert_id, sa.alert_type, sa.alert_description, sa.emitted_at, sa.resolved, sa.resolved_at,
                   u.first_name AS emitter_first, u.last_name AS emitter_last,
                   c.classroom_name
            FROM sos_alerts sa
            LEFT JOIN users u ON sa.emitted_by_user_id = u.user_id
            LEFT JOIN classrooms c ON sa.classroom_id = c.classroom_id
            WHERE sa.school_id = ?
            ORDER BY sa.emitted_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/sos/resolved' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT sa.alert_id, sa.alert_type, sa.alert_description, sa.emitted_at, sa.resolved_at,
                   ue.first_name AS emitter_first, ue.last_name AS emitter_last,
                   ur.first_name AS resolver_first, ur.last_name AS resolver_last,
                   c.classroom_name
            FROM sos_alerts sa
            LEFT JOIN users ue ON sa.emitted_by_user_id = ue.user_id
            LEFT JOIN users ur ON sa.resolved_by_user_id = ur.user_id
            LEFT JOIN classrooms c ON sa.classroom_id = c.classroom_id
            WHERE sa.school_id = ? AND sa.resolved = TRUE
            ORDER BY sa.resolved_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/sos/resolution-time' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT sa.alert_id, sa.alert_type, sa.emitted_at, sa.resolved_at,
                   EXTRACT(EPOCH FROM (sa.resolved_at - sa.emitted_at)) / 60 AS minutes_to_resolve,
                   ue.first_name AS emitter_first, ue.last_name AS emitter_last,
                   ur.first_name AS resolver_first, ur.last_name AS resolver_last
            FROM sos_alerts sa
            LEFT JOIN users ue ON sa.emitted_by_user_id = ue.user_id
            LEFT JOIN users ur ON sa.resolved_by_user_id = ur.user_id
            WHERE sa.school_id = ? AND sa.resolved = TRUE
            ORDER BY sa.emitted_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/sos/history' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("
            SELECT sa.alert_id, sa.alert_type, sa.alert_description, sa.emitted_at, sa.resolved, sa.resolved_at,
                   ue.first_name AS emitter_first, ue.last_name AS emitter_last,
                   ur.first_name AS resolver_first, ur.last_name AS resolver_last,
                   c.classroom_name
            FROM sos_alerts sa
            LEFT JOIN users ue ON sa.emitted_by_user_id = ue.user_id
            LEFT JOIN users ur ON sa.resolved_by_user_id = ur.user_id
            LEFT JOIN classrooms c ON sa.classroom_id = c.classroom_id
            WHERE sa.school_id = ?
            ORDER BY sa.emitted_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}


// ============================================================================
// 8. HISTÓRICOS
// ============================================================================

if ($cleanPath === '/audit/historical/student' && $method === 'GET') {
    try {
        $studentId = $_GET['student_id'] ?? null;
        if (!$studentId) auditError('student_id requerido', 400);
        $stmt = $conn->prepare("
            SELECT sra.audit_id, sra.action_type, sra.previous_data, sra.new_data, sra.performed_at,
                   u.first_name, u.last_name
            FROM student_record_audit sra
            LEFT JOIN users u ON sra.performed_by_user_id = u.user_id
            WHERE sra.school_id = ? AND sra.student_id = ?
            ORDER BY sra.performed_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId, $studentId]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/historical/teacher' && $method === 'GET') {
    try {
        $userId = $_GET['user_id'] ?? null;
        $sql = "SELECT uc.command_id, uc.command_type, uc.executed_at, uc.command_payload FROM user_commands uc WHERE uc.school_id = ?";
        $params = [$schoolId];
        if ($userId) { $sql .= " AND uc.executed_by_user_id = ?"; $params[] = $userId; }
        else { $sql .= " AND uc.executed_by_user_id IN (SELECT user_id FROM users WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE role_name = 'TEACHER'))"; $params[] = $schoolId; }
        $sql .= " ORDER BY uc.executed_at DESC LIMIT 100";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/historical/attendance' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT be.event_id, be.event_type, be.event_timestamp, s.first_name, s.last_name
            FROM biometric_events be LEFT JOIN students s ON be.student_id = s.student_id
            WHERE be.school_id = ? AND (be.event_timestamp AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
            ORDER BY be.event_timestamp DESC LIMIT 200
        ");
        $stmt->execute([$schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/historical/discipline' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT si.incident_id, si.incident_type, si.severity_level, si.description, si.detected_at, si.resolved, s.first_name, s.last_name
            FROM security_incidents si LEFT JOIN students s ON si.related_student_id = s.student_id
            WHERE si.school_id = ? AND (si.detected_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
            ORDER BY si.detected_at DESC LIMIT 200
        ");
        $stmt->execute([$schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/historical/permissions' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            (SELECT 'class' AS type, authorization_id, exit_time AS event_time, authorization_reason AS reason, created_at FROM class_exit_authorizations WHERE school_id = ? AND exit_time BETWEEN ? AND ?)
            UNION ALL
            (SELECT 'school' AS type, authorization_id, exit_time AS event_time, authorization_reason AS reason, created_at FROM school_exit_authorizations WHERE school_id = ? AND exit_time BETWEEN ? AND ?)
            UNION ALL
            (SELECT 'trip' AS type, authorization_id, departure_time AS event_time, purpose AS reason, created_at FROM pedagogical_trip_authorizations WHERE school_id = ? AND departure_time BETWEEN ? AND ?)
            ORDER BY event_time DESC LIMIT 200
        ");
        $stmt->execute([$schoolId, $from, $to, $schoolId, $from, $to, $schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/historical/messaging' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT tm.twilio_message_id, tm.phone_number, tm.message_content, tm.direction, tm.type_code, tm.delivery_status, tm.sent_at, s.first_name, s.last_name
            FROM twilio_messages tm LEFT JOIN students s ON tm.student_id = s.student_id
            WHERE tm.school_id = ? AND (tm.sent_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
            ORDER BY tm.sent_at DESC LIMIT 200
        ");
        $stmt->execute([$schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/historical/search' && $method === 'GET') {
    try {
        $q = $_GET['q'] ?? '';
        if ($q === '') auditJson(['status' => 'ok', 'data' => []]);
        $like = "%{$q}%";
        $results = [];
        $st = $conn->prepare("SELECT student_id, first_name, last_name, document_number, 'student' AS entity FROM students WHERE school_id = ? AND (first_name ILIKE ? OR last_name ILIKE ? OR document_number ILIKE ?) LIMIT 20");
        $st->execute([$schoolId, $like, $like, $like]);
        $results = array_merge($results, $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $conn->prepare("SELECT user_id, first_name, last_name, email, 'user' AS entity FROM users WHERE school_id = ? AND (first_name ILIKE ? OR last_name ILIKE ? OR email ILIKE ?) LIMIT 20");
        $st->execute([$schoolId, $like, $like, $like]);
        $results = array_merge($results, $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $conn->prepare("SELECT incident_id, incident_type, description, detected_at, 'incident' AS entity FROM security_incidents WHERE school_id = ? AND (description ILIKE ? OR incident_type ILIKE ?) LIMIT 20");
        $st->execute([$schoolId, $like, $like]);
        $results = array_merge($results, $st->fetchAll(PDO::FETCH_ASSOC));
        auditJson(['status' => 'ok', 'data' => $results]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/historical/download' && $method === 'GET') {
    try {
        $type = $_GET['type'] ?? '';
        $id   = $_GET['id'] ?? '';
        if (!$type || !$id) auditError('type e id requeridos', 400);
        switch ($type) {
            case 'student':
                $stmt = $conn->prepare("SELECT sra.*, u.first_name, u.last_name FROM student_record_audit sra LEFT JOIN users u ON sra.performed_by_user_id = u.user_id WHERE sra.school_id = ? AND sra.student_id = ? ORDER BY sra.performed_at DESC");
                $stmt->execute([$schoolId, $id]);
                break;
            case 'user':
                $stmt = $conn->prepare("SELECT gal.*, u.first_name, u.last_name FROM global_audit_logs gal LEFT JOIN users u ON gal.performed_by_user_id = u.user_id WHERE gal.school_id = ? AND gal.performed_by_user_id = ? ORDER BY gal.created_at DESC");
                $stmt->execute([$schoolId, $id]);
                break;
            case 'incident':
                $stmt = $conn->prepare("SELECT si.*, s.first_name, s.last_name FROM security_incidents si LEFT JOIN students s ON si.related_student_id = s.student_id WHERE si.school_id = ? AND si.incident_id = ?");
                $stmt->execute([$schoolId, $id]);
                break;
            default:
                auditError('tipo no soportado', 400);
        }
        auditJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/historical/download-consolidated' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $consolidated = [];
        $stmt = $conn->prepare("SELECT 'attendance' AS section, COUNT(*) AS count FROM biometric_events WHERE school_id = ? AND (event_timestamp AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?");
        $stmt->execute([$schoolId, $from, $to]);
        $consolidated[] = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $conn->prepare("SELECT 'discipline' AS section, COUNT(*) AS count FROM security_incidents WHERE school_id = ? AND (detected_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?");
        $stmt->execute([$schoolId, $from, $to]);
        $consolidated[] = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $conn->prepare("SELECT 'messages' AS section, COUNT(*) AS count FROM twilio_messages WHERE school_id = ? AND (sent_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?");
        $stmt->execute([$schoolId, $from, $to]);
        $consolidated[] = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $conn->prepare("SELECT 'sos' AS section, COUNT(*) AS count FROM sos_alerts WHERE school_id = ? AND (emitted_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?");
        $stmt->execute([$schoolId, $from, $to]);
        $consolidated[] = $stmt->fetch(PDO::FETCH_ASSOC);
        auditJson(['status' => 'ok', 'from' => $from, 'to' => $to, 'data' => $consolidated]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}


// ============================================================================
// 9. REPORTES CONSOLIDADOS
// ============================================================================

if ($cleanPath === '/audit/consolidated/attendance' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) FILTER (WHERE event_type LIKE 'INGRESO_%') AS entries,
                COUNT(*) FILTER (WHERE event_type LIKE 'INGRESO_TARDE%') AS lates,
                COUNT(*) FILTER (WHERE event_type LIKE 'INASISTENCIA%') AS absences,
                COUNT(DISTINCT student_id) AS unique_students
            FROM biometric_events
            WHERE school_id = ? AND (event_timestamp AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
        ");
        $stmt->execute([$schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'period' => [$from, $to], 'summary' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/consolidated/discipline' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total_incidents,
                COUNT(*) FILTER (WHERE severity_level = 'CRITICAL') AS critical,
                COUNT(*) FILTER (WHERE severity_level = 'HIGH') AS high,
                COUNT(*) FILTER (WHERE severity_level = 'MEDIUM') AS medium,
                COUNT(*) FILTER (WHERE severity_level = 'LOW') AS low,
                COUNT(*) FILTER (WHERE resolved = TRUE) AS resolved
            FROM security_incidents
            WHERE school_id = ? AND (detected_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
        ");
        $stmt->execute([$schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'period' => [$from, $to], 'summary' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/consolidated/permissions' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM class_exit_authorizations WHERE school_id = ? AND exit_time BETWEEN ? AND ?) AS class_exits,
                (SELECT COUNT(*) FROM school_exit_authorizations WHERE school_id = ? AND exit_time BETWEEN ? AND ?) AS school_exits,
                (SELECT COUNT(*) FROM pedagogical_trip_authorizations WHERE school_id = ? AND departure_time BETWEEN ? AND ?) AS trips
        ");
        $stmt->execute([$schoolId, $from, $to, $schoolId, $from, $to, $schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'period' => [$from, $to], 'summary' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/consolidated/messaging' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) FILTER (WHERE direction = 'OUTBOUND') AS sent,
                COUNT(*) FILTER (WHERE direction = 'INBOUND') AS replies,
                COUNT(*) FILTER (WHERE delivery_status NOT IN ('delivered','read','sent')) AS failed,
                COUNT(*) FILTER (WHERE type_code = 'CITACION') AS citations
            FROM twilio_messages
            WHERE school_id = ? AND (sent_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
        ");
        $stmt->execute([$schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'period' => [$from, $to], 'summary' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/consolidated/teacher' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM user_commands WHERE school_id = ? AND executed_at BETWEEN ? AND ? AND executed_by_user_id IN (SELECT user_id FROM users WHERE role_id IN (SELECT role_id FROM roles WHERE role_name = 'TEACHER'))) AS commands,
                (SELECT COUNT(*) FROM schedules WHERE teacher_user_id IN (SELECT user_id FROM users WHERE school_id = ? AND role_id IN (SELECT role_id FROM roles WHERE role_name = 'TEACHER'))) AS total_classes
        ");
        $stmt->execute([$schoolId, $from, $to, $schoolId]);
        auditJson(['status' => 'ok', 'period' => [$from, $to], 'summary' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/consolidated/security' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM global_audit_logs WHERE school_id = ? AND created_at BETWEEN ? AND ?) AS audit_logs,
                (SELECT COUNT(*) FROM user_sessions WHERE user_id IN (SELECT user_id FROM users WHERE school_id = ?)) AS sessions,
                (SELECT COUNT(*) FROM user_commands WHERE school_id = ? AND executed_at BETWEEN ? AND ?) AS commands,
                (SELECT COUNT(*) FROM global_audit_logs WHERE school_id = ? AND action_type LIKE 'FAILED%' AND created_at BETWEEN ? AND ?) AS failed_attempts
        ");
        $stmt->execute([$schoolId, $from, $to, $schoolId, $schoolId, $from, $to, $schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'period' => [$from, $to], 'summary' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

if ($cleanPath === '/audit/consolidated/institutional' && $method === 'GET') {
    try {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM students WHERE school_id = ? AND active = TRUE) AS active_students,
                (SELECT COUNT(*) FROM users WHERE school_id = ? AND active = TRUE) AS active_users,
                (SELECT COUNT(*) FROM academic_groups WHERE school_id = ?) AS groups,
                (SELECT COUNT(*) FROM biometric_events WHERE school_id = ? AND (event_timestamp AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?) AS total_events,
                (SELECT COUNT(*) FROM sos_alerts WHERE school_id = ? AND (emitted_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?) AS sos_count,
                (SELECT COUNT(*) FROM security_incidents WHERE school_id = ? AND (detected_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?) AS incidents_count
        ");
        $stmt->execute([$schoolId, $schoolId, $schoolId, $schoolId, $from, $to, $schoolId, $from, $to, $schoolId, $from, $to]);
        auditJson(['status' => 'ok', 'period' => [$from, $to], 'summary' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { auditError($e->getMessage()); }
}

