<?php
/**
 * Database Configuration
 *
 * Update these credentials to match your MySQL settings.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'profit_tracker');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_TIMEZONE', 'Asia/Manila');

// Use Asia/Manila timezone for all PHP date/time functions
// — NOT server timezone, NOT database timezone
date_default_timezone_set(APP_TIMEZONE);

/**
 * Get PDO database connection
 *
 * @return PDO
 * @throws PDOException
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Force MySQL session timezone to Asia/Manila (+08:00)
        // so NOW(), CURRENT_TIMESTAMP, etc. match the app timezone
        $pdo->exec("SET time_zone = '+08:00'");
    }

    return $pdo;
}
