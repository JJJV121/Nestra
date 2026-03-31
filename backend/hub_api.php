<?php
/**
 * hub_api.php — Powers the Living Hub (Agreements, Chores, Expenses)
 * Takes ?with=MATCH_ID and ?action=...
 */
include 'db_connect.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$me = intval($_SESSION['user_id']);
$with = intval($_REQUEST['with'] ?? 0);
if (!$with) {
    echo json_encode(['error' => 'Missing match ID']);
    exit;
}

// Auto-create tables if they don't exist (no setup required)
$conn->query("CREATE TABLE IF NOT EXISTS chores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pair_hash VARCHAR(50),
    title VARCHAR(255),
    assigned_to INT,
    status VARCHAR(20) DEFAULT 'Pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pair_hash VARCHAR(50),
    payer_id INT,
    amount DECIMAL(10,2),
    description VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$pair_hash = min($me, $with) . '_' . max($me, $with);
$action = $_REQUEST['action'] ?? 'get_all';

// --- Actions ---
if ($action === 'add_chore') {
    $title = trim($_POST['title'] ?? '');
    $assigned_to = intval($_POST['assigned_to'] ?? 0);
    if ($title) {
        $stmt = $conn->prepare("INSERT INTO chores (pair_hash, title, assigned_to) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $pair_hash, $title, $assigned_to);
        $stmt->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'add_expense') {
    $desc = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    if ($desc && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO expenses (pair_hash, payer_id, amount, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sids", $pair_hash, $me, $amount, $desc);
        $stmt->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'toggle_chore') {
    $id = intval($_POST['id']);
    $conn->query("UPDATE chores SET status = IF(status='Pending', 'Done', 'Pending') WHERE id = $id AND pair_hash = '$pair_hash'");
    echo json_encode(['success' => true]);
    exit;
}

// --- Fetch Dashboard Data ---

// 1. Smart Agreement Generation
$surveys = [];
$res = $conn->query("SELECT user_id, sleep, cleanliness, noise, social FROM survey_responses WHERE user_id IN ($me, $with) ORDER BY submitted_at DESC");
$seen = [];
while ($row = $res->fetch_assoc()) {
    if (!isset($seen[$row['user_id']]))
        $seen[$row['user_id']] = $row;
}
$s_me = $seen[$me] ?? null;
$s_with = $seen[$with] ?? null;

$rules = [];
if ($s_me && $s_with) {
    // Sleep Rule
    if ($s_me['sleep'] == $s_with['sleep']) {
        if ($s_me['sleep'] == 'Early Riser')
            $rules[] = "🌅 <strong>Quiet hours</strong> start at 10 PM. Lights out early!";
        else if ($s_me['sleep'] == 'Night Owl')
            $rules[] = "🌙 <strong>Late nights</strong> are fine, but use headphones after midnight.";
        else
            $rules[] = "☀️ <strong>Sleep schedules</strong> are flexible, communicate daily if plans change.";
    }
    else {
        $rules[] = "🌓 <strong>Mixed schedules:</strong> Earplugs & sleep masks recommended. Strict quiet down between 11 PM and 7 AM.";
    }

    // Cleanliness Rule
    if ($s_me['cleanliness'] == $s_with['cleanliness']) {
        if ($s_me['cleanliness'] == 'Spotless' || $s_me['cleanliness'] == 'Very Clean')
            $rules[] = "✨ <strong>Cleanliness:</strong> Deep clean every Sunday. No dishes left in sink overnight.";
        else
            $rules[] = "🧹 <strong>Cleanliness:</strong> Keep common areas reasonably tidy. Weekly basic cleaning agreed.";
    }
    else {
        $rules[] = "⚖️ <strong>Cleanliness Compromise:</strong> All common areas must be clean. Private spaces can be flexible.";
    }

    // Social Rule
    if (($s_me['social'] == 'Introvert' || $s_me['social'] == 'Quiet') || ($s_with['social'] == 'Introvert' || $s_with['social'] == 'Quiet')) {
        $rules[] = "🤫 <strong>Guests:</strong> 24-hour notice required for guests. Protect the quiet zone.";
    }
    else {
        $rules[] = "🍕 <strong>Guests:</strong> Friends are welcome! Just give a heads up if more than 2 people are coming over.";
    }
}
else {
    $rules[] = "📝 Be respectful, clean up after yourself, and communicate openly.";
}

// 2. Chores List
$chores = [];
$res = $conn->query("SELECT c.*, u.fullname as assignee FROM chores c LEFT JOIN users u ON c.assigned_to = u.id WHERE c.pair_hash = '$pair_hash' ORDER BY c.created_at DESC");
while ($row = $res->fetch_assoc())
    $chores[] = $row;

// 3. Expenses & Splits
$expenses = [];
$total_me = 0;
$total_with = 0;
$res = $conn->query("SELECT e.*, u.fullname as payer FROM expenses e LEFT JOIN users u ON e.payer_id = u.id WHERE e.pair_hash = '$pair_hash' ORDER BY e.created_at DESC");
while ($row = $res->fetch_assoc()) {
    $expenses[] = $row;
    if ($row['payer_id'] == $me)
        $total_me += $row['amount'];
    else
        $total_with += $row['amount'];
}

$balance = ($total_me - $total_with) / 2; // Positive means '$with' owes '$me'
$owed_text = "All settled up! 🎉";
$owed_class = "settled"; // for UI coloring
if ($balance > 0) {
    $owed_text = "They owe you <strong>₹" . number_format($balance, 2) . "</strong>";
    $owed_class = "owed";
}
else if ($balance < 0) {
    $owed_text = "You owe them <strong>₹" . number_format(abs($balance), 2) . "</strong>";
    $owed_class = "owe";
}

// Fetch names
$names = [];
$res = $conn->query("SELECT id, fullname FROM users WHERE id IN ($me, $with)");
while ($r = $res->fetch_assoc())
    $names[$r['id']] = $r['fullname'];

echo json_encode([
    'rules' => $rules,
    'chores' => $chores,
    'expenses' => $expenses,
    'owed_text' => $owed_text,
    'owed_class' => $owed_class,
    'names' => $names,
    'me' => $me,
    'with' => $with
]);
$conn->close();
?>
