<?php
// streak_reset.php
// Resets the current streak for a task or habit (longest_streak is kept).
// action=manual : the user resets it
// action=auto   : only resets if the item is archived
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require 'db.php'; // gives us $pdo
header('Content-Type: application/json');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $itemType = $_POST['item_type'] ?? '';
    $itemName = trim($_POST['item_name'] ?? '');
    $action = $_POST['action'] ?? 'manual';

    if ($itemType === '' || $itemName === '') {
        echo json_encode(['success' => false, 'message' => 'Please provide item_type (task/habit) and item_name.']);
        exit;
    }
    if (!in_array($itemType, ['task', 'habit'], true)) {
        echo json_encode(['success' => false, 'message' => 'Item type must be task or habit.']);
        exit;
    }
    if (!in_array($action, ['manual', 'auto'], true)) {
        echo json_encode(['success' => false, 'message' => 'Action must be manual or auto.']);
        exit;
    }

    $table = $itemType === 'task' ? 'tasks' : 'habits';
    $stmt = $pdo->prepare("SELECT id, status FROM $table WHERE name = ? AND user_id = ?");
    $stmt->execute([$itemName, $userId]);
    $item = $stmt->fetch();
    if (!$item) {
        echo json_encode(['success' => false, 'message' => ucfirst($itemType) . ' not found.']);
        exit;
    }
    $itemId = (int)$item['id'];

    if ($action === 'auto' && $item['status'] !== 'archived') {
        echo json_encode(['success' => true, 'message' => 'Item not archived. No auto-reset.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE streaks SET current_streak = 0
         WHERE item_type = ? AND item_id = ? AND user_id = ?"
    );
    $stmt->execute([$itemType, $itemId, $userId]);

    echo json_encode([
        'success' => true,
        'message' => $action === 'auto' ? 'Streak auto-reset (item archived).' : 'Streak manually reset.',
        'streak_count' => 0,
    ]);
} catch (Throwable $e) {
    error_log('streak_reset error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error while resetting streak.']);
}