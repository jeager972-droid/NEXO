<?php
$databaseUrl = getenv('DATABASE_URL');
$host = getenv('PGHOST');
$port = getenv('PGPORT');
$dbname = getenv('PGDATABASE');
$user = getenv('PGUSER');
$pass = getenv('PGPASSWORD');

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
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);

    if ($hmacSecret = getenv('APP_NEXO_HMAC_SECRET') ?: getenv('NEXO_HMAC_SECRET')) {
        $stmt = $pdo->prepare("SELECT set_config('app.nexo_hmac_secret', ?, false)");
        $stmt->execute([$hmacSecret]);
    }
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
    exit;
}
?>
