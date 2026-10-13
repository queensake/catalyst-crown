<?php
// category_delete.php
// Deletes a category. Two-step process:
// 1. Call without 'action' → returns category details (for confirmation view)
// 2. Call with 'action' → executes delete with reassignment/uncategorize logic

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
$categoryId = $_POST['category_id'] ?? '';
$action = $_POST['action'] ?? ''; // 'move', 'uncategorize', 'delete_attached'

if ($categoryId === '') {
    $response['message'] = 'Please provide the category ID.';
    echo json_encode($response);
    exit;
}

// Confirm category belongs to this user
$stmt = $pdo->prepare("SELECT id, name, notes FROM categories WHERE id = ? AND user_id = ?");
$stmt->execute([$categoryId, $userId]);
$category = $stmt->fetch();
if (!$category) {
    $response['message'] = 'Category not found.';
    echo json_encode($response);
    exit;
}

// If no action, return category details for confirmation
if ($action === '') {
    // Count associated items
    $stmtTasks = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE category_id = ? AND user_id = ?");
    $stmtTasks->execute([$categoryId, $userId]);
    $tasks = $stmtTasks->fetch()['count'];

    $stmtHabits = $pdo->prepare("SELECT COUNT(*) as count FROM habits WHERE category_id = ? AND user_id = ?");
    $stmtHabits->execute([$categoryId, $userId]);
    $habits = $stmtHabits->fetch()['count'];

    $response['success'] = true;
    $response['message'] = 'Category details for confirmation.';
    $response['category'] = [
        'id' => $category['id'],
        'name' => $category['name'],
        'notes' => $category['notes'],
        'tasks_count' => $tasks,
        'habits_count' => $habits
    ];
    $response['needs_reassignment'] = ($tasks > 0 || $habits > 0);
    echo json_encode($response);
    exit;
}

// Action provided — execute delete
if (!in_array($action, ['move', 'uncategorize', 'delete_attached'])) {
    $response['message'] = 'Invalid action.';
    echo json_encode($response);
    exit;
}

if ($action === 'move') {
    $newCategoryId = $_POST['new_category_id'] ?? '';
    if ($newCategoryId === '') {
        $response['message'] = 'Please provide a new category.';
        echo json_encode($response);
        exit;
    }
    // Verify new category exists and belongs to user
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND user_id = ?");
    $stmt->execute([$newCategoryId, $userId]);
    if (!$stmt->fetch()) {
        $response['message'] = 'Invalid new category.';
        echo json_encode($response);
        exit;
    }
    // Move all tasks and habits
    $pdo->prepare("UPDATE tasks SET category_id = ? WHERE category_id = ? AND user_id = ?")->execute([$newCategoryId, $categoryId, $userId]);
    $pdo->prepare("UPDATE habits SET category_id = ? WHERE category_id = ? AND user_id = ?")->execute([$newCategoryId, $categoryId, $userId]);
} elseif ($action === 'uncategorize') {
    // Remove category from all items
    $pdo->prepare("UPDATE tasks SET category_id = NULL WHERE category_id = ? AND user_id = ?")->execute([$categoryId, $userId]);
    $pdo->prepare("UPDATE habits SET category_id = NULL WHERE category_id = ? AND user_id = ?")->execute([$categoryId, $userId]);
} elseif ($action === 'delete_attached') {
    // Delete all tasks and habits in this category
    $pdo->prepare("DELETE FROM tasks WHERE category_id = ? AND user_id = ?")->execute([$categoryId, $userId]);
    $pdo->prepare("DELETE FROM habits WHERE category_id = ? AND user_id = ?")->execute([$categoryId, $userId]);
}

// Delete the category
$stmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
$stmt->execute([$categoryId, $userId]);

$response['success'] = true;
$response['message'] = 'Category deleted.';

echo json_encode($response);