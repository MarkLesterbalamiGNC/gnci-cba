<?php
/**
 * Database connection
 *
 * Edit these 4 constants to match your MySQL setup (e.g. XAMPP defaults),
 * OR - better for a public website - create
 * config/db.local.php and put your real (private) values there. If db.local.php
 * exists it is used instead of the values below. You can also set the
 * environment variables BIOTRACK_DB_HOST / _NAME / _USER / _PASS.
 */
require_once __DIR__ . '/../includes/security.php';
bt_harden_php();   // never print PHP/SQL errors to visitors; they go to logs/php_error.log

if (is_file(__DIR__ . '/db.local.php')) require_once __DIR__ . '/db.local.php';

defined('DB_HOST') || define('DB_HOST', getenv('BIOTRACK_DB_HOST') ?: 'localhost');
defined('DB_NAME') || define('DB_NAME', getenv('BIOTRACK_DB_NAME') ?: 'biotrack');
defined('DB_USER') || define('DB_USER', getenv('BIOTRACK_DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('BIOTRACK_DB_PASS') !== false ? getenv('BIOTRACK_DB_PASS') : '');

// One time zone for everything (PHP dates AND MySQL NOW()/CURDATE()), so the
// dashboard, Attendance List, Attendance Report and the scanner all agree on "today".
date_default_timezone_set('Asia/Manila');

try {
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
    ];
    // One SQL statement per query: blocks "stacked query" SQL-injection tricks (PHP 8.1+)
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) $opts[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;

    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        $opts
    );
} catch (PDOException $e) {
    error_log('BioTrack DB connection failed: ' . $e->getMessage());   // details stay in logs/, not on screen
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    die('Service temporarily unavailable. Please try again later.');
}
