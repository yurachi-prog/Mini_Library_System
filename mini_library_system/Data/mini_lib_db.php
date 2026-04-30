<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "library_system_db";

//$conn = new mysqli($host, $user, $pass, $db);//-->
$conn = new mysqli("localhost", "root", "", "library_system_db", 3307);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
