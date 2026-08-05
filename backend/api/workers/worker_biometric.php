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
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../redis.php';

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

                $stmt = $conn->prepare(
                    "INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,event_timestamp,event_fingerprint,metadata_json)
                     SELECT uuid_generate_v4(),school_id,student_id,
                            ?,
                            ?,'PROCESSED',to_timestamp(?),?,?::jsonb
                     FROM students WHERE document_number = ? AND school_id = ? LIMIT 1
                     ON CONFLICT (event_fingerprint, event_timestamp) WHERE event_fingerprint IS NOT NULL DO NOTHING"
                );
                $stmt->execute([$deviceId, $evt, $capturedAt, $fingerprint, $metadataJson, $doc, $instId]);
                $inserted = $stmt->rowCount() > 0;

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
                             AND NOT EXISTS (
                                 SELECT 1 FROM attendance_incidents
                                 WHERE student_id = students.student_id
                                   AND school_id = ?
                                   AND (detected_at)::date = (to_timestamp(?))::date
                                   AND incident_type = 'LATE_ARRIVAL'
                             )
                             RETURNING incident_id"
                        );
                        $lateStmt->execute([$capturedAt, $doc, $instId, $instId, $capturedAt]);
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
                            $teacherStmt = $conn->prepare(
                                "SELECT sch.teacher_user_id FROM schedules sch
                                 JOIN student_group_assignments sga ON sga.group_id = sch.group_id AND sga.active = TRUE
                                 JOIN academic_groups ag ON ag.group_id = sga.group_id
                                 WHERE sga.student_id = ?
                                   AND sch.day_of_week = EXTRACT(ISODOW FROM (NOW() AT TIME ZONE 'America/Bogota'))
                                   AND sch.school_id = ?"
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
                                    "INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json)
                                     VALUES (?, ?, 'Llegada tarde detectada', ?, 'ALERT', ?::jsonb)"
                                );
                                foreach (array_unique($teacherIds) as $teacherUserId) {
                                    try {
                                        $notifStmt->execute([
                                            $instId,
                                            $teacherUserId,
                                            "El estudiante {$studentFullName} llegó tarde a clase",
                                            $notifMeta,
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
                    logW('SYNC_NOOP', "doc=$doc evt=$evt — estudiante no encontrado o duplicado");
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
            if (empty($schoolId) || empty($doc) || empty($nombre)) return false;

            $conn->exec("BEGIN");
            try {
                // FIX (PgBouncer): SET LOCAL en lugar de set_config con prepare
                $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$schoolId) . ", true)");
                $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

                $stmt = $conn->prepare("INSERT INTO students(school_id,document_number,first_name,last_name,active) VALUES(?,?,?,'',TRUE) ON CONFLICT(school_id, document_number) DO UPDATE SET first_name=EXCLUDED.first_name,active=TRUE RETURNING student_id");
                $stmt->execute([$schoolId, $doc, $nombre]);
                $studentId = $stmt->fetchColumn();

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
$redis = getRedisConnection();
if (!$redis) {
    logW('FATAL', 'Redis unavailable on startup');
    exit(1);
}
$iterations = 0;
$lastGc = 0;
$lastHeartbeat = 0;

while (!$shutdown) {
    try {
        // FIX: Enviar heartbeat cada 30 segundos
        if (time() - $lastHeartbeat >= 30) {
            $lastHeartbeat = time();
            sendHeartbeat($redis);
        }

        // FIX (SRE-2): Atomic Lua pop + timestamp injection.
        $item = $redis->eval($scriptReliablePop, ['queue:biometric_ingest', 'queue:biometric_processing', time()], 2);
        if (!$item) { usleep($EMPTY_QUEUE_SLEEP_US); continue; }

        $job = json_decode($item, true);
        if (!$job) {
            $redis->lRem('queue:biometric_processing', $item, 0);
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
            // Escalar al catch externo para forzar restart del supervisor (reconecta PDO)
            throw $e;
        }
    } catch (Exception $e) {
        logW('FATAL', $e->getMessage());
        exit(1); // Let supervisor restart with backoff (reconnects both Redis and PDO)
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
