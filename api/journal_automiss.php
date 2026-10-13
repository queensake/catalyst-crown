<?php
// journal_automiss.php
// Records missed and completed habit days in journal_entries for past days.
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
    $written = syncMissedHabits($pdo, (int)$_SESSION['user_id']);
    echo json_encode(['success' => true, 'written' => $written]);
} catch (Throwable $e) {
    error_log('journal_automiss error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not record missed habits.']);
}