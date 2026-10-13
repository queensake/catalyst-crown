<?php
// journal_lib.php
// Shared helpers: habit periods, completion counts, habit status, streak calculation, and missed-habit sync.

// Start and end (YYYY-MM-DD) of the period that contains $d
function periodBounds(string $frequency, DateTime $d): array {
    $start = clone $d;
    switch ($frequency) {
        case 'weekly':
            $start->modify('monday this week');
            $end = (clone $start)->modify('+6 days');
            break;
        case 'monthly':
            $start->modify('first day of this month');
            $end = (clone $start)->modify('last day of this month');
            break;
        case 'yearly':
            $start->setDate((int)$d->format('Y'), 1, 1);
            $end = (clone $start)->setDate((int)$d->format('Y'), 12, 31);
            break;
        default: // daily
            $end = clone $d;
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

// Total completions for a habit between two dates (inclusive)
function habitCountInRange(PDO $pdo, int $userId, int $habitId, string $from, string $to): int {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(count_completed), 0) FROM habit_completions
         WHERE habit_id = ? AND user_id = ? AND subtask_id IS NULL AND completed_date BETWEEN ? AND ?"
    );
    $stmt->execute([$habitId, $userId, $from, $to]);
    return (int)$stmt->fetchColumn();
}

// Status of one habit on one day.
// Build: 'finished' when the target is met, 'missed' when the period ended unmet, otherwise 'pending'.
// Avoid: 'missed' when the limit was exceeded, 'finished' when the period ended within the limit, otherwise 'pending'.
function habitStatusOn(PDO $pdo, int $userId, array $habit, DateTime $day, DateTime $today): string {
    [$periodStart, $periodEnd] = periodBounds($habit['frequency'], $day);
    $target = max(1, (int)$habit['times_per_frequency']);
    $done = habitCountInRange($pdo, $userId, (int)$habit['id'], $periodStart, $day->format('Y-m-d'));
    $todayStr = $today->format('Y-m-d');
    if (($habit['habit_type'] ?? 'build') === 'avoid') {
        if ($done > $target) return 'missed';
        return $periodEnd < $todayStr ? 'finished' : 'pending';
    }
    if ($done >= $target) return 'finished';
    if ($periodEnd < $todayStr) return 'missed';
    return 'pending';
}

// Recalculates current_streak and longest_streak for every active habit of a user,
// working backwards from the current period. Stores the result in the streaks table.
function syncHabitStreaks(PDO $pdo, int $userId): void {
    $todayStr = (new DateTime('today'))->format('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT id, frequency, times_per_frequency, habit_type, DATE(created_at) AS created
         FROM habits WHERE user_id = ? AND status = 'active'"
    );
    $stmt->execute([$userId]);

    foreach ($stmt->fetchAll() as $h) {
        $habitId = (int)$h['id'];
        $target = max(1, (int)$h['times_per_frequency']);
        $isAvoid = ($h['habit_type'] ?? 'build') === 'avoid';

        $streak = 0;
        $cursor = new DateTime('today');
        $isCurrent = true;
        for ($i = 0; $i < 500; $i++) {
            [$ps, $pe] = periodBounds($h['frequency'], $cursor);
            if ($pe < $h['created']) break; // period ended before the habit existed

            $count = habitCountInRange($pdo, $userId, $habitId, $ps, min($pe, $todayStr));

            if ($isAvoid) {
                if ($count > $target) {
                    if ($isCurrent) $streak = 0; // over the limit right now
                    break;
                }
                $streak++;
            } else {
                if ($count >= $target) {
                    $streak++;
                } elseif (!$isCurrent) {
                    break; // a finished period missed its target
                } // the current period still in progress does not break the streak
            }

            $isCurrent = false;
            $cursor = new DateTime($ps);
            $cursor->modify('-1 day');
        }

        $row = $pdo->prepare("SELECT id, longest_streak FROM streaks WHERE item_type = 'habit' AND item_id = ? AND user_id = ? LIMIT 1");
        $row->execute([$habitId, $userId]);
        $existing = $row->fetch();
        $longest = max((int)($existing['longest_streak'] ?? 0), $streak);

        $last = $pdo->prepare("SELECT MAX(completed_date) FROM habit_completions WHERE habit_id = ? AND user_id = ? AND subtask_id IS NULL");
        $last->execute([$habitId, $userId]);
        $lastCompleted = $last->fetchColumn() ?: null;

        if ($existing) {
            $pdo->prepare("UPDATE streaks SET current_streak = ?, longest_streak = ?, last_completed = ? WHERE id = ?")
                ->execute([$streak, $longest, $lastCompleted, $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO streaks (user_id, item_type, item_id, current_streak, longest_streak, last_completed) VALUES (?, 'habit', ?, ?, ?, ?)")
                ->execute([$userId, $habitId, $streak, $longest, $lastCompleted]);
        }
    }
}

// Writes journal_entries rows for past days: completed (build) or auto_marked_missed (missed target, or exceeded limit).
// Only active habits are checked, so archived habits never generate entries.
function syncMissedHabits(PDO $pdo, int $userId): int {
    $today = new DateTime('today');
    $yesterday = (clone $today)->modify('-1 day');
    $earliest = (clone $today)->modify('-60 days');

    $stmt = $pdo->prepare(
        "SELECT id, frequency, times_per_frequency, habit_type, DATE(created_at) AS created
         FROM habits WHERE user_id = ? AND status = 'active'"
    );
    $stmt->execute([$userId]);
    $habits = $stmt->fetchAll();

    $check = $pdo->prepare("SELECT id FROM journal_entries WHERE user_id = ? AND habit_id = ? AND entry_date = ?");
    $insert = $pdo->prepare(
        "INSERT INTO journal_entries (user_id, habit_id, entry_date, completed, auto_marked_missed)
         VALUES (?, ?, ?, ?, ?)"
    );

    $written = 0;
    foreach ($habits as $h) {
        $habitId = (int)$h['id'];
        $target = max(1, (int)$h['times_per_frequency']);
        $isAvoid = ($h['habit_type'] ?? 'build') === 'avoid';
        $cursor = new DateTime($h['created']);
        if ($cursor < $earliest) $cursor = clone $earliest;

        while ($cursor <= $yesterday) {
            $dayStr = $cursor->format('Y-m-d');
            [$periodStart, $periodEnd] = periodBounds($h['frequency'], $cursor);
            $count = habitCountInRange($pdo, $userId, $habitId, $periodStart, $dayStr);

            if ($isAvoid) {
                $completed = false;
                $missed = $periodEnd === $dayStr && $count > $target; // slipped: went over the limit
            } else {
                $completed = $count >= $target;
                $missed = !$completed && $periodEnd === $dayStr;
            }

            if ($completed || $missed) {
                $check->execute([$userId, $habitId, $dayStr]);
                if (!$check->fetch()) {
                    $insert->execute([$userId, $habitId, $dayStr, $completed ? 1 : 0, $missed ? 1 : 0]);
                    $written++;
                }
            }
            $cursor->modify('+1 day');
        }
    }
    return $written;
}