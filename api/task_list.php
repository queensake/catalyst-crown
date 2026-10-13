<?php
require 'db.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.', 'tasks' => []]);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? 'active';
    $sort = $_GET['sort'] ?? 'newest';

    $sql = "SELECT t.id, t.name, t.frequency, t.tracking, t.deadline, t.category_id,
                   t.color_id, t.notes, t.goal, t.reminder_datetime, t.completed,
                   t.status, t.created_at, c.name AS category_name,
                   tc.hex_code AS color_hex
            FROM tasks t
            LEFT JOIN categories c ON c.id = t.category_id
            LEFT JOIN task_colors tc ON tc.id = t.color_id
            WHERE t.user_id = ?";
    $params = [$userId];

    if ($status === 'active') {
        $sql .= " AND t.status = 'active' AND t.completed = FALSE";
    } elseif ($status === 'completed') {
        $sql .= " AND t.completed = TRUE AND t.status = 'active'";
    } elseif ($status === 'archived') {
        $sql .= " AND t.status = 'archived'";
    }
    if ($search !== '') {
        $sql .= " AND (t.name LIKE ? OR t.notes LIKE ? OR t.goal LIKE ?)";
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term);
    }
    $sql .= $sort === 'oldest' ? " ORDER BY t.created_at ASC" : " ORDER BY t.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();

    $subStmt = $pdo->prepare(
        "SELECT id, name, frequency, deadline FROM task_subtasks WHERE task_id = ? ORDER BY id ASC"
    );
    foreach ($tasks as &$task) {
        $subStmt->execute([$task['id']]);
        $task['subtasks'] = $subStmt->fetchAll();
        $task['subtasks_total'] = count($task['subtasks']);
        $task['subtasks_completed'] = 0;
        $task['progress_percent'] = $task['subtasks_total'] > 0
            ? 0
            : ((bool)$task['completed'] ? 100 : 0);
    }
    unset($task);

    echo json_encode(['success' => true, 'tasks' => $tasks]);
} catch (Throwable $e) {
    error_log('Task list error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load tasks.', 'tasks' => []]);
}
