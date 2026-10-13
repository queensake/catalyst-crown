<?php
// category_create.php
// Creates a new category for the logged-in user.
// Fields: name (required), color_id (optional), notes (optional)

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
$name = trim($_POST['name'] ?? '');
$colorId = $_POST['color_id'] ?? null;
$notes = trim($_POST['notes'] ?? '');

if ($name === '') {
    $response['message'] = 'Please enter a category name.';
    echo json_encode($response);
    exit;
}

// Check for duplicate name (case-insensitive, per user)
$stmt = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND user_id = ?");
$stmt->execute([$name, $userId]);
if ($stmt->fetch()) {
    $response['message'] = 'A category with this name already exists.';
    echo json_encode($response);
    exit;
}

// Validate color_id if provided
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

// Create the category
$stmt = $pdo->prepare(
    "INSERT INTO categories (user_id, name, color_id, notes)
     VALUES (?, ?, ?, ?)"
);
$stmt->execute([$userId, $name, $colorId, $notes ?: null]);

$response['success'] = true;
$response['message'] = 'Category created.';
$response['category_id'] = $pdo->lastInsertId();

echo json_encode($response);