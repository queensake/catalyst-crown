<?php
// Recent in-app reminders for the logged-in user, including tasks, habits and child items.
require 'db.php';
header('Content-Type: application/json');
session_start();
$response = ['success' => false, 'notifications' => [], 'message' => ''];
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = 'You must be logged in.';
    echo json_encode($response);
    exit;
}
$userId = (int)$_SESSION['user_id'];
try {
    $sql = "SELECT rn.item_type, rn.item_id, rn.reminder_datetime, rn.notified_at,
                   CASE rn.item_type
                     WHEN 'task' THEN t.name
                     WHEN 'habit' THEN h.name
                     WHEN 'subtask' THEN st.name
                     WHEN 'subhabit' THEN hs.name
                   END AS item_name,
                   CASE rn.item_type
                     WHEN 'task' THEN 'Task reminder'
                     WHEN 'habit' THEN 'Habit reminder'
                     WHEN 'subtask' THEN 'Subtask reminder'
                     WHEN 'subhabit' THEN 'Subhabit reminder'
                   END AS reminder_label
            FROM reminder_notifications rn
            LEFT JOIN tasks t ON rn.item_type = 'task' AND rn.item_id = t.id
            LEFT JOIN habits h ON rn.item_type = 'habit' AND rn.item_id = h.id
            LEFT JOIN task_subtasks st ON rn.item_type = 'subtask' AND rn.item_id = st.id
            LEFT JOIN habit_subtasks hs ON rn.item_type = 'subhabit' AND rn.item_id = hs.id
            WHERE rn.user_id = ?
            ORDER BY rn.notified_at DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['title'] = $row['item_name'] ?: 'Reminder';
        $row['message'] = ($row['reminder_label'] ?: 'Reminder') . ': ' . ($row['item_name'] ?: 'An item is due');
    }
    unset($row);
    $response['success'] = true;
    $response['notifications'] = $rows;
} catch (Throwable $e) {
    error_log('Notification bell panel error: ' . $e->getMessage());
    $response['message'] = 'Could not load in-app reminders.';
}
echo json_encode($response);
