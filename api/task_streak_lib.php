
<?php
// task_streak_lib.php
// Account-wide task streak: how many completed tasks in a row were finished on or before their deadline.
// A task with no deadline counts as on time. The latest task finished late ends the streak.
// Stored on the users table (task_streak, longest_task_streak).
function syncTaskStreak(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare(
        "SELECT t.id, t.deadline, MAX(c.completed_date) AS done_on
         FROM tasks t
         JOIN task_completions c ON c.task_id = t.id AND c.user_id = t.user_id
         WHERE t.user_id = ? AND t.completed = 1
         GROUP BY t.id, t.deadline
         ORDER BY done_on DESC, t.id DESC"
    );
    $stmt->execute([$userId]);

    $streak = 0;
    foreach ($stmt->fetchAll() as $row) {
        $onTime = $row['deadline'] === null || $row['done_on'] <= $row['deadline'];
        if (!$onTime) break;
        $streak++;
    }

    $cur = $pdo->prepare("SELECT longest_task_streak FROM users WHERE id = ?");
    $cur->execute([$userId]);
    $longest = max((int)$cur->fetchColumn(), $streak);

    $pdo->prepare("UPDATE users SET task_streak = ?, longest_task_streak = ? WHERE id = ?")
        ->execute([$streak, $longest, $userId]);
}