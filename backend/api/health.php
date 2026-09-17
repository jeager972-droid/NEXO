<?php
/**
 * =============================================================================
 * health.php — Health check real sin autenticación.
 * =============================================================================
 *
 * Verifica dependencias críticas: DB (SELECT 1), Redis (PING) y heartbeats
 * de workers. Retorna JSON con HTTP 200 si todo OK, 503 si algo falla.
 * Cada verificación tiene timeout de 2s.
 */

header('Content-Type: application/json; charset=utf-8');

$response = [
    'status'  => 'ok',
    'db'      => false,
    'redis'   => false,
    'workers' => [],
];
$allHealthy = true;

// 1. Database health — SELECT 1 con timeout de 2s
$dbOk = false;
try {
    $databaseUrl = getenv('DATABASE_URL');
    if ($databaseUrl) {
        $dsn = str_replace(['postgres://', 'postgresql://'], 'pgsql:', $databaseUrl);
        if (strpos($dsn, 'pgsql:') === 0 && strpos($dsn, '?') === false) {
            $parts = parse_url(str_replace(['postgres://', 'postgresql://'], 'http://', $databaseUrl));
            if ($parts) {
                $host = $parts['host'] ?? '127.0.0.1';
                $port = $parts['port'] ?? 6543;
                if (strpos($host, 'supabase.com') !== false || strpos($host, 'pooler') !== false) {
                    $port = 6543;
                }
                $dbname = ltrim($parts['path'] ?? '', '/');
                $user = $parts['user'] ?? '';
                $pass = $parts['pass'] ?? '';
                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};options='--connect_timeout=2'";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2,
                ]);
            }
        }
    } else {
        $host = getenv('PGHOST') ?: '127.0.0.1';
        $port = getenv('PGPORT') ?: 6543;
        $dbname = getenv('PGDATABASE') ?: 'nexo';
        $user = getenv('PGUSER') ?: 'nexo';
        $pass = getenv('PGPASSWORD') ?: '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};options='--connect_timeout=2'";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);
    }
    if (isset($pdo)) {
        $pdo->query("SELECT 1");
        $dbOk = true;
    }
} catch (Exception $e) {
    $dbOk = false;
}
$response['db'] = $dbOk;
if (!$dbOk) $allHealthy = false;

// 2. Redis health — PING con timeout de 2s
$redisOk = false;
$redisConn = null;
try {
    if (class_exists('Redis')) {
        require_once __DIR__ . '/core/redis.php';
        $config = _nexoResolveRedisConfig();
        $redisConn = new Redis();
        $context = [];
        if ($config['tls']) {
            $context['stream'] = [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $config['host'],
                'SNI_enabled' => true,
            ];
        }
        $host = $config['tls'] ? 'tls://' . $config['host'] : $config['host'];
        $connected = $redisConn->connect($host, $config['port'], 2.0, null, 0, 2.0, $context);
        if ($connected) {
            if ($config['pass'] !== '') {
                if ($config['user'] !== '' && $config['user'] !== 'default') {
                    $redisConn->auth(['user' => $config['user'], 'pass' => $config['pass']]);
                } else {
                    $redisConn->auth($config['pass']);
                }
            }
            if (isset($config['db']) && $config['db'] !== 0) {
                $redisConn->select((int)$config['db']);
            }
            $redisConn->ping();
            $redisOk = true;
        }
    }
} catch (Exception $e) {
    $redisOk = false;
}
$response['redis'] = $redisOk;
if (!$redisOk) $allHealthy = false;

// 3. Worker heartbeats — verificar todos los workers daemon activos.
// Umbral 300s: evasion_detector heartbea cada ~120s (EVASION_CHECK_INTERVAL),
// un umbral menor generaría falsos negativos. audit_worker es opcional
// (solo corre con AUDIT_WORKER_ENABLED=1) y no debe tumbar el health.
$workerKeys = [
    'twilio'            => 'worker:twilio:last_heartbeat',
    'biometric'         => 'worker:biometric:last_heartbeat',
    'absence_detector'  => 'worker:absence_detector:last_heartbeat',
    'evasion_detector'  => 'worker:evasion_detector:last_heartbeat',
    'permission_status' => 'worker:permission_status:last_heartbeat',
    'device_health'     => 'worker:device_health:last_heartbeat',
    'teacher_alerts'    => 'worker:teacher_alerts:last_heartbeat',
    'absence_followup'  => 'worker:absence_followup:last_heartbeat',
];
if (getenv('AUDIT_WORKER_ENABLED') === '1') {
    $workerKeys['audit'] = 'worker:audit:last_heartbeat';
}
foreach ($workerKeys as $name => $key) {
    $response['workers'][$name] = false;
}

if ($redisOk && $redisConn) {
    try {
        $values = $redisConn->mGet(array_values($workerKeys));
        $idx = 0;
        $now = time();
        foreach ($workerKeys as $name => $key) {
            $heartbeat = $values[$idx] ?? false;
            $idx++;
            if ($heartbeat !== false && is_numeric($heartbeat)) {
                $age = $now - (int)$heartbeat;
                $response['workers'][$name] = ($age <= 300);
            }
        }
    } catch (Exception $e) {
        // Workers ya quedan en false
    }
}

foreach ($response['workers'] as $name => $alive) {
    if (!$alive) {
        $allHealthy = false;
        break;
    }
}

http_response_code($allHealthy ? 200 : 503);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
