<?php
// notification_check.php
// Returns current notifications: upcoming task deadlines, daily habits not done today, and rewards unlocked in the last day.
// GET: type (all|deadline|reminder|milestone), limit (1-50)
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require 'db.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.', 'notifications' => []]);
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $type = $_GET['type'] ?? 'all';
    $notifications = [];

    // 1. Tasks due within the next 3 days (deadline is a DATE column)
    if ($type === 'all' || $type === 'deadline') {
        $stmt = $pdo->prepare(
            "SELECT id, name, deadline FROM tasks
             WHERE user_id = ? AND status = 'active' AND completed = 0
               AND deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
             ORDER BY deadline ASC LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        foreach ($stmt->fetchAll() as $d) {
            $notifications[] = [
                'type' => 'deadline',
                'id' => 'deadline_' . $d['id'],
                'title' => $d['name'],
                'message' => 'Due on ' . $d['deadline'],
                'is_read' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    // 2. Active daily habits with no completion recorded today
    if ($type === 'all' || $type === 'reminder') {
        $stmt = $pdo->prepare(
            "SELECT h.id, h.name FROM habits h
             WHERE h.user_id = ? AND h.status = 'active' AND h.frequency = 'daily'
               AND NOT EXISTS (
                   SELECT 1 FROM habit_completions c
                   WHERE c.habit_id = h.id AND c.user_id = h.user_id
                     AND c.completed_date = CURDATE() AND c.subtask_id IS NULL
               )
             LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        foreach ($stmt->fetchAll() as $h) {
            $notifications[] = [
                'type' => 'reminder',
                'id' => 'reminder_' . $h['id'],
                'title' => $h['name'],
                'message' => 'Daily habit not completed yet',
                'is_read' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    // 3. Rewards unlocked in the last 24 hours
    if ($type === 'all' || $type === 'milestone') {
        $stmt = $pdo->prepare(
            "SELECT id, reward_name, unlocked_at FROM completion_rewards
             WHERE user_id = ? AND unlocked = 1 AND unlocked_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
             ORDER BY unlocked_at DESC LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        foreach ($stmt->fetchAll() as $m) {
            $notifications[] = [
                'type' => 'milestone',
                'id' => 'milestone_' . $m['id'],
                'title' => 'Reward Unlocked',
                'message' => $m['reward_name'],
                'is_read' => 0,
                'created_at' => $m['unlocked_at'],
            ];
        }
    }

    usort($notifications, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    $notifications = array_slice($notifications, 0, $limit);

    echo json_encode(['success' => true, 'notifications' => $notifications, 'count' => count($notifications)]);
} catch (Throwable $e) {
    error_log('notification_check error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load notifications.', 'notifications' => []]);
}