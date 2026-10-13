<?php
// notification_mark_read.php
// Marks a notification as read (if using persistent notification storage)
// For now, notifications are transient (calculated on demand)
// This endpoint is reserved for future use with notification history table

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
$notificationId = trim($_POST['notification_id'] ?? '');

if ($notificationId === '') {
    $response['message'] = 'Please provide notification_id.';
    echo json_encode($response);
    exit;
}

// Current implementation: notifications are transient (calculated per-request)
// If persistent notification history is added, mark here:
// $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
// $stmt->execute([$notificationId, $userId]);

$response['success'] = true;
$response['message'] = 'Notification marked as read.';

echo json_encode($response);