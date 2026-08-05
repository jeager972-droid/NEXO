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
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../redis.php';

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

    // Obtener todos los permisos ACTIVE de esta escuela
    $stmt = $conn->prepare("
        SELECT authorization_id, student_id, exit_time, return_time
        FROM class_exit_authorizations
        WHERE school_id = ?
          AND status = 'ACTIVE'
        ORDER BY exit_time ASC
    ");
    $stmt->execute([$schoolId]);
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($permissions as $perm) {
        $authId   = $perm['authorization_id'];
        $studentId = $perm['student_id'];
        $exitTime  = $perm['exit_time'];

        // 1. Verificar si hay un evento biométrico INGRESO_% después de exit_time
        $returnCheck = $conn->prepare("
            SELECT 1 FROM biometric_events
            WHERE school_id = ?
              AND student_id = ?
              AND event_timestamp > ?
              AND event_type LIKE 'INGRESO_%'
            LIMIT 1
        ");
        $returnCheck->execute([$schoolId, $studentId, $exitTime]);
        $hasReturned = (bool)$returnCheck->fetchColumn();

        if ($hasReturned) {
            // El estudiante regresó: marcar como COMPLETED
            try {
                $upd = $conn->prepare("
                    UPDATE class_exit_authorizations
                    SET status = 'COMPLETED'
                    WHERE authorization_id = ? AND status = 'ACTIVE'
                ");
                $upd->execute([$authId]);
                if ($upd->rowCount() > 0) {
                    $updated++;
                    logE('COMPLETED', "auth=$authId student=$studentId school=$schoolId");
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
        $schoolsStmt = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE");
        $schools = $schoolsStmt->fetchAll(PDO::FETCH_COLUMN);

        $total = 0;
        foreach ($schools as $schoolId) {
            $total += processSchoolPermissions($pdo, $schoolId);
        }
        logE('CRON_DONE', "schools=" . count($schools) . " updated=$total");
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
$CHECK_INTERVAL_SEC = (int)(getenv('PERMISSION_CHECK_INTERVAL') ?: 60);

while (!$shutdown) {
    try {
        if (time() - $lastHeartbeat >= 30) {
            $lastHeartbeat = time();
            $redis->set('worker:permission_status:last_heartbeat', time());
        }

        try {
            $schoolsStmt = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE");
            $schools = $schoolsStmt->fetchAll(PDO::FETCH_COLUMN);

            $total = 0;
            foreach ($schools as $schoolId) {
                $total += processSchoolPermissions($pdo, $schoolId);
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
            require __DIR__ . '/../db.php';
        }
    } catch (Exception $e) {
        logE('FATAL', $e->getMessage());
        exit(1);
    }
}

logE('STOP', 'Permission status worker stopped');
