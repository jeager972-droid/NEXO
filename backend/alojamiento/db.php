<?php 
try { 
    $databaseUrl = getenv('DATABASE_URL');
    
    if ($databaseUrl) {
        // Railway proporciona DATABASE_URL en formato postgresql://user:pass@host:port/db
        $dbparts = parse_url($databaseUrl);

        $host = $dbparts['host'];
        $port = $dbparts['port'] ?? '6432';
        $user = $dbparts['user'];
        $pass = $dbparts['pass'];
        $dbname = ltrim($dbparts['path'], '/');

        // Construir DSN exacto para PostgreSQL PDO
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        $pdo = new PDO($dsn, $user, $pass);
    } else {
        // Fallback a variables individuales (estándar de Railway)
        $host = getenv('PGHOST');
        $port = getenv('PGPORT');
        $dbname = getenv('PGDATABASE');
        $user = getenv('PGUSER');
        $pass = getenv('PGPASSWORD');

        if (!$host || !$dbname) {
            throw new PDOException("Faltan variables de entorno de base de datos.");
        }

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        $pdo = new PDO($dsn, $user, $pass);
    }
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
    
} catch (PDOException $e) { 
    // LOG DE ERROR CRÍTICO A STDERR (Visible en Railway)
    $logEntry = sprintf("[%s] [DATABASE_ERROR] %s\n", gmdate('Y-m-d H:i:s'), $e->getMessage());
    file_put_contents('php://stderr', $logEntry);

    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Error de conexión a la base de datos institucional',
        'debug_hint' => 'Revisa los logs de Railway para el mensaje técnico.'
    ]);
    exit;
} 
?>
