<?php
// habit_create.php
// Creates a new habit for the logged-in user.
// Fields: name (required), frequency (required), times_per_frequency, tracking (required),
// habit_type (build/avoid), category_name (optional), color_id (optional), notes, goal

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
$frequency = $_POST['frequency'] ?? '';
$timesPerFrequency = $_POST['times_per_frequency'] ?? 1;
$tracking = $_POST['tracking'] ?? '';
$habitType = $_POST['habit_type'] ?? 'build';
$categoryName = trim($_POST['category_name'] ?? '');
$colorId = $_POST['color_id'] ?? null;
$notes = trim($_POST['notes'] ?? '');
$goal = trim($_POST['goal'] ?? '');
$notificationEnabled = !empty($_POST['notification_enabled']) ? 1 : 0;
$reminderDatetime = trim((string)($_POST['reminder_datetime'] ?? ''));
$reminderFormatted = null;
if ($notificationEnabled && $reminderDatetime !== '') {
    if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}$/', $reminderDatetime)) { $response['message'] = 'Reminder datetime must be in DD-MM-YYYY HH:mm format.'; echo json_encode($response); exit; }
    [$datePart, $timePart] = explode(' ', $reminderDatetime, 2);
    [$day, $month, $year] = explode('-', $datePart);
    if (!checkdate((int)$month, (int)$day, (int)$year)) { $response['message'] = 'Reminder datetime is invalid.'; echo json_encode($response); exit; }
    $reminderFormatted = $year . '-' . $month . '-' . $day . ' ' . $timePart . ':00';
    $reminderFormatted = reminderToUtc($reminderFormatted);
    if (strtotime($reminderFormatted . ' UTC') === false || strtotime($reminderFormatted . ' UTC') <= time()) { $response['message'] = 'Reminder must be set for a future date and time.'; echo json_encode($response); exit; }
}
$subhabitsJson = $_POST['subhabits'] ?? '[]';
$subhabits = json_decode($subhabitsJson, true);
if (!is_array($subhabits)) {
    $response['message'] = 'Invalid subhabits data.';
    echo json_encode($response);
    exit;
}

if ($name === '') {
    $response['message'] = 'Please enter a habit name.';
    echo json_encode($response);
    exit;
}

if ($frequency === '' || !in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'])) {
    $response['message'] = 'Invalid frequency. Must be: daily, weekly, monthly, or yearly.';
    echo json_encode($response);
    exit;
}

if ($tracking === '' || !in_array($tracking, ['streak', 'completion', 'both'])) {
    $response['message'] = 'Invalid tracking method. Must be: streak, completion, or both.';
    echo json_encode($response);
    exit;
}

if (!in_array($habitType, ['build', 'avoid'])) {
    $response['message'] = 'Habit type must be: build or avoid.';
    echo json_encode($response);
    exit;
}

// Look up category by name if provided
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

// Validate color if provided
if ($colorId !== null && $colorId !== '') {
    $stmt = $pdo->prepare("SELECT id FROM task_colors WHERE id = ?");
    $stmt->execute([$colorId]);
    if (!$stmt->fetch()) {
        $response['message'] = 'Invalid color.';
        echo json_encode($response);
        exit;
    }
} else {
    $colorId = null;
}

// Create the habit and any draft subhabits in one transaction.
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "INSERT INTO habits (user_id, name, frequency, times_per_frequency, tracking, habit_type,
                             category_id, color_id, notes, goal, reminder_datetime, notification_enabled, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())"
    );
    $stmt->execute([$userId, $name, $frequency, $timesPerFrequency, $tracking, $habitType,
                    $categoryId, $colorId, $notes ?: null, $goal ?: null, $reminderFormatted, $notificationEnabled]);
    $habitId = (int)$pdo->lastInsertId();

    if (count($subhabits) > 0) {
        $subhabitStmt = $pdo->prepare(
            "INSERT INTO habit_subtasks (habit_id, name, frequency, times_per_frequency, reminder_datetime, notification_enabled) VALUES (?, ?, ?, 1, ?, ?)"
        );
        foreach ($subhabits as $subhabit) {
            $subhabitName = trim((string)($subhabit['name'] ?? ''));
            if ($subhabitName === '') {
                throw new InvalidArgumentException('Each subhabit must have a name.');
            }
            $subFrequency = $subhabit['frequency'] ?? null;
            if ($subFrequency === 'none' || $subFrequency === '') $subFrequency = null;
            if ($subFrequency !== null && !in_array($subFrequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
                $subFrequency = null;
            }
            $subReminder = null;
            $rawReminder = trim((string)($subhabit['reminder_datetime'] ?? ''));
            $subNotify = !empty($subhabit['notification_enabled']) ? 1 : 0;
            if ($subNotify && $rawReminder !== '') {
                if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}$/', $rawReminder)) throw new InvalidArgumentException('Subhabit reminder must be in DD-MM-YYYY HH:mm format.');
                [$datePart, $timePart] = explode(' ', $rawReminder, 2);
                [$day, $month, $year] = explode('-', $datePart);
                if (!checkdate((int)$month, (int)$day, (int)$year)) throw new InvalidArgumentException('Subhabit reminder is not a valid date.');
                $subReminder = $year . '-' . $month . '-' . $day . ' ' . $timePart . ':00';
                $subReminder = reminderToUtc($subReminder);
                if (strtotime($subReminder . ' UTC') === false || strtotime($subReminder . ' UTC') <= time()) throw new InvalidArgumentException('Subhabit reminder must be set for a future date and time.');
            }
            $subhabitStmt->execute([$habitId, $subhabitName, $subFrequency, $subReminder, $subNotify]);
        }
    }

    $pdo->commit();
    $response['success'] = true;
    $response['message'] = 'Habit created.';
    $response['habit_name'] = $name;
    $response['subhabits_created'] = count($subhabits);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Habit creation error: ' . $e->getMessage());
}

echo json_encode($response);