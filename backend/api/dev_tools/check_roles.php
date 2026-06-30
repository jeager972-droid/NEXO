<?php
require_once 'db.php';
try {
    $stmt = $pdo->query("SELECT * FROM roles");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($roles, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo $e->getMessage();
}
