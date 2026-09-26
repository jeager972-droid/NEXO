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
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/redis.php';
require_once __DIR__ . '/contingency_lib.php';

$shutdown = false;
pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

function logE(string $e, string $m = ''): void {
    error_log("[EVASION_DETECTOR] {$e} | {$m}");
}

/**
 * Envía notificación WhatsApp + notificación interna a un usuario.
 */
function notifyUser($conn, $redis, string $userId, string $phone, string $msg, string $schoolId, string $typeCode, array $meta): void {
    // Notificación interna con dedup por incidente
    $incidentId = $meta['incident_id'] ?? null;
    $dedupKey = $incidentId ? hash('sha256', $userId . '|' . $incidentId) : null;
    try {
        if ($dedupKey) {
            $notifStmt = $conn->prepare("
                INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at, dedup_key)
                VALUES (?, ?, ?, ?, 'ALERT', ?::jsonb, NOW(), ?)
                ON CONFLICT (dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING
            ");
            $notifStmt->execute([
                $schoolId, $userId,
                'Alerta de Evasión',
                $msg,
                json_encode($meta, JSON_UNESCAPED_UNICODE),
                $dedupKey
            ]);
        } else {
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
        }
    } catch (Exception $e) {
        logE('NOTIF_INSERT_FAIL', $e->getMessage());
    }
    // WhatsApp
    if (!empty($phone)) {
        try {
            $payload = json_encode([
                'to' => $phone,
                'body' => $msg,
                'school_id' => $schoolId,
                'sender_user_id' => $userId,
                'type_code' => $typeCode,
                'retries' => 0,
                'created_at' => time(),
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
 * Setea RLS context en su propia transacción para que el SELECT vea las
 * filas insertadas por insertEvasionIncident en iteraciones anteriores
 * (el RLS context se pierde tras COMMIT en autocommit).
 */
function hasEvasionToday(PDO $conn, string $schoolId, string $studentId): bool {
    $conn->exec("BEGIN");
    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
    $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
    $stmt = $conn->prepare("
        SELECT 1 FROM attendance_incidents
        WHERE student_id = ? AND school_id = ?
          AND (detected_at AT TIME ZONE 'America/Bogota')::date
              = (NOW() AT TIME ZONE 'America/Bogota')::date
          AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
          AND incident_type = 'EVASION_INTERNA'
        LIMIT 1
    ");
    $stmt->execute([$studentId, $schoolId]);
    $exists = (bool)$stmt->fetchColumn();
    $conn->exec("COMMIT");
    return $exists;
}

/**
 * Registra un incidente de evasión en attendance_incidents.
 * Setea RLS context en su propia transacción para que el INSERT
 * respete la policy ai_insert (school_id = get_current_school_id()).
 */
function insertEvasionIncident(PDO $conn, string $schoolId, string $studentId, string $metaJson): ?string {
    $conn->exec("BEGIN");
    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
    $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

    // Estudiante exento de biometría — sin huella no puede "evadir" por
    // falta de marcación; su presencia es por vía manual.
    $exStmt = $conn->prepare("SELECT biometric_exempt, manual_pending_until FROM students WHERE student_id = ? AND school_id = ?");
    $exStmt->execute([$studentId, $schoolId]);
    $exRow = $exStmt->fetch(PDO::FETCH_ASSOC);
    if ($exRow && (!empty($exRow['biometric_exempt'])
        || (!empty($exRow['manual_pending_until']) && strtotime($exRow['manual_pending_until']) > time()))) {
        $conn->exec("COMMIT");
        logE('EVASION_EXEMPT', "student=$studentId — exento/pendiente-manual, evasión no generada");
        return null;
    }

    // Política institucional — la escuela puede desactivar la
    // generación automática de EVASION_INTERNA (school_action_policies).
    if (!nexoPolicyEnabled($conn, $schoolId, 'EVASION_INTERNA')) {
        $conn->exec("COMMIT");
        logE('EVASION_POLICY_OFF', "student=$studentId — EVASION_INTERNA deshabilitada por política de la escuela");
        return null;
    }

    // Gate de salud de nodo: si el/los nodos del grupo del estudiante están
    // caídos, no hay datos para afirmar evasión → se marca SIN_DATOS_NODO y
    // se omite el incidente.
    if (ctGateEnabled()) {
        $offlineGid = ctStudentOfflineGroupId($conn, $schoolId, $studentId, ctOfflineSeconds());
        if ($offlineGid !== null) {
            ctMarkNoNodeData($conn, $schoolId, $offlineGid, 'grupo del estudiante', 'evasion_gate');
            $conn->exec("COMMIT");
            logE('EVASION_GATED', "student=$studentId — nodo del grupo offline, evasión no generada");
            return null;
        }
    }

    $incidentId = bin2hex(random_bytes(16));
    $incidentId = substr($incidentId, 0, 8) . '-' . substr($incidentId, 8, 4) . '-' . substr($incidentId, 12, 4) . '-' . substr($incidentId, 16, 4) . '-' . substr($incidentId, 20, 12);
    $stmt = $conn->prepare("
        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
        VALUES (?::uuid, ?, ?, 'EVASION_INTERNA', NOW(), ?::jsonb)
    ");
    $stmt->execute([$incidentId, $schoolId, $studentId, $metaJson]);
    $conn->exec("COMMIT");
    return $incidentId;
}

/**
 * Verifica si ya existe un incidente de LATE_ARRIVAL para el estudiante hoy
 * (evita duplicados por múltiples ejecuciones del worker).
 */
function hasLateArrivalToday(PDO $conn, string $schoolId, string $studentId): bool {
    $conn->exec("BEGIN");
    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
    $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
    $stmt = $conn->prepare("
        SELECT 1 FROM attendance_incidents
        WHERE student_id = ? AND school_id = ?
          AND (detected_at AT TIME ZONE 'America/Bogota')::date
              = (NOW() AT TIME ZONE 'America/Bogota')::date
          AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
          AND incident_type = 'LATE_ARRIVAL'
        LIMIT 1
    ");
    $stmt->execute([$studentId, $schoolId]);
    $exists = (bool)$stmt->fetchColumn();
    $conn->exec("COMMIT");
    return $exists;
}

/**
 * Registra un incidente de LATE_ARRIVAL en attendance_incidents.
 */
function insertLateArrivalIncident(PDO $conn, string $schoolId, string $studentId, string $metaJson): void {
    $conn->exec("BEGIN");
    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
    $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
    $stmt = $conn->prepare("
        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
        VALUES (uuid_generate_v4(), ?, ?, 'LATE_ARRIVAL', NOW(), ?::jsonb)
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

    // 1. Intentar schedules primero: ¿quién está en clase con el estudiante ahora?
    //    (preciso: usa start_time/end_time/block_number del horario real)
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
    if ($teacher) return $teacher;

    // 2. Fallback: schedules puede estar vacío tras onboarding (modela horarios
    //    reales, no acceso). Usar teacher_group_access para encontrar cualquier
    //    docente del grupo del estudiante. start_time/end_time/block_number
    //    serán NULL (el llamador debe tolerarlo).
    $fallbackStmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.phone,
               NULL::time AS start_time, NULL::time AS end_time, NULL::INTEGER AS block_number
        FROM teacher_group_access tga
        JOIN student_group_assignments sga ON sga.group_id = tga.group_id AND sga.active = TRUE
        JOIN users u ON u.user_id = tga.teacher_user_id
        WHERE sga.student_id = ? AND tga.school_id = ?
        LIMIT 1
    ");
    $fallbackStmt->execute([$studentId, $schoolId]);
    $teacher = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
    return $teacher ?: null;
}

/**
 * Obtiene los coordinadores de una escuela (para notificación).
 */
function getCoordinators(PDO $conn, string $schoolId): array {
    // Destinatarios por school_notification_routes
    // (event_kind EVASION_INTERNA); sin rutas → default COORDINATOR.
    $ids = nexoRouteUserIds($conn, $schoolId, 'EVASION_INTERNA', ['COORDINATOR']);
    if (empty($ids)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, phone FROM users WHERE user_id IN ($ph)");
    $stmt->execute($ids);
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

    // set_config para RLS (transaction-level para PgBouncer)
    $conn->exec("BEGIN");
    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
    $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

    // 1. Obtener configuración institucional (multi-jornada: puede haber varias filas)
    $configStmt = $conn->prepare("SELECT * FROM school_schedule_config WHERE school_id = ? AND onboarding_completed = TRUE ORDER BY work_shift");
    $configStmt->execute([$schoolId]);
    $configs = $configStmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener override de daily_schedule_config para hoy (extender_bloque / fusionar_bloque)
    $dscStmt = $conn->prepare("
        SELECT dsc.group_id, dsc.expected_entry_time, dsc.expected_exit_time, dsc.metadata_json
        FROM daily_schedule_config dsc
        WHERE dsc.school_id = ?
          AND dsc.config_date = ?::date
          AND dsc.expected_exit_time IS NOT NULL
    ");
    $dscStmt->execute([$schoolId, $todayDate]);
    $dscOverrides = [];
    foreach ($dscStmt->fetchAll(PDO::FETCH_ASSOC) as $dsc) {
        $dscOverrides[$dsc['group_id']] = [
            'entry_time' => $dsc['expected_entry_time'],
            'exit_time' => $dsc['expected_exit_time'],
            'merged' => json_decode($dsc['metadata_json'] ?? '{}', true)['merged'] ?? false,
        ];
    }

    $conn->exec("COMMIT");

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

        // Si hay override de exit_time para hoy (extender_bloque), usarlo
        $overrideExitTimes = array_column($dscOverrides, 'exit_time');
        if (!empty($overrideExitTimes)) {
            // Tomar el exit_time más tarde (la extensión aplica a toda la jornada)
            $maxExit = max($overrideExitTimes);
            if ($maxExit > $exitTime) {
                $exitTime = $maxExit;
            }
        }

        // Si no hay entry_time o exit_time, saltar esta jornada
        if (!$entryTime || !$exitTime) continue;

        // 2. Verificar si estamos dentro de esta jornada escolar
        $entryTs = DateTime::createFromFormat('H:i:s', $entryTime, new DateTimeZone('America/Bogota'));
        $exitTs = DateTime::createFromFormat('H:i:s', $exitTime, new DateTimeZone('America/Bogota'));
        if (!$entryTs || !$exitTs) continue;

        // Al finalizar la jornada (exit_time exacto): dejar de detectar evasión.
        // Los estudiantes se van a casa, el sistema se vacía.
        $exitTsToday = DateTime::createFromFormat('Y-m-d H:i:s', "$todayDate $exitTime", new DateTimeZone('America/Bogota'));
        if ($nowBogota >= $exitTsToday) continue;

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
            $detected += detectEvasionRotating($conn, $redis, $schoolId, $todayDate, $nowBogota, $config, $dscOverrides);
        } else {
            $detected += detectEvasionNonRotating($conn, $redis, $schoolId, $todayDate, $nowBogota, $config, $dscOverrides);
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
function detectEvasionNonRotating(PDO $conn, $redis, string $schoolId, string $todayDate, DateTime $nowBogota, array $config, array $dscOverrides = []): int {
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

        // Verificar si el estudiante está en un grupo con bloque fusionado (fusionar_bloque)
        if (!empty($dscOverrides)) {
            $groupStmt = $conn->prepare("
                SELECT sga.group_id FROM student_group_assignments sga
                WHERE sga.student_id = ? AND sga.active = TRUE LIMIT 1
            ");
            $groupStmt->execute([$studentId]);
            $groupId = $groupStmt->fetchColumn();
            if ($groupId && isset($dscOverrides[$groupId]) && $dscOverrides[$groupId]['merged']) {
                continue; // Bloque fusionado, no alertar
            }
        }
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
            $evasionIncidentId = insertEvasionIncident($conn, $schoolId, $studentId, $meta);
            if ($evasionIncidentId === null) continue; // nodo offline — gateado
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
 * FLUJO COMPLETO:
 *
 * Primer bloque:
 *   - Ventana válida: 1hr antes del inicio → +10 min después del inicio.
 *   - Marcó entre (inicio - 1hr) e (inicio exacto): a tiempo.
 *   - Marcó entre (inicio) e (inicio + 10min): LATE_ARRIVAL.
 *   - No marcó a las (inicio + 10min): EVASION_INTERNA (alerta MUY_ALTA
 *     instantánea al coordinador).
 *
 * Bloques N → N+1:
 *   - 5 min para llegar y marcar huella: a tiempo.
 *   - +5 min más (total 10 min): LATE_ARRIVAL.
 *   - Vencido el plazo (10 min): EVASION_INTERNA instantánea.
 *
 * fusionar_bloque:
 *   - Si el profesor activó fusionar_bloque para un grupo, se omite la
 *     verificación de la transición del par de bloques fusionado
 *     (típicamente 1 hora = 2 bloques). SOLO para ese grupo.
 *
 * Fin de jornada:
 *   - Al llegar exit_time, el worker deja de detectar (estudiantes se van).
 */
function detectEvasionRotating(PDO $conn, $redis, string $schoolId, string $todayDate, DateTime $nowBogota, array $config, array $dscOverrides = []): int {
    $detected = 0;

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

    if (count($blocks) < 1) return 0;

    // ── Helper: obtener group_id de un estudiante ──────────────────────
    $groupCache = [];
    $getGroupId = function(string $studentId) use ($conn, &$groupCache): ?string {
        if (isset($groupCache[$studentId])) return $groupCache[$studentId];
        $stmt = $conn->prepare("
            SELECT sga.group_id FROM student_group_assignments sga
            WHERE sga.student_id = ? AND sga.active = TRUE LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $gid = $stmt->fetchColumn() ?: null;
        $groupCache[$studentId] = $gid;
        return $gid;
    };

    // ── Detectar salidas al baño (20 min) y permisos expirados ──────────
    // Retorna lista de estudiantes actualmente en baño (para omitirlos en
    // la transición de bloques N→N+1).
    $bathroomResult = detectBathroomAndPermissions($conn, $redis, $schoolId, $todayDate, $nowBogota, $config, $blocks, $dscOverrides);
    $detected += $bathroomResult['detected'];
    $inBathroom = $bathroomResult['in_bathroom'];

    // ── Helper: verificar si un par de bloques está fusionado para un grupo ──
    $isMergedForGroup = function(?string $groupId, string $blockNStart, string $blockN1Start) use ($dscOverrides): bool {
        if (!$groupId || !isset($dscOverrides[$groupId])) return false;
        $override = $dscOverrides[$groupId];
        if (!$override['merged']) return false;
        $mergedEntry = $override['entry_time'];
        $mergedExit = $override['exit_time'];
        if (!$mergedEntry || !$mergedExit) return false;
        return ($blockNStart >= $mergedEntry && $blockN1Start <= $mergedExit);
    };

    // ── Helper: verificar permiso activo ───────────────────────────────
    $hasPermiso = function(string $studentId) use ($conn, $schoolId): bool {
        $stmt = $conn->prepare("
            SELECT 1 FROM class_exit_authorizations
            WHERE school_id = ? AND student_id = ? AND status = 'ACTIVE'
              AND exit_time <= NOW()
              AND (return_time IS NULL OR return_time >= NOW())
            LIMIT 1
        ");
        $stmt->execute([$schoolId, $studentId]);
        return (bool)$stmt->fetchColumn();
    };

    // ── Helper: salida pedagógica autorizada en curso ──────────────────
    // Un grupo completo puede estar en salida pedagógica — no es evasión.
    $onTrip = function(string $studentId) use ($conn, $schoolId): bool {
        $stmt = $conn->prepare("
            SELECT 1 FROM pedagogical_trip_authorizations
            WHERE school_id = ? AND student_id = ?
              AND departure_time <= NOW()
              AND (return_time IS NULL OR return_time >= NOW())
            LIMIT 1
        ");
        $stmt->execute([$schoolId, $studentId]);
        return (bool)$stmt->fetchColumn();
    };

    // ══════════════════════════════════════════════════════════════════
    // 1. PRIMER BLOQUE
    // ══════════════════════════════════════════════════════════════════
    $firstBlock = $blocks[0];
    $firstStart = DateTime::createFromFormat('H:i:s', $firstBlock['start_time'], new DateTimeZone('America/Bogota'));
    if ($firstStart) {
        $firstDeadline = clone $firstStart;
        $firstDeadline->add(new DateInterval('PT10M'));
        // Ventena de "a tiempo": 1hr antes del inicio
        $firstEarlyWindow = clone $firstStart;
        $firstEarlyWindow->sub(new DateInterval('PT1H'));

        // Solo verificar si ya pasó el deadline del primer bloque (+10 min)
        if ($nowBogota >= $firstDeadline) {
            // Estudiantes con cualquier evento biométrico hoy (están en la institución)
            $inInstitutionStmt = $conn->prepare("
                SELECT DISTINCT be.student_id, s.first_name, s.last_name
                FROM biometric_events be
                JOIN students s ON s.student_id = be.student_id
                WHERE be.school_id = ?
                  AND be.event_timestamp >= ?::date
                  AND be.event_timestamp < (?::date + INTERVAL '1 day')
                  AND s.active = TRUE AND s.deleted_at IS NULL
            ");
            $inInstitutionStmt->execute([$schoolId, $todayDate, $todayDate]);
            $inInstitution = $inInstitutionStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($inInstitution as $student) {
                $studentId = $student['student_id'];
                $studentName = trim($student['first_name'] . ' ' . $student['last_name']);

                if (hasEvasionToday($conn, $schoolId, $studentId)) continue;

                // ¿Marcó a tiempo? (entre 1hr antes y inicio exacto)
                $onTimeCheck = $conn->prepare("
                    SELECT 1 FROM biometric_events
                    WHERE school_id = ? AND student_id = ?
                      AND event_timestamp >= (?::date + ?::time)::timestamptz
                      AND event_timestamp < (?::date + ?::time)::timestamptz
                      AND event_type LIKE 'INGRESO_%'
                    LIMIT 1
                ");
                $onTimeCheck->execute([
                    $schoolId, $studentId,
                    $todayDate, $firstEarlyWindow->format('H:i:s'),
                    $todayDate, $firstBlock['start_time']
                ]);
                if ($onTimeCheck->fetchColumn()) continue; // A tiempo

                // ¿Marcó tarde? (entre inicio exacto y +10 min)
                $lateCheck = $conn->prepare("
                    SELECT 1 FROM biometric_events
                    WHERE school_id = ? AND student_id = ?
                      AND event_timestamp >= (?::date + ?::time)::timestamptz
                      AND event_timestamp <= (?::date + ?::time)::timestamptz
                      AND event_type LIKE 'INGRESO_%'
                    LIMIT 1
                ");
                $lateCheck->execute([
                    $schoolId, $studentId,
                    $todayDate, $firstBlock['start_time'],
                    $todayDate, $firstDeadline->format('H:i:s')
                ]);

                if ($lateCheck->fetchColumn()) {
                    // LLEGADA TARDE — registrarlo si no tiene ya una hoy
                    if (hasLateArrivalToday($conn, $schoolId, $studentId)) continue;
                    if ($hasPermiso($studentId) || $onTrip($studentId)) continue;
                    $meta = json_encode([
                        'student_id' => $studentId,
                        'student_name' => $studentName,
                        'block_number' => $firstBlock['block_number'],
                        'block_start' => $firstBlock['start_time'],
                        'alert_reason' => "Llegada tarde al bloque {$firstBlock['block_number']} (inicio " . substr($firstBlock['start_time'], 0, 5) . ")",
                        'action' => 'late_arrival',
                    ], JSON_UNESCAPED_UNICODE);
                    try {
                        insertLateArrivalIncident($conn, $schoolId, $studentId, $meta);
                        $detected++;
                        logE('LATE_ARRIVAL', "school=$schoolId student=$studentName block={$firstBlock['block_number']}");
                    } catch (Exception $e) {
                        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                        logE('LATE_INSERT_FAIL', "student=$studentId error=" . $e->getMessage());
                    }
                    continue;
                }

                // No marcó nada → EVASION_INTERNA
                if ($hasPermiso($studentId) || $onTrip($studentId)) continue;
                $detected += generateEvasionAlert(
                    $conn, $redis, $schoolId, $studentId, $studentName,
                    "No asistió al primer bloque ({$firstBlock['block_number']}) que inició a las " . substr($firstBlock['start_time'], 0, 5),
                    ['current_block' => 0, 'next_block' => $firstBlock['block_number'], 'next_block_start' => $firstBlock['start_time']]
                );
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. BLOQUES N → N+1
    // ══════════════════════════════════════════════════════════════════
    if (count($blocks) >= 2) {
        for ($i = 0; $i < count($blocks) - 1; $i++) {
            $currentBlock = $blocks[$i];
            $nextBlock = $blocks[$i + 1];

            $nextStart = DateTime::createFromFormat('H:i:s', $nextBlock['start_time'], new DateTimeZone('America/Bogota'));
            if (!$nextStart) continue;

            // Deadline: +10 min desde el inicio del siguiente bloque
            $deadline = clone $nextStart;
            $deadline->add(new DateInterval('PT10M'));
            // "A tiempo": primeros 5 min
            $onTimeEnd = clone $nextStart;
            $onTimeEnd->add(new DateInterval('PT5M'));

            // Si aún no hemos pasado el deadline, no evaluar este par
            if ($nowBogota < $deadline) continue;

            // Estudiantes que asistieron al bloque actual
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

                // Si está en baño, no flaggear por missing el siguiente bloque.
                // El detector de baño ya está corriendo su propio timer de 20 min.
                // Cuando vuelva del baño, tendrá 5 min para marcar el siguiente bloque.
                if (isset($inBathroom[$studentId])) {
                    // Verificar si ya volvió del baño (marcó en algún dispositivo
                    // después del fin del bloque actual) y le dieron 5 min
                    $returnAfterBlock = $conn->prepare("
                        SELECT 1 FROM biometric_events
                        WHERE school_id = ? AND student_id = ?
                          AND event_timestamp > (?::date + ?::time)::timestamptz
                          AND event_type LIKE 'INGRESO_%'
                        LIMIT 1
                    ");
                    $returnAfterBlock->execute([
                        $schoolId, $studentId,
                        $todayDate, $currentBlock['end_time']
                    ]);

                    if (!$returnAfterBlock->fetchColumn()) {
                        continue; // Aún no ha vuelto del baño, no flaggear
                    }

                    // Ya volvió del baño. ¿Marcó en el siguiente bloque dentro de 5 min?
                    $blockEndTs = DateTime::createFromFormat('H:i:s', $currentBlock['end_time'], new DateTimeZone('America/Bogota'));
                    $bathroomGraceEnd = clone $blockEndTs;
                    $bathroomGraceEnd->add(new DateInterval('PT5M'));

                    // Si aún no pasaron los 5 min, no evaluar
                    if ($nowBogota < $bathroomGraceEnd) continue;

                    $nextBathroomCheck = $conn->prepare("
                        SELECT 1 FROM biometric_events
                        WHERE school_id = ? AND student_id = ?
                          AND event_timestamp > (?::date + ?::time)::timestamptz
                          AND event_timestamp <= (?::date + ?::time)::timestamptz
                          AND event_type LIKE 'INGRESO_%'
                        LIMIT 1
                    ");
                    $nextBathroomCheck->execute([
                        $schoolId, $studentId,
                        $todayDate, $currentBlock['end_time'],
                        $todayDate, $bathroomGraceEnd->format('H:i:s')
                    ]);
                    if ($nextBathroomCheck->fetchColumn()) continue; // Marcó en el siguiente bloque

                    // No marcó en los 5 min después de volver del baño → EVASION_INTERNA
                    if ($hasPermiso($studentId) || $onTrip($studentId)) continue;
                    $alertReason = "Volvió del baño pero no asistió al bloque {$nextBlock['block_number']} (5 min de gracia expirados)";
                    $detected += generateEvasionAlert(
                        $conn, $redis, $schoolId, $studentId, $studentName,
                        $alertReason,
                        ['current_block' => $currentBlock['block_number'], 'next_block' => $nextBlock['block_number'], 'next_block_start' => $nextBlock['start_time']]
                    );
                    continue;
                }

                // Verificar fusionar_bloque para este grupo
                $groupId = $getGroupId($studentId);
                if ($isMergedForGroup($groupId, $currentBlock['start_time'], $nextBlock['start_time'])) {
                    continue; // Bloque fusionado para este grupo
                }

                // ¿Marcó a tiempo? (0-5 min después del inicio del siguiente bloque)
                $onTimeCheck = $conn->prepare("
                    SELECT 1 FROM biometric_events
                    WHERE school_id = ? AND student_id = ?
                      AND event_timestamp >= (?::date + ?::time)::timestamptz
                      AND event_timestamp <= (?::date + ?::time)::timestamptz
                      AND event_type LIKE 'INGRESO_%'
                    LIMIT 1
                ");
                $onTimeCheck->execute([
                    $schoolId, $studentId,
                    $todayDate, $nextBlock['start_time'],
                    $todayDate, $onTimeEnd->format('H:i:s')
                ]);
                if ($onTimeCheck->fetchColumn()) continue; // A tiempo

                // ¿Marcó tarde? (5-10 min después del inicio)
                $lateCheck = $conn->prepare("
                    SELECT 1 FROM biometric_events
                    WHERE school_id = ? AND student_id = ?
                      AND event_timestamp > (?::date + ?::time)::timestamptz
                      AND event_timestamp <= (?::date + ?::time)::timestamptz
                      AND event_type LIKE 'INGRESO_%'
                    LIMIT 1
                ");
                $lateCheck->execute([
                    $schoolId, $studentId,
                    $todayDate, $onTimeEnd->format('H:i:s'),
                    $todayDate, $deadline->format('H:i:s')
                ]);

                if ($lateCheck->fetchColumn()) {
                    // LLEGADA TARDE
                    if (hasLateArrivalToday($conn, $schoolId, $studentId)) continue;
                    if ($hasPermiso($studentId) || $onTrip($studentId)) continue;
                    $meta = json_encode([
                        'student_id' => $studentId,
                        'student_name' => $studentName,
                        'current_block' => $currentBlock['block_number'],
                        'next_block' => $nextBlock['block_number'],
                        'next_block_start' => $nextBlock['start_time'],
                        'alert_reason' => "Llegada tarde al bloque {$nextBlock['block_number']}" . ($nextBlock['block_name'] ? " ({$nextBlock['block_name']})" : '') . " (inicio " . substr($nextBlock['start_time'], 0, 5) . ")",
                        'action' => 'late_arrival',
                    ], JSON_UNESCAPED_UNICODE);
                    try {
                        insertLateArrivalIncident($conn, $schoolId, $studentId, $meta);
                        $detected++;
                        logE('LATE_ARRIVAL', "school=$schoolId student=$studentName block={$nextBlock['block_number']}");
                    } catch (Exception $e) {
                        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                        logE('LATE_INSERT_FAIL', "student=$studentId error=" . $e->getMessage());
                    }
                    continue;
                }

                // No marcó nada → EVASION_INTERNA instantánea
                if ($hasPermiso($studentId) || $onTrip($studentId)) continue;
                $alertReason = "No asistió al bloque {$nextBlock['block_number']}" . ($nextBlock['block_name'] ? " ({$nextBlock['block_name']})" : '') . " que inició a las " . substr($nextBlock['start_time'], 0, 5);
                $detected += generateEvasionAlert(
                    $conn, $redis, $schoolId, $studentId, $studentName,
                    $alertReason,
                    ['current_block' => $currentBlock['block_number'], 'next_block' => $nextBlock['block_number'], 'next_block_start' => $nextBlock['start_time']]
                );
            }
        }
    }

    return $detected;
}

/**
 * Genera una alerta de evasión: inserta el incidente, notifica al profesor
 * y a los coordinadores. Retorna 1 si se generó, 0 si falló.
 */
function generateEvasionAlert(PDO $conn, $redis, string $schoolId, string $studentId, string $studentName, string $alertReason, array $blockInfo): int {
    $teacher = getCurrentTeacher($conn, $schoolId, $studentId);
    $coordinators = getCoordinators($conn, $schoolId);

    $metaArr = [
        'student_id' => $studentId,
        'student_name' => $studentName,
        'current_block' => $blockInfo['current_block'] ?? null,
        'next_block' => $blockInfo['next_block'] ?? null,
        'next_block_start' => $blockInfo['next_block_start'] ?? null,
        'alert_reason' => $alertReason,
        'teacher_name' => $teacher ? trim($teacher['first_name'] . ' ' . $teacher['last_name']) : 'N/A',
        'action' => 'evasion_interna',
    ];

    try {
        $incidentId = insertEvasionIncident($conn, $schoolId, $studentId, json_encode($metaArr, JSON_UNESCAPED_UNICODE));
        if ($incidentId === null) {
            return 0; // nodo offline — sin incidente ni notificaciones
        }
        $metaArr['incident_id'] = $incidentId;
        $meta = json_encode($metaArr, JSON_UNESCAPED_UNICODE);
        logE('EVASION_DETECTED', "school=$schoolId student=$studentName incident=$incidentId reason=$alertReason");

        if ($teacher) {
            $teacherMsg = "⚠️ *NEXO — Evasión Detectada*\n\nEstudiante: *{$studentName}*\n{$alertReason}\n\nSe ha avisado al coordinador de la situación.";
            notifyUser($conn, $redis, $teacher['user_id'], $teacher['phone'] ?? '', $teacherMsg, $schoolId, 'EVASION_INTERNA', $metaArr);
        }

        $coordMsg = "⚠️ *NEXO — Evasión Detectada*\n\nEstudiante: *{$studentName}*\n{$alertReason}";
        if ($teacher) {
            $coordMsg .= "\nProfesor responsable: " . trim($teacher['first_name'] . ' ' . $teacher['last_name']);
        }
        foreach ($coordinators as $coord) {
            notifyUser($conn, $redis, $coord['user_id'], $coord['phone'] ?? '', $coordMsg, $schoolId, 'EVASION_INTERNA', $metaArr);
        }
        return 1;
    } catch (Exception $e) {
        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
        logE('INSERT_FAIL', "student=$studentId error=" . $e->getMessage());
        return 0;
    }
}

/**
 * Detecta salidas al baño y permisos expirados durante bloques de clase
 * para colegios que rotan de salones.
 *
 * SALIDA AL BAÑO:
 *   - El estudiante marca huella al entrar al aula (1er INGRESO en dispositivo X).
 *   - Si marca nuevamente en el MISMO dispositivo X → salida al baño (2do evento).
 *   - Tiene 20 minutos para volver a marcar en el mismo dispositivo X.
 *   - Si no vuelve en 20 min → EVASION_INTERNA.
 *   - La alternación es por dispositivo: 1er=ingreso, 2do=salida baño, 3er=regreso, etc.
 *
 * BAÑO DURANTE TRANSICIÓN DE BLOQUE:
 *   - Si el estudiante está en baño (último evento es par) cuando termina el bloque,
 *     no se le flagged por missing el siguiente bloque.
 *   - Cuando vuelve al dispositivo original (marca huella), tiene 5 min para
 *     marcar en el dispositivo del siguiente bloque.
 *
 * PERMISO EXPIRADO CON CAMBIO DE BLOQUE:
 *   - El permiso tiene return_time (plazo). El worker no le pide al estudiante
 *     marcar durante el permiso.
 *   - Al expirar el permiso, si el bloque en el que estaba ya terminó, tiene
 *     5 min para marcar asistencia en el siguiente bloque.
 *   - Si el bloque no terminó, debe regresar al aula actual sin gracia adicional.
 *
 * Retorna: ['detected' => int, 'in_bathroom' => array[studentId => true]]
 * El array in_bathroom se usa para que el check de transición N→N+1 omita
 * estudiantes que están en baño.
 */
function detectBathroomAndPermissions(PDO $conn, $redis, string $schoolId, string $todayDate, DateTime $nowBogota, array $config, array $blocks, array $dscOverrides = []): array {
    $detected = 0;
    $inBathroom = []; // studentId => true (estudiantes actualmente en baño)

    if (count($blocks) < 1) return ['detected' => 0, 'in_bathroom' => []];

    $workShift = $config['work_shift'] ?? 'mañana';

    // ── Helper: permiso activo ─────────────────────────────────────────
    $hasPermiso = function(string $studentId) use ($conn, $schoolId): ?array {
        $stmt = $conn->prepare("
            SELECT authorization_id, exit_time, return_time
            FROM class_exit_authorizations
            WHERE school_id = ? AND student_id = ? AND status = 'ACTIVE'
              AND exit_time <= NOW()
              AND (return_time IS NULL OR return_time >= NOW())
            ORDER BY exit_time DESC LIMIT 1
        ");
        $stmt->execute([$schoolId, $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };

    // ── Helper: salida pedagógica autorizada en curso ──────────────────
    // Un grupo completo puede estar en salida pedagógica — no es evasión.
    $onTrip = function(string $studentId) use ($conn, $schoolId): bool {
        $stmt = $conn->prepare("
            SELECT 1 FROM pedagogical_trip_authorizations
            WHERE school_id = ? AND student_id = ?
              AND departure_time <= NOW()
              AND (return_time IS NULL OR return_time >= NOW())
            LIMIT 1
        ");
        $stmt->execute([$schoolId, $studentId]);
        return (bool)$stmt->fetchColumn();
    };

    // ── 1. SALIDA AL BAÑO: detectar por bloque ─────────────────────────
    foreach ($blocks as $block) {
        $blockStart = $block['start_time'];
        $blockEnd = $block['end_time'];

        // Estudiantes con 2+ eventos INGRESO en el mismo dispositivo durante este bloque
        // La alternación: 1er=ingreso, 2nd=salida baño, 3rd=regreso, etc.
        $stmt = $conn->prepare("
            SELECT be.student_id, s.first_name, s.last_name,
                   be.device_id,
                   be.event_timestamp,
                   ROW_NUMBER() OVER (PARTITION BY be.student_id, be.device_id ORDER BY be.event_timestamp) as event_seq
            FROM biometric_events be
            JOIN students s ON s.student_id = be.student_id
            WHERE be.school_id = ?
              AND be.event_timestamp >= (?::date + ?::time)::timestamptz
              AND be.event_timestamp <= (?::date + ?::time)::timestamptz
              AND be.event_type LIKE 'INGRESO_%'
              AND s.active = TRUE AND s.deleted_at IS NULL
            ORDER BY be.student_id, be.device_id, be.event_timestamp
        ");
        $stmt->execute([$schoolId, $todayDate, $blockStart, $todayDate, $blockEnd]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Agrupar por student_id + device_id y determinar el último event_seq
        $studentDevices = [];
        foreach ($events as $ev) {
            $key = $ev['student_id'] . '|' . $ev['device_id'];
            if (!isset($studentDevices[$key])) {
                $studentDevices[$key] = [
                    'student_id' => $ev['student_id'],
                    'student_name' => trim($ev['first_name'] . ' ' . $ev['last_name']),
                    'device_id' => $ev['device_id'],
                    'last_seq' => (int)$ev['event_seq'],
                    'last_timestamp' => $ev['event_timestamp'],
                ];
            } else {
                $studentDevices[$key]['last_seq'] = (int)$ev['event_seq'];
                $studentDevices[$key]['last_timestamp'] = $ev['event_timestamp'];
            }
        }

        foreach ($studentDevices as $info) {
            $studentId = $info['student_id'];
            $studentName = $info['student_name'];
            $lastSeq = $info['last_seq'];

            // Si el último evento es par (2nd, 4th, etc.) → está en baño
            if ($lastSeq % 2 === 0) {
                $inBathroom[$studentId] = true;

                if (hasEvasionToday($conn, $schoolId, $studentId)) continue;

                // Calcular tiempo transcurrido desde la salida al baño
                $bathroomStart = new DateTime($info['last_timestamp'], new DateTimeZone('UTC'));
                $bathroomStart->setTimezone(new DateTimeZone('America/Bogota'));
                $elapsedMin = (int)(($nowBogota->getTimestamp() - $bathroomStart->getTimestamp()) / 60);

                // 20 min para volver
                if ($elapsedMin >= 20) {
                    // Verificar permiso activo (si tiene permiso, no es evasión)
                    if ($hasPermiso($studentId) || $onTrip($studentId)) continue;

                    $detected += generateEvasionAlert(
                        $conn, $redis, $schoolId, $studentId, $studentName,
                        "Salida al baño hace {$elapsedMin} min sin regresar (bloque {$block['block_number']}, límite 20 min)",
                        ['current_block' => $block['block_number'], 'next_block' => null, 'next_block_start' => null]
                    );
                }
            }
        }
    }

    // ── 2. PERMISO EXPIRADO + CAMBIO DE BLOQUE ──────────────────────────
    // Estudiantes con permiso cuyo return_time ya pasó. Si el bloque en el que
    // estaban ya terminó, dar 5 min de gracia para marcar el siguiente bloque.
    $expiredPermStmt = $conn->prepare("
        SELECT cea.student_id, cea.return_time, cea.exit_time,
               s.first_name, s.last_name
        FROM class_exit_authorizations cea
        JOIN students s ON s.student_id = cea.student_id
        WHERE cea.school_id = ?
          AND cea.status = 'ACTIVE'
          AND cea.return_time IS NOT NULL
          AND cea.return_time < NOW()
          AND s.active = TRUE AND s.deleted_at IS NULL
    ");
    $expiredPermStmt->execute([$schoolId]);
    $expiredPerms = $expiredPermStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiredPerms as $perm) {
        $studentId = $perm['student_id'];
        $studentName = trim($perm['first_name'] . ' ' . $perm['last_name']);

        if (hasEvasionToday($conn, $schoolId, $studentId)) continue;
        if (isset($inBathroom[$studentId])) continue; // Ya detectado como bathroom

        // Determinar en qué bloque estaba cuando salió el permiso
        $permExitTs = new DateTime($perm['exit_time'], new DateTimeZone('UTC'));
        $permExitTs->setTimezone(new DateTimeZone('America/Bogota'));
        $permExitStr = $permExitTs->format('H:i:s');

        $currentBlock = null;
        $nextBlock = null;
        foreach ($blocks as $i => $blk) {
            if ($blk['start_time'] <= $permExitStr && $blk['end_time'] >= $permExitStr) {
                $currentBlock = $blk;
                $nextBlock = $blocks[$i + 1] ?? null;
                break;
            }
        }

        if (!$currentBlock) continue;

        // ¿El bloque en el que estaba ya terminó?
        $blockEndTs = DateTime::createFromFormat('H:i:s', $currentBlock['end_time'], new DateTimeZone('America/Bogota'));
        $blockEnded = ($nowBogota > $blockEndTs);

        if ($blockEnded && $nextBlock) {
            // El bloque terminó: dar 5 min desde el fin del bloque para marcar el siguiente
            $graceDeadline = clone $blockEndTs;
            $graceDeadline->add(new DateInterval('PT5M'));

            // Si aún estamos dentro de los 5 min de gracia, no alertar
            if ($nowBogota < $graceDeadline) continue;

            // ¿Marcó en el siguiente bloque?
            $nextStart = $nextBlock['start_time'];
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
                $todayDate, $nextStart,
                $todayDate, $graceDeadline->format('H:i:s')
            ]);
            if ($nextCheck->fetchColumn()) continue; // Marcó en el siguiente bloque

            // No marcó → EVASION_INTERNA
            $detected += generateEvasionAlert(
                $conn, $redis, $schoolId, $studentId, $studentName,
                "Permiso expirado y no regresó al siguiente bloque {$nextBlock['block_number']} (fin del bloque anterior: " . substr($currentBlock['end_time'], 0, 5) . ")",
                ['current_block' => $currentBlock['block_number'], 'next_block' => $nextBlock['block_number'], 'next_block_start' => $nextBlock['start_time']]
            );
        } else {
            // El bloque no ha terminado: debe estar en el aula. Si no marcó
            // regreso en este bloque después del permiso, es evasión.
            $returnCheck = $conn->prepare("
                SELECT 1 FROM biometric_events
                WHERE school_id = ? AND student_id = ?
                  AND event_timestamp > ?::timestamptz
                  AND event_timestamp <= (?::date + ?::time)::timestamptz
                  AND event_type LIKE 'INGRESO_%'
                LIMIT 1
            ");
            $returnCheck->execute([
                $schoolId, $studentId,
                $perm['return_time'],
                $todayDate, $currentBlock['end_time']
            ]);
            if ($returnCheck->fetchColumn()) continue; // Regresó al aula

            // No regresó → EVASION_INTERNA
            $detected += generateEvasionAlert(
                $conn, $redis, $schoolId, $studentId, $studentName,
                "Permiso expirado y no regresó al bloque {$currentBlock['block_number']} (retorno esperado: " . substr($perm['return_time'], 0, 5) . ")",
                ['current_block' => $currentBlock['block_number'], 'next_block' => null, 'next_block_start' => null]
            );
        }
    }

    return ['detected' => $detected, 'in_bathroom' => $inBathroom];
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
            $recessIncidentId = insertEvasionIncident($conn, $schoolId, $studentId, $meta);
            if ($recessIncidentId === null) continue; // nodo offline — gateado
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
            $lockKey = "lock:evasion_detector:$schoolId";
            if (!$redis->set($lockKey, '1', ['nx', 'ex' => 300])) {
                logE('LOCK_SKIP', "school=$schoolId already locked by another instance");
                continue;
            }
            try {
                $total += processSchoolEvasion($pdo, $redis, $schoolId);
            } finally {
                $redis->del($lockKey);
            }
        }
        logE('CRON_DONE', "schools=" . count($schools) . " evasions=$total");
        exit(0);
    } catch (Exception $e) {
        logE('FATAL', $e->getMessage());
        exit(1);
    }
}

// Modo daemon
$redis = null;
try {
    $redis = getRedisConnection();
} catch (Exception $e) {}

$iterations = 0;
$lastHeartbeat = 0;
$CHECK_INTERVAL_SEC = (int)(getenv('EVASION_CHECK_INTERVAL') ?: 120);

while (!$shutdown) {
    try {
        try {
            $redis = getRedisConnection();
        } catch (Exception $e) {}

        if ($redis && time() - $lastHeartbeat >= 30) {
            $lastHeartbeat = time();
            try { $redis->set('worker:evasion_detector:last_heartbeat', time()); } catch (Exception $e) {}
        }

        try {
            $schoolsStmt = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE");
            $schools = $schoolsStmt->fetchAll(PDO::FETCH_COLUMN);

            $total = 0;
            foreach ($schools as $schoolId) {
                // Si Redis está disponible, usamos lock. Si no, asumimos que es seguro procesar (single instance fallback).
                $lockKey = "lock:evasion_detector:$schoolId";
                $hasLock = false;
                if ($redis) {
                    try {
                        if (!$redis->set($lockKey, '1', ['nx', 'ex' => 300])) {
                            continue;
                        }
                        $hasLock = true;
                    } catch (Exception $e) {}
                }

                try {
                    $total += processSchoolEvasion($pdo, $redis, $schoolId);
                } finally {
                    if ($hasLock && $redis) {
                        try { $redis->del($lockKey); } catch (Exception $e) {}
                    }
                }
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
            require __DIR__ . '/../core/db.php';
        }
    } catch (Exception $e) {
        logE('FATAL', $e->getMessage());
        exit(1);
    }
}

logE('STOP', 'Evasion detector worker stopped');
