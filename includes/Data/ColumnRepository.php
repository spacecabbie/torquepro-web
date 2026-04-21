<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

/**
 * Loads plottable column metadata and per-session emptiness flags.
 *
 * Queries INFORMATION_SCHEMA for k* sensor columns in the raw_logs table
 * and determines which columns contain meaningful data for a given session.
 *
 * Origin: get_columns.php
 */
class ColumnRepository
{
    /**
     * MariaDB/MySQL data types considered plottable as numeric series.
     * varchar is included because Torque stores all sensor readings as varchar
     * even though the values are always numeric.
     *
     * @var list<string>
     */
    private const PLOTTABLE_TYPES = ['float', 'varchar', 'double', 'decimal', 'int', 'bigint'];

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Return all plottable sensor columns from the raw_logs table.
     *
     * Each entry contains:
     *  - 'colname'    string  MariaDB column name (e.g. 'kd', 'kff1006')
     *  - 'colcomment' string  Human-readable sensor name from COLUMN_COMMENT
     *
     * @return list<array{colname: string, colcomment: string}>
     * @throws \PDOException on database failure
     */
    public function findPlottable(): array
    {
        $schema = defined('DB_NAME')  ? DB_NAME  : '';
        $table  = defined('DB_TABLE') ? DB_TABLE : 'raw_logs';

        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_COMMENT, DATA_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema
               AND TABLE_NAME   = :table"
        );
        $stmt->execute([':schema' => $schema, ':table' => $table]);

        $columns = [];

        foreach ($stmt->fetchAll() as $row) {
            if (
                str_starts_with($row['COLUMN_NAME'], 'k') &&
                in_array($row['DATA_TYPE'], self::PLOTTABLE_TYPES, true)
            ) {
                $columns[] = [
                    'colname'    => $row['COLUMN_NAME'],
                    'colcomment' => $row['COLUMN_COMMENT'],
                ];
            }
        }

        return $columns;
    }

    /**
     * Return a map of column name → bool indicating whether each column
     * contains fewer than 2 distinct non-null values for the given session.
     *
     * A column is considered "empty" (true) when it has < 2 distinct values,
     * meaning it carries no useful variation to plot.
     *
     * @param  string                             $sessionId  Numeric session ID.
     * @param  list<array{colname: string, colcomment: string}> $columns   Output of findPlottable().
     * @return array<string, bool>  colname → true if empty, false if has data.
     * @throws \PDOException on database failure
     */
    public function findEmpty(string $sessionId, array $columns): array
    {
        $table  = defined('DB_TABLE') ? DB_TABLE : 'raw_logs';
        $result = [];

        foreach ($columns as $col) {
            $colname    = $col['colname'];
            $quotedCol  = '`' . str_replace('`', '``', $colname) . '`';

            $stmt = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT {$quotedCol}) < 2 AS is_empty
                 FROM `{$table}`
                 WHERE session = :sid"
            );
            $stmt->execute([':sid' => $sessionId]);
            $row = $stmt->fetch();

            $result[$colname] = (bool) $row['is_empty'];
        }

        return $result;
    }
}
