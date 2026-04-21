<?php
declare(strict_types=1);

namespace TorqueLogs\Helpers;

/**
 * SQL identifier helpers — whitelist validation and safe quoting.
 *
 * All methods are static and side-effect free.
 * These utilities must be used before any dynamic column name is
 * interpolated into a query string.
 *
 * Origin: upload_data.php → is_valid_column_name() / quote_identifier()
 */
final class SqlHelper
{
    /**
     * Maximum byte length of a MariaDB/MySQL identifier.
     */
    private const MAX_IDENTIFIER_LENGTH = 64;

    /**
     * Private constructor — prevents instantiation of a utility class.
     */
    private function __construct() {}

    /**
     * Validate that a string is safe to use as a SQL column/table identifier.
     *
     * Allows only ASCII letters, digits and underscores; must start with a
     * letter or underscore; max 64 characters (MariaDB identifier limit).
     *
     * Origin: upload_data.php → is_valid_column_name()
     *
     * @param  string $name  The identifier to validate.
     * @return bool          True if the name is safe, false otherwise.
     */
    public static function isValidColumnName(string $name): bool
    {
        if (strlen($name) > self::MAX_IDENTIFIER_LENGTH) {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

    /**
     * Backtick-quote a validated SQL identifier.
     *
     * Any backtick characters within the name are escaped by doubling them,
     * as per SQL standard escaping rules for delimited identifiers.
     *
     * **Always call isValidColumnName() before this method.**
     * This function only quotes — it does NOT validate.
     *
     * Origin: upload_data.php → quote_identifier()
     *
     * @param  string $name  A pre-validated identifier.
     * @return string        The identifier wrapped in backticks.
     */
    public static function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
