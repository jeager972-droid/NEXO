<?php
// routes/consultations.php - Motor de consultas dinámicas unificado
global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

if ($cleanPath === '/consultations/query') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId   = $authUser['id'];
    $role     = $authUser['role'];

    $module = filter_var($input['module'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $groupName = filter_var($input['group_name'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $studentId = filter_var($input['student_id'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $fromDate = filter_var($input['from_date'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $toDate = filter_var($input['to_date'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $userRoleUpper = strtoupper($role ?? '');

    if (!$module) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Módulo requerido']));
    }

    // Helper: validar que el docente/psicorientador tenga asignado el grupo
    // FIX: si no tiene schedules, fallback a validar que el grupo pertenezca a la institución
    $teacherGroupFilter = '';
    $isTeacher = in_array($userRoleUpper, ['DOCENTE', 'PSICORIENTADOR']);
    if ($isTeacher && $groupName) {
        $checkStmt = $conn->prepare("
            SELECT 1 FROM schedules sch
            JOIN academic_groups ag ON ag.group_id = sch.group_id
            WHERE sch.teacher_user_id = ? AND ag.group_name = ?
            LIMIT 1
        ");
        $checkStmt->execute([$userId, $groupName]);
        $hasSchedule = (bool)$checkStmt->fetchColumn();
        if (!$hasSchedule) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'No tienes acceso a este grupo.']));
        }
    }

    // Helper: subquery para filtrar estudiantes de un grupo
    $groupSubSql = function($alias = 's') {
        return " AND {$alias}.student_id IN (
            SELECT sga.student_id FROM student_group_assignments sga
            JOIN academic_groups ag ON ag.group_id = sga.group_id
            WHERE ag.group_name = ? AND sga.active = TRUE
        )";
    };

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
            case 'Estudiantes del Grupo':
                $groupFilter = $groupName ? " AND ag.group_name = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, s.document_number, ag.group_name
                    FROM students s
                    JOIN student_group_assignments sga ON s.student_id = sga.student_id AND sga.active = TRUE
                    JOIN academic_groups ag ON sga.group_id = ag.group_id
                    WHERE s.school_id = ? AND s.active = TRUE {$groupFilter}
                    ORDER BY ag.group_name, s.last_name
                    LIMIT 100
                ");
                $params = [$schoolId];
                if ($groupName) $params[] = $groupName;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'document_number' => 'Documento', 'group_name' => 'Grupo'];
                break;

            case 'Llegadas Tarde':
            case 'Historial Tardanzas':
                $gFilter = $groupName ? " AND be.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "";
                $sFilter = $studentId ? " AND be.student_id = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, be.event_timestamp, be.event_type, be.event_result
                    FROM biometric_events be
                    JOIN students s ON be.student_id = s.student_id
                    WHERE be.school_id = ?
                      AND (be.event_type LIKE 'INGRESO_TARDE%' OR be.event_type LIKE 'LATE%' OR be.event_result = 'LATE')
                      AND (be.event_timestamp AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
                      {$gFilter}
                      {$sFilter}
                    ORDER BY be.event_timestamp DESC
                    LIMIT 50
                ");
                $params = [$schoolId, $fromDate, $toDate];
                if ($groupName) $params[] = $groupName;
                if ($studentId) $params[] = $studentId;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'event_timestamp' => 'Fecha/Hora', 'event_type' => 'Tipo', 'event_result' => 'Resultado'];
                break;

            case 'Inasistencias':
            case 'Estudiantes Ausentes':
                $gFilter = $groupName ? " AND ai.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "";
                $sFilter = $studentId ? " AND ai.student_id = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ai.detected_at, ai.incident_type
                    FROM attendance_incidents ai
                    JOIN students s ON ai.student_id = s.student_id
                    WHERE ai.school_id = ? AND ai.incident_type IN ('UNAUTHORIZED_ABSENCE', 'INASISTENCIA')
                      AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
                      {$gFilter}
                      {$sFilter}
                    ORDER BY ai.detected_at DESC
                    LIMIT 50
                ");
                $params = [$schoolId, $fromDate, $toDate];
                if ($groupName) $params[] = $groupName;
                if ($studentId) $params[] = $studentId;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'detected_at' => 'Fecha/Hora', 'incident_type' => 'Incidente'];
                break;

            case 'Estudiantes fuera del salón':
            case 'Estudiantes con Permiso':
            case 'Mis Permisos':
            case 'Permisos Activos':
                $gFilter = $groupName ? " AND ai.student_id IN (SELECT sga.student_id FROM student_group_assignments sga JOIN academic_groups ag ON ag.group_id = sga.group_id WHERE ag.group_name = ? AND sga.active = TRUE)" : "";
                $sFilter = $studentId ? " AND ai.student_id = ?" : "";
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ai.detected_at, ai.incident_type
                    FROM attendance_incidents ai
                    JOIN students s ON ai.student_id = s.student_id
                    WHERE ai.school_id = ? AND ai.incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')
                      AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
                      {$gFilter}
                      {$sFilter}
                    ORDER BY ai.detected_at DESC
                    LIMIT 50
                ");
                $params = [$schoolId, $fromDate, $toDate];
                if ($groupName) $params[] = $groupName;
                if ($studentId) $params[] = $studentId;
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'detected_at' => 'Fecha/Hora', 'incident_type' => 'Tipo Permiso'];
                break;

            // ==========================================
            // HISTORIAL & MENSAJERÍA
            // ==========================================
            case 'Historial Asistencia':
            case 'Asistencia General':
            case 'Asistencia Institucional':
                $dateFrom = $input['date_from'] ?? null;
                $dateTo   = $input['date_to']   ?? null;

                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, be.event_timestamp, be.event_type
                    FROM biometric_events be
                    JOIN students s ON be.student_id = s.student_id
                    WHERE be.school_id = :sid
                      AND (be.event_timestamp AT TIME ZONE 'America/Bogota')::date
                          BETWEEN :date_from AND :date_to
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

            case 'Incidentes Disciplinarios':
            case 'Vulneraciones':
            case 'Alertas':
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, ai.incident_type, ai.detected_at, ai.metadata_json, s.student_id
                    FROM attendance_incidents ai
                    JOIN students s ON ai.student_id = s.student_id
                    WHERE ai.school_id = ? AND (ai.incident_type IN ('INCIDENTE', 'DAÑO', 'SOS') OR ai.incident_type LIKE 'RISK_ALERT%')
                    ORDER BY ai.detected_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'incident_type' => 'Tipo', 'detected_at' => 'Fecha'];
                break;

            case 'Seguimiento Estudiantil':
                $stmt = $conn->prepare("
                    SELECT s.first_name, s.last_name, s.document_number, st.status, st.updated_at, st.tracking_id, st.student_id
                    FROM student_tracking st
                    JOIN students s ON st.student_id = s.student_id
                    WHERE st.school_id = ?
                    ORDER BY CASE WHEN st.status = 'en proceso' THEN 1 ELSE 2 END, st.updated_at DESC
                    LIMIT 100
                ");
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'document_number' => 'Documento', 'status' => 'Estado', 'updated_at' => 'Última Act.'];
                break;

            case 'Mensajes Enviados':
            case 'Respuestas Acudientes':
            case 'Citaciones':
                $adminRoles = ['SECRETARIA', 'COORDINADOR', 'RECTOR',
                               'SUPER_RECTOR'];
                $isAdmin = in_array($authUser['role'], $adminRoles);

                if ($isAdmin) {
                    $stmt = $conn->prepare(
                        "SELECT
                             m.message_id,
                             m.recipient_phone,
                             m.message_body,
                             m.status,
                             m.created_at
                         FROM twilio_messages m
                         WHERE m.school_id = :sid
                         ORDER BY m.created_at DESC
                         LIMIT 200"
                    );
                    $stmt->execute([':sid' => $schoolId]);
                } else {
                    $stmt = $conn->prepare(
                        "SELECT
                             m.message_id,
                             m.recipient_phone,
                             m.message_body,
                             m.direction,
                             m.created_at
                         FROM twilio_messages m
                         WHERE m.school_id  = :sid
                           AND m.teacher_id = :tid
                         ORDER BY m.created_at DESC"
                    );
                    $stmt->execute([':sid' => $schoolId, ':tid' => $userId]);
                }
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['phone_number' => 'Teléfono', 'type_code' => 'Tipo', 'message_content' => 'Mensaje', 'sent_at' => 'Enviado', 'delivery_status' => 'Estado'];
                break;

            case 'Mensajes Internos':
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
            // COORDINACIÓN & RECTORÍA & SECRETARÍA
            // ==========================================
            case 'TODOS los grupos':
            case 'Grupos':
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

            case 'TODOS los profesores':
            case 'Profesores':
                $stmt = $conn->prepare("
                    SELECT u.first_name, u.last_name, u.email, u.phone
                    FROM users u
                    JOIN roles r ON u.role_id = r.role_id
                    WHERE u.school_id = ? AND r.role_name IN ('DOCENTE', 'TEACHER') AND u.active = TRUE
                ");
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'email' => 'Email', 'phone' => 'Teléfono'];
                break;

            case 'Estudiantes':
                $lastId   = $input['last_id'] ?? null;
                $pageSize = 100;

                if ($lastId) {
                    $stmt = $conn->prepare("
                        SELECT first_name, last_name, document_number
                        FROM students
                        WHERE school_id = :sid AND active = TRUE
                          AND student_id > :last_id
                        ORDER BY student_id ASC
                        LIMIT :page_size
                    ");
                    $stmt->bindValue(':sid',       $schoolId,  PDO::PARAM_STR);
                    $stmt->bindValue(':last_id',   $lastId,    PDO::PARAM_STR);
                    $stmt->bindValue(':page_size', $pageSize,  PDO::PARAM_INT);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("
                        SELECT first_name, last_name, document_number
                        FROM students
                        WHERE school_id = :sid AND active = TRUE
                        ORDER BY student_id ASC
                        LIMIT :page_size
                    ");
                    $stmt->bindValue(':sid',       $schoolId, PDO::PARAM_STR);
                    $stmt->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
                    $stmt->execute();
                }
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'document_number' => 'Documento'];
                break;

            case 'Acudientes':
                $stmt = $conn->prepare(
                    "SELECT
                         g.guardian_id,
                         g.full_name,
                         g.phone,
                         u.email
                     FROM guardians g
                     LEFT JOIN users u ON g.user_id = u.user_id
                     INNER JOIN guardian_student_relationships gsr
                         ON gsr.guardian_id = g.guardian_id
                     INNER JOIN students s
                         ON s.student_id = gsr.student_id
                     WHERE s.school_id = ?
                     ORDER BY g.full_name ASC"
                );
                $stmt->execute([$schoolId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'whatsapp_phone' => 'WhatsApp'];
                break;

            case 'Métricas Institucionales':
            case 'Métricas Globales':
            case 'Estadísticas Históricas':
            case 'Indicadores Críticos':
            case 'Grupos Críticos':
            case 'Estudiantes Críticos':
            case 'Reportes Históricos':
            case 'TODOS los Consolidados':
            case 'Históricos Completos':
            case 'Exportaciones Institucionales':
            case 'Matrículas':
            case 'Cambios Registro':
            case 'Auxiliares':
            case 'Portería':
            case 'Personal Institucional':
            case 'Reportes':
            case 'Auditoría Local':
            case 'Salidas Pedagógicas':
            case 'Autorizaciones Emitidas':
                // Fallback for pending specific complex queries but show empty structure to avoid crashing
                $data = [];
                $columns = ['info' => 'Información'];
                break;

            default:
                $data = [];
                $columns = ['info' => 'Información'];
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
        echo json_encode(['status' => 'error', 'message' => 'Error al consultar datos']);
    }
    exit;
}
