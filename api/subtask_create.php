<?php
// subtask_create.php
// Creates a subtask for a task. Requires task ownership verification.
// Fields: task_name (required), name (required), deadline (optional, DD-MM-YYYY), frequency (optional)

require 'db.php';
require_once __DIR__ . '/reminder_time.php'; // gives us $pdo

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
$subtaskName = trim($_POST['name'] ?? '');
$deadline = $_POST['deadline'] ?? null; // DD-MM-YYYY format
$frequency = $_POST['frequency'] ?? null;
$notificationEnabled = !empty($_POST['notification_enabled']) ? 1 : 0;
$rawReminder = trim((string)($_POST['reminder_datetime'] ?? ''));
$reminderFormatted = null;
if ($notificationEnabled && $rawReminder !== '') {
    if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}$/', $rawReminder)) { $response['message'] = 'Reminder datetime must be in DD-MM-YYYY HH:mm format.'; echo json_encode($response); exit; }
    [$datePart, $timePart] = explode(' ', $rawReminder, 2); [$day, $month, $year] = explode('-', $datePart);
    if (!checkdate((int)$month, (int)$day, (int)$year)) { $response['message'] = 'Reminder datetime is invalid.'; echo json_encode($response); exit; }
    $reminderFormatted = $year . '-' . $month . '-' . $day . ' ' . $timePart . ':00';
    $reminderFormatted = reminderToUtc($reminderFormatted);
    if (strtotime($reminderFormatted . ' UTC') === false || strtotime($reminderFormatted . ' UTC') <= time()) { $response['message'] = 'Reminder must be set for a future date and time.'; echo json_encode($response); exit; }
}

if ($frequency !== null && $frequency !== '' && !in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
    $response['message'] = 'Invalid frequency.';
    echo json_encode($response);
    exit;
}

if ($taskName === '' || $subtaskName === '') {
    $response['message'] = 'Please provide the task name and subtask name.';
    echo json_encode($response);
    exit;
}

// Confirm the task belongs to this user
$stmt = $pdo->prepare("SELECT id FROM tasks WHERE name = ? AND user_id = ?");
$stmt->execute([$taskName, $userId]);
$task = $stmt->fetch();
if (!$task) {
    $response['message'] = 'Task not found.';
    echo json_encode($response);
    exit;
}

$taskId = $task['id'];

// Validate and convert deadline if provided
$deadlineFormatted = null;
if ($deadline !== null && $deadline !== '') {
    if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $deadline)) {
        $response['message'] = 'Deadline must be in DD-MM-YYYY format.';
        echo json_encode($response);
        exit;
    }
    // Convert DD-MM-YYYY to YYYY-MM-DD for database
    $dateParts = explode('-', $deadline);
    $deadlineFormatted = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
}

// Create the subtask
$stmt = $pdo->prepare(
    "INSERT INTO task_subtasks (task_id, name, deadline, frequency, reminder_datetime, notification_enabled)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->execute([$taskId, $subtaskName, $deadlineFormatted, $frequency ?: null, $reminderFormatted, $notificationEnabled]);

$response['success'] = true;
$response['message'] = 'Subtask created.';
$response['subtask_name'] = $subtaskName;

echo json_encode($response);