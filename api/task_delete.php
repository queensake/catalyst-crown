<?php
// task_delete.php
// Deletes a task and its subtasks. Two-step:
// 1. Call without 'action' → returns task goal + reward for confirmation preview
// 2. Call with 'action=confirm' → executes delete

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
$taskName = $_POST['task_name'] ?? '';
$action = $_POST['action'] ?? '';

if ($taskName === '') {
    $response['message'] = 'Please provide the task name.';
    echo json_encode($response);
    exit;
}

// Look up task by name
$stmt = $pdo->prepare("SELECT id, goal FROM tasks WHERE name = ? AND user_id = ?");
$stmt->execute([$taskName, $userId]);
$task = $stmt->fetch();
if (!$task) {
    $response['message'] = 'Task not found.';
    echo json_encode($response);
    exit;
}

$taskId = $task['id'];

// If no action, return task details for confirmation
if ($action === '') {
    // Look up reward for this task
    $stmtReward = $pdo->prepare(
        "SELECT reward_name FROM completion_rewards WHERE item_type = 'task' AND item_id = ? AND user_id = ?"
    );
    $stmtReward->execute([$taskId, $userId]);
    $reward = $stmtReward->fetch();

    $response['success'] = true;
    $response['message'] = 'Task details for confirmation.';
    $response['task'] = [
        'name' => $taskName,
        'goal' => $task['goal'],
        'reward' => $reward ? $reward['reward_name'] : null
    ];
    echo json_encode($response);
    exit;
}

// Action provided — execute delete
if ($action !== 'confirm') {
    $response['message'] = 'Invalid action.';
    echo json_encode($response);
    exit;
}

// Delete task (cascade will handle subtasks via FK on task_subtasks.task_id)
$stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
$stmt->execute([$taskId, $userId]);

$response['success'] = true;
$response['message'] = 'Task deleted.';

echo json_encode($response);