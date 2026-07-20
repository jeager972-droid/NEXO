<?php
/**
 * NEXO Twilio Worker — Outbox Pattern
 * Ejecutar bajo supervisor: php worker_twilio.php
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/twilio.php'; // normalizeWhatsAppPhone, getTwilioStatusCallbackUrl, sendTwilioDirect, logTwilioMessage

// securityLog se mantiene local: en el worker escribe a stderr, no a DB
function securityLog($event, $details = '') {
    $fallbackMsg = sprintf("[%s] [EVENT:%s] [DETAILS:%s]\n", gmdate('Y-m-d H:i:s'), $event, $details);
    file_put_contents('php://stderr', $fallbackMsg);
}

function buildTwilioPayload($to, $body, $templateSid = null, $templateVars = null) {
    $from = getenv('TWILIO_WHATSAPP_FROM') ?: getenv('TWILIO_FROM_NUMBER');
    $payload = [
        'From' => "whatsapp:" . normalizeWhatsAppPhone($from),
        'To'   => "whatsapp:" . normalizeWhatsAppPhone($to),
    ];

    if ($templateSid) {
        $payload['ContentSid'] = $templateSid;
        if ($templateVars) {
            $payload['ContentVariables'] = json_encode($templateVars, JSON_UNESCAPED_UNICODE);
        }
    } else {
        $payload['Body'] = $body;
    }

    $statusCallback = getTwilioStatusCallbackUrl();
    if ($statusCallback) {
        $payload['StatusCallback'] = $statusCallback;
        securityLog('TWILIO_STATUS_CALLBACK_SET', "URL: $statusCallback");
    } else {
        securityLog('TWILIO_STATUS_CALLBACK_MISSING', 'TWILIO_WEBHOOK_URL_BASE or APP_URL not set');
    }
    return $payload;
}

function sendTwilioWhatsAppRequest($payload) {
    $sid   = getenv('TWILIO_ACCOUNT_SID');
    $token = getenv('TWILIO_AUTH_TOKEN');
    if (!$sid || !$token) {
        return ['ok' => false, 'error' => 'Missing Twilio credentials', 'sid' => null];
    }
    $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => ($err ?: "HTTP $httpCode"), 'sid' => null];
    }

    $json = json_decode($response, true);
    // Detectar error 63016 (outside messaging window) u otros errores de Twilio
    if ($httpCode >= 400 || isset($json['code']) || isset($json['error_code'])) {
        $errorCode = $json['code'] ?? $json['error_code'] ?? $httpCode;
        $errorMsg  = $json['message'] ?? $json['error_message'] ?? ($err ?: "HTTP $httpCode");
        return ['ok' => false, 'error' => "[$errorCode] $errorMsg", 'sid' => null, 'twilio_code' => $errorCode];
    }

    return ['ok' => true, 'error' => null, 'sid' => $json['sid'] ?? null];
}

/**
 * Envía mensaje de WhatsApp. Intenta texto libre primero; si falla por 63016
 * (fuera de ventana de 24h), reintenta con template si está configurado.
 */
function sendTwilioWhatsAppSmart($to, $body, $typeCode = 'OUTBOUND') {
    // 1) Intentar mensaje de sesión (texto libre)
    $payload = buildTwilioPayload($to, $body);
    $send = sendTwilioWhatsAppRequest($payload);

    if ($send['ok']) return $send;

    $twilioCode = $send['twilio_code'] ?? '';

    // 2) Si es 63016 (outside window) o 63015 (sandbox), reintentar con template
    if (in_array($twilioCode, [63016, 63015])) {
        $templateSid = getenv('TWILIO_WHATSAPP_TEMPLATE_SID');
        if ($templateSid) {
            // Template con una sola variable {{1}} que recibe el body completo
            $templatePayload = buildTwilioPayload($to, $body, $templateSid, ['1' => $body]);
            $templateSend = sendTwilioWhatsAppRequest($templatePayload);
            if ($templateSend['ok']) {
                securityLog('TWILIO_TEMPLATE_FALLBACK_OK', "SID: {$templateSend['sid']} To: $to");
                return $templateSend;
            }
            return ['ok' => false, 'error' => 'Template fallback también falló: ' . $templateSend['error'], 'sid' => null];
        }
        return ['ok' => false, 'error' => "[$twilioCode] Fuera de ventana de 24h. Configura TWILIO_WHATSAPP_TEMPLATE_SID en Railway.", 'sid' => null];
    }

    return $send;
}

// Backwards compat
function sendTwilioWhatsAppDirect($to, $body) {
    return sendTwilioWhatsAppSmart($to, $body);
}

function connectRedis() {
    $redis = new Redis();
    // Timeout de 100ms para evitar bloqueos en workers de fondo
    $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379, 0.1);
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

    if ($schoolId) {
        try {
            // SECURITY-FIX: prepared statements — $schoolId viene de Redis (no confiable)
            $stmtSchool = $conn->prepare("SELECT set_config('app.current_school_id', ?, false)");
            $stmtSchool->execute([(string)$schoolId]);
            $stmtRole = $conn->prepare("SELECT set_config('app.current_role', ?, false)");
            $stmtRole->execute(['SYSTEM_WORKER']);
        } catch (Exception $e) {
            securityLog('WORKER_CONTEXT_SET_FAIL', $e->getMessage());
            return;
        }
    }

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
        
        if (!empty($job['message_id'])) {
            try {
                $upd = $conn->prepare("UPDATE twilio_messages SET delivery_status = 'SENT', provider_message_sid = ?, metadata_json = ?::jsonb WHERE twilio_message_id = ?");
                $upd->execute([$send['sid'], json_encode(['action' => 'worker_sent'], JSON_UNESCAPED_UNICODE), $job['message_id']]);
            } catch (Exception $e) {
                securityLog('TWILIO_WORKER_UPD_FAIL', $e->getMessage());
                throw $e; // Escalar al catch externo para forzar restart del supervisor
            }
        } else {
            logTwilioMessage($conn, $schoolId, $typeCode, 'OUTBOUND', $to, $body,
                ['action' => 'worker_sent'],
                $studentId, $guardianId, $senderUserId, $send['sid'], 'SENT');
        }
        
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
        if (!empty($job['message_id'])) {
            try {
                $upd = $conn->prepare("UPDATE twilio_messages SET delivery_status = 'FAILED_PERMANENT', metadata_json = ?::jsonb WHERE twilio_message_id = ?");
                $upd->execute([json_encode(['action' => 'worker_failed', 'error' => $send['error']], JSON_UNESCAPED_UNICODE), $job['message_id']]);
            } catch (Exception $e) {
                securityLog('TWILIO_WORKER_UPD_FAIL', $e->getMessage());
                throw $e; // Escalar al catch externo para forzar restart del supervisor
            }
        } else {
            logTwilioMessage($conn, $schoolId, $typeCode, 'OUTBOUND', $to, $body,
                ['action' => 'worker_failed', 'error' => $send['error']],
                $studentId, $guardianId, $senderUserId, null, 'FAILED_PERMANENT');
        }
        securityLog('TWILIO_WORKER_DEAD_LETTER', "To: $to Error: {$send['error']}");
    }
}

/* ============================================================
   Main Loop
   ============================================================ */
$conn = $pdo;
try {
    $conn->query("SELECT set_config('app.current_role', 'SYSTEM_WORKER', false)");
} catch (PDOException $e) {
    securityLog('WORKER_ROLE_SET_SKIP', $e->getMessage());
}

try {
    $redis = connectRedis();
} catch (Exception $e) {
    securityLog('TWILIO_WORKER_FATAL', "Failed to connect to Redis on startup: " . $e->getMessage());
    exit(1);
}

$mainQueue   = 'queue:twilio';
$delayQueue  = 'queue:twilio:delayed';

// FIX: Leaky Bucket rate limiter config
$rateLimit = max(1, (int)(getenv('TWILIO_RATE_LIMIT') ?: 10)); // mensajes por segundo
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
        exit(1); // Let supervisor restart with backoff
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
