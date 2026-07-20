<?php
/**
 * NEXO Boot Check — Valida variables de entorno críticas antes de arrancar
 * Si falta algo, el sistema falla closed (no arranca).
 */

$required = [
    'DATABASE_URL'       => 'URL de conexión PostgreSQL (o PGHOST/PGDATABASE/PGUSER/PGPASSWORD)',
    'NEXO_AES_KEY'       => 'Clave AES-256-GCM para cifrado de datos sensibles',
    'REDISHOST'          => 'Host de Redis para colas y rate limiting',
    'CORS_ALLOW_ORIGINS' => 'Orígenes exactos para CORS (sin wildcards)',
];

$missing = [];
foreach ($required as $key => $desc) {
    $val = getenv($key);
    if ($val === false || trim($val) === '') {
        $missing[] = "$key ($desc)";
    }
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
