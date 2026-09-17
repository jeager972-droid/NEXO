<?php
/**
 * routes/events.php — SSE: stream de notificaciones en tiempo real (V-110).
 *
 *   GET /events/stream (Authorization: Bearer)
 *
 * Emite Server-Sent Events con las notificaciones nuevas del usuario
 * (polling interno a BD cada 2 s, keep-alive cada 15 s). La PWA lo consume
 * con EventSource; el polling REST sigue existiendo como fallback.
 *
 * Nota: PHP-FPM sostiene un worker por conexión — aceptable para la escala
 * institucional prevista; para >cientos de conexiones simultáneas por nodo
 * se evaluará un relay por Redis pub/sub.
 */

if ($cleanPath !== '/events/stream') return;

if ($method !== 'GET') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'GET only']));
}

$authUser = requireAuth();
$schoolId = $authUser['school_id'];
$userId = $authUser['id'];
// Liberar la conexión de pool: requireAuth deja una transacción abierta para
// RLS. En el loop, cada poll abre/cierra su propia tx (PgBouncer transaction
// pooling: sin esto, una sesión SSE retendría un slot de pool 5 min).
try { if ($conn->inTransaction()) $conn->commit(); } catch (Exception $ignore) {}

// Cabeceras SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // desactiva buffering de nginx
while (ob_get_level() > 0) ob_end_clean();
set_time_limit(0);
ignore_user_abort(false);

$MAX_SECONDS = 300; // el cliente reconecta (EventSource lo hace automático)
$started = time();
$sent = [];
$lastKeepAlive = microtime(true);

echo "retry: 3000\n\n";

while (time() - $started < $MAX_SECONDS) {
    if (connection_aborted()) break;

    try {
        $conn->exec("BEGIN");
        $conn->exec("SELECT set_config('app.current_school_id', " . $conn->quote((string)$schoolId) . ", true)");
        $stmt = $conn->prepare("
            SELECT notification_id, title, message, type, metadata_json, created_at
            FROM notifications
            WHERE user_id = ? AND school_id = ?
              AND created_at > NOW() - INTERVAL '15 minutes'
            ORDER BY created_at ASC LIMIT 100
        ");
        $stmt->execute([$userId, $schoolId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $conn->exec("COMMIT");
    } catch (Throwable $e) {
        try { if ($conn->inTransaction()) $conn->rollBack(); } catch (Exception $ignore) {}
        echo ": db-error\n\n"; @flush(); sleep(5); continue;
    }

    foreach ($rows as $n) {
        if (isset($sent[$n['notification_id']])) continue;
        $sent[$n['notification_id']] = true;
        echo "id: {$n['notification_id']}\n";
        echo "event: notification\n";
        echo 'data: ' . json_encode([
            'id' => $n['notification_id'],
            'title' => $n['title'],
            'message' => $n['message'],
            'type' => $n['type'],
            'metadata' => json_decode($n['metadata_json'] ?? 'null'),
            'created_at' => $n['created_at'],
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    if ((microtime(true) - $lastKeepAlive) >= 15) {
        echo ": keep-alive\n\n";
        $lastKeepAlive = microtime(true);
    }
    @flush();
    usleep(2000000); // 2 s
}

echo "event: close\n";
echo "data: {\"reason\":\"max_age\"}\n\n";
@flush();
exit;
