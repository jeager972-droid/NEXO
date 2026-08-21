<?php
/**
 * =============================================================================
 * workers/worker_notification_purge.php — Purga automática de notificaciones.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Elimina notificaciones con más de 30 días de antigüedad de la tabla
 * `notifications`. Diseñado para ejecutarse como cron diario o como daemon
 * con intervalo de 1 hora.
 *
 * FLUJO GENERAL
 * -------------
 *   1. Conecta a PostgreSQL (db.php).
 *   2. Ejecuta DELETE en notifications WHERE created_at < (NOW() - INTERVAL '30 days').
 *   3. Registra el número de filas eliminadas.
 *   4. Si modo daemon: espera 3600s y repite. Si modo cron: ejecuta una vez y sale.
 *
 * USO
 * ---
 *   Cron (recomendado):  0 3 * * *  php worker_notification_purge.php
 *   Daemon:              php worker_notification_purge.php --daemon
 *
 * DEPENDENCIAS
 * ------------
 *   - db.php : conexión PDO ($pdo).
 *   - Redis no es necesario para este worker.
 */

require_once __DIR__ . '/../core/db.php';

$daemonMode = in_array('--daemon', $argv ?? [], true);
$intervalSeconds = 3600; // 1 hora entre ciclos en modo daemon
$maxAgeDays = 30;

/**
 * Ejecuta un ciclo de purga.
 *
 * @param PDO $conn Conexión a PostgreSQL.
 * @param int $maxAgeDays Días máximos de antigüedad antes de purgar.
 * @return int Número de filas eliminadas.
 */
function purgeOldNotifications(PDO $conn, int $maxAgeDays): int {
    $stmt = $conn->prepare("
        DELETE FROM notifications
        WHERE created_at < (NOW() AT TIME ZONE 'America/Bogota' - INTERVAL '" . (int)$maxAgeDays . " days')
    ");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Log a stderr (compatible con systemd/journalctl).
 */
function logPurge(string $level, string $msg): void {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] [NOTIF_PURGE] [{$level}] {$msg}\n");
}

logPurge('INFO', "Worker de purga de notificaciones iniciado. Modo: " . ($daemonMode ? 'daemon' : 'cron'));

do {
    try {
        if (!$pdo) {
            throw new Exception("Conexión a BD no disponible");
        }

        $deleted = purgeOldNotifications($pdo, $maxAgeDays);
        logPurge('INFO', "Notificaciones purgadas (> {$maxAgeDays} días): {$deleted}");

    } catch (Exception $e) {
        logPurge('ERROR', $e->getMessage());
    }

    if (!$daemonMode) {
        break;
    }

    sleep($intervalSeconds);
} while ($daemonMode);

logPurge('INFO', "Worker de purga finalizado.");
