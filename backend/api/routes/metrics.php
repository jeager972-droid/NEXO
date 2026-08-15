<?php
/**
 * =============================================================================
 * routes/metrics.php — Endpoint de métricas Prometheus para observabilidad.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone GET /metrics en formato Prometheus exposition. Recopila métricas de:
 *   - Salud de PostgreSQL (up, conexiones activas/idle).
 *   - Heartbeats de workers (twilio, biometric; audit solo si AUDIT_WORKER_ENABLED=1)
 *     usando un único comando MGET.
 *   - Longitud de colas Redis (biometric_ingest, twilio; audit_logs condicional).
 *   - Métricas de negocio (login attempts, eventos biométricos, Twilio, alertas
 *     de riesgo, eventos de pánico).
 *   - Uso de disco.
 *
 * La autenticación es opcional mediante METRICS_SECRET_KEY en cabecera X-Metrics-Key
 * o query param ?key=.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - $conn : conexión PDO.
 *   - getRedisConnection() de _auth_middleware.php.
 *
 * Es utilizado por:
 *   - Prometheus / Grafana u otro scraper de métricas.
 */

global $cleanPath;

if ($cleanPath === '/metrics') {
    $metricsKey = getenv('METRICS_SECRET_KEY') ?: '';
    $providedKey = $_SERVER['HTTP_X_METRICS_KEY'] ?? ($_GET['key'] ?? '');
    // VF-014: Auth obligatoria — si no hay METRICS_SECRET_KEY configurada, denegar acceso
    if ($metricsKey === '' || !hash_equals($metricsKey, $providedKey)) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        exit('# Unauthorized');
    }

    $metrics = [];
    global $conn;
    $now = time();

    // Helper para emitir métricas
    function emit($name, $type, $help, $values) {
        global $metrics;
        $metrics[] = "# HELP $name $help";
        $metrics[] = "# TYPE $name $type";
        foreach ($values as $v) {
            $metrics[] = is_array($v) ? ($name . $v[0] . ' ' . $v[1]) : ($name . ' ' . $v);
        }
    }

    // ── Database ──
    try {
        $dbUp = 1;
        $activeConns = $conn->query("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()")->fetchColumn();
        $idleConns = $conn->query("SELECT count(*) FROM pg_stat_activity WHERE state = 'idle'")->fetchColumn();
    } catch (Exception $e) {
        $dbUp = 0;
        $activeConns = 0;
        $idleConns = 0;
    }
    emit('nexo_db_up', 'gauge', 'Database connectivity', [$dbUp]);
    emit('nexo_db_connections_active', 'gauge', 'Active PostgreSQL connections', [(int)$activeConns]);
    emit('nexo_db_connections_idle', 'gauge', 'Idle PostgreSQL connections', [(int)$idleConns]);

    // ── Workers ──
    // Un solo MGET para todos los heartbeats, reduciendo comandos Redis.
    try {
        $redis = getRedisConnection();
        $workers = [
            'twilio' => 'worker:twilio:last_heartbeat',
            'biometric' => 'worker:biometric:last_heartbeat',
        ];
        if (getenv('AUDIT_WORKER_ENABLED') === '1') {
            $workers['audit'] = 'worker:audit:last_heartbeat';
        }
        $values = $redis->mGet(array_values($workers));
        $idx = 0;
        foreach ($workers as $name => $key) {
            $hb = (int)($values[$idx] ?? 0);
            $age = $hb > 0 ? $now - $hb : 99999;
            emit("nexo_worker_up", 'gauge', "Worker $name health", [['{worker="' . $name . '"}', $age <= 300 ? 1 : 0]]);
            emit("nexo_worker_heartbeat_age_seconds", 'gauge', "Seconds since last heartbeat", [['{worker="' . $name . '"}', $age]]);
            $idx++;
        }
    } catch (Exception $e) {
        emit('nexo_worker_up', 'gauge', 'Worker health', [['{worker="all"}', 0]]);
    }

    // ── Queues ──
    try {
        $redis = getRedisConnection();
        $queues = [
            'biometric_ingest' => 'queue:biometric_ingest',
            'twilio' => 'queue:twilio',
        ];
        if (getenv('AUDIT_WORKER_ENABLED') === '1') {
            $queues['audit_logs'] = 'queue:audit_logs';
        }
        foreach ($queues as $name => $key) {
            $len = (int)$redis->lLen($key);
            emit('nexo_queue_length', 'gauge', 'Redis queue length', [['{queue="' . $name . '"}', $len]]);
        }
    } catch (Exception $e) {
        emit('nexo_queue_length', 'gauge', 'Redis queue length', [['{queue="all"}', -1]]);
    }

    // ── Business metrics ──
    try {
        $loginCount = $conn->query("SELECT COUNT(*) FROM rate_limits WHERE rl_key LIKE 'login:%'")->fetchColumn();
        emit('nexo_auth_logins_total', 'counter', 'Total login attempts', [(int)$loginCount]);
    } catch (Exception $e) {
        emit('nexo_auth_logins_total', 'counter', 'Total login attempts', [0]);
    }

    try {
        $bioToday = $conn->query("SELECT COUNT(*) FROM biometric_events WHERE event_timestamp >= (NOW() AT TIME ZONE 'America/Bogota')::date")->fetchColumn();
        emit('nexo_biometric_events_today', 'gauge', 'Biometric events today', [(int)$bioToday]);
    } catch (Exception $e) {
        emit('nexo_biometric_events_today', 'gauge', 'Biometric events today', [0]);
    }

    try {
        $sent = $conn->query("SELECT COUNT(*) FROM twilio_messages WHERE direction='OUTBOUND' AND delivery_status IN ('SENT','DELIVERED','READ')")->fetchColumn();
        $failed = $conn->query("SELECT COUNT(*) FROM twilio_messages WHERE direction='OUTBOUND' AND delivery_status IN ('FAILED','FAILED_PERMANENT','UNDELIVERED')")->fetchColumn();
        $pending = $conn->query("SELECT COUNT(*) FROM twilio_messages WHERE direction='OUTBOUND' AND delivery_status='QUEUED'")->fetchColumn();
        emit('nexo_twilio_sent_total', 'counter', 'Twilio messages sent', [(int)$sent]);
        emit('nexo_twilio_failed_total', 'counter', 'Twilio messages failed', [(int)$failed]);
        emit('nexo_twilio_pending', 'gauge', 'Twilio messages pending', [(int)$pending]);
    } catch (Exception $e) {
        emit('nexo_twilio_sent_total', 'counter', 'Twilio messages sent', [0]);
        emit('nexo_twilio_failed_total', 'counter', 'Twilio messages failed', [0]);
        emit('nexo_twilio_pending', 'gauge', 'Twilio messages pending', [0]);
    }

    try {
        $alertsToday = $conn->query("SELECT COUNT(*) FROM attendance_incidents WHERE detected_at >= (NOW() AT TIME ZONE 'America/Bogota')::date AND incident_type LIKE 'RISK_ALERT%'")->fetchColumn();
        emit('nexo_risk_alerts_today', 'gauge', 'Risk alerts today', [(int)$alertsToday]);
    } catch (Exception $e) {
        emit('nexo_risk_alerts_today', 'gauge', 'Risk alerts today', [0]);
    }

    try {
        $panicCount = $conn->query("SELECT COUNT(*) FROM school_panic_events WHERE triggered_at >= (NOW() AT TIME ZONE 'America/Bogota')::date - INTERVAL '30 days'")->fetchColumn();
        emit('nexo_panic_events_30d', 'gauge', 'Panic events last 30 days', [(int)$panicCount]);
    } catch (Exception $e) {
        emit('nexo_panic_events_30d', 'gauge', 'Panic events last 30 days', [0]);
    }

    // ── Disk ──
    $free = disk_free_space('.');
    $total = disk_total_space('.');
    $usedPct = $total > 0 ? round((1 - $free / $total) * 100, 2) : 0;
    emit('nexo_disk_used_percent', 'gauge', 'Disk usage percent', [$usedPct]);

    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $metrics) . "\n";
    exit;
}
