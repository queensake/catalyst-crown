<?php
// forgot_password.php
// User submits email → generates a random reset token, stores it with
// 1-hour expiry, emails a link, then user clicks that link to reset password.

require 'db.php';              // gives us $pdo
require 'email_config.php';    // gives us SMTP_HOST, SMTP_USERNAME, etc.
require 'rate_limiter.php';    // Rate limiting

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

// -------------------------------------------------
// 1. READ SUBMITTED DATA
// -------------------------------------------------
$email = trim($_POST['email'] ?? '');
$response = ['success' => false, 'message' => ''];

// -------------------------------------------------
// 2. CHECK RATE LIMIT (before validation)
// -------------------------------------------------
$limiter = new RateLimiter($pdo);
$limitCheck = $limiter->checkRateLimit($email, 'forgot_password');
if (!$limitCheck['allowed']) {
    $response['success'] = false;
    $response['message'] = "Too many password reset requests. Please try again in 15 minutes.";
    $response['cooldown_seconds_left'] = ceil((strtotime($limitCheck['locked_until']) - time()));
    http_response_code(429);
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 3. VALIDATE EMAIL
// -------------------------------------------------
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $response['message'] = 'Please enter a valid email address.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 4. LOOK UP THE USER
// -------------------------------------------------
$stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

// IMPORTANT: Don't reveal whether email exists. Always return success.
// But record the attempt even if user doesn't exist (prevents enumeration + rate limits anyway)
if (!$user) {
    $limiter->recordFailedAttempt($email, 'forgot_password');
    $response['success'] = true;
    $response['message'] = 'If an account exists for that email, a password reset link has been sent.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 5. GENERATE A RESET TOKEN & SET EXPIRY (1 HOUR)
// -------------------------------------------------
$resetToken = bin2hex(random_bytes(32)); // 64 random hex characters
$expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

$stmt = $pdo->prepare(
    "UPDATE users
     SET password_reset_token = ?,
         password_reset_token_expires = ?
     WHERE id = ?"
);
$stmt->execute([$resetToken, $expiresAt, $user['id']]);

// -------------------------------------------------
// 6. EMAIL THE RESET LINK
// -------------------------------------------------
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
    $mail->addAddress($email, $user['username']);

    $mail->isHTML(true);
    $mail->Subject = 'Reset your Catalyst Crown password';
    
    $resetLink = 'http://localhost/project_task_manager/pages/forgot_password.html?token=' . urlencode($resetToken);
    
    $mail->Body    = "Greetings {$user['username']}!,<br><br>"
                    . "We received a request to reset your password. "
                    . "<a href='{$resetLink}'>Click here to reset your password</a>.<br><br>"
                    . "This link expires in 1 hour.<br>"
                    . "If you didn't request this, you can safely ignore this email; your password will remain unchanged.";
                    
    $mail->AltBody = "Greetings {$user['username']}!, we received a request to reset your password. "
                    . "Visit this link to reset it: {$resetLink} "
                    . "This link expires in 1 hour.";

    $mail->send();

    // Record successful attempt (resets counter)
    $limiter->recordSuccessfulAttempt($email, 'forgot_password');

    $response['success'] = true;
    $response['message'] = 'If an account exists for that email, a password reset link has been sent.';
} catch (Exception $e) {
    // Record failed attempt (email send failed)
    $limiter->recordFailedAttempt($email, 'forgot_password');
    
    $response['message'] = 'Something went wrong sending the reset email. Please try again.';
}

echo json_encode($response);
?>