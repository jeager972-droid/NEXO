<?php
// _cors_middleware.php
$allowedOrigins = array_filter(explode(',', getenv('CORS_ALLOW_ORIGINS') ?: ''));

// Si el origen de la petición está en nuestra lista blanca, lo permitimos
if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true'); // Crucial para enviar cookies HttpOnly
    header('Access-Control-Max-Age: 86400'); // Cachea el preflight por 1 día
} elseif (empty($allowedOrigins) && isset($_SERVER['HTTP_ORIGIN'])) {
    // Fallback if no allowed origins are configured
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

// INTERCEPTAR PETICIONES OPTIONS (Preflight CORS)
// Esto evita que lleguen al _auth_middleware y devuelvan 401
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    } else {
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    }
    
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header('Access-Control-Allow-Headers: ' . $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
    } else {
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization, X-Device-Token');
    }
    
    http_response_code(204); // No Content - Respuesta exitosa para el preflight
    exit;
}
