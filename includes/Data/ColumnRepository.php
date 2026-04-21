<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

/**
 * Loads plottable column metadata and per-session emptiness flags.
 *
 * Queries the sensors table for registered k* sensors and determines which 
 * sensors contain meaningful data for a given session.
 *
 * Origin: get_columns.php (updated for normalized schema)
 */
class ColumnRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Return all plottable sensor columns from the sensors table.
     *
     * Each entry contains:
     *  - 'colname'    string  Sensor key (e.g. 'kd', 'kff1006')
     *  - 'colcomment' string  Human-readable sensor name from short_name
     *
     * @return list<array{colname: string, colcomment: string}>
     * @throws \PDOException on database failure
     */
    public function findPlottable(): array
    {
        // Query the sensors table for all registered sensors
        $stmt = $this->pdo->query(
            "SELECT sensor_key, short_name, full_name
             FROM sensors
             ORDER BY sensor_key"
        );

        $columns = [];

        foreach ($stmt->fetchAll() as $row) {
            // Use short_name if available, otherwise full_name, otherwise sensor_key
            $displayName = $row['short_name'] ?: $row['full_name'] ?: $row['sensor_key'];
            
            $columns[] = [
                'colname'    => $row['sensor_key'],
                'colcomment' => $displayName,
            ];
        }

        return $columns;
    }

    /**
     * Return a map of sensor key → bool indicating whether each sensor
     * contains fewer than 2 distinct non-null values for the given session.
     *
     * A sensor is considered "empty" (true) when it has < 2 distinct values,
     * meaning it carries no useful variation to plot.
     *
     * Uses a single aggregated query instead of one query per sensor to
     * avoid N+1 round-trips when many sensors are registered.
     *
     * @param  string                             $sessionId  Session ID string.
     * @param  list<array{colname: string, colcomment: string}> $columns   Output of findPlottable().
     * @return array<string, bool>  sensor_key → true if empty, false if has data.
     * @throws \PDOException on database failure
     */
    public function findEmpty(string $sessionId, array $columns): array
    {
        if (empty($columns)) {
            return [];
        }

        // One query: count distinct values per sensor for the session.
        $stmt = $this->pdo->prepare(
            "SELECT sensor_key, COUNT(DISTINCT value) AS cnt
             FROM sensor_readings
             WHERE session_id = :sid
             GROUP BY sensor_key"
        );
        $stmt->execute([':sid' => $sessionId]);

        // Build a lookup: sensor_key → distinct-value count.
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['sensor_key']] = (int) $row['cnt'];
        }

        // Any sensor not present in the result has 0 readings → empty.
        $result = [];
        foreach ($columns as $col) {
            $key = $col['colname'];
            $result[$key] = ($counts[$key] ?? 0) < 2;
        }

        return $result;
    }
}
