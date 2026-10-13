<?php
// dashboard_wellness.php
// Journal summary for the dashboard: average rating per wellness metric, days journaled, and today's habit status.
// GET: period (week|month)
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
    $period = $_GET['period'] ?? 'week';
    if (!in_array($period, ['week', 'month'], true)) {
        echo json_encode(['success' => false, 'message' => 'Period must be week or month.']);
        exit;
    }

    $today = new DateTime('today');
    $start = $period === 'week'
        ? (clone $today)->modify('-6 days')
        : (clone $today)->modify('first day of this month');
    $from = $start->format('Y-m-d');
    $to = $today->format('Y-m-d');
    $days = (int)$start->diff($today)->days + 1;

    // Average rating per metric over the period
    $stmt = $pdo->prepare(
        "SELECT wt.name, AVG(wm.value) AS avg_value, COUNT(wm.value) AS rated_days
         FROM wellness_metric_types wt
         LEFT JOIN wellness_metrics wm ON wm.metric_type_id = wt.id
              AND wm.user_id = ? AND wm.entry_date BETWEEN ? AND ?
         GROUP BY wt.id, wt.name ORDER BY wt.id"
    );
    $stmt->execute([$userId, $from, $to]);
    $metrics = [];
    foreach ($stmt->fetchAll() as $r) {
        $metrics[] = [
            'key' => strtolower($r['name']),
            'label' => $r['name'],
            'average' => (int)$r['rated_days'] > 0 ? round((float)$r['avg_value'], 1) : null,
            'rated_days' => (int)$r['rated_days'],
        ];
    }

    // Days with a journal entry or any rating
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT entry_date FROM journal_days WHERE user_id = ? AND entry_date BETWEEN ? AND ?
            UNION
            SELECT entry_date FROM wellness_metrics WHERE user_id = ? AND entry_date BETWEEN ? AND ?
         ) AS logged"
    );
    $stmt->execute([$userId, $from, $to, $userId, $from, $to]);
    $loggedDays = (int)$stmt->fetchColumn();

    // Today's habit status (active habits only)
    $stmt = $pdo->prepare(
        "SELECT id, name, frequency, times_per_frequency FROM habits
         WHERE user_id = ? AND status = 'active' AND DATE(created_at) <= ?"
    );
    $stmt->execute([$userId, $to]);
    $habits = ['finished' => 0, 'pending' => 0, 'missed' => 0];
    foreach ($stmt->fetchAll() as $h) {
        $habits[habitStatusOn($pdo, $userId, $h, $today, $today)]++;
    }

    echo json_encode([
        'success' => true,
        'period' => $period,
        'period_days' => $days,
        'metrics' => $metrics,
        'logged_days' => $loggedDays,
        'habits' => $habits,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('dashboard_wellness error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load wellness summary.']);
}