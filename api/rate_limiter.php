<?php
// rate_limiter.php
// Handles rate limiting and brute-force protection

require 'db.php'; // gives us $pdo

class RateLimiter {
    private $pdo;
    private $maxAttempts = 5;      // Max attempts before lockout
    private $lockoutDuration = 900; // 15 minutes in seconds
    private $attemptWindow = 3600;  // 1 hour window to reset attempts

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Check if an identifier is rate limited
     * Returns: ['allowed' => bool, 'remaining' => int, 'locked_until' => timestamp|null]
     */
    public function checkRateLimit($identifier, $action) {
        $stmt = $this->pdo->prepare(
            "SELECT attempt_count, locked_until, last_attempt 
             FROM rate_limit_attempts 
             WHERE identifier = ? AND action = ?"
        );
        $stmt->execute([$identifier, $action]);
        $record = $stmt->fetch();

        $now = time();
        $nowDateTime = date('Y-m-d H:i:s', $now);

        // No previous attempts
        if (!$record) {
            return [
                'allowed' => true,
                'remaining' => $this->maxAttempts,
                'locked_until' => null
            ];
        }

        // Check if currently locked
        if ($record['locked_until'] && strtotime($record['locked_until']) > $now) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'locked_until' => $record['locked_until']
            ];
        }

        // Check if attempt window has expired (reset counter)
        if (strtotime($record['last_attempt']) + $this->attemptWindow < $now) {
            $this->resetAttempts($identifier, $action);
            return [
                'allowed' => true,
                'remaining' => $this->maxAttempts,
                'locked_until' => null
            ];
        }

        // Count remaining attempts
        $remaining = max(0, $this->maxAttempts - $record['attempt_count']);

        return [
            'allowed' => $remaining > 0,
            'remaining' => $remaining,
            'locked_until' => null
        ];
    }

    /**
     * Record a failed attempt
     */
    public function recordFailedAttempt($identifier, $action) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO rate_limit_attempts (identifier, action, attempt_count, locked_until)
             VALUES (?, ?, 1, NULL)
             ON DUPLICATE KEY UPDATE 
                attempt_count = attempt_count + 1,
                last_attempt = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$identifier, $action]);

        // Check if should be locked now
        $stmt = $this->pdo->prepare(
            "SELECT attempt_count FROM rate_limit_attempts 
             WHERE identifier = ? AND action = ?"
        );
        $stmt->execute([$identifier, $action]);
        $record = $stmt->fetch();

        if ($record && $record['attempt_count'] >= $this->maxAttempts) {
            $lockedUntil = date('Y-m-d H:i:s', time() + $this->lockoutDuration);
            $stmt = $this->pdo->prepare(
                "UPDATE rate_limit_attempts 
                 SET locked_until = ? 
                 WHERE identifier = ? AND action = ?"
            );
            $stmt->execute([$lockedUntil, $identifier, $action]);
        }
    }

    /**
     * Record a successful attempt (resets counter)
     */
    public function recordSuccessfulAttempt($identifier, $action) {
        $this->resetAttempts($identifier, $action);
    }

    /**
     * Reset attempts for an identifier
     */
    public function resetAttempts($identifier, $action) {
        $stmt = $this->pdo->prepare(
            "DELETE FROM rate_limit_attempts 
             WHERE identifier = ? AND action = ?"
        );
        $stmt->execute([$identifier, $action]);
    }

    /**
     * Set custom max attempts
     */
    public function setMaxAttempts($max) {
        $this->maxAttempts = $max;
    }

    /**
     * Set custom lockout duration (seconds)
     */
    public function setLockoutDuration($seconds) {
        $this->lockoutDuration = $seconds;
    }
}
?>