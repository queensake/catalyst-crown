<?php
// subtask_delete.php
// Deletes a subtask. Task must belong to logged-in user.

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
$taskName = trim($_POST['task_name'] ?? '');
$subtaskName = trim($_POST['subtask_name'] ?? '');

if ($taskName === '' || $subtaskName === '') {
    $response['message'] = 'Please provide the task name and subtask name.';
    echo json_encode($response);
    exit;
}

// Look up task
$stmt = $pdo->prepare("SELECT id FROM tasks WHERE name = ? AND user_id = ?");
$stmt->execute([$taskName, $userId]);
$task = $stmt->fetch();
if (!$task) {
    $response['message'] = 'Task not found.';
    echo json_encode($response);
    exit;
}

// Look up subtask
$stmt = $pdo->prepare("SELECT id FROM task_subtasks WHERE name = ? AND task_id = ?");
$stmt->execute([$subtaskName, $task['id']]);
$subtask = $stmt->fetch();
if (!$subtask) {
    $response['message'] = 'Subtask not found.';
    echo json_encode($response);
    exit;
}

// Delete the subtask
$stmt = $pdo->prepare("DELETE FROM task_subtasks WHERE id = ?");
$stmt->execute([$subtask['id']]);

$response['success'] = true;
$response['message'] = 'Subtask deleted.';

echo json_encode($response);