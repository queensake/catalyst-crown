<?php
// dashboard_carousel.php
// Up to 3 cards for the dashboard carousel: active tasks and habits, newest first.
// Each card: progress %, current streak, and change in completions this month vs last month.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require 'db.php';
require_once __DIR__ . '/journal_lib.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function periodStart(string $frequency): string {
    $d = new DateTime('today');
    if ($frequency === 'weekly') $d->modify('monday this week');
    elseif ($frequency === 'monthly') $d->modify('first day of this month');
    elseif ($frequency === 'yearly') $d->setDate((int)$d->format('Y'), 1, 1);
    return $d->format('Y-m-d');
}

function deltaPercent(int $thisMonth, int $lastMonth): int {
    if ($lastMonth === 0) return $thisMonth > 0 ? 100 : 0;
    return (int)round(($thisMonth - $lastMonth) / $lastMonth * 100);
}

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.', 'carousel_items' => []]);
        exit;
    }
    $userId = $_SESSION['user_id'];
    syncHabitStreaks($pdo, $userId);

    $thisStart = (new DateTime('first day of this month'))->format('Y-m-d');
    $lastStart = (new DateTime('first day of last month'))->format('Y-m-d');
    $lastEnd = (new DateTime('last day of last month'))->format('Y-m-d');

    // Newest 3 active items across tasks and habits
    $stmt = $pdo->prepare(
        "SELECT 'task' AS type, id, name, created_at FROM tasks
         WHERE user_id = ? AND status = 'active' AND completed = FALSE
         UNION ALL
         SELECT 'habit' AS type, id, name, created_at FROM habits
         WHERE user_id = ? AND status = 'active'
         ORDER BY created_at DESC LIMIT 3"
    );
    $stmt->execute([$userId, $userId]);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];

        $streakStmt = $pdo->prepare(
            "SELECT current_streak FROM streaks WHERE item_type = ? AND item_id = ? AND user_id = ? LIMIT 1"
        );
        $streakStmt->execute([$row['type'], $id, $userId]);
        $streak = (int)($streakStmt->fetchColumn() ?: 0);

        if ($row['type'] === 'task') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS total, COALESCE(SUM(completed = 1), 0) AS done FROM task_subtasks WHERE task_id = ?"
            );
            $stmt->execute([$id]);
            $c = $stmt->fetch();
            $percentage = (int)$c['total'] > 0 ? (int)round($c['done'] / $c['total'] * 100) : 0;

            $countSql = "SELECT COUNT(*) FROM task_completions WHERE task_id = ? AND user_id = ? AND completed_date ";
            $idParams = [$id, $userId];
        } else {
            $stmt = $pdo->prepare("SELECT frequency, times_per_frequency, habit_type FROM habits WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $h = $stmt->fetch();
            $target = max(1, (int)$h['times_per_frequency']);

            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(count_completed), 0) FROM habit_completions
                 WHERE habit_id = ? AND user_id = ? AND subtask_id IS NULL AND completed_date >= ?"
            );
            $stmt->execute([$id, $userId, periodStart($h['frequency'])]);
            $periodCount = (int)$stmt->fetchColumn();
            $percentage = ($h['habit_type'] ?? 'build') === 'avoid'
                ? ($periodCount > $target ? 0 : 100)
                : min(100, (int)round($periodCount / $target * 100));

            $countSql = "SELECT COALESCE(SUM(count_completed), 0) FROM habit_completions
                         WHERE habit_id = ? AND user_id = ? AND subtask_id IS NULL AND completed_date ";
            $idParams = [$id, $userId];
        }

        $stmt = $pdo->prepare($countSql . ">= ?");
        $stmt->execute(array_merge($idParams, [$thisStart]));
        $thisMonth = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare($countSql . "BETWEEN ? AND ?");
        $stmt->execute(array_merge($idParams, [$lastStart, $lastEnd]));
        $lastMonth = (int)$stmt->fetchColumn();

        $delta = deltaPercent($thisMonth, $lastMonth);
        $items[] = [
            'type' => $row['type'],
            'id' => $id,
            'name' => $row['name'],
            'percentage' => $percentage,
            'streak' => $streak,
            'delta' => $delta,
            'delta_direction' => $delta >= 0 ? 'up' : 'down',
        ];
    }

    echo json_encode(['success' => true, 'carousel_items' => $items, 'count' => count($items)]);
} catch (Throwable $e) {
    error_log('dashboard_carousel error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load dashboard.', 'carousel_items' => []]);
}