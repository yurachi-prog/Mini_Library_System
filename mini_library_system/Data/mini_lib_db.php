<?php
// Database connection using PDO for Postgres

$host = getenv("DB_HOST");   // Render Postgres host
$db   = getenv("DB_NAME");   // Database name
$user = getenv("DB_USER");   // Database user
$pass = getenv("DB_PASS");   // Database password
$port = getenv("DB_PORT");   // Usually 5432

try {
    // Connect to Postgres
    $conn = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);

    // Set error mode to exception for better debugging
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Optional: confirm connection
    // echo "Connected successfully!";
} catch (PDOException $e) {
    // If connection fails, show error
    die("Connection failed: " . $e->getMessage());
}
?>
