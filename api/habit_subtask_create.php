<?php
// habit_subtask_create.php
// Creates a subtask for a habit. Requires habit ownership verification.
// Fields: habit_name (required), name (required), frequency (optional), times_per_frequency (optional)

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
$habitName = trim($_POST['habit_name'] ?? '');
$subtaskName = trim($_POST['name'] ?? '');
$frequency = $_POST['frequency'] ?? null;
$timesPerFrequency = $_POST['times_per_frequency'] ?? 1;
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

if ($habitName === '' || $subtaskName === '') {
    $response['message'] = 'Please provide the habit name and subtask name.';
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

// Validate frequency if provided
if ($frequency !== null && $frequency !== '') {
    if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'])) {
        $response['message'] = 'Invalid frequency.';
        echo json_encode($response);
        exit;
    }
}

// Create the subtask
$stmt = $pdo->prepare(
    "INSERT INTO habit_subtasks (habit_id, name, frequency, times_per_frequency, reminder_datetime, notification_enabled)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->execute([$habitId, $subtaskName, $frequency ?: null, $timesPerFrequency, $reminderFormatted, $notificationEnabled]);

$response['success'] = true;
$response['message'] = 'Habit subtask created.';
$response['subtask_name'] = $subtaskName;

echo json_encode($response);