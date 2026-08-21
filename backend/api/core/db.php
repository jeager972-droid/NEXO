<?php
/**
 * =============================================================================
 * db.php — Inicialización de conexión PDO a PostgreSQL.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Lee configuración de base de datos desde variables de entorno (DATABASE_URL
 * o PGHOST/PGPORT/PGDATABASE/PGUSER/PGPASSWORD), crea un PDO con prepared
 * statements nativos, errores como excepciones y timeout de 5s, y configura
 * el parámetro `app.nexo_hmac_secret` si está disponible (usado por triggers
 * o funciones SQL para firmar/verificar hashes internos).
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - Variables de entorno: DATABASE_URL, PGHOST, PGPORT, PGDATABASE, PGUSER,
 *     PGPASSWORD, APP_NEXO_HMAC_SECRET, NEXO_HMAC_SECRET.
 *   - Extensión pdo_pgsql.
 *
 * Es utilizado por:
 *   - api.php (asigna $conn = $pdo).
 *   - workers/worker_*.php (incluyen db.php directamente).
 */

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
        // Emulate prepares: PgBouncer/Supabase transaction-pool mode does not
        // support server-side prepared statements across different backends.
        // PDO still binds/escapes parameters, so SQL injection protection remains.
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);

    if ($hmacSecret = getenv('APP_NEXO_HMAC_SECRET') ?: getenv('NEXO_HMAC_SECRET')) {
        $stmt = $pdo->prepare("SELECT set_config('app.nexo_hmac_secret', ?, false)");
        $stmt->execute([$hmacSecret]);
    }

    // FIX C4: statement_timeout para evitar que queries lentas agoten el pool.
    // PgBouncer query_timeout=30000 (30s) protege a nivel de pool; esto protege
    // a nivel de PostgreSQL. En modo transaction pooling, SET LOCAL no persiste
    // entre conexiones, así que se ejecuta en cada nueva conexión PDO.
    $pdo->exec("SET statement_timeout = '30s'");
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
    exit;
}
?>
