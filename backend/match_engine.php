<?php
/**
 * Nestra AI Matching Engine
 * match_engine.php
 *
 * POST to this endpoint after a user submits the survey.
 * Returns the top matches ranked by weighted compatibility score.
 *
 * Fields: sleep, cleanliness, work, social, room, diet, pets, noise, needs
 */
include 'db_connect.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in.']);
    exit;
}

$user_id = intval($_SESSION['user_id']);

// ── Fetch current user's latest survey ─────────────────────────────────────
$sql = "SELECT * FROM survey_responses WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current) {
    echo json_encode(['error' => 'Please complete the survey first.']);
    exit;
}

// ── Exclude Rejected Matches ───────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS match_rejects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    match_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_pair (user_id, match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ── Fetch all other users' latest surveys (ignoring rejected ones) ─────────
$sql = "SELECT sr.*, u.fullname, u.email
        FROM survey_responses sr
        JOIN users u ON sr.user_id = u.id
        WHERE sr.user_id != ? 
          AND sr.user_id NOT IN (SELECT match_id FROM match_rejects WHERE user_id = ?)
        ORDER BY sr.submitted_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$others = $stmt->get_result();
$stmt->close();

// ── Weighted Similarity Scoring ────────────────────────────────────────────
// Higher weight = more important for compatibility
$weights = [
    'sleep' => 20, // Sleep schedule is critical
    'cleanliness' => 18, // Cleanliness is highly important
    'noise' => 15, // Noise tolerance
    'social' => 14, // Social/introvert style
    'diet' => 12, // Diet preference
    'room' => 10, // Room type
    'pets' => 6, // Pet preference
    'work' => 5, // Work style (less friction)
];
$total_weight = array_sum($weights);

// ── Partial match rules (for non-exact but compatible values) ──────────────
function partial_score($field, $a, $b)
{
    // Define compatibility pairs that give partial credit (0.5)
    $compatible_pairs = [
        'sleep' => [['Early Riser', 'Morning Person'], ['Night Owl', 'Late Night']],
        'cleanliness' => [['Moderate', 'Clean'], ['Clean', 'Very Clean']],
        'noise' => [['Moderate', 'Low'], ['Low', 'Quiet']],
        'social' => [['Introvert', 'Ambivert'], ['Ambivert', 'Extrovert']],
        'pets' => [['No pets', 'Okay with pets']],
        'diet' => [['Vegetarian', 'Vegan'], ['Non-Vegetarian', 'Flexitarian']],
        'work' => [['Remote', 'Work from Home'], ['Office', 'Hybrid']],
    ];

    if ($a === $b)
        return 1.0; // Exact match = full score

    if (isset($compatible_pairs[$field])) {
        foreach ($compatible_pairs[$field] as $pair) {
            if ((in_array($a, $pair) && in_array($b, $pair))) {
                return 0.5; // Compatible but not identical
            }
        }
    }
    return 0.0; // Incompatible
}

// ── Room assignment logic ──────────────────────────────────────────────────
$rooms = ['A', 'B', 'C', 'D', 'E'];
$wings = ['Quiet Wing', 'Garden View', 'East Block', 'West Wing', 'Social Wing'];

function assignRoom($user_id_a, $user_id_b)
{
    global $rooms, $wings;
    $seed = ($user_id_a + $user_id_b) % 5;
    $floor = (($user_id_a * $user_id_b) % 4) + 1;
    $room_num = (($user_id_a + $user_id_b) % 20) + 1;
    return [
        'room' => "Room " . $rooms[$seed] . $room_num . " – " . $floor . ordinal($floor) . " Floor",
        'wing' => $wings[$seed],
    ];
}

function ordinal($n)
{
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $s[($v - 20 % 10)] ?? $s[$v] ?? $s[0];
}

// ── Reason generator ──────────────────────────────────────────────────────
function generateReason($current, $other, $weights)
{
    $reasons = [];

    if ($current['sleep'] === $other['sleep'])
        $reasons[] = "same sleep schedule (" . $current['sleep'] . ")";

    if ($current['cleanliness'] === $other['cleanliness'])
        $reasons[] = "matching cleanliness standard (" . $current['cleanliness'] . ")";

    if ($current['social'] === $other['social'])
        $reasons[] = "similar social style (" . $current['social'] . ")";

    if ($current['diet'] === $other['diet'])
        $reasons[] = "same dietary preference (" . $current['diet'] . ")";

    if ($current['noise'] === $other['noise'])
        $reasons[] = "compatible noise tolerance";

    if ($current['pets'] === $other['pets'])
        $reasons[] = "matching pet preference";

    if (empty($reasons)) {
        return "Complementary lifestyles that work well together.";
    }

    return "You both share: " . implode(', ', array_slice($reasons, 0, 3)) . ".";
}

// ── Score all other users ──────────────────────────────────────────────────
$matches = [];
$seen_users = []; // Deduplicate by user_id (keep their latest survey)

while ($other = $others->fetch_assoc()) {
    if (isset($seen_users[$other['user_id']]))
        continue;
    $seen_users[$other['user_id']] = true;

    $weighted_score = 0;
    foreach ($weights as $field => $weight) {
        $s = partial_score($field, $current[$field] ?? '', $other[$field] ?? '');
        $weighted_score += $s * $weight;
    }

    $compatibility = round(($weighted_score / $total_weight) * 100, 1);
    $room_info = assignRoom($user_id, $other['user_id']);
    $reason = generateReason($current, $other, $weights);

    $matches[] = [
        'user_id' => $other['user_id'],
        'fullname' => $other['fullname'],
        'compatibility' => $compatibility,
        'room' => $room_info['room'],
        'wing' => $room_info['wing'],
        'reason' => $reason,
        'sleep' => $other['sleep'],
        'cleanliness' => $other['cleanliness'],
        'diet' => $other['diet'],
        'social' => $other['social'],
        'needs' => $other['needs'],
    ];
}

// Sort by compatibility descending
usort($matches, fn($a, $b) => $b['compatibility'] <=> $a['compatibility']);

// ── Store best match in session for match_result.html to read ──────────────
$best = !empty($matches) ? $matches[0] : null;

// ── Check mutual acceptance for the best match ──────────────────────────────
if ($best) {
    // Auto-create table
    $conn->query("CREATE TABLE IF NOT EXISTS match_accepts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        match_id INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_pair (user_id, match_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Did I accept them?
    $stmt = $conn->prepare("SELECT COUNT(*) FROM match_accepts WHERE user_id=? AND match_id=?");
    $stmt->bind_param("ii", $user_id, $best['user_id']);
    $stmt->execute();
    $best['i_accepted'] = $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();

    // Did they accept me?
    $stmt = $conn->prepare("SELECT COUNT(*) FROM match_accepts WHERE user_id=? AND match_id=?");
    $stmt->bind_param("ii", $best['user_id'], $user_id);
    $stmt->execute();
    $best['they_accepted'] = $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
}

$conn->close();

echo json_encode([
    'success' => true,
    'your_profile' => [
        'sleep' => $current['sleep'],
        'cleanliness' => $current['cleanliness'],
        'diet' => $current['diet'],
        'social' => $current['social'],
        'room' => $current['room'],
    ],
    'best_match' => $best,
    'all_matches' => array_slice($matches, 0, 5),
    'total_users' => count($seen_users),
]);
?>
