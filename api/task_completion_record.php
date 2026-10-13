<?php
// task_completion_record.php
// Marks a task completed (only when all its subtasks are done), logs the completion
// in task_completions, and unlocks the task's reward if it has one.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
set_exception_handler(function ($e) {
    error_log('task_completion_record: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error while completing the task.']);
    exit;
});

require 'db.php'; // gives us $pdo
require_once __DIR__ . '/task_streak_lib.php';
header('Content-Type: application/json');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'You must be logged in.';
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id'];
$taskName = trim($_POST['task_name'] ?? '');
$completedDate = $_POST['completed_date'] ?? date('Y-m-d');

if ($taskName === '') {
    $response['message'] = 'Please provide task_name.';
    echo json_encode($response);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedDate)) {
    $response['message'] = 'Invalid date format. Use YYYY-MM-DD.';
    echo json_encode($response);
    exit;
}

// Find the task
$stmt = $pdo->prepare("SELECT id, completed FROM tasks WHERE name = ? AND user_id = ?");
$stmt->execute([$taskName, $userId]);
$task = $stmt->fetch();
if (!$task) {
    $response['message'] = 'Task not found.';
    echo json_encode($response);
    exit;
}
$taskId = (int)$task['id'];

if ((int)$task['completed'] === 1) {
    $response['message'] = 'Task already completed.';
    echo json_encode($response);
    exit;
}

// 100% rule: every subtask must be completed
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total, COALESCE(SUM(completed = 1), 0) AS done FROM task_subtasks WHERE task_id = ?"
);
$stmt->execute([$taskId]);
$counts = $stmt->fetch();
$total = (int)$counts['total'];
$done = (int)$counts['done'];

if ($total > 0 && $done < $total) {
    $response['message'] = 'Not all subtasks are completed (100% rule).';
    $response['completed_subtasks'] = $done;
    $response['total_subtasks'] = $total;
    echo json_encode($response);
    exit;
}

// Mark the task completed
$stmt = $pdo->prepare("UPDATE tasks SET completed = 1 WHERE id = ? AND user_id = ?");
$stmt->execute([$taskId, $userId]);

// Log the completion
$stmt = $pdo->prepare(
    "INSERT INTO task_completions (user_id, task_id, completed_date) VALUES (?, ?, ?)"
);
$stmt->execute([$userId, $taskId, $completedDate]);
syncTaskStreak($pdo, $userId);

// Unlock the reward, if this task has one that is still locked
$rewardUnlocked = null;
$stmt = $pdo->prepare(
    "SELECT id, reward_name FROM completion_rewards
     WHERE item_type = 'task' AND item_id = ? AND user_id = ? AND unlocked = 0
     LIMIT 1"
);
$stmt->execute([$taskId, $userId]);
$reward = $stmt->fetch();
if ($reward) {
    $stmt = $pdo->prepare("UPDATE completion_rewards SET unlocked = 1, unlocked_at = NOW() WHERE id = ?");
    $stmt->execute([$reward['id']]);
    $rewardUnlocked = $reward['reward_name'];
}

$response['success'] = true;
$response['message'] = 'Task marked as completed.';
$response['reward_unlocked'] = $rewardUnlocked;
echo json_encode($response);