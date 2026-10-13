<?php
// habit_list.php
// Returns habits for the logged-in user with filtering and search.
// Query params: search (name), status (active/archived/all), sort (newest/oldest)

require 'db.php'; // gives us $pdo

header('Content-Type: application/json');
session_start();

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'You must be logged in.';
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id'];
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'active'; // active, archived, or all
$sort = $_GET['sort'] ?? 'newest'; // newest or oldest

$sql = "SELECT h.id, h.name, h.frequency, h.times_per_frequency, h.tracking, h.habit_type,
               h.category_id, h.color_id, h.notes, h.goal, h.status, h.created_at,
               c.name as category_name, tc.hex_code as color_hex
        FROM habits h
        LEFT JOIN categories c ON c.id = h.category_id
        LEFT JOIN task_colors tc ON tc.id = h.color_id
        WHERE h.user_id = ?";
$params = [$userId];

// Filter by status
if ($status === 'active') {
    $sql .= " AND h.status = 'active'";
} elseif ($status === 'archived') {
    $sql .= " AND h.status = 'archived'";
}
// if status === 'all', no additional filter

// Search in name
if ($search !== '') {
    $sql .= " AND h.name LIKE ?";
    $params[] = '%' . $search . '%';
}

// Sort
$sql .= $sort === 'oldest' ? " ORDER BY h.created_at ASC" : " ORDER BY h.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$habits = $stmt->fetchAll();

// For each habit, fetch its subtasks
foreach ($habits as &$h) {
    $stmtSub = $pdo->prepare(
        "SELECT id, name, frequency, times_per_frequency FROM habit_subtasks WHERE habit_id = ? ORDER BY id ASC"
    );
    $stmtSub->execute([$h['id']]);
    $h['subtasks'] = $stmtSub->fetchAll();
}

$response['success'] = true;
$response['habits'] = $habits;

echo json_encode($response);