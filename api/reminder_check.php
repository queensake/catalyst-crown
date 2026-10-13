<?php
// Run periodically (Railway Cron/Job or cron) to enqueue due in-app reminders.
require 'db.php';
header('Content-Type: application/json');
$response = ['success' => false, 'checked' => 0, 'notified' => 0, 'message' => ''];

try {
    // Use MySQL's clock for comparisons so PHP/MySQL timezone differences don't skip due reminders.
    // item_type/item_id/reminder_datetime uniquely identify a reminder in reminder_notifications.
    $queries = [
        ['task', "SELECT t.user_id, t.id, t.name, t.reminder_datetime FROM tasks t WHERE t.reminder_datetime IS NOT NULL AND t.notification_enabled = 1 AND t.reminder_datetime <= NOW() AND COALESCE(t.completed, 0) = 0 AND COALESCE(t.status, 'active') = 'active'"],
        ['habit', "SELECT h.user_id, h.id, h.name, h.reminder_datetime FROM habits h WHERE h.reminder_datetime IS NOT NULL AND h.notification_enabled = 1 AND h.reminder_datetime <= NOW() AND COALESCE(h.status, 'active') = 'active'"],
        ['subtask', "SELECT t.user_id, st.id, st.name, st.reminder_datetime FROM task_subtasks st JOIN tasks t ON t.id = st.task_id WHERE st.reminder_datetime IS NOT NULL AND st.notification_enabled = 1 AND st.reminder_datetime <= NOW() AND COALESCE(t.completed, 0) = 0 AND COALESCE(t.status, 'active') = 'active'"],
        ['subhabit', "SELECT h.user_id, hs.id, hs.name, hs.reminder_datetime FROM habit_subtasks hs JOIN habits h ON h.id = hs.habit_id WHERE hs.reminder_datetime IS NOT NULL AND hs.notification_enabled = 1 AND hs.reminder_datetime <= NOW() AND COALESCE(h.status, 'active') = 'active'"],
    ];

    $insert = $pdo->prepare("INSERT INTO reminder_notifications (user_id, item_type, item_id, reminder_datetime, notified_at) SELECT ?, ?, ?, ?, NOW() WHERE NOT EXISTS (SELECT 1 FROM reminder_notifications WHERE user_id = ? AND item_type = ? AND item_id = ? AND reminder_datetime = ?)");
    foreach ($queries as [$itemType, $sql]) {
        $stmt = $pdo->prepare($sql . ' ORDER BY reminder_datetime ASC LIMIT 200');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['checked'] += count($rows);
        foreach ($rows as $row) {
            $insert->execute([$row['user_id'], $itemType, $row['id'], $row['reminder_datetime'], $row['user_id'], $itemType, $row['id'], $row['reminder_datetime']]);
            $response['notified'] += $insert->rowCount();
        }
    }
    $response['success'] = true;
} catch (Throwable $e) {
    error_log('Reminder check error: ' . $e->getMessage());
    $response['message'] = 'Could not check reminders. Verify reminder_notifications and notification columns exist.';
}
echo json_encode($response);
