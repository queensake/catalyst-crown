<?php
// view_all_tasks.php
// Paginated task listing. Query params: search, category_name, status (active/completed/archived/all), sort (newest/oldest), page, limit
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

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
    $categoryName = trim($_GET['category_name'] ?? '');
    $status = $_GET['status'] ?? 'active';
    $sort = $_GET['sort'] ?? 'newest';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $from = "FROM tasks t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN task_colors tc ON tc.id = t.color_id
             WHERE t.user_id = ?";
    $params = [$userId];

    // Same meaning as task_list.php: 'completed' is the completed flag, not a status value
    if ($status === 'active') {
        $from .= " AND t.status = 'active' AND t.completed = FALSE";
    } elseif ($status === 'completed') {
        $from .= " AND t.status = 'active' AND t.completed = TRUE";
    } elseif ($status === 'archived') {
        $from .= " AND t.status = 'archived'";
    }
    if ($categoryName !== '') {
        $from .= " AND c.name = ?";
        $params[] = $categoryName;
    }
    if ($search !== '') {
        $from .= " AND (t.name LIKE ? OR t.notes LIKE ? OR t.goal LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS cnt $from");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['cnt'];
    $totalPages = max(1, (int)ceil($total / $limit));

    $order = $sort === 'oldest' ? 'ASC' : 'DESC';
    $stmt = $pdo->prepare(
        "SELECT t.id, t.name, t.deadline, t.category_id, t.notes, t.goal, t.completed, t.status, t.created_at,
                c.name AS category_name, tc.hex_code AS color_hex,
                (SELECT COUNT(*) FROM task_subtasks s WHERE s.task_id = t.id) AS subtasks_total,
                (SELECT COUNT(*) FROM task_subtasks s WHERE s.task_id = t.id AND s.completed = 1) AS subtasks_completed
         $from
         ORDER BY t.created_at $order
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'tasks' => $tasks,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => $totalPages],
    ]);
} catch (Throwable $e) {
    error_log('view_all_tasks error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load tasks.', 'detail' => $e->getMessage(), 'tasks' => []]);
}