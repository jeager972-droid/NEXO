<?php
$dsn = "pgsql:host=autorack.proxy.rlwy.net;port=23144;dbname=railway";
$pdo = new PDO($dsn, "postgres", "DGFoPhbUpjGTNkRjKSKpStJWvAqtYaBz");
$stmt = $pdo->query("SELECT role_name FROM roles");
$roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
print_r($roles);
