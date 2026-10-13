<?php
// login.php
// Step 1 of login: checks identifier + password, checks is_verified.
// On success, does NOT start the logged-in session yet — instead
// generates a 2FA code (reusing the same verification_code columns
// used at registration), emails it, and tells the frontend to show
// the code-entry screen. The real session only starts in
// login_verify_2fa.php once the code is confirmed.

require 'db.php';              // gives us $pdo
require 'email_config.php';    // gives us SMTP_HOST, SMTP_USERNAME, etc.

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'rate_limiter.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
session_start();

$response = ['success' => false, 'message' => ''];
$limiter = new RateLimiter($pdo);

// -------------------------------------------------
// 1. READ SUBMITTED DATA
// -------------------------------------------------
$identifier = trim($_POST['identifier'] ?? '');
$password   = $_POST['password'] ?? '';
$rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === 'true';

if ($identifier === '' || $password === '') {
    $response['message'] = 'Please enter your username/email and password.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 2. CHECK RATE LIMIT
// -------------------------------------------------
$limitCheck = $limiter->checkRateLimit($identifier, 'login');
if (!$limitCheck['allowed']) {
    $response['message'] = "Too many login attempts. Please try again later.";
    $response['cooldown_seconds_left'] = ceil((strtotime($limitCheck['locked_until']) - time()));
    http_response_code(429);
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 3. LOOK UP THE USER BY USERNAME OR EMAIL
// -------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
$stmt->execute([$identifier, $identifier]);
$user = $stmt->fetch();

if (!$user) {
    $limiter->recordFailedAttempt($identifier, 'login');
    $response['message'] = 'Invalid username or password.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 4. CHECK PASSWORD
// -------------------------------------------------
if (!password_verify($password, $user['password'])) {
    $limiter->recordFailedAttempt($identifier, 'login');
    $response['message'] = 'Invalid username or password.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 5. BLOCK LOGIN IF EMAIL NOT VERIFIED (registration step, unrelated to 2FA)
// -------------------------------------------------
if (!$user['is_verified']) {
    $limiter->recordFailedAttempt($identifier, 'login');
    $response['message'] = 'Please verify your email before logging in.';
    $response['needs_verification'] = true;
    $response['email'] = $user['email'];
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 6. PASSWORD OK — CHECK COOLDOWN BEFORE SENDING 2FA CODE
// Prevent spam-clicking login by enforcing 15s between attempts
// -------------------------------------------------
if ($user['verification_last_sent'] !== null) {
    $secondsSinceLastSend = time() - strtotime($user['verification_last_sent']);
    if ($secondsSinceLastSend < 15) {
        $secondsLeft = 15 - $secondsSinceLastSend;
        $response['success'] = false;
        $response['message'] = "Please wait {$secondsLeft} more second(s) before logging in again.";
        $response['cooldown_seconds_left'] = $secondsLeft;
        echo json_encode($response);
        exit;
    }
}

// -------------------------------------------------
// 7. GENERATE + SEND 2FA CODE
// Reuses verification_code / verification_code_expires /
// verification_last_sent columns (safe to reuse since a
// verified user has no live registration code sitting there).
// -------------------------------------------------
$loginCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
$now = date('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    "UPDATE users
     SET verification_code = ?,
         verification_code_expires = ?,
         verification_last_sent = ?
     WHERE id = ?"
);
$stmt->execute([$loginCode, $expiresAt, $now, $user['id']]);

// Stash the remember_me choice + which user this code belongs to in a
// short-lived PHP session, so login_verify_2fa.php knows what to do
// once the code is confirmed, WITHOUT starting the real logged-in
// session yet. This is a separate, pending-only session state.
$_SESSION['pending_2fa_user_id']  = $user['id'];
$_SESSION['pending_2fa_remember'] = $rememberMe;

// Full address of the site, so links in emails work locally and on Railway.
// Set APP_URL on Railway (e.g. https://your-app.up.railway.app). Local default below.
$appUrl = rtrim(getenv('APP_URL') ?: 'http://localhost/project_task_manager', '/');
$resetUrl = $appUrl . '/pages/forgot_password.html';

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
    $mail->addAddress($user['email'], $user['username']);

    $mail->isHTML(true);
    $mail->Subject = 'Login verification code for Catalyst Crown';
    $mail->Body    = "Hello {$user['username']},<br><br>"
                    . "We detected a login attempt on your account. Your verification code is: <strong>{$loginCode}</strong><br><br>"
                    . "This code expires in 10 minutes.<br><br>"
                    . "If this wasn't you, <a href='{$resetUrl}'>reset your password here</a>.";
    $mail->AltBody = "Hello {$user['username']}, we detected a login attempt on your account. "
                    . "Your verification code is {$loginCode}. This code expires in 10 minutes. "
                    . "If this wasn't you, reset your password here: {$resetUrl}";

    $mail->send();

    // Record successful attempt (resets counter)
    $limiter->recordSuccessfulAttempt($identifier, 'login');

    $response['success'] = true;
    $response['needs_2fa'] = true;
    $response['message'] = 'A verification code has been sent to your email.';
    $response['email'] = $user['email']; // so frontend can display/prefill
} catch (Exception $e) {
    $response['message'] = 'Could not send the verification code. Please try again.';
}

echo json_encode($response);
?>