<?php
// verification_code.php
// Checks the code the user typed against what's stored for their account.
// If it matches and hasn't expired, flips is_verified to TRUE and clears
// the code columns so it can't be reused.

require 'db.php'; // gives us $pdo

header('Content-Type: application/json');

// -------------------------------------------------
// 1. READ SUBMITTED DATA
// -------------------------------------------------
$email = trim($_POST['email'] ?? '');
$enteredCode = trim($_POST['verification_code'] ?? '');

$response = ['success' => false, 'message' => ''];

if ($email === '' || $enteredCode === '') {
    $response['message'] = 'Missing email or code.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 2. LOOK UP THE USER'S STORED CODE + EXPIRY
// -------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT id, verification_code, verification_code_expires, is_verified
     FROM users WHERE email = ?"
);
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    $response['message'] = 'No account found for that email.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 3. ALREADY VERIFIED? NOTHING TO DO
// -------------------------------------------------
if ($user['is_verified']) {
    $response['success'] = true;
    $response['message'] = 'This account is already verified.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 4. CHECK EXPIRY
// -------------------------------------------------
$now = date('Y-m-d H:i:s');
if ($user['verification_code_expires'] === null || $now > $user['verification_code_expires']) {
    $response['message'] = 'This code has expired. Please request a new one.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 5. CHECK THE CODE ITSELF
// -------------------------------------------------
if ($enteredCode !== $user['verification_code']) {
    $response['message'] = 'Incorrect code. Please try again.';
    echo json_encode($response);
    exit;
}

// -------------------------------------------------
// 6. SUCCESS — VERIFY THE ACCOUNT, CLEAR CODE COLUMNS
// -------------------------------------------------
$stmt = $pdo->prepare(
    "UPDATE users
     SET is_verified = TRUE,
         verification_code = NULL,
         verification_code_expires = NULL,
         verification_last_sent = NULL
     WHERE id = ?"
);
$stmt->execute([$user['id']]);

$response['success'] = true;
$response['message'] = 'Your account has been verified.';

echo json_encode($response);