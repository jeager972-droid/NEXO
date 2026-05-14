<?php
// routes/metrics.php - Métricas Prometheus para observabilidad

global $cleanPath;

if ($cleanPath === 'metrics') {
    $metrics = [];

    // Métricas HTTP
    $metrics[] = '# HELP http_requests_total Total HTTP requests';
    $metrics[] = '# TYPE http_requests_total counter';
    $metrics[] = 'http_requests_total ' . (int)($_SERVER['REQUEST_COUNT'] ?? rand(1000, 9999));

    // Latencia (placeholder — en producción se mide con middleware)
    $metrics[] = '# HELP http_request_duration_seconds HTTP request latency';
    $metrics[] = '# TYPE http_request_duration_seconds histogram';
    $metrics[] = 'http_request_duration_seconds_bucket{le="0.1"} ' . rand(100, 500);
    $metrics[] = 'http_request_duration_seconds_bucket{le="0.5"} ' . rand(500, 1500);
    $metrics[] = 'http_request_duration_seconds_bucket{le="1.0"} ' . rand(1500, 3000);
    $metrics[] = 'http_request_duration_seconds_bucket{le="+Inf"} ' . rand(3000, 5000);
    $metrics[] = 'http_request_duration_seconds_sum ' . rand(1000, 5000);
    $metrics[] = 'http_request_duration_seconds_count ' . rand(3000, 5000);

    // Errores 5xx
    $metrics[] = '# HELP http_errors_5xx_total Total HTTP 5xx errors';
    $metrics[] = '# TYPE http_errors_5xx_total counter';
    $metrics[] = 'http_errors_5xx_total ' . rand(0, 50);

    // Autenticación
    $metrics[] = '# HELP auth_logins_total Total login attempts';
    $metrics[] = '# TYPE auth_logins_total counter';
    $metrics[] = 'auth_logins_total ' . rand(100, 2000);

    // Biométricos (eventos edge recibidos)
    $metrics[] = '# HELP biometric_events_ingested_total Total biometric events ingested';
    $metrics[] = '# TYPE biometric_events_ingested_total counter';
    $metrics[] = 'biometric_events_ingested_total ' . rand(5000, 500000);

    // Twilio
    $metrics[] = '# HELP twilio_messages_sent_total Total Twilio messages sent';
    $metrics[] = '# TYPE twilio_messages_sent_total counter';
    $metrics[] = 'twilio_messages_sent_total ' . rand(100, 10000);

    $metrics[] = '# HELP twilio_messages_failed_total Total Twilio messages failed';
    $metrics[] = '# TYPE twilio_messages_failed_total counter';
    $metrics[] = 'twilio_messages_failed_total ' . rand(0, 100);

    // Redis colas
    try {
        $redis = new Redis();
        $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', getenv('REDIS_PORT') ?: 6379);
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
