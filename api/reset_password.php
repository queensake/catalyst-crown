<?php
// reset_password.php

require 'db.php'; // gives us $pdo

header('Content-Type: application/json');

// 1. READ SUBMITTED DATA
$token = trim($_GET['token'] ?? '');
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$response = ['success' => false, 'message' => ''];


// 2. LOOK UP THE USER BY TOKEN
$stmt = $pdo->prepare(
    "SELECT id, password, password_reset_token_expires
     FROM users WHERE password_reset_token = ?"
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    $response['message'] = 'Invalid or expired reset link.';
    echo json_encode($response);
    exit;
}

// 3. CHECK EXPIRY
$now = date('Y-m-d H:i:s');
if ($user['password_reset_token_expires'] === null || $now > $user['password_reset_token_expires']) {
    $response['message'] = 'This reset link has expired. Please request a new one.';
    echo json_encode($response);
    exit;
}

// 4. NEW PASSWORD VALIDATION
if (
    strlen($newPassword) < 8 ||
    !preg_match('/[A-Z]/', $newPassword) ||
    !preg_match('/[a-z]/', $newPassword) ||
    !preg_match('/[\W_]/', $newPassword)
) {
    $response['message'] = 'New password must be at least 8 characters long, include at least one uppercase and lowercase letter, and a special character.';
    echo json_encode($response);
    exit;
}

if ($newPassword !== $confirmPassword) {
    $response['message'] = 'New password and confirmation password do not match.';
    echo json_encode($response);
    exit;
}

if (password_verify($newPassword, $user['password'])) {
    $response['message'] = 'New password must be different from your current password.';
    echo json_encode($response);
    exit;
}

// 5. UPDATE PASSWORD, CLEAR THE RESET TOKEN
$newHashed = password_hash($newPassword, PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    "UPDATE users
     SET password = ?,
         password_reset_token = NULL,
         password_reset_token_expires = NULL
     WHERE id = ?"
);
$stmt->execute([$newHashed, $user['id']]);

$response['success'] = true;
$response['message'] = 'Your password has been reset successfully. You may now log in.';

echo json_encode($response);