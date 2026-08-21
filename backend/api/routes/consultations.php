<?php
/**
 * =============================================================================
 * routes/consultations.php — Motor de consultas dinámicas unificado.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone POST /consultations/query, un endpoint genérico que retorna datos
 * tabulados para distintos módulos del frontend según el parámetro `module`.
 * Soporta módulos de asistencia, disciplina, mensajería, estudiantes,
 * personal, métricas institucionales, auditoría, etc.
 *
 * Restricciones de rol:
 *   - Los docentes/psicoorientadores deben tener el grupo asignado en schedules.
 *   - Si no envían group_name, se fuerza a filtrar por sus grupos propios.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación y permisos.
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Frontend: tablas dinámicas y reportes (ConsultationViewer, etc.).
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

// ============================================================================
// POST /consultations/query — Motor de consultas por módulo.
// ============================================================================
if ($cleanPath === '/consultations/query') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId   = $authUser['id'];
    $role     = $authUser['role'];

    // El frontend DEBE enviar slugs inmutables, no textos de UI en español.
    $module = filter_var($input['module'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $groupName = filter_var($input['group_name'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $studentId = filter_var($input['student_id'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $fromDate = filter_var($input['from_date'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $toDate = filter_var($input['to_date'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $grade = filter_var($input['grade'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $userRoleUpper = strtoupper($role ?? '');

    if (!$module) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Módulo requerido']));
    }

    // Helper: validar que el docente/psicorientador tenga asignado el grupo
    // Los roles con global_view (rector, coordinador, secretaria) bypassan la validación
    $teacherGroupFilter = '';
    $hasGlobalView = in_array('consultations.global_view', $authUser['permissions'] ?? []);
    $isTeacher = in_array('consultations.teacher_view', $authUser['permissions'] ?? [])
        && !$hasGlobalView;
    if ($isTeacher) {
        if ($groupName) {
            $checkStmt = $conn->prepare("
                SELECT 1 FROM teacher_group_access tga
                JOIN academic_groups ag ON ag.group_id = tga.group_id
                WHERE tga.teacher_user_id = ? AND ag.group_name = ?
                LIMIT 1
            ");
            $checkStmt->execute([$userId, $groupName]);
            $hasSchedule = (bool)$checkStmt->fetchColumn();
            if (!$hasSchedule) {
                http_response_code(403);
                exit(json_encode(['status' => 'error', 'message' => 'No tienes acceso a este grupo.']));
            }
        } else {
            // FIX: Si el docente NO envía grupo, forzamos que solo vea estudiantes de sus propios grupos
            $safeUserId = $conn->quote($userId);
            $teacherGroupFilter = " AND s.student_id IN (
                SELECT sga.student_id FROM student_group_assignments sga
                JOIN teacher_group_access tga ON tga.group_id = sga.group_id
                WHERE tga.teacher_user_id = {$safeUserId} AND sga.active = TRUE
            )";
        }
    }

    // Helper: filtro por grado (ej. '6', '7', ... '11') — filtra grupos cuyo grade_level coincide
    $gradeFilter = '';
    if ($grade) {
        $gradeFilter = " AND s.student_id IN (
            SELECT sga.student_id FROM student_group_assignments sga
            JOIN academic_groups ag ON ag.group_id = sga.group_id
            WHERE ag.grade_level = ? AND sga.active = TRUE
        )";
    }

    // Fechas por defecto: hoy
    if (!$fromDate) $fromDate = gmdate('Y-m-d');
    if (!$toDate) $toDate = gmdate('Y-m-d');

    try {
        $data = [];
        $columns = [];

        switch ($module) {
            // ==========================================
            // DOCENTES & PSICORIENTADOR: Mis Clases
            // ==========================================
            case 'group_students':
                $groupFilter = $groupName ? " AND ag.group_name = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, s.document_number, ag.group_name
                    FROM students s
                    JOIN student_group_assignments sga ON s.student_id = sga.student_id AND sga.active = TRUE
                    JOIN academic_groups ag ON sga.group_id = ag.group_id
                    WHERE s.school_id = ? AND s.deleted_at IS NULL {$groupFilter} {$teacherGroupFilter}
                    ORDER BY ag.group_name, s.last_name
                    LIMIT 100
                ");
                $params = [$schoolId];
                if ($groupName) $params[] = $groupName;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'document_number' => 'Documento', 'group_name' => 'Grupo'];
                break;

            case 'late_arrivals':
                $gFilter = $groupName ? " AND be.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "";
                $sFilter = $studentId ? " AND be.student_id = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, be.event_timestamp, be.event_type, be.event_result
                    FROM biometric_events be
                    JOIN students s ON be.student_id = s.student_id
                    WHERE be.school_id = ?
                      AND (be.event_type LIKE 'INGRESO_TARDE%' OR be.event_type LIKE 'LATE%' OR be.event_result = 'LATE')
                      AND be.event_timestamp >= (?::date) AND be.event_timestamp < ((?::date + INTERVAL '1 day'))
                      {$gFilter}
                      {$sFilter}
                      {$teacherGroupFilter}
                      {$gradeFilter}
                    ORDER BY be.event_timestamp DESC
                    LIMIT 50
                ");
                $params = [$schoolId, $fromDate, $toDate];
                if ($groupName) $params[] = $groupName;
                if ($studentId) $params[] = $studentId;
                if ($grade) $params[] = $grade;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'event_timestamp' => 'Fecha/Hora', 'event_type' => 'Tipo', 'event_result' => 'Resultado'];
                break;

            case 'absences':
                $gFilter = $groupName ? " AND ai.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "";
                $sFilter = $studentId ? " AND ai.student_id = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ai.detected_at, ai.incident_type
                    FROM attendance_incidents ai
                    JOIN students s ON ai.student_id = s.student_id
                    WHERE ai.school_id = ? AND ai.incident_type IN ('UNAUTHORIZED_ABSENCE', 'INASISTENCIA')
                      AND ai.detected_at >= (?::date) AND ai.detected_at < ((?::date + INTERVAL '1 day'))
                      {$gFilter}
                      {$sFilter}
                      {$teacherGroupFilter}
                      {$gradeFilter}
                    ORDER BY ai.detected_at DESC
                    LIMIT 50
                ");
                $params = [$schoolId, $fromDate, $toDate];
                if ($groupName) $params[] = $groupName;
                if ($studentId) $params[] = $studentId;
                if ($grade) $params[] = $grade;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'detected_at' => 'Fecha/Hora', 'incident_type' => 'Incidente'];
                break;

            case 'active_permissions':
                $gFilter = $groupName ? " AND ai.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "";
                $sFilter = $studentId ? " AND ai.student_id = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ai.detected_at, ai.incident_type
                    FROM attendance_incidents ai
                    JOIN students s ON ai.student_id = s.student_id
                    WHERE ai.school_id = ? AND ai.incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')
                      AND ai.detected_at >= (?::date) AND ai.detected_at < ((?::date + INTERVAL '1 day'))
                      {$gFilter}
                      {$sFilter}
                      {$teacherGroupFilter}
                      {$gradeFilter}
                    ORDER BY ai.detected_at DESC
                    LIMIT 50
                ");
                $params = [$schoolId, $fromDate, $toDate];
                if ($groupName) $params[] = $groupName;
                if ($studentId) $params[] = $studentId;
                if ($grade) $params[] = $grade;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'detected_at' => 'Fecha/Hora', 'incident_type' => 'Tipo Permiso'];
                break;

            // ==========================================
            // HISTORIAL & MENSAJERÍA
            // ==========================================
            case 'attendance_history':
                $dateFrom = $input['date_from'] ?? $fromDate;
                $dateTo   = $input['date_to']   ?? $toDate;

                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, be.event_timestamp, be.event_type
                    FROM biometric_events be
                    JOIN students s ON be.student_id = s.student_id
                    WHERE be.school_id = :sid
                      AND be.event_timestamp >= (:date_from::date)
                      AND be.event_timestamp < ((:date_to::date + INTERVAL '1 day'))
                      {$teacherGroupFilter}
                    ORDER BY be.event_timestamp DESC
                    LIMIT 500
                ");
                $stmt->execute([
                    ':sid'       => $schoolId,
                    ':date_from' => $dateFrom,
                    ':date_to'   => $dateTo,
                ]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'event_timestamp' => 'Fecha/Hora', 'event_type' => 'Evento'];
                break;

            case 'incidents':
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ai.incident_type, ai.detected_at, ai.metadata_json, s.student_id
                    FROM attendance_incidents ai
                    JOIN students s ON ai.student_id = s.student_id
                    WHERE ai.school_id = ? AND (ai.incident_type IN ('INCIDENTE', 'DAÑO', 'SOS') OR ai.incident_type LIKE 'RISK_ALERT%')
                      {$teacherGroupFilter}
                    ORDER BY ai.detected_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'incident_type' => 'Tipo', 'detected_at' => 'Fecha'];
                break;

            case 'student_tracking_active':
                try {
                    $stmt = $conn->prepare("
                        SELECT s.first_name, s.last_name, s.document_number, st.status, st.updated_at, st.tracking_id, st.student_id
                        FROM student_tracking st
                        JOIN students s ON st.student_id = s.student_id
                        WHERE st.school_id = ?
                          {$teacherGroupFilter}
                        ORDER BY CASE WHEN st.status = 'en proceso' THEN 1 ELSE 2 END, st.updated_at DESC
                        LIMIT 100
                    ");
                    $stmt->execute([$schoolId]);
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'document_number' => 'Documento', 'status' => 'Estado', 'updated_at' => 'Última Act.'];
                } catch (PDOException $e) {
                    $data = [];
                    $columns = ['info' => 'Sin datos de seguimiento disponibles'];
                    securityLog('TRACKING_TABLE_MISSING', $e->getMessage());
                }
                break;

            case 'student_tracking_completed':
                try {
                    $stmt = $conn->prepare("
                        SELECT s.first_name, s.last_name, s.document_number, st.status, st.updated_at, st.tracking_id, st.student_id
                        FROM student_tracking st
                        JOIN students s ON st.student_id = s.student_id
                        WHERE st.school_id = ?
                          AND st.status != 'en proceso'
                        ORDER BY st.updated_at DESC
                        LIMIT 100
                    ");
                    $stmt->execute([$schoolId]);
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'document_number' => 'Documento', 'status' => 'Estado', 'updated_at' => 'Última Act.'];
                } catch (PDOException $e) {
                    $data = [];
                    $columns = ['info' => 'Sin datos de seguimientos completados disponibles'];
                    securityLog('TRACKING_TABLE_MISSING', $e->getMessage());
                }
                break;

            case 'sent_messages':
                $adminRoles = ['SECRETARY', 'COORDINATOR', 'RECTOR'];
                $isAdmin = in_array($authUser['role'], $adminRoles);

                if ($isAdmin) {
                    $stmt = $conn->prepare(
                        "SELECT
                             m.twilio_message_id,
                             m.phone_number,
                             m.message_content,
                             m.delivery_status,
                             m.sent_at
                         FROM twilio_messages m
                         WHERE m.school_id = :sid
                         ORDER BY m.sent_at DESC
                         LIMIT 200"
                    );
                    $stmt->execute([':sid' => $schoolId]);
                } else {
                    $stmt = $conn->prepare(
                        "SELECT
                             m.twilio_message_id,
                             m.phone_number,
                             m.message_content,
                             m.direction,
                             m.sent_at
                         FROM twilio_messages m
                         WHERE m.school_id  = :sid
                           AND m.sender_user_id = :tid
                         ORDER BY m.sent_at DESC"
                    );
                    $stmt->execute([':sid' => $schoolId, ':tid' => $userId]);
                }
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['phone_number' => 'Teléfono', 'type_code' => 'Tipo', 'message_content' => 'Mensaje', 'sent_at' => 'Enviado', 'delivery_status' => 'Estado'];
                break;

            case 'internal_messages':
                $stmt = $conn->prepare("
                    SELECT u.first_name as sender_name, u.last_name as sender_last, im.subject, im.message_content, im.sent_at
                    FROM internal_messages im
                    JOIN users u ON im.sender_user_id = u.user_id
                    WHERE im.school_id = ? AND (im.receiver_user_id = ? OR im.sender_user_id = ?)
                    ORDER BY im.sent_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$schoolId, $userId, $userId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['sender_name' => 'Nombre', 'sender_last' => 'Apellido', 'subject' => 'Asunto', 'message_content' => 'Mensaje', 'sent_at' => 'Fecha'];
                break;

            // ==========================================
            // COORDINACIÓN: Módulos de Permisos y Salidas
            // ==========================================
            case 'biometric_spam':
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, be.event_timestamp, be.event_type, be.event_result,
                           COUNT(*) OVER (PARTITION BY be.student_id) as intentos
                    FROM biometric_events be
                    JOIN students s ON be.student_id = s.student_id
                    WHERE be.school_id = ?
                      AND be.event_result IN ('NO_MATCH', 'SPOOF_DETECTED', 'LIVENESS_FAIL', 'TIMEOUT')
                      AND be.event_timestamp >= (?::date) AND be.event_timestamp < ((?::date + INTERVAL '1 day'))
                      {$teacherGroupFilter}
                    ORDER BY be.event_timestamp DESC
                    LIMIT 100
                ");
                $stmt->execute([$schoolId, $fromDate, $toDate]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                            'event_timestamp' => 'Fecha/Hora', 'event_type' => 'Tipo',
                            'event_result' => 'Resultado', 'intentos' => 'Intentos'];
                break;

            case 'issued_permissions':
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ag.group_name,
                           cea.authorization_reason as reason,
                           cea.exit_time, cea.return_time,
                           u.first_name as issuer_first, u.last_name as issuer_last
                    FROM class_exit_authorizations cea
                    JOIN students s ON cea.student_id = s.student_id
                    LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                    LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
                    LEFT JOIN users u ON u.user_id = cea.authorized_by_user_id
                    WHERE cea.school_id = ?
                      AND cea.exit_time >= (?::date) AND cea.exit_time < ((?::date + INTERVAL '1 day'))
                      {$teacherGroupFilter}
                    ORDER BY cea.exit_time DESC
                    LIMIT 100
                ");
                $stmt->execute([$schoolId, $fromDate, $toDate]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                            'group_name' => 'Grupo', 'reason' => 'Motivo',
                            'exit_time' => 'Salida', 'return_time' => 'Retorno',
                            'issuer_first' => 'Autorizado por'];
                break;

            case 'school_exits':
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ag.group_name,
                           sea.authorization_reason as reason,
                           sea.exit_time, sea.status,
                           u.first_name as issuer_first, u.last_name as issuer_last
                    FROM school_exit_authorizations sea
                    JOIN students s ON sea.student_id = s.student_id
                    LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                    LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
                    LEFT JOIN users u ON u.user_id = sea.authorized_by_user_id
                    WHERE sea.school_id = ?
                      AND sea.exit_time >= (?::date) AND sea.exit_time < ((?::date + INTERVAL '1 day'))
                      {$teacherGroupFilter}
                    ORDER BY sea.exit_time DESC
                    LIMIT 100
                ");
                $stmt->execute([$schoolId, $fromDate, $toDate]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                            'group_name' => 'Grupo', 'reason' => 'Motivo',
                            'exit_time' => 'Fecha Salida', 'status' => 'Estado'];
                break;

            case 'pedagogical_trips':
                // Consulta user_commands tipo PEDAGOGICA (el nuevo flujo ya no inserta en
                // pedagogical_trip_authorizations sino que notifica vía WhatsApp directamente)
                $stmt = $conn->prepare("
                    SELECT uc.executed_at,
                           uc.command_payload,
                           u.first_name as issuer_first,
                           u.last_name  as issuer_last
                    FROM user_commands uc
                    LEFT JOIN users u ON u.user_id = uc.executed_by_user_id
                    WHERE uc.school_id = ? AND uc.command_type = 'PEDAGOGICA'
                      AND uc.executed_at >= (?::date) AND uc.executed_at < ((?::date + INTERVAL '1 day'))
                    ORDER BY uc.executed_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$schoolId, $fromDate, $toDate]);
                $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $data = array_map(function($r) {
                    $payload = json_decode($r['command_payload'] ?? '{}', true) ?: [];
                    return [
                        'grupo'        => $payload['group']   ?? '—',
                        'motivo'       => $payload['reason']  ?? $payload['message'] ?? '—',
                        'issuer_first' => $r['issuer_first'],
                        'issuer_last'  => $r['issuer_last'],
                        'executed_at'  => $r['executed_at'],
                    ];
                }, $rawRows);
                $columns = ['grupo' => 'Grupo', 'motivo' => 'Motivo',
                            'issuer_first' => 'Autorizado por', 'executed_at' => 'Fecha'];
                break;

            // ==========================================
            // COORDINACIÓN & RECTORÍA & SECRETARÍA
            // ==========================================
            case 'all_groups':
                $stmt = $conn->prepare("
                    SELECT group_name, grade_level, academic_year
                    FROM academic_groups
                    WHERE school_id = ?
                    ORDER BY group_name
                ");
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['group_name' => 'Grupo', 'grade_level' => 'Grado', 'academic_year' => 'Año'];
                break;

            case 'all_teachers':
                $stmt = $conn->prepare("
                    SELECT u.first_name, u.last_name, u.email, u.phone
                    FROM users u
                    JOIN roles r ON u.role_id = r.role_id
                    WHERE u.school_id = ? AND r.role_name = 'TEACHER' AND u.deleted_at IS NULL
                ");
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'email' => 'Email', 'phone' => 'Teléfono'];
                break;

            case 'all_students':
                // FIX: Cursor pagination (keyset) con UUID (usando created_at y student_id como desempate)
                $lastCreatedAt = $input['last_created_at'] ?? null;
                $lastStudentId = $input['last_student_id'] ?? null;
                $pageSize = 100;

                if ($lastCreatedAt) {
                    $stmt = $conn->prepare("
                        SELECT first_name, last_name, document_number, created_at, student_id
                        FROM students
                        WHERE school_id = :sid AND active = TRUE
                          AND (created_at, student_id) < (:last_created_at, :last_student_id::UUID)
                        ORDER BY created_at DESC, student_id DESC
                        LIMIT :page_size
                    ");
                    $stmt->bindValue(':sid',             $schoolId,      PDO::PARAM_STR);
                    $stmt->bindValue(':last_created_at', $lastCreatedAt, PDO::PARAM_STR);
                    $stmt->bindValue(':last_student_id', $lastStudentId, PDO::PARAM_STR);
                    $stmt->bindValue(':page_size',       $pageSize,      PDO::PARAM_INT);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("
                        SELECT first_name, last_name, document_number, created_at, student_id
                        FROM students
                        WHERE school_id = :sid AND active = TRUE
                        ORDER BY created_at DESC, student_id DESC
                        LIMIT :page_size
                    ");
                    $stmt->bindValue(':sid',       $schoolId, PDO::PARAM_STR);
                    $stmt->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
                    $stmt->execute();
                }
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'document_number' => 'Documento'];
                break;

            case 'all_guardians':
                $stmt = $conn->prepare(
                    "SELECT
                         g.guardian_id,
                         u.first_name || ' ' || u.last_name AS full_name,
                         u.phone,
                         u.email,
                         g.whatsapp_phone
                     FROM guardians g
                     JOIN users u ON g.user_id = u.user_id
                     INNER JOIN guardian_student_relationships gsr
                         ON gsr.guardian_id = g.guardian_id
                     INNER JOIN students s
                         ON s.student_id = gsr.student_id
                     WHERE s.school_id = ?
                     ORDER BY u.last_name, u.first_name ASC"
                );
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['full_name' => 'Nombre', 'phone' => 'Teléfono', 'whatsapp_phone' => 'WhatsApp'];
                break;

            case 'institutional_metrics':
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, s.document_number,
                           COALESCE(ag.group_name, 'Sin grupo') as group_name,
                           TO_CHAR(s.created_at, 'DD/MM/YYYY') as enrolled_at,
                           CASE WHEN s.active THEN 'Activo' ELSE 'Inactivo' END as estado
                    FROM students s
                    LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                    LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
                    WHERE s.school_id = ?
                    ORDER BY s.created_at DESC
                    LIMIT 200
                ");
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                            'document_number' => 'Documento', 'group_name' => 'Grupo',
                            'enrolled_at' => 'Matrícula', 'estado' => 'Estado'];
                break;

            case 'staff':
                $roleFilter = ['TEACHER', 'SECRETARY', 'SECURITY', 'AUXILIARY', 'COUNSELOR', 'COORDINATOR'];
                $placeholders = implode(',', array_fill(0, count($roleFilter), '?'));
                $stmt = $conn->prepare("
                    SELECT u.first_name, u.last_name, u.email, u.phone, r.role_name as rol,
                           CASE WHEN u.active THEN 'Activo' ELSE 'Inactivo' END as estado
                    FROM users u
                    JOIN roles r ON r.role_id = u.role_id
                    WHERE u.school_id = ? AND UPPER(r.role_name) IN ($placeholders)
                    ORDER BY u.last_name, u.first_name
                ");
                $stmt->execute(array_merge([$schoolId], $roleFilter));
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                            'email' => 'Email', 'phone' => 'Teléfono',
                            'rol' => 'Rol', 'estado' => 'Estado'];
                break;
                
            case 'staff_auxiliary':
                $roleFilter = ['AUXILIARY'];
                $placeholders = implode(',', array_fill(0, count($roleFilter), '?'));
                $stmt = $conn->prepare("
                    SELECT u.first_name, u.last_name, u.email, u.phone, r.role_name as rol,
                           CASE WHEN u.active THEN 'Activo' ELSE 'Inactivo' END as estado
                    FROM users u
                    JOIN roles r ON r.role_id = u.role_id
                    WHERE u.school_id = ? AND UPPER(r.role_name) IN ($placeholders)
                    ORDER BY u.last_name, u.first_name
                ");
                $stmt->execute(array_merge([$schoolId], $roleFilter));
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                            'email' => 'Email', 'phone' => 'Teléfono',
                            'rol' => 'Rol', 'estado' => 'Estado'];
                break;
                
            case 'staff_security':
                $roleFilter = ['SECURITY'];
                $placeholders = implode(',', array_fill(0, count($roleFilter), '?'));
                $stmt = $conn->prepare("
                    SELECT u.first_name, u.last_name, u.email, u.phone, r.role_name as rol,
                           CASE WHEN u.active THEN 'Activo' ELSE 'Inactivo' END as estado
                    FROM users u
                    JOIN roles r ON r.role_id = u.role_id
                    WHERE u.school_id = ? AND UPPER(r.role_name) IN ($placeholders)
                    ORDER BY u.last_name, u.first_name
                ");
                $stmt->execute(array_merge([$schoolId], $roleFilter));
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                            'email' => 'Email', 'phone' => 'Teléfono',
                            'rol' => 'Rol', 'estado' => 'Estado'];
                break;

            case 'reports':
                $stmt = $conn->prepare("
                    SELECT report_type as tipo, 
                           TO_CHAR(generated_at, 'DD/MM/YYYY HH12:MI AM') as generado_en,
                           format as formato,
                           COALESCE(status, 'completado') as estado
                    FROM report_exports
                    WHERE school_id = ?
                    ORDER BY generated_at DESC
                    LIMIT 100
                ");
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['tipo' => 'Tipo', 'generado_en' => 'Generado en', 'formato' => 'Formato', 'estado' => 'Estado'];
                break;

            // ==========================================
            // NUEVOS MÓDULOS: Inasistencias Justificadas, Eventos Críticos
            // ==========================================
            case 'justified_absences':
                $gFilter = $groupName ? " AND ai.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "";
                $sFilter = $studentId ? " AND ai.student_id = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ai.detected_at, ai.incident_type, s.student_id, ai.metadata_json,
                           ai.metadata_json->>'motivo' as motivo,
                           ai.metadata_json->>'guardian_phone' as guardian_phone,
                           ai.metadata_json->>'justified_by' as justified_by
                    FROM attendance_incidents ai
                    JOIN students s ON ai.student_id = s.student_id
                    WHERE ai.school_id = ? AND ai.incident_type IN ('JUSTIFIED_ABSENCE', 'INASISTENCIA_JUSTIFICADA')
                      AND ai.detected_at >= (?::date) AND ai.detected_at < ((?::date + INTERVAL '1 day'))
                      {$gFilter}
                      {$sFilter}
                      {$teacherGroupFilter}
                      {$gradeFilter}
                    ORDER BY ai.detected_at DESC
                    LIMIT 50
                ");
                $params = [$schoolId, $fromDate, $toDate];
                if ($groupName) $params[] = $groupName;
                if ($studentId) $params[] = $studentId;
                if ($grade) $params[] = $grade;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'detected_at' => 'Fecha/Hora', 'incident_type' => 'Tipo', 'motivo' => 'Motivo'];
                break;

            case 'unjustified_absences':
                $gFilter = $groupName ? " AND ai.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "";
                $sFilter = $studentId ? " AND ai.student_id = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ai.detected_at, ai.incident_type, s.student_id, ai.metadata_json,
                           ai.metadata_json->>'guardian_phone' as guardian_phone,
                           ai.metadata_json->>'guardian_response' as guardian_response
                    FROM attendance_incidents ai
                    JOIN students s ON ai.student_id = s.student_id
                    WHERE ai.school_id = ? AND ai.incident_type = 'INASISTENCIA_NO_JUSTIFICADA'
                      AND ai.detected_at >= (?::date) AND ai.detected_at < ((?::date + INTERVAL '1 day'))
                      {$gFilter}
                      {$sFilter}
                      {$teacherGroupFilter}
                      {$gradeFilter}
                    ORDER BY ai.detected_at DESC
                    LIMIT 50
                ");
                $params = [$schoolId, $fromDate, $toDate];
                if ($groupName) $params[] = $groupName;
                if ($studentId) $params[] = $studentId;
                if ($grade) $params[] = $grade;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'detected_at' => 'Fecha/Hora', 'incident_type' => 'Tipo', 'guardian_response' => 'Respuesta del acudiente'];
                break;

            case 'evasions':
                $gFilter = $groupName ? " AND s.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "";
                $sFilter = $studentId ? " AND s.student_id = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, s.student_id, s.document_number,
                           ai.detected_at, ai.incident_type, ai.metadata_json,
                           ag.group_name, ag.grade_level,
                           ai.metadata_json->>'classroom' as classroom,
                           ai.metadata_json->>'detected_by' as detected_by,
                           ai.metadata_json->>'expected_classroom' as expected_classroom
                    FROM attendance_incidents ai
                    JOIN students s ON ai.student_id = s.student_id
                    LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                    LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
                    WHERE ai.school_id = ? AND ai.incident_type IN ('EVASION_INTERNA')
                      AND ai.detected_at >= (?::date) AND ai.detected_at < ((?::date + INTERVAL '1 day'))
                      {$gFilter}
                      {$sFilter}
                      {$teacherGroupFilter}
                      {$gradeFilter}
                    ORDER BY ai.detected_at DESC
                    LIMIT 500
                ");
                $params = [$schoolId, $fromDate, $toDate];
                if ($groupName) $params[] = $groupName;
                if ($studentId) $params[] = $studentId;
                if ($grade) $params[] = $grade;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = [
                    'first_name' => 'Nombre', 'last_name' => 'Apellido',
                    'document_number' => 'Documento', 'group_name' => 'Grupo',
                    'grade_level' => 'Grado', 'detected_at' => 'Fecha/Hora',
                    'incident_type' => 'Tipo', 'classroom' => 'Salón',
                    'expected_classroom' => 'Salón esperado', 'detected_by' => 'Detectado por'
                ];
                break;

            case 'sos_emitted':
                $stmt = $conn->prepare("
                    SELECT sa.alert_id, sa.alert_description, sa.emitted_at, sa.resolved_at,
                           u.first_name AS emitter_first, u.last_name AS emitter_last,
                           sa.emitted_by_user_id
                    FROM sos_alerts sa
                    LEFT JOIN users u ON sa.emitted_by_user_id = u.user_id
                    WHERE sa.school_id = ?
                      AND sa.emitted_at >= (?::date) AND sa.emitted_at < ((?::date + INTERVAL '1 day'))
                    ORDER BY sa.emitted_at DESC
                    LIMIT 100
                ");
                $stmt->execute([$schoolId, $fromDate, $toDate]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['emitter_first' => 'Nombre', 'emitter_last' => 'Apellido', 'alert_description' => 'Descripción', 'emitted_at' => 'Fecha/Hora', 'resolved_at' => 'Resuelto'];
                break;

            case 'damages_reported':
                $stmt = $conn->prepare("
                    SELECT uc.executed_at, uc.command_payload, u.first_name, u.last_name
                    FROM user_commands uc
                    LEFT JOIN users u ON uc.executed_by_user_id = u.user_id
                    WHERE uc.school_id = ? AND uc.command_type = 'DAÑO'
                      AND uc.executed_at >= (?::date) AND uc.executed_at < ((?::date + INTERVAL '1 day'))
                    ORDER BY uc.executed_at DESC
                    LIMIT 100
                ");
                $stmt->execute([$schoolId, $fromDate, $toDate]);
                $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $data = array_map(function($r) {
                    $payload = json_decode($r['command_payload'] ?? '{}', true) ?: [];
                    return [
                        'first_name' => $r['first_name'],
                        'last_name' => $r['last_name'],
                        'location' => $payload['location'] ?? '—',
                        'description' => $payload['description'] ?? $payload['message'] ?? '—',
                        'executed_at' => $r['executed_at'],
                    ];
                }, $rawRows);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'location' => 'Ubicación', 'description' => 'Descripción', 'executed_at' => 'Fecha/Hora'];
                break;

            case 'critical_situations':
                $stmt = $conn->prepare("
                    SELECT uc.executed_at, uc.command_payload, u.first_name, u.last_name
                    FROM user_commands uc
                    LEFT JOIN users u ON uc.executed_by_user_id = u.user_id
                    WHERE uc.school_id = ? AND uc.command_type = 'SITUACION_CRITICA'
                      AND uc.executed_at >= (?::date) AND uc.executed_at < ((?::date + INTERVAL '1 day'))
                    ORDER BY uc.executed_at DESC
                    LIMIT 100
                ");
                $stmt->execute([$schoolId, $fromDate, $toDate]);
                $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $data = array_map(function($r) {
                    $payload = json_decode($r['command_payload'] ?? '{}', true) ?: [];
                    return [
                        'first_name' => $r['first_name'],
                        'last_name' => $r['last_name'],
                        'location' => $payload['location'] ?? '—',
                        'message' => $payload['message'] ?? '—',
                        'executed_at' => $r['executed_at'],
                    ];
                }, $rawRows);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'location' => 'Ubicación', 'message' => 'Detalle', 'executed_at' => 'Fecha/Hora'];
                break;

            default:
                $data = [];
                $columns = ['info' => 'Módulo desconocido'];
                break;
        }

        echo json_encode([
            'status' => 'ok',
            'data' => $data,
            'columns' => $columns,
            'meta' => [
                'module' => $module,
                'count' => count($data)
            ]
        ]);

    } catch (Exception $e) {
        securityLog('CONSULTATION_QUERY_ERROR', $e->getMessage(), $userId, $schoolId);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al consultar datos', 'debug' => $e->getMessage()]);
    }
    exit;
}
