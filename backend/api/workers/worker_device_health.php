<?php
/**
 * =============================================================================
 * workers/worker_device_health.php — Monitor de salud de nodos edge (F-04).
 * =============================================================================
 *
 * RESPONSABILIDAD
 * ----------------
 * Vigila edge_devices.last_ping de cada institución. Un nodo sin ping por más
 * de NODE_OFFLINE_MINUTES se declara offline y genera:
 *   - security_incidents tipo NODO_OFFLINE (uno abierto por dispositivo)
 *   - edge_devices.status = 'offline'
 *   - notificación interna a coordinación (deduplicada por dispositivo)
 *
 * BIDIRECCIONAL: cuando el nodo vuelve a reportar ping, el incidente se
 * resuelve (resolved=TRUE) y coordinación recibe aviso de recuperación.
 *
 * El gate de los detectores (worker_absence_detector / worker_evasion_detector,
 * vía contingency_lib) suspende la generación de INASISTENCIA/EVASION_INTERNA
 * para grupos cuyo nodo está caído y marca SIN_DATOS_NODO en su lugar.
 *
 * EJECUCIÓN
 * ---------
 *   DEVICE_HEALTH_MODE=cron  → una pasada y sale (para crontab)
 *   DEVICE_HEALTH_MODE=daemon → loop cada DEVICE_HEALTH_INTERVAL (default 60s)
 *   NODE_OFFLINE_MINUTES=10  → umbral de silencio de ping
 * =============================================================================
 */

declare(ticks=1);
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/redis.php';
require_once __DIR__ . '/contingency_lib.php';

$shutdown = false;
pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

function logH(string $e, string $m = ''): void {
    error_log("[DEVICE_HEALTH] {$e} | {$m}");
}

/**
 * Procesa una escuela: detecta nodos caídos/recuperados y actualiza su estado.
 * Retorna ['offline' => n, 'recovered' => n].
 */
function processSchoolHealth(PDO $conn, string $schoolId): array {
    $result = ['offline' => 0, 'recovered' => 0];
    $offlineSeconds = ctOfflineSeconds();
    $now = time();

    $conn->exec("BEGIN");
    $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote($schoolId) . ", true)");
    $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");

    try {
        $devices = ctFetchDeviceRows($conn, $schoolId);

        // NODO_OFFLINE abiertos por dispositivo (incident_id => device_id)
        $openStmt = $conn->prepare("
            SELECT incident_id, metadata_json->>'device_id' AS device_id
            FROM security_incidents
            WHERE school_id = ? AND incident_type = 'NODO_OFFLINE' AND resolved = FALSE
        ");
        $openStmt->execute([$schoolId]);
        $openIncidents = [];   // device_id => incident_id
        foreach ($openStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['device_id']) $openIncidents[$row['device_id']] = $row['incident_id'];
        }

        $trans = ctNodeTransitions($devices, array_fill_keys(array_keys($openIncidents), true), $now, $offlineSeconds);

        // ── Bajada: nodo deja de reportar ──
        foreach ($trans['went_offline'] as $dev) {
            $groupLabel = $dev['group_name'] ?: 'sin grupo asignado';
            $incidentId = ctCreateSecurityIncident(
                $conn, $schoolId, 'NODO_OFFLINE', 'HIGH',
                "Nodo '{$dev['device_name']}' ({$groupLabel}) sin reporte por más de " . intdiv($offlineSeconds, 60) . " minutos. La detección automática para su grupo queda en modo contingencia.",
                [
                    'device_id'   => $dev['device_id'],
                    'device_name' => $dev['device_name'],
                    'group_id'    => $dev['group_id'],
                    'group_name'  => $dev['group_name'],
                    'location'    => $dev['location'],
                    'last_ping'   => $dev['last_ping_ts'] !== null ? date('c', $dev['last_ping_ts']) : null,
                ]
            );
            $conn->prepare("UPDATE edge_devices SET status = 'offline' WHERE device_id = ?")
                 ->execute([$dev['device_id']]);
            ctNotifyCoordinators(
                $conn, $schoolId,
                'Nodo sin reporte',
                "El nodo '{$dev['device_name']}' ({$groupLabel}) lleva más de " . intdiv($offlineSeconds, 60) . " min sin reportar. No se generarán ausencias/evasiones para su grupo hasta que vuelva.",
                ['device_id' => $dev['device_id'], 'incident_id' => $incidentId, 'kind' => 'NODO_OFFLINE'],
                'NODO_OFFLINE:' . $dev['device_id']
            );
            logH('NODE_OFFLINE', "school=$schoolId device={$dev['device_id']} name={$dev['device_name']} group={$dev['group_id']} incident=$incidentId");
            $result['offline']++;
        }

        // ── Subida: nodo vuelve a reportar ──
        foreach ($trans['came_online'] as $dev) {
            $incidentId = $openIncidents[$dev['device_id']] ?? null;
            if ($incidentId) {
                $conn->prepare("
                    UPDATE security_incidents
                    SET resolved = TRUE, resolved_at = NOW(),
                        metadata_json = COALESCE(metadata_json,'{}'::jsonb) || ?::jsonb
                    WHERE incident_id = ?::uuid
                ")->execute([json_encode(['recovered_at' => date('c', $now)]), $incidentId]);
            }
            $conn->prepare("UPDATE edge_devices SET status = 'online' WHERE device_id = ?")
                 ->execute([$dev['device_id']]);
            ctNotifyCoordinators(
                $conn, $schoolId,
                'Nodo recuperado',
                "El nodo '{$dev['device_name']}' volvió a reportar. La detección automática de su grupo queda restaurada.",
                ['device_id' => $dev['device_id'], 'incident_id' => $incidentId, 'kind' => 'NODO_RECOVERED'],
                'NODO_RECOVERED:' . $dev['device_id'] . ':' . ($incidentId ?? 'x')
            );
            logH('NODE_RECOVERED', "school=$schoolId device={$dev['device_id']} incident=$incidentId");
            $result['recovered']++;
        }

        $conn->exec("COMMIT");
    } catch (Exception $e) {
        try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
        logH('PROCESS_FAIL', "school=$schoolId error=" . $e->getMessage());
    }
    return $result;
}

// ============================================================================
// Bucle principal
// ============================================================================
logH('START', 'Device health worker started');

$runMode = getenv('DEVICE_HEALTH_MODE') ?: 'cron';

if ($runMode === 'cron') {
    try {
        $schools = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE")->fetchAll(PDO::FETCH_COLUMN);
        $off = 0; $rec = 0;
        foreach ($schools as $schoolId) {
            $r = processSchoolHealth($pdo, $schoolId);
            $off += $r['offline']; $rec += $r['recovered'];
        }
        logH('CRON_DONE', "schools=" . count($schools) . " offline=$off recovered=$rec");
        exit(0);
    } catch (Exception $e) {
        logH('FATAL', $e->getMessage());
        exit(1);
    }
}

// Modo daemon
$redis = null;
try { $redis = getRedisConnection(); } catch (Exception $e) {}
$CHECK_INTERVAL_SEC = (int)(getenv('DEVICE_HEALTH_INTERVAL') ?: 60);
$lastHeartbeat = 0;

while (!$shutdown) {
    try {
        try { $redis = getRedisConnection(); } catch (Exception $e) {}
        if ($redis && time() - $lastHeartbeat >= 30) {
            try { $redis->set('worker:device_health:last_heartbeat', time()); } catch (Exception $e) {}
            $lastHeartbeat = time();
        }
        $schools = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($schools as $schoolId) {
            processSchoolHealth($pdo, $schoolId);
        }
    } catch (Exception $e) {
        logH('LOOP_FAIL', $e->getMessage());
    }
    sleep($CHECK_INTERVAL_SEC);
}
logH('STOP', 'Device health worker stopped');
