<?php
// Returns the authenticated PHP session state for the main page.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
session_start();

if (isset($_SESSION['user_id'])) {
    echo json_encode([
        'authenticated' => true,
        'username' => $_SESSION['username'] ?? ''
    ]);
} else {
    echo json_encode(['authenticated' => false]);
}
?>
