<?php
// habit_subtask_update.php
// Updates a habit subtask. Can update: name, frequency, times_per_frequency

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
$habitName = trim($_POST['habit_name'] ?? '');
$subtaskName = trim($_POST['subtask_name'] ?? '');

if ($habitName === '' || $subtaskName === '') {
    $response['message'] = 'Please provide the habit name and subtask name.';
    echo json_encode($response);
    exit;
}

// Look up habit
$stmt = $pdo->prepare("SELECT id FROM habits WHERE name = ? AND user_id = ?");
$stmt->execute([$habitName, $userId]);
$habit = $stmt->fetch();
if (!$habit) {
    $response['message'] = 'Habit not found.';
    echo json_encode($response);
    exit;
}

// Look up subtask by name under that habit
$stmt = $pdo->prepare("SELECT id FROM habit_subtasks WHERE name = ? AND habit_id = ?");
$stmt->execute([$subtaskName, $habit['id']]);
$subtask = $stmt->fetch();
if (!$subtask) {
    $response['message'] = 'Subtask not found.';
    echo json_encode($response);
    exit;
}

$subtaskId = $subtask['id'];

// Build dynamic update
$updates = [];
$params = [];

if (isset($_POST['name'])) {
    $name = trim($_POST['name']);
    if ($name === '') {
        $response['message'] = 'Name cannot be empty.';
        echo json_encode($response);
        exit;
    }
    $updates[] = "name = ?";
    $params[] = $name;
}

if (isset($_POST['frequency'])) {
    $frequency = $_POST['frequency'];
    if ($frequency !== '' && $frequency !== null) {
        if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'])) {
            $response['message'] = 'Invalid frequency.';
            echo json_encode($response);
            exit;
        }
    }
    $updates[] = "frequency = ?";
    $params[] = $frequency ?: null;
}

if (isset($_POST['times_per_frequency'])) {
    $updates[] = "times_per_frequency = ?";
    $params[] = $_POST['times_per_frequency'];
}

if (empty($updates)) {
    $response['message'] = 'No fields to update.';
    echo json_encode($response);
    exit;
}

$params[] = $subtaskId;

$sql = "UPDATE habit_subtasks SET " . implode(", ", $updates) . " WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$response['success'] = true;
$response['message'] = 'Habit subtask updated.';

echo json_encode($response);