<?php
$host = 'localhost';
$db   = 'dso_queue_system';
$user = 'root'; // default XAMPP/WAMP user
$pass = '';     // default XAMPP/WAMP password
$charset = 'utf8mb4';

// 1. Connect without DB selected to ensure the database exists
try {
    $pdo_init = new PDO("mysql:host=$host;charset=$charset", $user, $pass);
    $pdo_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `$db`");
} catch (\PDOException $e) {
    die("Setup Error: Could not connect to MySQL or create database. " . $e->getMessage());
}

// 2. Connect to the actual database
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     
     // 3. Automatically import the schema if the users table doesn't exist
     $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
     if ($stmt->rowCount() == 0) {
         $sql = file_get_contents(__DIR__ . '/database.sql');
         if ($sql) {
             $pdo->exec($sql);
         }
     }
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>
