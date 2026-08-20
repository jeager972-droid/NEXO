<?php
/**
 * =============================================================================
 * workers/worker_twilio.php — Worker de envío de WhatsApp (Outbox Pattern).
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Consume trabajos de la cola `queue:twilio` y de la cola con score
 * `queue:twilio:delayed`, envía mensajes WhatsApp a través de la API de Twilio,
 * actualiza delivery_status en twilio_messages y maneja reintentos con backoff
 * exponencial. Implementa deduplicación por 30s y leaky bucket rate limiter.
 *
 * FLUJO GENERAL
 * -------------
 *   Redis queue:twilio / queue:twilio:delayed
 *        │
 *        ▼
 *   processJob($job, $conn, $redis, ...)
 *        │
 *   ├── dedup check
 *   ├── rate limit sleep
 *   ├── sendTwilioWhatsAppSmart()
 *   │    ├── intenta texto libre
 *   │    └── fallback a template si 63016/63015
 *   └── UPDATE twilio_messages (SENT o FAILED_PERMANENT)
 *        │
 *   requeue con delay exponencial si falla
 *
 * USO DE REDIS AQUÍ
 * -----------------
 * Redis actúa como broker y coordinador del worker Twilio:
 *   - queue:twilio                : trabajos de mensajes pendientes.
 *   - queue:twilio:delayed        : trabajos con reintento programado (sorted set).
 *   - twilio:dedup:<hash>         : cache de deduplicación por 30s.
 *   - worker:twilio:last_heartbeat : señal de vida del worker.
 * El worker espera bloqueado (blPop) hasta 1s entre iteraciones; si hay trabajo,
 * lo procesa y actualiza el estado en PostgreSQL.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - db.php : conexión PDO ($pdo).
 *   - lib/twilio.php : normalizeWhatsAppPhone, getTwilioStatusCallbackUrl,
 *                      sendTwilioDirect, logTwilioMessage.
 *   - Redis : colas queue:twilio y queue:twilio:delayed.
 *
 * Es utilizado por:
 *   - Rutas que llaman enqueueTwilioJob()/rPush('queue:twilio', ...).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../redis.php';
require_once __DIR__ . '/../lib/twilio.php'; // normalizeWhatsAppPhone, getTwilioStatusCallbackUrl, sendTwilioDirect, logTwilioMessage

/**
 * Escribe un evento de seguridad del worker a stderr.
 *
 * @param string $event Tipo de evento.
 * @param string $details Detalles.
 * @return void
 */
function securityLog($event, $details = '') {
    $fallbackMsg = sprintf("[%s] [EVENT:%s] [DETAILS:%s]\n", gmdate('Y-m-d H:i:s'), $event, $details);
    file_put_contents('php://stderr', $fallbackMsg);
}

/**
 * Construye el payload x-www-form-urlencoded para la API Messages de Twilio.
 *
 * @param string $to Número destino normalizado.
 * @param string $body Cuerpo del mensaje.
 * @param string|null $templateSid SID de template (fallback 24h window).
 * @param array|null $templateVars Variables del template.
 * @return array Payload listo para http_build_query.
 */
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

/**
 * Ejecuta POST a la API Messages de Twilio con cURL.
 *
 * @param array $payload Payload form-urlencoded.
 * @return array {ok, error, sid, twilio_code?}.
 */
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
 * Envía mensaje WhatsApp intentando texto libre; si falla por 63016/63015
 * (fuera de ventana de 24h o sandbox), reintenta con template configurado.
 *
 * @param string $to Número destino.
 * @param string $body Cuerpo del mensaje.
 * @param string $typeCode Código de tipo (no usado directamente, legacy).
 * @return array Resultado del envío.
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
        return ['ok' => false, 'error' => "[$twilioCode] Fuera de ventana de 24h. Configura TWILIO_WHATSAPP_TEMPLATE_SID como variable de entorno.", 'sid' => null];
    }

    return $send;
}

/**
 * Alias hacia atrás para compatibilidad.
 *
 * @param string $to Número destino.
 * @param string $body Cuerpo del mensaje.
 * @return array Resultado del envío.
 */
function sendTwilioWhatsAppDirect($to, $body) {
    return sendTwilioWhatsAppSmart($to, $body);
}

/**
 * Procesa un trabajo de Twilio: envía mensaje, actualiza DB y maneja reintentos.
 *
 * @param array $job Trabajo decodificado de Redis.
 * @param PDO $conn Conexión PDO.
 * @param Redis $redis Conexión Redis.
 * @param string $delayQueue Nombre de la cola ZSET para reintentos.
 * @param float $lastSend Timestamp del último envío (por referencia).
 * @param float $sendDelay Mínimo intervalo entre envíos (leaky bucket).
 * @return void
 *
 * Efectos secundarios:
 *   - Actualiza twilio_messages con SENT o FAILED_PERMANENT.
 *   - Reencola en $delayQueue con backoff exponencial hasta 5 intentos.
 *   - Setea clave dedup por 30s en Redis tras envío exitoso.
 */
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
        // Nota: El contexto RLS se setea con SET LOCAL dentro de transacción
        // justo antes de las queries, no aquí. Con PgBouncer transaction
        // pooling, set_config(..., false) se pierde entre conexiones.
    }

    // FIX: Dedup por número+contenido en ventana de 30s para evitar envenenamiento de cola
    $dedupKey = 'twilio:dedup:' . md5($to . '|' . $body);
    if ($redis->get($dedupKey)) {
        securityLog('TWILIO_DEDUP_SKIP', "Skipped duplicate to $to within 30s window");
        return;
    }

    // FIX C5: Límite diario por número de teléfono para controlar costo económico.
    // Máximo 10 SMS/día por destinatario. Configurable vía TWILIO_MAX_DAILY_PER_PHONE.
    $maxDailyPerPhone = (int)(getenv('TWILIO_MAX_DAILY_PER_PHONE') ?: 10);
    $todayKey = 'twilio:daily:' . $to . ':' . date('Ymd');
    $dailyCount = (int)$redis->get($todayKey);
    if ($dailyCount >= $maxDailyPerPhone) {
        securityLog('TWILIO_DAILY_LIMIT_SKIP', "Skipped to $to: $dailyCount/$maxDailyPerPhone SMS today");
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

        // FIX C5: Incrementar contador diario por número (expira a medianoche)
        $ttl = strtotime('tomorrow') - time();
        $redis->setex($todayKey, max(1, $ttl), (string)($dailyCount + 1));

        // FIX (PgBouncer): SET LOCAL dentro de transacción para RLS
        try {
            $conn->exec("BEGIN");
            if ($schoolId) {
                $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$schoolId) . ", true)");
                $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
            }

            if (!empty($job['message_id'])) {
                $upd = $conn->prepare("UPDATE twilio_messages SET delivery_status = 'SENT', provider_message_sid = ?, metadata_json = ?::jsonb WHERE twilio_message_id = ?");
                $upd->execute([$send['sid'], json_encode(['action' => 'worker_sent'], JSON_UNESCAPED_UNICODE), $job['message_id']]);
            } else {
                logTwilioMessage($conn, $schoolId, $typeCode, 'OUTBOUND', $to, $body,
                    ['action' => 'worker_sent'],
                    $studentId, $guardianId, $senderUserId, $send['sid'], 'SENT');
            }
            $conn->exec("COMMIT");
        } catch (Exception $e) {
            try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
            securityLog('TWILIO_WORKER_UPD_FAIL', $e->getMessage());
            throw $e;
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
        // FIX (PgBouncer): SET LOCAL dentro de transacción para RLS
        try {
            $conn->exec("BEGIN");
            if ($schoolId) {
                $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$schoolId) . ", true)");
                $conn->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
            }
            if (!empty($job['message_id'])) {
                $upd = $conn->prepare("UPDATE twilio_messages SET delivery_status = 'FAILED_PERMANENT', metadata_json = ?::jsonb WHERE twilio_message_id = ?");
                $upd->execute([json_encode(['action' => 'worker_failed', 'error' => $send['error']], JSON_UNESCAPED_UNICODE), $job['message_id']]);
            } else {
                logTwilioMessage($conn, $schoolId, $typeCode, 'OUTBOUND', $to, $body,
                    ['action' => 'worker_failed', 'error' => $send['error']],
                    $studentId, $guardianId, $senderUserId, null, 'FAILED_PERMANENT');
            }
            $conn->exec("COMMIT");
        } catch (Exception $e) {
            try { $conn->exec("ROLLBACK"); } catch (Exception $ignore) {}
            securityLog('TWILIO_WORKER_UPD_FAIL', $e->getMessage());
            throw $e;
        }
        $redis->rPush('queue:twilio:dlq', json_encode($job, JSON_UNESCAPED_UNICODE));
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
    $redis = getRedisConnection();
    if (!$redis) {
        throw new Exception('Redis unavailable on startup');
    }
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

// FIX (CIRCUIT BREAKER): Límite absoluto de envíos por hora para prevenir
// flood runaway. Si se supera, el worker entra en modo "tripped": deja de
// procesar jobs, espera a la siguiente ventana horaria, y resetea. Los jobs
// quedan en la cola (no se pierden) y se procesan en la siguiente ventana.
// Configurable vía TWILIO_MAX_SENDS_PER_HOUR (default: 500).
// VF-022: Contador distribuido en Redis para que múltiples instancias
// respeten el límite global, no por-instancia.
$maxSendsPerHour = max(1, (int)(getenv('TWILIO_MAX_SENDS_PER_HOUR') ?: 500));
$redisHourKey = 'twilio:sends:hour:' . date('YmdH'); // Clave rotativa por hora

securityLog('TWILIO_WORKER_START', "Worker initialized. Rate: {$rateLimit}/s | Max/hour: {$maxSendsPerHour} (distributed) | Queues: {$mainQueue}, {$delayQueue}");

$shutdown = false;
$iterations = 0;

pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

while (!$shutdown) {
    try {
        pcntl_signal_dispatch();

        // VF-022: Circuit breaker distribuido via Redis
        $currentHourKey = 'twilio:sends:hour:' . date('YmdH');
        // Resetear clave si cambió la hora
        if ($currentHourKey !== $redisHourKey) {
            $redisHourKey = $currentHourKey;
        }

        // VF-022: Verificar límite global en Redis
        $globalSends = (int)$redis->get($redisHourKey);
        if ($globalSends >= $maxSendsPerHour) {
            securityLog('TWILIO_CIRCUIT_BREAKER_TRIPPED', "global_sends={$globalSends} >= max={$maxSendsPerHour}. Pausing 60s.");
            sleep(60);
            continue;
        }

        // 1. Reintentar trabajos atrasados
        $now = microtime(true);
        $delayed = $redis->zRangeByScore($delayQueue, 0, $now, ['limit' => [0, 1]]);
        if (!empty($delayed)) {
            $jobJson = $delayed[0];
            $redis->zRem($delayQueue, $jobJson);
            $job = json_decode($jobJson, true);
            if ($job) {
                processJob($job, $conn, $redis, $delayQueue, $lastSend, $sendDelay);
                // VF-022: Incrementar contador distribuido en Redis (atómico)
                $newCount = $redis->incr($redisHourKey);
                if ($newCount === 1) $redis->expire($redisHourKey, 7200); // TTL 2h
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
                // VF-022: Incrementar contador distribuido en Redis (atómico)
                $newCount = $redis->incr($redisHourKey);
                if ($newCount === 1) $redis->expire($redisHourKey, 7200); // TTL 2h
                $redis->set('worker:twilio:last_heartbeat', time(), 600);
            }
        }
    } catch (Exception $e) {
        securityLog('TWILIO_WORKER_FATAL', $e->getMessage());
        try { $redis = getRedisConnection(); } catch (Exception $re) { sleep(5); continue; }
        if (!$redis) { sleep(5); continue; }
        continue;
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
