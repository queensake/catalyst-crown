<?php
// streak_calculate.php
// Returns streak info for a task or habit.
// Tasks: 100% subtask rule. Habits: current streak plus progress in the current period.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require 'db.php'; // gives us $pdo
header('Content-Type: application/json');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Start of the current period for a habit frequency
function periodStart(string $frequency): string {
    $d = new DateTime('today');
    if ($frequency === 'weekly') {
        $d->modify('monday this week');
    } elseif ($frequency === 'monthly') {
        $d->modify('first day of this month');
    } elseif ($frequency === 'yearly') {
        $d->setDate((int)$d->format('Y'), 1, 1);
    }
    return $d->format('Y-m-d');
}

// Current streak row for an item, or zeros if none exists yet
function fetchStreak(PDO $pdo, $userId, string $itemType, int $itemId): array {
    $stmt = $pdo->prepare(
        "SELECT current_streak, longest_streak, last_completed
         FROM streaks WHERE item_type = ? AND item_id = ? AND user_id = ? LIMIT 1"
    );
    $stmt->execute([$itemType, $itemId, $userId]);
    $row = $stmt->fetch();
    return $row ?: ['current_streak' => 0, 'longest_streak' => 0, 'last_completed' => null];
}

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $itemType = $_POST['item_type'] ?? '';
    $itemName = trim($_POST['item_name'] ?? '');

    if ($itemType === '' || $itemName === '') {
        echo json_encode(['success' => false, 'message' => 'Please provide item_type (task/habit) and item_name.']);
        exit;
    }
    if (!in_array($itemType, ['task', 'habit'], true)) {
        echo json_encode(['success' => false, 'message' => 'Item type must be task or habit.']);
        exit;
    }

    if ($itemType === 'task') {
        $stmt = $pdo->prepare("SELECT id FROM tasks WHERE name = ? AND user_id = ?");
        $stmt->execute([$itemName, $userId]);
        $item = $stmt->fetch();
        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Task not found.']);
            exit;
        }
        $itemId = (int)$item['id'];

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(completed = 1), 0) AS done
             FROM task_subtasks WHERE task_id = ?"
        );
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();
        $total = (int)$row['total'];
        $done = (int)$row['done'];
        $pct = $total > 0 ? (int)round($done / $total * 100) : 100;

        $streak = fetchStreak($pdo, $userId, 'task', $itemId);

        echo json_encode([
            'success' => true,
            'item_type' => 'task',
            'item_name' => $itemName,
            'total_subtasks' => $total,
            'completed_subtasks' => $done,
            'completion_percentage' => $pct,
            'meets_100_percent_rule' => $pct === 100,
            'streak_count' => (int)$streak['current_streak'],
            'longest_streak' => (int)$streak['longest_streak'],
            'last_completed_date' => $streak['last_completed'],
        ]);
        exit;
    }

    // Habit
    $stmt = $pdo->prepare(
        "SELECT id, frequency, times_per_frequency, habit_type FROM habits WHERE name = ? AND user_id = ?"
    );
    $stmt->execute([$itemName, $userId]);
    $item = $stmt->fetch();
    if (!$item) {
        echo json_encode(['success' => false, 'message' => 'Habit not found.']);
        exit;
    }
    $itemId = (int)$item['id'];
    $target = max(1, (int)$item['times_per_frequency']);

    // Completions in the current period (habit-level rows, not subtasks)
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(count_completed), 0) AS cnt
         FROM habit_completions
         WHERE habit_id = ? AND user_id = ? AND subtask_id IS NULL AND completed_date >= ?"
    );
    $stmt->execute([$itemId, $userId, periodStart($item['frequency'])]);
    $periodCount = (int)$stmt->fetch()['cnt'];

    $streak = fetchStreak($pdo, $userId, 'habit', $itemId);

    $daysSince = null;
    if (!empty($streak['last_completed'])) {
        $daysSince = (new DateTime('today'))->diff(new DateTime($streak['last_completed']))->days;
    }

    echo json_encode([
        'success' => true,
        'item_type' => 'habit',
        'item_name' => $itemName,
        'habit_type' => $item['habit_type'],
        'frequency' => $item['frequency'],
        'target_per_period' => $target,
        'current_period_count' => $periodCount,
        'period_progress_percent' => min(100, (int)round($periodCount / $target * 100)),
        'streak_count' => (int)$streak['current_streak'],
        'longest_streak' => (int)$streak['longest_streak'],
        'last_completed_date' => $streak['last_completed'],
        'days_since_completion' => $daysSince,
    ]);
} catch (Throwable $e) {
    error_log('streak_calculate error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error while calculating streak.']);
}