<?php
// register.php
// Handles new account creation: validates input, hashes password,
// generates a 6-digit verification code, emails it via PHPMailer,
// and inserts the new unverified user into the database.

require 'db.php';              // gives us $pdo
require 'email_config.php';    // gives us SMTP_HOST, SMTP_USERNAME, etc.

// Manual (non-Composer) PHPMailer include — 3 files, same folder
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

require 'rate_limiter.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

// -------------------------------------------------
// 1. READ THE SUBMITTED FORM DATA
// -------------------------------------------------
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';

$response = ['success' => false, 'message' => ''];

// -------------------------------------------------
// 2. VALIDATE INPUT
// -------------------------------------------------
if ($username === '' || $email === '' || $password === '' || $confirm === '') {
    $response['message'] = 'Please fill every field.';
    echo json_encode($response);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $response['message'] = 'Please enter a valid email address.';
    echo json_encode($response);
    exit;
}

// Password strength check: min 8 chars, at least 1 uppercase, 1 lowercase, 1 special character
if (
    strlen($password) < 8 ||
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[a-z]/', $password) ||
    !preg_match('/[\W_]/', $password)
) {
    $response['message'] = 'Password must be at least 8 characters long, include at least one uppercase and lowercase letter, and a special character.';
    echo json_encode($response);
    exit;
}

if ($password !== $confirm) {
    $response['message'] = 'Passwords do not match.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 3. CHECK RATE LIMIT
// -------------------------------------------------
$limiter = new RateLimiter($pdo);
$limitCheck = $limiter->checkRateLimit($email, 'register');
if (!$limitCheck['allowed']) {
    $response['success'] = false;
    $response['message'] = "Too many registration attempts from this email. Please try again later.";
    $response['cooldown_seconds_left'] = ceil((strtotime($limitCheck['locked_until']) - time()));
    http_response_code(429);
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 4. CHECK FOR DUPLICATE USERNAME
// -------------------------------------------------
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);
if ($stmt->fetch()) {
    $limiter->recordFailedAttempt($email, 'register');
    $response['message'] = 'The username is taken.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 5. CHECK FOR DUPLICATE EMAIL
// -------------------------------------------------
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    $limiter->recordFailedAttempt($email, 'register');
    $response['message'] = 'The email is already registered.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 6. HASH PASSWORD + GENERATE VERIFICATION CODE
// -------------------------------------------------
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

// 6-digit numeric code, e.g. "042917"
// random_int(0, 999999) can generate numbers like 4829 (4 digits) or 917 (3 digits)
// so we pad with leading zeros to ensure exactly 6 digits
$verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
$now = date('Y-m-d H:i:s');

// -------------------------------------------------
// 7. INSERT THE NEW USER (unverified)
// -------------------------------------------------
$stmt = $pdo->prepare(
    "INSERT INTO users (username, email, password, is_verified, verification_code, verification_code_expires, verification_last_sent)
     VALUES (?, ?, ?, FALSE, ?, ?, ?)"
);
$stmt->execute([$username, $email, $hashedPassword, $verificationCode, $expiresAt, $now]);

// -------------------------------------------------
// 8. SEND THE VERIFICATION CODE VIA EMAIL (PHPMailer)
// -------------------------------------------------
$mail = new PHPMailer(true); // true = enable exceptions

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
    $mail->addAddress($email, $username);

    $mail->isHTML(true);
    $mail->Subject = 'Verification code for Catalyst Crown';
    $mail->Body    = "Greetings {$username}!,<br><br>Your verification code is: <strong>{$verificationCode}</strong><br><br>This code expires in 10 minutes.";
    $mail->AltBody = "Greetings {$username}!, your verification code is {$verificationCode}. This code expires in 10 minutes.";

    $mail->send();

    // Record successful attempt (resets counter)
    $limiter->recordSuccessfulAttempt($email, 'register');

    $response['success'] = true;
    $response['message'] = 'Account has been successfully created. Enter the verification code sent to your email.';
    $response['note'] = 'The code will expire in 10 minutes.';
} catch (Exception $e) {
    // Account was already inserted — don't fail silently, tell the truth
    // Email send failed, but account creation succeeded, so don't record as failed attempt
    $response['success'] = true;
    $response['message'] = 'Your account is ready, but we could not send your confirmation email. Please try again.';
}

echo json_encode($response);
?>