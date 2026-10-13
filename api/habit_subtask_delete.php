<?php
// habit_subtask_delete.php
// Deletes a habit subtask. Habit must belong to logged-in user.

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

// Look up subtask
$stmt = $pdo->prepare("SELECT id FROM habit_subtasks WHERE name = ? AND habit_id = ?");
$stmt->execute([$subtaskName, $habit['id']]);
$subtask = $stmt->fetch();
if (!$subtask) {
    $response['message'] = 'Subtask not found.';
    echo json_encode($response);
    exit;
}

// Delete the subtask
$stmt = $pdo->prepare("DELETE FROM habit_subtasks WHERE id = ?");
$stmt->execute([$subtask['id']]);

$response['success'] = true;
$response['message'] = 'Habit subtask deleted.';

echo json_encode($response);