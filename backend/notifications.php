<?php
/**
 * notifications.php
 * Endpoint to sum unread messages for the logged in user.
 */
include 'db_connect.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unread' => 0]);
    exit;
}
$me = intval($_SESSION['user_id']);

// Force standard column existence
@$conn->query("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) DEFAULT 0");

// Get the latest unread message sender details and grand total
$res = $conn->query("SELECT sender_id FROM messages WHERE receiver_id = $me AND is_read = 0 ORDER BY sent_at DESC LIMIT 1");
$latest_sender = 0;
$unread_total = 0;

if ($res && $res->num_rows > 0) {
    $latest_sender = intval($res->fetch_assoc()['sender_id']);
    $tot = $conn->query("SELECT COUNT(*) as tot FROM messages WHERE receiver_id = $me AND is_read = 0")->fetch_assoc();
    $unread_total = intval($tot['tot']);
}

// Fetch sender details to create the smart redirect link
$sender_name = "Match";
if ($latest_sender > 0) {
    $u = $conn->query("SELECT fullname FROM users WHERE id = $latest_sender")->fetch_assoc();
    if ($u)
        $sender_name = $u['fullname'];
}

echo json_encode([
    'unread' => $unread_total,
    'sender_id' => $latest_sender,
    'sender_name' => $sender_name
]);
$conn->close();
?>
