<?php
/**
 * =============================================================================
 * workers/worker_absence_detector.php — Detección automática de ausentes.
 * =============================================================================
 *
 * RESPONSABILIDAD
 * ----------------
 * Recorre todas las instituciones activas y, para cada grupo con clases hoy,
 * compara los estudiantes esperados (activos, asignados al grupo, con la jornada
 * correspondiente) contra los ingresos reales registrados en biometric_events.
 *
 * Los estudiantes sin ingreso después de la hora límite de su jornada se marcan
 * automáticamente como INASISTENCIA en attendance_incidents y se envía notificación
 * WhatsApp al acudiente.
 *
 * LÓGICA DE JORNADAS
 * -------------------
 *   - mañana:   hora límite 07:10 (configurable por daily_schedule_config)
 *   - tarde:    hora límite 12:10 (configurable por daily_schedule_config)
 *   - completa: usa la jornada de la mañana (07:10)
 *
 * Si daily_schedule_config tiene expected_entry_time para el grupo y fecha,
 * se usa ese valor + 10 minutos como hora límite.
 *
 * Si daily_schedule_config.has_classes = FALSE, no se detectan ausentes.
 *
 * FLUJO
 * -----
 *   1. Para cada school activa:
 *   2.   Para cada grupo con clases hoy:
 *   3.     Obtener estudiantes activos del grupo con su work_shift
 *   4.     Obtener estudiantes que ya marcaron ingreso hoy (INGRESO_%)
 *   5.     Para cada estudiante sin ingreso y pasada la hora límite:
 *   6.       INSERT attendance_incidents (INASISTENCIA) si no existe ya
 *   7.       Enviar WhatsApp al acudiente
 *
 * EJECUCIÓN
 * ---------
 *   - Cron cada 1 minuto entre 06:30 y 08:00 (mañana) y 11:30 y 13:00 (tarde)
 *   - O supercronic con crontab
 */

declare(ticks=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../redis.php';

$shutdown = false;
pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

function logA(string $e, string $m = ''): void {
    error_log("[ABSENCE_DETECTOR] {$e} | {$m}");
}

/**
 * Envía un job de Twilio al Redis para notificación WhatsApp.
 */
function enqueueAbsenceNotification($redis, string $phone, string $studentName, string $groupName, string $schoolId, string $studentId, string $userId): void {
    if (empty($phone)) return;
    $msg = "📋 *NEXO — Inasistencia Detectada*\n\n"
         . "Estudiante: {$studentName}\n"
         . "Grupo: {$groupName}\n\n"
         . "Su hijo/a no registró ingreso en el sistema biométrico. "
         . "Si tiene alguna justificación, por favor contáctese con la institución.";
    try {
        $payload = json_encode([
            'to' => $phone,
            'message' => $msg,
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'user_id' => $userId,
            'type_code' => 'INASISTENCIA',
        ], JSON_UNESCAPED_UNICODE);
        $redis->rPush('queue:twilio', $payload);
        $redis->expire('queue:twilio', 86400);
    } catch (Exception $e) {
        logA('TWILIO_ENQUEUE_FAIL', $e->getMessage());
    }
}

/**
 * Procesa una institución: detecta ausentes por grupo.
 */
function processSchool(PDO $conn, $redis, string $schoolId): int {
    $detected = 0;
    $today = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');

    // 1. Obtener grupos con clases hoy
    // Usar daily_schedule_config si existe, si no, asumir que sí hay clases
    $groupsStmt = $conn->prepare("
        SELECT ag.group_id, ag.group_name,
               COALESCE(dsc.has_classes, TRUE) as has_classes,
               dsc.expected_entry_time
        FROM academic_groups ag
        LEFT JOIN daily_schedule_config dsc
          ON dsc.group_id = ag.group_id
          AND dsc.config_date = (NOW() AT TIME ZONE 'America/Bogota')::date
          AND dsc.school_id = ag.school_id
        WHERE ag.school_id = ?
        ORDER BY ag.group_name
    ");

    // set_config para RLS (transaction-level para PgBouncer)
    $conn->exec("BEGIN");
    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
    $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

    $groupsStmt->execute([$schoolId]);
    $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener entry_time por jornada desde school_schedule_config (multi-jornada)
    $shiftConfigStmt = $conn->prepare("SELECT work_shift, entry_time FROM school_schedule_config WHERE school_id = ? AND onboarding_completed = TRUE");
    $shiftConfigStmt->execute([$schoolId]);
    $shiftConfigs = [];
    foreach ($shiftConfigStmt->fetchAll(PDO::FETCH_ASSOC) as $sc) {
        $shiftConfigs[$sc['work_shift']] = $sc['entry_time'];
    }
    $conn->exec("COMMIT");

    foreach ($groups as $group) {
        if (!$group['has_classes']) continue;

        $groupId = $group['group_id'];
        $groupName = $group['group_name'];
        $expectedEntry = $group['expected_entry_time'];

        // 2. Obtener estudiantes activos del grupo
        $studentsStmt = $conn->prepare("
            SELECT s.student_id, s.first_name, s.last_name, s.document_number,
                   s.work_shift,
                   COALESCE(g.whatsapp_phone, u.phone) as guardian_phone,
                   g.guardian_id
            FROM students s
            INNER JOIN student_group_assignments sga
              ON s.student_id = sga.student_id AND sga.active = TRUE
            LEFT JOIN guardian_student_relationships gsr
              ON gsr.student_id = s.student_id AND gsr.primary_guardian = TRUE
            LEFT JOIN guardians g ON gsr.guardian_id = g.guardian_id
            LEFT JOIN users u ON g.user_id = u.user_id
            WHERE sga.group_id = ? AND s.active = TRUE AND s.deleted_at IS NULL
        ");
        $studentsStmt->execute([$groupId]);
        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($students)) continue;

        // 3. Obtener estudiantes que ya marcaron ingreso hoy
        $presentStmt = $conn->prepare("
            SELECT DISTINCT student_id
            FROM biometric_events
            WHERE school_id = ?
              AND event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota')::date
              AND event_timestamp < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
              AND event_type LIKE 'INGRESO_%'
              AND student_id IN (
                  SELECT sga2.student_id FROM student_group_assignments sga2
                  WHERE sga2.group_id = ? AND sga2.active = TRUE
              )
        ");
        $presentStmt->execute([$schoolId, $groupId]);
        $presentIds = $presentStmt->fetchAll(PDO::FETCH_COLUMN);

        // 4. Determinar hora límite por jornada
        $now = new DateTime('now', new DateTimeZone('America/Bogota'));
        $currentMinutes = (int)$now->format('H') * 60 + (int)$now->format('i');

        foreach ($students as $student) {
            $studentId = $student['student_id'];

            // Si ya marcó ingreso, no es ausente
            if (in_array($studentId, $presentIds)) continue;

            // 4.5. Verificar si tiene permiso activo (no marcar inasistencia si tiene permiso)
            $permisoStmt = $conn->prepare("
                SELECT 1 FROM class_exit_authorizations
                WHERE school_id = ? AND student_id = ? AND status = 'ACTIVE'
                  AND exit_time <= NOW()
                  AND (return_time IS NULL OR return_time >= NOW())
                LIMIT 1
            ");
            $permisoStmt->execute([$schoolId, $studentId]);
            if ($permisoStmt->fetchColumn()) continue; // Tiene permiso activo, no es inasistencia

            // Determinar hora límite
            $shift = $student['work_shift'] ?? 'mañana';
            if ($expectedEntry) {
                // Usar horario configurado por coordinador + 10 min de tolerancia
                $entryTime = strtotime($expectedEntry);
                $limitMinutes = (int)date('H', $entryTime) * 60 + (int)date('i', $entryTime) + 10;
            } elseif (isset($shiftConfigs[$shift])) {
                // Usar entry_time de school_schedule_config para esta jornada + 10 min
                $cfgEntry = $shiftConfigs[$shift];
                $entryTs = strtotime($cfgEntry);
                $limitMinutes = (int)date('H', $entryTs) * 60 + (int)date('i', $entryTs) + 10;
            } else {
                // Defaults hardcoded según jornada
                switch ($shift) {
                    case 'tarde':
                        $limitMinutes = 12 * 60 + 10; // 12:10
                        break;
                    case 'noche':
                        $limitMinutes = 18 * 60 + 10; // 18:10
                        break;
                    case 'completa':
                    case 'mañana':
                    default:
                        $limitMinutes = 7 * 60 + 10; // 07:10
                        break;
                }
            }

            // Si aún no pasó la hora límite, no marcar ausente
            if ($currentMinutes < $limitMinutes) continue;

            // 5. Verificar si ya existe un incidente de inasistencia hoy
            $checkStmt = $conn->prepare("
                SELECT 1 FROM attendance_incidents
                WHERE student_id = ? AND school_id = ?
                  AND detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date
                  AND detected_at < ((NOW() AT TIME ZONE 'America/Bogota')::date + INTERVAL '1 day')
                  AND incident_type = 'INASISTENCIA'
                LIMIT 1
            ");
            $checkStmt->execute([$studentId, $schoolId]);
            if ($checkStmt->fetchColumn()) continue; // Ya registrado

            // 6. INSERT attendance_incidents
            try {
                $conn->exec("BEGIN");
                $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
                $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

                $incStmt = $conn->prepare("
                    INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at)
                    VALUES (uuid_generate_v4(), ?, ?, 'INASISTENCIA', NOW())
                ");
                $incStmt->execute([$schoolId, $studentId]);
                $conn->exec("COMMIT");
                $detected++;

                $studentName = trim($student['first_name'] . ' ' . $student['last_name']);
                logA('ABSENCE_DETECTED', "school=$schoolId group=$groupName student=$studentName doc={$student['document_number']} shift=$shift");

                // 7. Notificar al acudiente
                if (!empty($student['guardian_phone'])) {
                    enqueueAbsenceNotification(
                        $redis,
                        $student['guardian_phone'],
                        $studentName,
                        $groupName,
                        $schoolId,
                        $studentId,
                        'SYSTEM'
                    );
                }
            } catch (Exception $e) {
                try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                logA('INSERT_FAIL', "student={$student['student_id']} error=" . $e->getMessage());
            }
        }
    }

    return $detected;
}

// ============================================================================
// Bucle principal
// ============================================================================
logA('START', 'Absence detector worker started');

$runMode = getenv('ABSENCE_DETECTOR_MODE') ?: 'cron'; // 'cron' = una vez y salir, 'daemon' = loop

if ($runMode === 'cron') {
    // Modo cron: ejecutar una vez y salir
    try {
        $redis = getRedisConnection();
        // Obtener todas las escuelas activas
        $schoolsStmt = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE");
        $schools = $schoolsStmt->fetchAll(PDO::FETCH_COLUMN);

        $total = 0;
        foreach ($schools as $schoolId) {
            $total += processSchool($pdo, $redis, $schoolId);
        }
        logA('CRON_DONE', "schools=" . count($schools) . " absences=$total");
        exit(0);
    } catch (Exception $e) {
        logA('FATAL', $e->getMessage());
        exit(1);
    }
}

// Modo daemon: loop continuo
$redis = getRedisConnection();
if (!$redis) {
    logA('FATAL', 'Redis unavailable');
    exit(1);
}

$iterations = 0;
$lastHeartbeat = 0;
$CHECK_INTERVAL_SEC = (int)(getenv('ABSENCE_CHECK_INTERVAL') ?: 60); // 1 min por defecto

while (!$shutdown) {
    try {
        if (time() - $lastHeartbeat >= 30) {
            $lastHeartbeat = time();
            $redis->set('worker:absence_detector:last_heartbeat', time());
        }

        try {
            $schoolsStmt = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE");
            $schools = $schoolsStmt->fetchAll(PDO::FETCH_COLUMN);

            $total = 0;
            foreach ($schools as $schoolId) {
                $total += processSchool($pdo, $redis, $schoolId);
            }
            if ($total > 0) {
                logA('DETECTED', "absences=$total");
            }
        } catch (Exception $e) {
            logA('ERR', $e->getMessage());
        }

        sleep($CHECK_INTERVAL_SEC);
        $iterations++;

        // Reconectar PDO cada 100 iteraciones
        if ($iterations % 100 === 0) {
            $pdo = getDbConnection();
        }
    } catch (Exception $e) {
        logA('FATAL', $e->getMessage());
        exit(1);
    }
}

logA('STOP', 'Absence detector worker stopped');
