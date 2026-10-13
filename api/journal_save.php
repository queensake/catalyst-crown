<?php
// journal_save.php
// Saves today's ratings and free-text entry. Past days are read-only.
// POST: date (YYYY-MM-DD, must be today), content, sleep, stress, mood, energy, water, food, exercise
// Each rating is 1-5. An empty rating removes that rating for the day.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $todayStr = (new DateTime('today'))->format('Y-m-d');

    $dateStr = trim($_POST['date'] ?? $todayStr);
    $date = DateTime::createFromFormat('!Y-m-d', $dateStr);
    if (!$date || $date->format('Y-m-d') !== $dateStr) {
        echo json_encode(['success' => false, 'message' => 'Invalid date. Use YYYY-MM-DD.']);
        exit;
    }
    if ($dateStr !== $todayStr) {
        echo json_encode(['success' => false, 'message' => 'Past days are read-only.']);
        exit;
    }

    // Validate every rating before writing anything
    $metricNames = ['sleep', 'stress', 'mood', 'energy', 'water', 'food', 'exercise'];
    $values = [];
    foreach ($metricNames as $field) {
        $raw = trim((string)($_POST[$field] ?? ''));
        if ($raw === '') {
            $values[$field] = null;
            continue;
        }
        if (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 5) {
            echo json_encode(['success' => false, 'message' => ucfirst($field) . ' must be between 1 and 5.']);
            exit;
        }
        $values[$field] = (int)$raw;
    }

    // Map metric names to ids
    $typeIds = [];
    foreach ($pdo->query("SELECT id, name FROM wellness_metric_types")->fetchAll() as $t) {
        $typeIds[strtolower($t['name'])] = (int)$t['id'];
    }

    $pdo->beginTransaction();

    $upsert = $pdo->prepare(
        "INSERT INTO wellness_metrics (user_id, entry_date, metric_type_id, value)
         VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $remove = $pdo->prepare(
        "DELETE FROM wellness_metrics WHERE user_id = ? AND entry_date = ? AND metric_type_id = ?"
    );
    foreach ($values as $field => $value) {
        if (!isset($typeIds[$field])) continue;
        if ($value === null) {
            $remove->execute([$userId, $dateStr, $typeIds[$field]]);
        } else {
            $upsert->execute([$userId, $dateStr, $typeIds[$field], $value]);
        }
    }

    $content = trim($_POST['content'] ?? '');
    $pdo->prepare(
        "INSERT INTO journal_days (user_id, entry_date, content) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = NOW()"
    )->execute([$userId, $dateStr, $content]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Saved.', 'date' => $dateStr]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('journal_save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the journal.']);
}