<?php
// completion_reward_unlock.php
// Unlocks a reward when a task or habit is completed.
// Called after task_completion_record or habit_completion_record.
// Updates progress and marks as unlocked if target met.

require 'db.php';

header('Content-Type: application/json');
session_start();

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'You must be logged in.';
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id'];
$itemType = $_POST['item_type'] ?? ''; // task or habit
$itemName = trim($_POST['item_name'] ?? '');

if ($itemType === '' || $itemName === '') {
    $response['message'] = 'Provide item_type (task/habit) and item_name.';
    echo json_encode($response);
    exit;
}

if (!in_array($itemType, ['task', 'habit'])) {
    $response['message'] = 'Item type must be task or habit.';
    echo json_encode($response);
    exit;
}

// Look up item by name
if ($itemType === 'task') {
    $stmt = $pdo->prepare("SELECT id FROM tasks WHERE name = ? AND user_id = ?");
    $stmt->execute([$itemName, $userId]);
    $item = $stmt->fetch();
    if (!$item) {
        $response['message'] = 'Task not found.';
        echo json_encode($response);
        exit;
    }
    $itemId = $item['id'];
} else { // habit
    $stmt = $pdo->prepare("SELECT id FROM habits WHERE name = ? AND user_id = ?");
    $stmt->execute([$itemName, $userId]);
    $item = $stmt->fetch();
    if (!$item) {
        $response['message'] = 'Habit not found.';
        echo json_encode($response);
        exit;
    }
    $itemId = $item['id'];
}

// Look up reward for this item
$stmt = $pdo->prepare(
    "SELECT id, reward_name, target_progress, progress FROM completion_rewards 
     WHERE item_type = ? AND item_id = ? AND user_id = ?"
);
$stmt->execute([$itemType, $itemId, $userId]);
$reward = $stmt->fetch();

if (!$reward) {
    $response['success'] = true;
    $response['message'] = 'No reward linked to this item.';
    echo json_encode($response);
    exit;
}

$rewardId = $reward['id'];
$currentProgress = $reward['progress'];
$targetProgress = $reward['target_progress'];
$newProgress = $currentProgress + 1;
$isUnlocked = $newProgress >= $targetProgress;

// Update progress
$stmt = $pdo->prepare("UPDATE completion_rewards SET progress = ? WHERE id = ?");
$stmt->execute([$newProgress, $rewardId]);

// Mark as unlocked if target met
if ($isUnlocked) {
    $stmt = $pdo->prepare("UPDATE completion_rewards SET is_unlocked = 1, unlocked_date = NOW() WHERE id = ?");
    $stmt->execute([$rewardId]);
    
    $response['success'] = true;
    $response['message'] = 'Reward unlocked!';
    $response['reward_name'] = $reward['reward_name'];
    $response['reward_unlocked'] = true;
    $response['progress'] = $newProgress;
    $response['target'] = $targetProgress;
} else {
    $response['success'] = true;
    $response['message'] = 'Reward progress updated.';
    $response['reward_name'] = $reward['reward_name'];
    $response['reward_unlocked'] = false;
    $response['progress'] = $newProgress;
    $response['target'] = $targetProgress;
}

echo json_encode($response);