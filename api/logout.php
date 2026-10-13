<?php
// logout.php
// Ends user session

header('Content-Type: application/json');
session_start();

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'No active session.';
    echo json_encode($response);
    exit;
}

// Destroy session
session_destroy();

$response['success'] = true;
$response['message'] = 'Logged out successfully.';

echo json_encode($response);