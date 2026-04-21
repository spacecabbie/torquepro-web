<?php
declare(strict_types=1);

/**
 * db.php — PDO connection factory
 *
 * Returns a shared PDO instance configured for MariaDB / MySQL.
 * All other files require_once this file and call get_pdo().
 *
 * Replaces all legacy mysql_connect() / mysql_select_db() calls.
 */

function get_pdo(): PDO
{
    // $db_host, $db_user, $db_pass, $db_name must already be loaded
    // via require_once('creds.php') before this function is called.
    global $db_host, $db_user, $db_pass, $db_name;

    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    } catch (PDOException $e) {
        // Surface a safe error message; never expose credentials.
        http_response_code(500);
        exit('Database connection failed: ' . $e->getMessage());
    }

    return $pdo;
}
