<?php
// task_create.php
// Creates a new task for the logged-in user.
// Fields: name (required), deadline (optional, DD-MM-YYYY),
// category_name (optional), notes (optional), goal (optional), color_id (optional),
// reminder_datetime (optional, DD-MM-YYYY HH:mm)

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
$name = trim($_POST['name'] ?? '');
$deadline = $_POST['deadline'] ?? null; // DD-MM-YYYY format
$categoryName = trim($_POST['category_name'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$goal = trim($_POST['goal'] ?? '');
$colorId = $_POST['color_id'] ?? null;
$reminderDatetime = $_POST['reminder_datetime'] ?? null; // DD-MM-YYYY HH:mm format
$notificationEnabled = !empty($_POST['notification_enabled']) ? 1 : 0;
$subtasksJson = $_POST['subtasks'] ?? '[]';
$subtasks = json_decode($subtasksJson, true);
if (!is_array($subtasks)) {
    $response['message'] = 'Invalid subtasks data.';
    echo json_encode($response);
    exit;
}

if ($name === '') {
    $response['message'] = 'Please enter a task name.';
    echo json_encode($response);
    exit;
}

// Validate and convert deadline format if provided
$deadlineFormatted = null;
if ($deadline !== null && $deadline !== '') {
    if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $deadline)) {
        $response['message'] = 'Deadline must be in DD-MM-YYYY format.';
        echo json_encode($response);
        exit;
    }
    // Convert DD-MM-YYYY to YYYY-MM-DD for database storage
    $dateParts = explode('-', $deadline);
    $deadlineFormatted = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
}

// Validate and convert reminder_datetime format if provided
$reminderFormatted = null;
if ($reminderDatetime !== null && $reminderDatetime !== '') {
    if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}$/', $reminderDatetime)) {
        $response['message'] = 'Reminder datetime must be in DD-MM-YYYY HH:mm format.';
        echo json_encode($response);
        exit;
    }
    // Convert DD-MM-YYYY HH:mm to YYYY-MM-DD HH:mm for database storage
    $parts = explode(' ', $reminderDatetime);
    $dateParts = explode('-', $parts[0]);
    $reminderFormatted = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0] . ' ' . $parts[1];
    $reminderFormatted = reminderToUtc($reminderFormatted);
    if (strtotime($reminderFormatted . ' UTC') === false || strtotime($reminderFormatted . ' UTC') <= time()) {
        $response['message'] = 'Reminder must be set for a future date and time.';
        echo json_encode($response);
        exit;
    }
}

// If category_name provided, look up the ID
$categoryId = null;
if ($categoryName !== '') {
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$categoryName, $userId]);
    $category = $stmt->fetch();
    if ($category) {
        $categoryId = $category['id'];
    } else {
        $response['message'] = 'Category not found.';
        echo json_encode($response);
        exit;
    }
}

// Create the task and any draft subtasks in one transaction.
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "INSERT INTO tasks (user_id, name, deadline, category_id, color_id, notes, goal, reminder_datetime, notification_enabled, completed, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE, 'active', NOW())"
    );
    $stmt->execute([
        $userId,
        $name,
        $deadlineFormatted,
        $categoryId,
        $colorId ?: null,
        $notes ?: null,
        $goal ?: null,
        $reminderFormatted,
        $notificationEnabled
    ]);
    $taskId = (int)$pdo->lastInsertId();

    if (count($subtasks) > 0) {
        $subtaskStmt = $pdo->prepare(
            "INSERT INTO task_subtasks (task_id, name, deadline, frequency, reminder_datetime, notification_enabled) VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($subtasks as $subtask) {
            $subtaskName = trim((string)($subtask['name'] ?? ''));
            if ($subtaskName === '') {
                throw new InvalidArgumentException('Each subtask must have a name.');
            }
            $subtaskDeadline = null;
            $rawDeadline = trim((string)($subtask['deadline'] ?? ''));
            if ($rawDeadline !== '') {
                if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $rawDeadline)) {
                    throw new InvalidArgumentException('Subtask deadline must be in DD-MM-YYYY format.');
                }
                [$day, $month, $year] = explode('-', $rawDeadline);
                if (!checkdate((int)$month, (int)$day, (int)$year)) {
                    throw new InvalidArgumentException('Subtask deadline is not a valid date.');
                }
                $subtaskDeadline = $year . '-' . $month . '-' . $day;
            }
            $frequency = $subtask['frequency'] ?? null;
            if ($frequency === 'none' || $frequency === '') $frequency = null;
            if ($frequency !== null && !in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
                $frequency = null;
            }
            $subReminder = null;
            $rawReminder = trim((string)($subtask['reminder_datetime'] ?? ''));
            if (!empty($subtask['notification_enabled']) && $rawReminder !== '') {
                if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}$/', $rawReminder)) {
                    throw new InvalidArgumentException('Subtask reminder must be in DD-MM-YYYY HH:mm format.');
                }
                [$datePart, $timePart] = explode(' ', $rawReminder, 2);
                [$day, $month, $year] = explode('-', $datePart);
                if (!checkdate((int)$month, (int)$day, (int)$year)) throw new InvalidArgumentException('Subtask reminder is not a valid date.');
                $subReminder = $year . '-' . $month . '-' . $day . ' ' . $timePart . ':00';
                $subReminder = reminderToUtc($subReminder);
                if (strtotime($subReminder . ' UTC') === false || strtotime($subReminder . ' UTC') <= time()) throw new InvalidArgumentException('Subtask reminder must be set for a future date and time.');
            }
            $subtaskStmt->execute([$taskId, $subtaskName, $subtaskDeadline, $frequency, $subReminder, !empty($subtask['notification_enabled']) ? 1 : 0]);
        }
    }

    $pdo->commit();
    $response['success'] = true;
    $response['message'] = 'Task created.';
    $response['task_name'] = $name;
    $response['subtasks_created'] = count($subtasks);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Task creation error: ' . $e->getMessage());
}

echo json_encode($response);