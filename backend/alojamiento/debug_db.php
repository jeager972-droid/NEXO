<?php 
try { 
    $databaseUrl = getenv('DATABASE_URL'); 
    $pdo = new PDO($databaseUrl); 
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
    echo "Connected to PostgreSQL successfully"; 
} catch (PDOException $e) { 
    die("Connection failed: " . $e->getMessage()); 
} 
?>
