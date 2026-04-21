<?php
declare(strict_types=1);

namespace TorqueLogs\Database;

/**
 * PDO connection factory — singleton pattern.
 *
 * Provides a single shared PDO instance for the lifetime of the request.
 * All database access in the application goes through this class.
 *
 * Origin: db.php → get_pdo()
 *
 * @see restructure.md
 */
final class Connection
{
    /** @var \PDO|null Shared PDO instance */
    private static ?\PDO $instance = null;

    /**
     * Private constructor — prevents direct instantiation.
     * Use Connection::get() to obtain the PDO instance.
     */
    private function __construct() {}

    /**
     * Return the shared PDO instance, creating it on first call.
     *
     * Reads DB_HOST, DB_USER, DB_PASS, DB_NAME from includes/config.php
     * constants. On connection failure a safe 500 response is sent and
     * execution halts — credentials are never exposed to the browser.
     *
     * @return \PDO
     */
    public static function get(): \PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    /**
     * Reset the singleton (useful for testing or reconnection scenarios).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Create and configure a new PDO instance.
     *
     * @return \PDO
     * @throws \RuntimeException if required constants are not defined
     */
    private static function createConnection(): \PDO
    {
        if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_NAME')) {
            http_response_code(500);
            exit('Configuration error: database constants not defined. Check includes/config.php.');
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_NAME
        );

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            return new \PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            // Surface a safe message; never expose credentials or stack trace.
            http_response_code(500);
            exit('Database connection failed.');
        }
    }
}
