<?php
/**
 * NEXO Twilio Worker — Outbox Pattern
 * Ejecutar bajo supervisor: php worker_twilio.php
 */
require_once __DIR__ . '/db.php';

function securityLog($event, $details = '') {
    $ip = 'worker';
    $uri = 'worker_twilio.php';
    $fallbackMsg = sprintf("[%s] [EVENT:%s] [DETAILS:%s] [IP:%s]\n", gmdate('Y-m-d H:i:s'), $event, $details, $ip);
    file_put_contents('php://stderr', $fallbackMsg);
}

function logTwilioMessage($conn, $schoolId, $typeCode, $direction, $phone, $content, $meta = [], $studentId = null, $guardianId = null, $senderUserId = null, $providerSid = null, $deliveryStatus = null) {
    try {
        $stmt = $conn->prepare("INSERT INTO twilio_messages (
                twilio_message_id, school_id, student_id, guardian_id, sender_user_id,
                type_code, direction, phone_number, message_content, provider_message_sid,
                delivery_status, sent_at, metadata_json
            ) VALUES (
                uuid_generate_v4(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?::jsonb
            )");
        $stmt->execute([
            $schoolId, $studentId, $guardianId, $senderUserId,
            $typeCode, $direction, $phone, $content,
            $providerSid, $deliveryStatus,
            json_encode($meta, JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $e) {
        securityLog('TWILIO_LOG_ERROR', $e->getMessage());
    }
}

function normalizeWhatsAppPhone($value) {
    $value = trim((string)$value);
    $value = preg_replace('/^whatsapp:/i', '', $value);
    if ($value === '') return '';
    if ($value[0] !== '+') $value = '+' . $value;
    return preg_replace('/[^0-9\+]/', '', $value);
}

function sendTwilioWhatsAppDirect($to, $body) {
    $sid   = getenv('TWILIO_ACCOUNT_SID');
    $token = getenv('TWILIO_AUTH_TOKEN');
    $from  = getenv('TWILIO_FROM_NUMBER');
    if (!$sid || !$token || !$from) {
        return ['ok' => false, 'error' => 'Missing Twilio credentials', 'sid' => null];
    }

    $url     = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $payload = http_build_query([
        'From' => "whatsapp:$from",
        'To'   => "whatsapp:" . normalizeWhatsAppPhone($to),
        'Body' => $body
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        return ['ok' => false, 'error' => ($err ?: "HTTP $httpCode"), 'sid' => null];
    }

    $json = json_decode($response, true);
    return ['ok' => true, 'error' => null, 'sid' => $json['sid'] ?? null];
}

function connectRedis() {
    $redis = new Redis();
    $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
    if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);
    $redis->select((int)(getenv('REDIS_DB') ?: 0));
    return $redis;
}

function processJob($job, $conn, $redis, $delayQueue, &$lastSend, $sendDelay) {
    $to           = $job['to'] ?? '';
    $body         = $job['body'] ?? '';
    $schoolId     = $job['school_id'] ?? null;
    $studentId    = $job['student_id'] ?? null;
    $guardianId   = $job['guardian_id'] ?? null;
    $senderUserId = $job['sender_user_id'] ?? null;
    $typeCode     = $job['type_code'] ?? 'OUTBOUND';
    $retries      = (int)($job['retries'] ?? 0);

    // FIX: Dedup por número+contenido en ventana de 30s para evitar envenenamiento de cola
    $dedupKey = 'twilio:dedup:' . md5($to . '|' . $body);
    if ($redis->get($dedupKey)) {
        securityLog('TWILIO_DEDUP_SKIP', "Skipped duplicate to $to within 30s window");
        return;
    }

    // FIX: Leaky Bucket rate limiter para no exceder límites de Twilio
    $now = microtime(true);
    $timeSinceLast = $now - $lastSend;
    if ($timeSinceLast < $sendDelay) {
        usleep((int)(($sendDelay - $timeSinceLast) * 1000000));
    }
    $lastSend = microtime(true);

    $send = sendTwilioWhatsAppDirect($to, $body);

    if ($send['ok']) {
        // Marcar dedup para evitar duplicados por 30 segundos
        $redis->setex($dedupKey, 30, '1');
        logTwilioMessage($conn, $schoolId, $typeCode, 'OUTBOUND', $to, $body,
            ['action' => 'worker_sent'],
            $studentId, $guardianId, $senderUserId, $send['sid'], 'SENT');
        securityLog('TWILIO_WORKER_SENT', "SID: {$send['sid']} To: $to");
        return;
    }

    $maxRetries = 5;
    if ($retries < $maxRetries) {
        $job['retries'] = $retries + 1;
        $delayMs = min(pow(2, $retries) * 1000, 30000); // Backoff exponencial hasta 30 s
        $nextTry = microtime(true) + ($delayMs / 1000);
        $redis->zAdd($delayQueue, $nextTry, json_encode($job, JSON_UNESCAPED_UNICODE));
        securityLog('TWILIO_WORKER_RETRY', "To: $to Retry: {$job['retries']} Delay: {$delayMs}ms Error: {$send['error']}");
    } else {
        logTwilioMessage($conn, $schoolId, $typeCode, 'OUTBOUND', $to, $body,
            ['action' => 'worker_failed', 'error' => $send['error']],
            $studentId, $guardianId, $senderUserId, null, 'FAILED_PERMANENT');
        securityLog('TWILIO_WORKER_DEAD_LETTER', "To: $to Error: {$send['error']}");
    }
}

/* ============================================================
   Main Loop
   ============================================================ */
$conn = $pdo;
$conn->exec("SET app.current_role = 'SUPER_RECTOR'");
$redis = connectRedis();
$mainQueue   = 'queue:twilio';
$delayQueue  = 'queue:twilio:delayed';

// FIX: Leaky Bucket rate limiter config
$rateLimit = (int)(getenv('TWILIO_RATE_LIMIT') ?: 10); // mensajes por segundo
$sendDelay = 1.0 / $rateLimit;
$lastSend = microtime(true) - $sendDelay;

securityLog('TWILIO_WORKER_START', "Worker initialized. Rate: {$rateLimit}/s | Queues: {$mainQueue}, {$delayQueue}");

$shutdown = false;
$iterations = 0;

pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

while (!$shutdown) {
    try {
        pcntl_signal_dispatch();

        // 1. Reintentar trabajos atrasados
        $now = microtime(true);
        $delayed = $redis->zRangeByScore($delayQueue, 0, $now, ['limit' => [0, 1]]);
        if (!empty($delayed)) {
            $jobJson = $delayed[0];
            $redis->zRem($delayQueue, $jobJson);
            $job = json_decode($jobJson, true);
            if ($job) {
                processJob($job, $conn, $redis, $delayQueue, $lastSend, $sendDelay);
                $redis->set('worker:twilio:last_heartbeat', time(), 600);
            }
            continue;
        }

        // 2. Esperar nuevo trabajo (max 1 s)
        $result = $redis->blPop($mainQueue, 1);
        if ($result && isset($result[1])) {
            $job = json_decode($result[1], true);
            if ($job) {
                processJob($job, $conn, $redis, $delayQueue, $lastSend, $sendDelay);
                $redis->set('worker:twilio:last_heartbeat', time(), 600);
            }
        }
    } catch (Exception $e) {
        securityLog('TWILIO_WORKER_FATAL', $e->getMessage());
        sleep(5);
    }

    // FIX: Forzar GC y monitorear memoria en vez de matar el proceso
    if (++$iterations % 1000 === 0) {
        gc_collect_cycles();
        $memPeak = memory_get_peak_usage(true) / 1024 / 1024;
        if ($memPeak > 256) {
            securityLog('TWILIO_MEMORY_LIMIT', "Peak {$memPeak}MB > 256MB. Graceful restart.");
            exit(0);
        }
    }
}

securityLog('TWILIO_WORKER_STOP', "Twilio worker shutting down gracefully");
exit(0);
