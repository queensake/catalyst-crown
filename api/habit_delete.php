<?php
// habit_delete.php
// Deletes a habit and its subtasks. Two-step:
// 1. Call without 'action' → returns habit goal + reward for confirmation preview
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
$habitName = trim($_POST['habit_name'] ?? '');
$action = $_POST['action'] ?? '';

if ($habitName === '') {
    $response['message'] = 'Please provide the habit name.';
    echo json_encode($response);
    exit;
}

// Look up habit by name
$stmt = $pdo->prepare("SELECT id, goal FROM habits WHERE name = ? AND user_id = ?");
$stmt->execute([$habitName, $userId]);
$habit = $stmt->fetch();
if (!$habit) {
    $response['message'] = 'Habit not found.';
    echo json_encode($response);
    exit;
}

$habitId = $habit['id'];

// If no action, return habit details for confirmation
if ($action === '') {
    // Look up reward for this habit
    $stmtReward = $pdo->prepare(
        "SELECT reward_name FROM completion_rewards WHERE item_type = 'habit' AND item_id = ? AND user_id = ?"
    );
    $stmtReward->execute([$habitId, $userId]);
    $reward = $stmtReward->fetch();

    $response['success'] = true;
    $response['message'] = 'Habit details for confirmation.';
    $response['habit'] = [
        'name' => $habitName,
        'goal' => $habit['goal'],
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

// Delete habit (cascade will handle subtasks via FK on habit_subtasks.habit_id)
$stmt = $pdo->prepare("DELETE FROM habits WHERE id = ? AND user_id = ?");
$stmt->execute([$habitId, $userId]);

$response['success'] = true;
$response['message'] = 'Habit deleted.';

echo json_encode($response);