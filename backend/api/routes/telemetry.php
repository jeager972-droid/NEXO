<?php
/**
 * =============================================================================
 * routes/telemetry.php — Ingesta de telemetría del cliente.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Recibe lotes de eventos de telemetría desde el frontend (errores JS,
 * latencias, pings), valida la estructura, sanitiza PII y escribe en
 * system_telemetry. Es restringido: descarta campos sensibles y trunca strings.
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación.
 *   - $conn : conexión PDO.
 *
 * Es utilizado por:
 *   - Frontend: servicio de telemetría (web/desktop/mobile).
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';

// ============================================================================
// POST /telemetry — Ingesta validada y sanitizada de eventos de telemetría.
// ============================================================================
if ($cleanPath !== '/telemetry' || $method !== 'POST') return;

$actor = requireAuth();

// ── Validar estructura de la petición ─────────────────────
$events = $input['events'] ?? null;
if (!is_array($events) || empty($events)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'events[] requerido']));
}

$sessionId = $input['session_id'] ?? '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionId)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'session_id (UUID v4) inválido']));
}

$appVersion = substr(preg_replace('/[^a-zA-Z0-9.\-_]/', '', $input['app_version'] ?? 'unknown'), 0, 32);
$platform   = $input['platform'] ?? 'web';
if (!in_array($platform, ['web', 'desktop', 'android', 'ios'], true)) {
    $platform = 'web';
}

// ── Constantes de validación ───────────────────────────────
const ALLOWED_EVENT_TYPES = ['JS_ERROR', 'API_LATENCY', 'BIOMETRIC_LATENCY', 'APP_PING', 'RENDER_SLOW'];
const ALLOWED_SEVERITIES  = ['debug', 'info', 'warn', 'error'];
const PII_FIELDS = [
    'email', 'name', 'first_name', 'last_name', 'nombre', 'apellido',
    'document', 'cedula', 'documento', 'phone', 'telefono', 'celular',
    'student_id', 'user_id', 'actor_id', 'address', 'direccion',
    'ip', 'token', 'password', 'contraseña',
];
const MAX_EVENTS_PER_BATCH = 50;
const MAX_PAYLOAD_BYTES    = 4096;

// Limitar lote
$events = array_slice($events, 0, MAX_EVENTS_PER_BATCH);

// ── Prepared statement ─────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO system_telemetry
        (session_id, app_version, platform, event_type, severity, payload, user_agent, created_at)
    VALUES
        (:session_id, :app_version, :platform, :event_type, :severity, :payload::jsonb, :user_agent, NOW())
");

$userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
$accepted  = 0;
$rejected  = 0;

foreach ($events as $ev) {
    if (!is_array($ev)) { $rejected++; continue; }

    // Tipo de evento
    $eventType = strtoupper(trim($ev['type'] ?? ''));
    if (!in_array($eventType, ALLOWED_EVENT_TYPES, true)) { $rejected++; continue; }

    // Severidad
    $severity = strtolower(trim($ev['severity'] ?? 'info'));
    if (!in_array($severity, ALLOWED_SEVERITIES, true)) $severity = 'info';

    // ── Sanitización del payload: sin PII, sin valores gigantes ──
    $raw = is_array($ev['payload'] ?? null) ? $ev['payload'] : [];

    // Eliminar campos PII recursivamente (primer nivel y anidados)
    array_walk_recursive($raw, function (&$v, $k) {
        if (in_array(strtolower((string)$k), PII_FIELDS, true)) {
            $v = '[REDACTED]';
        }
    });
    foreach (PII_FIELDS as $f) {
        unset($raw[$f]);
    }

    // Normalizar paths: /estudiantes/123 → /estudiantes/:id
    if (isset($raw['path']) && is_string($raw['path'])) {
        $raw['path'] = preg_replace('/\/\d+/', '/:id', $raw['path']);
        $raw['path'] = substr($raw['path'], 0, 120);
    }

    // Truncar strings a 500 chars
    array_walk_recursive($raw, function (&$v) {
        if (is_string($v)) $v = mb_substr($v, 0, 500);
    });

    $payloadJson = json_encode($raw, JSON_UNESCAPED_UNICODE);
    if (strlen($payloadJson) > MAX_PAYLOAD_BYTES) { $rejected++; continue; }

    try {
        $stmt->execute([
            ':session_id'  => $sessionId,
            ':app_version' => $appVersion,
            ':platform'    => $platform,
            ':event_type'  => $eventType,
            ':severity'    => $severity,
            ':payload'     => $payloadJson,
            ':user_agent'  => $userAgent,
        ]);
        $accepted++;
    } catch (Throwable $e) {
        securityLog('TELEMETRY_INSERT_ERROR', $e->getMessage(), $actor['id'] ?? null);
        $rejected++;
    }
}

http_response_code(202);
echo json_encode([
    'status'   => 'accepted',
    'accepted' => $accepted,
    'rejected' => $rejected,
]);
exit;
