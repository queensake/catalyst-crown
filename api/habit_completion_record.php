<?php
// habit_completion_record.php
// Logs one completion for a habit, then recalculates its streak.
// POST: habit_name, completed_date (YYYY-MM-DD, optional, defaults to today)
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/journal_lib.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $habitName = trim($_POST['habit_name'] ?? '');
    if ($habitName === '') {
        echo json_encode(['success' => false, 'message' => 'Provide habit_name.']);
        exit;
    }

    $todayStr = (new DateTime('today'))->format('Y-m-d');
    $dateStr = trim($_POST['completed_date'] ?? $todayStr);
    $date = DateTime::createFromFormat('!Y-m-d', $dateStr);
    if (!$date || $date->format('Y-m-d') !== $dateStr) {
        echo json_encode(['success' => false, 'message' => 'Invalid date. Use YYYY-MM-DD.']);
        exit;
    }
    if ($dateStr > $todayStr) {
        echo json_encode(['success' => false, 'message' => 'Future dates cannot be logged.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, frequency, times_per_frequency, habit_type FROM habits WHERE name = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$habitName, $userId]);
    $habit = $stmt->fetch();
    if (!$habit) {
        echo json_encode(['success' => false, 'message' => 'Habit not found.']);
        exit;
    }
    $habitId = (int)$habit['id'];
    $target = max(1, (int)$habit['times_per_frequency']);
    $isAvoid = $habit['habit_type'] === 'avoid';

    [$periodStart, $periodEnd] = periodBounds($habit['frequency'], $date);
    $countInPeriod = habitCountInRange($pdo, $userId, $habitId, $periodStart, $periodEnd) + 1;

    $pdo->beginTransaction();
    $pdo->prepare(
        "INSERT INTO habit_completions (user_id, habit_id, subtask_id, completed_date, count_completed)
         VALUES (?, ?, NULL, ?, 1)"
    )->execute([$userId, $habitId, $dateStr]);

    syncHabitStreaks($pdo, $userId);

    $stmt = $pdo->prepare("SELECT current_streak FROM streaks WHERE item_type = 'habit' AND item_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$habitId, $userId]);
    $streak = (int)($stmt->fetchColumn() ?: 0);

    $pdo->commit();

    if ($isAvoid && $countInPeriod > $target) {
        $message = 'Limit exceeded. Streak broken.';
    } elseif (!$isAvoid && $countInPeriod >= $target) {
        $message = "Target reached! Streak: $streak";
    } elseif ($isAvoid) {
        $message = "Logged. Within limit: $countInPeriod/$target this period. Streak: $streak";
    } else {
        $message = "Logged. $countInPeriod/$target this period.";
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'count_in_period' => $countInPeriod,
        'target' => $target,
        'streak_count' => $streak,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('habit_completion_record error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not log the completion.']);
}