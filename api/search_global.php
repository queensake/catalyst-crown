<?php
// search_global.php
// Global search across tasks, habits, categories, and journal
// Query params: q (search query), type (all/tasks/habits/categories/journal), page, limit

require 'db.php';

header('Content-Type: application/json');
session_start();

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'You must be logged in.';
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id'];
$q = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'all'; // all, tasks, habits, categories, journal
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

if ($q === '') {
    $response['message'] = 'Please provide a search query.';
    echo json_encode($response);
    exit;
}

$results = ['tasks' => [], 'habits' => [], 'categories' => [], 'journal' => []];
$totalCount = 0;

// Search tasks
if ($type === 'all' || $type === 'tasks') {
    $stmt = $pdo->prepare(
        "SELECT 'task' as result_type, t.id, t.name, t.description, t.deadline, 
                c.name as category_name, t.status
         FROM tasks t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = ? AND (t.name LIKE ? OR t.description LIKE ? OR t.notes LIKE ?)
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$userId, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', $limit, $offset]);
    $results['tasks'] = $stmt->fetchAll();
    $totalCount += count($results['tasks']);
}

// Search habits
if ($type === 'all' || $type === 'habits') {
    $stmt = $pdo->prepare(
        "SELECT 'habit' as result_type, h.id, h.name, h.frequency, h.habit_type,
                c.name as category_name, h.status
         FROM habits h
         LEFT JOIN categories c ON c.id = h.category_id
         WHERE h.user_id = ? AND (h.name LIKE ? OR h.notes LIKE ?)
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$userId, '%' . $q . '%', '%' . $q . '%', $limit, $offset]);
    $results['habits'] = $stmt->fetchAll();
    $totalCount += count($results['habits']);
}

// Search categories
if ($type === 'all' || $type === 'categories') {
    $stmt = $pdo->prepare(
        "SELECT 'category' as result_type, c.id, c.name, c.notes
         FROM categories c
         WHERE c.user_id = ? AND (c.name LIKE ? OR c.notes LIKE ?)
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$userId, '%' . $q . '%', '%' . $q . '%', $limit, $offset]);
    $results['categories'] = $stmt->fetchAll();
    $totalCount += count($results['categories']);
}

// Search journal
if ($type === 'all' || $type === 'journal') {
    $stmt = $pdo->prepare(
        "SELECT 'journal' as result_type, id, date, content
         FROM journal
         WHERE user_id = ? AND content LIKE ?
         ORDER BY STR_TO_DATE(date, '%d-%m-%y') DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$userId, '%' . $q . '%', $limit, $offset]);
    $results['journal'] = $stmt->fetchAll();
    $totalCount += count($results['journal']);
}

// Flatten results for unified response
$allResults = [];
$allResults = array_merge($allResults, array_map(fn($r) => array_merge($r, ['type' => 'task']), $results['tasks']));
$allResults = array_merge($allResults, array_map(fn($r) => array_merge($r, ['type' => 'habit']), $results['habits']));
$allResults = array_merge($allResults, array_map(fn($r) => array_merge($r, ['type' => 'category']), $results['categories']));
$allResults = array_merge($allResults, array_map(fn($r) => array_merge($r, ['type' => 'journal']), $results['journal']));

$response['success'] = true;
$response['query'] = $q;
$response['results'] = $allResults;
$response['result_count'] = $totalCount;
$response['pagination'] = [
    'page' => $page,
    'limit' => $limit,
    'total' => $totalCount
];

echo json_encode($response);