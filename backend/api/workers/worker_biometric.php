<?php
/**
 * =============================================================================
 * workers/worker_biometric.php — Procesador de eventos biométricos (zero data loss).
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Consume trabajos de la cola `queue:biometric_ingest`, los mueve atómicamente a
 * `queue:biometric_processing` mediante Lua, ejecuta la acción correspondiente
 * (SYNC_ATTENDANCE, REGISTER_STUDENT, DELETE_STUDENT) y solo elimina el trabajo
 * de `processing` tras commit exitoso. Incluye garbage collector de trabajos
 * zombies y reintentos con DLQ.
 *
 * FLUJO GENERAL
 * -------------
 *   Redis queue:biometric_ingest
 *        │
 *        ▼
 *   scriptReliablePop (LMOVE + timestamp)
 *        │
 *        ▼
 *   processJob($job, $pdo)
 *        │
 *   ├── SYNC_ATTENDANCE  ──► INSERT biometric_events con fingerprint
 *   │                        + cruce con class_exit_authorizations ACTIVE
 *   │                          (metadata_json: permiso_id, exit/return_time,
 *   │                           reason, event_role RETURN|EXIT_WITH_PERMISSION)
 *   ├── REGISTER_STUDENT ──► INSERT/UPDATE students + acudiente
 *   └── DELETE_STUDENT   ──► UPDATE students SET active=FALSE
 *        │
 *        ▼
 *   OK: lRem(processing); FAIL: requeue o DLQ
 *        │
 *   scriptGc cada 60s: reinserta zombies >300s
 *
 * USO DE REDIS AQUÍ
 * -----------------
 * Redis actúa como broker de la cola de eventos biométricos:
 *   - queue:biometric_ingest      : trabajos pendientes enviados por la API EDGE.
 *   - queue:biometric_processing  : trabajos en ejecución (patrón reliable queue).
 *   - queue:biometric_dlq         : trabajos fallidos tras 3 reintentos.
 *   - worker:biometric:last_heartbeat : señal de vida del worker.
 * Cuando la cola está vacía el worker espera BIOMETRIC_EMPTY_QUEUE_SLEEP_US
 * (default 1s) para no saturar Upstash con polls innecesarios.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - db.php : conexión PDO ($pdo).
 *   - Redis : colas biometric_ingest, biometric_processing, biometric_dlq.
 *   - pcntl : manejo de señales SIGTERM.
 *
 * Es utilizado por:
 *   - api.php : encola trabajos desde /edge/ingest y /devices/poll fallback.
 */

declare(ticks=1);
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/redis.php';
require_once __DIR__ . '/../lib/attendance_reconcile.php';

// Configurar rol de sistema para workers.
// Nota: con PgBouncer transaction pooling, set_config(..., false) se pierde
// entre conexiones. El rol real se setea con SET LOCAL dentro de cada
// transacción en processJob(). Este set inicial es best-effort.
try {
    $pdo->query("SELECT set_config('app.current_role', 'SYSTEM_WORKER', false)");
} catch (PDOException $e) {
    logW('ROLE_SET_SKIP', $e->getMessage());
}

$shutdown = false;
pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

/**
 * Escribe un log del worker a error_log.
 *
 * @param string $e Evento.
 * @param string $m Mensaje.
 * @return void
 */
function logW(string $e, string $m): void {
    error_log("[BIOMETRIC_WORKER] {$e} | {$m}");
}

/**
 * Actualiza el heartbeat del worker en Redis.
 *
 * @param Redis $redis Conexión Redis.
 * @return void
 */
function sendHeartbeat($redis) {
    try {
        $redis->set('worker:biometric:last_heartbeat', time());
    } catch (Exception $e) {
        // Silenciar: heartbeat no debe detener el worker
    }
}

/**
 * Ejecuta la acción indicada en un trabajo biométrico.
 *
 * @param array $job Trabajo decodificado de Redis.
 * @param PDO $conn Conexión PDO.
 * @return bool True si se procesó correctamente; false para reencolar.
 *
 * Acciones:
 *   - SYNC_ATTENDANCE: inserta evento biométrico con fingerprint (idempotente).
 *   - REGISTER_STUDENT: upsert student + crea/actualiza acudiente.
 *   - DELETE_STUDENT: desactiva estudiante y limpia biometric_hash.
 */
function processJob(array $job, PDO $conn): bool {
    $action = $job['action'] ?? 'UNKNOWN';
    $data   = $job['data'] ?? [];
    $instId = $job['school_id'] ?? null;
    $capturedAt = (int)($data['captured_at'] ?? 0);

    // FIX (PgBouncer): set_config(..., false) no persiste entre consultas con
    // PgBouncer transaction pooling. Cada caso debe usar beginTransaction() +
    // set_config(..., true) (transaction-level) para que RLS funcione.
    if (!$instId) {
        logW('NO_SCHOOL_ID', 'Job sin school_id');
        return false;
    }

    switch ($action) {
        case 'SYNC_ATTENDANCE':
            $doc = trim($data['doc'] ?? '');
            $evt = strtoupper($data['event'] ?? '');
            $deviceId = $job['device_id'] ?? null;
            $fingerprint = hash('sha256', implode(':', [
                (string)$instId,
                $doc,
                $evt,
                (string)$capturedAt
            ]));

            // FIX (SRE-1): Idempotencia vía fingerprint + ON CONFLICT DO NOTHING.
            // Si el worker re-procesa un job (ej. tras GC de zombies), el INSERT
            // es idempotente y no crea duplicados con distinto UUID.
            // FIX (PgBouncer): Usar exec("BEGIN") + SET LOCAL en lugar de
            // PDO::beginTransaction() + prepare(set_config). PDO con EMULATE_PREPARES
            // puede no manejar correctamente el estado de transacción con PgBouncer
            // transaction pooling. SET LOCAL es equivalente a set_config(..., true)
            // pero sin prepared statements.
            try {
                $conn->exec("BEGIN");
                $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$instId) . ", true)");
                $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

                // ──────────────────────────────────────────────────────────────
                // Cruce con permisos activos (class_exit_authorizations).
                // Si el estudiante tiene un permiso ACTIVE cuya exit_time ya pasó,
                // se anota en metadata_json del evento biométrico para que el
                // worker_permission_status pueda marcar COMPLETED (retorno) o
                // registrar que la salida tiene permiso asociado.
                //   - INGRESO_* después de exit_time → retorno de permiso (RETURN)
                //   - SALIDA_*  después de exit_time → salida con permiso (EXIT_WITH_PERMISSION)
                // No se altera la lógica de inserción del evento; solo se enriquece
                // el metadata_json.
                // ──────────────────────────────────────────────────────────────
                $permisoMetadata = null;
                $studentId = null;

                $sidStmt = $conn->prepare(
                    "SELECT student_id FROM students WHERE document_number = ? AND school_id = ? LIMIT 1"
                );
                $sidStmt->execute([$doc, $instId]);
                $studentId = $sidStmt->fetchColumn();

                // ──────────────────────────────────────────────────────────────
                // Deduplicación temporal: si existe un evento reciente (dentro de
                // BIOMETRIC_DEDUP_WINDOW_SECONDS, default 30) del mismo estudiante
                // y del MISMO event_type, se descarta para evitar que múltiples
                // huellas en corto tiempo (doble toque, error) generen falsos
                // INGRESO+SALIDA que el worker de evasión interpretaría como
                // salida real.
                //   - Si el último evento es del MISMO tipo → descartar (duplicado).
                //   - Si el tipo es DIFERENTE (ej: último INGRESO, nuevo SALIDA) →
                //     es un cambio legítimo de estado, NO se deduplica.
                //   - Si no hay tipo definido (vacío) → deduplicar (conservador).
                // Usa el índice idx_biometric_events_school_student_time
                // (school_id, student_id, event_timestamp DESC).
                // ──────────────────────────────────────────────────────────────
                if ($studentId) {
                    $dedupWindow = getenv('BIOMETRIC_DEDUP_WINDOW_SECONDS');
                    if ($dedupWindow === false || $dedupWindow === '') {
                        $dedupWindow = 30;
                    }
                    $dedupWindow = max(1, (int)$dedupWindow);

                    $dedupStmt = $conn->prepare(
                        "SELECT event_type FROM biometric_events
                         WHERE school_id = ? AND student_id = ?
                           AND event_timestamp >= NOW() - (? || ' seconds')::interval
                         ORDER BY event_timestamp DESC
                         LIMIT 1"
                    );
                    $dedupStmt->execute([$instId, $studentId, $dedupWindow]);
                    $lastEvt = $dedupStmt->fetchColumn();

                    if ($lastEvt !== false
                        && ($lastEvt === $evt || $evt === '' || $lastEvt === '')) {
                        error_log("[BIOMETRIC_DEDUP] Discarded duplicate event for student {$studentId} within {$dedupWindow}s window");
                        $conn->exec("COMMIT");
                        return true;
                    }
                }

                if ($studentId) {
                    $permStmt = $conn->prepare(
                        "SELECT authorization_id, exit_time, return_time, authorization_reason
                         FROM class_exit_authorizations
                         WHERE school_id = ? AND student_id = ? AND status = 'ACTIVE'
                           AND exit_time <= NOW()
                           AND (return_time IS NULL OR return_time >= NOW())
                         ORDER BY exit_time DESC LIMIT 1"
                    );
                    $permStmt->execute([$instId, $studentId]);
                    $perm = $permStmt->fetch(PDO::FETCH_ASSOC);

                    if ($perm) {
                        $isIngreso = (strpos($evt, 'INGRESO_') === 0);
                        $permisoEventRole = $isIngreso ? 'RETURN' : 'EXIT_WITH_PERMISSION';

                        $permisoMetadata = [
                            'permiso_id'          => $perm['authorization_id'],
                            'permiso_exit_time'   => $perm['exit_time'],
                            'permiso_return_time' => $perm['return_time'],
                            'permiso_reason'      => $perm['authorization_reason'],
                            'permiso_event_role'  => $permisoEventRole,
                        ];

                        error_log("[BIOMETRIC] Event for student {$studentId} crossed with active permission {$perm['authorization_id']}");
                    }
                }

                $metadataJson = $permisoMetadata
                    ? json_encode($permisoMetadata, JSON_UNESCAPED_UNICODE)
                    : null;

                // ── F-01b: resolución espacial — dispositivo→grupo→schedule del bloque actual ──
                // edge_devices.group_id → schedules(group_id, day_of_week, ventana horaria)
                // → classroom_id + schedule_id del evento. Sin grupo o sin schedule → NULLs
                // (comportamiento degradado, no bloqueante).
                $classroomId = null; $scheduleId = null;
                try {
                    $spatialStmt = $conn->prepare("
                        SELECT sch.schedule_id, sch.classroom_id AS scheduled_classroom,
                               ed.classroom_id AS device_classroom
                        FROM edge_devices ed
                        LEFT JOIN schedules sch
                          ON sch.group_id = ed.group_id
                         AND sch.day_of_week = EXTRACT(ISODOW FROM (to_timestamp(?) AT TIME ZONE 'America/Bogota'))::int
                         AND (to_timestamp(?) AT TIME ZONE 'America/Bogota')::time BETWEEN sch.start_time AND sch.end_time
                        WHERE ed.device_id = ?::uuid
                        LIMIT 1
                    ");
                    $spatialStmt->execute([$capturedAt, $capturedAt, $deviceId]);
                    $spatial = $spatialStmt->fetch(PDO::FETCH_ASSOC);
                    if ($spatial) {
                        $classroomId = $spatial['scheduled_classroom'] ?? null;
                        $scheduleId = $spatial['schedule_id'] ?? null;
                        // F-01c: enforcement — aula del dispositivo ≠ aula programada → wrong_classroom
                        if (!empty($spatial['device_classroom']) && !empty($spatial['scheduled_classroom'])
                            && $spatial['device_classroom'] !== $spatial['scheduled_classroom']) {
                            $flagStmt = $conn->prepare("SELECT spatial_enforcement FROM schools WHERE school_id = ?");
                            $flagStmt->execute([$instId]);
                            if ($flagStmt->fetchColumn()) {
                                $meta = $permisoMetadata ?? [];
                                $meta['wrong_classroom'] = true;
                                $meta['device_classroom'] = $spatial['device_classroom'];
                                $meta['scheduled_classroom'] = $spatial['scheduled_classroom'];
                                $metadataJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
                                error_log("[SPATIAL] wrong_classroom: device={$deviceId} student={$studentId}");
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Resolución espacial nunca debe tumbar el ingest del evento
                    error_log("[SPATIAL] resolución falló (no bloqueante): " . $e->getMessage());
                }

                $stmt = $conn->prepare(
                    "INSERT INTO biometric_events(event_id,school_id,student_id,device_id,classroom_id,schedule_id,event_type,event_result,event_timestamp,event_fingerprint,metadata_json)
                     SELECT uuid_generate_v4(),school_id,student_id,
                            ?,?::uuid,?::uuid,
                            ?,'PROCESSED',to_timestamp(?),?,?::jsonb
                     FROM students WHERE document_number = ? AND school_id = ? LIMIT 1
                     ON CONFLICT (event_fingerprint, event_timestamp) WHERE event_fingerprint IS NOT NULL DO NOTHING"
                );
                $stmt->execute([$deviceId, $classroomId, $scheduleId, $evt, $capturedAt, $fingerprint, $metadataJson, $doc, $instId]);
                $inserted = $stmt->rowCount() > 0;

                // Si el evento es un INGRESO y el estudiante tenía una EVASION_INTERNA
                // activa hoy, marcar returned_to_class=true en el incidente. La evasión
                // persiste en la métrica hasta que el estudiante marque huella en su aula.
                if ($inserted && strpos($evt, 'INGRESO_') === 0 && $studentId) {
                    try {
                        $evasionClearStmt = $conn->prepare("
                            UPDATE attendance_incidents
                            SET metadata_json = COALESCE(metadata_json, '{}'::jsonb) || '{\"returned_to_class\": true}'::jsonb
                            WHERE school_id = ? AND student_id = ?
                              AND incident_type = 'EVASION_INTERNA'
                              AND (detected_at AT TIME ZONE 'America/Bogota')::date
                                  = (NOW() AT TIME ZONE 'America/Bogota')::date
                              AND (metadata_json->>'returned_to_class' IS DISTINCT FROM 'true')
                        ");
                        $evasionClearStmt->execute([$instId, $studentId]);
                        $cleared = $evasionClearStmt->rowCount();
                        if ($cleared > 0) {
                            error_log("[BIOMETRIC] Evasion cleared for student {$studentId} — returned to class");
                        }
                    } catch (Exception $evasionErr) {
                        error_log("[BIOMETRIC] Evasion clear failed: " . $evasionErr->getMessage());
                    }

                    // V-530/V-531/V-574: un INGRESO tardío reconcilia la INASISTENCIA
                    // abierta del día — la ausencia deja de tratarse como hecho y se
                    // genera alerta de reaparición con el espacio donde apareció.
                    try {
                        nexoReconcileAbsence($conn, (string)$instId, $studentId, $evt, $classroomId, $scheduleId);
                    } catch (Exception $recErr) {
                        error_log("[BIOMETRIC] Absence reconciliation failed: " . $recErr->getMessage());
                    }

                    // Un INGRESO_* posterior a una salida con retorno esperado cierra
                    // la autorización: registra actual_return_time real del retorno.
                    try {
                        $returnStmt = $conn->prepare("
                            UPDATE school_exit_authorizations
                            SET status = 'COMPLETED', actual_return_time = NOW()
                            WHERE school_id = ? AND student_id = ?
                              AND status = 'APPROVED'
                              AND expected_return_time IS NOT NULL
                              AND actual_return_time IS NULL
                              AND (exit_time AT TIME ZONE 'America/Bogota')::date
                                  = (NOW() AT TIME ZONE 'America/Bogota')::date
                        ");
                        $returnStmt->execute([$instId, $studentId]);
                        if ($returnStmt->rowCount() > 0) {
                            error_log("[BIOMETRIC] School exit return recorded for student {$studentId}");
                        }
                    } catch (Exception $retErr) {
                        error_log("[BIOMETRIC] School exit return update failed: " . $retErr->getMessage());
                    }
                }

                // Si el evento es SALIDA_AUTORIZADA, completar la autorización
                // pendiente en school_exit_authorizations. El estudiante validó
                // su huella en el sensor de coordinación y sale de la institución.
                // actual_return_time NO se escribe aquí: corresponde al retorno real
                // (un INGRESO_* posterior). Si no hay retorno esperado → COMPLETED.
                if ($inserted && strpos($evt, 'SALIDA_AUTORIZADA') !== false && $studentId) {
                    try {
                        $completeStmt = $conn->prepare("
                            UPDATE school_exit_authorizations
                            SET status = CASE WHEN expected_return_time IS NOT NULL THEN 'APPROVED' ELSE 'COMPLETED' END
                            WHERE school_id = ? AND student_id = ?
                              AND status = 'PENDING_FINGERPRINT'
                              AND (exit_time AT TIME ZONE 'America/Bogota')::date
                                  = (NOW() AT TIME ZONE 'America/Bogota')::date
                        ");
                        $completeStmt->execute([$instId, $studentId]);
                        $completed = $completeStmt->rowCount();
                        if ($completed > 0) {
                            error_log("[BIOMETRIC] School exit authorization COMPLETED for student {$studentId}");
                        }
                    } catch (Exception $exitErr) {
                        error_log("[BIOMETRIC] School exit completion failed: " . $exitErr->getMessage());
                    }
                }

                // Si el evento NO es INGRESO_PUNTUAL, evaluar si es llegada tarde.
                // Reglas:
                //   INGRESO_MANANA (7:01-11:00) → LATE_ARRIVAL (siempre, 7:01+ es tarde para mañana)
                //   INGRESO_MADRUGADA (<6:40) → LATE_ARRIVAL (siempre, muy temprano)
                //   INGRESO_TARDE (11:30-16:00) → LATE_ARRIVAL solo si el estudiante es
                //     de jornada mañana/completa (llegó en la tarde cuando debía en la mañana)
                //   INGRESO_PUNTUAL → no es tarde
                //   INGRESO_EXTRAORDINARIO → no se clasifica como tarde
                if ($inserted && $evt !== 'INGRESO_PUNTUAL' && $evt !== 'INGRESO_EXTRAORDINARIO') {
                    $isLate = false;
                    if (strpos($evt, 'INGRESO_MANANA') === 0 || strpos($evt, 'INGRESO_MADRUGADA') === 0) {
                        $isLate = true;
                    } elseif (strpos($evt, 'INGRESO_TARDE') === 0) {
                        // Consultar work_shift del estudiante
                        $shiftStmt = $conn->prepare(
                            "SELECT work_shift FROM students WHERE document_number = ? AND school_id = ? LIMIT 1"
                        );
                        $shiftStmt->execute([$doc, $instId]);
                        $workShift = $shiftStmt->fetchColumn();
                        // Si es de jornada mañana o completa, llegar en la tarde es tarde
                        if ($workShift === 'mañana' || $workShift === 'completa') {
                            $isLate = true;
                        }
                    }

                    if ($isLate) {
                        // Verificar contra el horario configurado del grupo del estudiante.
                        // daily_schedule_config.expected_entry_time (sobrescrito por coordinador
                        // para un día específico) tiene prioridad; si no existe, se usa
                        // school_schedule_config.entry_time de la jornada del estudiante.
                        // Si el evento está dentro de expected_entry_time + 10 min de tolerancia,
                        // no se considera llegada tarde.
                        $scheduleStmt = $conn->prepare("
                            SELECT dsc.expected_entry_time, ssc.entry_time
                            FROM students s
                            LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                            LEFT JOIN daily_schedule_config dsc ON dsc.group_id = sga.group_id
                                AND dsc.config_date = (NOW() AT TIME ZONE 'America/Bogota')::date
                                AND dsc.school_id = ?
                            LEFT JOIN school_schedule_config ssc ON ssc.school_id = s.school_id
                                AND ssc.work_shift = s.work_shift
                                AND ssc.entry_time IS NOT NULL
                            WHERE s.document_number = ? AND s.school_id = ?
                            LIMIT 1
                        ");
                        $scheduleStmt->execute([$instId, $doc, $instId]);
                        $scheduleRow = $scheduleStmt->fetch(PDO::FETCH_ASSOC);

                        $expectedEntry = $scheduleRow['expected_entry_time'] ?? $scheduleRow['entry_time'] ?? null;
                        if ($expectedEntry) {
                            // Comparar hora del evento contra expected_entry_time + 10 min de tolerancia
                            $eventTime = date('H:i:s', $capturedAt);
                            $tolerance = date('H:i:s', strtotime($expectedEntry . ' +10 minutes'));
                            if ($eventTime <= $tolerance) {
                                $isLate = false; // Dentro de la tolerancia, no es tarde
                            }
                        }
                    }

                    if ($isLate) {
                        $lateStmt = $conn->prepare(
                            "INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at)
                             SELECT uuid_generate_v4(), school_id, student_id, 'LATE_ARRIVAL', to_timestamp(?)
                             FROM students WHERE document_number = ? AND school_id = ?
                             ON CONFLICT (student_id, school_id, (detected_at)::date) WHERE incident_type = 'LATE_ARRIVAL' DO NOTHING
                             RETURNING incident_id"
                        );
                        $lateStmt->execute([$capturedAt, $doc, $instId]);
                        $incidentId = $lateStmt->fetchColumn();

                        // ───────────────────────────────────────────────────────
                        // Notificar al docente del grupo del estudiante sobre la
                        // llegada tarde, con botones Justificar / No Justificar.
                        // ───────────────────────────────────────────────────────
                        $studentNameStmt = $conn->prepare("SELECT first_name, last_name, student_id FROM students WHERE document_number = ? AND school_id = ? LIMIT 1");
                        $studentNameStmt->execute([$doc, $instId]);
                        $studentRow = $studentNameStmt->fetch(PDO::FETCH_ASSOC);
                        $studentFullName = trim(($studentRow['first_name'] ?? '') . ' ' . ($studentRow['last_name'] ?? ''));
                        $studentIdForNotif = $studentRow['student_id'] ?? null;

                        if ($studentIdForNotif && $incidentId) {
                            // FIX: usar teacher_group_access en lugar de schedules.
                            // schedules puede estar vacío tras onboarding (modela horarios
                            // reales, no acceso). Notificamos a todos los docentes del
                            // grupo del estudiante, no solo al que está en clase ahora.
                            $teacherStmt = $conn->prepare(
                                "SELECT tga.teacher_user_id FROM teacher_group_access tga
                                 JOIN student_group_assignments sga ON sga.group_id = tga.group_id AND sga.active = TRUE
                                 JOIN academic_groups ag ON ag.group_id = sga.group_id
                                 WHERE sga.student_id = ?
                                   AND tga.school_id = ?"
                            );
                            $teacherStmt->execute([$studentIdForNotif, $instId]);
                            $teacherIds = $teacherStmt->fetchAll(PDO::FETCH_COLUMN);

                            if (!empty($teacherIds)) {
                                $notifMeta = json_encode([
                                    'action' => 'late_arrival',
                                    'student_id' => $studentIdForNotif,
                                    'student_name' => $studentFullName,
                                    'incident_id' => $incidentId,
                                    'actions' => [
                                        ['id' => 'justify', 'label' => 'Justificar', 'style' => 'success'],
                                        ['id' => 'no_justify', 'label' => 'No justificar', 'style' => 'danger'],
                                    ],
                                ], JSON_UNESCAPED_UNICODE);

                                $notifStmt = $conn->prepare(
                                    "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, dedup_key)
                                     VALUES (?, ?, 'Llegada tarde detectada', ?, 'ALERT', ?::jsonb, ?)
                                     ON CONFLICT (dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING"
                                );
                                foreach (array_unique($teacherIds) as $teacherUserId) {
                                    try {
                                        $dedupKey = $incidentId ? hash('sha256', $teacherUserId . '|' . $incidentId) : null;
                                        $notifStmt->execute([
                                            $instId,
                                            $teacherUserId,
                                            "El estudiante {$studentFullName} llegó tarde a clase",
                                            $notifMeta,
                                            $dedupKey,
                                        ]);
                                    } catch (Exception $ne) {
                                        logW('NOTIF_LATE_ARRIVAL_INSERT_FAIL', $ne->getMessage());
                                    }
                                }
                            }
                        }
                    }
                }

                $conn->exec("COMMIT");
                if (!$inserted) {
                    // Distinguir entre estudiante no encontrado y evento duplicado
                    $checkStmt = $conn->prepare("SELECT 1 FROM students WHERE document_number = ? AND school_id = ? AND active = TRUE LIMIT 1");
                    $checkStmt->execute([$doc, $instId]);
                    $studentExists = $checkStmt->fetchColumn();

                    if (!$studentExists) {
                        logW('UNKNOWN_STUDENT', "doc=$doc evt=$evt school=$instId device=$deviceId — estudiante no existe en BD");
                        // Incidente de seguridad para trazabilidad — dedup por
                        // documento (1/hora): un lector con doc inválido repetido
                        // no debe inundar security_incidents.
                        try {
                            $dupStmt = $conn->prepare(
                                "SELECT 1 FROM security_incidents
                                 WHERE school_id = ? AND incident_type = 'UNKNOWN_STUDENT'
                                   AND metadata_json->>'document' = ?
                                   AND detected_at > NOW() - INTERVAL '1 hour'
                                 LIMIT 1"
                            );
                            $dupStmt->execute([$instId, $doc]);
                            if (!$dupStmt->fetchColumn()) {
                                $secStmt = $conn->prepare(
                                    "INSERT INTO security_incidents (incident_id, school_id, incident_type, severity_level, description, detected_at, metadata_json)
                                     VALUES (uuid_generate_v4(), ?, 'UNKNOWN_STUDENT', 'WARNING', ?, NOW(), ?::jsonb)"
                                );
                                $secStmt->execute([
                                    $instId,
                                    "Evento biométrico recibido para documento no registrado: $doc",
                                    json_encode(['document' => $doc, 'event_type' => $evt, 'device_id' => $deviceId, 'captured_at' => $capturedAt])
                                ]);
                            }
                        } catch (Exception $se) {
                            logW('UNKNOWN_STUDENT_INCIDENT_FAIL', $se->getMessage());
                        }
                    } else {
                        logW('SYNC_NOOP', "doc=$doc evt=$evt — evento duplicado (fingerprint ya existe)");
                    }
                }
                return $inserted;
            } catch (Exception $e) {
                try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                logW('SYNC_FAIL', $e->getMessage());
                return false;
            }

        case 'REGISTER_STUDENT':
            $schoolId = $instId; $doc = trim($data['doc'] ?? '');
            $nombre = trim($data['nombre'] ?? '');
            $parentTel = trim($data['parent_tel'] ?? '');
            $parentDoc = trim($data['parent_doc'] ?? '');
            $parentName = trim($data['parent_name'] ?? '');
            $huellaId = isset($data['huella_id']) ? (int)$data['huella_id'] : null;
            $hasFingerprint = !empty($data['has_fingerprint']);
            if (empty($schoolId) || empty($doc) || empty($nombre)) return false;

            $conn->exec("BEGIN");
            try {
                // FIX (PgBouncer): SET LOCAL en lugar de set_config con prepare
                $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$schoolId) . ", true)");
                $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

                // Si el edge reporta has_fingerprint, setear biometric_hash
                if ($hasFingerprint) {
                    $biometricHash = $huellaId !== null ? 'fp_' . $huellaId : 'fp_local';
                    $stmt = $conn->prepare("INSERT INTO students(school_id,document_number,first_name,last_name,active,biometric_hash) VALUES(?,?,?,'',TRUE,?) ON CONFLICT(school_id, document_number) DO UPDATE SET first_name=EXCLUDED.first_name,active=TRUE,biometric_hash=EXCLUDED.biometric_hash RETURNING student_id");
                    $stmt->execute([$schoolId, $doc, $nombre, $biometricHash]);
                } else {
                    $stmt = $conn->prepare("INSERT INTO students(school_id,document_number,first_name,last_name,active) VALUES(?,?,?,'',TRUE) ON CONFLICT(school_id, document_number) DO UPDATE SET first_name=EXCLUDED.first_name,active=TRUE RETURNING student_id");
                    $stmt->execute([$schoolId, $doc, $nombre]);
                }
                // FIX: se eliminó un execute() duplicado que re-ejecutaba el
                // statement con solo 3 params — en la rama has_fingerprint el
                // statement espera 4 → PDOException → rollback del enrolamiento.
                $studentId = $stmt->fetchColumn();

                // F-03: registrar el slot de dedo en student_fingerprints
                if ($hasFingerprint && $studentId) {
                    $fingerSlot = isset($data['finger_slot']) ? (int)$data['finger_slot'] : 1;
                    if (!in_array($fingerSlot, [1, 2], true)) $fingerSlot = 1;
                    $devId = $deviceId ?? null;
                    if ($devId && !preg_match('/^[0-9a-fA-F-]{36}$/', (string)$devId)) $devId = null;
                    $fpStmt = $conn->prepare("
                        INSERT INTO student_fingerprints (student_id, school_id, finger_slot, edge_huella_id, device_id)
                        VALUES (?, ?, ?, ?, ?::uuid)
                        ON CONFLICT (student_id, finger_slot)
                        DO UPDATE SET edge_huella_id = EXCLUDED.edge_huella_id, device_id = EXCLUDED.device_id
                    ");
                    $fpStmt->execute([$studentId, $schoolId, $fingerSlot, $huellaId, $devId ?: null]);
                }

                if (!empty($parentDoc) && !empty($parentName)) {
                    // Search for guardian by document_number in users table
                    $stmt = $conn->prepare("
                        SELECT g.guardian_id, u.user_id FROM guardians g
                        JOIN users u ON u.user_id = g.user_id
                        WHERE u.document_number = ?
                    ");
                    $stmt->execute([$parentDoc]);
                    $guardianRow = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($guardianRow) {
                        $guardianId = $guardianRow['guardian_id'];
                        $userId = $guardianRow['user_id'];
                        // Update user name and phone
                        $nameParts = explode(' ', $parentName, 2);
                        $firstName = $nameParts[0];
                        $lastName = $nameParts[1] ?? $firstName; // Fallback al primer nombre para evitar colapso NOT NULL
                        $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, phone=COALESCE(?,phone) WHERE user_id=?");
                        $stmt->execute([$firstName, $lastName, $parentTel, $userId]);
                        // Update guardian whatsapp_phone
                        $stmt = $conn->prepare("UPDATE guardians SET whatsapp_phone=COALESCE(?,whatsapp_phone) WHERE guardian_id=?");
                        $stmt->execute([$parentTel, $guardianId]);

                    } else {
                        // Obtener el role_id de GUARDIAN
                        $roleStmt = $conn->prepare("SELECT role_id FROM roles WHERE role_name = 'GUARDIAN' LIMIT 1");
                        $roleStmt->execute();
                        $guardianRoleId = $roleStmt->fetchColumn();

                        if (!$guardianRoleId) {
                            throw new Exception('Rol GUARDIAN no encontrado en la DB');
                        }

                        // Insert user first
                        $nameParts = explode(' ', $parentName, 2);
                        $firstName = $nameParts[0];
                        $lastName = $nameParts[1] ?? $firstName;
                        $lockedHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                        $stmt = $conn->prepare("INSERT INTO users(school_id,role_id,document_number,first_name,last_name,phone,password_hash,password_salt,active) VALUES(?,?,?,?,?,?,?,?,TRUE) RETURNING user_id");
                        $stmt->execute([$schoolId, $guardianRoleId, $parentDoc, $firstName, $lastName, $parentTel, $lockedHash, '']);
                        $userId = $stmt->fetchColumn();
                        // Then insert guardian linking to user
                        $stmt = $conn->prepare("INSERT INTO guardians(user_id,whatsapp_phone) VALUES(?,?) RETURNING guardian_id");
                        $stmt->execute([$userId, $parentTel]);
                        $guardianId = $stmt->fetchColumn();
                    }
                    $stmt = $conn->prepare("SELECT 1 FROM guardian_student_relationships WHERE student_id=? AND guardian_id=?");
                    $stmt->execute([$studentId, $guardianId]);
                    if (!$stmt->fetchColumn()) {
                        $stmt = $conn->prepare("INSERT INTO guardian_student_relationships(student_id,guardian_id,primary_guardian,relationship_type) VALUES(?,?,TRUE,'GUARDIAN')");
                        $stmt->execute([$studentId, $guardianId]);
                    }

                }
                $conn->exec("COMMIT"); return true;
            } catch (Exception $e) {
                try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                throw $e;
            }

        case 'DELETE_STUDENT':
            $doc = trim($data['doc'] ?? ''); $schoolId = $instId;
            if (empty($doc)) return false;
            try {
                $conn->exec("BEGIN");
                $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$schoolId) . ", true)");
                $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
                $stmt = $conn->prepare("UPDATE students SET active=FALSE,biometric_hash=NULL WHERE document_number=? AND school_id=? RETURNING student_id");
                $stmt->execute([$doc, $schoolId]);
                $conn->exec("COMMIT");
                return true;
            } catch (Exception $e) {
                try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
                logW('DELETE_FAIL', $e->getMessage());
                return false;
            }

        default:
            logW('UNKNOWN', $action); return false;
    }
}

// ============================================================
// Reliable Queue: LMOVE atomically moves ingest -> processing
// ============================================================

// FIX (SRE-2): Script Lua atómico que hace LMOVE + inyecta timestamp.
// Esto garantiza que, si el worker muere, el GC pueda medir cuánto tiempo
// lleva el item en processing y reinsertarlo.
$scriptReliablePop = <<<'LUA'
local ingest = KEYS[1]
local processing = KEYS[2]
local now = tonumber(ARGV[1])
local item = redis.call('LMOVE', ingest, processing, 'RIGHT', 'LEFT')
if item then
    local ok, job = pcall(cjson.decode, item)
    if ok and job then
        job.processing_since = now
        local newItem = cjson.encode(job)
        redis.call('LREM', processing, 0, item)
        redis.call('LPUSH', processing, newItem)
        return newItem
    end
    return item
end
return nil
LUA;

// FIX (SRE-2): Garbage Collector — reinserta en ingest los jobs zombies
// (más de 5 minutos en processing sin commit exitoso).
$scriptGc = <<<'LUA'
local processing = KEYS[1]
local ingest = KEYS[2]
local maxAge = tonumber(ARGV[1])
local now = tonumber(ARGV[2])
local items = redis.call('LRANGE', processing, 0, -1)
local recovered = 0
for i = 1, #items do
    local item = items[i]
    local ok, job = pcall(cjson.decode, item)
    if ok and job and job.processing_since then
        if (now - job.processing_since) > maxAge then
            redis.call('LREM', processing, 0, item)
            redis.call('LPUSH', ingest, item)
            recovered = recovered + 1
        end
    else
        redis.call('LREM', processing, 0, item)
        redis.call('LPUSH', ingest, item)
        recovered = recovered + 1
    end
end
return recovered
LUA;

$GC_MAX_AGE_SEC = (int)(getenv('BIOMETRIC_GC_MAX_AGE') ?: 300);
$EMPTY_QUEUE_SLEEP_US = (int)(getenv('BIOMETRIC_EMPTY_QUEUE_SLEEP_US') ?: 1_000_000); // 1s por defecto (evita 20 polls/s en Upstash)

logW('START', 'Biometric async worker started');
$redis = null;
try {
    $redis = getRedisConnection(true);
} catch (Exception $e) {}

$iterations = 0;
$lastGc = 0;
$lastHeartbeat = 0;

while (!$shutdown) {
    try {
        if (!$redis) {
            sleep(15);
            try { $redis = getRedisConnection(true); } catch (Exception $e) {}
            if (!$redis) continue;
        }

        // FIX: Enviar heartbeat cada 30 segundos
        if (time() - $lastHeartbeat >= 30) {
            $lastHeartbeat = time();
            try { sendHeartbeat($redis); } catch (Exception $e) {}
        }

        // FIX (SRE-2): Atomic Lua pop + timestamp injection.
        $item = false;
        try {
            $item = $redis->eval($scriptReliablePop, ['queue:biometric_ingest', 'queue:biometric_processing', time()], 2);
        } catch (Exception $e) {
            logW('REDIS_ERR', $e->getMessage());
            $redis = null; // Force reconnect on next iteration
            @touch('/tmp/redis_circuit_open'); // Trip circuit breaker manually
            continue;
        }
        if (!$item) { usleep($EMPTY_QUEUE_SLEEP_US); continue; }

        $job = json_decode($item, true);
        if (!$job) {
            try { $redis->lRem('queue:biometric_processing', $item, 0); } catch (Exception $e) {}
            continue;
        }

        try {
            $ok = processJob($job, $pdo);
            if ($ok) {
                // ONLY remove from processing AFTER successful commit()
                $redis->lRem('queue:biometric_processing', $item, 0);
                logW('OK', sprintf("action=%s req=%s", $job['action'] ?? '?', $job['request_id'] ?? 'n/a'));
            } else {
                // Logic failure: reencolar en la cola principal para reintentar (igual que excepción DB)
                $redis->lPush('queue:biometric_ingest', $item);
                $redis->lRem('queue:biometric_processing', $item, 0);
                logW('LOGIC_FAIL', sprintf("action=%s, requeued", $job['action'] ?? '?'));
            }
        } catch (Exception $e) {
            logW('ERR', $e->getMessage());
            $jobArray = json_decode($item, true) ?: [];
            $retries = ($jobArray['retries'] ?? 0) + 1;
            $jobArray['retries'] = $retries;

            if ($retries <= 3) {
                $redis->rPush('queue:biometric_ingest', json_encode($jobArray, JSON_UNESCAPED_UNICODE));
            } else {
                $redis->rPush('queue:biometric_dlq', json_encode($jobArray, JSON_UNESCAPED_UNICODE));
            }
            $redis->lRem('queue:biometric_processing', $item, 0);
            
            // Reconnect PDO if it failed (db.php recrea $pdo global)
            if (strpos($e->getMessage(), 'server closed the connection') !== false || strpos($e->getMessage(), 'gone away') !== false) {
                require __DIR__ . '/../core/db.php';
            }
        }
    } catch (Exception $e) {
        logW('FATAL', $e->getMessage());
        sleep(15);
        $redis = null; // Force reconnect
    }

    // FIX (SRE-2): Ejecutar GC de zombies cada 60 segundos.
    if (time() - $lastGc >= 60) {
        $lastGc = time();
        try {
            $recovered = $redis->eval($scriptGc, ['queue:biometric_processing', 'queue:biometric_ingest', $GC_MAX_AGE_SEC, time()], 2);
            if ($recovered > 0) {
                logW('GC_ZOMBIE', "Recovered {$recovered} zombie job(s) after {$GC_MAX_AGE_SEC}s");
            }
        } catch (Exception $e) {
            logW('GC_ERR', $e->getMessage());
        }
    }

    if (++$iterations % 1000 === 0) {
        gc_collect_cycles();
        $mem = memory_get_peak_usage(true) / 1024 / 1024;
        if ($mem > 256) { logW('MEM', "{$mem}MB > 256MB restart"); exit(0); }
    }
}
logW('STOP', 'Biometric worker shutdown'); exit(0);
