<?php
// view_all_habits.php
// Paginated habit listing. Query params: search, category_name, status (active/archived/all), habit_type (build/avoid/all), sort (newest/oldest), page, limit
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require 'db.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.', 'habits' => []]);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $search = trim($_GET['search'] ?? '');
    $categoryName = trim($_GET['category_name'] ?? '');
    $status = $_GET['status'] ?? 'active';
    $habitType = $_GET['habit_type'] ?? 'all';
    $sort = $_GET['sort'] ?? 'newest';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $from = "FROM habits h
             LEFT JOIN categories c ON c.id = h.category_id
             LEFT JOIN task_colors tc ON tc.id = h.color_id
             WHERE h.user_id = ?";
    $params = [$userId];

    if ($status !== 'all') {
        $from .= " AND h.status = ?";
        $params[] = $status;
    }
    if ($habitType === 'build' || $habitType === 'avoid') {
        $from .= " AND h.habit_type = ?";
        $params[] = $habitType;
    }
    if ($categoryName !== '') {
        $from .= " AND c.name = ?";
        $params[] = $categoryName;
    }
    if ($search !== '') {
        $from .= " AND (h.name LIKE ? OR h.notes LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like);
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS cnt $from");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['cnt'];
    $totalPages = max(1, (int)ceil($total / $limit));

    $order = $sort === 'oldest' ? 'ASC' : 'DESC';
    $stmt = $pdo->prepare(
        "SELECT h.id, h.name, h.frequency, h.times_per_frequency, h.habit_type, h.status, h.notes, h.created_at,
                c.name AS category_name, tc.hex_code AS color_hex,
                COALESCE((SELECT current_streak FROM streaks st
                          WHERE st.item_type = 'habit' AND st.item_id = h.id AND st.user_id = h.user_id
                          LIMIT 1), 0) AS streak_count
         $from
         ORDER BY h.created_at $order
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    $habits = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'habits' => $habits,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => $totalPages],
    ]);
} catch (Throwable $e) {
    error_log('view_all_habits error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load habits.', 'detail' => $e->getMessage(), 'habits' => []]);
}