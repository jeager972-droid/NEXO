<?php
// routes/metrics.php - Métricas Prometheus para observabilidad

global $cleanPath;

if ($cleanPath === '/metrics') {
    $metrics = [];
    global $conn;

    // Métricas HTTP — sin middleware real, emitimos 0 para no simular
    $metrics[] = '# HELP http_requests_total Total HTTP requests';
    $metrics[] = '# TYPE http_requests_total counter';
    $metrics[] = 'http_requests_total 0';

    $metrics[] = '# HELP http_request_duration_seconds HTTP request latency';
    $metrics[] = '# TYPE http_request_duration_seconds histogram';
    $metrics[] = 'http_request_duration_seconds_bucket{le="0.1"} 0';
    $metrics[] = 'http_request_duration_seconds_bucket{le="0.5"} 0';
    $metrics[] = 'http_request_duration_seconds_bucket{le="1.0"} 0';
    $metrics[] = 'http_request_duration_seconds_bucket{le="+Inf"} 0';
    $metrics[] = 'http_request_duration_seconds_sum 0';
    $metrics[] = 'http_request_duration_seconds_count 0';

    $metrics[] = '# HELP http_errors_5xx_total Total HTTP 5xx errors';
    $metrics[] = '# TYPE http_errors_5xx_total counter';
    $metrics[] = 'http_errors_5xx_total 0';

    // Autenticación real
    $metrics[] = '# HELP auth_logins_total Total login attempts';
    $metrics[] = '# TYPE auth_logins_total counter';
    try {
        $loginCount = $conn->query("SELECT COUNT(*) FROM rate_limits WHERE rl_key LIKE 'login:%'")->fetchColumn();
        $metrics[] = 'auth_logins_total ' . (int)$loginCount;
    } catch (Exception $e) {
        $metrics[] = 'auth_logins_total 0';
    }

    // Biométricos
    $metrics[] = '# HELP biometric_events_ingested_total Total biometric events ingested';
    $metrics[] = '# TYPE biometric_events_ingested_total counter';
    try {
        $bioCount = $conn->query("SELECT COUNT(*) FROM biometric_events")->fetchColumn();
        $metrics[] = 'biometric_events_ingested_total ' . (int)$bioCount;
    } catch (Exception $e) {
        $metrics[] = 'biometric_events_ingested_total 0';
    }

    // Twilio real
    $metrics[] = '# HELP twilio_messages_sent_total Total Twilio messages sent';
    $metrics[] = '# TYPE twilio_messages_sent_total counter';
    $metrics[] = '# HELP twilio_messages_failed_total Total Twilio messages failed';
    $metrics[] = '# TYPE twilio_messages_failed_total counter';
    try {
        $sent = $conn->query("SELECT COUNT(*) FROM twilio_messages WHERE direction='OUTBOUND' AND delivery_status='SENT'")->fetchColumn();
        $failed = $conn->query("SELECT COUNT(*) FROM twilio_messages WHERE direction='OUTBOUND' AND delivery_status='FAILED_PERMANENT'")->fetchColumn();
        $metrics[] = 'twilio_messages_sent_total ' . (int)$sent;
        $metrics[] = 'twilio_messages_failed_total ' . (int)$failed;
    } catch (Exception $e) {
        $metrics[] = 'twilio_messages_sent_total 0';
        $metrics[] = 'twilio_messages_failed_total 0';
    }

    // Redis colas
    try {
        $redis = new Redis();
        $redis->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
        if ($pass = getenv('REDIS_PASSWORD')) $redis->auth($pass);

        $auditQueueLen = $redis->lLen('queue:audit_logs');
        $twilioQueueLen = $redis->lLen('queue:twilio');
        $twilioDelayedLen = $redis->zCard('queue:twilio:delayed');

        $metrics[] = '# HELP redis_queue_length Current length of Redis queues';
        $metrics[] = '# TYPE redis_queue_length gauge';
        $metrics[] = 'redis_queue_length{queue="audit_logs"} ' . (int)$auditQueueLen;
        $metrics[] = 'redis_queue_length{queue="twilio"} ' . (int)$twilioQueueLen;
        $metrics[] = 'redis_queue_length{queue="twilio_delayed"} ' . (int)$twilioDelayedLen;
    } catch (Exception $e) {
        $metrics[] = '# Redis metrics unavailable';
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $metrics) . "\n";
    exit;
}
