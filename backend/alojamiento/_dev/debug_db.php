<?php
$DB_HOST = getenv('MYSQLHOST') ?: 'localhost'; 
$DB_USER = getenv('MYSQLUSER') ?: 'root'; 
$DB_PASS = getenv('MYSQLPASSWORD') ?: ''; 
$DB_NAME = getenv('MYSQLDATABASE') ?: 'nexo'; 
$DB_PORT = getenv('MYSQLPORT') ?: '3306'; 

$conn = @mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$tables = ['estudiantes', 'salones', 'personal', 'roles', 'auditoria_global', 'instituciones'];
foreach ($tables as $table) {
    echo "\n--- Table: $table ---\n";
    $res = mysqli_query($conn, "SHOW COLUMNS FROM $table");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            echo "{$row['Field']} - {$row['Type']}\n";
        }
    } else {
        echo "Error: " . mysqli_error($conn) . "\n";
    }
}

echo "\n--- Admin Check ---\n";
$res = mysqli_query($conn, "SELECT id, nombre FROM roles");
while ($row = mysqli_fetch_assoc($res)) {
    echo "ID: {$row['id']} | Role: {$row['nombre']}\n";
}
?>
