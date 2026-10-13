<?php
// dashboard_modal.php
// Deep-dive for one task or habit. GET params: item_type (task|habit), item_name, period (week|month)
// Returns: progress % for the period, daily trend, day-of-week totals, subtask breakdown, streak info.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require 'db.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $itemType = $_GET['item_type'] ?? '';
    $itemName = trim($_GET['item_name'] ?? '');
    $period = $_GET['period'] ?? 'week';

    if (!in_array($itemType, ['task', 'habit'], true) || $itemName === '') {
        echo json_encode(['success' => false, 'message' => 'Provide item_type (task/habit) and item_name.']);
        exit;
    }
    if (!in_array($period, ['week', 'month'], true)) {
        echo json_encode(['success' => false, 'message' => 'Period must be week or month.']);
        exit;
    }

    $today = new DateTime('today');
    $start = $period === 'week'
        ? (clone $today)->modify('-6 days')
        : (clone $today)->modify('first day of this month');
    $startStr = $start->format('Y-m-d');
    $endStr = $today->format('Y-m-d');
    $days = (int)$start->diff($today)->days + 1;

    if ($itemType === 'task') {
        $stmt = $pdo->prepare("SELECT id FROM tasks WHERE name = ? AND user_id = ?");
        $stmt->execute([$itemName, $userId]);
        $item = $stmt->fetch();
        if (!$item) { echo json_encode(['success' => false, 'message' => 'Task not found.']); exit; }
        $itemId = (int)$item['id'];

        $stmt = $pdo->prepare(
            "SELECT completed_date AS d, COUNT(*) AS c FROM task_completions
             WHERE task_id = ? AND user_id = ? AND completed_date BETWEEN ? AND ? GROUP BY completed_date"
        );
        $stmt->execute([$itemId, $userId, $startStr, $endStr]);
        $perDay = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT id, name, completed FROM task_subtasks WHERE task_id = ? ORDER BY id ASC");
        $stmt->execute([$itemId]);
        $subtasks = array_map(fn($s) => ['name' => $s['name'], 'done' => (int)$s['completed'] === 1], $stmt->fetchAll());
    } else {
        $stmt = $pdo->prepare("SELECT id FROM habits WHERE name = ? AND user_id = ?");
        $stmt->execute([$itemName, $userId]);
        $item = $stmt->fetch();
        if (!$item) { echo json_encode(['success' => false, 'message' => 'Habit not found.']); exit; }
        $itemId = (int)$item['id'];

        $stmt = $pdo->prepare(
            "SELECT completed_date AS d, SUM(count_completed) AS c FROM habit_completions
             WHERE habit_id = ? AND user_id = ? AND subtask_id IS NULL AND completed_date BETWEEN ? AND ?
             GROUP BY completed_date"
        );
        $stmt->execute([$itemId, $userId, $startStr, $endStr]);
        $perDay = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT name, completed FROM habit_subtasks WHERE habit_id = ? ORDER BY id ASC");
        $stmt->execute([$itemId]);
        $subtasks = array_map(fn($s) => ['name' => $s['name'], 'done' => (int)$s['completed'] === 1], $stmt->fetchAll());
    }

    // Daily trend (every day in the period) and day-of-week totals
    $counts = [];
    foreach ($perDay as $row) $counts[$row['d']] = (int)$row['c'];

    $trend = [];
    $weekdays = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0, 'Sun' => 0];
    $activeDays = 0;
    $cursor = clone $start;
    while ($cursor <= $today) {
        $key = $cursor->format('Y-m-d');
        $c = $counts[$key] ?? 0;
        $trend[] = ['date' => $key, 'label' => $cursor->format('j M'), 'count' => $c];
        if ($c > 0) {
            $activeDays++;
            $weekdays[$cursor->format('D')] += $c;
        }
        $cursor->modify('+1 day');
    }

    // Streak info
    $stmt = $pdo->prepare(
        "SELECT current_streak, longest_streak, last_completed FROM streaks
         WHERE item_type = ? AND item_id = ? AND user_id = ? LIMIT 1"
    );
    $stmt->execute([$itemType, $itemId, $userId]);
    $streak = $stmt->fetch() ?: ['current_streak' => 0, 'longest_streak' => 0, 'last_completed' => null];

    echo json_encode([
        'success' => true,
        'item_type' => $itemType,
        'item_name' => $itemName,
        'period' => $period,
        'period_days' => $days,
        'active_days' => $activeDays,
        'consistency_percentage' => $days > 0 ? (int)round($activeDays / $days * 100) : 0,
        'trend' => $trend,
        'day_of_week' => $weekdays,
        'subtasks' => $subtasks,
        'streak_count' => (int)$streak['current_streak'],
        'longest_streak' => (int)$streak['longest_streak'],
        'last_completed_date' => $streak['last_completed'],
    ]);
} catch (Throwable $e) {
    error_log('dashboard_modal error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load details.']);
}