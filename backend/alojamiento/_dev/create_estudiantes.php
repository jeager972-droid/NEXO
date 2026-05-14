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

$sql = "CREATE TABLE IF NOT EXISTS estudiantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento VARCHAR(50) UNIQUE NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    telefono_whatsapp VARCHAR(20),
    salon_id INT NOT NULL,
    institucion_id INT NOT NULL,
    n_lista INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (salon_id) REFERENCES salones(id),
    FOREIGN KEY (institucion_id) REFERENCES instituciones(id)
)";

if (mysqli_query($conn, $sql)) {
    echo "✅ Tabla 'estudiantes' creada exitosamente.\n";
} else {
    echo "❌ Error al crear la tabla: " . mysqli_error($conn) . "\n";
}

// También verificamos si 'inasistencias_diarias' tiene la columna 'documento_estudiante_hash' o similar
// Pero por ahora nos enfocamos en 'estudiantes' para que los comandos funcionen.
?>