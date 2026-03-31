<?php
session_start();
include 'db_connect.php';
header('Content-Type: application/json');

$message = strtolower(trim($_POST['message'] ?? ''));
if (!$message) {
    echo json_encode(['reply' => "I didn't catch that. Could you repeat?"]);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$reply = "";

// Simple intent routing based on keywords + db data
if (strpos($message, 'match') !== false) {
    if (!$user_id) {
        $reply = "Please <a href='login.html'>login</a> to see your matches.";
    }
    else {
        $stmt = $conn->prepare("SELECT u.fullname FROM match_accepts ma JOIN users u ON ma.match_id = u.id WHERE ma.user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $matches = [];
        while ($row = $res->fetch_assoc()) {
            $matches[] = $row['fullname'];
        }
        if (count($matches) > 0) {
            $reply = "Your accepted matches are: " . implode(", ", $matches) . ".";
        }
        else {
            $reply = "You don't have any accepted matches yet. Check the <a href='match_result.html'>Match Result</a> page!";
        }
    }
}
elseif (strpos($message, 'survey') !== false) {
    if (!$user_id) {
        $reply = "You must be logged in to take the survey.";
    }
    else {
        $stmt = $conn->prepare("SELECT id FROM survey_responses WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $reply = "You have already completed your survey! You can view your matches now.";
        }
        else {
            $reply = "You haven't completed the survey yet. <a href='survey.html'>Take it now</a>.";
        }
    }
}
elseif (strpos($message, 'stat') !== false || strpos($message, 'user') !== false) {
    $res = $conn->query("SELECT COUNT(*) as c FROM users");
    $count = $res->fetch_assoc()['c'];
    $reply = "Nestra currently has $count amazing users looking for roommates!";
}
elseif (strpos($message, 'hello') !== false || strpos($message, 'hi ') !== false || $message === 'hi') {
    $reply = "Hello! 👋 How can I assist you with Nestra today?";
}
elseif (strpos($message, 'help') !== false) {
    $reply = "I can help you check your matches, survey status, or Nestra stats. Just ask!";
}
else {
    // Basic catch-all based on predefined array from the frontend translated to backend
    $qa = [
        'thank' => "You're welcome! 😊",
        'bye' => "Goodbye! Have a great day! 👋",
        'contact' => "You can reach support at support@nestra.com.",
        'password' => "Use the 'Forgot Password' link on the Login page to reset your password.",
        'about' => "Nestra is a smart roommate matching platform using AI.",
    ];

    $found = false;
    foreach ($qa as $key => $ans) {
        if (strpos($message, $key) !== false) {
            $reply = $ans;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $reply = "Sorry, I'm still learning and didn't quite understand that. 🤔 Ask about your matches, survey, or our stats!";
    }
}

echo json_encode(['reply' => $reply]);
$conn->close();
?>
