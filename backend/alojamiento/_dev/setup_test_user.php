<?php
require_once __DIR__ . '/../db.php';

$email = 'admin@nexo.edu';
$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    // 1. Asegurar que existen roles
    $pdo->exec("INSERT INTO roles (nombre) VALUES ('RECTOR'), ('COORDINADOR'), ('DOCENTE') ON CONFLICT DO NOTHING");
    
    // 2. Obtener ID del rol RECTOR
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE nombre = 'RECTOR' LIMIT 1");
    $stmt->execute();
    $rolId = $stmt->fetchColumn();

    // 3. Crear usuario de prueba
    $stmt = $pdo->prepare("
        INSERT INTO personal (institucion_id, rol_id, documento, nombre, telefono_whatsapp, email, password_hash, estado)
        VALUES (1, ?, '12345678', 'Administrador de Prueba', '573243607948', ?, ?, 'ACTIVO')
        ON CONFLICT (documento) DO UPDATE SET password_hash = EXCLUDED.password_hash, email = EXCLUDED.email
    ");
    
    $stmt->execute([$rolId, $email, $hash]);

    echo "✅ Usuario de prueba creado:\n";
    echo "Email: $email\n";
    echo "Password: $password\n";
    echo "Hash: $hash\n";
    echo "Teléfono: +573243607948 (Asignado para pruebas de Twilio)\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
