<?php
/**
 * NEXO Boot Check — Valida variables de entorno críticas antes de arrancar
 * Si falta algo, el sistema falla closed (no arranca).
 */

$required = [
    'JWT_PRIVATE_KEY'   => 'Llave privada RS256 para firma de tokens',
    'JWT_PUBLIC_KEY'    => 'Llave pública RS256 para verificación de tokens',
    'DATABASE_URL'      => 'URL de conexión PostgreSQL (o PGHOST/PGDATABASE/PGUSER/PGPASSWORD)',
    'NEXO_AES_KEY'      => 'Clave AES-256-GCM para cifrado de datos sensibles',
    'REDISHOST'         => 'Host de Redis para colas y rate limiting',
];

$missing = [];
foreach ($required as $key => $desc) {
    $val = getenv($key);
    if ($val === false || trim($val) === '') {
        $missing[] = "$key ($desc)";
    }
}

// Validación opcional pero recomendada
$recommended = [
    'CORS_ALLOW_ORIGINS' => 'Orígenes exactos para CORS (sin wildcards)',
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
