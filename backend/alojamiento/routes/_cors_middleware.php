<?php
// _cors_middleware.php
$allowedOriginsStr = getenv('CORS_ALLOW_ORIGINS') ?: '';
$allowedOrigins = array_filter(explode(',', $allowedOriginsStr));
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

$isAllowed = false;

if ($origin) {
    if (in_array($origin, $allowedOrigins)) {
        $isAllowed = true;
    }
}

// Si el origen de la petición está en nuestra lista blanca, lo permitimos
if ($isAllowed && $origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true'); // Crucial para enviar cookies HttpOnly
    header('Access-Control-Max-Age: 86400'); // Cachea el preflight por 1 día
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
