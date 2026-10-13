<?php
// task_update.php
// Updates a task. Can update: name, description, deadline, category_name, notes, goal, completed, archived

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
$taskName = $_POST['task_name'] ?? '';

if ($taskName === '') {
    $response['message'] = 'Please provide the task name.';
    echo json_encode($response);
    exit;
}

// Look up task by name
$stmt = $pdo->prepare("SELECT id FROM tasks WHERE name = ? AND user_id = ?");
$stmt->execute([$taskName, $userId]);
$task = $stmt->fetch();
if (!$task) {
    $response['message'] = 'Task not found.';
    echo json_encode($response);
    exit;
}

$taskId = $task['id'];

// Build dynamic update
$updates = [];
$params = [];

if (isset($_POST['name'])) {
    $name = trim($_POST['name']);
    if ($name === '') {
        $response['message'] = 'Name cannot be empty.';
        echo json_encode($response);
        exit;
    }
    $updates[] = "name = ?";
    $params[] = $name;
}

if (isset($_POST['description'])) {
    $updates[] = "description = ?";
    $params[] = trim($_POST['description']) ?: null;
}

if (isset($_POST['deadline'])) {
    $deadline = $_POST['deadline'];
    $deadlineFormatted = null;
    if ($deadline !== '' && $deadline !== null) {
        if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $deadline)) {
            $response['message'] = 'Deadline must be in DD-MM-YYYY format.';
            echo json_encode($response);
            exit;
        }
        // Convert DD-MM-YYYY to YYYY-MM-DD for database
        $dateParts = explode('-', $deadline);
        $deadlineFormatted = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
    }
    $updates[] = "deadline = ?";
    $params[] = $deadlineFormatted;
}

if (isset($_POST['category_name'])) {
    $categoryName = trim($_POST['category_name']);
    $categoryId = null;
    if ($categoryName !== '') {
        $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND user_id = ?");
        $stmtCat->execute([$categoryName, $userId]);
        $category = $stmtCat->fetch();
        if ($category) {
            $categoryId = $category['id'];
        } else {
            $response['message'] = 'Category not found.';
            echo json_encode($response);
            exit;
        }
    }
    $updates[] = "category_id = ?";
    $params[] = $categoryId;
}

if (isset($_POST['notes'])) {
    $updates[] = "notes = ?";
    $params[] = trim($_POST['notes']) ?: null;
}

if (isset($_POST['goal'])) {
    $updates[] = "goal = ?";
    $params[] = trim($_POST['goal']) ?: null;
}

if (isset($_POST['completed'])) {
    $completed = $_POST['completed'] === 'true' ? 1 : 0;
    $updates[] = "completed = ?";
    $params[] = $completed;
}

if (isset($_POST['archived'])) {
    $archived = $_POST['archived'] === 'true' ? 1 : 0;
    $updates[] = "archived = ?";
    $params[] = $archived;
}

if (empty($updates)) {
    $response['message'] = 'No fields to update.';
    echo json_encode($response);
    exit;
}

$params[] = $taskId;
$params[] = $userId;

$sql = "UPDATE tasks SET " . implode(", ", $updates) . " WHERE id = ? AND user_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$response['success'] = true;
$response['message'] = 'Task updated.';

echo json_encode($response);