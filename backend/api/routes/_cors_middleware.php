<?php
/**
 * =============================================================================
 * _cors_middleware.php — Middleware de Cross-Origin Resource Sharing (CORS).
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Configura las cabeceras HTTP necesarias para permitir peticiones cross-origin
 * desde orígenes declarados en la variable de entorno CORS_ALLOW_ORIGINS. Además
 * intercepta las peticiones OPTIONS (preflight) respondiendo con 204 No Content
 * antes de que lleguen al resto de la lógica de la API.
 *
 * FLUJO GENERAL
 * -------------
 *   HTTP Request
 *        │
 *        ▼
 *   ¿Origin en CORS_ALLOW_ORIGINS?
 *        │
 *        ├── SI ──► Agregar Access-Control-Allow-Origin, Allow-Credentials, Max-Age
 *        │
 *        ▼
 *   ¿METHOD === OPTIONS?
 *        │
 *        └── SI ──► Agregar Allow-Methods / Allow-Headers ──► 204 No Content exit
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - Variable de entorno CORS_ALLOW_ORIGINS (lista separada por comas).
 *   - Superglobales $_SERVER (HTTP_ORIGIN, REQUEST_METHOD, etc.).
 *
 * Es utilizado por:
 *   - backend/api/api.php lo incluye al inicio de cada petición para permitir
 *     que el frontend React (alojado en otro dominio) se comunique con la API.
 *
 * POSIBLES EXCEPCIONES
 * --------------------
 *   - Ninguna. Este middleware es pasivo: si no hay origen o no está permitido
 *     simplemente no agrega cabeceras CORS (el navegador bloqueará la respuesta).
 */

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
