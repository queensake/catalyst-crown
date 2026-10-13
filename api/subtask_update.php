<?php
// task_completion_record.php
// Records a task completion. Marks task completed, logs date, checks 100% rule for subtasks.
// If all subtasks completed → triggers reward unlock check

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
$completedDate = $_POST['completed_date'] ?? date('Y-m-d'); // default today

if ($taskName === '') {
    $response['message'] = 'Please provide task name.';
    echo json_encode($response);
    exit;
}

// Look up task by name
$stmt = $pdo->prepare("SELECT id, completed FROM tasks WHERE name = ? AND user_id = ?");
$stmt->execute([$taskName, $userId]);
$task = $stmt->fetch();
if (!$task) {
    $response['message'] = 'Task not found.';
    echo json_encode($response);
    exit;
}

$taskId = $task['id'];

// If already completed, return early
if ($task['completed']) {
    $response['message'] = 'Task already completed.';
    echo json_encode($response);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedDate)) {
    $response['message'] = 'Invalid date format. Use YYYY-MM-DD.';
    echo json_encode($response);
    exit;
}

// Check 100% rule: all subtasks must be completed
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM task_subtasks WHERE task_id = ?");
$stmt->execute([$taskId]);
$subtaskCount = $stmt->fetch();
$totalSubtasks = $subtaskCount['total'];

if ($totalSubtasks > 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as completed FROM task_subtasks WHERE task_id = ? AND completed = 1");
    $stmt->execute([$taskId]);
    $completedSubtasks = $stmt->fetch();
    
    if ($completedSubtasks['completed'] < $totalSubtasks) {
        $response['message'] = 'Not all subtasks are completed (100% rule).';
        $response['completed_subtasks'] = $completedSubtasks['completed'];
        $response['total_subtasks'] = $totalSubtasks;
        echo json_encode($response);
        exit;
    }
}

// Mark task as completed
$stmt = $pdo->prepare("UPDATE tasks SET completed = 1, completed_date = ? WHERE id = ? AND user_id = ?");
$stmt->execute([$completedDate, $taskId, $userId]);

// Check if task has a reward linked
$stmt = $pdo->prepare(
    "SELECT id, reward_name FROM completion_rewards WHERE item_type = 'task' AND item_id = ? AND user_id = ?"
);
$stmt->execute([$taskId, $userId]);
$reward = $stmt->fetch();

$rewardUnlocked = null;
if ($reward) {
    // Mark reward as unlocked
    $stmt = $pdo->prepare("UPDATE completion_rewards SET progress = progress + 1 WHERE id = ?");
    $stmt->execute([$reward['id']]);
    $rewardUnlocked = $reward['reward_name'];
}

$response['success'] = true;
$response['message'] = 'Task marked as completed.';
$response['reward_unlocked'] = $rewardUnlocked;

echo json_encode($response);