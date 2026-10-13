<?php
// subtask_completion_record.php
// Records a subtask completion. Marks subtask completed, logs date.
// Returns completion % for parent task.

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
$completedDate = $_POST['completed_date'] ?? date('Y-m-d'); // default today

if ($taskName === '' || $subtaskName === '') {
    $response['message'] = 'Please provide task_name and subtask_name.';
    echo json_encode($response);
    exit;
}

// Look up task by name
$stmt = $pdo->prepare("SELECT id FROM tasks WHERE name = ? AND user_id = ?");
$stmt->execute([$taskName, $userId]);
$task = $stmt->fetch();
if (!$task) {
    $response['message'] = 'Task not found.';
    echo json_encode($response);
    exit;
}

$taskId = $task['id'];

// Look up subtask by name under that task
$stmt = $pdo->prepare("SELECT id, completed FROM task_subtasks WHERE name = ? AND task_id = ?");
$stmt->execute([$subtaskName, $taskId]);
$subtask = $stmt->fetch();
if (!$subtask) {
    $response['message'] = 'Subtask not found.';
    echo json_encode($response);
    exit;
}

$subtaskId = $subtask['id'];

// If already completed, return early
if ($subtask['completed']) {
    $response['message'] = 'Subtask already completed.';
    echo json_encode($response);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedDate)) {
    $response['message'] = 'Invalid date format. Use YYYY-MM-DD.';
    echo json_encode($response);
    exit;
}

// Mark subtask as completed
$stmt = $pdo->prepare("UPDATE task_subtasks SET completed = 1, completed_date = ? WHERE id = ? AND task_id = ?");
$stmt->execute([$completedDate, $subtaskId, $taskId]);

// Calculate progress for parent task
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM task_subtasks WHERE task_id = ?");
$stmt->execute([$taskId]);
$totalSubtasks = $stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) as completed FROM task_subtasks WHERE task_id = ? AND completed = 1");
$stmt->execute([$taskId]);
$completedSubtasks = $stmt->fetch()['completed'];

$progressPercentage = $totalSubtasks > 0 ? round(($completedSubtasks / $totalSubtasks) * 100) : 0;

$response['success'] = true;
$response['message'] = 'Subtask marked as completed.';
$response['subtask_name'] = $subtaskName;
$response['progress'] = "$completedSubtasks/$totalSubtasks";
$response['progress_percentage'] = $progressPercentage;

echo json_encode($response);