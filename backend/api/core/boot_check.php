<?php
/**
 * =============================================================================
 * boot_check.php — Validación de variables críticas antes del arranque.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Revisa las variables de entorno obligatorias para que la API no arranque
 * con configuración incompleta (fail-closed). Además valida que exista al
 * menos un mecanismo de firma JWT (RS256 con JWT_PRIVATE_KEY/JWT_PUBLIC_KEY
 * o HS256 con JWT_SECRET) y emite advertencias para variables recomendadas.
 *
 * Variables requeridas:
 *   - DATABASE_URL (o PGHOST/PGDATABASE/PGUSER/PGPASSWORD)
 *   - NEXO_AES_KEY (clave AES-256-GCM para cifrado edge)
 *   - REDISHOST (Redis para colas, rate limit y cache)
 *   - CORS_ALLOW_ORIGINS (orígenes exactos permitidos)
 *
 * Variables recomendadas:
 *   - JWT_ISSUER, JWT_AUDIENCE
 *
 * Si falta alguna requerida, responde HTTP 503 y termina la ejecución.
 *
 * Es utilizado por:
 *   - api.php al inicio de cada petición.
 */

$required = [
    'DATABASE_URL'       => 'URL de conexión PostgreSQL (o PGHOST/PGDATABASE/PGUSER/PGPASSWORD)',
    'NEXO_AES_KEY'       => 'Clave AES-256-GCM para cifrado de datos sensibles',
    'CORS_ALLOW_ORIGINS' => 'Orígenes exactos para CORS (sin wildcards)',
];

$missing = [];
foreach ($required as $key => $desc) {
    $val = getenv($key);
    if ($val === false || trim($val) === '') {
        $missing[] = "$key ($desc)";
    }
}

$aesKey = getenv('NEXO_AES_KEY');
if ($aesKey !== false && trim($aesKey) !== '' && strlen($aesKey) !== 32) {
    $missing[] = 'NEXO_AES_KEY (debe tener exactamente 32 bytes)';
}

// Redis puede configurarse con REDIS_URL (prioridad) o con REDISHOST.
$redisUrl  = getenv('REDIS_URL');
$redisHost = getenv('REDISHOST');
if (($redisUrl === false || trim($redisUrl) === '') && ($redisHost === false || trim($redisHost) === '')) {
    $missing[] = 'REDIS_URL o REDISHOST (configuración de Redis)';
}

// JWT: require RS256 keys (JWT_PRIVATE_KEY + JWT_PUBLIC_KEY) OR HMAC secret (JWT_SECRET)
$jwtPrivate = getenv('JWT_PRIVATE_KEY');
$jwtPublic  = getenv('JWT_PUBLIC_KEY');
$jwtSecret  = getenv('JWT_SECRET');
$hasRS256   = ($jwtPrivate !== false && trim($jwtPrivate) !== '') && ($jwtPublic !== false && trim($jwtPublic) !== '');
$hasHMAC    = ($jwtSecret !== false && trim($jwtSecret) !== '');
if (!$hasRS256 && !$hasHMAC) {
    $missing[] = 'JWT_PRIVATE_KEY + JWT_PUBLIC_KEY (RS256) o JWT_SECRET (HS256) — se necesita al menos un método de firma JWT';
}

// HMAC secret para cadena de auditoría (VF-017)
$hmacSecret = getenv('APP_NEXO_HMAC_SECRET') ?: getenv('NEXO_HMAC_SECRET');
if ($hmacSecret === false || trim($hmacSecret) === '' || trim($hmacSecret) === 'default-secret-change-me') {
    $missing[] = 'APP_NEXO_HMAC_SECRET (secret para cadena de auditoría HMAC — no debe ser default)';
}

// Metrics secret para /metrics (VF-017)
$metricsSecret = getenv('METRICS_SECRET_KEY');
if ($metricsSecret === false || trim($metricsSecret) === '') {
    $missing[] = 'METRICS_SECRET_KEY (secret para autenticación de /metrics)';
}

// Validación opcional pero recomendada
$recommended = [
    'JWT_ISSUER'         => 'Issuer del JWT',
    'JWT_AUDIENCE'       => 'Audience del JWT',
];

foreach ($recommended as $key => $desc) {
    $val = getenv($key);
    if ($val === false || trim($val) === '') {
        error_log("[NEXO BOOT WARN] $key no configurado: $desc");
    }
}

if (!empty($missing)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'fatal',
        'message' => 'Environment misconfigured',
        'missing' => $missing
    ]);
    exit;
}
