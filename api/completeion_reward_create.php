<?php
// completion_reward_create.php
// Creates a reward linked to a task or habit.
// Fields: item_type (task/habit), item_name, reward_name

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
$itemType = $_POST['item_type'] ?? ''; // 'task' or 'habit'
$itemName = trim($_POST['item_name'] ?? '');
$rewardName = trim($_POST['reward_name'] ?? '');

if ($itemType === '' || $itemName === '' || $rewardName === '') {
    $response['message'] = 'Please provide item type, item name, and reward name.';
    echo json_encode($response);
    exit;
}

if (!in_array($itemType, ['task', 'habit'])) {
    $response['message'] = 'Item type must be task or habit.';
    echo json_encode($response);
    exit;
}

// Look up the item by name
$table = $itemType === 'task' ? 'tasks' : 'habits';
$stmt = $pdo->prepare("SELECT id FROM $table WHERE name = ? AND user_id = ?");
$stmt->execute([$itemName, $userId]);
$item = $stmt->fetch();

if (!$item) {
    $response['message'] = ucfirst($itemType) . ' not found.';
    echo json_encode($response);
    exit;
}

$itemId = $item['id'];

// Create the reward
$stmt = $pdo->prepare(
    "INSERT INTO completion_rewards (user_id, item_type, item_id, reward_name, unlocked, created_at)
     VALUES (?, ?, ?, ?, FALSE, NOW())"
);
$stmt->execute([$userId, $itemType, $itemId, $rewardName]);

$response['success'] = true;
$response['message'] = 'Reward created.';
$response['reward_id'] = $pdo->lastInsertId();

echo json_encode($response);