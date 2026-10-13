<?php
// change_password.php
// Lets a logged-in user change their password. Requires their CURRENT
// password as confirmation (not just being logged in) — this matters
// especially for the "wasn't you? change your password" flow, since
// if it really wasn't them, they won't know the current password anyway
// and this correctly blocks them.

require 'db.php'; // gives us $pdo

header('Content-Type: application/json');

session_start();

$response = ['success' => false, 'message' => ''];

// -------------------------------------------------
// 1. MUST BE LOGGED IN
// -------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'You must be logged in to change your password.';
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id'];

// -------------------------------------------------
// 2. READ SUBMITTED DATA
// -------------------------------------------------
$currentPassword = $_POST['current_password'] ?? '';
$newPassword     = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    $response['message'] = 'Please fill every field.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 3. VERIFY CURRENT PASSWORD
// -------------------------------------------------
$stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    $response['message'] = 'Account not found.';
    echo json_encode($response);
    exit;
}

if (!password_verify($currentPassword, $user['password'])) {
    $response['message'] = 'Current password is incorrect.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 4. NEW PASSWORD VALIDATION (same rule as register.php:
//    8+ chars, upper, lower, special char)
// -------------------------------------------------
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
    $response['message'] = 'New password and confirmation do not match.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 5. DON'T ALLOW REUSING THE SAME PASSWORD
// -------------------------------------------------
if (password_verify($newPassword, $user['password'])) {
    $response['message'] = 'New password must be different from your current password.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 6. UPDATE THE PASSWORD
// -------------------------------------------------
$newHashed = password_hash($newPassword, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->execute([$newHashed, $userId]);

$response['success'] = true;
$response['message'] = 'Your password has been changed successfully.';

echo json_encode($response);