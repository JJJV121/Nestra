<?php
/**
 * db_connect.php
 * Reads DB credentials from environment variables (set on Render dashboard).
 * Falls back to local XAMPP defaults for development.
 */

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'nestra_db';
$port = intval(getenv('DB_PORT') ?: 3308);

$conn = new mysqli($host, $user, $pass, $dbname, $port);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'DB connection failed: ' . $conn->connect_error]));
}

$conn->set_charset('utf8mb4');
?>