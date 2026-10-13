<?php
// db.php
// Creates the database connection and hands it to whichever script includes this file.

// -------------------------------------------------
// 0. TIME SETTINGS
// -------------------------------------------------
// PHP and MySQL both use UTC. Reminders are stored in UTC and shown in each
// viewer's own local time, so nothing depends on the server's location.
date_default_timezone_set('UTC');

// -------------------------------------------------
// SESSION SETTINGS
// Every endpoint loads this file before session_start(), so these apply everywhere.
// Keeps session data for 30 days, so "remember me" logins survive a browser restart.
// -------------------------------------------------
ini_set('session.gc_maxlifetime', (string)(30 * 24 * 60 * 60));

// -------------------------------------------------
// 1. CREDENTIALS
// -------------------------------------------------
// On Railway, these come from the MySQL service variables.
// On your computer (XAMPP), the fallback values below are used.
$host    = getenv('MYSQLHOST') ?: "127.0.0.1";
$port    = getenv('MYSQLPORT') ?: "3306";
$dbname  = getenv('MYSQLDATABASE') ?: "project_task_manager";
$dbuser  = getenv('MYSQLUSER') ?: "root";
$dbpass  = getenv('MYSQLPASSWORD') ?: "";
$charset = "utf8mb4";

// -------------------------------------------------
// 2. THE DSN
// -------------------------------------------------
$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";

// -------------------------------------------------
// 3. OPTIONS
// -------------------------------------------------
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// -------------------------------------------------
// 4. CONNECT
// -------------------------------------------------
try {
    $pdo = new PDO($dsn, $dbuser, $dbpass, $options);
    // MySQL's clock must match PHP's clock (UTC) for reminder checks
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    die("Database connection failed.");
}