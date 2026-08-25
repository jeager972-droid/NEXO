<?php
/**
 * =============================================================================
 * workers/worker_audit.php — Procesador opcional de logs de auditoría vía Redis.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Consumir mensajes de la cola Redis `queue:audit_logs` e insertarlos en la
 * tabla global_audit_logs manteniendo una cadena de hashes criptográfica por
 * escuela. Solo se ejecuta cuando AUDIT_WORKER_ENABLED=1; en el uso por defecto
 * los logs de seguridad se escriben directamente a stderr y este worker no
 * realiza polling ni consume comandos Redis.
 *
 * FLUJO GENERAL
 * -------------
 *   Redis queue:audit_logs
 *        │
 *        ▼
 *   blpop / lpop
 *        │
 *        ▼
 *   insertBatch()
 *        │
 *   ├── Busca último hash de la escuela
 *   ├── Calcula HMAC(prevHash|school|actor|event|details|ip|ts)
 *   └── INSERT con log_id, prev_audit_id, chain_hash
 *        │
 *        ▼
 *   heartbeat Redis + GC periódico
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - db.php : conexión PDO ($pdo).
 *   - Redis : extensión php-redis; cola `queue:audit_logs`.
 *   - Variables de entorno: AUDIT_WORKER_ENABLED, REDISHOST, REDISPORT,
 *     REDIS_PASSWORD, APP_NEXO_HMAC_SECRET.
 *
 * Es utilizado por:
 *   - Sistema: arrancado por supervisor/Docker. Producido por securityLog() de api.php.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/redis.php';

/**
 * Escribe un log del worker a stderr con timestamp.
 *
 * @param string $event Tipo de evento.
 * @param string $details Detalles adicionales.
 * @return void
 */
function logWorker($event, $details = '') {
    $msg = sprintf("[%s] [AUDIT_WORKER] [%s] %s\n", gmdate('Y-m-d H:i:s'), $event, $details);
    file_put_contents('php://stderr', $msg);
}

/**
 * Genera un UUID v4 sin depender de extensión uuid-ossp.
 *
 * @return string UUID v4 canónico.
 */
function generateUuidV4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Calcula el HMAC-SHA256 que enlaza un log con el anterior en la cadena.
 *
 * @param string|null $prevHash Hash del registro previo.
 * @param string|null $schoolId UUID de la escuela.
 * @param string|null $actorId UUID del actor.
 * @param string $eventType Tipo de evento.
 * @param string $description Descripción.
 * @param string $ipAddress Dirección IP.
 * @param string $createdAt Timestamp.
 * @param string $secret Secreto HMAC.
 * @return string Hash HMAC-SHA256.
 */
function calculateAuditHash($prevHash, $schoolId, $actorId, $eventType, $description, $ipAddress, $createdAt, $secret) {
    $prevHash = $prevHash ?: 'GENESIS';
    $schoolId = $schoolId ?: 'NULL';
    $actorId = $actorId ?: 'NULL';
    $payload = "{$prevHash}|{$schoolId}|{$actorId}|{$eventType}|{$description}|{$ipAddress}|{$createdAt}";
    return hash_hmac('sha256', $payload, $secret);
}

/**
 * Inserta un lote de logs de auditoría en una transacción manteniendo la cadena de hashes.
 *
 * @param PDO $conn Conexión PDO.
 * @param array $rows Filas decodificadas de Redis.
 * @return void
 *
 * Efectos secundarios: inicia y confirma/ revierte una transacción; en error
 * reencola las filas fallidas en `queue:audit_logs`.
 */
function insertBatch($conn, array $rows) {
    if (empty($rows)) return;
    
    $secret = getenv('APP_NEXO_HMAC_SECRET');
    if (!$secret || $secret === 'default-secret-change-me') {
        error_log('[AUDIT_WORKER] FATAL: APP_NEXO_HMAC_SECRET no configurada o es default. Worker no puede iniciar.');
        exit(1);
    }
    $lastHashes = []; // Cache en memoria para el batch

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO global_audit_logs
                (log_id, school_id, performed_by_user_id, action_type,
                 description, ip_address, action_details, created_at,
                 prev_audit_id, chain_hash)
            VALUES
                (?, ?, ?, ?, ?,
                 ?::inet,
                 ?::jsonb,
                 ?, ?, ?)
        ");
        
        foreach ($rows as $row) {
            $schoolId = $row['school_id'];
            
            if (!isset($lastHashes[$schoolId])) {
                $chk = $conn->prepare("SELECT log_id, chain_hash FROM global_audit_logs WHERE school_id = ? ORDER BY created_at DESC, log_id DESC LIMIT 1");
                $chk->execute([$schoolId]);
                $lastHashes[$schoolId] = $chk->fetch(PDO::FETCH_ASSOC) ?: ['log_id' => null, 'chain_hash' => 'GENESIS'];
            }
            
            $prevAuditId = $lastHashes[$schoolId]['log_id'];
            $prevHash = $lastHashes[$schoolId]['chain_hash'];
            
            $logId = generateUuidV4();
            $actorId = $row['actor_id'];
            $eventType = $row['event_type'];
            $description = $row['description'];
            $ipAddress = $row['ip_address'] ?? '0.0.0.0';
            
            // Format to match PostgreSQL jsonb::text cast style
            $uriStr = isset($row['uri']) ? '"' . str_replace('"', '\"', $row['uri']) . '"' : '"N/A"';
            $reqStr = isset($row['request_id']) ? '"' . str_replace('"', '\"', $row['request_id']) . '"' : 'null';
            $actionDetailsJson = '{"uri": ' . $uriStr . ', "request_id": ' . $reqStr . '}';
            
            // Format to match PostgreSQL timestamptz cast style (YYYY-MM-DD HH:MM:SS+00)
            $createdAt = $row['created_at'] ?? gmdate('Y-m-d H:i:s');
            $createdAtPg = date('Y-m-d H:i:s+00', strtotime($createdAt));

            $hash = calculateAuditHash($prevHash, $schoolId, $actorId, $eventType, $actionDetailsJson, $ipAddress, $createdAtPg, $secret);

            $stmt->execute([
                $logId,
                $schoolId,
                $actorId,
                $eventType,
                $description,
                $ipAddress,
                $actionDetailsJson,
                $createdAt,
                $prevAuditId,
                $hash
            ]);
            
            // Actualizar la caché del lote para el siguiente registro
            $lastHashes[$schoolId] = ['log_id' => $logId, 'chain_hash' => $hash];
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

// Worker audit: si no está habilitado, salir sin conectar a Redis.
$auditEnabled = getenv('AUDIT_WORKER_ENABLED') === '1';
if (!$auditEnabled) {
    logWorker('DISABLED', 'AUDIT_WORKER_ENABLED no está activo. Worker dormido por 1 hora para evitar consumo.');
    sleep(3600);
    exit(0);
}

$conn = $pdo;
try {
    $conn->query("SELECT set_config('app.current_role', 'SYSTEM_WORKER', false)");
} catch (PDOException $e) {
    logWorker('ROLE_SET_SKIP', $e->getMessage());
}
try {
    $redis = getRedisConnection();
    if (!$redis) {
        throw new Exception('Redis unavailable on startup');
    }
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
                // Reencolar con retry count; tras 3 reintentos, enviar a DLQ
                $dlqQueue = 'queue:audit_logs_dlq';
                foreach ($batch as $row) {
                    $retryCount = ($row['_retry_count'] ?? 0) + 1;
                    $row['_retry_count'] = $retryCount;
                    if ($retryCount > 3) {
                        $row['_error'] = $e->getMessage();
                        $row['_failed_at'] = date('c');
                        $redis->rPush($dlqQueue, json_encode($row, JSON_UNESCAPED_UNICODE));
                        logWorker('DLQ', "Audit log moved to DLQ after $retryCount retries: " . json_encode($row, JSON_UNESCAPED_UNICODE));
                    } else {
                        $redis->rPush($queue, json_encode($row, JSON_UNESCAPED_UNICODE));
                        logWorker('REQUEUE', "Audit log requeued (retry $retryCount/3)");
                    }
                }
                // No re-throw: continuar procesando siguiente batch en lugar de
                // forzar restart del supervisor (que causaría loop infinito)
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
