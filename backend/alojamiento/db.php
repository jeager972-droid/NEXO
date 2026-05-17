<?php
$databaseUrl = getenv('DATABASE_URL');
$host = getenv('PGHOST');
$port = getenv('PGPORT');
$dbname = getenv('PGDATABASE');
$user = getenv('PGUSER');
$pass = getenv('PGPASSWORD');

// Prioridad a DATABASE_URL si existe
if ($databaseUrl) {
    $dbparts = parse_url($databaseUrl);
    if ($dbparts) {
        $host = $dbparts['host'];
        $port = $dbparts['port'] ?? '5432';
        $user = $dbparts['user'];
        $pass = $dbparts['pass'];
        $dbname = ltrim($dbparts['path'], '/');
    }
}

if (!$host || !$dbname) {
    die(json_encode(['status' => 'error', 'message' => 'Faltan variables de entorno de base de datos']));
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [
        // FIX (SRE-1): EMULATE_PREPARES=true elimina prepared statements del servidor,
        // haciendo cada query autocontenida. Requerido para PgBouncer pool_mode=transaction.
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // FIX (SRE-1): DISCARD ALL destruye variables de sesión residuales (set_config)
    // que podrían filtrarse de un request anterior reutilizando la misma conexión persistente.
    // Esto mitiga Tenant Leakage cuando PgBouncer no resetea el backend.
    $pdo->exec("DISCARD ALL");

    // Al cerrar el request, sanitizamos la conexión antes de devolverla al pool.
    register_shutdown_function(function () use ($pdo) {
        try {
            $pdo->exec("DISCARD ALL");
        } catch (Throwable $e) {
            // Silenciar: la conexión puede estar rota; no arrojar en shutdown.
        }
    });
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
    exit;
}
?>
