<?php
// streak_list.php
// Returns the current (non-zero) streaks for the logged-in user's active habits and tasks.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require 'db.php';
require_once __DIR__ . '/journal_lib.php';
require_once __DIR__ . '/task_streak_lib.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.', 'streaks' => []]);
        exit;
    }
    $userId = $_SESSION['user_id'];
    syncHabitStreaks($pdo, $userId);
    syncTaskStreak($pdo, $userId);

    $stmt = $pdo->prepare(
        "SELECT s.item_type, s.item_id, s.current_streak, COALESCE(h.name, t.name) AS name
         FROM streaks s
         LEFT JOIN habits h ON s.item_type = 'habit' AND h.id = s.item_id
                            AND h.user_id = s.user_id AND h.status = 'active'
         LEFT JOIN tasks t ON s.item_type = 'task' AND t.id = s.item_id
                           AND t.user_id = s.user_id AND t.status = 'active' AND t.completed = FALSE
         WHERE s.user_id = ? AND s.current_streak > 0 AND (h.id IS NOT NULL OR t.id IS NOT NULL)
         ORDER BY s.current_streak DESC"
    );
    $stmt->execute([$userId]);
    $streaks = array_map(fn($r) => [
        'item_type' => $r['item_type'],
        'name' => $r['name'],
        'current_streak' => (int)$r['current_streak'],
    ], $stmt->fetchAll());

    $acct = $pdo->prepare("SELECT task_streak FROM users WHERE id = ?");
    $acct->execute([$userId]);
    $taskStreak = (int)$acct->fetchColumn();
    if ($taskStreak > 0) {
        $streaks[] = ['item_type' => 'account', 'name' => 'Tasks on time', 'current_streak' => $taskStreak];
    }
    echo json_encode(['success' => true, 'streaks' => $streaks]);
} catch (Throwable $e) {
    error_log('streak_list error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load streaks.', 'streaks' => []]);
}