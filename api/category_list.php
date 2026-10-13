<?php
// category_list.php
// Returns the user's categories. status: active (default), archived, or all. Optional search by name.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require 'db.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.', 'categories' => []]);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? 'active';

    $sql = "SELECT c.id, c.name, c.color_id, c.notes, c.status, tc.hex_code AS color_hex
            FROM categories c
            LEFT JOIN task_colors tc ON tc.id = c.color_id
            WHERE c.user_id = ?";
    $params = [$userId];
    if ($status === 'active' || $status === 'archived') {
        $sql .= " AND c.status = ?";
        $params[] = $status;
    }
    if ($search !== '') {
        $sql .= " AND c.name LIKE ?";
        $params[] = '%' . $search . '%';
    }
    $sql .= " ORDER BY c.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'categories' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('category_list error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load categories.', 'categories' => []]);
}