<?php
/**
 * =============================================================================
 * workers/worker_evasion_detector.php — Detección automática de evasión.
 * =============================================================================
 *
 * RESPONSABILIDAD
 * ----------------
 * Detecta estudiantes que salieron del salón y no han vuelto dentro del plazo
 * límite, generando alertas de EVASION_INTERNA. La lógica depende de si el
 * colegio rota de salones o no.
 *
 * LÓGICA — COLEGIO QUE NO ROTA DE SALONES
 * -----------------------------------------
 *   - El estudiante marca INGRESO al entrar a la institución.
 *   - Si marca nuevamente la huella, se interpreta como SALIDA (baño, permiso, etc).
 *   - Si tiene permiso activo: la alerta se activa 5 minutos después de que
 *     expire el permiso (return_time + 5 min).
 *   - Si NO tiene permiso: la alerta se activa pasados 15 minutos desde que salió.
 *   - Si regresa antes del plazo: se considera ida al baño (no genera alerta).
 *   - 5 minutos antes de la hora de salida (exit_time): es válido marcar SALIDA
 *     final. Este evento resta del conteo de presentes y desactiva el monitoreo.
 *   - Receso: 10 minutos después de recess_end_time, si el estudiante no ingresó
 *     y no tiene permiso, se activa alerta de evasión.
 *
 * LÓGICA — COLEGIO QUE ROTA DE SALONES
 * --------------------------------------
 *   - El estudiante marca huella al llegar a cada clase (no al salir).
 *   - Entre el fin de una clase y el inicio de la siguiente hay un margen de
 *     10 minutos. Pasados los 10 minutos sin registro en la siguiente clase,
 *     se considera evasión.
 *   - El resto de la lógica (permisos, receso) aplica igual.
 *
 * NOTIFICACIONES
 * ---------------
 *   - Al profesor responsable del momento (según schedules).
 *   - Al coordinador.
 *   - NO al rector (para evasión interna).
 *
 * EJECUCIÓN
 * ---------
 *   - Cron cada 2 minutos durante la jornada escolar.
 *   - O daemon con loop cada 120 segundos.
 */

declare(ticks=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../redis.php';

$shutdown = false;
pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

function logE(string $e, string $m = ''): void {
    error_log("[EVASION_DETECTOR] {$e} | {$m}");
}

/**
 * Envía notificación WhatsApp + notificación interna a un usuario.
 */
function notifyUser($conn, $redis, string $userId, string $phone, string $msg, string $schoolId, string $typeCode, array $meta): void {
    // Notificación interna
    try {
        $notifStmt = $conn->prepare("
            INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
            VALUES (?, ?, ?, ?, 'ALERT', ?::jsonb, NOW())
        ");
        $notifStmt->execute([
            $schoolId, $userId,
            'Alerta de Evasión',
            $msg,
            json_encode($meta, JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $e) {
        logE('NOTIF_INSERT_FAIL', $e->getMessage());
    }
    // WhatsApp
    if (!empty($phone)) {
        try {
            $payload = json_encode([
                'to' => $phone,
                'message' => $msg,
                'school_id' => $schoolId,
                'user_id' => $userId,
                'type_code' => $typeCode,
            ], JSON_UNESCAPED_UNICODE);
            $redis->rPush('queue:twilio', $payload);
            $redis->expire('queue:twilio', 86400);
        } catch (Exception $e) {
            logE('TWILIO_ENQUEUE_FAIL', $e->getMessage());
        }
    }
}

/**
 * Verifica si ya existe un incidente de evasión para el estudiante hoy
 * (evita duplicados / acumulación).
 */
function hasEvasionToday(PDO $conn, string $schoolId, string $studentId): bool {
    $stmt = $conn->prepare("
        SELECT 1 FROM attendance_incidents
        WHERE student_id = ? AND school_id = ?
          AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
          AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
          AND incident_type = 'EVASION_INTERNA'
        LIMIT 1
    ");
    $stmt->execute([$studentId, $schoolId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Registra un incidente de evasión en attendance_incidents.
 */
function insertEvasionIncident(PDO $conn, string $schoolId, string $studentId, string $metaJson): void {
    $conn->exec("BEGIN");
    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
    $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
    $stmt = $conn->prepare("
        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
        VALUES (uuid_generate_v4(), ?, ?, 'EVASION_INTERNA', NOW(), ?::jsonb)
    ");
    $stmt->execute([$schoolId, $studentId, $metaJson]);
    $conn->exec("COMMIT");
}

/**
 * Obtiene el profesor responsable actual de un estudiante según schedules
 * (bloque horario actual para el día de la semana y grupo del estudiante).
 */
function getCurrentTeacher(PDO $conn, string $schoolId, string $studentId): ?array {
    $nowBogota = new DateTime('now', new DateTimeZone('America/Bogota'));
    $dayOfWeek = (int)$nowBogota->format('N'); // 1=Lunes, 7=Domingo
    $currentTime = $nowBogota->format('H:i:s');

    $stmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.phone,
               sch.start_time, sch.end_time, sch.block_number
        FROM schedules sch
        JOIN student_group_assignments sga ON sga.group_id = sch.group_id AND sga.active = TRUE
        JOIN users u ON u.user_id = sch.teacher_user_id
        WHERE sga.student_id = ?
          AND sch.day_of_week = ?
          AND sch.start_time <= ?::time
          AND sch.end_time >= ?::time
        LIMIT 1
    ");
    $stmt->execute([$studentId, $dayOfWeek, $currentTime, $currentTime]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    return $teacher ?: null;
}

/**
 * Obtiene los coordinadores de una escuela (para notificación).
 */
function getCoordinators(PDO $conn, string $schoolId): array {
    $stmt = $conn->prepare("
        SELECT user_id, first_name, last_name, phone
        FROM users
        WHERE school_id = ?
          AND role_id IN (SELECT role_id FROM roles WHERE UPPER(role_name) = 'COORDINATOR')
          AND active = TRUE
    ");
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Procesa una institución: detecta evasión según configuración.
 */
function processSchoolEvasion(PDO $conn, $redis, string $schoolId): int {
    $detected = 0;
    $nowBogota = new DateTime('now', new DateTimeZone('America/Bogota'));
    $todayDate = $nowBogota->format('Y-m-d');
    $currentTimeStr = $nowBogota->format('H:i:s');

    // set_config para RLS
    try {
        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", false)");
    } catch (Exception $ignore) {}

    // 1. Obtener configuración institucional (multi-jornada: puede haber varias filas)
    $configStmt = $conn->prepare("SELECT * FROM school_schedule_config WHERE school_id = ? AND onboarding_completed = TRUE ORDER BY work_shift");
    $configStmt->execute([$schoolId]);
    $configs = $configStmt->fetchAll(PDO::FETCH_ASSOC);

    // Si no hay configuración (onboarding no completado), no procesar
    if (empty($configs)) {
        return 0;
    }

    // Procesar cada jornada activa
    foreach ($configs as $config) {
        $rotatesClassrooms = (bool)$config['rotates_classrooms'];
        $entryTime = $config['entry_time'];
        $exitTime = $config['exit_time'];
        $recessStart = $config['recess_start_time'];
        $recessEnd = $config['recess_end_time'];

        // Si no hay entry_time o exit_time, saltar esta jornada
        if (!$entryTime || !$exitTime) continue;

        // 2. Verificar si estamos dentro de esta jornada escolar
        $entryTs = DateTime::createFromFormat('H:i:s', $entryTime, new DateTimeZone('America/Bogota'));
        $exitTs = DateTime::createFromFormat('H:i:s', $exitTime, new DateTimeZone('America/Bogota'));
        if (!$entryTs || !$exitTs) continue;

        // 5 minutos antes de exit_time: permitir salida final, no detectar evasión
        $exitThreshold = clone $exitTs;
        $exitThreshold->sub(new DateInterval('PT5M'));
        if ($nowBogota >= $exitThreshold) continue;

        // Antes de entry_time: no detectar evasión en esta jornada
        $entryTsToday = DateTime::createFromFormat('Y-m-d H:i:s', "$todayDate $entryTime", new DateTimeZone('America/Bogota'));
        if ($nowBogota < $entryTsToday) continue;

        // 3. Verificar si estamos en receso
        $inRecess = false;
        if ($recessStart && $recessEnd) {
            $recessStartTs = DateTime::createFromFormat('Y-m-d H:i:s', "$todayDate $recessStart", new DateTimeZone('America/Bogota'));
            $recessEndTs = DateTime::createFromFormat('Y-m-d H:i:s', "$todayDate $recessEnd", new DateTimeZone('America/Bogota'));
            if ($nowBogota >= $recessStartTs && $nowBogota <= $recessEndTs) {
                $inRecess = true;
            }
            // 10 minutos después del receso: verificar que todos hayan vuelto
            $recessEndPlus10 = clone $recessEndTs;
            $recessEndPlus10->add(new DateInterval('PT10M'));
            if ($nowBogota > $recessEndTs && $nowBogota <= $recessEndPlus10) {
                // Verificar estudiantes que no volvieron del receso
                $detected += checkRecessReturn($conn, $redis, $schoolId, $todayDate, $recessStart, $recessEnd);
            }
        }

        if ($inRecess) continue; // En receso no se detecta evasión en esta jornada

        // 4. Detectar evasión según modo
        if ($rotatesClassrooms) {
            $detected += detectEvasionRotating($conn, $redis, $schoolId, $todayDate, $nowBogota, $config);
        } else {
            $detected += detectEvasionNonRotating($conn, $redis, $schoolId, $todayDate, $nowBogota, $config);
        }
    }

    return $detected;
}

/**
 * Detección de evasión para colegios que NO rotan de salones.
 *
 * El estudiante marca INGRESO al entrar. Si marca nuevamente, es SALIDA.
 * Sin permiso: alerta a los 15 min. Con permiso: alerta 5 min después de expirar.
 */
function detectEvasionNonRotating(PDO $conn, $redis, string $schoolId, string $todayDate, DateTime $nowBogota, array $config): int {
    $detected = 0;

    // Obtener estudiantes que ingresaron hoy y luego salieron (segundo evento biométrico)
    // Un evento SALIDA_% o un segundo INGRESO_% indica que salió del salón
    $stmt = $conn->prepare("
        SELECT be.student_id, s.first_name, s.last_name,
               MAX(be.event_timestamp) as last_event_time,
               COUNT(be.event_id) as event_count,
               array_agg(be.event_type ORDER BY be.event_timestamp) as event_types
        FROM biometric_events be
        JOIN students s ON s.student_id = be.student_id
        WHERE be.school_id = ?
          AND be.event_timestamp >= ?::date
          AND be.event_timestamp < (?::date + INTERVAL '1 day')
          AND be.event_type IN ('INGRESO_INSTITUCION', 'INGRESO_AULA', 'SALIDA_AULA', 'SALIDA_INSTITUCION', 'INGRESO_BAÑO', 'SALIDA_BAÑO')
          AND s.active = TRUE AND s.deleted_at IS NULL
        GROUP BY be.student_id, s.first_name, s.last_name
        HAVING COUNT(be.event_id) >= 2
    ");
    $stmt->execute([$schoolId, $todayDate, $todayDate]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($students as $student) {
        $studentId = $student['student_id'];
        $studentName = trim($student['first_name'] . ' ' . $student['last_name']);

        // Si ya tiene alerta de evasión hoy, skip
        if (hasEvasionToday($conn, $schoolId, $studentId)) continue;

        // El último evento es la "salida"
        $lastEventTime = new DateTime($student['last_event_time'], new DateTimeZone('UTC'));
        $lastEventTime->setTimezone(new DateTimeZone('America/Bogota'));

        // Verificar si ya regresó (tiene un INGRESO después de la salida)
        $returnCheck = $conn->prepare("
            SELECT 1 FROM biometric_events
            WHERE school_id = ? AND student_id = ?
              AND event_timestamp > ?
              AND event_type LIKE 'INGRESO_%'
              AND event_timestamp >= ?::date
              AND event_timestamp < (?::date + INTERVAL '1 day')
            LIMIT 1
        ");
        $returnCheck->execute([$schoolId, $studentId, $student['last_event_time'], $todayDate, $todayDate]);
        if ($returnCheck->fetchColumn()) continue; // Ya regresó, no es evasión

        // Tiempo transcurrido desde la salida
        $elapsed = $nowBogota->getTimestamp() - $lastEventTime->getTimestamp();
        $elapsedMin = (int)($elapsed / 60);

        // Verificar si tiene permiso activo
        $permisoStmt = $conn->prepare("
            SELECT authorization_id, exit_time, return_time, authorization_reason
            FROM class_exit_authorizations
            WHERE school_id = ? AND student_id = ? AND status = 'ACTIVE'
              AND exit_time <= NOW()
            ORDER BY exit_time DESC LIMIT 1
        ");
        $permisoStmt->execute([$schoolId, $studentId]);
        $permiso = $permisoStmt->fetch(PDO::FETCH_ASSOC);

        $shouldAlert = false;
        $alertReason = '';

        if ($permiso) {
            $returnTime = $permiso['return_time'] ? new DateTime($permiso['return_time'], new DateTimeZone('UTC')) : null;
            if ($returnTime) {
                $returnTime->setTimezone(new DateTimeZone('America/Bogota'));
                $minutesAfterExpiry = ($nowBogota->getTimestamp() - $returnTime->getTimestamp()) / 60;
                if ($minutesAfterExpiry >= 5) {
                    $shouldAlert = true;
                    $alertReason = "Permiso expirado hace {$minutesAfterExpiry} min (retorno esperado: " . $returnTime->format('H:i') . ")";
                }
            }
        } else {
            // Sin permiso: 15 minutos
            if ($elapsedMin >= 15) {
                $shouldAlert = true;
                $alertReason = "Salió hace {$elapsedMin} min sin permiso (salida a las " . $lastEventTime->format('H:i') . ")";
            }
        }

        if (!$shouldAlert) continue;

        // Generar alerta
        $teacher = getCurrentTeacher($conn, $schoolId, $studentId);
        $coordinators = getCoordinators($conn, $schoolId);

        $meta = json_encode([
            'student_id' => $studentId,
            'student_name' => $studentName,
            'last_event_time' => $lastEventTime->format('H:i'),
            'elapsed_minutes' => $elapsedMin,
            'has_permiso' => (bool)$permiso,
            'alert_reason' => $alertReason,
            'teacher_name' => $teacher ? trim($teacher['first_name'] . ' ' . $teacher['last_name']) : 'N/A',
            'action' => 'evasion_interna',
        ], JSON_UNESCAPED_UNICODE);

        try {
            insertEvasionIncident($conn, $schoolId, $studentId, $meta);
            $detected++;
            logE('EVASION_DETECTED', "school=$schoolId student=$studentName reason=$alertReason");

            // Notificar al profesor
            if ($teacher) {
                $teacherName = trim($teacher['first_name'] . ' ' . $teacher['last_name']);
                $teacherMsg = "⚠️ *NEXO — Evasión Detectada*\n\nEstudiante: *{$studentName}*\n{$alertReason}\n\nSe ha avisado al coordinador de la situación.";
                notifyUser($conn, $redis, $teacher['user_id'], $teacher['phone'] ?? '', $teacherMsg, $schoolId, 'EVASION_INTERNA', json_decode($meta, true));
            }

            // Notificar a coordinadores
            $coordMsg = "⚠️ *NEXO — Evasión Detectada*\n\nEstudiante: *{$studentName}*\n{$alertReason}";
            if ($teacher) {
                $coordMsg .= "\nProfesor responsable: " . trim($teacher['first_name'] . ' ' . $teacher['last_name']);
            }
            foreach ($coordinators as $coord) {
                notifyUser($conn, $redis, $coord['user_id'], $coord['phone'] ?? '', $coordMsg, $schoolId, 'EVASION_INTERNA', json_decode($meta, true));
            }
        } catch (Exception $e) {
            try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
            logE('INSERT_FAIL', "student=$studentId error=" . $e->getMessage());
        }
    }

    return $detected;
}

/**
 * Detección de evasión para colegios que SÍ rotan de salones.
 *
 * Entre el fin de una clase y el inicio de la siguiente hay 10 min de margen.
 * Si el estudiante no marca ingreso en la siguiente clase dentro de esos 10 min,
 * se considera evasión.
 */
function detectEvasionRotating(PDO $conn, $redis, string $schoolId, string $todayDate, DateTime $nowBogota, array $config): int {
    $detected = 0;
    $dayOfWeek = (int)$nowBogota->format('N');

    // Obtener bloques horarios de la institución para esta jornada
    $workShift = $config['work_shift'] ?? 'mañana';
    $blocksStmt = $conn->prepare("
        SELECT block_number, start_time, end_time, block_name
        FROM school_time_blocks
        WHERE school_id = ? AND work_shift = ?
        ORDER BY block_number
    ");
    $blocksStmt->execute([$schoolId, $workShift]);
    $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($blocks) < 2) return 0; // Necesita al menos 2 bloques para detectar evasión

    // Para cada par de bloques consecutivos, verificar estudiantes que asistieron
    // al bloque N pero no marcaron ingreso en el bloque N+1 dentro del margen
    for ($i = 0; $i < count($blocks) - 1; $i++) {
        $currentBlock = $blocks[$i];
        $nextBlock = $blocks[$i + 1];

        $nextStart = DateTime::createFromFormat('H:i:s', $nextBlock['start_time'], new DateTimeZone('America/Bogota'));
        if (!$nextStart) continue;

        // El margen de 10 min empieza desde el inicio del siguiente bloque
        $deadline = clone $nextStart;
        $deadline->add(new DateInterval('PT10M'));

        // Si aún no hemos pasado el deadline, no evaluar este par
        if ($nowBogota < $deadline) continue;

        // Estudiantes que asistieron al bloque actual (tienen evento entre start y end del bloque)
        $attendedStmt = $conn->prepare("
            SELECT DISTINCT be.student_id, s.first_name, s.last_name
            FROM biometric_events be
            JOIN students s ON s.student_id = be.student_id
            WHERE be.school_id = ?
              AND be.event_timestamp >= (?::date + ?::time)::timestamptz
              AND be.event_timestamp <= (?::date + ?::time)::timestamptz
              AND be.event_type LIKE 'INGRESO_%'
              AND s.active = TRUE AND s.deleted_at IS NULL
        ");
        $attendedStmt->execute([
            $schoolId,
            $todayDate, $currentBlock['start_time'],
            $todayDate, $currentBlock['end_time']
        ]);
        $attended = $attendedStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($attended as $student) {
            $studentId = $student['student_id'];
            $studentName = trim($student['first_name'] . ' ' . $student['last_name']);

            if (hasEvasionToday($conn, $schoolId, $studentId)) continue;

            // Verificar si marcó ingreso en el siguiente bloque (dentro del margen)
            $nextCheck = $conn->prepare("
                SELECT 1 FROM biometric_events
                WHERE school_id = ? AND student_id = ?
                  AND event_timestamp >= (?::date + ?::time)::timestamptz
                  AND event_timestamp <= (?::date + ?::time)::timestamptz
                  AND event_type LIKE 'INGRESO_%'
                LIMIT 1
            ");
            $nextCheck->execute([
                $schoolId, $studentId,
                $todayDate, $nextBlock['start_time'],
                $todayDate, $deadline->format('H:i:s')
            ]);
            if ($nextCheck->fetchColumn()) continue; // Sí asistió al siguiente bloque

            // Verificar permiso activo
            $permisoStmt = $conn->prepare("
                SELECT authorization_id, exit_time, return_time
                FROM class_exit_authorizations
                WHERE school_id = ? AND student_id = ? AND status = 'ACTIVE'
                  AND exit_time <= NOW()
                  AND (return_time IS NULL OR return_time >= NOW())
                LIMIT 1
            ");
            $permisoStmt->execute([$schoolId, $studentId]);
            $permiso = $permisoStmt->fetch(PDO::FETCH_ASSOC);

            if ($permiso) continue; // Tiene permiso activo, no es evasión

            // Generar alerta de evasión
            $teacher = getCurrentTeacher($conn, $schoolId, $studentId);
            $coordinators = getCoordinators($conn, $schoolId);

            $alertReason = "No asistió al bloque {$nextBlock['block_number']}" . ($nextBlock['block_name'] ? " ({$nextBlock['block_name']})" : '') . " que inició a las " . substr($nextBlock['start_time'], 0, 5);

            $meta = json_encode([
                'student_id' => $studentId,
                'student_name' => $studentName,
                'current_block' => $currentBlock['block_number'],
                'next_block' => $nextBlock['block_number'],
                'next_block_start' => $nextBlock['start_time'],
                'alert_reason' => $alertReason,
                'teacher_name' => $teacher ? trim($teacher['first_name'] . ' ' . $teacher['last_name']) : 'N/A',
                'action' => 'evasion_interna',
            ], JSON_UNESCAPED_UNICODE);

            try {
                insertEvasionIncident($conn, $schoolId, $studentId, $meta);
                $detected++;
                logE('EVASION_DETECTED', "school=$schoolId student=$studentName block={$nextBlock['block_number']} reason=$alertReason");

                // Notificar al profesor del bloque actual
                if ($teacher) {
                    $teacherMsg = "⚠️ *NEXO — Evasión Detectada*\n\nEstudiante: *{$studentName}*\n{$alertReason}\n\nSe ha avisado al coordinador de la situación.";
                    notifyUser($conn, $redis, $teacher['user_id'], $teacher['phone'] ?? '', $teacherMsg, $schoolId, 'EVASION_INTERNA', json_decode($meta, true));
                }

                // Notificar a coordinadores
                $coordMsg = "⚠️ *NEXO — Evasión Detectada*\n\nEstudiante: *{$studentName}*\n{$alertReason}";
                if ($teacher) {
                    $coordMsg .= "\nProfesor responsable: " . trim($teacher['first_name'] . ' ' . $teacher['last_name']);
                }
                foreach ($coordinators as $coord) {
                    notifyUser($conn, $redis, $coord['user_id'], $coord['phone'] ?? '', $coordMsg, $schoolId, 'EVASION_INTERNA', json_decode($meta, true));
                }
            } catch (Exception $e) {
                try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                logE('INSERT_FAIL', "student=$studentId error=" . $e->getMessage());
            }
        }
    }

    return $detected;
}

/**
 * Verifica estudiantes que no volvieron del receso.
 * 10 minutos después de recess_end_time, si no hay INGRESO y no tiene permiso.
 */
function checkRecessReturn(PDO $conn, $redis, string $schoolId, string $todayDate, string $recessStart, string $recessEnd): int {
    $detected = 0;

    // Estudiantes que estaban presentes antes del receso
    $presentBeforeStmt = $conn->prepare("
        SELECT DISTINCT be.student_id, s.first_name, s.last_name
        FROM biometric_events be
        JOIN students s ON s.student_id = be.student_id
        WHERE be.school_id = ?
          AND be.event_timestamp >= ?::date
          AND be.event_timestamp < (?::date + ?::time)::timestamptz
          AND be.event_type LIKE 'INGRESO_%'
          AND s.active = TRUE AND s.deleted_at IS NULL
    ");
    $presentBeforeStmt->execute([$schoolId, $todayDate, $todayDate, $recessStart]);
    $presentBefore = $presentBeforeStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($presentBefore as $student) {
        $studentId = $student['student_id'];
        $studentName = trim($student['first_name'] . ' ' . $student['last_name']);

        if (hasEvasionToday($conn, $schoolId, $studentId)) continue;

        // Verificar si marcó ingreso después del receso
        $returnCheck = $conn->prepare("
            SELECT 1 FROM biometric_events
            WHERE school_id = ? AND student_id = ?
              AND event_timestamp >= (?::date + ?::time)::timestamptz
              AND event_type LIKE 'INGRESO_%'
            LIMIT 1
        ");
        $returnCheck->execute([$schoolId, $studentId, $todayDate, $recessEnd]);
        if ($returnCheck->fetchColumn()) continue; // Regresó del receso

        // Verificar permiso activo
        $permisoStmt = $conn->prepare("
            SELECT 1 FROM class_exit_authorizations
            WHERE school_id = ? AND student_id = ? AND status = 'ACTIVE'
              AND exit_time <= NOW()
              AND (return_time IS NULL OR return_time >= NOW())
            LIMIT 1
        ");
        $permisoStmt->execute([$schoolId, $studentId]);
        if ($permisoStmt->fetchColumn()) continue; // Tiene permiso

        // Generar alerta
        $teacher = getCurrentTeacher($conn, $schoolId, $studentId);
        $coordinators = getCoordinators($conn, $schoolId);

        $alertReason = "No regresó del receso (fin a las " . substr($recessEnd, 0, 5) . ")";

        $meta = json_encode([
            'student_id' => $studentId,
            'student_name' => $studentName,
            'recess_end' => $recessEnd,
            'alert_reason' => $alertReason,
            'teacher_name' => $teacher ? trim($teacher['first_name'] . ' ' . $teacher['last_name']) : 'N/A',
            'action' => 'evasion_receso',
        ], JSON_UNESCAPED_UNICODE);

        try {
            insertEvasionIncident($conn, $schoolId, $studentId, $meta);
            $detected++;
            logE('RECESS_EVASION', "school=$schoolId student=$studentName");

            if ($teacher) {
                $teacherMsg = "⚠️ *NEXO — Evasión de Receso*\n\nEstudiante: *{$studentName}*\n{$alertReason}\n\nSe ha avisado al coordinador.";
                notifyUser($conn, $redis, $teacher['user_id'], $teacher['phone'] ?? '', $teacherMsg, $schoolId, 'EVASION_INTERNA', json_decode($meta, true));
            }

            $coordMsg = "⚠️ *NEXO — Evasión de Receso*\n\nEstudiante: *{$studentName}*\n{$alertReason}";
            foreach ($coordinators as $coord) {
                notifyUser($conn, $redis, $coord['user_id'], $coord['phone'] ?? '', $coordMsg, $schoolId, 'EVASION_INTERNA', json_decode($meta, true));
            }
        } catch (Exception $e) {
            try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
            logE('INSERT_FAIL', "student=$studentId error=" . $e->getMessage());
        }
    }

    return $detected;
}

// ============================================================================
// Bucle principal
// ============================================================================
logE('START', 'Evasion detector worker started');

$runMode = getenv('EVASION_DETECTOR_MODE') ?: 'cron';

if ($runMode === 'cron') {
    try {
        $redis = getRedisConnection();
        $schoolsStmt = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE");
        $schools = $schoolsStmt->fetchAll(PDO::FETCH_COLUMN);

        $total = 0;
        foreach ($schools as $schoolId) {
            $total += processSchoolEvasion($pdo, $redis, $schoolId);
        }
        logE('CRON_DONE', "schools=" . count($schools) . " evasions=$total");
        exit(0);
    } catch (Exception $e) {
        logE('FATAL', $e->getMessage());
        exit(1);
    }
}

// Modo daemon
$redis = getRedisConnection();
if (!$redis) {
    logE('FATAL', 'Redis unavailable');
    exit(1);
}

$iterations = 0;
$lastHeartbeat = 0;
$CHECK_INTERVAL_SEC = (int)(getenv('EVASION_CHECK_INTERVAL') ?: 120);

while (!$shutdown) {
    try {
        if (time() - $lastHeartbeat >= 30) {
            $lastHeartbeat = time();
            $redis->set('worker:evasion_detector:last_heartbeat', time());
        }

        try {
            $schoolsStmt = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE");
            $schools = $schoolsStmt->fetchAll(PDO::FETCH_COLUMN);

            $total = 0;
            foreach ($schools as $schoolId) {
                $total += processSchoolEvasion($pdo, $redis, $schoolId);
            }
            if ($total > 0) {
                logE('DETECTED', "evasions=$total");
            }
        } catch (Exception $e) {
            logE('ERR', $e->getMessage());
        }

        sleep($CHECK_INTERVAL_SEC);
        $iterations++;

        if ($iterations % 100 === 0) {
            $pdo = getDbConnection();
        }
    } catch (Exception $e) {
        logE('FATAL', $e->getMessage());
        exit(1);
    }
}

logE('STOP', 'Evasion detector worker stopped');
