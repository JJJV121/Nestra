<?php
/**
 * chat_fetch.php — Fetch messages between current user and their match
 * GET: receiver_id, since (optional timestamp for polling)
 */
include 'db_connect.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$me = intval($_SESSION['user_id']);
$receiver_id = intval($_GET['receiver_id'] ?? 0);
$since = $_GET['since'] ?? '2000-01-01 00:00:00';

if (!$receiver_id) {
    echo json_encode(['error' => 'Missing receiver_id']);
    exit;
}

// Fetch messages between the two users after 'since'
$sql = "SELECT m.id, m.sender_id, m.receiver_id, m.message, m.sent_at,
               u.fullname AS sender_name
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE ((m.sender_id = ? AND m.receiver_id = ?)
            OR (m.sender_id = ? AND m.receiver_id = ?))
          AND m.sent_at > ?
        ORDER BY m.sent_at ASC
        LIMIT 100";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiiis", $me, $receiver_id, $receiver_id, $me, $since);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $row['id'],
        'sender_id' => intval($row['sender_id']),
        'message' => $row['message'],
        'sent_at' => $row['sent_at'],
        'sender_name' => $row['sender_name'],
        'is_mine' => intval($row['sender_id']) === $me,
    ];
}

$stmt->close();

// Mark these messages as read since they are being fetched by the receiver
@$conn->query("UPDATE messages SET is_read = 1 WHERE receiver_id = $me AND sender_id = $receiver_id AND is_read = 0");

$conn->close();
echo json_encode(['messages' => $messages, 'me' => $me]);
?>
