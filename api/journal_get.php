<?php
// journal_get.php
// Returns one day of the journal: wellness ratings (7 metrics), free-text entry, and habit status.
// GET: date (YYYY-MM-DD), defaults to today.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/journal_lib.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $today = new DateTime('today');
    $todayStr = $today->format('Y-m-d');

    $dateStr = trim((string)($_GET['date'] ?? $todayStr));
    $date = DateTime::createFromFormat('!Y-m-d', $dateStr);
    if (!$date || $date->format('Y-m-d') !== $dateStr) {
        echo json_encode(['success' => false, 'message' => 'Invalid date. Use YYYY-MM-DD.']);
        exit;
    }

    syncMissedHabits($pdo, $userId);

    // Wellness ratings for the day (null = not rated)
    $stmt = $pdo->prepare(
        "SELECT wt.name, wm.value FROM wellness_metric_types wt
         LEFT JOIN wellness_metrics wm ON wm.metric_type_id = wt.id AND wm.user_id = ? AND wm.entry_date = ?
         ORDER BY wt.id"
    );
    $stmt->execute([$userId, $dateStr]);
    $ratings = [];
    foreach ($stmt->fetchAll() as $r) {
        $ratings[strtolower($r['name'])] = $r['value'] === null ? null : (int)$r['value'];
    }

    // Free-text entry
    $stmt = $pdo->prepare("SELECT content FROM journal_days WHERE user_id = ? AND entry_date = ?");
    $stmt->execute([$userId, $dateStr]);
    $content = (string)($stmt->fetchColumn() ?: '');

    // Habits for the day, grouped by status (active habits only)
    $stmt = $pdo->prepare(
        "SELECT id, name, frequency, times_per_frequency FROM habits
         WHERE user_id = ? AND status = 'active' AND DATE(created_at) <= ? ORDER BY id"
    );
    $stmt->execute([$userId, $dateStr]);
    $habits = ['finished' => [], 'pending' => [], 'missed' => []];
    $habitList = [];
    foreach ($stmt->fetchAll() as $h) {
        $status = habitStatusOn($pdo, $userId, $h, $date, $today);
        $habits[$status][] = $h['name'];
        $habitList[] = ['id' => (int)$h['id'], 'name' => $h['name'], 'status' => $status];
    }

    echo json_encode([
        'success' => true,
        'date' => $dateStr,
        'editable' => $dateStr === $todayStr,
        'ratings' => $ratings,
        'content' => $content,
        'habits' => $habits,
        'habit_list' => $habitList,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('journal_get error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load the journal.']);
}