<?php
// reminder_time.php
// Reminders are entered in the user's local time and stored in UTC.
// The browser sets the client_tz cookie automatically (no user choice needed).
function clientTimezone(): string {
    $tz = $_COOKIE['client_tz'] ?? 'UTC';
    return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
}

// $local is 'YYYY-MM-DD HH:MM:SS' in the user's timezone; returns the same moment in UTC
function reminderToUtc(string $local): string {
    $dt = new DateTime($local, new DateTimeZone(clientTimezone()));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}