<?php
// archive_item.php
// Archives or restores a task, habit, or category.
// Tasks cannot be archived once completed. Archiving a task or habit resets its streak to 0.
// POST: item_type (task|habit|category), item_name, action (archive|restore)
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require 'db.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $itemType = $_POST['item_type'] ?? '';
    $itemName = trim($_POST['item_name'] ?? '');
    $action = $_POST['action'] ?? '';

    if (!in_array($itemType, ['task', 'habit', 'category'], true) || $itemName === '') {
        echo json_encode(['success' => false, 'message' => 'Provide item_type (task/habit/category) and item_name.']);
        exit;
    }
    if (!in_array($action, ['archive', 'restore'], true)) {
        echo json_encode(['success' => false, 'message' => 'Action must be archive or restore.']);
        exit;
    }

    if ($itemType === 'task') {
        $stmt = $pdo->prepare("SELECT id, status, completed FROM tasks WHERE name = ? AND user_id = ?");
        $table = 'tasks';
    } elseif ($itemType === 'habit') {
        $stmt = $pdo->prepare("SELECT id, status, 0 AS completed FROM habits WHERE name = ? AND user_id = ?");
        $table = 'habits';
    } else {
        $stmt = $pdo->prepare("SELECT id, status, 0 AS completed FROM categories WHERE name = ? AND user_id = ?");
        $table = 'categories';
    }
    $stmt->execute([$itemName, $userId]);
    $item = $stmt->fetch();
    if (!$item) {
        echo json_encode(['success' => false, 'message' => ucfirst($itemType) . ' not found.']);
        exit;
    }
    $itemId = (int)$item['id'];

    if ($action === 'archive') {
        if ($itemType === 'task' && (int)$item['completed'] === 1) {
            echo json_encode(['success' => false, 'message' => 'Completed tasks cannot be archived.']);
            exit;
        }
        if ($item['status'] === 'archived') {
            echo json_encode(['success' => true, 'message' => 'Already archived.', 'status' => 'archived']);
            exit;
        }
        $pdo->prepare("UPDATE $table SET status = 'archived' WHERE id = ? AND user_id = ?")
            ->execute([$itemId, $userId]);
        if ($itemType !== 'category') {
            $pdo->prepare("UPDATE streaks SET current_streak = 0 WHERE item_type = ? AND item_id = ? AND user_id = ?")
                ->execute([$itemType, $itemId, $userId]);
        }
        echo json_encode(['success' => true, 'message' => 'Archived.', 'status' => 'archived']);
    } else {
        if ($item['status'] === 'active') {
            echo json_encode(['success' => true, 'message' => 'Already active.', 'status' => 'active']);
            exit;
        }
        $pdo->prepare("UPDATE $table SET status = 'active' WHERE id = ? AND user_id = ?")
            ->execute([$itemId, $userId]);
        echo json_encode(['success' => true, 'message' => 'Restored.', 'status' => 'active']);
    }
} catch (Throwable $e) {
    error_log('archive_item error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error while updating archive status.']);
}