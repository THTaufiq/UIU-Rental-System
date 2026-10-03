<?php
// Database Connection Configuration (MySQLi ONLY)
$host     = 'localhost';
$db       = 'uiu_rental_system';
$user     = 'root';
$pass     = '';
$charset  = 'utf8mb4';

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        die("MySQLi Connection Failed: " . $conn->connect_error);
    }
    $conn->set_charset($charset);
}

