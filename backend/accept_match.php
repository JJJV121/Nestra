<?php
/**
 * accept_match.php
 * Endpoint for a user to explicitly accept their best match
 */
include 'db_connect.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$me = intval($_SESSION['user_id']);
$match_id = intval($_POST['match_id'] ?? 0);

if (!$match_id) {
    echo json_encode(['error' => 'No match ID provided']);
    exit;
}

// Auto-create table
$conn->query("CREATE TABLE IF NOT EXISTS match_accepts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    match_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_pair (user_id, match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$stmt = $conn->prepare("INSERT IGNORE INTO match_accepts (user_id, match_id) VALUES (?, ?)");
$stmt->bind_param("ii", $me, $match_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
}
else {
    echo json_encode(['error' => $stmt->error]);
}

$stmt->close();
$conn->close();
?>
