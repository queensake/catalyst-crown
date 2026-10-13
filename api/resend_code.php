<?php
// resend_code.php
// Generates a fresh 6-digit verification code and re-sends it via email.
// Works for BOTH:
//   - Registration verification (unverified accounts)
//   - 2FA login (verified accounts)
// Same 15-second cooldown applies to both.

require 'db.php';              // gives us $pdo
require 'email_config.php';    // gives us SMTP_HOST, SMTP_USERNAME, etc.

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

if ($email === '') {
    $response['message'] = 'Missing email.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 2. LOOK UP THE USER
// Works for both verified and unverified accounts
// -------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT id, username, is_verified, verification_last_sent FROM users WHERE email = ?"
);
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    $response['message'] = 'No account found for the provided email.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 3. ENFORCE THE 15-SECOND COOLDOWN
// Applies whether this is registration or 2FA resend
// -------------------------------------------------
if ($user['verification_last_sent'] !== null) {
    $secondsSinceLastSend = time() - strtotime($user['verification_last_sent']);
    if ($secondsSinceLastSend < 15) {
        $secondsLeft = 15 - $secondsSinceLastSend;
        $response['message'] = "Please wait for {$secondsLeft} more second(s) before requesting a new code.";
        $response['cooldown_seconds_left'] = $secondsLeft;
        echo json_encode($response);
        exit;
    }
}

// -------------------------------------------------
// 4. GENERATE A NEW CODE + UPDATE THE DATABASE
// -------------------------------------------------
$verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
$now = date('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    "UPDATE users
     SET verification_code = ?,
         verification_code_expires = ?,
         verification_last_sent = ?
     WHERE id = ?"
);
$stmt->execute([$verificationCode, $expiresAt, $now, $user['id']]);

// -------------------------------------------------
// 5. SEND THE CODE VIA EMAIL
// Message differs slightly based on whether they're verified or not
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
    
    if ($user['is_verified']) {
        // 2FA login resend
        $mail->Subject = 'Your new login verification code for Catalyst Crown';
        $mail->Body    = "Greetings {$user['username']}!,<br><br>"
                        . "Your new login verification code is: <strong>{$verificationCode}</strong><br><br>"
                        . "This code expires in 10 minutes. If you don't see this email, please check your spam or promotions folder. "
                        . "If you didn't request this, you can safely ignore this email.";
        $mail->AltBody = "Greetings {$user['username']}!, your new login verification code is {$verificationCode}. "
                        . "This code expires in 10 minutes. Please check spam if you don't see it. "
                        . "If you didn't request this, you can safely ignore this email.";
    } else {
        // Registration verification resend
        $mail->Subject = 'Your new verification code for Catalyst Crown';
        $mail->Body    = "Greetings {$user['username']}!,<br><br>"
                        . "Your new verification code is: <strong>{$verificationCode}</strong><br><br>"
                        . "This code expires in 10 minutes. If you don't see this email, please check your spam or promotions folder. "
                        . "If you did not request this action, kindly ignore this mail.";
        $mail->AltBody = "Greetings {$user['username']}!, your new verification code is {$verificationCode}. "
                        . "This code expires in 10 minutes. Please check spam if you don't see it. "
                        . "If you did not request this action, kindly ignore this mail.";
    }

    $mail->send();

    $response['success'] = true;
    $response['message'] = 'A new code has been sent to your email.';
    $response['note'] = 'The code will expire in 10 minutes.';
} catch (Exception $e) {
    $response['message'] = 'Could not send the verification email. Please try again. ' . $mail->ErrorInfo;
}

echo json_encode($response);