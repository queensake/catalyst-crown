<?php
// habit_update.php
// Updates a habit. Can update: name, frequency, times_per_frequency, tracking, habit_type, category_name, color_id, notes, goal, status

require 'db.php'; // gives us $pdo

header('Content-Type: application/json');
session_start();

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'You must be logged in.';
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id'];
$habitName = trim($_POST['habit_name'] ?? '');

if ($habitName === '') {
    $response['message'] = 'Please provide the habit name.';
    echo json_encode($response);
    exit;
}

// Look up habit by name
$stmt = $pdo->prepare("SELECT id FROM habits WHERE name = ? AND user_id = ?");
$stmt->execute([$habitName, $userId]);
$habit = $stmt->fetch();
if (!$habit) {
    $response['message'] = 'Habit not found.';
    echo json_encode($response);
    exit;
}

$habitId = $habit['id'];

// Build dynamic update
$updates = [];
$params = [];

if (isset($_POST['name'])) {
    $name = trim($_POST['name']);
    if ($name === '') {
        $response['message'] = 'Name cannot be empty.';
        echo json_encode($response);
        exit;
    }
    $updates[] = "name = ?";
    $params[] = $name;
}

if (isset($_POST['frequency'])) {
    $frequency = $_POST['frequency'];
    if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'])) {
        $response['message'] = 'Invalid frequency.';
        echo json_encode($response);
        exit;
    }
    $updates[] = "frequency = ?";
    $params[] = $frequency;
}

if (isset($_POST['times_per_frequency'])) {
    $updates[] = "times_per_frequency = ?";
    $params[] = $_POST['times_per_frequency'];
}

if (isset($_POST['tracking'])) {
    $tracking = $_POST['tracking'];
    if (!in_array($tracking, ['streak', 'completion', 'both'])) {
        $response['message'] = 'Invalid tracking method.';
        echo json_encode($response);
        exit;
    }
    $updates[] = "tracking = ?";
    $params[] = $tracking;
}

if (isset($_POST['habit_type'])) {
    $type = $_POST['habit_type'];
    if (!in_array($type, ['build', 'avoid'])) {
        $response['message'] = 'Habit type must be build or avoid.';
        echo json_encode($response);
        exit;
    }
    $updates[] = "habit_type = ?";
    $params[] = $type;
}

if (isset($_POST['category_name'])) {
    $categoryName = trim($_POST['category_name']);
    $categoryId = null;
    if ($categoryName !== '') {
        $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND user_id = ?");
        $stmtCat->execute([$categoryName, $userId]);
        $category = $stmtCat->fetch();
        if ($category) {
            $categoryId = $category['id'];
        } else {
            $response['message'] = 'Category not found.';
            echo json_encode($response);
            exit;
        }
    }
    $updates[] = "category_id = ?";
    $params[] = $categoryId;
}

if (isset($_POST['color_id'])) {
    $colorId = $_POST['color_id'];
    if ($colorId !== '' && $colorId !== null) {
        $stmtColor = $pdo->prepare("SELECT id FROM task_colors WHERE id = ?");
        $stmtColor->execute([$colorId]);
        if (!$stmtColor->fetch()) {
            $response['message'] = 'Invalid color.';
            echo json_encode($response);
            exit;
        }
    }
    $updates[] = "color_id = ?";
    $params[] = $colorId ?: null;
}

if (isset($_POST['notes'])) {
    $updates[] = "notes = ?";
    $params[] = trim($_POST['notes']) ?: null;
}

if (isset($_POST['goal'])) {
    $updates[] = "goal = ?";
    $params[] = trim($_POST['goal']) ?: null;
}

if (isset($_POST['status'])) {
    $status = $_POST['status'];
    if (!in_array($status, ['active', 'archived'])) {
        $response['message'] = 'Status must be active or archived.';
        echo json_encode($response);
        exit;
    }
    $updates[] = "status = ?";
    $params[] = $status;
}

if (empty($updates)) {
    $response['message'] = 'No fields to update.';
    echo json_encode($response);
    exit;
}

$params[] = $habitId;
$params[] = $userId;

$sql = "UPDATE habits SET " . implode(", ", $updates) . " WHERE id = ? AND user_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$response['success'] = true;
$response['message'] = 'Habit updated.';

echo json_encode($response);