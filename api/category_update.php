<?php
// category_update.php
// Updates a category. Can update: name, color_id, notes

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
$category_name = trim($_POST['name'] ?? '');

if (isset($_POST['name']) && $category_name === '') {
    $response['message'] = 'Please provide the category name.';
    echo json_encode($response);
    exit;
}

// Confirm category belongs to this user
$stmt = $pdo->prepare("SELECT id, name FROM categories WHERE id = ? AND user_id = ?");
$stmt->execute([$categoryId, $userId]);
$category = $stmt->fetch();
if (!$category) {
    $response['message'] = 'Category not found.';
    echo json_encode($response);
    exit;
}

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
    // Check for duplicate (excluding self)
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND user_id = ? AND id != ?");
    $stmt->execute([$name, $userId, $categoryId]);
    if ($stmt->fetch()) {
        $response['message'] = 'A category with this name already exists.';
        echo json_encode($response);
        exit;
    }
    $updates[] = "name = ?";
    $params[] = $name;
}

if (isset($_POST['color_id'])) {
    $colorId = $_POST['color_id'];
    if ($colorId !== '' && $colorId !== null) {
        $stmtColor = $pdo->prepare("SELECT id FROM task_colors WHERE id = ?");
        $stmtColor->execute([$colorId]);
        if (!$stmtColor->fetch()) {
            $response['message'] = 'Invalid color.';
            echo json_encode($response);
            exit;
        }
    }
    $updates[] = "color_id = ?";
    $params[] = $colorId ?: null;
}

if (isset($_POST['notes'])) {
    $updates[] = "notes = ?";
    $params[] = trim($_POST['notes']) ?: null;
}

if (empty($updates)) {
    $response['message'] = 'No fields to update.';
    echo json_encode($response);
    exit;
}

$params[] = $categoryId;
$params[] = $userId;

$sql = "UPDATE categories SET " . implode(", ", $updates) . " WHERE id = ? AND user_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$response['success'] = true;
$response['message'] = 'Category updated.';

echo json_encode($response);