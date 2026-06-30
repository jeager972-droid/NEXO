<?php
require_once __DIR__ . '/db.php';
$sql = file_get_contents(__DIR__ . '/sql/student_tracking_schema.sql');
try {
    $pdo->exec($sql);
    echo "Tables created successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
