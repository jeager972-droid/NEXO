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
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
    exit;
}
?>
