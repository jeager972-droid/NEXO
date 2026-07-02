<?php
/**
 * NEXO Audit Log Worker — Procesa logs de auditoría encolados en Redis
 * Ejecutar bajo supervisor o como servicio Docker: php worker_audit.php
 */
require_once __DIR__ . '/../db.php';

function logWorker($event, $details = '') {
    $msg = sprintf("[%s] [AUDIT_WORKER] [%s] %s\n", gmdate('Y-m-d H:i:s'), $event, $details);
    file_put_contents('php://stderr', $msg);
}

function connectRedis() {
    $redis = new Redis();
    // Timeout de 100ms para evitar bloqueos en workers de fondo
    $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379, 0.1);
    if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
    return $redis;
}

function insertBatch($conn, array $rows) {
    if (empty($rows)) return;
    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO global_audit_logs
                (log_id, school_id, performed_by_user_id, action_type,
                 description, ip_address, action_details, created_at)
            VALUES
                (uuid_generate_v4(), ?, ?, ?, ?,
                 ?::inet,
                 jsonb_build_object('uri', ?::text, 'request_id', ?::text),
                 ?)
        ");
        foreach ($rows as $row) {
            $stmt->execute([
                $row['school_id'],
                $row['actor_id'],       // campo en la cola Redis se llama así
                $row['event_type'],     // ídem
                $row['description'],
                $row['ip_address'] ?? '0.0.0.0',
                $row['uri'] ?? 'N/A',
                $row['request_id'] ?? null,
                $row['created_at'] ?? gmdate('Y-m-d H:i:s')
            ]);
        }
        $conn->commit();
        logWorker('BATCH_INSERT', 'Inserted ' . count($rows) . ' audit records');
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/* ============================================================
   Main Loop
   ============================================================ */
declare(ticks=1);

$shutdown = false;
$iterations = 0;
pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

$conn = $pdo;
try {
    $conn->query("SELECT set_config('app.current_role', 'SUPER_RECTOR', false)");
} catch (PDOException $e) {
    logWorker('ROLE_SET_SKIP', $e->getMessage());
}
try {
    $redis = connectRedis();
} catch (Exception $e) {
    logWorker('FATAL', "Failed to connect to Redis on startup: " . $e->getMessage());
    exit(1);
}
$batchSize = 100;
$batch = [];
$queue = 'queue:audit_logs';
$pollTimeout = 5; // segundos

logWorker('START', "Audit worker initialized. Batch size: $batchSize");

while (!$shutdown) {
    try {
        pcntl_signal_dispatch();

        // Si el lote está vacío, esperar bloqueante hasta max $pollTimeout segundos
        if (empty($batch)) {
            $items = $redis->blPop($queue, $pollTimeout);
            if ($items && isset($items[1])) {
                $row = json_decode($items[1], true);
                if ($row) $batch[] = $row;
            }
            // Si no llegó nada, continuamos (batch sigue vacío, volvemos a esperar)
            continue;
        }

        // Ya tenemos al menos un elemento, extraer el resto sin bloqueo
        while (count($batch) < $batchSize) {
            $item = $redis->lPop($queue);
            if ($item === false) break; // No hay más elementos

            $row = json_decode($item, true);
            if ($row) $batch[] = $row;
        }

        // Guardar lote (completo o parcial)
        if (!empty($batch)) {
            try {
                insertBatch($conn, $batch);
                // FIX: Heartbeat para health check
                $redis->set('worker:audit:last_heartbeat', time(), 600);
            } catch (Exception $e) {
                logWorker('BATCH_ERROR', $e->getMessage());
                // Reencolar los logs fallidos en la cola de reintentos
                foreach ($batch as $row) {
                    $redis->rPush($queue, json_encode($row, JSON_UNESCAPED_UNICODE));
                }
                // Escalar al catch externo para forzar restart del supervisor
                throw $e;
            }
            $batch = [];
        }
    } catch (Exception $e) {
        logWorker('FATAL', $e->getMessage());
        exit(1); // Let supervisor restart with backoff
    }

    // Pequeña pausa para no saturar CPU
    usleep(10000); // 10ms

    // FIX: Forzar GC y monitorear memoria en vez de matar el proceso
    if (++$iterations % 1000 === 0) {
        gc_collect_cycles();
        $memPeak = memory_get_peak_usage(true) / 1024 / 1024;
        if ($memPeak > 256) {
            logWorker('MEMORY_LIMIT', "Peak {$memPeak}MB > 256MB. Graceful restart.");
            exit(0);
        }
    }
}

logWorker('STOP', "Audit worker shutting down gracefully");
exit(0);
