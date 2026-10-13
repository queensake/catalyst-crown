<?php
// login_verify_2fa.php
// Step 2 of login: checks the code the user typed against the verification_code columns
// (reused from registration), and if it matches and hasn't expired, starts the real logged-in session.
// This is where "remember me" takes effect, since this is the point the user becomes logged in.

require 'db.php'; // gives us $pdo

header('Content-Type: application/json');

// The pending session set by login.php (pre-login, holds which user this code belongs to and their remember_me choice)
session_start();

$response = ['success' => false, 'message' => ''];

$enteredCode = trim($_POST['code'] ?? '');

if (!isset($_SESSION['pending_2fa_user_id'])) {
    $response['message'] = 'No login attempt in progress. Please log in again.';
    echo json_encode($response);
    exit;
}

if ($enteredCode === '') {
    $response['message'] = 'Please enter the verification code.';
    echo json_encode($response);
    exit;
}

$pendingUserId = $_SESSION['pending_2fa_user_id'];
$rememberMe    = $_SESSION['pending_2fa_remember'] ?? false;

// 1. Look up the stored code and expiry
$stmt = $pdo->prepare("SELECT id, username, verification_code, verification_code_expires FROM users WHERE id = ?");
$stmt->execute([$pendingUserId]);
$user = $stmt->fetch();

if (!$user) {
    $response['message'] = 'Account not found. Please log in again.';
    echo json_encode($response);
    exit;
}

// 2. Check expiry
$now = date('Y-m-d H:i:s');
if ($user['verification_code_expires'] === null || $now > $user['verification_code_expires']) {
    $response['message'] = 'This code has expired. Please log in again to get a new one.';
    echo json_encode($response);
    exit;
}

// 3. Check the code itself
if ($enteredCode !== $user['verification_code']) {
    $response['message'] = 'Incorrect verification code. Please try again.';
    echo json_encode($response);
    exit;
}

// 4. Success: clear the code and start the real session
$stmt = $pdo->prepare(
    "UPDATE users SET verification_code = NULL, verification_code_expires = NULL, verification_last_sent = NULL WHERE id = ?"
);
$stmt->execute([$user['id']]);

unset($_SESSION['pending_2fa_user_id']);
unset($_SESSION['pending_2fa_remember']);

// "Remember me" must be applied via session_set_cookie_params() before the session cookie is (re)issued.
if ($rememberMe) {
    $lifetime = 30 * 24 * 60 * 60; // 30 days
    // Keep the server-side session data this long too (not just the cookie)
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_regenerate_id(true);
    // The session cookie was already sent by session_start() without an expiry,
    // so send it again with one. A cookie with an expiry survives closing the browser.
    setcookie(session_name(), session_id(), [
        'expires'  => time() + $lifetime,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$_SESSION['user_id']  = $user['id'];
$_SESSION['username'] = $user['username'];

$response['success'] = true;
$response['message'] = 'Login successful.';
$response['username'] = $user['username'];
$response['security_notice'] = "If this wasn't you, change your password immediately.";
// Logged-in users change their password here (needs the current password).
// Forgot password is for when they can't log in, and it asks for their email.
$response['change_password_url'] = '/project_task_manager/pages/change_password.html';

echo json_encode($response);