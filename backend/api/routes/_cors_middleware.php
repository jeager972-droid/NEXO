<?php
/**
 * =============================================================================
 * _cors_middleware.php — Middleware de Cross-Origin Resource Sharing (CORS).
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Configura las cabeceras HTTP necesarias para permitir peticiones cross-origin
 * desde orígenes declarados en la variable de entorno CORS_ALLOW_ORIGINS, y
 * desde previews dinámicas de Vercel generadas para el proyecto NEXO. Además
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
$allowedOrigins = array_filter(array_map('trim', explode(',', $allowedOriginsStr)));
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

/**
 * Verifica si un origen está autorizado.
 *
 * Reglas, en orden de prioridad:
 *
 * 1. Lista blanca exacta (CORS_ALLOW_ORIGINS).
 *    Estos orígenes son configurados manualmente y se comparan con ===.
 *
 * 2. Excepción estricta para previews de Vercel del proyecto NEXO.
 *
 *    Vercel genera un subdominio aleatorio para cada preview, por ejemplo:
 *        https://nexo-gsug3f5v6-jeager972-droids-projects.vercel.app
 *    Ese dominio cambia en cada despliegue, por lo que no puede mantenerse en
 *    CORS_ALLOW_ORIGINS manualmente. Esta excepción acepta ÚNICAMENTE orígenes
 *    que cumplan TODAS estas condiciones:
 *        - Esquema https://
 *        - Host bajo .vercel.app
 *        - El subdominio empieza EXACTAMENTE con "nexo-"
 *        - El identificador de preview contiene solo letras, números y guiones
 *
 *    El patrón es:
 *        ^https://nexo-[a-zA-Z0-9-]+\.vercel\.app$
 *
 *    NOTAS DE SEGURIDAD CRÍTICAS:
 *    - Esta excepción existe ÚNICAMENTE porque Vercel genera previews dinámicas
 *      para el proyecto NEXO. No existe ninguna otra razón para ampliarla.
 *    - Únicamente se acepta el prefijo del proyecto ("nexo").
 *    - Ampliar este patrón a otros dominios (por ejemplo "*.vercel.app" u otros
 *      TLDs) sería una vulnerabilidad grave de CORS.
 *    - Jamás debe reemplazarse por "*.vercel.app".
 *    - Jamás debe responderse con "Access-Control-Allow-Origin: *" cuando se
 *      usan cookies o autenticación; por eso se refleja el origen exacto.
 *    - Este patrón está atado al proyecto NEXO. No copiar a otros proyectos sin
 *      entender las implicaciones.
 *
 * @param string $origin        Origen recibido en HTTP_ORIGIN.
 * @param array  $allowedOrigins Orígenes exactos de CORS_ALLOW_ORIGINS.
 * @return bool
 */
function _nexoIsOriginAllowed(string $origin, array $allowedOrigins): bool {
    // 1. Lista blanca exacta (configuración manual, sin wildcards).
    if (in_array($origin, $allowedOrigins, true)) {
        return true;
    }

    // 2. Excepción estricta: previews de Vercel para el proyecto NEXO.
    //    - ^https://          : solo conexiones seguras.
    //    - nexo-              : prefijo EXCLUSIVO del proyecto NEXO.
    //    - [a-zA-Z0-9-]+      : identificador aleatorio de Vercel; sin puntos.
    //    - \.vercel\.app$     : anclado exacto al dominio .vercel.app.
    if (preg_match('~^https://nexo-[a-zA-Z0-9-]+\.vercel\.app$~', $origin) === 1) {
        return true;
    }

    return false;
}

$isAllowed = false;

if ($origin) {
    $isAllowed = _nexoIsOriginAllowed($origin, $allowedOrigins);
}

// Si el origen de la petición está autorizado, lo permitimos.
// IMPORTANTE: el origen se refleja tal cual. NUNCA se usa "*".
if ($isAllowed && $origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true'); // Crucial para enviar cookies HttpOnly
    header('Access-Control-Max-Age: 86400'); // Cachea el preflight por 1 día
}

// INTERCEPTAR PETICIONES OPTIONS (Preflight CORS)
// Esto evita que lleguen al _auth_middleware y devuelvan 401
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // VF-042: Simplificado — ambas ramas del if/else original enviaban el
    // mismo header, por lo que la condición era redundante.
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header('Access-Control-Allow-Headers: ' . $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
    } else {
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization, X-Device-Token');
    }

    http_response_code(204); // No Content - Respuesta exitosa para el preflight
    exit;
}
