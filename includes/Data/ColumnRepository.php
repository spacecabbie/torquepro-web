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
     * @param  string                             $sessionId  Session ID string.
     * @param  list<array{colname: string, colcomment: string}> $columns   Output of findPlottable().
     * @return array<string, bool>  sensor_key → true if empty, false if has data.
     * @throws \PDOException on database failure
     */
    public function findEmpty(string $sessionId, array $columns): array
    {
        $result = [];

        foreach ($columns as $col) {
            $sensorKey = $col['colname'];

            // Check how many distinct values this sensor has for this session
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT value) < 2 AS is_empty
                 FROM sensor_readings
                 WHERE session_id = :sid AND sensor_key = :sensor_key"
            );
            $stmt->execute([
                ':sid'        => $sessionId,
                ':sensor_key' => $sensorKey
            ]);
            $row = $stmt->fetch();

            $result[$sensorKey] = (bool) $row['is_empty'];
        }

        return $result;
    }
}
