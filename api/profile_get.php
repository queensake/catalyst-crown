<?php
// profile_get.php
// Returns user profile information (username, email, created_at)
// Does NOT return password

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

$stmt = $pdo->prepare("SELECT id, username, email, created_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    $response['message'] = 'User not found.';
    echo json_encode($response);
    exit;
}

$response['success'] = true;
$response['user'] = [
    'id' => $user['id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'created_at' => $user['created_at']
];

echo json_encode($response);