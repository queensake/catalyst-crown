=<?php
// habit_subtask_completion_record.php
// Records a habit subtask completion. Marks subtask completed, logs date.
// Returns completion % for parent habit.

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
set_exception_handler(function ($e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error while recording completion.']);
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
$habitName = trim($_POST['habit_name'] ?? '');
$subtaskName = trim($_POST['subtask_name'] ?? '');
$completedDate = $_POST['completed_date'] ?? date('Y-m-d'); // default today

if ($habitName === '' || $subtaskName === '') {
    $response['message'] = 'Please provide habit_name and subtask_name.';
    echo json_encode($response);
    exit;
}

// Look up habit by name
$stmt = $pdo->prepare("SELECT id FROM habits WHERE name = ? AND user_id = ?");
$stmt->execute([$habitName, $userId]);
$habit = $stmt->fetch();
if (!$habit) {
    $response['message'] = 'Habit not found.';
    echo json_encode($response);
    exit;
}

$habitId = $habit['id'];

// Look up subtask by name under that habit
$stmt = $pdo->prepare("SELECT id, completed FROM habit_subtasks WHERE name = ? AND habit_id = ?");
$stmt->execute([$subtaskName, $habitId]);
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
$stmt = $pdo->prepare("UPDATE habit_subtasks SET completed = 1, completed_date = ? WHERE id = ? AND habit_id = ?");
$stmt->execute([$completedDate, $subtaskId, $habitId]);

// Calculate progress for parent habit
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM habit_subtasks WHERE habit_id = ?");
$stmt->execute([$habitId]);
$totalSubtasks = $stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) as completed FROM habit_subtasks WHERE habit_id = ? AND completed = 1");
$stmt->execute([$habitId]);
$completedSubtasks = $stmt->fetch()['completed'];

$progressPercentage = $totalSubtasks > 0 ? round(($completedSubtasks / $totalSubtasks) * 100) : 0;

$response['success'] = true;
$response['message'] = 'Habit subtask marked as completed.';
$response['subtask_name'] = $subtaskName;
$response['progress'] = "$completedSubtasks/$totalSubtasks";
$response['progress_percentage'] = $progressPercentage;

echo json_encode($response);