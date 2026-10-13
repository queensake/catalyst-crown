<?php
// profile_update.php
// Updates user profile. Can update: email, password
// Password change requires old password verification

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
$newEmail = trim($_POST['email'] ?? '');
$newPassword = $_POST['new_password'] ?? '';
$oldPassword = $_POST['old_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// Get current user data
$stmt = $pdo->prepare("SELECT email, password FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    $response['message'] = 'User not found.';
    echo json_encode($response);
    exit;
}

$updates = [];
$params = [];

// Email update
if ($newEmail !== '') {
    // Validate email format
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Invalid email format.';
        echo json_encode($response);
        exit;
    }

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$newEmail, $userId]);
    if ($stmt->fetch()) {
        $response['message'] = 'Email already in use.';
        echo json_encode($response);
        exit;
    }

    $updates[] = "email = ?";
    $params[] = $newEmail;
}

// Password update
if ($newPassword !== '') {
    // Require old password for security
    if ($oldPassword === '') {
        $response['message'] = 'Old password required to change password.';
        echo json_encode($response);
        exit;
    }

    // Verify old password
    if (!password_verify($oldPassword, $user['password'])) {
        $response['message'] = 'Old password is incorrect.';
        echo json_encode($response);
        exit;
    }

    // Validate new password
    if (strlen($newPassword) < 8) {
        $response['message'] = 'Password must be at least 8 characters.';
        echo json_encode($response);
        exit;
    }

    if (!preg_match('/[A-Z]/', $newPassword)) {
        $response['message'] = 'Password must contain at least one uppercase letter.';
        echo json_encode($response);
        exit;
    }

    if (!preg_match('/[a-z]/', $newPassword)) {
        $response['message'] = 'Password must contain at least one lowercase letter.';
        echo json_encode($response);
        exit;
    }

    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $newPassword)) {
        $response['message'] = 'Password must contain at least one special character.';
        echo json_encode($response);
        exit;
    }

    // Confirm password match
    if ($newPassword !== $confirmPassword) {
        $response['message'] = 'Passwords do not match.';
        echo json_encode($response);
        exit;
    }

    // Hash password
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
    $updates[] = "password = ?";
    $params[] = $hashedPassword;
}

// If no updates, return error
if (empty($updates)) {
    $response['message'] = 'No fields to update.';
    echo json_encode($response);
    exit;
}

// Execute update
$params[] = $userId;
$sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$response['success'] = true;
$response['message'] = 'Profile updated successfully.';

if ($newEmail !== '') {
    $response['email_updated'] = true;
}

if ($newPassword !== '') {
    $response['password_updated'] = true;
}

echo json_encode($response);