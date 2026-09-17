<?php
/**
 * =============================================================================
 * workers/worker_permission_status.php — Auto-marcar permisos COMPLETED/EXPIRED.
 * =============================================================================
 *
 * RESPONSABILIDAD
 * ----------------
 * Revisa periódicamente todas las autorizaciones de salida (class_exit_authorizations)
 * con status='ACTIVE' y actualiza su estado:
 *
 *   - COMPLETED : Si el estudiante registró un evento biométrico INGRESO_% después
 *                 de exit_time (regresó al salón/institución).
 *   - EXPIRED   : Si NOW() > return_time + 5 minutos Y no hay ingreso posterior
 *                 a exit_time (no regresó a tiempo).
 *
 * EJECUCIÓN
 * ---------
 *   - Cron cada 1 minuto (una sola ejecución y salir).
 *   - O daemon con loop cada 60 segundos.
 *   - Variable de entorno PERMISSION_STATUS_MODE = 'cron' | 'daemon'
 *   - Variable de entorno PERMISSION_CHECK_INTERVAL = segundos (default 60)
 */

declare(ticks=1);
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/redis.php';
require_once __DIR__ . '/../lib/notify_routing.php';

$shutdown = false;
pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

function logE(string $e, string $m = ''): void {
    error_log("[PERMISSION_STATUS] {$e} | {$m}");
}

/**
 * Procesa una institución: actualiza permisos ACTIVE a COMPLETED o EXPIRED.
 *
 * @param PDO $conn    Conexión PDO global.
 * @param string $schoolId UUID de la escuela.
 * @return int Número de permisos actualizados.
 */
function processSchoolPermissions(PDO $conn, string $schoolId): int {
    $updated = 0;

    // set_config para RLS (transaction-level para PgBouncer)
    $conn->exec("BEGIN");
    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
    $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

    // Obtener todos los permisos ACTIVE de esta escuela (con contexto espacial V-031)
    $stmt = $conn->prepare("
        SELECT cea.authorization_id, cea.student_id, cea.exit_time, cea.return_time,
               cea.schedule_id, sch.classroom_id AS expected_classroom_id
        FROM class_exit_authorizations cea
        LEFT JOIN schedules sch ON sch.schedule_id = cea.schedule_id
        WHERE cea.school_id = ?
          AND cea.status = 'ACTIVE'
        ORDER BY cea.exit_time ASC
    ");
    $stmt->execute([$schoolId]);
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($permissions as $perm) {
        $authId   = $perm['authorization_id'];
        $studentId = $perm['student_id'];
        $exitTime  = $perm['exit_time'];

        // 1. Buscar el evento de retorno (INGRESO_% posterior a exit_time).
        //    V-063: si el permiso conoce el aula esperada, el retorno debe ocurrir
        //    en ese espacio — no vale identificarse en cualquier nodo.
        $expectedClassroom = $perm['expected_classroom_id'] ?? null;
        $returnCheck = $conn->prepare("
            SELECT event_id, event_timestamp, classroom_id
            FROM biometric_events
            WHERE school_id = ?
              AND student_id = ?
              AND event_timestamp > ?
              AND event_type LIKE 'INGRESO_%'
            ORDER BY event_timestamp ASC
        ");
        $returnCheck->execute([$schoolId, $studentId, $exitTime]);
        $returnEvent = null;
        $spaceValidated = null; // null = no verificable (sin contexto espacial)
        while ($ev = $returnCheck->fetch(PDO::FETCH_ASSOC)) {
            if ($expectedClassroom === null) {
                $returnEvent = $ev;
                $spaceValidated = null;
                break;
            }
            if ($ev['classroom_id'] !== null && $ev['classroom_id'] === $expectedClassroom) {
                $returnEvent = $ev;
                $spaceValidated = true;
                break;
            }
            // Evento en espacio distinto al esperado: no cuenta como retorno,
            // pero se registra para trazabilidad.
            logE('RETURN_WRONG_SPACE', "auth=$authId student=$studentId event_classroom=" . ($ev['classroom_id'] ?? 'null') . " expected=$expectedClassroom");
        }

        if ($returnEvent) {
            // El estudiante regresó: marcar como COMPLETED con hora real de retorno
            try {
                $upd = $conn->prepare("
                    UPDATE class_exit_authorizations
                    SET status = 'COMPLETED',
                        actual_return_time = ?,
                        metadata_json = COALESCE(metadata_json, '{}'::jsonb) || ?::jsonb
                    WHERE authorization_id = ? AND status = 'ACTIVE'
                ");
                $upd->execute([
                    $returnEvent['event_timestamp'],
                    json_encode([
                        'return_event_id' => $returnEvent['event_id'],
                        'return_space_validated' => $spaceValidated,
                    ]),
                    $authId,
                ]);
                if ($upd->rowCount() > 0) {
                    $updated++;
                    logE('COMPLETED', "auth=$authId student=$studentId school=$schoolId space_validated=" . var_export($spaceValidated, true));
                }
            } catch (Exception $e) {
                logE('UPDATE_COMPLETED_FAIL', "auth=$authId error=" . $e->getMessage());
            }
            continue;
        }

        // 2. Verificar si el permiso ha expirado (NOW() > return_time + 5 min)
        // Solo si return_time no es NULL
        if ($perm['return_time'] !== null) {
            $expireCheck = $conn->prepare("
                SELECT 1
                WHERE (NOW() AT TIME ZONE 'America/Bogota') > (?::timestamptz + INTERVAL '5 minutes')
            ");
            $expireCheck->execute([$perm['return_time']]);
            $isExpired = (bool)$expireCheck->fetchColumn();

            if ($isExpired) {
                try {
                    $upd = $conn->prepare("
                        UPDATE class_exit_authorizations
                        SET status = 'EXPIRED'
                        WHERE authorization_id = ? AND status = 'ACTIVE'
                    ");
                    $upd->execute([$authId]);
                    if ($upd->rowCount() > 0) {
                        $updated++;
                        logE('EXPIRED', "auth=$authId student=$studentId school=$schoolId return_time=" . $perm['return_time']);

                        // V-041: notificar a los destinatarios configurados
                        // (school_notification_routes, event_kind PERMISSION_EXPIRED;
                        // default COORDINATOR+RECTOR)
                        try {
                            foreach (nexoRouteUserIds($conn, (string)$schoolId, 'PERMISSION_EXPIRED') as $uid) {
                                $conn->prepare("
                                    INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, dedup_key)
                                    VALUES (?, ?, 'Permiso vencido', ?, 'ALERT', ?::jsonb, ?)
                                    ON CONFLICT (dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING
                                ")->execute([$schoolId, $uid,
                                    "El permiso del estudiante venció sin registro de retorno.",
                                    json_encode(['authorization_id' => $authId, 'student_id' => $studentId, 'event' => 'PERMISSION_EXPIRED']),
                                    hash('sha256', "perm_expired|$authId")]);
                            }
                        } catch (Exception $ne) {
                            logE('EXPIRED_NOTIFY_FAIL', "auth=$authId error=" . $ne->getMessage());
                        }
                    }
                } catch (Exception $e) {
                    logE('UPDATE_EXPIRED_FAIL', "auth=$authId error=" . $e->getMessage());
                }
            }
        }
    }

    $conn->exec("COMMIT");
    return $updated;
}

// ============================================================================
// Bucle principal
// ============================================================================
logE('START', 'Permission status worker started');

$runMode = getenv('PERMISSION_STATUS_MODE') ?: 'cron';

if ($runMode === 'cron') {
    try {
        $redis = getRedisConnection();
        $schoolsStmt = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE");
        $schools = $schoolsStmt->fetchAll(PDO::FETCH_COLUMN);

        $total = 0;
        foreach ($schools as $schoolId) {
            $lockKey = "lock:permission_status:$schoolId";
            if ($redis && !$redis->set($lockKey, '1', ['nx', 'ex' => 300])) {
                logE('LOCK_SKIP', "school=$schoolId already locked by another instance");
                continue;
            }
            try {
                $total += processSchoolPermissions($pdo, $schoolId);
            } finally {
                if ($redis) $redis->del($lockKey);
            }
        }
        logE('CRON_DONE', "schools=" . count($schools) . " updated=$total");
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
$CHECK_INTERVAL_SEC = (int)(getenv('PERMISSION_CHECK_INTERVAL') ?: 60);

while (!$shutdown) {
    try {
        try {
            $redis = getRedisConnection();
        } catch (Exception $e) {}

        if ($redis && time() - $lastHeartbeat >= 30) {
            $lastHeartbeat = time();
            try { $redis->set('worker:permission_status:last_heartbeat', time()); } catch (Exception $e) {}
        }

        try {
            $schoolsStmt = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE");
            $schools = $schoolsStmt->fetchAll(PDO::FETCH_COLUMN);

            $total = 0;
            foreach ($schools as $schoolId) {
                $lockKey = "lock:permission_status:$schoolId";
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
                    $total += processSchoolPermissions($pdo, $schoolId);
                } finally {
                    if ($hasLock && $redis) {
                        try { $redis->del($lockKey); } catch (Exception $e) {}
                    }
                }
            }
            if ($total > 0) {
                logE('UPDATED', "permissions=$total");
            }
        } catch (Exception $e) {
            logE('ERR', $e->getMessage());
        }

        sleep($CHECK_INTERVAL_SEC);
        $iterations++;

        if ($iterations % 100 === 0) {
            // Reconectar PDO para evitar conexiones stale en daemon de larga duración
            $pdo = null;
            require __DIR__ . '/../core/db.php';
        }
    } catch (Exception $e) {
        logE('FATAL', $e->getMessage());
        exit(1);
    }
}

logE('STOP', 'Permission status worker stopped');
